<?php
require __DIR__ . '/vendor/autoload.php';

use Kreait\Firebase\Factory;

// Получаем тело запроса
$payload = json_decode(file_get_contents('php://input'), true);

if (!isset($payload['update_type']) || $payload['update_type'] !== 'invoice_paid') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid webhook data']);
    exit;
}

$invoice = $payload['invoice'];
$ton_amount = $invoice['amount'];
$user_id = $invoice['user_id'] ?? null;

if (!$user_id) {
    http_response_code(400);
    echo json_encode(['error' => 'No user ID provided']);
    exit;
}

// Получаем переменные окружения
$firebase_url = getenv('FIREBASE_DB_URL');
$credentials_json = getenv('FIREBASE_CREDENTIALS');

if (!$firebase_url || !$credentials_json) {
    http_response_code(500);
    echo json_encode(['error' => 'Firebase credentials not set']);
    exit;
}

// Декодируем JSON из переменной окружения
$serviceAccount = json_decode($credentials_json, true);

if (!$serviceAccount || !isset($serviceAccount['client_email'])) {
    http_response_code(500);
    echo json_encode(['error' => 'Invalid Firebase credentials JSON']);
    exit;
}

// Создаем фабрику с сервисным аккаунтом и URL базы данных
$firebase = (new Factory)
    ->withServiceAccount($serviceAccount)
    ->withDatabaseUri($firebase_url)
    ->createDatabase();

// Обновляем баланс
$ref = $firebase->getReference("profile/$user_id/balance");
$old_balance = $ref->getValue() ?? 0;
$new_balance = $old_balance + $ton_amount;
$ref->set($new_balance);

echo json_encode(['status' => 'ok']);
