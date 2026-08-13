<?php
// فایل: test.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>🧪 تست کامل</h1>";

require_once __DIR__ . '/config.php';

// ۱. تست getMe
echo "<h2>۱. تست توکن:</h2>";
$url = "https://api.telegram.org/bot" . BOT_TOKEN . "/getMe";
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT => 10
]);
$response = curl_exec($ch);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    echo "<p style='color:red'>❌ cURL: {$error}</p>";
} else {
    $data = json_decode($response, true);
    echo "<pre>";
    print_r($data);
    echo "</pre>";
}

// ۲. تست ارسال پیام
echo "<h2>۲. تست ارسال پیام:</h2>";
if (!empty(ADMIN_IDS)) {
    $chatId = ADMIN_IDS[0];
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'chat_id' => $chatId,
            'text' => "✅ تست از Render - " . date('H:i:s')
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 10
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    echo "<pre>";
    print_r($data);
    echo "</pre>";
    
    if ($data['ok']) {
        echo "<p style='color:green;font-size:20px'>✅ پیام ارسال شد! تلگرامت رو چک کن</p>";
    }
}

// ۳. تست getUpdates
echo "<h2>۳. آخرین پیام‌ها:</h2>";
$url = "https://api.telegram.org/bot" . BOT_TOKEN . "/getUpdates?limit=5";
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT => 10
]);
$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);
echo "<pre>";
print_r($data);
echo "</pre>";
?>