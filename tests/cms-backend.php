<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Isolated, disposable PHP integration tests. No website files are rewritten.
define('CMS_INTERNAL', true);
require dirname(__DIR__) . '/cms/lib/Store.php';
require dirname(__DIR__) . '/cms/lib/Auth.php';
$testRoot = sys_get_temp_dir() . '/lazi-cms-test-' . bin2hex(random_bytes(6));
mkdir($testRoot . '/public/cms', 0700, true);
mkdir($testRoot . '/sessions', 0700);
session_save_path($testRoot . '/sessions'); session_start();
$_SERVER['HTTPS'] = 'on'; $_SERVER['HTTP_HOST'] = 'sub.lazivoyage.com'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SESSION = ['csrf' => str_repeat('a', 64)];
$passed = [];
function check(bool $value, string $name): void { global $passed; if (!$value) { throw new RuntimeException('FAIL: ' . $name); } $passed[] = $name; }
function failure(callable $fn, string $code, string $name): void { try { $fn(); } catch (CMSException $e) { check($e->errorCode === $code, $name); return; } throw new RuntimeException('FAIL (expected rejection): ' . $name); }
function erase(string $dir): void { if (!is_dir($dir)) { return; } foreach (new DirectoryIterator($dir) as $f) { if ($f->isDot()) { continue; } $f->isDir() && !$f->isLink() ? erase($f->getPathname()) : unlink($f->getPathname()); } rmdir($dir); }
try {
    $store = new CMSStore($testRoot . '/public', ['dataDir' => $testRoot . '/private', 'setupToken' => str_repeat('t', 64)]);
    check(is_file($testRoot . '/private/cms.sqlite'), 'Private SQLite database');
    check(file_get_contents($testRoot . '/private/.htaccess') === "Require all denied\n" && str_contains(file_get_contents($testRoot . '/private/index.php'), 'http_response_code(404)'), 'Private storage web-access guards');
    failure(fn() => new CMSStore($testRoot . '/public', ['dataDir' => $testRoot . '/public/exposed']), 'configuration', 'Reject public data storage');
    $auth = new CMSAuth($store);
    check($auth->needsSetup(), 'Setup required before first account');
    $_SERVER['HTTP_X_CSRF_TOKEN'] = 'invalid'; failure(fn() => $auth->verifyMutation(), 'csrf', 'Reject missing/invalid CSRF');
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $_SESSION['csrf']; $_SERVER['HTTP_ORIGIN'] = 'https://attacker.example'; failure(fn() => $auth->verifyMutation(), 'origin', 'Reject cross-origin mutation');
    $_SERVER['HTTP_ORIGIN'] = 'https://sub.lazivoyage.com'; $auth->verifyMutation(); check(true, 'Accept same-origin CSRF mutation');
    failure(fn() => $auth->setup(['setupToken' => 'wrong', 'name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'testing-password-12']), 'setup_token', 'Reject invalid setup token');
    $admin = $auth->setup(['setupToken' => str_repeat('t', 64), 'name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'testing-password-12']);
    check($admin['role'] === 'admin' && !$auth->needsSetup(), 'Create first admin only with setup secret');
    check(json_decode(file_get_contents($testRoot . '/public/cms/deployment-state.json'), true)['installed'] === true, 'Deployment guard marker created');
    check(!str_contains(CMSStore::json($admin), 'password'), 'No password hashes in user response');
    failure(fn() => $auth->setup(['setupToken' => str_repeat('t', 64)]), 'already_setup', 'Setup permanently closed after first account');
    $post = $store->save('posts', ['title' => 'Hà Giang', 'slug' => 'ha-giang', 'content' => '<p>Safe <strong>format</strong></p><script>alert(1)</script><img src="/assets/test.webp" onerror="alert(2)"><a href="javascript:alert(3)">Bad</a><svg><script>alert(4)</script></svg>', 'aio' => ['summary' => 'A clear answer', 'sources' => [['title' => 'Source', 'url' => 'https://example.com/source']], 'faq' => [['question' => 'When?', 'answer' => 'Autumn']]]], $admin['id']);
    check(!str_contains($post['content'], '<script') && !str_contains($post['content'], 'onerror') && !str_contains($post['content'], 'javascript:'), 'DOM sanitizer removes executable content');
    check(str_contains($post['content'], '<strong>format</strong>'), 'DOM sanitizer preserves article formatting');
    $updated = $store->save('posts', [...$post, 'title' => 'Updated'], $admin['id']);
    check($updated['version'] === 2 && count($store->revisions('posts', $post['id'])) === 1, 'Save creates optimistic version and revision');
    failure(fn() => $store->save('posts', [...$post, 'title' => 'Stale write'], $admin['id']), 'version_conflict', 'Reject stale edit');
    failure(fn() => $store->save('pages', ['title' => 'Duplicate', 'slug' => 'ha-giang']), 'duplicate_slug', 'Reject cross-type duplicate slug');
    failure(fn() => $store->save('posts', ['title' => 'System path', 'slug' => 'cms/overwrite']), 'validation', 'Reject reserved system slug');
    failure(fn() => $store->save('snippets', ['name' => 'Invalid URL', 'urls' => ['javascript:alert(1)']]), 'validation', 'Reject active URL protocols');
    $settings = $store->settings();
    failure(fn() => $store->save('settings', [...$settings, 'redirects' => [['from' => '/a/', 'to' => '/b/'], ['from' => '/b/', 'to' => '/a/']]]), 'validation', 'Reject redirect cycle');
    $settings = $store->save('settings', [...$settings, 'redirects' => [['from' => '/old/', 'to' => '/new/', 'code' => 301]]], $admin['id']);
    check($settings['redirects'][0]['code'] === 301, 'Persist SEO redirects');
    $settings['header']['menu'] = [];
    $store->save('settings', $settings, $admin['id']);
    check($store->settings()['header']['menu'] === [], 'Empty header menu does not resurrect defaults');
    $production = $store->settings();
    $production['siteUrl'] = 'https://lazivoyage.com'; $production['publishUrl'] = 'https://lazivoyage.com'; $production['environment'] = 'production';
    $store->save('settings', $production);
    check($store->settings()['environment'] === 'production' && $store->settings()['publishUrl'] === 'https://lazivoyage.com' && !is_file($testRoot . '/public/index.html'), 'Production configuration persists without writing public HTML');
    $page = $store->save('pages', ['title' => 'Landing', 'slug' => 'landing', 'blocks' => [['type' => 'contact', 'url' => 'mailto:contact@example.com', 'buttonText' => 'Email Lazi', 'imageAlt' => 'Cover'], ['type' => 'faq', 'items' => [['question' => 'When?', 'answer' => 'Today']]]]]);
    check($page['blocks'][0]['url'] === 'mailto:contact@example.com' && $page['blocks'][0]['buttonText'] === 'Email Lazi' && $page['blocks'][1]['items'][0]['answer'] === 'Today', 'Frontend block aliases, contact links and FAQ persist');
    $editor = $auth->saveUser(['name' => 'Writer', 'email' => 'editor@example.com', 'password' => 'writer-password-12', 'role' => 'editor', 'active' => true]);
    failure(fn() => $auth->saveUser([...$admin, 'active' => false]), 'last_admin', 'Cannot disable last active admin');
    // A rejected transaction must not leave SQLite locked or open.
    $store->save('tags', ['name' => 'Vietnam', 'slug' => 'vietnam']); check(true, 'Rejected mutation rolls back cleanly');
    $auth->logout();
    failure(fn() => $auth->login(['email' => 'admin@example.com', 'password' => 'wrong-password']), 'invalid_credentials', 'Reject wrong password');
    $auth->login(['email' => 'editor@example.com', 'password' => 'writer-password-12']);
    failure(fn() => $auth->saveUser(['name' => 'Rogue', 'email' => 'rogue@example.com', 'password' => 'rogue-password-12', 'role' => 'admin']), 'forbidden', 'Editor cannot create admins');
    failure(fn() => $auth->users(), 'forbidden', 'Editor cannot list accounts');
    $auth->logout(); $auth->login(['email' => 'admin@example.com', 'password' => 'testing-password-12']);
    $auth->password(['currentPassword' => 'testing-password-12', 'newPassword' => 'replacement-password-12']); $auth->logout();
    failure(fn() => $auth->login(['email' => 'admin@example.com', 'password' => 'testing-password-12']), 'invalid_credentials', 'Password change invalidates old password');
    check($auth->login(['email' => 'admin@example.com', 'password' => 'replacement-password-12'])['role'] === 'admin', 'Replacement password works');
    $revision = $store->revisions('posts', $post['id'])[0]; $restored = $store->restore($revision['id'], $admin['id']);
    check($restored['title'] === 'Hà Giang' && $restored['status'] === 'draft', 'Restoration creates draft without publishing');
    failure(fn() => $store->remove('posts', $post['id'], $admin['id'], 1), 'version_conflict', 'Deletion rejects stale version');
    $store->remove('posts', $post['id'], $admin['id'], $restored['version']);
    check($store->get('posts', $post['id'])['status'] === 'trash', 'Deletion moves article to trash');
    $store->limit('test-limit', 1, 60); failure(fn() => $store->limit('test-limit', 1, 60), 'rate_limit', 'Rate limiter blocks excess attempts');
    fwrite(STDOUT, 'PASS ' . count($passed) . ' backend checks' . "\n" . implode("\n", $passed) . "\n");
} finally { session_write_close(); unset($auth, $store); gc_collect_cycles(); erase($testRoot); }
