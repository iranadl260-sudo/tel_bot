<?php
/**
 * ============================================================
 * 🤖 نصب‌کننده ربات تلگرام مدیریت دانلود
 * نسخه: 16.0 Final
 * شامل تمام اصلاحات و لینک مستقیم
 * ============================================================
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('max_execution_time', 300);
date_default_timezone_set('Asia/Tehran');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

class Installer {
    private $step = 1;
    private $errors = [];
    private $messages = [];
    private $config = [];
    private $diagnosticResults = [];
    
    public function __construct() {
        if (isset($_SESSION['config'])) {
            $this->config = $_SESSION['config'];
        }
        
        if (isset($_POST['step'])) {
            $this->step = (int)$_POST['step'];
        } elseif (isset($_GET['diagnostic'])) {
            $this->step = 20;
            $this->runFullDiagnostic();
        } elseif (isset($_GET['webhook'])) {
            $this->step = 30;
            $this->autoSetupWebhook();
        }
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->processStep();
        }
    }
    
    private function post($key, $default = '') {
        return isset($_POST[$key]) ? trim($_POST[$key]) : (isset($this->config[$key]) ? $this->config[$key] : $default);
    }
    
    private function processStep() {
        switch ($this->step) {
            case 1: $this->step = 2; break;
            case 2: $this->saveConfig(); break;
            case 3: $this->createFiles(); break;
            case 4: $this->createTables(); break;
            case 5: $this->setupWebhook(); break;
        }
    }
    
    private function saveConfig() {
        $required = ['bot_token', 'bot_username', 'admin_ids', 'db_host', 'db_name', 'db_user'];
        $valid = true;
        
        foreach ($required as $field) {
            if (empty($_POST[$field])) {
                $this->errors[] = "فیلد {$field} الزامی است";
                $valid = false;
            }
        }
        
        if (!$valid) return;
        
        $_SESSION['config'] = $_POST;
        $this->config = $_POST;
        $this->messages[] = "✅ تنظیمات ذخیره شد";
        $this->step = 3;
    }
    
    private function createFiles() {
        $config = $this->config;
        $files = $this->getAllFileContents($config);
        
        @mkdir('uploads', 0755, true);
        @mkdir('logs', 0755, true);
        
        $success = true;
        foreach ($files as $name => $content) {
            if (@file_put_contents($name, $content) === false) {
                $this->errors[] = "خطا در {$name}";
                $success = false;
            } else {
                @chmod($name, 0644);
                $this->messages[] = "✅ {$name}";
            }
        }
        
        if ($success) {
            $this->step = 4;
        }
    }
    
    private function createTables() {
        $config = $this->config;
        
        try {
            $dsn = "mysql:host={$config['db_host']};port=3306;dbname={$config['db_name']};charset=utf8mb4";
            $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
            
            $prefix = $config['db_prefix'] ?? 'bot_';
            
            $tables = [
                "{$prefix}files" => "CREATE TABLE IF NOT EXISTS `{$prefix}files` (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    file_id VARCHAR(191),
                    file_name VARCHAR(500),
                    file_size BIGINT DEFAULT 0,
                    file_type VARCHAR(50),
                    category VARCHAR(100) DEFAULT 'عمومی',
                    description TEXT,
                    download_link TEXT,
                    download_count INT DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    is_active TINYINT(1) DEFAULT 1,
                    UNIQUE KEY uk_file_id (file_id(191))
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                "{$prefix}users" => "CREATE TABLE IF NOT EXISTS `{$prefix}users` (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id BIGINT,
                    username VARCHAR(191),
                    first_name VARCHAR(191),
                    last_name VARCHAR(191),
                    joined_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uk_user_id (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                "{$prefix}download_logs" => "CREATE TABLE IF NOT EXISTS `{$prefix}download_logs` (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id BIGINT,
                    file_id VARCHAR(191),
                    downloaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_user (user_id),
                    INDEX idx_file (file_id(191))
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                "{$prefix}categories" => "CREATE TABLE IF NOT EXISTS `{$prefix}categories` (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100),
                    icon VARCHAR(50) DEFAULT '📁',
                    sort_order INT DEFAULT 0,
                    UNIQUE KEY uk_name (name(100))
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            ];
            
            foreach ($tables as $sql) {
                $pdo->exec($sql);
            }
            
            $cats = ['نرم افزار', 'کتاب', 'فیلم', 'موسیقی', 'بازی', 'آموزشی', 'عمومی'];
            $stmt = $pdo->prepare("INSERT IGNORE INTO `{$prefix}categories` (name) VALUES (?)");
            foreach ($cats as $cat) $stmt->execute([$cat]);
            
            $this->messages[] = "✅ جداول ساخته شدند";
            $this->step = 5;
        } catch (Exception $e) {
            $this->errors[] = "❌ دیتابیس: " . $e->getMessage();
        }
    }
    
    private function setupWebhook() {
        $token = $this->config['bot_token'] ?? '';
        $webhookUrl = $this->getWebhookUrl();
        
        @file_get_contents("https://api.telegram.org/bot{$token}/deleteWebhook?drop_pending_updates=true");
        
        $setUrl = "https://api.telegram.org/bot{$token}/setWebhook?url=" . urlencode($webhookUrl);
        $response = @file_get_contents($setUrl);
        
        if ($response) {
            $data = json_decode($response, true);
            if ($data['ok']) {
                $this->messages[] = "✅ Webhook تنظیم شد";
            } else {
                $this->errors[] = "❌ Webhook: " . $data['description'];
            }
        }
        
        $this->messages[] = "🎉 نصب کامل شد!";
        $this->step = 99;
    }
    
    private function autoSetupWebhook() {
        $token = defined('BOT_TOKEN') ? BOT_TOKEN : ($this->config['bot_token'] ?? '');
        $webhookUrl = $this->getWebhookUrl();
        
        @file_get_contents("https://api.telegram.org/bot{$token}/deleteWebhook?drop_pending_updates=true");
        
        $setUrl = "https://api.telegram.org/bot{$token}/setWebhook?url=" . urlencode($webhookUrl);
        $response = @file_get_contents($setUrl);
        
        if ($response) {
            $data = json_decode($response, true);
            if ($data['ok']) {
                $this->messages[] = "✅ Webhook: {$webhookUrl}";
            } else {
                $this->errors[] = "❌ " . $data['description'];
            }
        }
    }
    
    private function getWebhookUrl() {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $path = str_replace('install.php', '', $_SERVER['PHP_SELF']);
        $path = rtrim($path, '/');
        return "{$protocol}://{$host}{$path}/index.php";
    }
    
    private function runFullDiagnostic() {
        $this->diagnosticResults = [];
        
        // سرور
        $this->diagnosticResults[] = ['بخش' => 'سرور', 'تست' => 'PHP', 'وضعیت' => version_compare(PHP_VERSION, '7.4', '>='), 'جزئیات' => phpversion(), 'راه‌حل' => 'ارتقا PHP'];
        $this->diagnosticResults[] = ['بخش' => 'سرور', 'تست' => 'PDO MySQL', 'وضعیت' => extension_loaded('pdo_mysql'), 'جزئیات' => extension_loaded('pdo_mysql') ? 'فعال' : 'غیرفعال', 'راه‌حل' => 'فعال‌سازی pdo_mysql'];
        $this->diagnosticResults[] = ['بخش' => 'سرور', 'تست' => 'cURL', 'وضعیت' => extension_loaded('curl'), 'جزئیات' => extension_loaded('curl') ? 'فعال' : 'غیرفعال', 'راه‌حل' => 'فعال‌سازی curl'];
        $this->diagnosticResults[] = ['بخش' => 'سرور', 'تست' => 'دسترسی نوشتن', 'وضعیت' => is_writable(__DIR__), 'جزئیات' => is_writable(__DIR__) ? 'مجاز' : 'غیرمجاز', 'راه‌حل' => 'chmod 755'];
        
        // فایل‌ها
        foreach (['config.php', 'database.php', 'bot.php', 'index.php', 'admin.php'] as $f) {
            $exists = file_exists(__DIR__ . '/' . $f);
            $this->diagnosticResults[] = ['بخش' => 'فایل‌ها', 'تست' => $f, 'وضعیت' => $exists, 'جزئیات' => $exists ? 'موجود' : 'ناموجود', 'راه‌حل' => 'اجرای install.php'];
        }
        
        // تنظیمات
        if (file_exists(__DIR__ . '/config.php')) {
            require_once __DIR__ . '/config.php';
            
            $this->diagnosticResults[] = ['بخش' => 'تنظیمات', 'تست' => 'توکن', 'وضعیت' => defined('BOT_TOKEN') && !empty(BOT_TOKEN), 'جزئیات' => defined('BOT_TOKEN') && !empty(BOT_TOKEN) ? substr(BOT_TOKEN, 0, 15) . '...' : 'خالی', 'راه‌حل' => 'توکن را وارد کنید'];
            $this->diagnosticResults[] = ['بخش' => 'تنظیمات', 'تست' => 'ADMIN_IDS', 'وضعیت' => defined('ADMIN_IDS') && !empty(ADMIN_IDS), 'جزئیات' => defined('ADMIN_IDS') ? implode(',', ADMIN_IDS) : 'خالی', 'راه‌حل' => 'آیدی ادمین را وارد کنید'];
            $this->diagnosticResults[] = ['بخش' => 'تنظیمات', 'تست' => 'دیتابیس', 'وضعیت' => defined('DB_NAME') && !empty(DB_NAME), 'جزئیات' => defined('DB_NAME') ? DB_NAME : 'خالی', 'راه‌حل' => 'اطلاعات دیتابیس را وارد کنید'];
            
            // API
            if (defined('BOT_TOKEN') && !empty(BOT_TOKEN)) {
                $ch = curl_init('https://api.telegram.org/bot' . BOT_TOKEN . '/getMe');
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_TIMEOUT => 10]);
                $resp = curl_exec($ch);
                $err = curl_error($ch);
                curl_close($ch);
                
                if ($err) {
                    $this->diagnosticResults[] = ['بخش' => 'API', 'تست' => 'اتصال', 'وضعیت' => false, 'جزئیات' => $err, 'راه‌حل' => 'سرور دسترسی ندارد'];
                } else {
                    $data = json_decode($resp, true);
                    $this->diagnosticResults[] = ['بخش' => 'API', 'تست' => 'توکن', 'وضعیت' => $data['ok'], 'جزئیات' => $data['ok'] ? $data['result']['first_name'] : $data['description'], 'راه‌حل' => 'توکن جدید بگیرید'];
                }
                
                // Webhook
                $whResp = @file_get_contents('https://api.telegram.org/bot' . BOT_TOKEN . '/getWebhookInfo');
                if ($whResp) {
                    $whData = json_decode($whResp, true);
                    if ($whData['ok']) {
                        $info = $whData['result'];
                        $this->diagnosticResults[] = ['بخش' => 'Webhook', 'تست' => 'URL', 'وضعیت' => !empty($info['url']), 'جزئیات' => $info['url'] ?: 'خالی', 'راه‌حل' => 'اجرای webhook'];
                        if (!empty($info['last_error_message'])) {
                            $this->diagnosticResults[] = ['بخش' => 'Webhook', 'تست' => 'خطا', 'وضعیت' => false, 'جزئیات' => $info['last_error_message'], 'راه‌حل' => 'بررسی index.php'];
                        }
                    }
                }
                
                // تست پیام
                if (defined('ADMIN_IDS') && !empty(ADMIN_IDS)) {
                    $adminId = ADMIN_IDS[0];
                    $tResp = @file_get_contents('https://api.telegram.org/bot' . BOT_TOKEN . '/sendMessage?chat_id=' . $adminId . '&text=' . urlencode('✅ تست - ' . date('H:i:s')));
                    if ($tResp) {
                        $tData = json_decode($tResp, true);
                        $this->diagnosticResults[] = ['بخش' => 'پیام', 'تست' => 'ارسال', 'وضعیت' => $tData['ok'], 'جزئیات' => $tData['ok'] ? 'ارسال شد' : $tData['description'], 'راه‌حل' => '/start بزنید'];
                    }
                }
            }
            
            // دیتابیس
            if (file_exists(__DIR__ . '/database.php') && defined('DB_NAME') && !empty(DB_NAME)) {
                require_once __DIR__ . '/database.php';
                try {
                    $db = Database::getInstance();
                    $this->diagnosticResults[] = ['بخش' => 'دیتابیس', 'تست' => 'اتصال', 'وضعیت' => $db->isConnected(), 'جزئیات' => $db->isConnected() ? DB_NAME : 'متصل نیست', 'راه‌حل' => 'بررسی اطلاعات'];
                } catch (Exception $e) {
                    $this->diagnosticResults[] = ['بخش' => 'دیتابیس', 'تست' => 'اتصال', 'وضعیت' => false, 'جزئیات' => $e->getMessage(), 'راه‌حل' => 'اصلاح اطلاعات'];
                }
            }
        }
        
        // لاگ‌ها
        foreach (['logs/errors.log', 'logs/requests.log'] as $log) {
            $lp = __DIR__ . '/' . $log;
            $exists = file_exists($lp);
            $this->diagnosticResults[] = ['بخش' => 'لاگ‌ها', 'تست' => $log, 'وضعیت' => $exists, 'جزئیات' => $exists ? 'موجود' : 'ناموجود', 'راه‌حل' => 'به ربات پیام بدهید'];
        }
    }
    
    private function getAllFileContents($config) {
        $webhookUrl = $this->getWebhookUrl();
        
        return [
            'config.php' => $this->getConfigCode($config, $webhookUrl),
            'index.php' => $this->getIndexCode(),
            'database.php' => $this->getDatabaseCode(),
            'bot.php' => $this->getBotCode(),
            'admin.php' => $this->getAdminCode(),
            'diagnostic.php' => $this->getDiagnosticCode()
        ];
    }
    
    private function getConfigCode($config, $webhookUrl) {
        return "<?php
if (!defined('SECURE_ACCESS')) {
    define('SECURE_ACCESS', true);
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
define('BOT_TOKEN', '{$config['bot_token']}');
define('BOT_USERNAME', '{$config['bot_username']}');
define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');
define('WEBHOOK_URL', '{$webhookUrl}');
define('ADMIN_IDS', [{$config['admin_ids']}]);
define('DB_HOST', '{$config['db_host']}');
define('DB_NAME', '{$config['db_name']}');
define('DB_USER', '{$config['db_user']}');
define('DB_PASS', '{$config['db_pass']}');
define('DB_CHARSET', 'utf8mb4');
define('DB_PREFIX', '{$config['db_prefix']}');
define('MAX_FILE_SIZE', 1073741824);
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('ALLOWED_EXTENSIONS', ['pdf','zip','rar','7z','mp4','mp3','avi','mkv','apk','exe','doc','docx','jpg','png','gif','txt','iso']);
date_default_timezone_set('Asia/Tehran');
define('ENCRYPTION_KEY', '" . md5(uniqid()) . "');
define('BOT_VERSION', '16');
define('ADMIN_PASSWORD', 'admin123');
?>";
    }
    
    private function getIndexCode() {
        return "<?php
if (!defined('SECURE_ACCESS')) {
    define('SECURE_ACCESS', true);
}
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/errors.log');
file_put_contents(__DIR__ . '/logs/requests.log', date('Y-m-d H:i:s') . ' - Webhook called' . PHP_EOL, FILE_APPEND);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/bot.php';
\$content = file_get_contents('php://input');
\$update = json_decode(\$content, true);
if (\$update) {
    try {
        \$bot = new TelegramBot();
        \$bot->processUpdate(\$update);
    } catch (Exception \$e) {
        error_log('Error: ' . \$e->getMessage());
    }
}
http_response_code(200);
echo 'OK';
?>";
    }
    
    private function getDatabaseCode() {
        return "<?php
class Database {
    private static \$instance = null;
    private \$pdo = null;
    private function __construct() { \$this->connect(); }
    private function connect() {
        if (!defined('DB_NAME') || empty(DB_NAME) || empty(DB_USER)) return;
        try {
            \$dsn = 'mysql:host=' . DB_HOST . ';port=3306;dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            \$this->pdo = new PDO(\$dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
        } catch (PDOException \$e) {
            error_log('DB: ' . \$e->getMessage());
        }
    }
    public static function getInstance() {
        if (self::\$instance === null) self::\$instance = new self();
        return self::\$instance;
    }
    public function isConnected() { return \$this->pdo !== null; }
    public function getConnection() { if (\$this->pdo === null) \$this->connect(); return \$this->pdo; }
    public function table(\$name) { return DB_PREFIX . \$name; }
    public function addFile(\$data) {
        if (!\$this->isConnected()) return false;
        \$sql = 'INSERT IGNORE INTO ' . \$this->table('files') . ' (file_id, file_name, file_size, file_type, category, description, download_link) VALUES (?, ?, ?, ?, ?, ?, ?)';
        \$stmt = \$this->pdo->prepare(\$sql);
        return \$stmt->execute([\$data['file_id'], \$data['file_name'], \$data['file_size'], \$data['file_type'] ?? '', \$data['category'] ?? 'عمومی', \$data['description'] ?? '', \$data['download_link'] ?? '']);
    }
    public function getFileById(\$fileId) {
        if (!\$this->isConnected()) return null;
        \$stmt = \$this->pdo->prepare('SELECT * FROM ' . \$this->table('files') . ' WHERE file_id = ?');
        \$stmt->execute([\$fileId]);
        return \$stmt->fetch();
    }
    public function searchFiles(\$keyword = '', \$category = '', \$limit = 10) {
        if (!\$this->isConnected()) return [];
        \$sql = 'SELECT * FROM ' . \$this->table('files') . ' WHERE is_active = 1';
        \$params = [];
        if (!empty(\$keyword)) { \$sql .= ' AND file_name LIKE ?'; \$params[] = \"%{\$keyword}%\"; }
        if (!empty(\$category) && \$category !== 'all') { \$sql .= ' AND category = ?'; \$params[] = \$category; }
        \$sql .= ' ORDER BY created_at DESC LIMIT ?';
        \$params[] = (int)\$limit;
        \$stmt = \$this->pdo->prepare(\$sql);
        \$stmt->execute(\$params);
        return \$stmt->fetchAll();
    }
    public function incrementDownload(\$fileId) {
        if (!\$this->isConnected()) return;
        \$this->pdo->prepare('UPDATE ' . \$this->table('files') . ' SET download_count = download_count + 1 WHERE file_id = ?')->execute([\$fileId]);
    }
    public function registerUser(\$user) {
        if (!\$this->isConnected()) return;
        \$sql = 'INSERT IGNORE INTO ' . \$this->table('users') . ' (user_id, username, first_name, last_name) VALUES (?, ?, ?, ?)';
        \$this->pdo->prepare(\$sql)->execute([\$user['id'], \$user['username'] ?? '', \$user['first_name'] ?? '', \$user['last_name'] ?? '']);
    }
    public function getCategories() {
        if (!\$this->isConnected()) return [];
        return \$this->pdo->query('SELECT * FROM ' . \$this->table('categories') . ' ORDER BY sort_order')->fetchAll();
    }
    public function getStats() {
        if (!\$this->isConnected()) return ['files' => 0, 'users' => 0, 'downloads' => 0];
        return [
            'files' => \$this->pdo->query('SELECT COUNT(*) FROM ' . \$this->table('files'))->fetchColumn(),
            'users' => \$this->pdo->query('SELECT COUNT(*) FROM ' . \$this->table('users'))->fetchColumn(),
            'downloads' => \$this->pdo->query('SELECT COUNT(*) FROM ' . \$this->table('download_logs'))->fetchColumn()
        ];
    }
}
?>";
    }
    
    private function getBotCode() {
        return "<?php
if (!defined('SECURE_ACCESS')) { die('Access Denied!'); }

class TelegramBot {
    private \$db;
    public function __construct() { \$this->db = Database::getInstance(); }
    
    public function processUpdate(\$update) {
        if (isset(\$update['message'])) \$this->handleMessage(\$update['message']);
        if (isset(\$update['callback_query'])) \$this->handleCallback(\$update['callback_query']);
    }
    
    private function handleMessage(\$message) {
        \$chatId = \$message['chat']['id'];
        \$userId = \$message['from']['id'] ?? 0;
        \$text = \$message['text'] ?? '';
        \$firstName = \$message['from']['first_name'] ?? 'کاربر';
        
        try { \$this->db->registerUser(\$message['from']); } catch (Exception \$e) {}
        
        if (\$this->isFileMessage(\$message) && isAdmin(\$userId)) {
            \$this->handleFileUpload(\$chatId, \$message);
            return;
        }
        
        if (strpos(\$text, '/start') === 0) {
            \$this->sendMessage(\$chatId, \"🎉 سلام <b>{\$firstName}</b>!\\n\\nبه ربات خوش آمدید!\\n\\nدستورات:\\n🔍 /search\\n📂 /categories\\n📊 /stats\\nℹ️ /help\");
        } elseif (strpos(\$text, '/search') === 0) {
            \$this->cmdSearch(\$chatId, trim(str_replace('/search', '', \$text)));
        } elseif (strpos(\$text, '/stats') === 0) {
            \$stats = \$this->db->getStats();
            \$this->sendMessage(\$chatId, \"📊 {\$stats['files']} فایل | {\$stats['users']} کاربر | {\$stats['downloads']} دانلود\");
        } elseif (strpos(\$text, '/categories') === 0) {
            \$this->showCategories(\$chatId);
        } elseif (strpos(\$text, '/help') === 0) {
            \$this->sendMessage(\$chatId, '/start /search /stats /categories');
        } else {
            \$this->sendMessage(\$chatId, 'منو:', \$this->getMainMenu());
        }
    }
    
    private function handleCallback(\$callback) {
        \$data = \$callback['data'];
        \$chatId = \$callback['message']['chat']['id'];
        \$userId = \$callback['from']['id'];
        \$messageId = \$callback['message']['message_id'];
        
        \$this->apiRequest('answerCallbackQuery', ['callback_query_id' => \$callback['id']]);
        
        if (strpos(\$data, 'download_') === 0) {
            \$this->sendFileForDownload(\$chatId, \$messageId, str_replace('download_', '', \$data), \$userId);
        } elseif (strpos(\$data, 'category_') === 0) {
            \$this->showCategoryFiles(\$chatId, \$messageId, str_replace('category_', '', \$data));
        } elseif (\$data === 'categories') {
            \$this->showCategories(\$chatId, \$messageId);
        } elseif (\$data === 'stats') {
            \$stats = \$this->db->getStats();
            \$this->editMessage(\$chatId, \$messageId, \"📊 {\$stats['files']} فایل | {\$stats['users']} کاربر\");
        } elseif (\$data === 'main_menu') {
            \$this->editMessage(\$chatId, \$messageId, 'منو', \$this->getMainMenu());
        }
    }
    
    private function handleFileUpload(\$chatId, \$message) {
        \$fileData = null;
        \$caption = \$message['caption'] ?? '';
        \$category = 'عمومی';
        \$description = \$caption;
        
        if (!empty(\$caption) && preg_match('/#(\S+)/', \$caption, \$m)) {
            \$category = \$m[1];
            \$description = trim(preg_replace('/#\S+/', '', \$caption));
        }
        
        if (isset(\$message['document'])) {
            \$doc = \$message['document'];
            \$fileData = ['file_id' => \$doc['file_id'], 'file_name' => \$doc['file_name'] ?? 'file_' . time(), 'file_size' => \$doc['file_size'] ?? 0, 'file_type' => strtolower(pathinfo(\$doc['file_name'] ?? '', PATHINFO_EXTENSION)), 'category' => \$category, 'description' => \$description, 'download_link' => ''];
        } elseif (isset(\$message['video'])) {
            \$vid = \$message['video'];
            \$fileData = ['file_id' => \$vid['file_id'], 'file_name' => 'video_' . time() . '.mp4', 'file_size' => \$vid['file_size'] ?? 0, 'file_type' => 'mp4', 'category' => \$category, 'description' => \$description, 'download_link' => ''];
        } elseif (isset(\$message['audio'])) {
            \$aud = \$message['audio'];
            \$fileData = ['file_id' => \$aud['file_id'], 'file_name' => (\$aud['title'] ?? 'audio') . '.mp3', 'file_size' => \$aud['file_size'] ?? 0, 'file_type' => 'mp3', 'category' => \$category, 'description' => \$description, 'download_link' => ''];
        } elseif (isset(\$message['photo'])) {
            \$photo = end(\$message['photo']);
            \$fileData = ['file_id' => \$photo['file_id'], 'file_name' => 'photo_' . time() . '.jpg', 'file_size' => \$photo['file_size'] ?? 0, 'file_type' => 'jpg', 'category' => \$category, 'description' => \$description, 'download_link' => ''];
        }
        
        if (\$fileData) {
            \$fileData['download_link'] = \$this->getDirectDownloadLink(\$fileData['file_id']);
            
            try { \$this->db->addFile(\$fileData); } catch (Exception \$e) {}
            
            \$size = \$this->formatSize(\$fileData['file_size']);
            \$text = \"✅ <b>فایل ثبت شد!</b>\\n\\n📁 {\$fileData['file_name']}\\n📦 {\$size}\\n📂 {\$fileData['category']}\";
            
            if (!empty(\$fileData['download_link'])) {
                \$text .= \"\\n\\n🔗 <b>لینک دانلود:</b>\\n<code>{\$fileData['download_link']}</code>\";
                \$keyboard = ['inline_keyboard' => [[['text' => '🔗 دانلود مستقیم', 'url' => \$fileData['download_link']]], [['text' => '📥 دریافت', 'callback_data' => 'download_' . \$fileData['file_id']]], [['text' => '🔙 منو', 'callback_data' => 'main_menu']]]];
            } else {
                \$text .= \"\\n\\n📥 برای دریافت دکمه زیر را بزنید:\";
                \$keyboard = ['inline_keyboard' => [[['text' => '📥 دریافت فایل', 'callback_data' => 'download_' . \$fileData['file_id']]], [['text' => '🔙 منو', 'callback_data' => 'main_menu']]]];
            }
            
            \$this->sendMessage(\$chatId, \$text, \$keyboard);
        }
    }
    
    private function getDirectDownloadLink(\$fileId) {
        \$ch = curl_init(API_URL . 'getFile');
        curl_setopt_array(\$ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => ['file_id' => \$fileId], CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_TIMEOUT => 10]);
        \$response = curl_exec(\$ch);
        curl_close(\$ch);
        
        if (\$response) {
            \$data = json_decode(\$response, true);
            if (\$data['ok'] && isset(\$data['result']['file_path'])) {
                return 'https://api.telegram.org/file/bot' . BOT_TOKEN . '/' . \$data['result']['file_path'];
            }
        }
        
        \$url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/getFile?file_id=' . urlencode(\$fileId);
        \$resp2 = @file_get_contents(\$url);
        if (\$resp2) {
            \$data2 = json_decode(\$resp2, true);
            if (\$data2['ok'] && isset(\$data2['result']['file_path'])) {
                return 'https://api.telegram.org/file/bot' . BOT_TOKEN . '/' . \$data2['result']['file_path'];
            }
        }
        
        return '';
    }
    
    private function sendFileForDownload(\$chatId, \$messageId, \$fileId, \$userId) {
        \$file = \$this->db->getFileById(\$fileId);
        if (!\$file) { \$this->sendMessage(\$chatId, '❌ فایل یافت نشد!'); return; }
        
        try { \$this->db->incrementDownload(\$fileId); } catch (Exception \$e) {}
        
        \$size = \$this->formatSize(\$file['file_size']);
        
        if (!empty(\$file['download_link'])) {
            \$text = \"📥 <b>{\$file['file_name']}</b>\\n📦 {\$size}\\n📂 {\$file['category']}\\n\\n🔗 <code>{\$file['download_link']}</code>\";
            \$keyboard = ['inline_keyboard' => [[['text' => '🔗 دانلود', 'url' => \$file['download_link']]], [['text' => '🔙 بازگشت', 'callback_data' => 'main_menu']]]];
            \$this->editMessage(\$chatId, \$messageId, \$text, \$keyboard);
            return;
        }
        
        \$fileType = strtolower(\$file['file_type']);
        \$caption = \"📁 {\$file['file_name']}\";
        
        if (in_array(\$fileType, ['mp4', 'avi', 'mkv'])) {
            \$result = \$this->apiRequest('sendVideo', ['chat_id' => \$chatId, 'video' => \$file['file_id'], 'caption' => \$caption]);
        } elseif (in_array(\$fileType, ['mp3', 'ogg'])) {
            \$result = \$this->apiRequest('sendAudio', ['chat_id' => \$chatId, 'audio' => \$file['file_id'], 'caption' => \$caption]);
        } elseif (in_array(\$fileType, ['jpg', 'png', 'jpeg'])) {
            \$result = \$this->apiRequest('sendPhoto', ['chat_id' => \$chatId, 'photo' => \$file['file_id'], 'caption' => \$caption]);
        } else {
            \$result = \$this->apiRequest('sendDocument', ['chat_id' => \$chatId, 'document' => \$file['file_id'], 'caption' => \$caption]);
        }
        
        if (!\$result || !\$result['ok']) {
            \$this->sendMessage(\$chatId, '❌ ' . (\$result['description'] ?? 'خطا'));
        }
    }
    
    private function cmdSearch(\$chatId, \$keyword) {
        if (empty(\$keyword)) { \$this->sendMessage(\$chatId, '❌ /search کتاب'); return; }
        \$files = \$this->db->searchFiles(\$keyword);
        if (empty(\$files)) { \$this->sendMessage(\$chatId, '❌ نتیجه‌ای نیست!'); return; }
        
        \$text = \"🔍 نتایج: {\$keyword}\\n\\n\";
        \$keyboard = ['inline_keyboard' => []];
        foreach (\$files as \$file) {
            \$size = \$this->formatSize(\$file['file_size']);
            \$keyboard['inline_keyboard'][] = [['text' => \"📥 {\$file['file_name']} ({\$size})\", 'callback_data' => 'download_' . \$file['file_id']]];
        }
        \$keyboard['inline_keyboard'][] = [['text' => '🔙 بازگشت', 'callback_data' => 'main_menu']];
        \$this->sendMessage(\$chatId, \$text, \$keyboard);
    }
    
    private function showCategories(\$chatId, \$messageId = null) {
        \$categories = \$this->db->getCategories();
        \$keyboard = ['inline_keyboard' => []];
        foreach (\$categories as \$cat) {
            \$keyboard['inline_keyboard'][] = [['text' => \"{\$cat['icon']} {\$cat['name']}\", 'callback_data' => 'category_' . \$cat['name']]];
        }
        \$keyboard['inline_keyboard'][] = [['text' => '🔙 بازگشت', 'callback_data' => 'main_menu']];
        
        if (\$messageId) {
            \$this->editMessage(\$chatId, \$messageId, '📂 دسته‌بندی‌ها:', \$keyboard);
        } else {
            \$this->sendMessage(\$chatId, '📂 دسته‌بندی‌ها:', \$keyboard);
        }
    }
    
    private function showCategoryFiles(\$chatId, \$messageId, \$category) {
        \$files = \$this->db->searchFiles('', \$category);
        \$keyboard = ['inline_keyboard' => []];
        foreach (\$files as \$file) {
            \$size = \$this->formatSize(\$file['file_size']);
            \$keyboard['inline_keyboard'][] = [['text' => \"📥 {\$file['file_name']} ({\$size})\", 'callback_data' => 'download_' . \$file['file_id']]];
        }
        \$keyboard['inline_keyboard'][] = [['text' => '🔙 بازگشت', 'callback_data' => 'categories']];
        \$this->editMessage(\$chatId, \$messageId, \"📂 {\$category}:\", \$keyboard);
    }
    
    private function getMainMenu() {
        return ['inline_keyboard' => [
            [['text' => '🔍 جستجو', 'callback_data' => 'search'], ['text' => '📂 دسته‌بندی‌ها', 'callback_data' => 'categories']],
            [['text' => '📊 آمار', 'callback_data' => 'stats'], ['text' => 'ℹ️ راهنما', 'callback_data' => 'help']]
        ]];
    }
    
    private function sendMessage(\$chatId, \$text, \$keyboard = null) {
        if (empty(BOT_TOKEN)) return null;
        \$params = ['chat_id' => \$chatId, 'text' => \$text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
        if (\$keyboard) \$params['reply_markup'] = json_encode(\$keyboard);
        return \$this->apiRequest('sendMessage', \$params);
    }
    
    private function editMessage(\$chatId, \$messageId, \$text, \$keyboard = null) {
        \$params = ['chat_id' => \$chatId, 'message_id' => \$messageId, 'text' => \$text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
        if (\$keyboard) \$params['reply_markup'] = json_encode(\$keyboard);
        return \$this->apiRequest('editMessageText', \$params);
    }
    
    private function apiRequest(\$method, \$params = []) {
        if (empty(BOT_TOKEN)) return null;
        \$ch = curl_init(API_URL . \$method);
        curl_setopt_array(\$ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => \$params,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 5
        ]);
        \$response = curl_exec(\$ch);
        curl_close(\$ch);
        return json_decode(\$response, true);
    }
    
    private function isFileMessage(\$message) {
        return isset(\$message['document']) || isset(\$message['video']) || isset(\$message['audio']) || isset(\$message['photo']);
    }
    
    private function formatSize(\$bytes) {
        if (\$bytes >= 1073741824) return round(\$bytes / 1073741824, 2) . ' GB';
        if (\$bytes >= 1048576) return round(\$bytes / 1048576, 2) . ' MB';
        if (\$bytes >= 1024) return round(\$bytes / 1024, 2) . ' KB';
        return \$bytes . ' B';
    }
}

function isAdmin(\$userId) {
    \$admins = defined('ADMIN_IDS') ? ADMIN_IDS : [];
    if (!is_array(\$admins)) \$admins = [\$admins];
    return in_array(\$userId, \$admins);
}
?>";
    }
    
    private function getAdminCode() {
        return "<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

if (empty(\$_SESSION['admin_logged_in'])) {
    \$error = '';
    if (isset(\$_POST['login']) && \$_POST['password'] === ADMIN_PASSWORD) {
        \$_SESSION['admin_logged_in'] = true;
        header('Location: admin.php');
        exit;
    } elseif (isset(\$_POST['login'])) { \$error = 'رمز اشتباه!'; }
    ?><!DOCTYPE html><html dir='rtl'><head><meta charset='UTF-8'><title>ورود</title>
    <style>body{font-family:Tahoma;background:linear-gradient(135deg,#667eea,#764ba2);display:flex;justify-content:center;align-items:center;height:100vh}.box{background:white;padding:40px;border-radius:20px;width:350px;text-align:center}input{width:100%;padding:15px;margin:10px 0;border:2px solid #e0e0e0;border-radius:10px}button{width:100%;padding:15px;background:#667eea;color:white;border:none;border-radius:10px;font-size:16px;cursor:pointer}</style>
    </head><body><div class='box'><h2>🔐 پنل مدیریت</h2>
    <?php if(\$error): ?><p style='color:red'><?php echo \$error; ?></p><?php endif; ?>
    <form method='POST'><input type='password' name='password' placeholder='رمز عبور' required><button type='submit' name='login'>ورود</button></form>
    </div></body></html><?php
    exit;
}

if (isset(\$_GET['logout'])) { unset(\$_SESSION['admin_logged_in']); header('Location: admin.php'); exit; }

\$db = Database::getInstance();
\$stats = \$db->getStats();
?><!DOCTYPE html><html dir='rtl'><head><meta charset='UTF-8'><title>پنل</title>
<style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:Tahoma;background:#f0f2f5;padding:20px}.container{max-width:600px;margin:0 auto}.header{background:white;padding:20px;border-radius:15px;margin-bottom:20px;display:flex;justify-content:space-between}.header h1{color:#667eea;font-size:20px}.header a{color:#dc3545;text-decoration:none}.stats{display:grid;grid-template-columns:1fr 1fr 1fr;gap:15px}.card{background:white;padding:25px;border-radius:10px;text-align:center}.card h3{color:#666;font-size:13px}.card p{font-size:28px;font-weight:bold;color:#667eea}</style>
</head><body><div class='container'>
<div class='header'><h1>🤖 پنل مدیریت</h1><a href='?logout=1'>🚪 خروج</a></div>
<div class='stats'>
<div class='card'><h3>📁 فایل‌ها</h3><p><?php echo \$stats['files']; ?></p></div>
<div class='card'><h3>👥 کاربران</h3><p><?php echo \$stats['users']; ?></p></div>
<div class='card'><h3>📥 دانلودها</h3><p><?php echo \$stats['downloads']; ?></p></div>
</div></div></body></html>";
    }
    
    private function getDiagnosticCode() {
        return "<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
if (session_status() === PHP_SESSION_NONE) { session_start(); }
echo '<!DOCTYPE html><html dir=\"rtl\"><head><meta charset=\"UTF-8\"><title>عیب‌یاب</title>';
echo '<style>body{font-family:Tahoma;background:#f0f2f5;padding:20px}.container{max-width:800px;margin:0 auto;background:white;border-radius:15px;padding:30px}h1{color:#667eea}h2{color:#333;margin-top:25px;border-bottom:2px solid #f0f0f0;padding-bottom:10px;font-size:18px}.pass{color:#28a745;font-weight:bold}.fail{color:#dc3545;font-weight:bold}.fix{background:#fff3cd;padding:10px;border-radius:5px;margin:5px 0;font-size:13px}.ok{background:#d4edda;padding:10px;border-radius:5px;margin:5px 0;font-size:13px}</style>';
echo '</head><body><div class=\"container\">';
echo '<h1>🔍 عیب‌یاب کامل</h1>';
echo '<p>' . date('Y-m-d H:i:s') . '</p>';
echo '<h2>۱. سرور</h2>';
echo '<p class=\"' . (version_compare(PHP_VERSION,'7.4','>=') ? 'pass' : 'fail') . '\">' . (version_compare(PHP_VERSION,'7.4','>=') ? '✅' : '❌') . ' PHP: ' . phpversion() . '</p>';
echo '<p class=\"' . (extension_loaded('pdo_mysql') ? 'pass' : 'fail') . '\">' . (extension_loaded('pdo_mysql') ? '✅' : '❌') . ' PDO MySQL</p>';
echo '<p class=\"' . (extension_loaded('curl') ? 'pass' : 'fail') . '\">' . (extension_loaded('curl') ? '✅' : '❌') . ' cURL</p>';
echo '<h2>۲. فایل‌ها</h2>';
foreach (['config.php','database.php','bot.php','index.php','admin.php'] as \$f) {
    \$ex = file_exists(__DIR__ . '/' . \$f);
    echo '<p class=\"' . (\$ex ? 'pass' : 'fail') . '\">' . (\$ex ? '✅' : '❌') . ' ' . \$f . '</p>';
}
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
    echo '<h2>۳. تنظیمات</h2>';
    echo '<p>' . (defined('BOT_TOKEN') && !empty(BOT_TOKEN) ? '✅' : '❌') . ' توکن</p>';
    echo '<p>' . (defined('ADMIN_IDS') && !empty(ADMIN_IDS) ? '✅' : '❌') . ' ADMIN_IDS</p>';
    echo '<p>' . (defined('DB_NAME') && !empty(DB_NAME) ? '✅' : '❌') . ' دیتابیس</p>';
    echo '<h2>۴. API</h2>';
    if (defined('BOT_TOKEN') && !empty(BOT_TOKEN)) {
        \$ch = curl_init('https://api.telegram.org/bot' . BOT_TOKEN . '/getMe');
        curl_setopt_array(\$ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_TIMEOUT => 10]);
        \$resp = curl_exec(\$ch);
        curl_close(\$ch);
        if (\$resp) {
            \$data = json_decode(\$resp, true);
            echo \$data['ok'] ? '<div class=\"ok\">✅ ' . \$data['result']['first_name'] . '</div>' : '<p class=\"fail\">❌ ' . \$data['description'] . '</p>';
        } else {
            echo '<p class=\"fail\">❌ API در دسترس نیست</p>';
        }
        echo '<h2>۵. Webhook</h2>';
        \$whResp = @file_get_contents('https://api.telegram.org/bot' . BOT_TOKEN . '/getWebhookInfo');
        if (\$whResp) {
            \$whData = json_decode(\$whResp, true);
            if (\$whData['ok']) {
                \$info = \$whData['result'];
                echo '<p>📍 URL: ' . (\$info['url'] ?: '❌ خالی') . '</p>';
                if (!empty(\$info['url'])) echo '<p class=\"pass\">✅ تنظیم شده</p>';
                if (!empty(\$info['last_error_message'])) echo '<p class=\"fail\">❌ ' . \$info['last_error_message'] . '</p>';
            }
        }
        echo '<h2>۶. تست پیام</h2>';
        if (defined('ADMIN_IDS') && !empty(ADMIN_IDS)) {
            \$adminId = ADMIN_IDS[0];
            \$tResp = @file_get_contents('https://api.telegram.org/bot' . BOT_TOKEN . '/sendMessage?chat_id=' . \$adminId . '&text=' . urlencode('✅ تست - ' . date('H:i:s')));
            if (\$tResp) {
                \$tData = json_decode(\$tResp, true);
                echo \$tData['ok'] ? '<div class=\"ok\">✅ پیام ارسال شد</div>' : '<p class=\"fail\">❌ ' . \$tData['description'] . '</p>';
            }
        }
    }
    echo '<h2>۷. دیتابیس</h2>';
    if (file_exists(__DIR__ . '/database.php') && defined('DB_NAME') && !empty(DB_NAME)) {
        require_once __DIR__ . '/database.php';
        try {
            \$db = Database::getInstance();
            echo \$db->isConnected() ? '<div class=\"ok\">✅ متصل</div>' : '<p class=\"fail\">❌ متصل نیست</p>';
        } catch (Exception \$e) {
            echo '<p class=\"fail\">❌ ' . \$e->getMessage() . '</p>';
        }
    }
    echo '<h2>۸. لاگ‌ها</h2>';
    foreach (['logs/errors.log', 'logs/requests.log'] as \$log) {
        \$lp = __DIR__ . '/' . \$log;
        echo file_exists(\$lp) ? '<p>✅ ' . \$log . '</p>' : '<p class=\"fail\">❌ ' . \$log . '</p>';
    }
}
echo '</div></body></html>';
?>";
    }
    
    public function render() {
        ?>
        <!DOCTYPE html>
        <html dir="rtl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>🚀 نصب ربات</title>
        <style>
            *{margin:0;padding:0;box-sizing:border-box}
            body{font-family:Tahoma;background:linear-gradient(135deg,#667eea,#764ba2);min-height:100vh;padding:20px}
            .container{max-width:600px;margin:0 auto;background:white;border-radius:20px;padding:30px;box-shadow:0 20px 60px rgba(0,0,0,0.3)}
            h1{color:#667eea;text-align:center;margin-bottom:20px;font-size:22px}
            .alert{padding:12px;border-radius:8px;margin:8px 0;font-size:14px}
            .alert-success{background:#d4edda;color:#155724}
            .alert-error{background:#f8d7da;color:#721c24}
            .form-group{margin-bottom:15px}
            label{display:block;margin-bottom:5px;font-weight:bold;color:#555;font-size:13px}
            input{width:100%;padding:12px;border:2px solid #e0e0e0;border-radius:8px;font-size:14px;font-family:Tahoma}
            button{width:100%;padding:15px;background:#667eea;color:white;border:none;border-radius:10px;font-size:17px;cursor:pointer;font-weight:bold;margin-top:15px}
            .guide{background:#f0f4ff;padding:15px;border-radius:10px;margin:15px 0;border:1px solid #d0d8ff}
            .guide ol{padding-right:18px;font-size:12px;line-height:1.8}
            .success-icon{font-size:60px;text-align:center;margin:20px 0}
            .link-btn{display:inline-block;padding:12px 25px;background:#667eea;color:white;text-decoration:none;border-radius:8px;margin:5px;font-size:14px}
            .diag-table{width:100%;border-collapse:collapse;margin:10px 0}
            .diag-table th,.diag-table td{padding:8px;border:1px solid #ddd;font-size:12px;text-align:right}
            .diag-table th{background:#f5f5f5}
            .pass{color:#28a745;font-weight:bold}
            .fail{color:#dc3545;font-weight:bold}
        </style>
        </head><body>
        <div class="container">
            <h1>🚀 نصب ربات</h1>
            <?php foreach ($this->errors as $e): ?><div class="alert alert-error">❌ <?php echo $e; ?></div><?php endforeach; ?>
            <?php foreach ($this->messages as $m): ?><div class="alert alert-success">✅ <?php echo $m; ?></div><?php endforeach; ?>
            
            <?php if ($this->step == 1): ?>
                <form method="POST"><input type="hidden" name="step" value="1"><button type="submit">🔍 شروع نصب</button></form>
                <div style="text-align:center;margin-top:10px">
                    <a href="?diagnostic=1" class="link-btn">🔍 عیب‌یابی</a>
                    <a href="?webhook=1" class="link-btn">🌐 تنظیم Webhook</a>
                </div>
            <?php elseif ($this->step == 2): ?>
                <div class="guide">
                    <strong>📖 راهنمای دریافت اطلاعات:</strong>
                    <ol>
                        <li><strong>توکن:</strong> @BotFather → /mybots → API Token</li>
                        <li><strong>یوزرنیم:</strong> @BotFather → /mybots → Edit Bot</li>
                        <li><strong>آیدی ادمین:</strong> @userinfobot → Start → Your ID</li>
                        <li><strong>هاست دیتابیس:</strong> پنل هاست → MySQL → Host</li>
                        <li><strong>نام دیتابیس:</strong> پنل هاست → MySQL → Database</li>
                        <li><strong>کاربر:</strong> پنل هاست → MySQL → Username</li>
                        <li><strong>رمز:</strong> پنل هاست → MySQL → Password</li>
                    </ol>
                </div>
                <form method="POST">
                    <input type="hidden" name="step" value="2">
                    <div class="form-group"><label>🤖 توکن ربات:</label><input type="text" name="bot_token" required></div>
                    <div class="form-group"><label>📛 یوزرنیم ربات:</label><input type="text" name="bot_username" required></div>
                    <div class="form-group"><label>👑 آیدی عددی ادمین:</label><input type="text" name="admin_ids" required></div>
                    <div class="form-group"><label>🏷️ پیشوند جداول:</label><input type="text" name="db_prefix" value="bot_"></div>
                    <div class="form-group"><label>🗄️ هاست دیتابیس:</label><input type="text" name="db_host" required></div>
                    <div class="form-group"><label>📁 نام دیتابیس:</label><input type="text" name="db_name" required></div>
                    <div class="form-group"><label>👤 کاربر دیتابیس:</label><input type="text" name="db_user" required></div>
                    <div class="form-group"><label>🔑 رمز دیتابیس:</label><input type="password" name="db_pass"></div>
                    <button type="submit">💾 ذخیره و ادامه</button>
                </form>
            <?php elseif ($this->step == 3): ?>
                <form method="POST"><input type="hidden" name="step" value="3"><button type="submit">📁 ایجاد فایل‌ها</button></form>
            <?php elseif ($this->step == 4): ?>
                <form method="POST"><input type="hidden" name="step" value="4"><button type="submit">🗄️ ساخت جداول دیتابیس</button></form>
            <?php elseif ($this->step == 5): ?>
                <form method="POST"><input type="hidden" name="step" value="5"><button type="submit">🌐 تنظیم Webhook</button></form>
            <?php elseif ($this->step == 20): ?>
                <h2>🔍 عیب‌یابی کامل</h2>
                <table class="diag-table">
                    <thead><tr><th>بخش</th><th>تست</th><th>وضعیت</th><th>جزئیات</th><th>راه‌حل</th></tr></thead>
                    <tbody>
                    <?php foreach ($this->diagnosticResults as $r): ?>
                        <tr>
                            <td><?php echo $r['بخش']; ?></td>
                            <td><?php echo $r['تست']; ?></td>
                            <td class="<?php echo $r['وضعیت'] ? 'pass' : 'fail'; ?>"><?php echo $r['وضعیت'] ? '✅' : '❌'; ?></td>
                            <td><?php echo $r['جزئیات']; ?></td>
                            <td><?php echo $r['راه‌حل']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <a href="?" class="link-btn" style="display:block;text-align:center">🔙 بازگشت</a>
            <?php elseif ($this->step == 30): ?>
                <h2>🌐 تنظیم Webhook</h2>
                <a href="?" class="link-btn" style="display:block;text-align:center">🔙 بازگشت</a>
            <?php elseif ($this->step >= 99): ?>
                <div class="success-icon">🎉</div>
                <h2 style="color:#28a745;text-align:center">نصب با موفقیت انجام شد!</h2>
                <div class="alert alert-success">
                    ✅ فایل‌ها ایجاد شدند<br>
                    ✅ جداول ساخته شدند<br>
                    ✅ Webhook تنظیم شد
                </div>
                <div class="alert alert-error">
                    ⚠️ فایل install.php را حذف کنید!
                </div>
                <div style="text-align:center;margin-top:15px">
                    <a href="admin.php" class="link-btn">🔐 پنل مدیریت</a>
                    <a href="diagnostic.php" class="link-btn">🔍 عیب‌یاب</a>
                </div>
            <?php endif; ?>
        </div>
        </body></html>
        <?php
    }
}

$installer = new Installer();
$installer->render();
?>