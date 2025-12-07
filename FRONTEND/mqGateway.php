<?php
declare(strict_types= 1);

$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header("Access-Control-Allow-Origin: $origin");
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Vary: Origin');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

header('Content-Type: application/json; charset=utf-8');

function json_response(array $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function set_session_cookies(array $session_cookie): bool {
    if (empty($session_cookie['session_id']) || empty($session_cookie['auth_token'])) return false;

    $expiresAt = $session_cookie['expires_at'] ?? '';
    $expTs = 0;

    if (is_string($expiresAt) && $expiresAt !== '')  {
        $ts = strtotime($expiresAt);
        if ($ts !== false) $expTs = $ts;
    }

    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== "off";

    $opts = [
        'expires'   => $expTs,
        'path'      => '/',
        'secure'    => $secure,
        'httponly'  => true,
        'samesite'  => 'Lax'
    ];

    $ok1 = @setcookie('session_id', (string)$session_cookie['session_id'], $opts);
    $ok2 = @setcookie('auth_token', (string)$session_cookie['auth_token'], $opts);
    return $ok1 && $ok2;
}

function clear_session_cookies(): void {
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== "off";

    $opts = [
        'expires'    => time() - 3600,
        'path'       => '/',
        'secure'     => $secure,
        'httponly'   => true,
        'samesite'   => 'Lax'
    ];
    @setcookie('session_id', '', $opts);
    @setcookie('auth_token', '', $opts);
}

$raw = file_get_contents('php://input');
$input = null;

if (is_string($raw) && $raw !== '') {
    $decoded = json_decode($raw, true);

    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $input = $decoded;
    }
}

if (!is_array($input)) {
    json_response(['error' => 'Invalid JSON input'], 400);
}

$type = strtolower((string)($input['type'] ?? ''));

// allow existing types + new get_stock_value type
$allowedTypes = [
    'login',
    'register',
    'validate_session',
    'create_thread',
    'list_threads',
    'create_comment',
    'list_comment',
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
    $backendUrl = "http://100.114.135.58:5002/getStockValues?symbol="
                  . urlencode($symbol) . "&interval=" . urlencode($interval);
                  

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'timeout' => 10,
        ]
    ]);

    $rawBackend = @file_get_contents($backendUrl, false, $ctx);
    if ($rawBackend === false) {
        json_response(['status' => 'error', 'error' => 'Failed to contact stock service'], 502);
        error_log("[gateway] Failed to contact stock service at $backendUrl");
        error_log("[gateway] Context: " . print_r($ctx, true));
    }

    $decoded = json_decode($rawBackend, true);
    if (!is_array($decoded)) {
        json_response(['status' => 'error', 'error' => 'Non-JSON response from stock service'], 502);
    }

    $tsKey = null;
    foreach ($decoded as $k => $v) {
        if (strpos($k, "Time Series") === 0) {
            $tsKey = $k;
            break;
        }
    }

    if ($tsKey === null || !isset($decoded[$tsKey]) || !is_array($decoded[$tsKey])) {
        if (isset($decoded['Error Message'])) {
            json_response([
                'status' => 'error',
                'error'  => 'Stock service error',
                'detail' => $decoded['Error Message']
            ], 502);
        }
        json_response([
            'status' => 'error', 
            'error' => 'Malformed response from stock service', 
            'detail' => $decoded], 
            502
        );
    }

    $timeSeries = $decoded[$tsKey];

    $times = array_keys($timeSeries);
    sort($times);
    $latestTime  = end($times);
    $latestPoint = $timeSeries[$latestTime] ?? null;

    $latestPrice = $latestPoint ? (float)($latestPoint['4. close'] ?? 0) : null;

    $history = [];
    foreach ($times as $t) {
        $pt = $timeSeries[$t] ?? null;
        if (!is_array($pt)) continue;
        $history[] = [
            'time'  => $t,
            'price' => (float)($pt['4. close'] ?? 0),
        ];
    }

    json_response([
        'status' => 'success',
        'symbol' => $symbol,
        'price'  => $latestPrice,
        'history'=> $history,
    ], 200);
}

// ========== NORMAL RABBITMQ FLOW FOR LOGIN / REGISTER / FORUM ==========

$request = [
    'type'          => $type,
    'username'      => $username,
    'password'      => $password,
    'email'         => $email,
    'session_id'    => $session_id,
    'sessionId'     => $session_id,
    'sessionid'     => $session_id,
    'auth_token'    => $auth_token,
    'authToken'     => $auth_token,
    'token'         => $auth_token,
    "message"       => "Greeting from RabbitMQClient.php"
];

if ($type === 'create_thread') {
    $request['title'] = $title;
    $request['body']  = $body;
}

if ($type === "validate_session") {
    $request['action']  = 'validateSession';
    $request['op']      = 'validate_session';
}

if ($type === "place_trade"){
    $payload = json_encode([
        'symbol'        => (string)($input['symbol'] ?? $input['tickerSymbol'] ?? ''),
        'quantity'      => (int)($input['shareQuantity'] ?? $input['quantity'] ?? 0),
        'stockAction'   => (string)($input['action'] ?? $input['stockAction'] ?? ''), 
    ]);

    $backendAPITradeUrl = "http://100.114.135.58:5001/api/trade";

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'timeout' => 8,
            'header'  => "Content-Type: application/json\r\n" .
                         "Content-Length: " . strlen($payload) . "\r\n",
            'content' => $payload,
        ],   
    ]);

    $raw = @file_get_contents($backendAPITradeUrl, false, $ctx);

    if($raw === false){
        json_response([
            'status' => 'error', 
            'message' => 'Failed to contact trade Service'
        ], 502);
    }

    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        json_response([
            'status' => 'error', 
            'message' => 'Non-JSON response from trade service',
            'raw' => $raw,
        ], 502);
    }
    json_response($decoded, 200);
}

if($type === "get_portfolio") {
    $backendPortfolioUrl = "http://100.114.135.58:5001/api/portfolio";

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'timeout' => 8,
        ],   
    ]);

    $raw = @file_get_contents($backendPortfolioUrl, false, $ctx);
    
    if ($raw === false) {
        json_response([
            'status' => 'error', 
            'message' => 'Failed to contact portfolio Service'
        ], 502);
    }

    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        json_response([
            'status' => 'error', 
            'message' => 'Non-JSON response from portfolio service'
        ], 502);
    }

    json_response($decoded, 200);
}

try {
    $client = new rabbitMQClient("testRabbitMQ.ini","sharedServer");
    error_log('[gateway] sending: ' . json_encode($request));
    $response = $client->send_request($request);
    error_log('[gateway] reply: ' . json_encode($response));

    if (!is_array($response)) {
        $response = ['returnCode' => 99, 'message' => (string)$response];
    }

    $ok = false;

    if (isset($response['status']) && strtolower((string)$response['status']) === 'success') $ok = true;
    if (isset($response['returnCode']) && (int)$response['returnCode'] === 0) $ok = true;

    $cookieSet = false;

    if ($ok && isset($response['session']) && is_array($response['session'])) {
        $cookieSet = set_session_cookies($response['session']);
    }

    if ($type === 'validate_session' && !$ok) {
        clear_session_cookies();
    }

    $result = [
        'status'        => $ok ? 'success' : 'error',
        'returnCode'    => (int)($response['returnCode'] ?? ($ok ? 0 : 1)),
        'message'       => $response['message'] ?? '',
        'session'       => $response['session'] ?? null,
        'cookieSet'     => $cookieSet,
        'session_valid' => ($type === 'validate_session') ? $ok : null
    ];

    json_response($result, 200);
} catch (Throwable $e) {
    error_log('testRabbitMQClient.php exception: ' . $e->getMessage());
    json_response([
        'status'    => 'error',
        'returnCode'=> 1,
        'message'   => 'Gateway Error communicating with backend'
    ], 500);
}
