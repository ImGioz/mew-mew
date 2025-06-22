<?php
header('Content-Type: text/plain');

$token = '415872:AAZ88TTrizzKCbAWl31ZV1FNJGq1ZinAwPY';

$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => "Crypto-Pay-API-Token: $token\r\n"
    ]
]);

$response = file_get_contents('https://pay.crypt.bot/api/getExchangeRates', false, $context);

if (!$response) {
    http_response_code(500);
    echo "Ошибка: не удалось получить ответ от API.\n";
    exit;
}

$data = json_decode($response, true);

if (!$data || !isset($data['result'])) {
    http_response_code(500);
    echo "Ошибка: неверный формат ответа от API.\n";
    exit;
}

// Записываем полный список курсов в файл для просмотра
file_put_contents('rates_debug.json', json_encode($data['result'], JSON_PRETTY_PRINT));

// Выводим список всех курсов
foreach ($data['result'] as $rate) {
    echo strtoupper($rate['source']) . " -> " . strtoupper($rate['target']) . " = " . $rate['rate'] . "\n";
}
