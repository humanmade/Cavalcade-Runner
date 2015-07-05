<?php

namespace HM\Cavalcade\Runner;

use PDO;

const MYSQL_DATE_FORMAT = 'Y-m-d H:i:s';

class Logger {

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
		$statement->bindValue( ':job', $this->id );
		$statement->bindValue( ':status', $status );
		$statement->bindValue( ':timestamp', date( MYSQL_DATE_FORMAT ) );
		$statement->bindValue( ':content', $message );
		$statement->execute();
	}
}
