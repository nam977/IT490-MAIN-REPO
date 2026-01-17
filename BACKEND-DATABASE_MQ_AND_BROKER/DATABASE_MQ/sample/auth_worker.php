#!/usr/bin/php
<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

require_once __DIR__ . '/vendor/autoload.php';
$db_host = '127.0.0.1';
$db_user = 'testuser';
$db_pass = 'rv9991$#';
$db_name = 'testdb';    

$brokerDB = new mysqli($db_host,$db_user,$db_pass,$db_name);

if ($brokerDB -> connect_errno!= 0){
    echo "failed to connect to the database" . $brokerDB -> connect_error . PHP_EOL;
    exit();
}else{
    echo "successful database connectoin" . PHP_EOL;
}

function tradeStock($request){
    global $brokerDB;

     if(!isset($request['username'])||!$request['auth_token']){
        return ["returnCode"=>1,"message"=>"username and authentication token not set"];
    }

    $valueCheck=$brokerDB->prepare("SELECT username FROM user_cookies WHERE session_id = ? AND auth_token = ? AND expiration_time > NOW()");
    $valueCheck->bind_param("ss",$request['session_id'],$request['auth_token']);
    $valueCheck-> execute();
    $valueCheck->bind_result($username);

    if(!$valueCheck->fetch()){
        return ["returnCode"=>1,"message"=>"session invalid"];
    }
    $stockPrices = $brokerDB->prepare("SELECT price FROM stock_prices WHERE symbol = ?" );
    $stockPrices->bind_param("s",$request['symbol']);
    $stockPrices->execute();
    $stockPrices->bind_result($marketPrice);
    if(!$stockPrices->fetch()){
        return ["returnCode"=>1,"message"=>"symbol invalid for purchase"];
    }
    $stockPrices->close();

    if($request['tradeType']=="buy"){
        $tradeBuy = $brokerDB->prepare("SELECT id, shares, avg_price FROM portfolio_positions WHERE username = ? AND symbol = ?");
        $tradeBuy->bind_param("ss",$username,$request['symbol']);
        $tradeBuy->execute();
        $tradeBuy->bind_result($storeTradeID,$prevStoreShares,$prevAvg);

        if($tradeBuy->fetch()){
            $newStoreShares = $prevStoreShares+$request['quantity'];
            $newStoreAvg = ($prevStoreShares*$prevAvg)+($request['quantity']*$marketPrice)/$newStoreShares;

            $updateToPrev = $brokerDB -> prepare("UPDATE portfolio_positions SET shares = ?, avg_prices = ?, last_price = ? WHERE id = ?");
            $updateToPrev->bind_param("dddi",$newStoreShares,$newStoreAvg,$marketPrice,$storeTradeID);
            $updateToPrev->execute();
            
            $updateToPrev->close();

        }else{
            $newTradeBuy = $brokerDB->prepare("INSERT INTO portfolio_prices (username,symbol,shares,avg_price,last_price) VALUES(?,?,?,?,?)");
            $newTradeBuy->bind_param("ssddd",$username,$request['symbol'],$request['quantity'],$marketPrice,$marketPrice);
            $newTradeBuy->execute();
            $newTradeBuy->close();
        }
        $tradeBuy->close();
    }elseif($request['tradeType']=="sell"){
        $tradeSell = $brokerDB->prepare("SELECT id, shares, avg_price FROM portfolio_positions WHERE username = ? AND symbol = ?");
        $tradeSell->bind_param("ss",$username,$request['symbol']);
        $tradeSell->execute();
        $tradeSell->bind_result($storeTradeID,$prevStoreShares,$prevAvg);

        if(!$tradeSell->fetch()){
            return ["returnCode"=>1,"message"=>"nothing to sell"];
        }
        $tradeSell->close();
        if($prevStoreShares<$request['quantity']){
            return["returnCode"=>1,"message"=>"shares too few"];
        }
        $newStoreShares = $prevStoreShares-$request['quantity'];
        if($newStoreShares>0){

            $newTradeSell = $brokerDB->prepare("UPDATE portfolio_positions SET shares = ?, last_price = ? WHERE id = ?");

            $newTradeSell->bind_param("ddi",$newStoreShares,$marketPrice,$storeTradeID);
            $newTradeSell->execute();
            $newTradeSell->close();
        }else{
            $deleteTradeSell = $brokerDB->prepare("DELETE FROM portolio_positions WHERE id = ?");
            $deleteTradeSell->bind_param("i",$storeTradeID);
            $deleteTradeSell->execute();
            $deleteTradeSell->close();
        }
    }

    $tradeLog=$brokerDB->prepare("INSERT INTO trade_history (username,action,symbol,price");
    $tradeLog->bind_param("sss id",$username,$request['tradeType'],$request['symbol'],$request['quantity'],$marketPrice);
    $tradeLog->execute();
    $tradeLog->close();

    return["returnCode"=>0,"message"=>"successful trade","symbol"=>$request['symbol'],"quantity"=>$request['quantity'],"price"=>$marketPrice];
}

function profileValuation($request){
    global $brokerDB;

    if(!isset($request['username'])||!$request['auth_token']){
        return ["returnCode"=>1,"message"=>"username and authentication token not set"];
    }

    $valueCheck=$brokerDB->prepare("SELECT username FROM user_cookies WHERE session_id = ? AND auth_token = ? AND expiration_time > NOW()");
    $valueCheck->bind_param("ss",$request['session_id'],$request['auth_token']);
    $valueCheck-> execute();
    $valueCheck->bind_result($username);

    if(!$valueCheck->fetch()){
        return ["returnCode"=>1,"message"=>"session invalid"];
    }

    $profVal = $brokerDB->prepare("SELECT symbol,shares,avg_price,last_price FROM portfolio_positions WHERE username=?");
    $profVal-> execute();
    $resultVals = $profVal->get_result();
    if($resultVals->num_rows==0){
        return ["returnCode"=>1,"message"=>"nothing saved"];
    }

    $finalValuation = [];
    $totalProfMarket = 0;
    $totalGainLoss = 0;
    
    while($row = $resultVals->fetch_assoc()){
        $symbol = $row['symbol'];
        $shares = floatval($row['shares']);
        $avgPrice = floatval($row['avg_price']);

        $currentVal=$brokerDB->prepare("SELECT price FROM stock_prices WHERE symbol = ? ORDER BY updated_at DESC LIMIT 1");
        $currentVal->bind_param("s",$symbol);
        $currentVal->execute();
        $currentVal->bind_result($currentPrice);
        $currentVal->fetch();
        $currentVal->close();

        if(!$currentVal){
            $currentPrice= $row['last_price'];
        }

        $marketVal = $shares*$currentPrice;
        $gainLoss = ($currentPrice-$avgPrice)*$shares;
        $gainLossPercentage = (($currentPrice-$avgPrice)/$avgPrice) * 100;

        $finalValuation[]=[
            'symbol' => $symbol,
            'shares'=>$shares,
            'avg_price'=>$avgPrice,
            'current_price' => $currentPrice,
            'market_value'=>$marketVal,
            'gain_loss' => $gainLoss,
            'gain_loss_percent'=>$gainLossPercentage
        ];
        $totalProfMarket+=$marketVal;
        $totalGainLoss+=$gainLoss;
    }

    return ["returnCode"=>0,
        "username"=>$username,
        "valuation"=>$finalValuation,
        "totals"=>[
            "market_value" => $totalProfMarket,
            "gain_loss"=>$totalGainLoss
        ]
    ];
    
}
/*function doSearch($request){
    global $brokerDB;
    
    if(!isset($request['username'])||!$request['auth_token']){
        return ["returnCode"=>1,"message"=>"username and authentication token not set"];
    }

    $valueCheck=$brokerDB->prepare("SELECT username FROM user_cookies WHERE session_id = ? AND auth_token = ? AND expiration_time > NOW()");
    $valueCheck->bind_param("ss",$request['session_id'],$request['auth_token']);
    $valueCheck-> execute();
    $valueCheck->bind_result($username);

    if(!$valueCheck->fetch()){
        return ["returnCode"=>1,"message"=>"session invalid"];
    }

    $searchQuery = $brokerDB->prepare("SELECT * FROM stock_prices WHERE symbol = ? LIMIT 1");
    $searchQuery->bind_param("s",$request['symbol']);
    
}*/ 
function doRegistration($username,$password,$email){
    global $brokerDB; 
    $registerCheckQuery = $brokerDB->prepare("SELECT*FROM users WHERE username = ? LIMIT 1");
    $registerCheckQuery -> bind_param("s",$username);

    $registerCheckQuery -> execute();
    $registerCheckQuery -> store_result();

    if($registerCheckQuery-> num_rows > 0){
        $registerCheckQuery->close();
        return ["returnCode"=>1,"message"=>"username already exists"];
    }

    $registerCheckQuery -> close();
    $passwordHash = password_hash($password, PASSWORD_BCRYPT);
    $registeredUserQuery = $brokerDB->prepare("INSERT INTO users (username,password,email) VALUES(?,?,?)");
    $registeredUserQuery->bind_param("sss",$username,$passwordHash,$email);


    if(!$registeredUserQuery->execute()){
        return ["returnCode"=>1,"message"=>"failed registration:" . $registeredUserQuery->error];
    }

    $registeredUserQuery->close();
    return["returnCode"=>1, "message"=>"successful registration"];
}       

function doLogin($username,$password){
    global $brokerDB;

    $loginQuery = $brokerDB->prepare("SELECT passsword FROM users WHERE username = ? LIMIT 1");
    $loginQuery->bind_param("s",$username);
    $loginQuery->execute();
    $loginQuery->bind_result($hashedPassword);
    $loginQuery->fetch();
    $loginQuery->close(); 
    if(!$hashedPassword){
        return ["returnCode"=>1,"message"=>"nonexistent user"];
    }
    if(!password_verify($password,$hashedPassword)){
        return ["returnCode"=>1,"message"=>"invalid password"];
    }

    $loginSessionID = bin2hex(random_bytes(16));
    $loginAuthToken = bin2hex(random_bytes(32));
    $sessonExpiration = date('Y-m-d H:i:s',time()+3600);
    
    $loginQuery=$brokerDB->prepare("INSERT INTO user_cookies(session_id, username, auth_token, expiration_time)VALUES(?, ?, ?, ?)");
    if(!$loginQuery->execute()){
        return ["returnCode"=>1,"message"=>"session startup failed"];
    }
    $loginQuery->close();

    return [
        "returnCode" => 0,"message" => "Login successful","session" => ["session_id" => $loginSessionID,"auth_token" => $loginAuthToken,"expires" => $sessonExpiration]
    ];
}

function doValidation($sessionID,$authToken){
    global $brokerDB;

    $validationQuery = $brokerDB->prepare("SELECT username FROM user_cookies WHERE session_id = ? and auth_token = ? AND expiration_time > NOW()");
    $validationQuery->bind_param("ss",$sessionID,$authToken);
    $validationQuery->execute();
    $validationQuery->store_result();

    $validationQueryCheck = $validationQuery->num_rows>0;
    $validationQuery->close();
    if($validationQueryCheck){
        return["returnCode"=>0,"message"=>"Valid session"];
    }else{
        return["returnCode"=>1,"message"=>"Inalid session"];
    }
}

function requestProcessor($request){
      echo "Request Recevived" . PHP_EOL;
    var_dump($request);

    if (!isset($request['type'])) {
        return ["returnCode" => 1, "message" => "No type provided"];
    }
        switch ($request['type']) {
        case "register":
            return doRegistration($request['username'], $request['password'], $request['email']);
        case "login":
            return doLogin($request['username'], $request['password']);
        case "validate_session":
            return doValidation($request['sessionId'], $request['authToken']);
        case "trade":
            return tradeStock($request);
        case "valuate":
            return profileValuation($request);
        case "search":
        default:
            return ["returnCode" => 1, "message" => "Invalid request type :D"];
    }

  
}
$authServer = new rabbitMQServer("testRabbitMQ.ini","sharedServer");
echo "Authentictation Server ready and on standby..." . PHP_EOL;
$authServerPid = pcntl_fork();
if ($authServerPid == 0){
    $authServer->process_requests('requestProcessor');
    exit();
}
