<?php
/**
 * Telegram Unlimited Direct Link Bot (Up to 20GB)
 * Compatible with Render & Docker
 */

require_once __DIR__ . '/vendor/autoload.php';

// خواندن تنظیمات از Environment Variables (مناسب برای Render)
$botToken   = getenv('BOT_TOKEN') ?: 'YOUR_BOT_TOKEN';
$apiId      = (int)(getenv('TELEGRAM_API_ID') ?: 123456);
$apiHash    = getenv('TELEGRAM_API_HASH') ?: 'YOUR_API_HASH';
$siteUrl    = getenv('SITE_URL') ?: 'https://your-app.onrender.com';
$dbHost     = getenv('DB_HOST') ?: 'localhost';
$dbName     = getenv('DB_NAME') ?: 'tg_bot';
$dbUser     = getenv('DB_USER') ?: 'root';
$dbPass     = getenv('DB_PASS') ?: '';
$dbPrefix   = getenv('DB_PREFIX') ?: 'tg_';

// اتصال PDO
try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (Exception $e) {
    // در صورت عدم اتصال دیتابیس، ادامه می‌دهد
}

// ورودی تلگرام
$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!$update) {
    // پاسخ ساده به Health Check سرور Render
    echo "Bot Server is Running!";
    exit;
}

$message = $update['message'] ?? $update['edited_message'] ?? null;
if (!$message) exit;

$chatId = $message['chat']['id'];
$text = $message['text'] ?? '';

if ($text === '/start') {
    sendMessage($botToken, $chatId, "سلام! 👋\n\nهر فایلی تا **سقف ۲۰ گیگابایت** ارسال کنید تا لینک مستقیم آن را دریافت کنید.");
    exit;
}

// بررسی وجود فایل
$fileData = getFileData($message);
if (!$fileData) {
    sendMessage($botToken, $chatId, "⚠️ لطفاً یک فایل یا ویدیو ارسال یا فوروارد کنید.");
    exit;
}

$fileName = $fileData['name'];
$fileSize = $fileData['size'];
$sizeMb = round($fileSize / (1024 * 1024), 2);
$sizeGb = round($fileSize / (1024 * 1024 * 1024), 2);

$sizeStr = ($fileSize > 1073741824) ? "{$sizeGb} GB" : "{$sizeMb} MB";

sendMessage($botToken, $chatId, "⏳ **در حال پردازش فایل ($sizeStr)...**\nلطفاً شکیبا باشید، فرآیند دانلود فایل‌های سنگین ممکن است زمان‌بر باشد.");

$uploadDir = __DIR__ . '/uploads/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

$saveFilename = time() . '_' . preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $fileName);
$destination = $uploadDir . $saveFilename;

// دانلود فایل با استفاده از MadelineProto بدون محدودیت حجم
try {
    $settings = new \danog\MadelineProto\Settings();
    $settings->setAppInfo((new \danog\MadelineProto\Settings\AppInfo())
        ->setApiId($apiId)
        ->setApiHash($apiHash));

    $mp = new \danog\MadelineProto\API('session.madeline', $settings);
    $mp->botLogin($botToken);

    // دانلود به صورت Stream جهت عدم پر شدن حافظه RAM
    $mp->downloadToFile($message, $destination);

    if (file_exists($destination) && filesize($destination) > 0) {
        $directLink = rtrim($siteUrl, '/') . '/uploads/' . $save_filename;

        // ذخیره در SQL در صورت اتصال
        if (isset($pdo)) {
            $table = $dbPrefix . "files";
            $stmt = $pdo->prepare("INSERT INTO `$table` (file_id, file_name, file_size, download_path) VALUES (?, ?, ?, ?)");
            $stmt->execute([$fileData['file_id'], $fileName, $fileSize, $saveFilename]);
        }

        $resMsg = "✅ **لینک مستقیم شما آماده شد:**\n\n";
        $resMsg .= "📁 **نام فایل:** `" . htmlspecialchars($fileName) . "`\n";
        $resMsg .= "📏 **حجم:** `$sizeStr`\n\n";
        $resMsg .= "🔗 **لینک دانلود:**\n" . $directLink;

        sendMessage($botToken, $chatId, $resMsg, 'Markdown');
    } else {
        sendMessage($botToken, $chatId, "❌ خطایی در ذخیره‌سازی فایل رخ داد.");
    }
} catch (Exception $e) {
    sendMessage($botToken, $chatId, "❌ خطای سیستم: " . $e->getMessage());
}

// توابع کمکی
function getFileData($msg) {
    if (isset($msg['document'])) return ['file_id' => $msg['document']['file_id'], 'name' => $msg['document']['file_name'] ?? 'file.bin', 'size' => $msg['document']['file_size'] ?? 0];
    if (isset($msg['video'])) return ['file_id' => $msg['video']['file_id'], 'name' => $msg['video']['file_name'] ?? 'video.mp4', 'size' => $msg['video']['file_size'] ?? 0];
    if (isset($msg['audio'])) return ['file_id' => $msg['audio']['file_id'], 'name' => $msg['audio']['file_name'] ?? 'audio.mp3', 'size' => $msg['audio']['file_size'] ?? 0];
    return null;
}

function sendMessage($token, $chatId, $text, $mode = null) {
    $url = "https://api.telegram.org/bot$token/sendMessage";
    $data = ['chat_id' => $chatId, 'text' => $text];
    if ($mode) $data['parse_mode'] = $mode;
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch);
    curl_close($ch);
}