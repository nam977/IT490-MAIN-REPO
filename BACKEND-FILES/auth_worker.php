#!/usr/bin/php
<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

// Detect caller (IP or hostname)
$client_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

// Database credentials
$db_host = '127.0.0.1';
$db_user = 'testuser';
$db_pass = 'rv9991$#';
$db_name = 'testdb';

// Connect to MySQL
$mydb = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($mydb->connect_errno != 0) {
    $msg = "Failed to connect to database: " . $mydb->connect_error;
    error_log("auth_worker: " . $msg);
    @file_put_contents('/var/log/auth_worker_rpc.log', date('c') . " ERROR " . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
    exit(1);
}

$msg = "Successfully connected to database as user: $db_user";
error_log("auth_worker: " . $msg);
@file_put_contents('/var/log/auth_worker_rpc.log', date('c') . " INFO " . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);

// -----------------------------
// Registration (with bcrypt)
// -----------------------------
function doRegister($username, $password, $email)
{
    global $mydb;

    // Check if username already exists
    $stmt = $mydb->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->store_result();

    if ($stmt->num_rows > 0) {
        $stmt->close();
        return ["returnCode" => 1, "message" => "Username already exists"];
    }

    $stmt->close();

    // Hash password using bcrypt
    $hashed_password = password_hash($password, PASSWORD_BCRYPT);

    // Insert into DB
    $stmt = $mydb->prepare("INSERT INTO users (username, password, email) VALUES(?, ?, ?)");
    $stmt->bind_param("sss", $username, $hashed_password, $email);

    if (!$stmt->execute()) {
        return ["returnCode" => 1, "message" => "Registration failed: " . $stmt->error];
    } 

    $stmt->close();
    return ["returnCode" => 0, "message" => "Registration successful"]; 
}

// -----------------------------
// Login (with bcrypt verify)
// -----------------------------
function doLogin($username, $password)
{
    global $mydb;

    // Fetch hashed password
    $stmt = $mydb->prepare("SELECT password FROM users WHERE username = ? LIMIT 1");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->bind_result($hashed_password);
    $stmt->fetch();
    $stmt->close();

    if (!$hashed_password) {
        return ["returnCode" => 1, "message" => "User not found"];
    }

    // Verify password hash
    if (!password_verify($password, $hashed_password)) {
        return ["returnCode" => 1, "message" => "Invalid password"];
    }

    // Generate session token
    $session_id = bin2hex(random_bytes(16));
    $auth_token = bin2hex(random_bytes(32));
    $expiration = date('Y-m-d H:i:s', time() + 3600);

    $stmt = $mydb->prepare("
    INSERT INTO user_cookies(session_id, username, auth_token, expiration_time) VALUES(?, ?, ?, ?)
    ");
    $stmt->bind_param("ssss", $session_id, $username, $auth_token, $expiration);
    
    if(!$stmt->execute()) {
        return ["returnCode" => 1, "message" => "Failed to create session: " . $stmt->error];
    }
    $stmt->close();

    return [
        "returnCode" => 0,
        "message" => "Login successful",
        "session" => [
            "session_id" => $session_id,
            "auth_token" => $auth_token,
            "expires" => $expiration
        ]
    ];
}

// -----------------------------    
// Session Validation
// -----------------------------
function doValidate($sessionId, $authToken)
{
    global $mydb;

    $stmt = $mydb->prepare("
    SELECT username FROM user_cookies WHERE session_id = ? AND auth_token = ? AND expiration_time > NOW()");
    $stmt->bind_param("ss", $sessionId, $authToken);
    $stmt->execute();
    $stmt->store_result();

    $isValid = $stmt->num_rows > 0;
    $stmt->close();

    if ($isValid) {
        return ["returnCode" => 0, "message" => "Valid session"];
    }else {
        return ["returnCode" => 1, "message" => "Invalid session"];
    }
}

// -----------------------------
// Request Processor
// -----------------------------
function requestProcessor($request)
{
    $reqJson = json_encode($request);
    error_log("[auth_worker][REQ] " . $reqJson);
    @file_put_contents('/var/log/auth_worker_rpc.log', date('c') . " REQ " . $reqJson . PHP_EOL, FILE_APPEND | LOCK_EX);

    if (!isset($request['type'])) {
        $resp = ["returnCode" => 1, "message" => "No type provided"];
        $respJson = json_encode($resp);
        error_log("[auth_worker][RESP] " . $respJson);
        @file_put_contents('/var/log/auth_worker_rpc.log', date('c') . " RESP " . $respJson . PHP_EOL, FILE_APPEND | LOCK_EX);
        return $resp;
    }

    $resp = null;
    switch ($request['type']) {
        case "register":
            $resp = doRegister($request['username'], $request['password'], $request['email']);
            break;
        case "login":
            $resp = doLogin($request['username'], $request['password']);
            break;
        case "validate_session":
            $resp = doValidate($request['sessionId'], $request['authToken']);
            break;
        default:
            $resp = ["returnCode" => 1, "message" => "Invalid request type"];
            break;
    }

    $respJson = json_encode($resp);
    error_log("[auth_worker][RESP] " . $respJson);
    @file_put_contents('/var/log/auth_worker_rpc.log', date('c') . " RESP " . $respJson . PHP_EOL, FILE_APPEND | LOCK_EX);
    return $resp;
}

// -----------------------------
// RabbitMQ Server Setup
// -----------------------------
$server = new rabbitMQServer("testRabbitMQ.ini", "sharedServer");
if ($argc > 1 && $argv[1] == "test") {
    $request = array();
    $request['type'] = 'register';
    $request['username'] = 'testuser';
    $request['password'] = 'testpass';
    $request['email'] = 'testuser@example.com'; 
    print_r(requestProcessor($request));
}else{
    echo "Database server active, waiting for requests..." . PHP_EOL;
    $server->process_requests('requestProcessor');
}
?>