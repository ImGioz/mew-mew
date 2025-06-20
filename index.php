<?php
// CORS headers — добавляются всегда
header('Access-Control-Allow-Origin: https://casemirror-2f849.web.app');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

// Preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$amount = floatval($_GET['amount'] ?? 0);
if ($amount <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid amount']);
    exit;
}

$token = '415872:AAZ88TTrizzKCbAWl31ZV1FNJGq1ZinAwPY'; // Замените на реальный токен
$currency = 'TON';
$payload = 'user_deposit_' . uniqid();
$expired_in = 3600;

$data = [
    'asset' => $currency,
    'amount' => $amount,
    'description' => 'Deposit to WebApp',
    'hidden_message' => 'Thank you for using our app!',
    'payload' => $payload,
    'expires_in' => $expired_in
];

$options = [
    'http' => [
        'header'  => "Content-Type: application/json\r\nCrypto-Pay-API-Token: $token\r\n",
        'method'  => 'POST',
        'content' => json_encode($data),
    ],
];

$context = stream_context_create($options);
$result = @file_get_contents('https://pay.crypt.bot/api/createInvoice', false, $context);

if ($result === false) {
    http_response_code(502);
    echo json_encode(['error' => 'Unable to create invoice']);
    exit;
}

echo $result;
