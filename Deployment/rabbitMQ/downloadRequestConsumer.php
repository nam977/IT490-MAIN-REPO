<?php
require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$connection = new AMQPStreamConnection('localhost', 5672, 'deployment', 'deployment');
$channel = $connection->channel();

$channel->queue_declare('deployment', false, true, false, false);

echo " [*] Waiting for messages. To exit press CTRL+C\n";

$callback = function (AMQPMessage $msg) {
  echo ' [x] Received ', $msg->getBody(), "\n";
};

$channel->basic_consume('deployment', '', false, false, false, false, $callback);

//first checking the message if it is valid




    // "asker" => $user,
    // "file" => "",
    // "version" => "",
    // "action" => "download"




//if valid request then check database


//if database does not have then return error message




while ($channel->is_consuming()) {
    $channel->wait();
}

$channel->close();
$connection->close();

?>