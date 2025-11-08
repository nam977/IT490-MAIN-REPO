<?php
if (!isset($argv) || !is_array($argv)) {
    $argv = [];
}

$argv[0] = $_SERVER["SCRIPT_FILENAME"] ?? 'testRabbitMQClient.php';
$argv[1] = $_POST['type'] ?? $_GET['type'] ?? 'null';
$argv[2] = $_POST['username'] ?? $_GET['username'] ?? 'null';
$argv[3] = $_POST['password'] ?? $_GET['password'] ?? 'null';
$argv[4] = $_POST['email'] ?? $_GET['email'] ?? 'null';     

$GLOBALS['argc'] = count($argv);
?>