<?php
require 'vendor/autoload.php'; // подключаем autoload Composer

use Kreait\Firebase\Factory;

header('Access-Control-Allow-Origin: https://casemirror.cv');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Токен для CryptoBot API
$token = '42881:AAjtoAPYQHenA40LBBCXkPE8J0yP5JSbPP5';

// Получаем userId из GET (например, ?userId=abc123)
$userId = $_GET['userId'] ?? null;
if (!$userId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing userId']);
    exit;
}

// Получаем количество TON из GET (например, ?amount=0.1)
$ton_qty = floatval($_GET['amount'] ?? 0);
if ($ton_qty <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid TON quantity']);
    exit;
}

// Получаем курсы из CryptoBot API
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
$usdt_amount = round($ton_qty * $ton_to_usdt, 2);

// Генерируем уникальный payload для инвойса и записи в Firebase
$payload = 'ton_price_usdt_' . uniqid();

// Подготавливаем данные для создания инвойса у CryptoBot
$invoice_data = [
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
        'content' => json_encode($invoice_data),
    ],
];

$context = stream_context_create($options);
$result = @file_get_contents('https://testnet-pay.crypt.bot/api/createInvoice', false, $context);

if ($result === false) {
    http_response_code(502);
    echo json_encode(['error' => 'Unable to create invoice']);
    exit;
}

// Декодируем ответ, чтобы проверить результат
$invoice_response = json_decode($result, true);
if (!isset($invoice_response['ok']) || !$invoice_response['ok']) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to create invoice', 'details' => $invoice_response]);
    exit;
}

// *** Записываем платеж в Firebase через SDK ***

$firebase_url = getenv('FIREBASE_DB_URL');
$credentials_json = getenv('FIREBASE_CREDENTIALS');

if (!$firebase_url || !$credentials_json) {
    error_log('Firebase credentials not set');
    // Не прерываем, чтобы не ломать UX — клиенту возвращаем инвойс
    echo $result;
    exit;
}

$serviceAccount = json_decode($credentials_json, true);
if (!$serviceAccount || !isset($serviceAccount['client_email'])) {
    error_log('Invalid Firebase credentials JSON');
    echo $result;
    exit;
}

try {
    $factory = (new Factory)
        ->withServiceAccount($serviceAccount)
        ->withDatabaseUri($firebase_url);
    $database = $factory->createDatabase();

    $payment_record = [
        'userId' => $userId,
        'amount' => $usdt_amount,
        'amount_ton' => $ton_qty,    // сумма в TON из запроса
        'status' => 'pending',
        'createdAt' => time()
    ];

    // Записываем запись с ключом payload
    $database->getReference('payments/' . $payload)->set($payment_record);

} catch (Exception $e) {
    error_log('Firebase write error: ' . $e->getMessage());
    // Не прерываем выполнение, чтобы вернуть инвойс клиенту
}

// Возвращаем ответ с инвойсом клиенту
echo $result;
