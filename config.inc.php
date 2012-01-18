<?php
// copy this file to config.inc.php, and set your MySQL database connection info
$config = array(
    //connection information
    "host"   => 'db.ss.org', //the hostname of your database server  - cannot be empty
    "db"     => 'gtd',	//the name of your database - cannot be empty
    "prefix" =>'gtd_',	// the GTD table prefix for your installation (optional) - can be an empty string
    "user"   => 'gtd-time',	//username for database access  - cannot be empty
    "pass"   => 'gtd-warp',	//database password
    //database information
    "dbtype" => 'mysql'      //database type: currently only 'mysql' is valid.  DO NOT CHANGE!
    ,"charset"  => 'UTF8'
);

$_SESSION['addonsdir'] = 'addons/';
