<?php

/**
 * A result-set from a SQLite3 database.
 * 
 * @package SQLite3
 */
class SQLite3Query extends SS_Query {

	/**
	 * The SQLite3Database object that created this result set.
	 * @var SQLite3Database
	 */
	protected $database;

	/**
	 * The internal sqlite3 handle that points to the result set.
	 * @var resource
	 */
	protected $handle;

	/**
	 * Hook the result-set given into a Query class, suitable for use by framework.
	 * @param database The database object that created this query.
	 * @param handle the internal sqlite3 handle that is points to the resultset.
	 */
	public function __construct(SQLite3Database $database, $handle) {
		$this->database = $database;
		$this->handle = $handle;
	}

	public function __destruct() {
		if($this->handle) $this->handle->finalize();
	}

	public function seek($row) {
		$this->handle->reset();
		$i=0;
		while($i < $row && $row = @$this->handle->fetchArray()) $i++;
		return true;
	}

	/**
	 * @todo This looks terrible but there is no SQLite3::get_num_rows() implementation
	 */
	public function numRecords() {
		$c=0;
		while($this->handle->fetchArray()) $c++;
		$this->handle->reset();
		return $c;
	}

	public function nextRecord() {
		if($data = $this->handle->fetchArray(SQLITE3_ASSOC)) {
			return $data;
		} else {
			return false;
		}
	}
}
