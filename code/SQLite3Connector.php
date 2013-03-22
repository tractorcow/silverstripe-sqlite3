<?php

/**
 * SQLite connector class
 * 
 * @package SQLite3
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
	
	public function connect($parameters, $selectDB = false) {
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
		return $this->dbConn->lastErrorMsg();
	}

	public function getSelectedDatabase() {
		return $this->databaseName;
	}

	public function getVersion() {
		return $this->query("SELECT sqlite_version()")->value();
	}

	public function isActive() {
		return $this->databaseName && $this->dbConn;
	}
	
	/**
	 * Prepares the list of parameters in preparation for passing to mysqli_stmt_bind_param
	 * 
	 * @param array $parameters List of parameters
	 * @return array List of parameters types and values
	 */
	public function parsePreparedParameters($parameters) {
		
		$values = array();
		foreach($parameters as $value) {
			$phpType = gettype($value);
			$sqlType = null;

			// Allow overriding of parameter type using an associative array
			if($phpType === 'array') {
				$phpType = $value['type'];
				$value = $value['value'];
			}

			// Convert php variable type to one that makes mysqli_stmt_bind_param happy
			// @see http://www.php.net/manual/en/mysqli-stmt.bind-param.php
			switch($phpType) {
				case 'boolean':
				case 'integer':
					$sqlType = SQLITE3_INTEGER;
					break;
				case 'float': // Not actually returnable from gettype
				case 'double':
					$sqlType = SQLITE3_FLOAT;
					break;
				case 'object': // Allowed if the object or resource has a __toString method
				case 'resource':
				case 'string':
					$sqlType = SQLITE3_TEXT;
					break;
				case 'NULL':
					$sqlType = SQLITE3_NULL;
					break;
				case 'blob':
					$sqlType = SQLITE3_BLOB;
					break;
				case 'array':
				case 'unknown type':
				default:
					user_error("Cannot bind parameter \"$value\" as it is an unsupported type ($phpType)", E_USER_ERROR);
					break;
			}
			$values[] = array(
				'type' => $sqlType,
				'value' => $value
			);
		}
		return $values;
	}

	public function preparedQuery($sql, $parameters, $errorLevel = E_USER_ERROR) {
		
		// Check if we should only preview this query
		if ($this->previewWrite($sql)) return;

		// Type check, identify, and prepare parameters for passing to the statement bind function
		$parsedParameters = $this->parsePreparedParameters($parameters);

		// Benchmark query
		$conn = $this->dbConn;
		$handle = $this->benchmarkQuery($sql, function($sql) use($conn, $parsedParameters) {
			
			// Prepare statement
			$statement = @$conn->prepare($sql);
			if(empty($statement)) return null;
			
			// Bind all variables
			for($i = 0; $i < count($parsedParameters); $i++) {
				$value = $parsedParameters[$i]['value'];
				$type = $parsedParameters[$i]['type'];
				$statement->bindValue($i+1, $value, $type);
			}
			
			// Run
			return $statement->execute();
		});

		// Check for errors
		if (!$handle) {
			if(!$errorLevel) return null;
			$this->databaseError("Couldn't run query: $sql | " . $this->getLastError(), $errorLevel);
		}

		return new SQLite3Query($this, $handle);
	}

	public function query($sql, $errorLevel = E_USER_ERROR) {

		// Check if we should only preview this query
		if ($this->previewWrite($sql)) return;

		// Benchmark query
		$conn = $this->dbConn;
		$handle = $this->benchmarkQuery($sql, function($sql) use($conn) {
			return @$conn->query($sql);
		});

		// Check for errors
		if (!$handle) {
			if(!$errorLevel) return null;
			$this->databaseError("Couldn't run query: $sql | " . $this->getLastError(), $errorLevel);
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
        if($name !== $this->databaseName) {
            user_error("SQLite3Connector can't change databases. Please create a new database connection", E_USER_ERROR);
		}
        return true;
	}

	public function unloadDatabase() {
		$this->dbConn->close();
		$this->databaseName = null;
	}	
}