<?php
/**
 * Cavalcade Runner
 */

namespace HM\Cavalcade\Runner;

class Worker {
	public $process;
	public $pipes = [];
	public $job;

	public $output = '';
	public $error_output = '';
	public $status = null;

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
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
		printf( '[%d] Worker status: %s' . PHP_EOL, $this->job->id, print_r( $this->status, true ) );
		return ! ( $this->status['running'] );
	}

	/**
	 * Drain stdout & stderr into properties.
	 *
	 * Draining the pipes is needed to avoid workers hanging when they hit the system pipe buffer limits.
	 */
	public function drain_pipes() {
		while ( $data = fread( $this->pipes[1], 1024 ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			$this->output .= $data;
		}

		while ( $data = fread( $this->pipes[2], 1024 ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			$this->error_output .= $data;
		}
	}

	/**
	 * Send a SIGTERM to the process.
	 *
	 * This is used by Runner::terminate() to indicate a graceful shutdown.
	 * Workers have 60s (by default) to shut down gracefully.
	 */
	public function sigterm() {
		proc_terminate( $this->process, SIGTERM );
	}

	/**
	 * Send a SIGKILL to the process.
	 *
	 * This is used by Runner::terminate() to indicate a forced shutdown.
	 * Workers have 60s (by default) to shut down gracefully.
	 */
	public function sigkill() {
		proc_terminate( $this->process, SIGKILL );
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
		fclose( $this->pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $this->pipes[2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		printf( '[%d] Worker out: %s' . PHP_EOL, $this->job->id, $this->output );
		printf( '[%d] Worker err: %s' . PHP_EOL, $this->job->id, $this->error_output );
		printf( '[%d] Worker ret: %d' . PHP_EOL, $this->job->id, $this->status['exitcode'] );

		// Close the process down too
		proc_close( $this->process );
		unset( $this->process );

		return ( $this->status['exitcode'] === 0 );
	}
}
