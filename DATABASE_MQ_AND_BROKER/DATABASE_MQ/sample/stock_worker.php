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
        return ['status'=>'error','message'=>'invalid request' ];
    }
    $symbol = $mydb->real_esscape_string($request['symbol']);
    $price = floatval($request['price']);
    $timestamp = date('Y-m-d H:i:s');

    $mydb->query("INSERT INTO stock_prices (symbol,price,updated_at) VALUES  ('$symbol',$price,'$timestamp')")l
    $notification=[];

    $query = "SELECT username,avg_price,shares FROM portfolio_positions WHERE symbol = '$symbol'";
    $result = $mydb->query($query);

    while ($row = $result->fetch_assoc()){
        $username = $row['username'];
        $avg_price = floatval($row['avg_price']);
        $shares = floatval($row['shares']);

        $high_threshhold = $avg_price*1.10;
        $low_threshhold = $avg_price *0.90;
        $alerttype=null;
        if($price>=$high_threshhold) $alert_type = 'HIGH';
        elseif ($price<=$high_threshhold) $alert_type = 'LOW';

        if($alerttype){
            $notification[] = [
                'username'=>$username,
                'symbol'=>$symbol,
                'price'=>$price,
                'shares'=>$shares,
                'alert'=>$alerttype,
                'timestamp'=>$timestamp
            ];
        }
    }
    return['status'=>'success','notifications'=>$notificaton]
}

$stockServer = new rabbitMQServer("testRabbitMQ.ini","sharedServer2");
echo "Stock Server ready and on standby..." . PHP_EOL;
$stockServerPid = pcntl_fork();
if ($stockServerPid == 0){
    $stockServer->process_requests('requestProcessor');
    exit();
}
?>
