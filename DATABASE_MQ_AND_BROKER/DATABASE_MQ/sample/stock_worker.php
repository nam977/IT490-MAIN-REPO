#!/user/bin/php
<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

require_once __DIR__ . '/vendor/autoload.php';
$client_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

// Database credentials
$db_host = '127.0.0.1';
$db_user = 'testuser';
$db_pass = 'rv9991$#';
$db_name = 'testdb';

// Connect to MySQL
$mydb = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($mydb->connect_errno != 0) {
    echo "Failed to connect to database: " . $mydb->connect_error . PHP_EOL;
    exit(0);
}

function requestProcessor($request){
    global $mydb;

    if(!isset($request['symbol'])||!sset($request['price'])){
        return ['status'=>'error','message'=>'invalid requeeset ];
    }
}

$stockServer = new rabbitMQServer("testRabbitMQ.ini","sharedServer2");
echo "Stock Server ready and on standby..." . PHP_EOL;
$stockServerPid = pcntl_fork();
if ($stockServerPid == 0){
    $stockServer->process_requests('requestProcessor');
    exit();
}
