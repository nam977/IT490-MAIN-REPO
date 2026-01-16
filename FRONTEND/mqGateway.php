<?php
declare(strict_types= 1); # Enable strict typing

/*
    mqGateway.php

    A gateway API that uses RabbitMQ to communicate with backend services.
    Supports user authentication, portfolio management, and stock value retrieval.

    CORS headers are set to allow cross-origin requests with credentials.
    JSON input is expected and JSON responses are returned.

    Session cookies are managed for authentication purposes.
*/
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header("Access-Control-Allow-Origin: $origin");
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Vary: Origin');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { # Preflight request
    http_response_code(200); # OK
    exit;
}

/*
    Include required RabbitMQ library files
*/

require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

header('Content-Type: application/json; charset=utf-8'); # Set response content type to JSON


/*
    Send a JSON response and terminate the script.
*/

function json_response(array $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function set_session_cookie(array $session_cookie): bool {
    if (empty($session_cookie['session_id']) || empty($session_cookie['auth_token'])) return false;

    $expiresAt = $session_cookie['expires_at'] ?? '';
    $expTs = 0;

    if (is_string($expiresAt) && $expiresAt !== '')  {
        $ts = strtotime($expiresAt);
        if ($ts !== false) $expTs = $ts;
    }

    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== "off";
    // Set cookies with appropriate flags
    $opts = [
        'expires'   => $expTs,
        'path'      => '/',
        'secure'    => $secure,
        'httponly'  => true,
        'samesite'  => 'Lax'
    ];
    // Set the cookies
    $ok1 = @setcookie('session_id', (string)$session_cookie['session_id'], $opts);
    $ok2 = @setcookie('auth_token', (string)$session_cookie['auth_token'], $opts);
    return $ok1 && $ok2;
}

// Clear session cookies
function clear_session_cookies(): void {
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== "off";

    $opts = [ // Expire cookies in the past
        'expires'    => time() - 3600,
        'path'       => '/',
        'secure'     => $secure,
        'httponly'   => true,
        'samesite'   => 'Lax'
    ];
    @setcookie('session_id', '', $opts); // Clear session_id cookie
    @setcookie('auth_token', '', $opts); // Clear auth_token cookie
}

$raw = file_get_contents('php://input'); // Read raw input data
$input = null;

if (is_string($raw) && $raw !== '') { // Attempt to decode JSON input
    $decoded = json_decode($raw, true);
    /*
        If JSON decoding is successful and results in an array, use it as input.
    */
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $input = $decoded;
    }
}

if (!is_array($input)) {
    json_response(['error' => 'Invalid JSON input'], 400);
}

$type = strtolower((string)($input['type'] ?? ''));

$workerType = $type;

if ($type === "place_trade") {
    $workerType = 'trade';
} elseif($type === "get_portfolio") {
    $workerType = 'valuate';
}

// allow existing types + new get_stock_value type
$allowedTypes = [
    'login',
    'register',
    'validate_session',
    'get_stock_value', 
    'get_portfolio',
    'place_trade',
];

if (!in_array($type, $allowedTypes, true)) {
    json_response(['error' => 'Unknown request type'], 400);
}

$username   = (string)($input['username'] ?? '');
$password   = (string)($input['password'] ?? '');
$email      = (string)($input['email'] ?? '');
$session_id = (string)($input['sessionId'] ?? $input['session_id'] ?? $input['sessionid'] ?? '');
$auth_token = (string)($input['authToken'] ?? $input['auth_token'] ?? $input['authtoken'] ?? '');

$symbol     = (string)($input['symbol'] ?? '');
$quantity   = isset($input['shareQuantity']) 
                ? (int)$input['shareQuantity'] 
                : (isset($input['quantity']) ? (int)$input['quantity'] : 0);

$trade_type = (string)($input['action'] ?? $input['stockAction'] ?? '');
    

if ($session_id === '' && isset($_COOKIE['session_id'])) {
    $session_id = (string)$_COOKIE['session_id'];
}

if ($auth_token === '' && isset($_COOKIE['auth_token'])) {
    $auth_token = (string)$_COOKIE['auth_token'];
}

$title  = (string)($input['title'] ?? '');
$body   = (string)($input['body'] ?? '');

// ========== SPECIAL CASE: get_stock_value (call Flask app.py on backend) ==========
if ($type === 'get_stock_value') {
    $symbol   = (string)($input['symbol'] ?? '');
    $interval = (string)($input['interval'] ?? '1min');

    if ($symbol === '') {
        json_response(['status' => 'error', 'error' => 'Missing symbol'], 400);
    }

    // Adjust this IP if your backend+broker uses a different address
<<<<<<< HEAD
<<<<<<< HEAD
    $backendUrl = "http://100.114.135.58:5002/getStockValues?symbol="
=======
    $backendUrl = "http://127.0.0.1:5002/getStockValues?symbol="
>>>>>>> 3fe2ec0 (added if statements for get_portfolio and make_trade for app.py services in connection to alphavanguard. needs testing)
=======
    $backendUrl = "http://100.114.135.58:5002/getStockValues?symbol="
>>>>>>> c60d851d643971405856ab6eefad8ffd66079e10
                  . urlencode($symbol) . "&interval=" . urlencode($interval);
                  

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'timeout' => 10,
        ]
    ]);
    /*
        Contact the backend stock service and retrieve stock data.
    */
    $rawBackend = @file_get_contents($backendUrl, false, $ctx);
    if ($rawBackend === false) {
        json_response(['status' => 'error', 'error' => 'Failed to contact stock service'], 502);
        error_log("[gateway] Failed to contact stock service at $backendUrl");
        error_log("[gateway] Context: " . print_r($ctx, true));
    }
    /*
        Decode the JSON response from the backend stock service.
    */
    $decoded = json_decode($rawBackend, true);
    if (!is_array($decoded)) {
        json_response(['status' => 'error', 'error' => 'Non-JSON response from stock service'], 502);
    }

    /*   
    Parse the time series data from the response.
    */
    $tsKey = null;
    foreach ($decoded as $k => $v) {
        if (strpos($k, "Time Series") === 0) {
            $tsKey = $k;
            break;
        }
    }
    /*
        Validate the time series data structure.
    */
    if ($tsKey === null || !isset($decoded[$tsKey]) || !is_array($decoded[$tsKey])) {
        if (isset($decoded['Error Message'])) {
            json_response([
                'status' => 'error',
                'error'  => 'Stock service error',
                'detail' => $decoded['Error Message']
            ], 502);
        }
        json_response([ // Malformed response
            'status' => 'error', 
            'error' => 'Malformed response from stock service', 
            'detail' => $decoded], 
            502
        );
    }

    $timeSeries = $decoded[$tsKey]; // Time series data

    /*
        Extract the latest price and historical data points.
    */
    $times = array_keys($timeSeries);
    sort($times);
    $latestTime  = end($times);
    $latestPoint = $timeSeries[$latestTime] ?? null;

    $latestPrice = $latestPoint ? (float)($latestPoint['4. close'] ?? 0) : null;
    /*
        Build historical price data array.
    */
    $history = [];
    foreach ($times as $t) {
        $pt = $timeSeries[$t] ?? null;
        if (!is_array($pt)) continue;
        $history[] = [
            'time'  => $t,
            'price' => (float)($pt['4. close'] ?? 0),
        ];
    }
    /*
        Return the stock value response.
    */
    json_response([
        'status' => 'success',
        'symbol' => $symbol,
        'price'  => $latestPrice,
        'history'=> $history,
    ], 200);
}

<<<<<<< HEAD
<<<<<<< HEAD
=======
if($type === 'place_trade'){
	
	$symbol = strtoupper((string)($input['symbol'] ?? ''));

	if ($symbol === '' || !preg_match('/^[A-Z0-9][A-Z0-9.-]{0,14}$/', $symbol)) {
		json_response(['status' => 'error', 'error' => 'Invalid Stock Symbol'], 400);
	}

	$quantity = (int)($input['quantity'] ?? 0);

	if ($quantity <= 0) {
		json_response(['status' => 'error', 'error' => 'Quantity must be a positive number of shares'], 400);
	}

	$action = strtolower((string)($input['action'] ?? ($input['stockAction'] ?? '')));

	if ($action === 'stockbuy') $action = 'buy';

	if ($action === 'stocksell') $action = 'sell';

	if (!in_array($action, ['buy', 'sell'], true)) {
		json_response(['status' => 'error', 'error' => 'Invalid action (use buy or sell)'], 400);
	}

	if (!empty($input['mock'])) {
		$backendUrl = "http://127.0.0.1:5001/api/trade";
		
		$ctx = stream_context_create([
			'http' =>  [
				'method' 	=> 'POST',
				'timeout' 	=> 10,
				'header'	=> "Content-Type: application/json\r\n",
				'content'	=> json_encode([
					'symbol'	=> $symbol,
					'quantity'	=> $quantity,
					'action'	=> $action,
				])
			]
		]);

		$rawBackend = @file_get_contents($backendUrl, false, $ctx);

		if ($rawBackend === false) {
			json_response(['status' => 'error', 'error' => 'Failed to contact mock trade service'], 502);
		}

		$decoded = json_decode($rawBackend, true);

		if (!is_array($decoded)) {
                        json_response(['status' => 'error', 'error' => 'Non-JSON response from mock trade service'], 502);
		}

		json_response($decoded, 200);
	}
}

if ($type === 'get_portfolio') {

	$backendUrl = "http://127.0.0.1:5001/api/portfolio";

	$ctx = stream_context_create([
		'http' => [
			'method' 	=> 'GET',
			'timeout' 	=> 10,	
		]
	]);

	$rawBackend = @file_get_contents($backendUrl, false, $ctx);

	if ($rawBackend === false) {
		json_response(['status' => 'error', 'error' => 'Failed to contact portfolio service'], 502);
		error_log(`[gateway] Failed to contact portfolio service at ${backendUrl}`);
		error_log("[gateway] Context: " . print_r($ctx, true));
	}

	$decoded = json_decode($rawBackend, true);

	if (!is_array($decoded)) {
		json_response(['status' => 'error', 'error' => 'Non-JSON response from portfolio service'], 502);
	}

	json_response($decoded, 200);
}

>>>>>>> 3fe2ec0 (added if statements for get_portfolio and make_trade for app.py services in connection to alphavanguard. needs testing)
=======
>>>>>>> c60d851d643971405856ab6eefad8ffd66079e10
// ========== NORMAL RABBITMQ FLOW FOR LOGIN / REGISTER / FORUM ==========

$request = [
    'type'          => $workerType,
    'username'      => $username,
    'auth_token'    => $auth_token,
    'session_id'    => $session_id,
];

/*
    Add additional parameters based on request type 
*/

// Authentication requests
if ($workerType === 'register'){
    $request['username'] = $username;
    $request['password'] = $password;
    $request['email']    = $email;
} elseif ($workerType === "login"){
    $request['username'] = $username;
    $request['password'] = $password;
} elseif ($workerType === "validate_session"){
    $request['sessionId'] = $session_id;
    $request['authToken'] = $auth_token;
} 

/*
    Portfolio / Trade requests
*/

if ($workerType === "trade"){
    $request['symbol']          = $symbol;
    $request['quantity']        = $quantity;
    $request['tradeType']       = strtolower($trade_type);
} elseif ($workerType === "valuate"){
    // no additional parameters needed
    $request['username'] = $username;
    $request['sessionId'] = $session_id;
    $request['authToken'] = $auth_token;
}

/*
    For debugging purposes, you can uncomment the following line to add a test message.
*/
$request['message'] = "Hello and welcome from RabbitMQClient.php";

/*
    Create RabbitMQ client and send request to backend service.
*/
try{
    $client = new rabbitMQClient("testRabbitMQ.ini", "sharedServer");
    error_log("[gateway] Sending request of type '$workerType' (original: '$type') to RabbitMQ server");
    $response = $client->send_request($request);
    error_log("[gateway] Received response from RabbitMQ server: " . print_r($response, true)); // Log response for debugging   

    if(!is_array($response)){
        $response = ['returnCode' => 99, "message" => (string)$response]; // Handle non-array responses
    }

    $ok = false;

    if(isset($response['status']) && strtolower((string)$response['status']) === "success"){ // Check for success status
        $ok = true;
    }

    if(isset($response['returnCode']) && (int)$response['returnCode'] === 0){ // Check for return code success
        $ok = true;
    }

    $cookieSet = false;

    if($ok && isset($response['session']) && is_array($response['session'])){
        $cookieSet = set_session_cookie($response['session']); // Set session cookies
    }

    if($type === 'validate_session' && !$ok){
        clear_session_cookies();
    }   

    /*
        Build the final response to be sent back to the client.
    */

    $result = [
        'status'            => $ok ? 'success' : 'error',
        'returnCode'        => (int)($response['returnCode'] ?? ($ok ? 0 : 1)),
        'message'           => $response['message'] ?? '',
        'session'           => $response['session'] ?? null,
        'cookieSet'         => $cookieSet,
        'session_valid'     => ($type === 'validate_session' ? $ok : null),

    ];

    json_response($result, 200);
}catch(Exception $e){
    error_log("[gateway] Exception occurred: " . $e->getMessage());
    json_response( // Return error response on exception
        [
            'status' => 'error', 
            'returnCode' => 1,
            'error' => 'Gateway Error Communicating with backend'
        ], 500);  
<<<<<<< HEAD
<<<<<<< HEAD
}
=======
}
>>>>>>> 3fe2ec0 (added if statements for get_portfolio and make_trade for app.py services in connection to alphavanguard. needs testing)
=======
}
>>>>>>> c60d851d643971405856ab6eefad8ffd66079e10
