<?php
header('Access-Control-Allow-Origin: https://casemirror.cv', 'http://localhost:3000');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$token = '415872:AAZ88TTrizzKCbAWl31ZV1FNJGq1ZinAwPY';

// Получаем курс TON → USDT
$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => "Crypto-Pay-API-Token: $token\r\n"
    ]
]);

$response = file_get_contents('https://pay.crypt.bot/api/getExchangeRates', false, $context);
$rates = json_decode($response, true)['result'];

$ton_to_usdt = null;
foreach ($rates as $r) {
    if ($r['source'] === 'TON' && $r['target'] === 'USDT') {
        $ton_to_usdt = $r['rate'];
        break;
    }
}

if (!$ton_to_usdt) {
    http_response_code(500);
    echo json_encode(['error' => 'Exchange rate not found']);
    exit;
}

// Сколько TON хочет "купить" пользователь (в долларах по курсу TON)
$ton_qty = floatval($_GET['ton'] ?? 1); // Пример: ?ton=2
if ($ton_qty <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid TON quantity']);
    exit;
}

// Считаем сколько USDT это стоит
$usdt_amount = round($ton_qty * $ton_to_usdt, 2); // например: 2 * 6.5 = $13.00

$payload = 'ton_price_usdt_' . uniqid();
$data = [
    'asset' => 'USDT',                   // Инвойс в долларах
    'amount' => $usdt_amount,            // Сумма в USDT
    'swap_to' => 'USDT',                 // Всегда получаем USDT
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
$result = @file_get_contents('https://pay.crypt.bot/api/createInvoice', false, $context);

if ($result === false) {
    http_response_code(502);
    echo json_encode(['error' => 'Unable to create invoice']);
    exit;
}

echo $result;
