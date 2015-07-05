<?php

namespace HM\Cavalcade\Runner;

use PDO;

const MYSQL_DATE_FORMAT = 'Y-m-d H:i:s';

class Job {
	public $id;
	public $site;
	public $hook;
	public $args;
	public $start;
	public $nextrun;
	public $interval;
	public $status;

	protected $db;
	protected $table_prefix;

	public function __construct( $db, $table_prefix ) {
		$this->db = $db;
		$this->table_prefix = $table_prefix;
	}

	public function get_site_url() {
		$query = "SELECT domain, path FROM {$this->table_prefix}blogs";
		$query .= ' WHERE blog_id = :site';

		$statement = $this->db->prepare( $query );
		$statement->bindValue( ':site', $this->site );
		$statement->execute();

		$data = $statement->fetch( PDO::FETCH_ASSOC );
		$url = $data['domain'] . $data['path'];
		return $url;
	}

	/**
	 * Acquire a "running" lock on this job
	 *
	 * Ensures that only one supervisor can run the job at once.
	 *
	 * @return bool True if we acquired the lock, false if we couldn't.
	 */
	public function acquire_lock() {
		$query = "UPDATE {$this->table_prefix}cavalcade_jobs";
		$query .= ' SET status = "running"';
		$query .= ' WHERE status = "waiting" AND id = :id';

		$statement = $this->db->prepare( $query );
		$statement->bindValue( ':id', $this->id );
		$statement->execute();

		$rows = $statement->rowCount();
		return ( $rows === 1 );
	}

	public function mark_completed() {
		global $wpdb;

		$data = array();
		if ( $this->interval ) {
			$this->reschedule();
		} else {
			$query = "UPDATE {$this->table_prefix}cavalcade_jobs";
			$query .= ' SET status = "completed"';
			$query .= ' WHERE id = :id';

			$statement = $this->db->prepare( $query );
			$statement->bindValue( ':id', $this->id );
			$statement->execute();
		}

		$this->log_run( 'completed' );
	}

	public function reschedule() {
		$this->nextrun = date( MYSQL_DATE_FORMAT, strtotime( $this->nextrun ) + $this->interval );
		$this->status  = 'waiting';

		$query = "UPDATE {$this->table_prefix}cavalcade_jobs";
		$query .= ' SET status = :status, nextrun = :nextrun';
		$query .= ' WHERE id = :id';

		$statement = $this->db->prepare( $query );
		$statement->bindValue( ':id', $this->id );
		$statement->bindValue( ':status', $this->status );
		$statement->bindValue( ':nextrun', $this->nextrun );
		$statement->execute();
	}

	/**
	 * Mark the job as failed.
	 *
	 * @param  string $message failure detail message
	 */
	public function mark_failed( $message = '' ) {
		$query = "UPDATE {$this->table_prefix}cavalcade_jobs";
		$query .= ' SET status = "failed"';
		$query .= ' WHERE id = :id';

		$statement = $this->db->prepare( $query );
		$statement->bindValue( ':id', $this->id );
		$statement->execute();

		$this->log_run( 'failed', $message );
	}

	protected function log_run( $status, $message = '' ) {
		$query = "INSERT INTO {$this->table_prefix}cavalcade_logs (`job`, `status`, `timestamp`, `content`)";
		$query .= ' values( :job, :status, :timestamp, :content )';

		$statement = $this->db->prepare( $query );
		$statement->bindValue( ':job', $this->id );
		$statement->bindValue( ':status', $status );
		$statement->bindValue( ':timestamp', date( 'Y-m-d H:i:s' ) );
		$statement->bindValue( ':content', $message );
		$statement->execute();
	}
}
