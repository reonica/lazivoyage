<?php
declare(strict_types=1);
if (!defined('CMS_INTERNAL')) { http_response_code(404); exit; }

/** Index existing image files in place; never copy, rename or rewrite public files. */
function cms_import_media_records(CMSStore $store): array
{
    $metadata = [];
    $remember = static function (string $url, string $alt = '', string $caption = '') use (&$metadata): void {
        if (!str_starts_with($url, '/')) { return; }
        $url = (string)parse_url($url, PHP_URL_PATH);
        $metadata[$url] ??= ['alt' => '', 'caption' => ''];
        foreach (['alt' => $alt, 'caption' => $caption] as $field => $text) {
            if ($metadata[$url][$field] === '' && trim($text) !== '') {
                $metadata[$url][$field] = trim($text);
            }
        }
    };
    foreach (array_merge($store->all('posts'), $store->all('pages')) as $record) {
        $remember((string)($record['cover'] ?? ''), (string)($record['coverAlt'] ?? ''));
        if (!empty($record['content'])) {
            $doc = cms_dom((string)$record['content']);
            foreach ($doc->getElementsByTagName('img') as $img) {
                $caption = '';
                $parent = $img->parentNode;
                while ($parent instanceof DOMElement && !in_array(strtolower($parent->tagName), ['figure', 'body'], true)) {
                    $parent = $parent->parentNode;
                }
                if ($parent instanceof DOMElement && strtolower($parent->tagName) === 'figure') {
                    $caption = $parent->getElementsByTagName('figcaption')->item(0)?->textContent ?? '';
                }
                $remember($img->getAttribute('src'), $img->getAttribute('alt'), $caption);
            }
        }
        foreach ($record['blocks'] ?? [] as $block) {
            $remember((string)($block['image'] ?? ''), (string)($block['imageAlt'] ?? ''));
        }
    }

    $known = [];
    foreach ($store->all('media') as $record) { $known[$record['url']] = true; }
    $counts = ['added' => 0, 'existing' => 0, 'skipped' => 0, 'skippedFiles' => []];
    $skip = static function (string $path) use (&$counts): void {
        $counts['skipped']++;
        if (count($counts['skippedFiles']) < 20) { $counts['skippedFiles'][] = '/' . $path; }
    };
    $visited = 0;
    $scan = function (string $relative, int $depth = 0) use (&$scan, &$visited, &$counts, &$known, $skip, $metadata, $store): void {
        $directory = $store->siteRoot . '/' . $relative;
        if ($depth > 10 || is_link($directory) || !is_dir($directory) || !is_readable($directory)) { return; }
        foreach (new DirectoryIterator($directory) as $entry) {
            if ($entry->isDot()) { continue; }
            if (++$visited > 10000) { throw new CMSException('Có quá nhiều file để đồng bộ trong một lần.', 422, 'media_limit'); }
            $path = $entry->getPathname();
            $child = $relative . '/' . $entry->getFilename();
            if ($entry->isLink()) { $skip($child); continue; }
            if ($entry->isDir()) { $scan($child, $depth + 1); continue; }
            if (!$entry->isFile() || !preg_match('/\.(?:jpe?g|png|webp|gif)$/iD', $child)) { continue; }
            $url = '/' . $child;
            if (isset($known[$url])) { $counts['existing']++; continue; }
            try { CMSValidator::mediaUrl($url); } catch (CMSException $error) { $skip($child); continue; }
            $actual = realpath($path);
            if (!$actual || !str_starts_with($actual, $store->siteRoot . '/') || !is_readable($path) || $entry->getSize() > 64 * 1024 * 1024) {
                $skip($child); continue;
            }
            $info = @getimagesize($path);
            $types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (!$info || ($info['mime'] ?? '') !== $types[$extension] || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 80000000) {
                $skip($child); continue;
            }
            $id = 'existing-media-' . substr(hash('sha256', $url), 0, 24);
            if ($store->get('media', $id)) { $skip($child); continue; }
            $store->save('media', [
                'id' => $id, 'name' => $entry->getFilename(), 'url' => $url,
                'alt' => $metadata[$url]['alt'] ?? '', 'caption' => $metadata[$url]['caption'] ?? '',
                'width' => $info[0], 'height' => $info[1], 'size' => $entry->getSize(),
            ]);
            $known[$url] = true;
            $counts['added']++;
        }
    };
    foreach (['assets/images', 'uploads/cms'] as $base) {
        // Check every parent too: a symlinked assets/ must never expose files outside the site.
        $path = $store->siteRoot;
        $safe = true;
        foreach (explode('/', $base) as $part) {
            $path .= '/' . $part;
            if (is_link($path)) { $safe = false; break; }
        }
        if ($safe) { $scan($base); }
    }
    $store->audit('media.import', null, $counts);
    return $counts;
}

function cms_import_media(CMSStore $store): array
{
    return cms_locked($store, static function () use ($store): array {
        $counts = cms_import_media_records($store);
        return ['imported' => $counts, 'message' => sprintf(
            'Đã thêm %d ảnh vào thư viện; %d ảnh đã có; bỏ qua %d file không phù hợp. File ảnh và website công khai được giữ nguyên.',
            $counts['added'], $counts['existing'], $counts['skipped']
        )];
    });
}
