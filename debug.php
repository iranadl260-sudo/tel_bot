<?php
/**
 * Diagnostic & Setup Tool
 */
header('Content-Type: text/html; charset=utf-8');

$botToken = getenv('BOT_TOKEN') ?: $_GET['token'] ?? '';
$siteUrl  = getenv('SITE_URL') ?: $_GET['url'] ?? '';

echo "<h2>🛠 عیب‌یابی سیستم دانلود فایل تا ۲۰ گیگابایت</h2><hr>";

if (empty($botToken)) {
    echo "<p style='color:red;'>❌ توکن ربات مقداردهی نشده است (Environment Variable در Render را بررسی کنید).</p>";
} else {
    $ch = curl_init("https://api.telegram.org/bot$botToken/getMe");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if ($res['ok'] ?? false) {
        echo "<p style='color:green;'>✅ توکن ربات فعال است: @" . $res['result']['username'] . "</p>";
    } else {
        echo "<p style='color:red;'>❌ توکن معتبر نیست یا ارتباط با تلگرام قطع است.</p>";
    }
}

// بررسی رم و دیسک جهت دانلود ۲۰ گیگ
$freeSpace = disk_free_space(__DIR__);
$freeGb = round($freeSpace / (1024 * 1024 * 1024), 2);
echo "<p>💾 **فضای خالی دیسک جهت دانلود:** {$freeGb} GB</p>";

if ($freeGb < 20) {
    echo "<p style='color:orange;'>⚠️ فضای دیسک کمتر از ۲۰ گیگابایت است! برای دانلود فایل‌های بزرگ، حتماً روی Render یک Persistent Disk اضافه کنید.</p>";
}

// تنظیم خودکار وب‌هوک
if (!empty($botToken) && !empty($siteUrl)) {
    $webhookUrl = rtrim($siteUrl, '/') . '/bot.php';
    echo "<br><a href='?set_webhook=1&token=$botToken&url=$siteUrl' style='padding:10px; background:#0088cc; color:#fff; text-decoration:none; border-radius:5px;'>🔗 ست‌کردن وب‌هوک روی $webhookUrl</a>";

    if (isset($_GET['set_webhook'])) {
        $ch = curl_init("https://api.telegram.org/bot$botToken/setWebhook?url=" . urlencode($webhookUrl));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $whRes = json_decode(curl_exec($ch), true);
        curl_close($ch);
        echo "<pre>"; print_r($whRes); echo "</pre>";
    }
}