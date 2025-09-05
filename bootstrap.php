<?php

if( !extension_loaded('pdo_sqlite') || !extension_loaded('pdo_mysql') )
    die("ScavixWDF needs at least one database backend, so please install php-sqlite3 or php-mysql");

// const NO_CONFIG_NEEDED = 1;
// $GLOBALS['CONFIG'] = [];
require_once(__DIR__."/src/system.php");