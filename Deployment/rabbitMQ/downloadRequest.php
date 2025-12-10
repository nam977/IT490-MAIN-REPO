<?php
require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;


//change these based on the machine
$user = 'frontend';
$pass = 'frontend';


$connection = new AMQPStreamConnection('localhost', 5672, $user, $pass);
$channel = $connection->channel();

$channel->queue_declare('deployment', false, true, false, false);

//request for download

//prompt use for file and version #
$file = readline("What is the file name that you would like to download?\n");
$version = readline("Which version would you like?\n");

$payload = [
    "asker" => $user,
    "file" => $file,
    "version" => $version,
    "action" => "download"
];

$content = json_encode($payload);


$msg = new AMQPMessage($content);


$channel->basic_publish($msg, '', 'deployment');

echo " [*] Sent msg: \n'$content \n";



try {
    $channel->consume();
} catch (\Throwable $exception) {
    echo $exception->getMessage();
}

$channel->close();
$connection->close();

?>