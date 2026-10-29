<?php
declare(strict_types=1);

if (!defined('CMS_INTERNAL')) { http_response_code(404); exit; }

final class CMSException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422, public readonly string $errorCode = 'validation')
    {
        parent::__construct($message);
    }
}

/** Persistent data is deliberately outside the deployed document root. */
final class CMSStore
{
    public readonly string $siteRoot;
    public readonly string $dataDir;
    public PDO $db;
    public array $config;

    public function __construct(?string $siteRoot = null, ?array $config = null)
    {
        $this->siteRoot = realpath($siteRoot ?? dirname(__DIR__, 2)) ?: throw new CMSException('Không tìm thấy thư mục website.', 503, 'configuration');
        $local = [];
        if ($config === null && is_file(__DIR__ . '/../config.local.php')) {
            $local = require __DIR__ . '/../config.local.php';
            if (!is_array($local)) { throw new CMSException('Cấu hình CMS không hợp lệ.', 503, 'configuration'); }
        }
        $this->config = $config ?? $local;
        $requested = getenv('CMS_DATA_DIR') ?: ($this->config['dataDir'] ?? dirname($this->siteRoot) . '/.lazivoyage-cms-' . substr(hash('sha256', $this->siteRoot), 0, 12));
        if (!is_string($requested) || $requested === '' || $requested[0] !== '/' || preg_match('~(?:^|/)\.\.(?:/|$)~', $requested)) {
            throw new CMSException('CMS_DATA_DIR phải là đường dẫn tuyệt đối bên ngoài thư mục website.', 503, 'configuration');
        }
        if ($requested === $this->siteRoot || str_starts_with(rtrim($requested, '/') . '/', $this->siteRoot . '/')) {
            throw new CMSException('Kho dữ liệu CMS không được nằm trong thư mục website công khai, kể cả qua symlink.', 503, 'configuration');
        }
        if (!is_dir($requested) && !@mkdir($requested, 0700, true)) {
            throw new CMSException('Chưa tạo được kho dữ liệu riêng. Cấu hình CMS_DATA_DIR ngoài document root và cấp quyền ghi.', 503, 'storage');
        }
        $actual = realpath($requested);
        if (!$actual || $actual === $this->siteRoot || str_starts_with($actual . '/', $this->siteRoot . '/')) {
            throw new CMSException('Kho dữ liệu CMS không được nằm trong thư mục website công khai.', 503, 'configuration');
        }
        if (!is_writable($actual)) { throw new CMSException('Kho dữ liệu riêng chưa có quyền ghi.', 503, 'storage'); }
        $this->dataDir = $actual;
        @chmod($actual, 0700);
        // Defense in depth when a hosting account has overlapping document roots.
        // The configured storage must still be outside every public document root.
        $guards = ['.htaccess' => "Require all denied\n", 'index.php' => "<?php\nhttp_response_code(404);\nexit;\n"];
        foreach ($guards as $name => $content) {
            $path = $actual . '/' . $name;
            if (is_link($path)) { throw new CMSException('File bảo vệ kho dữ liệu không được là symbolic link.', 503, 'storage'); }
            if (!is_file($path) || file_get_contents($path) !== $content) {
                $temporary = $actual . '/.guard-' . bin2hex(random_bytes(6)) . '.php';
                if (@file_put_contents($temporary, $content, LOCK_EX) === false || !@chmod($temporary, 0600) || !@rename($temporary, $path)) {
                    @unlink($temporary);
                    throw new CMSException('Không ghi được cấu hình bảo vệ kho dữ liệu riêng.', 503, 'storage');
                }
            }
            @chmod($path, 0600);
        }
        if (!extension_loaded('pdo_sqlite')) { throw new CMSException('Hosting cần bật PHP PDO SQLite.', 503, 'sqlite_missing'); }
        $this->db = new PDO('sqlite:' . $actual . '/cms.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        @chmod($actual . '/cms.sqlite', 0600);
        $this->db->exec('PRAGMA busy_timeout=10000; PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;');
        $this->db->exec("CREATE TABLE IF NOT EXISTS documents (collection TEXT NOT NULL,id TEXT NOT NULL,data TEXT NOT NULL,version INTEGER NOT NULL,updated_at TEXT NOT NULL,PRIMARY KEY(collection,id));
            CREATE TABLE IF NOT EXISTS revisions (id INTEGER PRIMARY KEY AUTOINCREMENT,collection TEXT NOT NULL,document_id TEXT NOT NULL,data TEXT NOT NULL,actor_id INTEGER,created_at TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL,email TEXT NOT NULL UNIQUE,password_hash TEXT NOT NULL,role TEXT NOT NULL CHECK(role IN ('admin','editor')),active INTEGER NOT NULL DEFAULT 1,version INTEGER NOT NULL DEFAULT 1,created_at TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS audit (id INTEGER PRIMARY KEY AUTOINCREMENT,event TEXT NOT NULL,actor_id INTEGER,detail TEXT NOT NULL,created_at TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS limits (key TEXT PRIMARY KEY,hits INTEGER NOT NULL,expires INTEGER NOT NULL);");
    }

    public static function now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }
    public static function json(mixed $data): string { return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
    public static function collections(): array { return ['posts', 'pages', 'categories', 'tags', 'media', 'snippets', 'settings']; }

    private function collection(string $name): void
    {
        if (!in_array($name, self::collections(), true)) { throw new CMSException('Loại dữ liệu không được hỗ trợ.'); }
    }

    public function all(string $collection): array
    {
        $this->collection($collection);
        $stmt = $this->db->prepare('SELECT data,version FROM documents WHERE collection=? ORDER BY updated_at DESC,id');
        $stmt->execute([$collection]);
        return array_map(static function (array $row): array { $item = json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR); $item['version'] = (int) $row['version']; return $item; }, $stmt->fetchAll());
    }

    public function get(string $collection, string $id): ?array
    {
        $this->collection($collection);
        $stmt = $this->db->prepare('SELECT data,version FROM documents WHERE collection=? AND id=?');
        $stmt->execute([$collection, $id]);
        $row = $stmt->fetch();
        if (!$row) { return null; }
        $data = json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR);
        $data['version'] = (int) $row['version'];
        return $data;
    }

    public function save(string $collection, array $record, ?int $actorId = null, bool $internal = false): array
    {
        $this->collection($collection);
        $hasHomeFaq = array_key_exists('homeFaq', $record);
        $providedSeo = is_array($record['seo'] ?? null) ? $record['seo'] : [];
        $record = CMSValidator::record($collection, $record);
        $id = $collection === 'settings' ? 'site' : ($record['id'] ?? bin2hex(random_bytes(12)));
        if (!preg_match('/^[A-Za-z0-9_-]{1,96}$/D', (string) $id)) { throw new CMSException('ID không hợp lệ.'); }
        $started = !$this->db->inTransaction();
        if ($started) { $this->db->exec('BEGIN IMMEDIATE'); }
        try {
            $old = $this->get($collection, $id);
            if (!$internal && $old && (!isset($record['version']) || (int) $record['version'] !== $old['version'])) {
                throw new CMSException('Dữ liệu đã thay đổi ở phiên khác. Tải lại trước khi lưu để tránh ghi đè.', 409, 'version_conflict');
            }
            if ($collection === 'settings' && $old) {
                if (!$hasHomeFaq && isset($old['homeFaq'])) { $record['homeFaq'] = $old['homeFaq']; }
                foreach (['homeTitle', 'longDescription', 'focusKeywords'] as $field) {
                    if (!array_key_exists($field, $providedSeo) && array_key_exists($field, $old['seo'] ?? [])) { $record['seo'][$field] = $old['seo'][$field]; }
                }
            }
            if (in_array($collection, ['posts', 'pages', 'categories', 'tags'], true)) {
                $peers = in_array($collection, ['posts', 'pages'], true) ? ['posts', 'pages'] : [$collection];
                foreach ($peers as $peer) {
                    foreach ($this->all($peer) as $item) {
                        if (($peer !== $collection || $item['id'] !== $id) && $item['slug'] === $record['slug']) { throw new CMSException('Đường dẫn này đang được nội dung khác sử dụng.', 409, 'duplicate_slug'); }
                    }
                }
            }
            if ($old) { $this->revision($collection, $id, $old, $actorId); }
            $record['id'] = $id;
            $record['version'] = ($old['version'] ?? 0) + 1;
            $record['createdAt'] = $old['createdAt'] ?? $record['createdAt'] ?? self::now();
            $record['updatedAt'] = self::now();
            $stmt = $this->db->prepare('INSERT INTO documents(collection,id,data,version,updated_at) VALUES(?,?,?,?,?) ON CONFLICT(collection,id) DO UPDATE SET data=excluded.data,version=excluded.version,updated_at=excluded.updated_at');
            $stmt->execute([$collection, $id, self::json($record), $record['version'], $record['updatedAt']]);
            $this->audit('document.save', $actorId, ['collection' => $collection, 'id' => $id, 'version' => $record['version']]);
            if ($started) { $this->db->exec('COMMIT'); }
            return $record;
        } catch (Throwable $error) { if ($started && $this->db->inTransaction()) { $this->db->rollBack(); } throw $error; }
    }

    public function remove(string $collection, string $id, ?int $actorId = null, ?int $version = null): void
    {
        if ($collection === 'settings') { throw new CMSException('Không thể xóa cấu hình website.'); }
        $old = $this->get($collection, $id);
        if (!$old) { throw new CMSException('Không tìm thấy dữ liệu.', 404, 'not_found'); }
        if ($version === null || $old['version'] !== $version) { throw new CMSException('Dữ liệu đã thay đổi. Tải lại trước khi xóa.', 409, 'version_conflict'); }
        if (in_array($collection, ['posts', 'pages'], true)) {
            $old['status'] = 'trash';
            $this->save($collection, $old, $actorId);
            return;
        }
        if (in_array($collection, ['categories', 'tags'], true)) {
            foreach (array_merge($this->all('posts'), $this->all('pages')) as $post) {
                if (($collection === 'categories' && in_array($old['id'], [$post['category'] ?? '', $old['name'] === ($post['category'] ?? '') ? $old['id'] : null], true)) || ($collection === 'tags' && count(array_intersect([$old['id'], $old['name']], $post['tags'] ?? [])) > 0)) {
                    throw new CMSException('Phân loại này đang được sử dụng. Gỡ khỏi nội dung trước khi xóa.', 409, 'in_use');
                }
            }
        }
        $this->db->beginTransaction();
        try {
            $this->revision($collection, $id, $old, $actorId);
            $stmt = $this->db->prepare('DELETE FROM documents WHERE collection=? AND id=? AND version=?');
            $stmt->execute([$collection, $id, $version]);
            if ($stmt->rowCount() !== 1) { throw new CMSException('Dữ liệu đã thay đổi.', 409, 'version_conflict'); }
            $this->audit('document.remove', $actorId, ['collection' => $collection, 'id' => $id]);
            $this->db->commit();
        } catch (Throwable $error) { if ($this->db->inTransaction()) { $this->db->rollBack(); } throw $error; }
    }

    public static function brandSeo(): array
    {
        return [
            'homeTitle' => 'Lazi Voyage — Blog văn hoá Việt & cẩm nang du lịch Việt Nam',
            'description' => 'Blog văn hoá việt Lazi Voyage cung cấp góc nhìn đa dạng về lịch sử, văn hoá Việt Nam qua sự học tập, nghiên cứu không ngừng của người trẻ việt',
            'longDescription' => 'Lazi Voyage là blog văn hoá Việt chia sẻ góc nhìn về lịch sử, ẩm thực, làng nghề, lễ hội và kiến trúc. Cùng những người trẻ yêu Việt Nam khám phá cẩm nang du lịch Việt Nam để mỗi hành trình không chỉ là đi, mà còn hiểu và cảm nhận giá trị Việt.',
            'focusKeywords' => ['blog văn hoá việt', 'cẩm nang du lịch việt nam'],
            'about' => "Lazi Voyage là một blog văn hóa Việt chuyên sâu, nơi những người đam mê khám phá và trân trọng giá trị Việt tìm thấy tiếng nói chung. Sứ mệnh của chúng tôi là mang đến cho độc giả một cái nhìn toàn diện và chân thực nhất về văn hóa Việt Nam qua từng điểm đến.\n\nKhác với các blog du lịch thông thường, Lazivoyage không chỉ cung cấp những cẩm nang du lịch Việt Nam chi tiết, mà còn đi sâu vào từng nét đặc trưng văn hóa: từ ẩm thực địa phương, làng nghề truyền thống, lễ hội độc đáo cho đến kiến trúc cổ kính. Mỗi bài viết là một hành trình kể chuyện, giúp bạn không chỉ đi mà còn hiểu và cảm nhận được tinh hoa của đất nước.\n\nĐược sáng lập bởi những người yêu Việt Nam, chúng tôi tin rằng du lịch là cách tốt nhất để kết nối với văn hóa và con người. Lazivoyage.com là cẩm nang đáng tin cậy dành cho những ai muốn trải nghiệm Việt Nam một cách trọn vẹn, chân thực và sâu sắc.",
        ];
    }

    public function settings(): array
    {
        $brand = self::brandSeo();
        $defaults = ['id' => 'site', 'version' => 0, 'siteName' => 'Lazi Voyage', 'siteUrl' => 'https://lazivoyage.com', 'publishUrl' => 'https://sub.lazivoyage.com', 'environment' => 'test',
            'header' => ['logo' => '/assets/images/logo.webp', 'menu' => [['label' => 'Home', 'url' => '/'], ['label' => 'Blog', 'url' => '/blog/'], ['label' => 'Sử Việt', 'url' => '/su-viet/'], ['label' => 'Contact', 'url' => '/contact/']], 'sticky' => true, 'showSearch' => true],
            'footer' => ['description' => 'Những câu chuyện du lịch, văn hóa và cuộc sống Việt Nam.', 'email' => 'lazi.voyage@gmail.com', 'copyright' => '© Lazi Voyage', 'links' => []],
            'seo' => ['titleTemplate' => '%title% | Lazi Voyage', 'homeTitle' => $brand['homeTitle'], 'description' => $brand['description'], 'longDescription' => $brand['longDescription'], 'focusKeywords' => $brand['focusKeywords'], 'organizationName' => 'Lazi Voyage', 'sameAs' => [], 'googleVerification' => '', 'bingVerification' => ''], 'aio' => ['about' => $brand['about'], 'editorialPolicy' => ''], 'homeFaq' => ['initialized' => false, 'enabled' => true, 'title' => 'Bạn muốn biết?', 'items' => []], 'redirects' => []];
        $stored = $this->get('settings', 'site') ?? [];
        $result = array_replace($defaults, $stored);
        // Lists replace lists: a shorter menu must not resurrect default links.
        foreach (['header', 'footer', 'seo', 'aio', 'homeFaq'] as $group) { $result[$group] = array_replace($defaults[$group], $stored[$group] ?? []); }
        return $result;
    }

    private function revision(string $collection, string $id, array $record, ?int $actorId): void
    {
        $stmt = $this->db->prepare('INSERT INTO revisions(collection,document_id,data,actor_id,created_at) VALUES(?,?,?,?,?)');
        $stmt->execute([$collection, $id, self::json($record), $actorId, self::now()]);
    }

    public function revisions(?string $collection = null, ?string $id = null): array
    {
        $where = []; $params = [];
        if ($collection !== null) { $this->collection($collection); $where[] = 'collection=?'; $params[] = $collection; }
        if ($id !== null) { $where[] = 'document_id=?'; $params[] = $id; }
        $stmt = $this->db->prepare('SELECT id,collection,document_id,actor_id,created_at,data FROM revisions' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT 100');
        $stmt->execute($params);
        return array_map(static function (array $row): array { $data = json_decode($row['data'], true); return ['id' => (int) $row['id'], 'collection' => $row['collection'], 'documentId' => $row['document_id'], 'actorId' => $row['actor_id'], 'createdAt' => $row['created_at'], 'title' => $data['title'] ?? $data['name'] ?? $row['document_id'], 'version' => $data['version'] ?? 0]; }, $stmt->fetchAll());
    }

    public function restore(int $revisionId, int $actorId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM revisions WHERE id=?'); $stmt->execute([$revisionId]); $row = $stmt->fetch();
        if (!$row) { throw new CMSException('Không tìm thấy phiên bản.', 404, 'not_found'); }
        $record = json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR);
        $current = $this->get($row['collection'], $row['document_id']);
        $record['version'] = $current['version'] ?? 0;
        if (in_array($row['collection'], ['posts', 'pages'], true)) { $record['status'] = 'draft'; }
        return $this->save($row['collection'], $record, $actorId, true);
    }

    public function audit(string $event, ?int $actorId, array $detail = []): void
    {
        $stmt = $this->db->prepare('INSERT INTO audit(event,actor_id,detail,created_at) VALUES(?,?,?,?)');
        $stmt->execute([$event, $actorId, self::json($detail), self::now()]);
    }

    public function limit(string $key, int $maximum = 10, int $seconds = 900): void
    {
        $key = hash('sha256', $key); $now = time();
        $stmt = $this->db->prepare('INSERT INTO limits(key,hits,expires) VALUES(?,1,?) ON CONFLICT(key) DO UPDATE SET hits=CASE WHEN expires<=? THEN 1 ELSE hits+1 END,expires=CASE WHEN expires<=? THEN excluded.expires ELSE expires END');
        $stmt->execute([$key, $now + $seconds, $now, $now]);
        $stmt = $this->db->prepare('SELECT hits FROM limits WHERE key=?'); $stmt->execute([$key]);
        if ((int) $stmt->fetchColumn() > $maximum) { throw new CMSException('Thử quá nhiều lần. Vui lòng chờ trước khi thử lại.', 429, 'rate_limit'); }
        if (random_int(1, 100) === 1) { $this->db->prepare('DELETE FROM limits WHERE expires<?')->execute([$now - 86400]); }
    }
}

final class CMSValidator
{
    public static function plainText(mixed $value, int $max = 1000): string
    {
        $value = trim(html_entity_decode(strip_tags(self::text($value, $max * 6)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $length = preg_match_all('/./us', $value);
        if ($length === false || $length > $max) { throw new CMSException('Văn bản vượt quá số ký tự cho phép.'); }
        return $value;
    }

    public static function homeFaq(mixed $value): array
    {
        if (!is_array($value)) { throw new CMSException('Cấu hình FAQ trang chủ không hợp lệ.'); }
        $items = []; $ids = [];
        foreach (self::list($value['items'] ?? [], 100) as $index => $item) {
            if (!is_array($item)) { throw new CMSException('Câu hỏi FAQ không hợp lệ.'); }
            $id = self::text($item['id'] ?? ('faq-' . bin2hex(random_bytes(8))), 96);
            if (!preg_match('/^[A-Za-z0-9_-]{1,96}$/D', $id) || isset($ids[$id])) { throw new CMSException('ID của câu hỏi FAQ không hợp lệ hoặc trùng nhau.'); }
            $ids[$id] = true;
            $question = self::plainText($item['question'] ?? '', 500); $answer = self::plainText($item['answer'] ?? '', 10000);
            if ($question === '' || $answer === '') { throw new CMSException('Mỗi FAQ cần có câu hỏi và câu trả lời.'); }
            $items[] = ['id' => $id, 'question' => $question, 'answer' => $answer, 'enabled' => (bool) ($item['enabled'] ?? true), 'order' => max(0, min(10000, (int) ($item['order'] ?? $index)))];
        }
        return ['initialized' => (bool) ($value['initialized'] ?? false), 'enabled' => (bool) ($value['enabled'] ?? true), 'title' => self::plainText($value['title'] ?? 'Bạn muốn biết?', 300), 'items' => $items];
    }

    public static function mediaUrl(string $url): string
    {
        $upload = preg_match('~^/uploads/cms/[a-f0-9]{32}\.(?:jpg|png|webp|gif)$~D', $url);
        $asset = preg_match('~^/assets/images/(?:[a-z0-9][a-z0-9_.-]*/)*[a-z0-9][a-z0-9_.-]*\.(?:jpe?g|png|webp|gif)$~iD', $url);
        if (!$upload && !$asset) { throw new CMSException('Đường dẫn ảnh phải thuộc assets/images hoặc kho ảnh CMS.'); }
        return $url;
    }

    public static function text(mixed $value, int $max = 1000): string
    {
        if (!is_scalar($value) && $value !== null) { throw new CMSException('Trường văn bản không hợp lệ.'); }
        $value = trim((string) $value);
        if (strlen($value) > $max) { throw new CMSException('Trường văn bản vượt quá độ dài cho phép.'); }
        return $value;
    }

    public static function url(mixed $value, bool $relative = true): string
    {
        $value = self::text($value, 2048);
        if ($value === '') { return ''; }
        if (preg_match('/[\x00-\x20\x7f]/', $value) || str_contains($value, '\\')) { throw new CMSException('URL không hợp lệ.'); }
        if ($relative && str_starts_with($value, '/') && !str_starts_with($value, '//')) { return $value; }
        if (!filter_var($value, FILTER_VALIDATE_URL) || !in_array(strtolower(parse_url($value, PHP_URL_SCHEME) ?: ''), ['http', 'https'], true) || parse_url($value, PHP_URL_USER) !== null) { throw new CMSException('Chỉ chấp nhận URL http/https hoặc đường dẫn nội bộ.'); }
        return rtrim($value, '/');
    }

    private static function list(mixed $value, int $max = 100): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $max) { throw new CMSException('Danh sách không hợp lệ hoặc quá dài.'); }
        return $value;
    }

    private static function links(mixed $value): array
    {
        return array_map(static function ($row): array { if (!is_array($row)) { throw new CMSException('Liên kết không hợp lệ.'); } return ['label' => self::text($row['label'] ?? '', 200), 'url' => self::linkUrl($row['url'] ?? '')]; }, self::list($value, 50));
    }

    public static function linkUrl(mixed $value): string
    {
        $value = self::text($value, 2048);
        if (str_starts_with($value, 'mailto:') && filter_var(substr($value, 7), FILTER_VALIDATE_EMAIL)) { return $value; }
        if (preg_match('/^tel:\+?[0-9().-]{3,40}$/D', $value) || preg_match('/^#[A-Za-z][A-Za-z0-9_-]{0,150}$/D', $value)) { return $value; }
        return self::url($value);
    }

    private static function seo(mixed $value): array
    {
        if (!is_array($value)) { throw new CMSException('Cấu hình SEO không hợp lệ.'); }
        return ['title' => self::text($value['title'] ?? '', 500), 'description' => self::text($value['description'] ?? '', 3000), 'canonical' => self::url($value['canonical'] ?? '', false), 'noindex' => (bool) ($value['noindex'] ?? false), 'ogImage' => self::url($value['ogImage'] ?? '')];
    }

    public static function record(string $collection, array $value): array
    {
        $out = [];
        foreach (['id', 'createdAt', 'updatedAt'] as $field) { if (isset($value[$field])) { $out[$field] = self::text($value[$field], 100); } }
        if (isset($value['version'])) { $out['version'] = max(0, (int) $value['version']); }
        if (in_array($collection, ['posts', 'pages', 'categories', 'tags'], true)) {
            $out['slug'] = trim(self::text($value['slug'] ?? '', 240), '/');
            if (($out['slug'] === '' && $collection !== 'pages') || ($out['slug'] !== '' && !preg_match('~^[a-z0-9][a-z0-9-]*(?:/[a-z0-9][a-z0-9-]*)*$~D', $out['slug']))) { throw new CMSException('Slug chỉ gồm chữ thường không dấu, số, dấu gạch ngang và dấu /.'); }
            if (in_array($collection, ['posts', 'pages'], true) && preg_match('~^(?:admin|cms|uploads|assets|data|category|tag)(?:/|$)~', $out['slug'])) { throw new CMSException('Đường dẫn này dành cho hệ thống.'); }
            $out['seo'] = self::seo($value['seo'] ?? []);
        }
        if (in_array($collection, ['posts', 'pages'], true)) {
            $out['type'] = $collection === 'posts' ? 'post' : 'page';
            $out['title'] = self::text($value['title'] ?? '', 500);
            if ($out['title'] === '') { throw new CMSException('Nội dung cần có tiêu đề.'); }
            $out['excerpt'] = self::text($value['excerpt'] ?? '', 5000);
            $out['content'] = self::cleanHtml(self::text($value['content'] ?? '', 2000000));
            $out['cover'] = self::url($value['cover'] ?? ''); $out['coverAlt'] = self::text($value['coverAlt'] ?? '', 1000);
            $out['category'] = self::text($value['category'] ?? '', 200);
            $out['tags'] = array_values(array_unique(array_map(static fn($t) => self::text($t, 200), self::list($value['tags'] ?? [], 60))));
            $out['author'] = self::text($value['author'] ?? 'Lazi Voyage', 200);
            $out['publishedAt'] = self::text($value['publishedAt'] ?? '', 40);
            if ($out['publishedAt'] !== '' && strtotime($out['publishedAt']) === false) { throw new CMSException('Ngày đăng không hợp lệ.'); }
            $out['status'] = in_array($value['status'] ?? '', ['draft', 'published', 'trash'], true) ? $value['status'] : 'draft';
            $out['featured'] = (bool) ($value['featured'] ?? false);
            $aio = is_array($value['aio'] ?? null) ? $value['aio'] : [];
            $out['aio'] = ['summary' => self::text($aio['summary'] ?? '', 6000), 'reviewedAt' => self::text($aio['reviewedAt'] ?? '', 40),
                'sources' => array_map(static function ($source): array { if (is_string($source)) { return ['title' => $source, 'url' => self::url($source, false)]; } if (!is_array($source)) { throw new CMSException('Nguồn tham khảo không hợp lệ.'); } return ['title' => self::text($source['title'] ?? '', 500), 'url' => self::url($source['url'] ?? '', false)]; }, self::list($aio['sources'] ?? [], 100)),
                'faq' => array_map(static function ($faq): array { if (!is_array($faq)) { throw new CMSException('FAQ không hợp lệ.'); } return ['question' => self::text($faq['question'] ?? '', 1000), 'answer' => self::text($faq['answer'] ?? '', 10000)]; }, self::list($aio['faq'] ?? [], 50))];
            $out['blocks'] = array_map(static function ($block): array {
                if (!is_array($block)) { throw new CMSException('Khối nội dung không hợp lệ.'); }
                $type = self::text($block['type'] ?? 'text', 30);
                if (!in_array($type, ['hero', 'text', 'image', 'gallery', 'posts', 'faq', 'cta', 'contact'], true)) { throw new CMSException('Loại khối nội dung không được hỗ trợ.'); }
                $imageAlt = self::text($block['imageAlt'] ?? $block['alt'] ?? '', 1000);
                $buttonText = self::text($block['buttonText'] ?? $block['label'] ?? '', 200);
                $email = self::text($block['email'] ?? '', 254);
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { throw new CMSException('Email liên hệ trong khối không hợp lệ.'); }
                return ['id' => self::text($block['id'] ?? '', 100), 'type' => $type, 'title' => self::text($block['title'] ?? '', 500), 'content' => self::cleanHtml(self::text($block['content'] ?? '', 200000)), 'image' => self::url($block['image'] ?? ''), 'alt' => $imageAlt, 'imageAlt' => $imageAlt, 'url' => self::linkUrl($block['url'] ?? ''), 'label' => $buttonText, 'buttonText' => $buttonText, 'email' => $email, 'hidden' => (bool) ($block['hidden'] ?? false), 'tag' => self::text($block['tag'] ?? '', 200), 'images' => array_map(static fn($img) => self::url($img), self::list($block['images'] ?? [], 100)), 'items' => array_map(static function ($item): array { if (!is_array($item)) { throw new CMSException('Mục FAQ không hợp lệ.'); } return ['question' => self::text($item['question'] ?? '', 1000), 'answer' => self::text($item['answer'] ?? '', 10000)]; }, self::list($block['items'] ?? [], 50))];
            }, self::list($value['blocks'] ?? [], 100));
            $out['hideHeader'] = (bool) ($value['hideHeader'] ?? false); $out['hideFooter'] = (bool) ($value['hideFooter'] ?? false);
        } elseif (in_array($collection, ['categories', 'tags'], true)) {
            $out['name'] = self::text($value['name'] ?? '', 200);
            if ($out['name'] === '') { throw new CMSException('Phân loại cần có tên.'); }
            $out['description'] = self::text($value['description'] ?? '', 5000);
            $out['parent'] = self::text($value['parent'] ?? '', 100); $out['cover'] = self::url($value['cover'] ?? '');
        } elseif ($collection === 'snippets') {
            $out['name'] = self::text($value['name'] ?? '', 200);
            if ($out['name'] === '') { throw new CMSException('Đoạn mã cần có tên.'); }
            $out['position'] = in_array($value['position'] ?? '', ['head', 'body_start', 'body_end'], true) ? $value['position'] : 'head';
            $out['scope'] = in_array($value['scope'] ?? '', ['all', 'posts', 'pages', 'urls'], true) ? $value['scope'] : 'all';
            $out['urls'] = array_map(static fn($url) => self::url($url), self::list($value['urls'] ?? [], 100));
            $out['code'] = self::text($value['code'] ?? '', 200000); $out['enabled'] = (bool) ($value['enabled'] ?? false);
            $out['order'] = max(0, min(10000, (int) ($value['order'] ?? 0)));
            $out['allowTest'] = (bool) ($value['allowTest'] ?? false);
        } elseif ($collection === 'media') {
            $out['name'] = self::text($value['name'] ?? '', 255); $out['url'] = self::url($value['url'] ?? '');
            self::mediaUrl($out['url']);
            foreach (['alt', 'caption', 'source'] as $field) { $out[$field] = self::text($value[$field] ?? '', 2000); }
            $out['width'] = max(0, (int) ($value['width'] ?? 0)); $out['height'] = max(0, (int) ($value['height'] ?? 0)); $out['size'] = max(0, (int) ($value['size'] ?? 0));
        } elseif ($collection === 'settings') {
            foreach (['siteName', 'environment'] as $field) { $out[$field] = self::text($value[$field] ?? ($field === 'environment' ? 'test' : 'Lazi Voyage'), 300); }
            if (!in_array($out['environment'], ['test', 'production'], true)) { throw new CMSException('Môi trường không hợp lệ.'); }
            $out['siteUrl'] = self::url($value['siteUrl'] ?? 'https://lazivoyage.com', false); $out['publishUrl'] = self::url($value['publishUrl'] ?? 'https://sub.lazivoyage.com', false);
            foreach (['siteUrl', 'publishUrl'] as $field) { if ($out[$field] === '' || (parse_url($out[$field], PHP_URL_PATH) ?: '') !== '') { throw new CMSException('Domain website phải là URL gốc không có đường dẫn.'); } }
            $header = is_array($value['header'] ?? null) ? $value['header'] : []; $footer = is_array($value['footer'] ?? null) ? $value['footer'] : [];
            $out['header'] = ['logo' => self::url($header['logo'] ?? '/assets/images/logo.webp'), 'menu' => self::links($header['menu'] ?? []), 'sticky' => (bool) ($header['sticky'] ?? true), 'showSearch' => (bool) ($header['showSearch'] ?? true)];
            $out['footer'] = ['description' => self::text($footer['description'] ?? '', 5000), 'email' => self::text($footer['email'] ?? '', 254), 'copyright' => self::text($footer['copyright'] ?? '', 500), 'links' => self::links($footer['links'] ?? [])];
            if ($out['footer']['email'] !== '' && !filter_var($out['footer']['email'], FILTER_VALIDATE_EMAIL)) { throw new CMSException('Email footer không hợp lệ.'); }
            $seo = is_array($value['seo'] ?? null) ? $value['seo'] : []; $aio = is_array($value['aio'] ?? null) ? $value['aio'] : [];
            $out['seo'] = ['titleTemplate' => self::text($seo['titleTemplate'] ?? '%title% | Lazi Voyage', 500), 'homeTitle' => self::plainText($seo['homeTitle'] ?? '', 500), 'description' => self::plainText($seo['description'] ?? '', 3000), 'longDescription' => self::plainText($seo['longDescription'] ?? '', 5000), 'focusKeywords' => array_values(array_unique(array_map(static fn($word) => self::plainText($word, 150), self::list($seo['focusKeywords'] ?? [], 30)))), 'organizationName' => self::text($seo['organizationName'] ?? 'Lazi Voyage', 500), 'sameAs' => array_map(static fn($url) => self::url($url, false), self::list($seo['sameAs'] ?? [], 50)), 'googleVerification' => self::text($seo['googleVerification'] ?? '', 300), 'bingVerification' => self::text($seo['bingVerification'] ?? '', 300)];
            $out['aio'] = ['about' => self::plainText($aio['about'] ?? '', 10000), 'editorialPolicy' => self::text($aio['editorialPolicy'] ?? '', 20000)];
            $out['homeFaq'] = self::homeFaq($value['homeFaq'] ?? []);
            $out['redirects'] = []; $redirectMap = [];
            foreach (self::list($value['redirects'] ?? [], 500) as $redirect) {
                if (!is_array($redirect)) { throw new CMSException('Chuyển hướng không hợp lệ.'); }
                $from = self::text($redirect['from'] ?? '', 250); $to = self::text($redirect['to'] ?? '', 250);
                foreach ([$from, $to] as $path) {
                    if (!preg_match('~^/(?:[a-z0-9][a-z0-9-]*/)*$~D', $path) || preg_match('~^/(?:admin|cms|assets|data|uploads)(?:/|$)~', $path)) { throw new CMSException('Chuyển hướng chỉ dùng đường dẫn nội bộ dạng /duong-dan/, không dùng đường dẫn quản trị.'); }
                }
                $code = (int) ($redirect['code'] ?? 301);
                if ($from === $to || !in_array($code, [301, 302], true) || isset($redirectMap[$from])) { throw new CMSException('Chuyển hướng bị trùng, tự trỏ hoặc sai mã HTTP.'); }
                $redirectMap[$from] = $to;
                $out['redirects'][] = ['from' => $from, 'to' => $to, 'code' => $code];
            }
            foreach ($redirectMap as $from => $target) {
                $visited = [$from => true];
                while (isset($redirectMap[$target])) { if (isset($visited[$target])) { throw new CMSException('Các chuyển hướng tạo thành vòng lặp.'); } $visited[$target] = true; $target = $redirectMap[$target]; }
            }
        } else { throw new CMSException('Loại dữ liệu không được hỗ trợ.'); }
        return $out;
    }

    /** Allow article formatting, never executable content or active embeds. */
    public static function cleanHtml(string $html): string
    {
        if ($html === '') { return ''; }
        if (!class_exists('DOMDocument')) { throw new CMSException('Hosting cần bật extension PHP DOM để biên tập nội dung an toàn.', 503, 'dom_missing'); }
        $doc = new DOMDocument('1.0', 'UTF-8'); $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="cms-sanitize-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors(); libxml_use_internal_errors($previous);
        $wrapper = $doc->getElementById('cms-sanitize-root');
        if (!$wrapper) { return ''; }
        $allowed = ['p','br','hr','h2','h3','h4','h5','h6','strong','b','em','i','u','s','del','sup','sub','a','ul','ol','li','blockquote','pre','code','figure','figcaption','img','picture','source','table','thead','tbody','tfoot','tr','th','td','caption','details','summary','div','span'];
        $drop = ['script','style','iframe','object','embed','form','input','button','textarea','select','svg','math','template','link','meta','base','noscript'];
        $walk = static function (DOMNode $node) use (&$walk, $allowed, $drop): void {
            foreach (iterator_to_array($node->childNodes) as $child) {
                if ($child instanceof DOMComment) { $node->removeChild($child); continue; }
                if (!$child instanceof DOMElement) { continue; }
                $tag = strtolower($child->tagName);
                if (in_array($tag, $drop, true)) { $node->removeChild($child); continue; }
                $walk($child);
                if (!in_array($tag, $allowed, true)) { while ($child->firstChild) { $node->insertBefore($child->firstChild, $child); } $node->removeChild($child); continue; }
                foreach (iterator_to_array($child->attributes) as $attr) {
                    $name = strtolower($attr->name); $value = $attr->value;
                    $valid = in_array($name, ['id','class','title','alt','width','height','loading','decoding','colspan','rowspan','scope','start','open'], true);
                    if ($name === 'id' && !preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,150}$/D', $value)) { $valid = false; }
                    if ($name === 'href' && $tag === 'a') {
                        $value = html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        $valid = !preg_match('/[\x00-\x20\x7f\\\\]/', $value) && (preg_match('~^(?:https?://|mailto:|tel:|#|/(?!/)|\.\.?/)~i', $value) === 1);
                    }
                    if ($name === 'src' && in_array($tag, ['img','source'], true)) { try { self::url($value); $valid = true; } catch (CMSException) { $valid = str_starts_with($value, '../assets/') || str_starts_with($value, 'assets/'); } }
                    if (!$valid) { $child->removeAttribute($attr->name); }
                }
                if ($tag === 'a') { $child->setAttribute('rel', 'noopener noreferrer'); }
            }
        };
        $walk($wrapper); $result = '';
        foreach ($wrapper->childNodes as $child) { $result .= $doc->saveHTML($child); }
        return $result;
    }
}
