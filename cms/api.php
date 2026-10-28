<?php
declare(strict_types=1);
define('CMS_INTERNAL', true);
require __DIR__ . '/lib/Store.php';
require __DIR__ . '/lib/Auth.php';

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'self'; base-uri 'none'; form-action 'none'");

function cms_reply(array $value, int $status = 200): never { http_response_code($status); echo CMSStore::json($value); exit; }
function cms_input(): array
{
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 3000000) { throw new CMSException('Yêu cầu quá lớn.', 413, 'payload_too_large'); }
    if (!str_starts_with(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) { throw new CMSException('Yêu cầu cần dùng JSON.', 415, 'content_type'); }
    try { $data = json_decode(file_get_contents('php://input'), true, 64, JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new CMSException('JSON không hợp lệ.', 400, 'invalid_json'); }
    if (!is_array($data) || array_is_list($data) && $data !== []) { throw new CMSException('Dữ liệu yêu cầu không hợp lệ.', 400, 'invalid_json'); }
    return $data;
}

try {
    $store = new CMSStore(); $auth = new CMSAuth($store);
    $action = (string) ($_GET['action'] ?? 'session');
    $get = ['session', 'bootstrap', 'users', 'revisions', 'preview'];
    $post = ['setup', 'login', 'logout', 'save', 'remove', 'user-save', 'password', 'upload', 'import', 'publish', 'restore'];
    if (!in_array($action, array_merge($get, $post), true)) { throw new CMSException('Không tìm thấy chức năng.', 404, 'not_found'); }
    $expected = in_array($action, $get, true) ? 'GET' : 'POST';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $expected) { header('Allow: ' . $expected); throw new CMSException('Phương thức không hợp lệ.', 405, 'method'); }
    if ($expected === 'POST') { $auth->verifyMutation(); }
    if ($action === 'session') { cms_reply(['user' => $auth->user(), 'csrf' => $auth->csrf(), 'needsSetup' => $auth->needsSetup(), 'setupReady' => strlen((string) (getenv('CMS_SETUP_TOKEN') ?: ($store->config['setupToken'] ?? ''))) >= 32, 'capabilities' => ['sqlite' => true, 'dom' => class_exists('DOMDocument'), 'storage' => true]]); }
    if ($action === 'setup') { $user = $auth->setup(cms_input()); cms_reply(['user' => $user, 'csrf' => $auth->csrf(), 'needsSetup' => false]); }
    if ($action === 'login') { $user = $auth->login(cms_input()); cms_reply(['user' => $user, 'csrf' => $auth->csrf(), 'needsSetup' => false]); }
    if ($action === 'logout') { $auth->logout(); cms_reply(['ok' => true, 'csrf' => $auth->csrf()]); }
    $user = $auth->requireUser();
    if ($action === 'bootstrap') {
        $data = ['user' => $user, 'csrf' => $auth->csrf(), 'settings' => $store->settings(), 'revisions' => $store->revisions()];
        foreach (['posts', 'pages', 'categories', 'tags', 'media'] as $collection) { $data[$collection] = $store->all($collection); }
        $data['snippets'] = $user['role'] === 'admin' ? $store->all('snippets') : [];
        if ($user['role'] !== 'admin') { $data['revisions'] = array_values(array_filter($data['revisions'], static fn($r) => !in_array($r['collection'], ['settings', 'snippets'], true))); }
        cms_reply($data);
    }
    if ($action === 'users') { cms_reply(['users' => $auth->users()]); }
    if ($action === 'user-save') { $data = cms_input(); if (!is_array($data['record'] ?? null)) { throw new CMSException('Thiếu dữ liệu tài khoản.'); } cms_reply(['user' => $auth->saveUser($data['record']), 'csrf' => $auth->csrf()]); }
    if ($action === 'password') { $auth->password(cms_input()); cms_reply(['ok' => true, 'csrf' => $auth->csrf()]); }
    if ($action === 'save') {
        $data = cms_input(); $collection = (string) ($data['collection'] ?? '');
        if (in_array($collection, ['settings', 'snippets'], true)) { $auth->requireUser(true); }
        if (!is_array($data['record'] ?? null)) { throw new CMSException('Thiếu nội dung cần lưu.'); }
        if ($collection === 'media') {
            $old = $store->get('media', (string) ($data['record']['id'] ?? ''));
            if (!$old) { throw new CMSException('Tải ảnh lên qua chức năng thư viện trước khi sửa thông tin.'); }
            foreach (['url', 'width', 'height', 'size'] as $field) { $data['record'][$field] = $old[$field]; }
        }
        cms_reply(['record' => $store->save($collection, $data['record'], $user['id'])]);
    }
    if ($action === 'remove') {
        $data = cms_input(); $collection = (string) ($data['collection'] ?? '');
        if (in_array($collection, ['settings', 'snippets', 'media'], true)) { $auth->requireUser(true); }
        $store->remove($collection, (string) ($data['id'] ?? ''), $user['id'], isset($data['version']) ? (int) $data['version'] : null);
        cms_reply(['ok' => true]);
    }
    if ($action === 'revisions') {
        $collection = isset($_GET['collection']) ? (string) $_GET['collection'] : null;
        if (in_array($collection, ['settings', 'snippets'], true)) { $auth->requireUser(true); }
        $rows = $store->revisions($collection, isset($_GET['id']) ? (string) $_GET['id'] : null);
        if ($user['role'] !== 'admin') { $rows = array_values(array_filter($rows, static fn($r) => !in_array($r['collection'], ['settings', 'snippets'], true))); }
        cms_reply(['revisions' => $rows]);
    }
    if ($action === 'restore') {
        $auth->requireUser(true); $data = cms_input();
        cms_reply(['record' => $store->restore((int) ($data['revisionId'] ?? 0), $user['id'])]);
    }
    if ($action === 'upload') {
        $store->limit('upload:' . $user['id'], 120, 3600);
        $file = $_FILES['file'] ?? null;
        if (!$file || !is_array($file) || !is_string($file['tmp_name'] ?? null) || (int) ($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) { throw new CMSException('Chọn một ảnh hợp lệ để tải lên.'); }
        if ((int) $file['size'] > 8 * 1024 * 1024) { throw new CMSException('Ảnh không được lớn hơn 8 MB.', 413, 'upload_size'); }
        $info = @getimagesize($file['tmp_name']);
        $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        if (!$info || !isset($types[$info['mime'] ?? '']) || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 80000000) { throw new CMSException('Chỉ nhận ảnh JPEG, PNG, WebP hoặc GIF hợp lệ; không nhận SVG.'); }
        $dir = $store->siteRoot . '/uploads/cms';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) { throw new CMSException('Thư mục uploads/cms chưa có quyền ghi.', 503, 'upload_storage'); }
        $rules = "Options -Indexes\n<IfModule mod_authz_core.c>\n<FilesMatch \"\\.(?:php[0-9]?|phtml|phar|cgi|pl|py|sh)$\">\nRequire all denied\n</FilesMatch>\n</IfModule>\n<IfModule mod_headers.c>\nHeader set X-Content-Type-Options nosniff\n</IfModule>\n";
        if (!is_file($dir . '/.htaccess') && file_put_contents($dir . '/.htaccess', $rules, LOCK_EX) === false) { throw new CMSException('Không ghi được cấu hình bảo vệ thư viện ảnh.', 503, 'upload_storage'); }
        $filename = bin2hex(random_bytes(16)) . '.' . $types[$info['mime']];
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) { throw new CMSException('Không lưu được ảnh tải lên.', 503, 'upload_storage'); }
        @chmod($dir . '/' . $filename, 0644);
        try { $record = $store->save('media', ['name' => basename((string) $file['name']), 'url' => '/uploads/cms/' . $filename, 'width' => $info[0], 'height' => $info[1], 'size' => (int) $file['size']], $user['id']); }
        catch (Throwable $error) { @unlink($dir . '/' . $filename); throw $error; }
        cms_reply(['record' => $record], 201);
    }
    if (in_array($action, ['import', 'publish', 'preview'], true)) {
        if (!is_file(__DIR__ . '/lib/Publisher.php')) { throw new CMSException('Chưa cài bộ xuất bản CMS.', 503, 'publisher_missing'); }
        require_once __DIR__ . '/lib/Publisher.php';
        if ($action === 'import') { $auth->requireUser(true); cms_input(); $result = cms_import_existing($store); $store->audit('site.import', $user['id']); cms_reply(is_array($result) ? $result : ['ok' => true]); }
        if ($action === 'publish') { $auth->requireUser(true); $data = cms_input(); $result = cms_publish($store, $data); $store->audit('site.publish', $user['id'], ['id' => $data['id'] ?? null, 'collection' => $data['collection'] ?? null]); cms_reply(is_array($result) ? $result : ['ok' => true]); }
        $collection = (string) ($_GET['collection'] ?? 'posts'); $id = (string) ($_GET['id'] ?? '');
        if (!in_array($collection, ['posts', 'pages'], true) || !$store->get($collection, $id)) { throw new CMSException('Không tìm thấy nội dung xem trước.', 404, 'not_found'); }
        header('Content-Type: text/html; charset=utf-8');
        header("Content-Security-Policy: sandbox allow-same-origin; default-src 'none'; img-src 'self' https: data:; style-src 'self' 'unsafe-inline'; font-src 'self'; frame-ancestors 'self'; base-uri 'none'; form-action 'none'");
        $html = cms_preview($store, ['collection' => $collection, 'id' => $id]);
        if (is_string($html)) { echo $html; }
        exit;
    }
} catch (CMSException $error) { cms_reply(['error' => $error->getMessage(), 'code' => $error->errorCode], $error->status); }
catch (Throwable $error) {
    error_log('Lazi CMS error: ' . $error->getMessage());
    cms_reply(['error' => 'CMS chưa xử lý được yêu cầu. Kiểm tra cấu hình PHP và nhật ký hosting.', 'code' => 'server_error'], 500);
}
