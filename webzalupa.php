<?php
require 'vendor/autoload.php';

use Kreait\Firebase\Factory;

header('Content-Type: application/json');
error_log('Webhook received: ' . file_get_contents('php://input'));

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

    // Получаем пользователя
    $userRef = $database->getReference('profile/' . $userId);
    $user = $userRef->getValue();

    $currentBalance = isset($user['balance']) ? floatval($user['balance']) : 0;

    // Обновляем баланс
    $newBalance = $currentBalance + $amountTon;
    $userRef->update(['balance' => $newBalance]);

    // === НАЧИСЛЕНИЕ БОНУСА ПРИГЛАСИВШЕМУ ===
    if (isset($user['referred_by']) && !empty($user['referred_by'])) {
        $refCode = $user['referred_by'];

        // Ищем пригласившего по его ref_code
        $profilesRef = $database->getReference('profile');
        $allProfiles = $profilesRef->getValue();

        $referrerId = null;
        foreach ($allProfiles as $profileId => $profileData) {
            if (isset($profileData['ref_code']) && $profileData['ref_code'] === $refCode) {
                $referrerId = $profileId;
                break;
            }
        }

        if ($referrerId) {
            $referrerRef = $database->getReference('profile/' . $referrerId);
            $referrer = $referrerRef->getValue();

            $currentBonus = isset($referrer['bonus_balance']) ? floatval($referrer['bonus_balance']) : 0;
            $bonusToAdd = $amountTon * 0.10;

            // Обновляем бонусный баланс
            $referrerRef->update([
                'bonus_balance' => $currentBonus + $bonusToAdd
            ]);

            // === НАКОПЛЕНИЕ В REF_DEP_SUM ===
            $referralDepRef = $database->getReference("profile/{$referrerId}/referrals/{$userId}");
            $existingDep = $referralDepRef->getValue();
            $currentDepSum = isset($existingDep['ref_dep_sum']) ? floatval($existingDep['ref_dep_sum']) : 0;

            $referralDepRef->update([
                'ref_dep_sum' => $currentDepSum + $bonusToAdd
            ]);

            error_log("✅ Bonus of {$bonusToAdd} TON added to referrer $referrerId");
            error_log("✅ Referral deposit sum updated: +{$bonusToAdd} TON from $userId under referrer $referrerId");
        } else {
            error_log("⚠️ Referrer not found by ref_code: $refCode");
        }
    } else {
        error_log("ℹ️ No referred_by field found for user: $userId");
    }
    // ==========================

    echo json_encode(['success' => true, 'newBalance' => $newBalance]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Firebase error', 'message' => $e->getMessage()]);
}
