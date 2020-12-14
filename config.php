<?php  // Moodle configuration file

unset($CFG);
global $CFG;
$CFG = new stdClass();

$CFG->dbtype    = 'pgsql';
$CFG->dblibrary = 'native';
$CFG->dbhost    = $_ENV['PGHOST'];
$CFG->dbname    = $_ENV['PGDATABASE'];
$CFG->dbuser    = $_ENV['PGUSER'];
$CFG->dbpass    = $_ENV['PGPASSWORD'];
$CFG->prefix    = 'mdl_';
$CFG->dboptions = array (
'dbpersist' => 0,
'dbport' => 5432,
'dbsocket' => '',
);

$CFG->wwwroot   = $_ENV['WWWROOT'];
//$CFG->reverseproxy = true;
$CFG->sslproxy = true;
if (isset($_ENV['UPGRADEKEY'])){
	$CFG->upgradekey=$_ENV['UPGRADEKEY'];
}
//$CFG->wwwroot   = 'http://mercurio.bitvax.com:9090';
$CFG->dataroot  = '/var/www/moodledata';
$CFG->admin     = 'admin';

$CFG->directorypermissions = 0777;

$CFG->session_handler_class = '\core\session\redis';
$CFG->session_redis_host = $_ENV['REDIS_HOST'];
$CFG->session_redis_port = 6379;  // Optional.
$CFG->session_redis_database = 7;  // Optional, default is db 0.
$CFG->session_redis_prefix = ''; // Optional, default is don't set one.
$CFG->session_redis_acquire_lock_timeout = 120;
$CFG->session_redis_lock_expire = 7200;

require_once(__DIR__ . '/lib/setup.php');

// There is no php closing tag in this file,
// it is intentional because it prevents trailing whitespace problems!
