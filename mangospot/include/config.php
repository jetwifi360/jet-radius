<?php
define("TYPE", "mysql"); // pgsql or mysql
define("DB_HOST", "localhost"); // localhost or ip
define("DB_USER", "mangospot"); // username database
define("DB_PASSWORD", "JetWifiAdmin123"); // password database
define("DB_DATABASE", "mangospot"); // table database
define("SSH_HOST", $_SERVER['SERVER_NAME']); // SSH Host
define("SSH_PORT", 22); // Port SSH
define("SSH_USERNAME", "ssh_username"); // Username SSH
define("SSH_PASSWORD", "ssh_password"); // Password SSH
if ( !defined('ABSPATH') )
	define('ABSPATH', dirname(__FILE__) . '/');
require_once(ABSPATH . 'connect.php');
?>