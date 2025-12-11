<?php
require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

/*$distributionDB = new mysqli("127.0.0.1","deployment,deployment,deployment");
if($mysqli->connect_errno){
    echo "failed dataabase conection";
}*/

function sftpConnector($sftpHost,$sftpUsername,$sftpPassword){
    $sftpConnection = ssh2_connect($sftpHost,22);
    if(!$sftpConnection){
        echo "failed sftp connecton";
        return false;
    }
    if (!ssh2_auth_password($sftpConnection,$sftpUsername,$sftpPassword)){
       echo "failed sftp authentication";
    return false;
    }

    $confirmedSftp = ssh2_sftp($sftpConnection);
    if(!confirmedSftp){
        echo "connnction failed";
        return false;
    }
    return ["connection"=>$sftpConnection,"sftp"=>$confirmedSftp];
       
}

$connection = new AMQPStreamConnection('localhost', 5672, 'deployment', 'deployment');
$channel = $connection->channel();

$channel->queue_declare('deployment', false, true, false, false);

echo " [*] Waiting for download/upload requests...\n";

$callback = function (AMQPMessage $msg) {
    $data = json_decode($msg -> getBody());
    echo ' [x] Received ', $msg->getBody(), "\n";
    $msg -> ack();


  //first checking the message if it is valid

    // "asker" => $user,
    // "file" => "",
    // "version" => "", (only in download)
    // "action" => "download"
    //
    
    $asker = $data->asker;
    $file = $data->file;
    $action = $data->action;

    if ($action == "upload"){
        //UPLOAD
        //if valid request, sftp, and update database
        


    } else if ($action == "download") {
        //DOWNLOAD
        //if valid request then check database
        $version = $data->version;
        

        //if database does not have then return error message
    } else {
        echo "Invalid request.";
    }




};

$channel->basic_consume('deployment', '', false, false, false, false, $callback);




while ($channel->is_consuming()) {
    $channel->wait();
}

$channel->close();
$connection->close();

?>
