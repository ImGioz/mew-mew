<?php
header('Access-Control-Allow-Origin: https://casemirror.cv');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$token = '42881:AAjtoAPYQHenA40LBBCXkPE8J0yP5JSbPP5';

// Получаем курсы
$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => "Crypto-Pay-API-Token: $token\r\n"
    ]
]);

$response = file_get_contents('https://testnet-pay.crypt.bot/api/getExchangeRates', false, $context);
if ($response === false) {
    http_response_code(502);
    echo json_encode(['error' => 'Unable to fetch exchange rates']);
    exit;
}

$data = json_decode($response, true);
if (!isset($data['result'])) {
    http_response_code(500);
    echo json_encode(['error' => 'Invalid response from exchange rates API']);
    exit;
}

$rates = $data['result'];

$ton_to_usd = null;
$usdt_to_usd = null;

foreach ($rates as $r) {
    if ($r['source'] === 'TON' && $r['target'] === 'USD') {
        $ton_to_usd = $r['rate'];
    }
    if ($r['source'] === 'USDT' && $r['target'] === 'USD') {
        $usdt_to_usd = $r['rate'];
    }
}

if (!$ton_to_usd || !$usdt_to_usd) {
    http_response_code(500);
    echo json_encode(['error' => 'Required exchange rates not found']);
    exit;
}

// Расчёт курса TON → USDT
$ton_to_usdt = $ton_to_usd / $usdt_to_usd;

$ton_qty = floatval($_GET['ton'] ?? 1);
if ($ton_qty <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid TON quantity']);
    exit;
}

$usdt_amount = round($ton_qty * $ton_to_usdt, 2);

$payload = 'ton_price_usdt_' . uniqid();
$data = [
    'asset' => 'USDT',
    'amount' => $usdt_amount,
    'swap_to' => 'USDT',
    'description' => 'Refill balance @casemirror',
    'hidden_message' => 'Thank you for using our app!',
    'payload' => $payload,
    'expires_in' => 3600
];

$options = [
    'http' => [
        'header'  => "Content-Type: application/json\r\nCrypto-Pay-API-Token: $token\r\n",
        'method'  => 'POST',
        'content' => json_encode($data),
    ],
];

$context = stream_context_create($options);
$result = @file_get_contents('https://testnet-pay.crypt.bot/api/createInvoice', false, $context);

if ($result === false) {
    http_response_code(502);
    echo json_encode(['error' => 'Unable to create invoice']);
    exit;
}

echo $result;
