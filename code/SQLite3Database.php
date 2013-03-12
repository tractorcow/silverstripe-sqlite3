<?php

/**
 * SQLite database controller class
 * 
 * @package SQLite3
 */
class SQLite3Database extends SS_Database {

	/*
	 * This holds the parameters that the original connection was created with,
	 * so we can switch back to it if necessary (used for unit tests)
	 * 
	 * @var array
	 */
	protected $parameters;

	/*
	 * if we're on a In-Memory db
	 * 
	 * @var boolean
	 */
	protected $livesInMemory = false;

	public static $default_pragma = array();
	

	/**
	 * Extension used to distinguish between sqllite database files and other files.
	 * Required to handle multiple databases.
	 * 
	 * @return string
	 */
	public static function database_extension() {
        return Config::inst()->get('SQLite3Database', 'database_extension');
    }
	
	/**
	 * Check if a database name has a valid extension
	 * 
	 * @param string $name
	 * @return boolean
	 */
	public static function is_valid_database_name($name) {
		$extension = self::database_extension();
		if(empty($extension)) return true;
		
		return substr_compare($name, $extension, -strlen($extension), strlen($extension)) === 0;
	}

	/**
	 * Connect to a SQLite3 database.
	 * @param array $parameters An map of parameters, which should include:
	 *  - database: The database to connect to
	 *  - path: the path to the SQLite3 database file
	 *  - key: the encryption key (needs testing)
	 *  - memory: use the faster In-Memory database for unit tests
	 */
	public function connect($parameters) {
		//We will store these connection parameters for use elsewhere (ie, unit tests)
		$this->parameters = $parameters;
		$this->enum_map = array();

		// Ensure database name is set
		$dbName = $parameters['database'];
		if(!self::is_valid_database_name($dbName)) {
			// If not using the correct file extension for database files then the
			// results of SQLite3SchemaManager::databaseList will be unpredictable
			$extension = self::database_extension();
			Deprecation::notice('3.2', "SQLite3Database now expects a database file with extension \"$extension\". Behaviour may be unpredictable otherwise.");
		}

		// use the very lightspeed SQLite In-Memory feature for testing
		$this->livesInMemory = !empty($parameters['memory']);
		if($this->livesInMemory) {
			$file = ':memory:';
		} else {
			//assumes that the path to dbname will always be provided:
			$file = $parameters['path'] . '/' . $dbName;
			if(!file_exists($parameters['path'])) {
				SQLiteDatabaseConfigurationHelper::create_db_dir($parameters['path']);
				SQLiteDatabaseConfigurationHelper::secure_db_dir($parameters['path']);
			}
		}
		$parameters['filepath'] = $file;
		
		$this->connector->connect($parameters);
		
		foreach(self::$default_pragma as $pragma => $value) {
			$this->setPragma($pragma, $value);
		}
		
		if(empty(self::$default_pragma['locking_mode'])) {
			self::$default_pragma['locking_mode'] = $this->getPragma('locking_mode');
		}
	}
	
	/**
	 * Retrieve parameters used to connect to this SQLLite database
	 * 
	 * @return array
	 */
	public function getParameters() {
		return $this->parameters;
	}
	
	public function getLivesInMemory() {
		return $this->livesInMemory;
	}

	/**
	 * Returns true if this database supports collations
	 * TODO: get rid of this?
	 * @return boolean
	 */
	public function supportsCollations() {
		return true;
	}

	public function supportsTimezoneOverride() {
		return false;
	}

	/**
	 * Get the version of SQLite3.
	 * @return float
	 */
	public function getVersion() {
		return $this->query("SELECT sqlite_version()")->value();
	}

	/**
	 * Execute PRAGMA commands.
	 * 
	 * @param string pragma name
	 * @param string value to set
	 */
	public function setPragma($pragma, $value) {
		$this->query("PRAGMA $pragma = $value");
	}
	
	/**
	 * Gets pragma value.
	 * 
	 * @param string pragma name
	 * @return string the pragma value
	 */
	public function getPragma($pragma) {
		return $this->query("PRAGMA $pragma")->value();
	}
	
	public function getDatabaseServer() {
		return "sqlite";
	}

	public function selectDatabase($name, $create = false, $errorLevel = E_USER_ERROR) {
		if (!$this->schemaManager->databaseExists($name)) {
			// Check DB creation permisson
			if (!$create) {
				if ($errorLevel !== false) {
					user_error("Attempted to connect to non-existing database \"$name\"", $errorLevel);
				}
				// Unselect database
				$this->connector->unloadDatabase();
				return false;
			}
			$this->schemaManager->createDatabase($name);
		}
		
		// Reconnect using the existing parameters
		$parameters = $this->parameters;
		$parameters['database'] = $name;
		$this->connect($parameters);
		return true;
	}

	function now(){
		return "datetime('now', 'localtime')";
	}

	function random(){
		return 'random()';
	}

	/**
	 * The core search engine configuration.
	 * @todo There is a fulltext search for SQLite making use of virtual tables, the fts3 extension and the MATCH operator
	 * there are a few issues with fts:
	 * - shared cached lock doesn't allow to create virtual tables on versions prior to 3.6.17
	 * - there must not be more than one MATCH operator per statement
	 * - the fts3 extension needs to be available
	 * for now we use the MySQL implementation with the MATCH()AGAINST() uglily replaced with LIKE
	 * 
	 * @param string $keywords Keywords as a space separated string
	 * @return object DataObjectSet of result pages
	 */
	public function searchEngine($classesToSearch, $keywords, $start, $pageLength, $sortBy = "Relevance DESC", $extraFilter = "", $booleanSearch = false, $alternativeFileFilter = "", $invertedMatch = false) {
		$fileFilter = '';
		$keywords = Convert::raw2sql(str_replace(array('*','+','-','"','\''),'',$keywords));
		$htmlEntityKeywords = htmlentities(utf8_decode($keywords));

		$extraFilters = array('SiteTree' => '', 'File' => '');

		if($extraFilter) {
			$extraFilters['SiteTree'] = " AND $extraFilter";

			if($alternativeFileFilter) $extraFilters['File'] = " AND $alternativeFileFilter";
			else $extraFilters['File'] = $extraFilters['SiteTree'];
		}

		// Always ensure that only pages with ShowInSearch = 1 can be searched
		$extraFilters['SiteTree'] .= ' AND ShowInSearch <> 0';
		// File.ShowInSearch was added later, keep the database driver backwards compatible 
		// by checking for its existence first
		$fields = $this->fieldList('File');
		if(array_key_exists('ShowInSearch', $fields)) {
			$extraFilters['File'] .= " AND ShowInSearch <> 0";
		}

		$limit = $start . ", " . (int) $pageLength;

		$notMatch = $invertedMatch ? "NOT " : "";
		if($keywords) {
			$match['SiteTree'] = "
				(Title LIKE '%$keywords%' OR MenuTitle LIKE '%$keywords%' OR Content LIKE '%$keywords%' OR MetaDescription LIKE '%$keywords%' OR
				Title LIKE '%$htmlEntityKeywords%' OR MenuTitle LIKE '%$htmlEntityKeywords%' OR Content LIKE '%$htmlEntityKeywords%' OR MetaDescription LIKE '%$htmlEntityKeywords%')
			";
			$match['File'] = "(Filename LIKE '%$keywords%' OR Title LIKE '%$keywords%' OR Content LIKE '%$keywords%') AND ClassName = 'File'";

			// We make the relevance search by converting a boolean mode search into a normal one
			$relevanceKeywords = $keywords;
			$htmlEntityRelevanceKeywords = $htmlEntityKeywords;
			$relevance['SiteTree'] = "(Title LIKE '%$relevanceKeywords%' OR MenuTitle LIKE '%$relevanceKeywords%' OR Content LIKE '%$relevanceKeywords%' OR MetaDescription LIKE '%$relevanceKeywords%') + (Title LIKE '%$htmlEntityRelevanceKeywords%' OR MenuTitle LIKE '%$htmlEntityRelevanceKeywords%' OR Content LIKE '%$htmlEntityRelevanceKeywords%' OR MetaDescription LIKE '%$htmlEntityRelevanceKeywords%')";
			$relevance['File'] = "(Filename LIKE '%$relevanceKeywords%' OR Title LIKE '%$relevanceKeywords%' OR Content LIKE '%$relevanceKeywords%')";
		} else {
			$relevance['SiteTree'] = $relevance['File'] = 1;
			$match['SiteTree'] = $match['File'] = "1 = 1";
		}

		// Generate initial queries and base table names
		$baseClasses = array('SiteTree' => '', 'File' => '');
		$queries = array();
		foreach($classesToSearch as $class) {
			$queries[$class] = DataList::create($class)->where($notMatch . $match[$class] . $extraFilters[$class], "")->dataQuery()->query();
			$fromArr = $queries[$class]->getFrom();
			$baseClasses[$class] = reset($fromArr);
		}

		// Make column selection lists
		$select = array(
			'SiteTree' => array(
				"\"ClassName\"",
				"\"ID\"",
				"\"ParentID\"",
				"\"Title\"",
				"\"URLSegment\"",
				"\"Content\"",
				"\"LastEdited\"",
				"\"Created\"",
				"NULL AS \"Filename\"",
				"NULL AS \"Name\"",
				"\"CanViewType\"",
				"$relevance[SiteTree] AS Relevance"
			),
			'File' => array(
				"\"ClassName\"",
				"\"ID\"",
				"NULL AS \"ParentID\"",
				"\"Title\"",
				"NULL AS \"URLSegment\"",
				"\"Content\"",
				"\"LastEdited\"",
				"\"Created\"",
				"\"Filename\"",
				"\"Name\"",
				"NULL AS \"CanViewType\"",
				"$relevance[File] AS Relevance"
			)
		);

		// Process queries
		foreach($classesToSearch as $class) {
			// There's no need to do all that joining
			$queries[$class]->setFrom($baseClasses[$class]);

			$queries[$class]->setSelect(array());
			foreach($select[$class] as $clause) {
				if(preg_match('/^(.*) +AS +"?([^"]*)"?/i', $clause, $matches)) {
					$queries[$class]->selectField($matches[1], $matches[2]);
				} else {
					$queries[$class]->selectField(str_replace('"', '', $clause));
				}
			}

			$queries[$class]->setOrderBy(array());
		}

		// Combine queries
		$querySQLs = array();
		$totalCount = 0;
		foreach($queries as $query) {
			$querySQLs[] = $query->sql();
			$totalCount += $query->unlimitedRowCount();
		}

		$fullQuery = implode(" UNION ", $querySQLs) . " ORDER BY $sortBy LIMIT $limit";
		// Get records
		$records = DB::query($fullQuery);

		foreach($records as $record) {
			$objects[] = new $record['ClassName']($record);
		}

		if(isset($objects)) $doSet = new ArrayList($objects);
		else $doSet = new ArrayList();
		$list = new PaginatedList($doSet);
		$list->setPageStart($start);
		$list->setPageLEngth($pageLength);
		$list->setTotalItems($totalCount);
		return $list;
	}

	/*
	 * Does this database support transactions?
	 */
	public function supportsTransactions(){
		return version_compare($this->getVersion(), '3.6', '>=');
	}

	/*
	 * This is a quick lookup to discover if the database supports particular extensions
	 */
	public function supportsExtensions($extensions=Array('partitions', 'tablespaces', 'clustering')){

		if(isset($extensions['partitions']))
			return true;
		elseif(isset($extensions['tablespaces']))
			return true;
		elseif(isset($extensions['clustering']))
			return true;
		else
			return false;
	}

	/**
	 * @deprecated 1.2 use transactionStart() (method required for 2.4.x)
	 */
	public function startTransaction($transaction_mode=false, $session_characteristics=false){
		$this->transactionStart($transaction_mode, $session_characteristics);
	}

	/*
	 * Start a prepared transaction
	 */
	public function transactionStart($transaction_mode=false, $session_characteristics=false){
		DB::query('BEGIN');
	}

	/*
	 * Create a savepoint that you can jump back to if you encounter problems
	 */
	public function transactionSavepoint($savepoint){
		DB::query("SAVEPOINT \"$savepoint\"");
	}

	/*
	 * Rollback or revert to a savepoint if your queries encounter problems
	 * If you encounter a problem at any point during a transaction, you may
	 * need to rollback that particular query, or return to a savepoint
	 */
	public function transactionRollback($savepoint=false){

		if($savepoint) {
			DB::query("ROLLBACK TO $savepoint;");
		} else {
			DB::query('ROLLBACK;');
		}
	}

	/**
	 * @deprecated 1.2 use transactionEnd() (method required for 2.4.x)
	 */
	public function endTransaction(){
		$this->transactionEnd();
	}

	/*
	 * Commit everything inside this transaction so far
	 */
	public function transactionEnd(){
		DB::query('COMMIT;');
	}

	/**
	 * Generate a WHERE clause for text matching.
	 * 
	 * @param String $field Quoted field name
	 * @param String $value Escaped search. Can include percentage wildcards.
	 * @param boolean $exact Exact matches or wildcard support.
	 * @param boolean $negate Negate the clause.
	 * @param boolean $caseSensitive Enforce case sensitivity if TRUE or FALSE.
	 *                               Stick with default collation if set to NULL.
	 * @return String SQL
	 */
	public function comparisonClause($field, $value, $exact = false, $negate = false, $caseSensitive = null) {
		if($exact && !$caseSensitive) {
			$comp = ($negate) ? '!=' : '=';
		} else {
			if($caseSensitive) {
				// GLOB uses asterisks as wildcards.
				// Replace them in search string, without replacing escaped percetage signs.
				$comp = 'GLOB';
				$value = preg_replace('/^%([^\\\\])/', '*$1', $value);
				$value = preg_replace('/([^\\\\])%$/', '$1*', $value);
				$value = preg_replace('/([^\\\\])%/', '$1*', $value);
			} else {
				$comp = 'LIKE';
			}
			if($negate) $comp = 'NOT ' . $comp;
		}
		
		return sprintf("%s %s '%s'", $field, $comp, $value);
	}

	/**
	 * Function to return an SQL datetime expression that can be used with SQLite3
	 * used for querying a datetime in a certain format
	 * @param string $date to be formated, can be either 'now', literal datetime like '1973-10-14 10:30:00' or field name, e.g. '"SiteTree"."Created"'
	 * @param string $format to be used, supported specifiers:
	 * %Y = Year (four digits)
	 * %m = Month (01..12)
	 * %d = Day (01..31)
	 * %H = Hour (00..23)
	 * %i = Minutes (00..59)
	 * %s = Seconds (00..59)
	 * %U = unix timestamp, can only be used on it's own
	 * @return string SQL datetime expression to query for a formatted datetime
	 */
	function formattedDatetimeClause($date, $format) {

		preg_match_all('/%(.)/', $format, $matches);
		foreach($matches[1] as $match) if(array_search($match, array('Y','m','d','H','i','s','U')) === false) user_error('formattedDatetimeClause(): unsupported format character %' . $match, E_USER_WARNING);
		
		$translate = array(
			'/%i/' => '%M',
			'/%s/' => '%S',
			'/%U/' => '%s',
		);
		$format = preg_replace(array_keys($translate), array_values($translate), $format);

		$modifiers = array();
		if($format == '%s' && $date != 'now') $modifiers[] = 'utc';
		if($format != '%s' && $date == 'now') $modifiers[] = 'localtime';

		if(preg_match('/^now$/i', $date)) {
			$date = "'now'";
		} else if(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/i', $date)) {
			$date = "'$date'";
		}

		$modifier = empty($modifiers) ? '' : ", '" . implode("', '", $modifiers) . "'";
		return "strftime('$format', $date$modifier)";
	}
	
	/**
	 * Function to return an SQL datetime expression that can be used with SQLite3
	 * used for querying a datetime addition
	 * @param string $date, can be either 'now', literal datetime like '1973-10-14 10:30:00' or field name, e.g. '"SiteTree"."Created"'
	 * @param string $interval to be added, use the format [sign][integer] [qualifier], e.g. -1 Day, +15 minutes, +1 YEAR
	 * supported qualifiers:
	 * - years
	 * - months
	 * - days
	 * - hours
	 * - minutes
	 * - seconds
	 * This includes the singular forms as well
	 * @return string SQL datetime expression to query for a datetime (YYYY-MM-DD hh:mm:ss) which is the result of the addition
	 */
	function datetimeIntervalClause($date, $interval) {
		$modifiers = array();
		if($date == 'now') $modifiers[] = 'localtime';

		if(preg_match('/^now$/i', $date)) {
			$date = "'now'";
		} else if(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/i', $date)) {
			$date = "'$date'";
		}

		$modifier = empty($modifiers) ? '' : ", '" . implode("', '", $modifiers) . "'";
		return "datetime($date$modifier, '$interval')";
	}

	/**
	 * Function to return an SQL datetime expression that can be used with SQLite3
	 * used for querying a datetime substraction
	 * @param string $date1, can be either 'now', literal datetime like '1973-10-14 10:30:00' or field name, e.g. '"SiteTree"."Created"'
	 * @param string $date2 to be substracted of $date1, can be either 'now', literal datetime like '1973-10-14 10:30:00' or field name, e.g. '"SiteTree"."Created"'
	 * @return string SQL datetime expression to query for the interval between $date1 and $date2 in seconds which is the result of the substraction
	 */
	function datetimeDifferenceClause($date1, $date2) {

		$modifiers1 = array();
		$modifiers2 = array();

		if($date1 == 'now') $modifiers1[] = 'localtime';
		if($date2 == 'now') $modifiers2[] = 'localtime';

		if(preg_match('/^now$/i', $date1)) {
			$date1 = "'now'";
		} else if(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/i', $date1)) {
			$date1 = "'$date1'";
		}

		if(preg_match('/^now$/i', $date2)) {
			$date2 = "'now'";
		} else if(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/i', $date2)) {
			$date2 = "'$date2'";
		}

		$modifier1 = empty($modifiers1) ? '' : ", '" . implode("', '", $modifiers1) . "'";
		$modifier2 = empty($modifiers2) ? '' : ", '" . implode("', '", $modifiers2) . "'";

		return "strftime('%s', $date1$modifier1) - strftime('%s', $date2$modifier2)";
	}
}
