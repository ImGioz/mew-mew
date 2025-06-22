<?php
require __DIR__ . '/vendor/autoload.php';

use Kreait\Firebase\Factory;
use Kreait\Firebase\ServiceAccount;

// Получение тела запроса
$payload = json_decode(file_get_contents('php://input'), true);

// Проверка, что это успешная оплата
if (!isset($payload['update_type']) || $payload['update_type'] !== 'invoice_paid') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid webhook data']);
    exit;
}

$invoice = $payload['invoice'];
$ton_amount = $invoice['amount']; // USDT по факту, но мы добавим в TON
$comment = $invoice['comment'] ?? '';
$user_id = $invoice['user_id'] ?? null;

if (!$user_id) {
    http_response_code(400);
    echo json_encode(['error' => 'No user ID provided']);
    exit;
}

// Подключение к Firebase
$firebase_url = getenv('FIREBASE_DB_URL');
$credentials_json = getenv('FIREBASE_CREDENTIALS');

// Временный файл для сервисного ключа
$tempFile = tempnam(sys_get_temp_dir(), 'firebase');
file_put_contents($tempFile, $credentials_json);

$firebase = (new Factory())
    ->withServiceAccount($tempFile)
    ->withDatabaseUri($firebase_url)
    ->createDatabase();

// Увеличение баланса пользователя
$ref = $firebase->getReference("profile/$user_id/balance");
$old_balance = $ref->getValue() ?? 0;
$new_balance = $old_balance + $ton_amount;
$ref->set($new_balance);

unlink($tempFile); // удалить временный файл

echo json_encode(['status' => 'ok']);
