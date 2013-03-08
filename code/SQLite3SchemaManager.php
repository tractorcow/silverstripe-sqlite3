<?php
/*
 * To change this template, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of SQLite3SchemaManager
 *
 * @author Damo
 */
class SQLite3SchemaManager {
	/*
	 * This will create a database based on whatever is in the $this->database value
	 * So you need to have called $this->selectDatabase() first, or used the __construct method
	 */
	public function createDatabase() {

		$this->dbConn = null;
		$fullpath = $this->parameters['path'] . '/' . $this->database;
		if(is_writable($fullpath)) unlink($fullpath);

		$this->connectDatabase();

	}

	/**
	 * Drop the database that this object is currently connected to.
	 * Use with caution.
	 */
	public function dropDatabase() {
		//First, we need to switch back to the original database so we can drop the current one
		$this->dbConn = null;
		$db_to_drop=$this->database;
		$this->selectDatabase($this->databaseOriginal);
		$this->connectDatabase();

		$fullpath = $this->parameters['path'] . '/' . $db_to_drop;
		if(is_writable($fullpath)) unlink($fullpath);
	}


	/**
	 * Returns true if the named database exists.
	 */
	public function databaseExists($name) {
		$SQL_name=Convert::raw2sql($name);
		$result=$this->query("PRAGMA database_list");
		foreach($result as $db) if($db['name'] == 'main' && preg_match('/\/' . $name . '/', $db['file'])) return true;
		if(file_exists($this->parameters['path'] . '/' . $name)) return true;
		return false;
	}

	function beginSchemaUpdate() {
		$this->pragma('locking_mode', 'EXCLUSIVE');
		$this->checkAndRepairTable();
		// if($this->TableExists('SQLiteEnums')) $this->query("DELETE FROM SQLiteEnums");
		$this->checkAndRepairTable();
		parent::beginSchemaUpdate();
	}

	function endSchemaUpdate() {
		parent::endSchemaUpdate();
		$this->pragma('locking_mode', self::$default_pragma['locking_mode']);
	}

	public function clearTable($table) {
		if($table != 'SQLiteEnums') $this->dbConn->query("DELETE FROM \"$table\"");
	}

	public function createTable($table, $fields = null, $indexes = null, $options = null, $advancedOptions = null) {

		if(!isset($fields['ID'])) $fields['ID'] = $this->IdColumn();

		$fieldSchemata = array();
		if($fields) foreach($fields as $k => $v) {
			$fieldSchemata[] = "\"$k\" $v";
		}
		$fieldSchemas = implode(",\n",$fieldSchemata);

		// Switch to "CREATE TEMPORARY TABLE" for temporary tables
		$temporary = empty($options['temporary']) ? "" : "TEMPORARY";
		$this->query("CREATE $temporary TABLE \"$table\" (
			$fieldSchemas
		)");

		if($indexes) {
			foreach($indexes as $indexName => $indexDetails) {
				$this->createIndex($table, $indexName, $indexDetails);
			}
		}

		return $table;
	}

	/**
	 * Alter a table's schema.
	 * @param $table The name of the table to alter
	 * @param $newFields New fields, a map of field name => field schema
	 * @param $newIndexes New indexes, a map of index name => index type
	 * @param $alteredFields Updated fields, a map of field name => field schema
	 * @param $alteredIndexes Updated indexes, a map of index name => index type
	 */
	public function alterTable($tableName, $newFields = null, $newIndexes = null, $alteredFields = null, $alteredIndexes = null, $alteredOptions = null, $advancedOptions = null) {

		if($newFields) foreach($newFields as $fieldName => $fieldSpec) $this->createField($tableName, $fieldName, $fieldSpec);

		if($alteredFields) foreach($alteredFields as $fieldName => $fieldSpec) $this->alterField($tableName, $fieldName, $fieldSpec);

		if($newIndexes) foreach($newIndexes as $indexName => $indexSpec) $this->createIndex($tableName, $indexName, $indexSpec);

		if($alteredIndexes) foreach($alteredIndexes as $indexName => $indexSpec) $this->alterIndex($tableName, $indexName, $indexSpec);

	}
    
	public function renameTable($oldTableName, $newTableName) {

		$this->query("ALTER TABLE \"$oldTableName\" RENAME TO \"$newTableName\"");

	}

	protected static $checked_and_repaired = false;

	/**
	 * Repairs and reindexes the table.  This might take a long time on a very large table.
	 * @var string $tableName The name of the table.
	 * @return boolean Return true if the table has integrity after the method is complete.
	 */
	public function checkAndRepairTable($tableName = null) {
		$ok = true;

		if(!SapphireTest::using_temp_db() && !self::$checked_and_repaired) {
			$this->alterationMessage("Checking database integrity","repaired");
			if($msgs = $this->query('PRAGMA integrity_check')) foreach($msgs as $msg) if($msg['integrity_check'] != 'ok') { Debug::show($msg['integrity_check']); $ok = false; }
			if(self::$vacuum) {
				$this->query('VACUUM', E_USER_NOTICE);
				if($this instanceof SQLitePDODatabase) {
					$msg = $this->dbConn->errorInfo();
					$msg = isset($msg[2]) ? $msg[2] : 'no errors';
				} else {
					$msg = $this->dbConn->lastErrorMsg();
				}
				if(preg_match('/authoriz/', $msg)) {
					$this->alterationMessage('VACUUM | ' . $msg, "error");
				} else {
					$this->alterationMessage("VACUUMing", "repaired");
				}
			}
			self::$checked_and_repaired = true;
		}
		
		return $ok;
	}

	public function createField($tableName, $fieldName, $fieldSpec) {
		$this->query("ALTER TABLE \"$tableName\" ADD \"$fieldName\" $fieldSpec");
	}

	/**
	 * Change the database type of the given field.
	 * @param string $tableName The name of the tbale the field is in.
	 * @param string $fieldName The name of the field to change.
	 * @param string $fieldSpec The new field specification
	 */
	public function alterField($tableName, $fieldName, $fieldSpec) {

		$oldFieldList = $this->fieldList($tableName);
		$fieldNameList = '"' . implode('","', array_keys($oldFieldList)) . '"';

		if(!empty($_REQUEST['avoidConflict']) && Director::isDev()) $fieldSpec = preg_replace('/\snot null\s/i', ' NOT NULL ON CONFLICT REPLACE ', $fieldSpec);

		if(array_key_exists($fieldName, $oldFieldList)) {

			$oldCols = array();

			foreach($oldFieldList as $name => $spec) {
				$newColsSpec[] = "\"$name\" " . ($name == $fieldName ? $fieldSpec : $spec);
			}

			$queries = array(
				"BEGIN TRANSACTION",
				"CREATE TABLE \"{$tableName}_alterfield_{$fieldName}\"(" . implode(',', $newColsSpec) . ")",
				"INSERT INTO \"{$tableName}_alterfield_{$fieldName}\" SELECT {$fieldNameList} FROM \"$tableName\"",
				"DROP TABLE \"$tableName\"",
				"ALTER TABLE \"{$tableName}_alterfield_{$fieldName}\" RENAME TO \"$tableName\"",
				"COMMIT"
			);

			$indexList = $this->indexList($tableName);
			foreach($queries as $query) $this->query($query.';');

			foreach($indexList as $indexName => $indexSpec) $this->createIndex($tableName, $indexName, $indexSpec);

		}

	}

	/**
	 * Change the database column name of the given field.
	 * 
	 * @param string $tableName The name of the tbale the field is in.
	 * @param string $oldName The name of the field to change.
	 * @param string $newName The new name of the field
	 */
	public function renameField($tableName, $oldName, $newName) {
		$oldFieldList = $this->fieldList($tableName);
		$oldCols = array();

		if(array_key_exists($oldName, $oldFieldList)) {
			foreach($oldFieldList as $name => $spec) {
				$oldCols[] = "\"$name\"" . (($name == $oldName) ? " AS $newName" : '');
				$newCols[] = "\"". (($name == $oldName) ? $newName : $name). "\"";
				$newColsSpec[] = "\"" . (($name == $oldName) ? $newName : $name) . "\" $spec";
			}

			// SQLite doesn't support direct renames through ALTER TABLE
			$queries = array(
				"BEGIN TRANSACTION",
				"CREATE TABLE \"{$tableName}_renamefield_{$oldName}\" (" . implode(',', $newColsSpec) . ")",
				"INSERT INTO \"{$tableName}_renamefield_{$oldName}\" SELECT " . implode(',', $oldCols) . " FROM \"$tableName\"",
				"DROP TABLE \"$tableName\"",
				"ALTER TABLE \"{$tableName}_renamefield_{$oldName}\" RENAME TO \"$tableName\"",
				"COMMIT"
			);

			// Remember original indexes
			$oldIndexList = $this->indexList($tableName);

			// Then alter the table column
			foreach($queries as $query) $this->query($query.';');

			// Recreate the indexes
			foreach($oldIndexList as $indexName => $indexSpec) {
				$renamedIndexSpec = array();
				foreach(explode(',', $indexSpec) as $col) {
					$col = trim($col, '"'); // remove quotes
					$renamedIndexSpec[] = ($col == $oldName) ? $newName : $col;
				}
				$this->createIndex($tableName, $indexName, implode(',', $renamedIndexSpec));
			}
		}
	}

	public function fieldList($table) {
		$sqlCreate = DB::query('SELECT sql FROM sqlite_master WHERE type = "table" AND name = "' . $table . '"')->record();
		$fieldList = array();

		if($sqlCreate && $sqlCreate['sql']) {
			preg_match('/^[\s]*CREATE[\s]+TABLE[\s]+[\'"]?[a-zA-Z0-9_\\\]+[\'"]?[\s]*\((.+)\)[\s]*$/ims', $sqlCreate['sql'], $matches);
			$fields = isset($matches[1]) ? preg_split('/,(?=(?:[^\'"]*$)|(?:[^\'"]*[\'"][^\'"]*[\'"][^\'"]*)*$)/x', $matches[1]) : array();
			foreach($fields as $field) {
				$details = preg_split('/\s/', trim($field));
				$name = array_shift($details);
				$name = str_replace('"', '', trim($name));
				$fieldList[$name] = implode(' ', $details);
			}
		}

		return $fieldList;
	}

	/**
	 * Create an index on a table.
	 * @param string $tableName The name of the table.
	 * @param string $indexName The name of the index.
	 * @param string $indexSpec The specification of the index, see Database::requireIndex() for more details.
	 */
	public function createIndex($tableName, $indexName, $indexSpec) {
		$spec = $this->convertIndexSpec($indexSpec, $indexName);
		if(!preg_match('/".+"/', $indexName)) $indexName = "\"$indexName\"";
		
		$this->query("CREATE INDEX IF NOT EXISTS $indexName ON \"$tableName\" ($spec)");

	}

	/*
	 * This takes the index spec which has been provided by a class (ie static $indexes = blah blah)
	 * and turns it into a proper string.
	 * Some indexes may be arrays, such as fulltext and unique indexes, and this allows database-specific
	 * arrays to be created.
	 */
	public function convertIndexSpec($indexSpec, $indexName = null) {
		if(is_array($indexSpec)) {
			$indexSpec = $indexSpec['value'];
		} else if(is_numeric($indexSpec)) {
			$indexSpec = $indexName;
		}
		
		if(preg_match('/\((.+)\)/', $indexSpec, $matches)) {
			$indexSpec = $matches[1];
		}

		return preg_replace('/\s/', '', $indexSpec);
	}

	/**
	 * prefix indexname with uppercase tablename if not yet done, in order to avoid ambiguity
	 */
	function getDbSqlDefinition($tableName, $indexName, $indexSpec) {
		return "\"$tableName.$indexName\"";
	}

	/**
	 * Alter an index on a table.
	 * @param string $tableName The name of the table.
	 * @param string $indexName The name of the index.
	 * @param string $indexSpec The specification of the index, see Database::requireIndex() for more details.
	 */
	public function alterIndex($tableName, $indexName, $indexSpec) {
		$this->createIndex($tableName, $indexName, $indexSpec);
	}

	/**
	 * Return the list of indexes in a table.
	 * @param string $table The table name.
	 * @return array
	 */
	public function indexList($table) {
		$indexList = array();
		foreach(DB::query('PRAGMA index_list("' . $table . '")') as $index) {
			$list = array();
			foreach(DB::query('PRAGMA index_info("' . $index["name"] . '")') as $details) $list[] = $details['name'];
			$indexList[$index["name"]] = implode(',', $list);
		}
		foreach($indexList as $name => $val) {
			// Normalize quoting to avoid false positives when checking for index changes
			// during schema generation
			$valParts = preg_split('/\s*,\s*/', $val);
			foreach($valParts as $i => $valPart) {
				$valParts[$i] = preg_replace('/^"?(.*)"?$/', '$1', $valPart);
			}
				
			$indexList[$name] = '"' . implode('","', $valParts) . '"';
		}

		return $indexList;
	}

	/**
	 * Returns a list of all the tables in the database.
	 * Table names will all be in lowercase.
	 * @return array
	 */
	public function tableList() {
		$tables = array();
		foreach($this->query('SELECT name FROM sqlite_master WHERE type = "table"') as $record) {
			//$table = strtolower(reset($record));
			$table = reset($record);
			$tables[strtolower($table)] = $table;
		}

		//Return an empty array if there's nothing in this database
		return isset($tables) ? $tables : Array();
	}

	function TableExists($tableName){

		$result=$this->query('SELECT name FROM sqlite_master WHERE type = "table" AND name="' . $tableName . '"')->first();

		if($result)
			return true;
		else
			return false;

	}
	
	/**
	 * Return a boolean type-formatted string
	 * 
	 * @params array $values Contains a tokenised list of info about this data type
	 * @return string
	 */
	public function boolean($values){
		return 'BOOL NOT NULL DEFAULT ' . (isset($values['default']) ? (int)$values['default'] : 0);
	}

	/**
	 * Return a date type-formatted string
	 * 
	 * @params array $values Contains a tokenised list of info about this data type
	 * @return string
	 */
	public function date($values){
		return "TEXT";
	}

	/**
	 * Return a decimal type-formatted string
	 * 
	 * @params array $values Contains a tokenised list of info about this data type
	 * @return string
	 */
	public function decimal($values, $asDbValue=false){

		$default = isset($values['default']) && is_numeric($values['default']) ? $values['default'] : 0;
		return "NUMERIC NOT NULL DEFAULT " . $default;

	}

	/**
	 * Return a enum type-formatted string
	 *
 	 * enumus are not supported. as a workaround to store allowed values we creates an additional table
	 * 
	 * @params array $values Contains a tokenised list of info about this data type
	 * @return string
	 */
	protected $enum_map = array();
	
	public function enum($values){
		$tablefield = $values['table'] . '.' . $values['name'];
		if(empty($this->enum_map)) $this->query("CREATE TABLE IF NOT EXISTS \"SQLiteEnums\" (\"TableColumn\" TEXT PRIMARY KEY, \"EnumList\" TEXT)");
		if(empty($this->enum_map[$tablefield]) || $this->enum_map[$tablefield] != implode(',', $values['enums'])) {
			$this->query("REPLACE INTO SQLiteEnums (TableColumn, EnumList) VALUES (\"{$tablefield}\", \"" . implode(',', $values['enums']) . "\")");
			$this->enum_map[$tablefield] = implode(',', $values['enums']);
		}
		return "TEXT DEFAULT '{$values['default']}'";
	}
	
	/**
	 * Return a set type-formatted string
	 * This type doesn't exist in SQLite as well
	 * 
	 * @params array $values Contains a tokenised list of info about this data type
	 * @return string
	 */
	public function set($values) {
		$tablefield = $values['table'] . '.' . $values['name'];
		if(empty($this->enum_map)) $this->query("CREATE TABLE IF NOT EXISTS SQLiteEnums (TableColumn TEXT PRIMARY KEY, EnumList TEXT)");
		if(empty($this->enum_map[$tablefield]) || $this->enum_map[$tablefield] != implode(',', $values['enums'])) {
			$this->query("REPLACE INTO SQLiteEnums (TableColumn, EnumList) VALUES (\"{$tablefield}\", \"" . implode(',', $values['enums']) . "\")");
			$this->enum_map[$tablefield] = implode(',', $values['enums']);
		}
		$default = '';
		if(!empty($values['default'])) {
			$default = str_replace(array('"',"'","\\","\0"), "", $values['default']);
			$default = " DEFAULT '$default'";
		}
		return 'TEXT' . $default;
	}

	/**
	 * Return a float type-formatted string
	 * 
	 * @params array $values Contains a tokenised list of info about this data type
	 * @return string
	 */
	public function float($values, $asDbValue=false){

		return "REAL";

	}

	/**
	 * Return a Double type-formatted string
	 * 
	 * @params array $values Contains a tokenised list of info about this data type
	 * @return string
	 */
	public function Double($values, $asDbValue=false){

		return "REAL";

	}

	/**
	 * Return a int type-formatted string
	 * 
	 * @params array $values Contains a tokenised list of info about this data type
	 * @return string
	 */
	public function int($values, $asDbValue=false){

		return "INTEGER({$values['precision']}) " . strtoupper($values['null']) . " DEFAULT " . (int)$values['default'];

	}

	/**
	 * Return a datetime type-formatted string
	 * For SQLite3, we simply return the word 'TEXT', no other parameters are necessary
	 * 
	 * @params array $values Contains a tokenised list of info about this data type
	 * @return string
	 */
	public function ss_datetime($values, $asDbValue=false){

		return "DATETIME";

	}

	/**
	 * Return a text type-formatted string
	 * 
	 * @params array $values Contains a tokenised list of info about this data type
	 * @return string
	 */
	public function text($values, $asDbValue=false){

		return 'TEXT';

	}

	/**
	 * Return a time type-formatted string
	 * 
	 * @params array $values Contains a tokenised list of info about this data type
	 * @return string
	 */
	public function time($values){

		return "TEXT";

	}

	/**
	 * Return a varchar type-formatted string
	 * 
	 * @params array $values Contains a tokenised list of info about this data type
	 * @return string
	 */
	public function varchar($values, $asDbValue=false){
		return "VARCHAR({$values['precision']}) COLLATE NOCASE";
	}

	/*
	 * Return a 4 digit numeric type.  MySQL has a proprietary 'Year' type.
	 * For SQLite3 we use TEXT
	 */
	public function year($values, $asDbValue=false){

		return "TEXT";

	}

	function escape_character($escape=false){

		if($escape) return "\\\""; else return "\"";

	}

	/**
	 * This returns the column which is the primary key for each table
	 * In SQLite3 it is INTEGER PRIMARY KEY AUTOINCREMENT
	 * SQLite3 does autoincrement ids even without the AUTOINCREMENT keyword, but the behaviour is signifficantly different
	 *
	 * @return string
	 */
	function IdColumn($asDbValue=false){
		return 'INTEGER PRIMARY KEY AUTOINCREMENT';
	}

	/**
	 * Returns true if this table exists
	 */
	function hasTable($tableName) {
		$SQL_table = Convert::raw2sql($tableName);
		return (bool)($this->query("SELECT name FROM sqlite_master WHERE type = \"table\" AND name = \"$tableName\"")->value());
	}

	/**
	 * Returns the SQL command to get all the tables in this database
	 */
	function allTablesSQL(){
		return 'SELECT name FROM sqlite_master WHERE type = "table"';
	}

	/**
	 * Return enum values for the given field
	 * @return array
	 */
	public function enumValuesForField($tableName, $fieldName) {
		$classnameinfo = DB::query("SELECT EnumList FROM SQLiteEnums WHERE TableColumn = \"{$tableName}.{$fieldName}\"")->first();
		$output = array();
		if($classnameinfo) {
			$output = explode(',', $classnameinfo['EnumList']);
		}
		return $output;
	}

	/**
	 * Get the actual enum fields from the constraint value:
	 */
	protected function EnumValuesFromConstraint($constraint){
		$constraint=substr($constraint, strpos($constraint, 'ANY (ARRAY[')+11);
		$constraint=substr($constraint, 0, -11);
		$constraints=Array();
		$segments=explode(',', $constraint);
		foreach($segments as $this_segment){
			$bits=preg_split('/ *:: */', $this_segment);
			array_unshift($constraints, trim($bits[0], " '"));
		}

		return $constraints;
	}
	
	/*
	 * This is a lookup table for data types.
	 * For instance, Postgres uses 'INT', while MySQL uses 'UNSIGNED'
	 * So this is a DB-specific list of equivalents.
	 */
	function dbDataType($type){
		$values=Array(
			'unsigned integer'=>'INT'
		);
		
		if(isset($values[$type]))
			return $values[$type];
		else return '';
	}
}