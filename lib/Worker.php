<?php
/**
 * Cavalcade Runner
 */

namespace HM\Cavalcade\Runner;

class Worker {
	public $process;
	public $pipes = array();
	public $job;

	protected $output = '';
	protected $error_output = '';
	protected $status = null;

	public function __construct( $process, $pipes, Job $job ) {
		$this->process = $process;
		$this->pipes = $pipes;
		$this->job = $job;
	}

	public function is_done() {
		if ( isset( $this->status['running'] ) && ! $this->status['running'] ) {
			// Already exited, so don't try and fetch again
			// (Exit code is only valid the first time after it exits)
			return ! ( $this->status['running'] );
		}

		$this->status = proc_get_status( $this->process );
		if (CAVALCADE_RUNNER_SYSLOG)
		    printf( '[%d] Worker status: %s' . PHP_EOL, $this->job->id, print_r( $this->status, true ) );

		return ! ( $this->status['running'] );
	}

	/**
	 * Shut down the process
	 *
	 * @return bool Did the process run successfully?
	 */
	public function shutdown() {
	    if (CAVALCADE_RUNNER_SYSLOG)
		    printf( '[%d] Worker shutting down...' . PHP_EOL, $this->job->id );
		// Exhaust the streams
		while ( ! feof( $this->pipes[1] ) ) {
			$this->output .= fread( $this->pipes[1], 1024 );
		}
		while ( ! feof( $this->pipes[2] ) ) {
			$this->error_output .= fread( $this->pipes[2], 1024 );
		}

		fclose( $this->pipes[1] );
		fclose( $this->pipes[2] );

		if (CAVALCADE_RUNNER_SYSLOG) {
            printf('[%d] Worker out: %s' . PHP_EOL, $this->job->id, $this->output);
            printf('[%d] Worker err: %s' . PHP_EOL, $this->job->id, $this->error_output);
            printf('[%d] Worker ret: %d' . PHP_EOL, $this->job->id, $this->status['exitcode']);
        }

		// Close the process down too
		proc_close( $this->process );
		unset( $this->process );

		return ( $this->status['exitcode'] === 0 );
	}
}
