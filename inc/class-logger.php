<?php

namespace HM\Cavalcade\Runner;

class Logger {
	protected $db;
	protected $table_prefix;

	public function __construct( $db, $table_prefix ) {
		$this->db = $db;
		$this->table_prefix = $table_prefix;
	}

	public function log_job_completed( Job $job, $message = '' ) {
		$this->log_run( $job->id, 'completed', $message );
	}

	public function log_job_failed( Job $job, $message = '' ) {
		$this->log_run( $job->id, 'failed', $message );
	}

	protected function log_run( $job_id, $status, $message = '' ) {
		$query = "INSERT INTO {$this->table_prefix}cavalcade_logs (`job`, `status`, `timestamp`, `content`)";
		$query .= ' values( :job, :status, :timestamp, :content )';

		$statement = $this->db->prepare( $query );
		$statement->bindValue( ':job', $job_id );
		$statement->bindValue( ':status', $status );
		$statement->bindValue( ':timestamp', date( MYSQL_DATE_FORMAT ) );
		$statement->bindValue( ':content', $message );
		$statement->execute();
	}
}
