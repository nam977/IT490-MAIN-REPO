#!/user/bin/php
<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

require_once __DIR__ . '/vendor/autoload.php';
$client_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$db_host = '127.0.0.1';
$db_user = 'testuser';
$db_pass = 'rv9991$#';
$db_name = 'testdb';

$brokerDB = new mysqli($db_host,$db_user,$db_pass,$db_name);
$stockWorkerClient = new rabbitMQClient("testRabbitMQ.ini","sharedServer2");
if ($brokerDB -> connect_errno!= 0){
    echo "failed to connect to the database" . $brokerDB -> connect_error . PHP_EOL;
    exit();
}else{
    echo "successful database connectoin" . PHP_EOL;
}

function requestProcessor($request){
    global $brokerDB,$stockWorkerClient;

    if(!is_array($request)){
        return ["returnCode"=>1,"message"=>"invalid request"];
    }
    if (!isset($request['type']) || $request['type'] !== 'stock_price_update') {
        return ["returnCode" => 1, "message" => "unsupported type"];
    }
    if (empty($request['symbol']) || !isset($request['price'])) {
        return ["returnCode" => 1, "message" => "Missing symbol or price"];
    }

    $stockSymbol = strtoupper(trim($request['symbol']));
    $stockPrice = (float)$request['price'];
    $stockTime = date('Y-m-d H:i:s');

    $stockPriceQuery = $brokerDB->prepare("INSERT INTO stock_prices (symbol,price,updated_at) VALUES (?,?,?)");
    $stockPriceQuery->bind_param("sds",$stockSymbol,$stockPrice,$stockTime);
    $stockPriceQuery->execute();

    $stockPriceQuery->close();

    $stockUserCheck= $brokerDB->prepare("SELECT username, shares, avg_price FROM portfolio_positions WHERE symbol = ?");
    $stockUserCheck->bind_param("s",$stockSymbol);
    $stockUserCheck->execute();
    $stockUserCheck->bind_result($stockUsername,$stockShares,$stockAverage);

    $stockThreshhold = 0.05;
    while($stockUserCheck->fetch()){
        if($stockShares<=0){
            continue;
        }
        $valueHigh=$stockAverage*(1+$stockThreshhold);
        $valueLow=$stockAverage*(1-$stockThreshhold);
        $stockAlert = null;

        if($stockPrice>$valueHigh){
            $stockAlert="high";
        }elseif($stockPrice<=$valueLow){
            $stockAlert="low";
        }
        $stockEmailQuery = $brokerDB->prepare("SELECT email FROM users WHERE username = ?");
        $stockEmailQuery->bind_param("s",$stockUsername);
        $stockEmailQuery->execute();
        $stockEmailQuery->bind_result($stockEmailAddress);
        $stockEmailQuery->fetch();
        $stockEmailQuery->close();

        if($stockAlert!==null){
            $notifContent = [
                "type"      => "stock_alert",
                "username"  => $stockUsername,
                "email" => $stockEmailAddress,
                "symbol"    => $stockSymbol,
                "price"     => $stockPrice,
                "alertType" => $stockAlert,
                "timestamp" => $stockTime
            ];

            try{
               $response = $stockWorkerClient->send_request($notifContent);
            }catch (Exception $e){
                echo "failed alert";
            }
        }
    }
}

$stockServer = new rabbitMQServer("testRabbitMQ.ini","sharedServer2");
echo "Stock Server ready and on standby..." . PHP_EOL;
$stockServerPid = pcntl_fork();
if ($stockServerPid == 0){
    $stockServer->process_requests('requestProcessor');
    exit();
}
