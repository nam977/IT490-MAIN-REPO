#!/usr/bin/php
<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');
require_once __DIR__ . '/vendor/autoload.php';

$vantageAPI = 'X06XHO4GPPMMFGJJ'; 
$stockSymbols = ["AAPL", "MSFT", "AMZN", "GOOG"];

$stockClient = new rabbitMQClient("testRabbitMQ.ini", "sharedServer2");

function doFetch($symbol, $vantageAPI): float|null 
{
    $url = "https://www.alphavantage.co/query?function=GLOBAL_QUOTE&symbol="
         . urlencode($symbol) . "&apikey={$vantageAPI}";
    $json = @file_get_contents($url);
    if (!$json){ 
        return null;
    }
    $stockData = json_decode($json, true);
    if (!isset($stockData['Global Quote']['05. price'])) {
        echo "stockData is hasn't been pollled properly";
        return null;
    }else{
        return (float)$stockData['Global Quote']['05. price'];
    }
}
foreach ($stockSymbols as $stockSymbol) {
    $stockPrice = doFetch($stockSymbol, $vantageAPI);
    if ($stockPrice === null) {
        echo "Failed fetch of {$stockSymbol}\n";
        continue;
    }

    echo "Succesful fetch of {$stockSymbol}: {$stockPrice}\n";

    $payload = [
        "type" => "stock_price_update",
        "symbol" => $stockSymbol,
        "price" => $stockPrice,
        "timestamp" => date('Y-m-d H:i:s')
    ];

    try {
        $response = $stockClient->send_request($payload);
        echo "Server response: " . json_encode($response) . "\n";
    } catch (Exception $e) {
        echo "Failed seneding {$stockSymbol} to server: {$e->getMessage()}\n";
    }
    sleep(15);
}

echo "Api Polling done\n";
