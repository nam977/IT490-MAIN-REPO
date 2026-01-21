#!/usr/bin/php
<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

function getDBConnection() {
    $mysqli = new mysqli("127.0.0.1", "testuser", "rv9991$#", "testdb");
    if ($mysqli->connect_errno) {
        echo "Failed to connect to MySQL: " . $mysqli->connect_error . PHP_EOL;
        exit(1);
    }
    return $mysqli;
}

function createSession($username)
{
    $db = getDBConnection();
    $session_id = bin2hex(random_bytes(16));
    $auth_token = bin2hex(random_bytes(16));
    $expiration = date('Y-m-d H:i:s', time() + (86400 * 30));

    $stmt = $db->prepare("INSERT INTO user_cookies (session_id, username, auth_token, expiration_time) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $session_id, $username, $auth_token, $expiration);
    $stmt->execute();

    return [
        'session_id' => $session_id,
        'auth_token' => $auth_token,
        'expiration_time' => $expiration
    ];
}

function doRegister($username, $password, $email)
{
    $db = getDBConnection();
    $stmt = $db->prepare("SELECT userId FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        return ["returnCode" => 1, "message" => "Username already exists"];
    }

    $hash = password_hash($password, PASSWORD_BCRYPT, ["cost" => 12]);
    $insert = $db->prepare("INSERT INTO users (username, password, email) VALUES (?, ?, ?)");
    $insert->bind_param("sss", $username, $hash, $email);
    if (!$insert->execute()) {
        return ["returnCode" => 2, "message" => "DB insert failed: " . $db->error];
    }

    $session = createSession($username);
    return ["returnCode" => 0, "message" => "Registration successful", "session" => $session];
}

function doLogin($username, $password)
{
    $db = getDBConnection();
    $stmt = $db->prepare("SELECT password FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows === 0) {
        return ["returnCode" => 1, "message" => "User not found"];
    }

    $stmt->bind_result($hash);
    $stmt->fetch();
    if (password_verify($password, $hash)) {
        $session = createSession($username);
        return ["returnCode" => 0, "message" => "Login successful", "session" => $session];
    } else {
        return ["returnCode" => 2, "message" => "Invalid password"];
    }
}

function getMyPortfolio(array $request){
    $sessionCheck = requireValidSession($request);

    if($sessionCheck['returnCode'] !== 0){
        return $sessionCheck;
    }

    $username = $sessionCheck['username'] ?? 'demo';

    $startingCash   = 100000.00;
    $cashBalance    = 9500.00;
    $holdingsValue  = 500.00;
    $totalEquity    = $startingCash;

    $holdings = [
        [
            "symbol"        => "AAPL",
            "shares"        => 10,
            "avg_price"     => 150.00,
            "last_price"    => 130.50,
            'market_value'  => 260.20,
        ],
        [
            "symbol"        => "GOOGL",
            "shares"        => 5,
            "avg_price"     => 2500.00,
            "last_price"    => 2400.00,
            'market_value'  => 1200.00,
        ],
    ];

    $myTrades = [
        [
            'timestamp'  => date('Y-m-d H:i:s', strtotime('-2 days')),
            'action'     => 'stockBuy',
            'symbol'     => 'AAPL',
            'shares'     => 10,
            'price'      => 150.00,
        ],
        [
            'timestamp'  => date('Y-m-d H:i:s', strtotime('-1 days')),
            'action'     => 'stockBuy',
            'symbol'     => 'MSFT',
            'shares'     => 5,
            'price'      => 2500.00,
        ],
    ];

    return [
        'status'       => 'success',
        'returnCode'   => 0,
        'message'      => 'Portfolio retrieved successfully',
        'username'     => $username,
        'startingCash' => $startingCash,
        'cashBalance'  => $cashBalance,
        'holdingsValue'=> $holdingsValue,
        'totalEquity'  => $totalEquity,
        'holdings'     => $holdings,
        'trades'       => $myTrades,
    ];
}

function doPlaceTrade(array $request){
    $mySessionCheck = requireValidSession($request);

    if ($mySessionCheck['returnCode'] !== 0) {
        return $mySessionCheck;
    }

    $username = $mySessionCheck['username'] ?? 'demo';

    $symbol   = strtoupper(trim($request['symbol'] ?? ''));
    $qty      = (int)($request['quantity'] ?? 0);
    $action   = $request['stockAction'] ?? $request['action'] ?? '';

    if ($symbol === '' || $qty <= 0 || ($action != 'stockBuy' && $action !== 'stockSell')) {
        return [
            "status" => "error",
            "returnCode" => 1,
            "message" => "Invalid trade parameters"
        ];
    }

    $dummyPrice = 123.45;

    return [
        'status'    => 'success',
        'returnCode'=> 0,
        'message'   => sprintf(
            '%s %d %s shares at $%.2f (stub, no DB update yet)',
            ($action === 'stockBuy' ? 'Bought' : 'Sold'),
            $qty,
            $symbol,
            $dummyPrice
        )
    ];
}

function doValidate($sessionId)
{
    $db = getDBConnection();
    $stmt = $db->prepare("SELECT username, expiration_time FROM user_cookies WHERE session_id = ?");
    $stmt->bind_param("s", $sessionId);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows === 0) {
        return ["returnCode" => 1, "message" => "Invalid session"];
    }

    $stmt->bind_result($username, $expiration);
    $stmt->fetch();
    if (strtotime($expiration) < time()) {
        return ["returnCode" => 2, "message" => "Session expired"];
    }

    return ["returnCode" => 0, "message" => "Session valid", "username" => $username];
}


function requireValidSession(array $request){
    $mySessionId = $request['sessionId'] ?? $request['session_id'] ?? $request['sessionID'] ?? '';

    if ($mySessionId === '') {
        return [
            "returnCode" => 99, 
            "status" => "error",
            "message" => "Session ID missing"
        ];
    }

    $result = doValidate($mySessionId);

    if ($result['returnCode'] !== 0) {
        return [
            "returnCode" => $result['returnCode'], 
            "status" => "error",
            "message" => $result['message'] ?? "Session validation failed"  
        ];
    }
    return [
        "returnCode" => 0,
        "status" => "success",
        "username" => $result['username'] ?? null
    ];
}

function requestProcessor($request)
{
    echo "DB Server received:" . PHP_EOL;
    var_dump($request);

    switch (strtolower($request['type'])) {
        case "register":
            return doRegister($request['username'], $request['password'], $request['email']);
        case "login":
            return doLogin($request['username'], $request['password']);
        case "validate_session":
            return doValidate($request['sessionId']);
        case "get_portfolio":
            return getMyPortfolio($request);
        case "place_trade":
            return doPlaceTrade($request);
        default:
            return [
                "returnCode" => 98, 
                "status" => "error",
                "message" => "Unknown DB request type"
            ];
    }
}

$server = new rabbitMQServer("testRabbitMQ.ini", "dbServer");
echo "DB Server listening..." . PHP_EOL;
$server->process_requests('requestProcessor');
?>

