<?php
declare(strict_types=1);
if (!defined('CMS_INTERNAL')) { http_response_code(404); exit; }

final class CMSAuth
{
    private const IDLE_SECONDS = 7200;
    private const SESSION_SECONDS = 43200;
    public function __construct(private readonly CMSStore $store)
    {
        if (PHP_SAPI === 'cli') { return; }
        $sessions = $store->dataDir . '/sessions';
        if (!is_dir($sessions) && !@mkdir($sessions, 0700)) { throw new CMSException('Không tạo được kho phiên đăng nhập.', 503, 'storage'); }
        ini_set('session.use_strict_mode', '1'); ini_set('session.use_only_cookies', '1');
        session_save_path($sessions);
        session_name('LAZICMSSESSID');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/cms/', 'secure' => self::https(), 'httponly' => true, 'samesite' => 'Strict']);
        session_start();
        if (isset($_SESSION['user_id']) && (time() - (int) ($_SESSION['seen_at'] ?? 0) > self::IDLE_SECONDS || time() - (int) ($_SESSION['started_at'] ?? 0) > self::SESSION_SECONDS)) { $this->clear(); }
        if (!isset($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
        $_SESSION['seen_at'] = time();
    }

    public static function https(): bool { return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443'; }
    public static function localHost(): bool { return in_array(strtolower(parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST) ?: ''), ['localhost', '127.0.0.1', '[::1]', '::1'], true); }

    public function csrf(): string { return $_SESSION['csrf'] ?? ''; }
    public function needsSetup(): bool { return (int) $this->store->db->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0; }

    public function user(): ?array
    {
        if (empty($_SESSION['user_id'])) { return null; }
        $stmt = $this->store->db->prepare('SELECT * FROM users WHERE id=? AND active=1'); $stmt->execute([(int) $_SESSION['user_id']]); $user = $stmt->fetch();
        if (!$user || (int) $user['version'] !== (int) ($_SESSION['user_version'] ?? -1)) { $this->clear(); return null; }
        return self::safeUser($user);
    }

    public function requireUser(bool $admin = false): array
    {
        $user = $this->user();
        if (!$user) { throw new CMSException('Vui lòng đăng nhập để tiếp tục.', 401, 'unauthenticated'); }
        if ($admin && $user['role'] !== 'admin') { throw new CMSException('Chức năng này chỉ dành cho quản trị viên.', 403, 'forbidden'); }
        return $user;
    }

    public function verifyMutation(): void
    {
        if (!self::https() && !self::localHost()) { throw new CMSException('Đăng nhập và chỉnh sửa CMS yêu cầu HTTPS.', 403, 'https_required'); }
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($origin !== '') {
            $expected = (self::https() ? 'https://' : 'http://') . $host;
            if (strtolower(rtrim($origin, '/')) !== $expected) { throw new CMSException('Yêu cầu không cùng nguồn website.', 403, 'origin'); }
        } elseif (!empty($_SERVER['HTTP_REFERER'])) {
            $parts = parse_url((string) $_SERVER['HTTP_REFERER']);
            $refHost = strtolower(($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : ''));
            if ($refHost !== $host || ($parts['scheme'] ?? '') !== (self::https() ? 'https' : 'http')) { throw new CMSException('Yêu cầu không cùng nguồn website.', 403, 'origin'); }
        }
        $token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($token === '' || !hash_equals($this->csrf(), $token)) { throw new CMSException('Phiên làm việc đã thay đổi. Tải lại trang và thử lại.', 403, 'csrf'); }
    }

    private function identity(array $input, bool $passwordRequired = true): array
    {
        $name = CMSValidator::text($input['name'] ?? '', 200); $email = strtolower(CMSValidator::text($input['email'] ?? '', 254));
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { throw new CMSException('Nhập tên và địa chỉ email hợp lệ.'); }
        $password = (string) ($input['password'] ?? '');
        if (($passwordRequired || $password !== '') && (strlen($password) < 12 || strlen($password) > 72)) { throw new CMSException('Mật khẩu cần từ 12 đến 72 ký tự.'); }
        return [$name, $email, $password];
    }

    public function setup(array $input): array
    {
        $this->store->limit('setup:' . ($_SERVER['REMOTE_ADDR'] ?? ''), 10, 1800);
        if (!$this->needsSetup()) { throw new CMSException('Đã có tài khoản quản trị. Chỉ quản trị viên đang đăng nhập được tạo tài khoản.', 409, 'already_setup'); }
        $expected = getenv('CMS_SETUP_TOKEN') ?: ($this->store->config['setupToken'] ?? '');
        if (!is_string($expected) || strlen($expected) < 32) { throw new CMSException('Chưa cấu hình khóa khởi tạo. Chạy php cms/console.php setup-token trong Terminal hosting.', 503, 'setup_not_ready'); }
        $supplied = CMSValidator::text($input['setupToken'] ?? '', 256);
        if ($supplied === '' || !hash_equals($expected, $supplied)) { throw new CMSException('Khóa khởi tạo không hợp lệ.', 403, 'setup_token'); }
        [$name, $email, $password] = $this->identity($input);
        $this->store->db->exec('BEGIN IMMEDIATE');
        try {
            if (!$this->needsSetup()) { throw new CMSException('Đã có tài khoản quản trị.', 409, 'already_setup'); }
            $stmt = $this->store->db->prepare('INSERT INTO users(name,email,password_hash,role,active,created_at) VALUES(?,?,?,?,1,?)');
            $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), 'admin', CMSStore::now()]);
            $id = (int) $this->store->db->lastInsertId();
            $this->store->audit('user.setup', $id);
            // This public, non-secret marker makes static deploy scripts fail closed.
            $marker = $this->store->siteRoot . '/cms/deployment-state.json';
            $temporary = $marker . '.' . bin2hex(random_bytes(6)) . '.tmp';
            if (@file_put_contents($temporary, '{"installed":true}' . "\n", LOCK_EX) === false || !@rename($temporary, $marker)) {
                @unlink($temporary);
                throw new CMSException('Không ghi được dấu bảo vệ triển khai CMS. Kiểm tra quyền ghi thư mục cms.', 503, 'deployment_marker');
            }
            @chmod($marker, 0644);
            $this->store->db->exec('COMMIT');
        } catch (Throwable $error) { if ($this->store->db->inTransaction()) { $this->store->db->rollBack(); } throw $error; }
        $this->establish($id, 1);
        return $this->requireUser();
    }

    public function login(array $input): array
    {
        $email = strtolower(CMSValidator::text($input['email'] ?? '', 254));
        $password = (string) ($input['password'] ?? '');
        $this->store->limit('login-ip:' . ($_SERVER['REMOTE_ADDR'] ?? ''), 30, 900);
        $this->store->limit('login-account:' . $email, 10, 900);
        $stmt = $this->store->db->prepare('SELECT * FROM users WHERE email=?'); $stmt->execute([$email]); $user = $stmt->fetch();
        // Always perform a password verification to reduce account enumeration timing.
        $valid = password_verify(substr($password, 0, 4096), $user['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi');
        if (!$user || !$valid || !(bool) $user['active'] || strlen($password) > 72) {
            $this->store->audit('login.failure', null, ['account' => hash('sha256', $email)]);
            throw new CMSException('Email hoặc mật khẩu không đúng.', 401, 'invalid_credentials');
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) { $this->store->db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]); }
        $this->establish((int) $user['id'], (int) $user['version']);
        $this->store->audit('login.success', (int) $user['id']);
        return self::safeUser($user);
    }

    private function establish(int $id, int $version): void
    {
        session_regenerate_id(true);
        $_SESSION = ['user_id' => $id, 'user_version' => $version, 'started_at' => time(), 'seen_at' => time(), 'csrf' => bin2hex(random_bytes(32))];
    }

    private function clear(): void
    {
        $_SESSION = ['csrf' => bin2hex(random_bytes(32))];
        if (session_status() === PHP_SESSION_ACTIVE) { session_regenerate_id(true); }
    }

    public function logout(): void { $user = $this->user(); $this->store->audit('logout', $user['id'] ?? null); $this->clear(); }

    public static function safeUser(array $user): array
    {
        return ['id' => (int) $user['id'], 'name' => $user['name'], 'email' => $user['email'], 'role' => $user['role'], 'active' => (bool) $user['active'], 'version' => (int) $user['version'], 'createdAt' => $user['created_at'] ?? ''];
    }

    public function users(): array { $this->requireUser(true); return array_map([self::class, 'safeUser'], $this->store->db->query('SELECT * FROM users ORDER BY id')->fetchAll()); }

    public function saveUser(array $record): array
    {
        $actor = $this->requireUser(true); $id = isset($record['id']) ? (int) $record['id'] : 0;
        [$name, $email, $password] = $this->identity($record, $id === 0);
        $role = $record['role'] ?? 'editor';
        if (!in_array($role, ['admin', 'editor'], true)) { throw new CMSException('Vai trò không hợp lệ.'); }
        $active = (int) (bool) ($record['active'] ?? true);
        $this->store->db->exec('BEGIN IMMEDIATE');
        try {
            if ($id > 0) {
                $stmt = $this->store->db->prepare('SELECT * FROM users WHERE id=?'); $stmt->execute([$id]); $old = $stmt->fetch();
                if (!$old) { throw new CMSException('Không tìm thấy tài khoản.', 404, 'not_found'); }
                if (!isset($record['version']) || (int) $record['version'] !== (int) $old['version']) { throw new CMSException('Tài khoản đã thay đổi. Tải lại trước khi sửa.', 409, 'version_conflict'); }
                if ($old['role'] === 'admin' && (bool) $old['active'] && (!$active || $role !== 'admin') && (int) $this->store->db->query("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1")->fetchColumn() <= 1) { throw new CMSException('Phải giữ ít nhất một quản trị viên đang hoạt động.', 409, 'last_admin'); }
                $hash = $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : $old['password_hash'];
                $stmt = $this->store->db->prepare('UPDATE users SET name=?,email=?,password_hash=?,role=?,active=?,version=version+1 WHERE id=?'); $stmt->execute([$name, $email, $hash, $role, $active, $id]);
            } else {
                $stmt = $this->store->db->prepare('INSERT INTO users(name,email,password_hash,role,active,created_at) VALUES(?,?,?,?,?,?)'); $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $active, CMSStore::now()]); $id = (int) $this->store->db->lastInsertId();
            }
            $stmt = $this->store->db->prepare('SELECT * FROM users WHERE id=?'); $stmt->execute([$id]); $saved = $stmt->fetch();
            $this->store->audit('user.save', $actor['id'], ['target' => $id, 'role' => $role, 'active' => $active]);
            $this->store->db->exec('COMMIT');
            if ($actor['id'] === $id && $active) { $_SESSION['user_version'] = (int) $saved['version']; }
            return self::safeUser($saved);
        } catch (PDOException $error) {
            if ($this->store->db->inTransaction()) { $this->store->db->rollBack(); }
            if (str_contains($error->getMessage(), 'UNIQUE')) { throw new CMSException('Email này đã có tài khoản.', 409, 'duplicate_email'); }
            throw $error;
        } catch (Throwable $error) { if ($this->store->db->inTransaction()) { $this->store->db->rollBack(); } throw $error; }
    }

    public function password(array $input): void
    {
        $user = $this->requireUser();
        $this->store->limit('password:' . $user['id'], 10, 900);
        $stmt = $this->store->db->prepare('SELECT password_hash FROM users WHERE id=?'); $stmt->execute([$user['id']]);
        if (!password_verify((string) ($input['currentPassword'] ?? ''), (string) $stmt->fetchColumn())) { throw new CMSException('Mật khẩu hiện tại không đúng.', 403, 'current_password'); }
        $new = (string) ($input['newPassword'] ?? '');
        $this->identity(['name' => $user['name'], 'email' => $user['email'], 'password' => $new]);
        $this->store->db->prepare('UPDATE users SET password_hash=?,version=version+1 WHERE id=?')->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
        $this->establish($user['id'], $user['version'] + 1);
        $this->store->audit('user.password', $user['id']);
    }
}
