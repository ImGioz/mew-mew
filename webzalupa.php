<?php
require 'vendor/autoload.php';

use Kreait\Firebase\Factory;

header('Content-Type: application/json');

// Для логов Render
error_log('Webhook received: ' . file_get_contents('php://input'));

// Получаем тело запроса (json)
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || !isset($data['payload']) || !isset($data['payload']['payload']) || !isset($data['payload']['status'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing payload or status']);
    exit;
}

$paymentData = $data['payload'];
$payload = $paymentData['payload'];
$status = $paymentData['status'];

if ($status !== 'paid') {
    http_response_code(200);
    echo json_encode(['message' => 'Not a paid status, ignoring']);
    exit;
}

// Firebase config
$firebase_url = getenv('FIREBASE_DB_URL');
$credentials_json = getenv('FIREBASE_CREDENTIALS');

if (!$firebase_url || !$credentials_json) {
    http_response_code(500);
    echo json_encode(['error' => 'Firebase credentials not set']);
    exit;
}

$serviceAccount = json_decode($credentials_json, true);
if (!$serviceAccount || !isset($serviceAccount['client_email'])) {
    http_response_code(500);
    echo json_encode(['error' => 'Invalid Firebase credentials JSON']);
    exit;
}

try {
    $factory = (new Factory)
        ->withServiceAccount($serviceAccount)
        ->withDatabaseUri($firebase_url);
    $database = $factory->createDatabase();

    // Получаем платеж
    $paymentRef = $database->getReference('payments/' . $payload);
    $payment = $paymentRef->getValue();

    if (!$payment || !isset($payment['userId']) || !isset($payment['amount_ton'])) {
        http_response_code(404);
        echo json_encode(['error' => 'Payment record incomplete or not found']);
        exit;
    }

    $userId = $payment['userId'];
    $amountTon = floatval($payment['amount_ton']);

    // Обновляем статус платежа
    $paymentRef->update([
        'status' => 'paid',
        'updatedAt' => time()
    ]);

    // Получаем текущий баланс пользователя
    $userRef = $database->getReference('profile/' . $userId);
    $user = $userRef->getValue();

    $currentBalance = isset($user['balance']) ? floatval($user['balance']) : 0;

    // Обновляем баланс
    $newBalance = $currentBalance + $amountTon;
    $userRef->update(['balance' => $newBalance]);

    echo json_encode(['success' => true, 'newBalance' => $newBalance]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Firebase error', 'message' => $e->getMessage()]);
}
