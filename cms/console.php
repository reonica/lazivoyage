<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('CMS_INTERNAL', true);
require __DIR__ . '/lib/Store.php';

$command = $argv[1] ?? 'help';
try {
    if ($command === 'configure-production') {
        $store = new CMSStore();
        $settings = $store->settings();
        $settings['siteUrl'] = 'https://lazivoyage.com';
        $settings['publishUrl'] = 'https://lazivoyage.com';
        $settings['environment'] = 'production';
        $store->save('settings', $settings);
        $store->audit('site.configure-production', null, ['siteUrl' => 'https://lazivoyage.com']);
        fwrite(STDOUT, "Đã lưu cấu hình production cho https://lazivoyage.com.\nChưa xuất bản hoặc sửa file website. Sau khi nhập nội dung hiện có, áp dụng cấu hình trong CMS để phát hành.\n");
        exit(0);
    }
    if ($command === 'setup-token') {
        $path = __DIR__ . '/config.local.php';
        $config = is_file($path) ? require $path : [];
        if (!is_array($config)) { throw new RuntimeException('config.local.php không hợp lệ.'); }
        $config['setupToken'] = bin2hex(random_bytes(32));
        $source = "<?php\n// Secret host configuration. Never commit or upload this file to GitHub.\nif (!defined('CMS_INTERNAL')) { http_response_code(404); exit; }\nreturn " . var_export($config, true) . ";\n";
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.php';
        if (file_put_contents($temporary, $source, LOCK_EX) === false) { throw new RuntimeException('Không ghi được cấu hình CMS.'); }
        chmod($temporary, 0600);
        if (!rename($temporary, $path)) { @unlink($temporary); throw new RuntimeException('Không lưu được cấu hình CMS.'); }
        $publishUrl = 'https://lazivoyage.com';
        try { $store = new CMSStore(); $publishUrl = rtrim((string) ($store->settings()['publishUrl'] ?? $publishUrl), '/'); }
        catch (Throwable $ignored) { /* doctor reports missing runtime/storage; token creation remains available. */ }
        fwrite(STDOUT, "Khóa khởi tạo đã được tạo trong cms/config.local.php (không đưa lên GitHub).\nMở " . $publishUrl . "/admin/ và nhập khóa này để tạo quản trị viên đầu tiên:\n\n" . $config['setupToken'] . "\n\nKhóa chỉ có tác dụng khi chưa có tài khoản. Không gửi khóa qua chat hoặc email.\n");
        exit(0);
    }
    if ($command === 'doctor') {
        $checks = ['phpVersion' => PHP_VERSION, 'phpSupported' => version_compare(PHP_VERSION, '8.1.0', '>='), 'sqlite' => extension_loaded('pdo_sqlite'), 'dom' => class_exists('DOMDocument'), 'imageInspection' => function_exists('getimagesize')];
        try { $store = new CMSStore(); $checks['privateStorage'] = true; $checks['hasAccounts'] = (int) $store->db->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0; $checks['setupConfigured'] = strlen((string) (getenv('CMS_SETUP_TOKEN') ?: ($store->config['setupToken'] ?? ''))) >= 32; }
        catch (Throwable $error) { $checks['privateStorage'] = false; $checks['error'] = $error->getMessage(); }
        $checks['siteWritable'] = is_writable(dirname(__DIR__));
        $checks['requirements'] = 'PHP 8.1 trở lên; PDO SQLite, DOM, getimagesize; thư mục website và kho dữ liệu riêng có quyền ghi của tài khoản PHP.';
        if (!$checks['siteWritable'] || !($checks['privateStorage'] ?? false)) { $checks['permissionHelp'] = 'Cấp quyền ghi cho chủ sở hữu chạy PHP; kho riêng nên là 0700, file bí mật 0600. Không dùng chmod 777. Đặt CMS_DATA_DIR ngoài document root nếu thư mục cha chưa có quyền ghi.'; }
        fwrite(STDOUT, json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        exit($checks['phpSupported'] && $checks['sqlite'] && $checks['dom'] && $checks['imageInspection'] && $checks['siteWritable'] && ($checks['privateStorage'] ?? false) ? 0 : 1);
    }
    if ($command === 'backup') {
        $store = new CMSStore(); $dir = $store->dataDir . '/backups';
        if (!is_dir($dir) && !mkdir($dir, 0700)) { throw new RuntimeException('Không tạo được thư mục sao lưu riêng.'); }
        $file = $dir . '/cms-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.sqlite';
        $store->db->exec('VACUUM INTO ' . $store->db->quote($file)); chmod($file, 0600);
        fwrite(STDOUT, "Đã sao lưu database vào kho riêng. Sao lưu thêm uploads/cms và published.json cùng thư mục để khôi phục đầy đủ.\n" . $file . "\n");
        exit(0);
    }
    fwrite(STDOUT, "Lazi Voyage CMS\n\nphp cms/console.php doctor                Kiểm tra PHP và quyền ghi\nphp cms/console.php configure-production  Lưu cấu hình lazivoyage.com, chưa xuất bản\nphp cms/console.php setup-token           Tạo khóa quản trị viên đầu tiên\nphp cms/console.php backup                Sao lưu SQLite trong kho riêng\n\nCMS_DATA_DIR: đường dẫn tuyệt đối đến kho dữ liệu bên ngoài tất cả document root đang được hosting phục vụ.\n");
    exit($command === 'help' ? 0 : 2);
} catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
