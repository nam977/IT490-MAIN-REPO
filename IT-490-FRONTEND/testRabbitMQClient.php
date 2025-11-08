<?php
declare(strict_types= 1);

// Improved CORS handling based on the article recommendations
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed_origins = [
    'http://localhost',
    'http://127.0.0.1',
    'http://localhost:80',
    'http://127.0.0.1:80'
];

// Dynamic origin handling as recommended in the article
if (in_array($origin, $allowed_origins) || ($origin === '' && isset($_SERVER['HTTP_HOST']))) {
    header("Access-Control-Allow-Origin: " . ($origin ?: 'http://' . $_SERVER['HTTP_HOST']));
} else {
    header("Access-Control-Allow-Origin: http://localhost");
}

header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Vary: Origin');

// Handle preflight requests properly as recommended in the article
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
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

if(is_string($raw) && $raw !== '') {
    $decoded = json_decode($raw, true);

    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)){
        $input = $decoded;
    } 
} 

if (!is_array($input)) {
    json_response(['error' => 'Invalid JSON input'], 400);
}

$type = strtolower((string)($input['type'] ?? ''));
if (!in_array($type, ['login', 'register', 'validate_session', 'create_thread', 'list_threads', 'create_comment', 'list_comment'], true)) {
    json_response(['error' => 'Unknown request type'], 400);
}

$username       = (string)($input['username'] ?? '');
$password       = (string)($input['password'] ?? '');
$email          = (string)($input['email'] ?? '');
$session_id     = (string)($input['sessionId'] ?? $input['session_id'] ?? $input['sessionid'] ?? '');
$auth_token     = (string)($input['authToken'] ?? $input['auth_token'] ?? $input['authtoken'] ?? '');

if ($session_id === '' && isset($_COOKIE['session_id'])) {
    $session_id = (string)$_COOKIE['session_id'];
}

if ($auth_token === '' && isset($_COOKIE['auth_token'])) {
    $auth_token = (string)$_COOKIE['auth_token'];
}


$title  = (string)($input['title'] ?? '');
$body   = (string)($input['body'] ?? '');


$request = [
    'type'          => $type,
    'username'      => $username,
    'password'      => $password,
    'email'         => $email,
    'session_id'    => $session_id,
    'auth_token'    => $auth_token,
    'authToken'     => $auth_token,
    'token'         => $auth_token,
    "message"       => "Greeting from RabbitMQClient.php"
];

if ($type === 'create_thread'){
    $request['title'] = $title;
    $request['body'] = $body;
}

if ($type === "validate_session") {
    $request['action']  = 'validateSession';
    $request['op']      = 'validate_session';   
}

// Load gateway config (use INI to control fallback behavior)
$hostInfo = getHostInfo(array('testRabbitMQ.ini'));
$gwCfg = $hostInfo['sharedServer'] ?? [];
$allow_fallback = false;
if (isset($gwCfg['USE_FALLBACK'])) {
    // parse possible string values like 'false'/'true'
    $allow_fallback = filter_var($gwCfg['USE_FALLBACK'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($allow_fallback === null) $allow_fallback = false;
}
// allow environment override for quick testing
$envAllow = getenv('USE_FALLBACK');
if ($envAllow !== false) {
    $envBool = filter_var($envAllow, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($envBool !== null) $allow_fallback = $envBool;
}

// If fallback is explicitly allowed, short-circuit and return a deterministic
// success response (useful for development/testing when RabbitMQ is unreachable)
if ($allow_fallback) {
    // produce a fake successful response for login/register operations
    $fakeResponse = ['returnCode' => 0, 'message' => 'Login successful (fallback)', 'session' => [
        'session_id' => uniqid('sess_', true),
        'auth_token' => bin2hex(random_bytes(12)),
        'expires_at' => date(DATE_ATOM, time() + 3600)
    ]];

    $cookieSet = false;
    if (isset($fakeResponse['session']) && is_array($fakeResponse['session'])) {
        $cookieSet = set_session_cookies($fakeResponse['session']);
    }

    $result = [
        'status' => 'success',
        'returnCode' => 0,
        'message' => $fakeResponse['message'],
        'session' => $fakeResponse['session'],
        'cookieSet' => $cookieSet
    ];
    json_response($result, 200);
}

try {
    // All requests must go through RabbitMQ to the backend server
    $client = new rabbitMQClient("testRabbitMQ.ini","sharedServer");
    error_log('[gateway] sending: ' . json_encode($request));
    $response = $client->send_request($request);
    error_log('[gateway] reply: ' . json_encode($response));

    if(!is_array($response)) {
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
} catch (Throwable $e){
    error_log('testRabbitMQClient.php exception: ' . $e->getMessage());
    json_response([
        'status'    => 'error',
        'returnCode'=> 1,
        'message' => 'Gateway Error communicating with backend'
    ], 500);
}
