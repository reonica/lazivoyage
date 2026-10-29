<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('CMS_INTERNAL', true);
require dirname(__DIR__) . '/cms/lib/Store.php';
require dirname(__DIR__) . '/cms/lib/Publisher.php';

$temp = sys_get_temp_dir() . '/lazi-media-test-' . bin2hex(random_bytes(8));
$root = $temp . '/site';
$checks = 0;
function media_check(bool $ok, string $label): void {
    global $checks;
    if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
    $checks++; echo 'PASS: ' . $label . "\n";
}
function media_erase(string $dir): void {
    foreach (new DirectoryIterator($dir) as $item) {
        if ($item->isDot()) { continue; }
        if ($item->isDir() && !$item->isLink()) { media_erase($item->getPathname()); }
        else { unlink($item->getPathname()); }
    }
    rmdir($dir);
}
foreach (['assets/images/nested', 'uploads/cms', 'data', 'cms'] as $dir) { mkdir($root . '/' . $dir, 0700, true); }
$image = (string)file_get_contents(dirname(__DIR__) . '/assets/images/logo.webp');
file_put_contents($root . '/assets/images/existing.webp', $image);
file_put_contents($root . '/assets/images/nested/second.webp', $image);
file_put_contents($root . '/assets/images/broken.png', 'not an image');
file_put_contents($root . '/assets/images/ignored.svg', '<svg/>');
file_put_contents($temp . '/outside.webp', $image);
symlink($temp . '/outside.webp', $root . '/assets/images/outside.webp');
symlink($temp, $root . '/assets/images/linked-directory');
$upload = '/uploads/cms/' . str_repeat('a', 32) . '.gif';
file_put_contents($root . $upload, base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'));
$before = hash_file('sha256', $root . '/assets/images/existing.webp');
file_put_contents($root . '/index.html', '<!doctype html><html><head><title>Home</title></head><body><main><h1>Home</h1></main></body></html>');
file_put_contents($root . '/data/search-index.json', '[]');
$homeBefore = file_get_contents($root . '/index.html');
try {
    $store = new CMSStore($root, ['dataDir' => $temp . '/private']);
    $uploaded = $store->save('media', ['url' => $upload, 'name' => 'Uploaded image', 'alt' => 'Manual upload alt', 'width' => 1, 'height' => 1, 'size' => 42]);
    $post = $store->save('posts', ['title' => 'Existing article', 'slug' => 'existing-article', 'cover' => '/assets/images/existing.webp', 'coverAlt' => 'Ảnh đại diện có sẵn', 'content' => '<figure><img src="/assets/images/existing.webp" alt="Body alt"><figcaption>Chú thích gốc</figcaption></figure><img src="/assets/images/nested/second.webp" alt="Ảnh trong bài">']);
    $result = cms_import_media($store);
    media_check($result['imported']['added'] === 2, 'Imports existing raster assets including nested directories');
    media_check($result['imported']['existing'] === 1, 'Recognizes already uploaded media without duplication');
    media_check($result['imported']['skipped'] === 3, 'Skips corrupt images, file symlinks and directory symlinks');
    $library = array_column($store->all('media'), null, 'url');
    $asset = $library['/assets/images/existing.webp'];
    media_check($asset['alt'] === 'Ảnh đại diện có sẵn' && $asset['caption'] === 'Chú thích gốc', 'Imports existing cover alt and figure caption');
    media_check($library['/assets/images/nested/second.webp']['alt'] === 'Ảnh trong bài', 'Imports inline image alt');
    media_check($asset['width'] > 0 && $asset['height'] > 0 && $asset['size'] === strlen($image), 'Reads actual image dimensions and size');
    media_check($library[$upload]['id'] === $uploaded['id'] && $library[$upload]['alt'] === 'Manual upload alt', 'Preserves uploaded image identity and metadata');
    $asset['alt'] = 'Alt đã sửa'; $asset['caption'] = 'Caption đã sửa';
    $asset = $store->save('media', $asset);
    $again = cms_import_media($store);
    media_check($again['imported']['added'] === 0 && count($store->all('media')) === 3, 'Repeated import is idempotent');
    media_check($store->get('media', $asset['id']) === $asset, 'Repeated import never overwrites manual edits or versions');
    media_check(hash_file('sha256', $root . '/assets/images/existing.webp') === $before && file_get_contents($root . '/index.html') === $homeBefore, 'Image files and public HTML remain unchanged');
    media_check($store->get('posts', $post['id']) === $post, 'Image sync never changes existing article drafts');
    foreach (['/assets/images/../secret.png', '/assets/images/%2e%2e/secret.png', '/cms/private.png', 'https://example.test/image.png', '/uploads/cms/file.php'] as $url) {
        $rejected = false;
        try { CMSValidator::mediaUrl($url); } catch (CMSException $error) { $rejected = true; }
        media_check($rejected, 'Rejects unsafe/out-of-scope image path: ' . $url);
    }
    file_put_contents($root . '/assets/images/new.webp', $image);
    $combined = cms_import_existing($store);
    media_check($combined['imported']['media'] === 1 && count($store->all('media')) === 4, 'Content import also discovers new images without changing existing content');
    // Missing real files are reported by skipping symlink roots, never read through them.
    rename($root . '/assets', $root . '/original-assets');
    symlink($root . '/original-assets', $root . '/assets');
    $linked = cms_import_media($store);
    media_check($linked['imported']['added'] === 0 && $linked['imported']['existing'] === 1, 'Rejects symlinked assets parent even when target is inside the site');
    if (in_array('--real-content', $argv ?? [], true)) {
        $realRoot = $temp . '/real-site';
        mkdir($realRoot . '/assets/images', 0700, true);
        $originals = [];
        foreach (new DirectoryIterator(dirname(__DIR__) . '/assets/images') as $file) {
            if ($file->isDot() || $file->isLink() || !$file->isFile()) { continue; }
            $name = $file->getFilename();
            copy($file->getPathname(), $realRoot . '/assets/images/' . $name);
            $originals[$name] = hash_file('sha256', $file->getPathname());
        }
        $realStore = new CMSStore($realRoot, ['dataDir' => $temp . '/real-private']);
        $real = cms_import_media($realStore);
        media_check(count($originals) === 67 && $real['imported']['added'] === 65 && $real['imported']['skipped'] === 2, 'Imports all 65 valid real images and skips the two pre-existing empty images');
        media_check(count($real['imported']['skippedFiles']) === 2, 'Reports skipped image paths for repair');
        $unchanged = true;
        foreach ($originals as $name => $hash) { $unchanged = $unchanged && hash_file('sha256', $realRoot . '/assets/images/' . $name) === $hash; }
        media_check($unchanged, 'All real image files stay byte-identical');
        $realStore->db->exec('PRAGMA wal_checkpoint(TRUNCATE); PRAGMA journal_mode=DELETE;');
        $realStore->db = new PDO('sqlite::memory:');
        unset($realStore);
    }
    echo 'PASS ' . $checks . " media checks\n";
} finally {
    if (isset($realStore)) {
        $realStore->db->exec('PRAGMA wal_checkpoint(TRUNCATE); PRAGMA journal_mode=DELETE;');
        $realStore->db = new PDO('sqlite::memory:');
        unset($realStore);
    }
    if (isset($store)) {
        $store->db->exec('PRAGMA wal_checkpoint(TRUNCATE); PRAGMA journal_mode=DELETE;');
        $store->db = new PDO('sqlite::memory:');
    }
    unset($store);
    media_erase($temp);
}
