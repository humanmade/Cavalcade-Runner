<?php
/**
 * Cavalcade Runner
 */

namespace HM\Cavalcade\Runner;

class Worker {
	public $process;
	public $pipes = [];
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
		printf( '[%d] Worker status: %s' . PHP_EOL, $this->job->id, print_r( $this->status, true ) );
		return ! ( $this->status['running'] );
	}

	/**
	 * Drain stdout & stderr into properties.
	 *
	 * Draining the pipes is needed to avoid workers hanging when they hit the system pipe buffer limits.
	 */
	public function drain_pipes() {
		while ( $data = fread( $this->pipes[1], 1024 ) ) {
			$this->output .= $data;
		}

		while ( $data = fread( $this->pipes[2], 1024 ) ) {
			$this->error_output .= $data;
		}
	}

	/**
	 * Shut down the process
	 *
	 * @return bool Did the process run successfully?
	 */
	public function shutdown() {
		printf( '[%d] Worker shutting down...' . PHP_EOL, $this->job->id );

		// Exhaust the streams
		$this->drain_pipes();
		fclose( $this->pipes[1] );
		fclose( $this->pipes[2] );

		printf( '[%d] Worker out: %s' . PHP_EOL, $this->job->id, $this->output );
		printf( '[%d] Worker err: %s' . PHP_EOL, $this->job->id, $this->error_output );
		printf( '[%d] Worker ret: %d' . PHP_EOL, $this->job->id, $this->status['exitcode'] );

		// Close the process down too
		proc_close( $this->process );
		unset( $this->process );

		return ( $this->status['exitcode'] === 0 );
	}
}
