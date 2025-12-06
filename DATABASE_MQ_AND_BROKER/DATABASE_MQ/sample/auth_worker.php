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

$mydb = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($mydb->connect_errno != 0) {
    echo "Failed to connect to database: " . $mydb->connect_error . PHP_EOL;
    exit(0);
}
echo "Successfully connected to database as user: $db_user" . PHP_EOL;

function doRegister($username, $password, $email)
{
    global $mydb;
    $stmt = $mydb->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        $stmt->close();
        return ["returnCode" => 1, "message" => "Username already exists"];
    }
    $stmt->close();
    $hashed_password = password_hash($password, PASSWORD_BCRYPT);

    $stmt = $mydb->prepare("INSERT INTO users (username, password, email) VALUES(?, ?, ?)");
    $stmt->bind_param("sss", $username, $hashed_password, $email);

    if (!$stmt->execute()) {
        return ["returnCode" => 1, "message" => "Registration failed: " . $stmt->error];
    }

    $stmt->close();
    return ["returnCode" => 0, "message" => "Registration successful"];
}

function doLogin($username, $password)
{
    global $mydb;

    $stmt = $mydb->prepare("SELECT password FROM users WHERE username = ? LIMIT 1");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->bind_result($hashed_password);
    $stmt->fetch();
    $stmt->close();

    if (!$hashed_password) {
        return ["returnCode" => 1, "message" => "User not found"];
    }

    if (!password_verify($password, $hashed_password)) {
        return ["returnCode" => 1, "message" => "Invalid password"];
    }

    $session_id = bin2hex(random_bytes(16));
    $auth_token = bin2hex(random_bytes(32));
    $expiration = date('Y-m-d H:i:s', time() + 3600);

    $stmt = $mydb->prepare("
        INSERT INTO user_cookies(session_id, username, auth_token, expiration_time)
        VALUES(?, ?, ?, ?)
    ");
    $stmt->bind_param("ssss", $session_id, $username, $auth_token, $expiration);

    if (!$stmt->execute()) {
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

function doValidate($sessionId, $authToken)
{
    global $mydb;

    $stmt = $mydb->prepare("
        SELECT username
        FROM user_cookies
        WHERE session_id = ? AND auth_token = ? AND expiration_time > NOW()
    ");
    $stmt->bind_param("ss", $sessionId, $authToken);
    $stmt->execute();
    $stmt->store_result();

    $isValid = $stmt->num_rows > 0;
    $stmt->close();

    if ($isValid) {
        return ["returnCode" => 0, "message" => "Valid session"];
    } else {
        return ["returnCode" => 1, "message" => "Invalid session"];
    }
}

function requestProcessor($request)
{
    echo "Received request:" . PHP_EOL;
    var_dump($request);

    if (!isset($request['type'])) {
        return ["returnCode" => 1, "message" => "No type provided"];
    }

    switch ($request['type']) {
        case "register":
            return doRegister($request['username'], $request['password'], $request['email']);
        case "login":
            return doLogin($request['username'], $request['password']);
        case "validate_session":
            return doValidate($request['sessionId'], $request['authToken']);
        default:
            return ["returnCode" => 1, "message" => "Invalid request type"];
    }
}

$authServer = new rabbitMQServer("testRabbitMQ.ini","sharedServer");
echo "Authentictation Server ready and on standby..." . PHP_EOL;
$authServerPid = pcntl_fork();
if ($authServerPid == 0){
    $authServer->process_requests('requestProcessor');
    exit();
}
