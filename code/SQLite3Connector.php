<?php

/**
 * 
 */
class SQLite3Connector extends DBConnector {

	/**
	 * The name of the database.
	 * 
	 * @var string
	 */
	protected $databaseName;
	
	/**
	 * Connection to the DBMS.
	 * 
	 * @var SQLite3
	 */
	protected $dbConn;

	public function connect($parameters) {
		$file = $parameters['filepath'];
		$this->dbConn = new SQLite3($file, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE, $parameters['key']);
		$this->dbConn->busyTimeout(60000);
		$this->databaseName = $parameters['database'];
	}
	
	public function affectedRows() {
		return $this->dbConn->changes();
	}

	public function getGeneratedID($table) {
		return $this->dbConn->lastInsertRowID();
	}

	public function getLastError() {
		
	}

	public function getSelectedDatabase() {
		
	}

	public function getVersion() {
		return $this->query("SELECT sqlite_version()")->value();
	}

	public function isActive() {
		return $this->databaseName && $this->dbConn;
	}

	public function preparedQuery($sql, $parameters, $errorLevel = E_USER_ERROR) {
		
	}

	public function query($sql, $errorLevel = E_USER_ERROR) {
		if(isset($_REQUEST['previewwrite']) && in_array(strtolower(substr($sql,0,strpos($sql,' '))), array('insert','update','delete','replace'))) {
			Debug::message("Will execute: $sql");
			return;
		}

		if(isset($_REQUEST['showqueries'])) { 
			$starttime = microtime(true);
		}

		@$handle = $this->dbConn->query($sql);

		if(isset($_REQUEST['showqueries'])) {
			$endtime = round(microtime(true) - $starttime,4);
			Debug::message("\n$sql\n{$endtime}ms\n", false);
		}

		DB::$lastQuery=$handle;

		if(!$handle) {
			$this->databaseError("Couldn't run query: $sql | " . $this->dbConn->lastErrorMsg(), $errorLevel);
		}

		return new SQLite3Query($this, $handle);
	}

	public function quoteString($value) {
		return "'".$this->escapeString($value)."'";
	}

	public function escapeString($value) {
		return $this->dbConn->escapeString($value);
	}

	public function selectDatabase($name) {
		
	}

	public function unloadDatabase() {
		
	}	
}