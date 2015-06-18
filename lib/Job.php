<?php

namespace HM\Cavalcade\Runner;

class Job {
	public $hook;
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
		$query .= ' WHERE status = "waiting" AND hook = :hook';

		$statement = $this->db->prepare( $query );
		$statement->bindValue( ':hook', $this->hook );
		$statement->execute();

		$rows = $statement->rowCount();
		return ( $rows === 1 );
	}

	public function mark_failed() {
		$query = "UPDATE {$this->table_prefix}cavalcade_jobs";
		$query .= ' SET status = "failed"';
		$query .= ' WHERE hook = :hook';

		$statement = $this->db->prepare( $query );
		$statement->bindValue( ':hook', $this->hook );
		$statement->execute();
	}
}
