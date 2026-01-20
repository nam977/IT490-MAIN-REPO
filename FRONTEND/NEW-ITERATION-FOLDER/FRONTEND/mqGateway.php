<?php
declare(strict_types=1);

/* ===================== CORS (safe version) ===================== */
$origin = $_SERVER['HTTP_ORIGIN'] ?? null;
if ($origin) {
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
    header("Vary: Origin");
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

/* ===================== Backend host config ===================== */
/* BACKEND SERVICES RUN HERE */
$BACKEND_HOST = '100.114.135.58';

/* ===================== Helpers ===================== */
function json_response(array $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/* ===================== Read JSON ===================== */
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);

if (!is_array($input)) {
    json_response(['status' => 'error', 'error' => 'Invalid JSON input'], 400);
}

$type = strtolower($input['type'] ?? '');

/* ===================== STOCK VALUES (Flask :5002) ===================== */
if ($type === 'get_stock_value') {
    $symbol   = strtoupper(trim($input['symbol'] ?? ''));
    $interval = $input['interval'] ?? '1min';

    if ($symbol === '') {
        json_response(['status' => 'error', 'error' => 'Missing symbol'], 400);
    }

    $backendUrl =
        "http://{$BACKEND_HOST}:5002/getStockValues?" .
        "symbol=" . urlencode($symbol) .
        "&interval=" . urlencode($interval);

    $ctx = stream_context_create([
        'http' => ['method' => 'GET', 'timeout' => 10]
    ]);

    $rawBackend = @file_get_contents($backendUrl, false, $ctx);
    if ($rawBackend === false) {
        json_response(['status'=>'error','error'=>'Failed to contact stock service'], 502);
    }

    $decoded = json_decode($rawBackend, true);
    if (!is_array($decoded)) {
        json_response(['status'=>'error','error'=>'Non-JSON response from stock service'], 502);
    }

    /* Parse AlphaVantage-style response */
    $tsKey = null;
    foreach ($decoded as $k => $_) {
        if (strpos($k, 'Time Series') === 0) {
            $tsKey = $k;
            break;
        }
    }

    if (!$tsKey || !isset($decoded[$tsKey])) {
        json_response(['status'=>'error','error'=>'Malformed stock data'], 502);
    }

    $series = $decoded[$tsKey];
    $times = array_keys($series);
    sort($times);

    $latest = end($times);
    $latestPrice = (float)($series[$latest]['4. close'] ?? 0);

    $history = [];
    foreach ($times as $t) {
        $history[] = [
            'time'  => $t,
            'price' => (float)($series[$t]['4. close'] ?? 0)
        ];
    }

    json_response([
        'status'  => 'success',
        'symbol'  => $symbol,
        'price'   => $latestPrice,
        'history' => $history
    ]);
}

/* ===================== MOCK TRADE (Flask :5001) ===================== */
if ($type === 'place_trade' && !empty($input['mock'])) {
    $symbol   = strtoupper($input['symbol'] ?? '');
    $quantity = (int)($input['quantity'] ?? 0);
    $action   = strtolower($input['action'] ?? '');

    if (!$symbol || $quantity <= 0 || !in_array($action, ['buy','sell'], true)) {
        json_response(['status'=>'error','error'=>'Invalid trade data'], 400);
    }

    $backendUrl = "http://{$BACKEND_HOST}:5001/api/trade";

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'timeout' => 10,
            'header'  => "Content-Type: application/json\r\n",
            'content' => json_encode([
                'symbol'   => $symbol,
                'quantity'=> $quantity,
                'action'  => $action
            ])
        ]
    ]);

    $rawBackend = @file_get_contents($backendUrl, false, $ctx);
    if ($rawBackend === false) {
        json_response(['status'=>'error','error'=>'Failed to contact trade service'], 502);
    }

    $decoded = json_decode($rawBackend, true);
    if (!is_array($decoded)) {
        json_response(['status'=>'error','error'=>'Non-JSON response from trade service'], 502);
    }

    json_response($decoded);
}

/* ===================== PORTFOLIO (Flask :5001) ===================== */
if ($type === 'get_portfolio') {
    $backendUrl = "http://{$BACKEND_HOST}:5001/api/portfolio";

    $ctx = stream_context_create([
        'http' => ['method' => 'GET', 'timeout' => 10]
    ]);

    $rawBackend = @file_get_contents($backendUrl, false, $ctx);
    if ($rawBackend === false) {
        json_response(['status'=>'error','error'=>'Failed to contact portfolio service'], 502);
    }

    $decoded = json_decode($rawBackend, true);
    if (!is_array($decoded)) {
        json_response(['status'=>'error','error'=>'Non-JSON response from portfolio service'], 502);
    }

    json_response($decoded);
}

/* ===================== FALLBACK ===================== */
json_response(['status'=>'error','error'=>'Unknown request type'], 400);

