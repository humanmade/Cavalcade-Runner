<?php
/**
 * Cavalcade Runner
 */

namespace HM\Cavalcade\Runner;

use Exception;
use PDO;

const LOOP_INTERVAL = 1;

class Runner {
	public $options = [];

	/**
	 * Hook system for the Runner.
	 *
	 * @var Hooks
	 */
	public $hooks;

	protected $db;
	protected $workers = [];
	protected $wp_path;
	protected $table_prefix;

	/**
	 * Instance of the runner.
	 *
	 * @var self
	 */
	protected static $instance;

	public function __construct( $options = [] ) {
		$defaults = [
			'max_workers' => 4,

			// After receiving a SIGTERM, delay until we propagate SIGTERM to
			// workers. This will kill any jobs which aren't specifically
			// designed to catch and ignore it, so should be set to a
			// reasonable value for general WordPress jobs.
			// (Delay in seconds, or false to disable.)
			'graceful_shutdown_timeout' => 30,

			// After sending a SIGTERM, delay until we send a SIGKILL to
			// force-shutdown any workers. This should be set to a higher
			// value than graceful_shutdown_timeout.
			//
			// Delay is specified as *total* time after Cavalcade-Runner
			// receives the SIGTERM from the system.
			// (Workers will have `force_shutdown_timeout - graceful_shutdown_timeout`
			// seconds to shut down gracefully.)
			//
			// (Delay in seconds, or false to disable.)
			'force_shutdown_timeout' => 90,
		];
		$this->options = array_merge( $defaults, $options );
		$this->hooks = new Hooks();
	}

	/**
	 * Get the singleton instance of the Runner.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( empty( static::$instance ) ) {
			static::$instance = new static();
		}

		return static::$instance;
	}

	public function bootstrap( $wp_path = '.' ) {
		// Check some requirements first
		if ( ! function_exists( 'pcntl_signal' ) ) {
			throw new Exception( 'pcntl extension is required' );
		}

		$config_path = realpath( $wp_path . '/wp-config.php' );
		if ( ! file_exists( $config_path ) ) {
			$config_path = realpath( $wp_path . '/../wp-config.php' );
			if ( ! file_exists( $config_path ) ) {
				throw new Exception( sprintf( 'Could not find config file at %s', realpath( $wp_path ) . '/wp-config.php or next level up.' ) );
			}
		}

		$this->wp_path = realpath( $wp_path );

		// Load WP config
		define( 'ABSPATH', dirname( __DIR__ ) . '/fakewp/' );
		if ( ! isset( $_SERVER['HTTP_HOST'] ) ) {
			$_SERVER['HTTP_HOST'] = 'cavalcade.example';
		}

		include $config_path;
		$this->table_prefix = isset( $table_prefix ) ? $table_prefix : 'wp_';

		/**
		 * Filter the table prefix from the configuration.
		 *
		 * @param string $table_prefix Table prefix to use for Cavalcade.
		 */
		$this->table_prefix = $this->hooks->run( 'Runner.bootstrap.table_prefix', $this->table_prefix );

		// Connect to the database!
		$this->connect_to_db();
	}

	public function run() {
		$running = [];

		// Handle SIGTERM calls
		pcntl_signal( SIGTERM, [ $this, 'terminate' ] );
		pcntl_signal( SIGINT, [ $this, 'terminate' ] );
		pcntl_signal( SIGQUIT, [ $this, 'terminate' ] );

		/**
		 * Action before starting to run.
		 */
		$this->hooks->run( 'Runner.run.before' );

		while ( true ) {
			// Check for any signals we've received
			pcntl_signal_dispatch();

			/**
			 * Action at the start of every loop iteration.
			 *
			 * @param Runner $this Instance of the Cavalcade Runner
			 */
			$this->hooks->run( 'Runner.run.loop_start', $this );

			// Check the running workers
			$this->check_workers();

			// Do we have workers to spare?
			if ( count( $this->workers ) === $this->options['max_workers'] ) {
				// At maximum workers, wait a cycle
				printf( '[  ] Out of workers' . PHP_EOL );
				sleep( LOOP_INTERVAL );
				continue;
			}

			// Find any new jobs, or wait for one
			$job = $this->get_next_job();
			if ( empty( $job ) ) {
				// No job to run, try again in a second
				sleep( LOOP_INTERVAL );
				continue;
			}

			// Spawn worker
			try {
				$this->run_job( $job );
			} catch ( Exception $e ) {
				trigger_error( sprintf( 'Unable to run job due to exception: %s', $e->getMessage() ), E_USER_WARNING );
				$job->mark_failed( $e->getMessage() );
				break;
			}

			// Go again!
		}

		$this->terminate( SIGTERM );
	}

	public function terminate( $signal ) {
		$received_at = microtime( true );

		/**
		 * Action before terminating workers.
		 *
		 * Use this to change the cleanup process.
		 *
		 * @param int $signal Signal received that caused termination.
		 */
		$this->hooks->run( 'Runner.terminate.will_terminate', $signal );

		printf( 'Cavalcade received terminate signal (%s), shutting down %d worker(s)...' . PHP_EOL, $signal, count( $this->workers ) );
		// Wait and clean up

		$graceful = $this->options['graceful_shutdown_timeout'];
		$did_graceful = false;
		$force = $this->options['force_shutdown_timeout'];
		while ( ! empty( $this->workers ) ) {
			$this->check_workers();

			$now = microtime( true );

			// If we've reached the graceful timeout, pass on the SIGTERM.
			// This will kill any workers that aren't intentionally capturing
			// SIGTERMs (eg any non-Cavalcade jobs in WP)
			if ( $graceful !== false && $now >= ( $received_at + $graceful ) ) {
				printf( 'Graceful shutdown timeout reached, sending SIGTERM to %d worker(s)...' . PHP_EOL, count( $this->workers ) );
				foreach ( $this->workers as $worker ) {
					$worker->sigterm();
				}
				$did_graceful = true;
			}

			// If we've reached the force timeout, we need to kill the workers.
			if ( $force !== false && $now >= ( $received_at + $force ) ) {
				printf( 'Force shutdown timeout reached, sending SIGKILL to %d worker(s)...' . PHP_EOL, count( $this->workers ) );
				foreach ( $this->workers as $worker ) {
					$worker->sigkill();
				}

				// Perform final check, then break.
				$this->check_workers();
				break;
			}

			usleep( 100000 );
		}

		/**
		 * Action after terminating workers.
		 *
		 * Use this to run final shutdown commands while still connected to the database.
		 *
		 * @param int $signal Signal received that caused termination.
		 */
		$this->hooks->run( 'Runner.terminate.terminated', $signal );

		unset( $this->db );

		throw new SignalInterrupt( 'Terminated by signal', $signal );
	}

	public function get_wp_path() {
		return $this->wp_path;
	}

	protected function connect_to_db() {
		$charset = defined( 'DB_CHARSET' ) ? DB_CHARSET : 'utf8';

		// Check if we're passed a Unix socket (`:/tmp/socket` or `localhost:/tmp/socket`)
		if ( preg_match( '#^[^:]*:(/.+)$#', DB_HOST, $matches ) ) {
			$dsn = sprintf( 'mysql:unix_socket=%s;dbname=%s;charset=%s', $matches[1], DB_NAME, $charset );
		} else {
			$dsn = sprintf( 'mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, $charset );
		}

		/**
		 * Filter for PDO DSN.
		 *
		 * @param string $dsn DSN passed to PDO.
		 * @param string $host Database host from config.
		 * @param string $name Database name from config.
		 * @param string $charset Character set from config, or default of 'utf8'
		 */
		$dsn = $this->hooks->run( 'Runner.connect_to_db.dsn', $dsn, DB_HOST, DB_NAME, $charset );

		/**
		 * Filter for PDO options.
		 *
		 * @param array $options Options to pass to PDO.
		 * @param string $dsn DSN for the connection.
		 * @param string $user User for the connection
		 * @param string $password Password for the connection.
		 */
		$options = $this->hooks->run( 'Runner.connect_to_db.options', [], $dsn, DB_USER, DB_PASSWORD );
		$this->db = new PDO( $dsn, DB_USER, DB_PASSWORD, $options );

		// Set it up just how we like it
		$this->db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->db->setAttribute( PDO::ATTR_EMULATE_PREPARES, false );
		$this->db->exec( 'SET time_zone = "+00:00"' );

		/**
		 * Action after connecting to the database.
		 *
		 * Use the PDO object to set additional attributes as needed.
		 *
		 * @param PDO $db PDO database connection.
		 */
		$this->hooks->run( 'Runner.connect_to_db.connected', $this->db );
	}

	/**
	 * Get next job to run
	 *
	 * @return stdClass|null
	 */
	protected function get_next_job() {
		$query = "SELECT * FROM {$this->table_prefix}cavalcade_jobs";
		$query .= ' WHERE nextrun < NOW() AND status = "waiting"';
		$query .= ' ORDER BY nextrun ASC';
		$query .= ' LIMIT 1';

		/**
		 * Filter for the next job query.
		 *
		 * @param string $query Database query for the next job.
		 */
		$query = $this->hooks->run( 'Runner.get_next_job.query', $query );

		$statement = $this->db->prepare( $query );
		$statement->execute();

		$data = $statement->fetchObject( __NAMESPACE__ . '\\Job', [ $this->db, $this->table_prefix ] );
		/**
		 * Filter for the next job.
		 *
		 * @param Job $data Next job to be run.
		 */
		return $this->hooks->run( 'Runner.get_next_job.job', $data );
	}

	protected function run_job( $job ) {
		// Mark the job as started
		$has_lock = $job->acquire_lock();
		if ( ! $has_lock ) {
			// Couldn't get lock, looks like another supervisor already started
			return;
		}

		$command = $this->get_job_command( $job );

		$cwd = $this->wp_path;
		printf( '[%d] Running %s (%s %s)' . PHP_EOL, $job->id, $command, $job->hook, $job->args );

		$spec = [
			// We're intentionally avoiding adding a stdin pipe
			// stdin 0 => null

			// stdout
			1 => [ 'pipe', 'w' ],

			// stderr
			2 => [ 'pipe', 'w' ],
		];
		$process = proc_open( $command, $spec, $pipes, $cwd );

		if ( ! is_resource( $process ) ) {
			// Set the job to failed as we don't know if the process was able to run the job.
			throw new Exception( 'Unable to proc_open.' );
		}

		// Disable blocking to allow partial stream reads before EOF.
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );

		$worker = new Worker( $process, $pipes, $job );
		$this->workers[] = $worker;

		printf( '[%d] Started worker' . PHP_EOL, $job->id );

		/**
		 * Action after starting a new worker.
		 *
		 * @param Worker $worker Worker that started.
		 * @param Job $job Job that the worker is processing.
		 */
		$this->hooks->run( 'Runner.run_job.started', $worker, $job );
	}

	protected function get_job_command( $job ) {
		$siteurl = $job->get_site_url();

		$command = sprintf(
			'wp cavalcade run %d',
			$job->id
		);

		if ( $siteurl ) {
			$command .= sprintf(
				' --url=%s',
				escapeshellarg( $siteurl )
			);
		}

		/**
		 * Filter for the command to be run for the job.
		 *
		 * @param string $command Full shell command to be run to start the job.
		 * @param Job $job Job to be run.
		 */
		return $this->hooks->run( 'Runner.get_job_command.command', $command, $job );
	}

	protected function check_workers() {
		if ( empty( $this->workers ) ) {
			return true;
		}

		$pipes_stdout = $pipes_stderr = [];
		foreach ( $this->workers as $id => $worker ) {
			$pipes_stdout[ $id ] = $worker->pipes[1];
			$pipes_stderr[ $id ] = $worker->pipes[2];
		}

		// Grab all the pipes ready to close
		$a = $b = null; // Dummy vars for reference passing

		$changed_stdout = stream_select( $pipes_stdout, $a, $b, 0 );
		if ( $changed_stdout === false ) {
			// An error occured!
			return false;
		}

		$changed_stderr = stream_select( $pipes_stderr, $a, $b, 0 );
		if ( $changed_stderr === false ) {
			// An error occured!
			return false;
		}

		if ( $changed_stdout === 0 && $changed_stderr === 0 ) {
			// No change, try again
			return true;
		}

		// List of Workers with a changed state
		$changed_workers = array_unique( array_merge( array_keys( $pipes_stdout ), array_keys( $pipes_stderr ) ) );

		/**
		 * Filter for using a custom Logger implementation, instead of the
		 * default one.
		 *
		 * @param object $logger Logger implementation that will be used.
		 */
		$logger = $this->hooks->run(
			'Runner.check_workers.logger',
			new Logger( $this->db, $this->table_prefix )
		);

		// Clean up all of the finished workers
		foreach ( $changed_workers as $id ) {
			$worker = $this->workers[ $id ];
			$worker->drain_pipes();
			if ( ! $worker->is_done() ) {
				// Process hasn't exited yet, keep rocking on
				continue;
			}

			if ( ! $worker->shutdown() ) {
				$worker->job->mark_failed();
				$logger->log_job_failed( $worker->job, 'Failed to shutdown worker.' );

				/**
				 * Action after a job has failed.
				 *
				 * @param Worker $worker Worker that ran the job.
				 * @param Job $job Job that failed.
				 * @param Logger $logger Logger for the job.
				 */
				$this->hooks->run( 'Runner.check_workers.job_failed', $worker, $worker->job, $logger );
			} else {
				$worker->job->mark_completed();
				$logger->log_job_completed( $worker->job );

				/**
				 * Action after a job has failed.
				 *
				 * @param Worker $worker Worker that ran the job.
				 * @param Job $job Job that completed.
				 * @param Logger $logger Logger for the job.
				 */
				$this->hooks->run( 'Runner.check_workers.job_completed', $worker, $worker->job, $logger );
			}

			unset( $this->workers[ $id ] );
		}
	}
}
