<?php
/**
 * Cavalcade Runner
 */

namespace HM\Cavalcade\Runner;

use Exception;
use PDO;
use PDOException;

const LOOP_INTERVAL = 1;

class Runner {
	public $options = array();

	protected $db;
	protected $workers = array();
	protected $wp_path;

	public function __construct( $options = array() ) {
		$defaults = array(
			'max_workers' => 4,
		);
		$this->options = array_merge( $defaults, $options );
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

		// Connect!
		$this->connect_to_db();
	}

	public function run() {
		$running = array();

		// Handle SIGTERM calls
		pcntl_signal( SIGTERM, array( $this, 'terminate' ) );
		pcntl_signal( SIGINT, array( $this, 'terminate' ) );

		while ( true ) {
			// Check for any signals we've received
			pcntl_signal_dispatch();

			// Check the running workers
			$this->check_workers();

			// Do we have workers to spare?
			if ( count( $this->workers ) === $this->options['max_workers'] ) {
				// At maximum workers, wait a cycle
                if (CAVALCADE_RUNNER_SYSLOG)
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
			}
			catch ( Exception $e ) {
				break;
			}

			// Go again!
		}

		$this->terminate( SIGTERM );
	}

	public function terminate( $signal ) {

        if (CAVALCADE_RUNNER_SYSLOG)
		    printf( 'Cavalcade received terminate signal (%s), shutting down %d worker(s)...' . PHP_EOL, $signal, count( $this->workers ) );
		// Wait and clean up
		while ( ! empty( $this->workers ) ) {
			$this->check_workers();
		}

		unset( $this->db );

		throw new SignalInterrupt( 'Terminated by signal', $signal );
	}

	protected function connect_to_db() {
		$charset = defined( 'DB_CHARSET' ) ? DB_CHARSET : 'utf8';

		// Check if we're passed a Unix socket (`:/tmp/socket` or `localhost:/tmp/socket`)
		if ( preg_match( '#^[^:]*:(/.+)$#', DB_HOST, $matches ) ) {
			$dsn = sprintf( 'mysql:unix_socket=%s;dbname=%s;charset=%s', $matches[1], DB_NAME, $charset );
		} else {
			$dsn = sprintf( 'mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, $charset );
		}

		$options = array();
		$this->db = new PDO( $dsn, DB_USER, DB_PASSWORD, $options );

		// Set it up just how we like it
		$this->db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->db->setAttribute( PDO::ATTR_EMULATE_PREPARES, false );
	}

	/**
	 * Get next job to run
	 *
	 * @return stdClass|null
	 */
	protected function get_next_job() {
		$query = "SELECT * FROM {$this->table_prefix}cavalcade_jobs";
		$query .= ' WHERE nextrun < NOW() AND status = "waiting"';

		$statement = $this->db->prepare( $query );
		$statement->execute();

		$data = $statement->fetchObject( __NAMESPACE__ . '\\Job', array( $this->db, $this->table_prefix ) );
		return $data;
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
		if (CAVALCADE_RUNNER_SYSLOG)
		    printf( '[%d] Running %s (%s %s)' . PHP_EOL, $job->id, $command, $job->hook, $job->args );

		$spec = array(
			// stdin
			// 0 => null,

			// stdout
			1 => array( 'pipe', 'w' ),

			// stderr
			2 => array( 'pipe', 'w' ),
		);
		$process = proc_open( $command, $spec, $pipes, $cwd );

		if ( ! is_resource( $process ) ) {
			throw new Exception();
		}

		$this->workers[] = new Worker( $process, $pipes, $job );
		if (CAVALCADE_RUNNER_SYSLOG)
		    printf( '[%d] Started worker' . PHP_EOL, $job->id );
	}

	protected function get_job_command( $job ) {
		$siteurl = $job->get_site_url();

		$command = sprintf(
			"wp cavalcade run %d",
			$job->id
		);

		if ( $siteurl ) {
			$command .= sprintf(
				" --url=%s",
				escapeshellarg( $siteurl )
			);
		}

		return $command;
	}

	protected function check_workers() {
		if ( empty( $this->workers ) ) {
			return true;
		}

		$pipes = array();
		foreach ( $this->workers as $id => $worker ) {
			$pipes[ $id ] = $worker->pipes[1];
		}

		// Grab all the pipes ready to close
		$a = $b = null; // Dummy vars for reference passing
		$changed = stream_select( $pipes, $a, $b, 0 );
		if ( $changed === false ) {
			// ERROR!
			return false;
		}

		if ( $changed === 0 ) {
			// No change, try again
			return true;
		}

		$logger = new Logger( $this->db, $this->table_prefix );

		// Clean up all of the finished workers
		foreach ( $pipes as $id => $stream ) {
			$worker = $this->workers[ $id ];
			if ( ! $worker->is_done() ) {
				// Process hasn't exited yet, keep rocking on
				continue;
			}

			if ( ! $worker->shutdown() ) {
				$worker->job->mark_failed();
				$logger->log_job_failed( $worker->job, 'Failed to shutdown worker.' );
			} else {
				$worker->job->mark_completed();
				$logger->log_job_completed( $worker->job );
			}

			unset( $this->workers[ $id ] );
		}
	}
}
