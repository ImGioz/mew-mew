<?php
require 'vendor/autoload.php';

use Kreait\Firebase\Factory;

header('Content-Type: application/json');

// Получаем тело запроса (json)
$input = file_get_contents('php://input');
error_log("Webhook received: " . $input);
$data = json_decode($input, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Проверяем наличие payload и нового статуса
$payload = $data['payload']['payload'] ?? null;
$new_status = $data['payload']['status'] ?? null;

if (!$payload || !$new_status) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing payload or status']);
    exit;
}

// Загрузка настроек Firebase из env
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

    // Обновляем поле status у платежа по ключу payload
    $ref = $database->getReference('payments/' . $payload);

    // Проверяем, что запись существует
    $payment = $ref->getValue();
    if (!$payment) {
        http_response_code(404);
        echo json_encode(['error' => 'Payment record not found']);
        exit;
    }

    $ref->update([
        'status' => $new_status,
        'updatedAt' => time()
    ]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Firebase error', 'message' => $e->getMessage()]);
}
