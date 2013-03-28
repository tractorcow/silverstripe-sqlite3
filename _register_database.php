<?php

$sqliteDatabaseAdapterRegistryFields = array(
	'path' => array(
		'title' => 'Database path<br /><small>Absolute path, writeable by the webserver user.<br />'
			. 'Recommended to be outside of your webroot</small>',
		'default' => realpath(dirname($_SERVER['SCRIPT_FILENAME'])) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . '.sqlitedb'
	),
	'database' => array(
		'title' => 'Database name',
		'default' => 'database.sqlite',
		'attributes' => array(
			"onchange" => "this.value = this.value.replace(/[\/\\:*?&quot;<>|. \t]+/g,'');"
		)
	)
);

// Basic SQLLite3 Database
DatabaseAdapterRegistry::register(
	array(
		'class' => 'SQLite3Database',
		'title' => 'SQLite 3.3+ (using SQLite3)',
		'helperPath' => dirname(__FILE__).'/code/SQLiteDatabaseConfigurationHelper.php',
		'supported' => class_exists('SQLite3'),
		'missingExtensionText' => 'The <a href="http://php.net/manual/en/book.sqlite3.php">SQLite3</a> 
			PHP Extension is not available. Please install or enable it of them and refresh this page.',
		'fields' => $sqliteDatabaseAdapterRegistryFields
	)
);

// PDO database
DatabaseAdapterRegistry::register(
	array(
		'class' => 'SQLite3Database',
		'title' => 'SQLite 3.3+ (using PDO)',
		'helperPath' => dirname(__FILE__).'/code/SQLiteDatabaseConfigurationHelper.php',
		'supported' => (class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers())),
		'missingExtensionText' => 
			'Either the <a href="http://php.net/manual/en/book.pdo.php">PDO Extension</a> or the
			<a href="http://php.net/manual/en/book.sqlite3.php">SQLite3 PDO Driver</a>
			are unavailable. Please install or enable these and refresh this page.',
		'fields' => $sqliteDatabaseAdapterRegistryFields
	)
);
