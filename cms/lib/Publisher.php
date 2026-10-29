<?php
declare(strict_types=1);

if (!defined('CMS_INTERNAL')) { http_response_code(404); exit; }
require_once __DIR__ . '/Media.php';

/** Static publisher. Private snapshots deliberately separate drafts from live content. */
function cms_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cms_slug(string $value): string
{
    $value = str_replace(['đ', 'Đ'], ['d', 'D'], $value);
    $ascii = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) : $value;
    return trim(strtolower((string)preg_replace('/[^a-zA-Z0-9]+/', '-', $ascii ?: $value)), '-');
}

function cms_safe_url(string $value, bool $image = false): string
{
    $value = trim($value);
    if ($value === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $value)) {
        return '';
    }
    if (str_starts_with($value, '//')) {
        return '';
    }
    if (str_starts_with($value, '/') || str_starts_with($value, '#')) {
        return $value;
    }
    $scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));
    if (in_array($scheme, $image ? ['http', 'https'] : ['http', 'https', 'mailto', 'tel'], true)) {
        return $value;
    }
    return '';
}

function cms_dom(string $html): DOMDocument
{
    if (!class_exists('DOMDocument')) {
        throw new RuntimeException('Cần bật extension PHP DOM/XML để nhập và xuất bản nội dung.');
    }
    $previous = libxml_use_internal_errors(true);
    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    foreach (iterator_to_array($doc->childNodes) as $child) {
        if ($child instanceof DOMProcessingInstruction) {
            $doc->removeChild($child);
        }
    }
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return $doc;
}

function cms_inner(DOMNode $node): string
{
    $html = '';
    foreach ($node->childNodes as $child) {
        $html .= $node->ownerDocument->saveHTML($child);
    }
    return $html;
}

function cms_clean_content(string $html): string
{
    $doc = cms_dom('<div id="cms-content-root">' . $html . '</div>');
    $root = $doc->getElementById('cms-content-root');
    if (!$root) {
        return '';
    }
    $allowed = ['p','br','hr','h2','h3','h4','h5','h6','strong','b','em','i','u','s','a','img','figure','figcaption','blockquote','ul','ol','li','table','thead','tbody','tfoot','tr','th','td','pre','code','div','span','section','details','summary','iframe'];
    $drop = ['script','style','template','noscript','form','button','input','textarea','select','object','embed','svg','math','link','meta','base'];
    $clean = function (DOMNode $node) use (&$clean, $allowed, $drop): void {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (!$child instanceof DOMElement) {
                if ($child instanceof DOMComment) {
                    $node->removeChild($child);
                }
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, $drop, true)) {
                $node->removeChild($child);
                continue;
            }
            $clean($child);
            if (!in_array($tag, $allowed, true)) {
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }
            foreach (iterator_to_array($child->attributes) as $attr) {
                $name = strtolower($attr->name);
                if (!in_array($name, ['href','src','alt','title','id','class','width','height','colspan','rowspan','scope','target','rel','loading','decoding','allowfullscreen'], true)) {
                    $child->removeAttribute($attr->name);
                }
            }
            foreach (['href','src'] as $name) {
                if ($child->hasAttribute($name)) {
                    $safe = cms_safe_url($child->getAttribute($name), $name === 'src');
                    if ($safe === '') {
                        $child->removeAttribute($name);
                    } else {
                        $child->setAttribute($name, $safe);
                    }
                }
            }
            if ($tag === 'a') {
                $child->setAttribute('rel', 'noopener noreferrer');
                if ($child->getAttribute('target') !== '_blank') {
                    $child->removeAttribute('target');
                }
            }
            if ($tag === 'img') {
                $child->setAttribute('loading', 'lazy');
                $child->setAttribute('decoding', 'async');
            }
            if ($tag === 'iframe') {
                $src = $child->getAttribute('src');
                $host = strtolower((string)parse_url($src, PHP_URL_HOST));
                if (!in_array($host, ['www.youtube.com','www.youtube-nocookie.com','player.vimeo.com'], true) || !str_starts_with($src, 'https://')) {
                    $node->removeChild($child);
                    continue;
                }
                $child->setAttribute('sandbox', 'allow-scripts allow-same-origin allow-presentation');
                $child->setAttribute('loading', 'lazy');
                $child->setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
            }
        }
    };
    $clean($root);
    return cms_inner($root);
}

function cms_resolve_source_urls(string $html, string $path): string
{
    return (string)preg_replace_callback('/\b(href|src|poster)\s*=\s*(["\'])(.*?)\2/si', function (array $m) use ($path): string {
        $url = html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($url === '' || $url[0] === '/' || $url[0] === '#' || preg_match('/^[a-z][a-z0-9+.-]*:/i', $url)) {
            return $m[0];
        }
        $segments = explode('/', trim($path, '/') . '/' . $url);
        $resolved = [];
        foreach ($segments as $segment) {
            if ($segment === '..') {
                array_pop($resolved);
            } elseif ($segment !== '.' && $segment !== '') {
                $resolved[] = $segment;
            }
        }
        $result = '/' . implode('/', $resolved) . (str_ends_with($url, '/') ? '/' : '');
        return $m[1] . '=' . $m[2] . cms_html($result) . $m[2];
    }, $html);
}

function cms_private_json(string $path, array $data): void
{
    $temp = $path . '.tmp-' . bin2hex(random_bytes(6));
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($temp, $json, LOCK_EX) === false) {
        throw new RuntimeException('Không ghi được dữ liệu xuất bản riêng tư.');
    }
    chmod($temp, 0600);
    if (!rename($temp, $path)) {
        @unlink($temp);
        throw new RuntimeException('Không cập nhật được dữ liệu xuất bản.');
    }
}

function cms_read_live(CMSStore $store): array
{
    $path = $store->dataDir . '/published.json';
    if (!is_file($path)) {
        return ['version' => 1, 'documents' => [], 'generated' => []];
    }
    $data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data) || !isset($data['documents'], $data['generated'])) {
        throw new RuntimeException('Dữ liệu phiên bản xuất bản không hợp lệ.');
    }
    return $data;
}

function cms_source_metadata(array $record, array $live): array
{
    foreach (['sourcePath','sourceId','sourceContentHash','originalSearchId'] as $field) {
        unset($record[$field]);
        if (array_key_exists($field, $live)) {
            $record[$field] = $live[$field];
        }
    }
    return $record;
}

function cms_locked(CMSStore $store, callable $callback): array
{
    $lock = fopen($store->dataDir . '/publish.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Một tác vụ nhập hoặc xuất bản khác đang chạy. Vui lòng thử lại.');
    }
    try {
        return $callback();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function cms_xpath_first(DOMXPath $xp, string $query): ?DOMNode
{
    return $xp->query($query)->item(0);
}

function cms_meta(DOMXPath $xp, string $name): string
{
    $node = cms_xpath_first($xp, '//meta[@name="' . $name . '" or @property="' . $name . '"]');
    return $node instanceof DOMElement ? $node->getAttribute('content') : '';
}

/** One-time migration preserves verification when new generated pages replace static sources. */
function cms_import_site_verification(CMSStore $store): void
{
    $marker = $store->dataDir . '/site-verification-import.json';
    if (is_file($marker)) {
        return;
    }
    $home = $store->siteRoot . '/index.html';
    if (!is_file($home) || is_link($home) || filesize($home) > 4 * 1024 * 1024) {
        return;
    }
    $xp = new DOMXPath(cms_dom((string)file_get_contents($home)));
    $settings = $store->settings();
    $imported = [];
    foreach (['googleVerification' => 'google-site-verification', 'bingVerification' => 'msvalidate.01'] as $field => $name) {
        $existing = trim((string)($settings['seo'][$field] ?? ''));
        $token = trim(cms_meta($xp, $name));
        if ($existing === '' && $token !== '') {
            $settings['seo'][$field] = $token;
            $imported[] = $field;
        }
    }
    if ($imported) {
        $store->save('settings', $settings);
        $store->audit('settings.verification.import', null, ['fields' => $imported]);
    }
    // Subsequent imports must not refill a token the administrator explicitly cleared.
    cms_private_json($marker, ['importedAt' => date(DATE_ATOM), 'fields' => $imported]);
}

function cms_import_existing(CMSStore $store): array
{
    return cms_locked($store, function () use ($store): array {
        $index = json_decode((string)file_get_contents($store->siteRoot . '/data/search-index.json'), true, 512, JSON_THROW_ON_ERROR);
        $live = cms_read_live($store);
        cms_import_site_verification($store);
        $sources = $store->dataDir . '/sources';
        if (!is_dir($sources) && !mkdir($sources, 0700, true)) {
            throw new RuntimeException('Không tạo được thư mục lưu bản gốc.');
        }
        $counts = ['posts' => 0, 'pages' => 0, 'categories' => 0, 'tags' => 0, 'skipped' => 0, 'media' => 0];
        $paths = [];
        foreach ($index as $item) {
            $paths[] = ['collection' => 'posts', 'path' => (string)$item['path'], 'item' => $item];
        }
        foreach (['/', '/gioi-thieu-ve-lazi/', '/contact/', '/chinh-sach-bao-mat/', '/dieu-khoan-su-dung/', '/tieu-chuan-cong-dong/'] as $path) {
            $paths[] = ['collection' => 'pages', 'path' => $path, 'item' => []];
        }
        foreach ($paths as $entry) {
            $path = $entry['path'];
            if (!preg_match('~^/(?:[a-z0-9-]+/)?$~', $path)) {
                continue;
            }
            $relative = trim($path, '/') . ($path === '/' ? '' : '/') . 'index.html';
            $source = $store->siteRoot . '/' . $relative;
            $id = 'legacy-' . substr(hash('sha256', $path), 0, 20);
            if ($store->get($entry['collection'], $id) !== null) {
                $counts['skipped']++;
                continue;
            }
            if (!is_file($source) || is_link($source) || filesize($source) > 4 * 1024 * 1024) {
                continue;
            }
            $html = (string)file_get_contents($source);
            $doc = cms_dom($html);
            $xp = new DOMXPath($doc);
            $content = cms_xpath_first($xp, $entry['collection'] === 'posts' ? '//article[contains(concat(" ",normalize-space(@class)," ")," article-content ")]' : '//main');
            if (!$content) {
                continue;
            }
            $title = cms_xpath_first($xp, '//h1');
            $item = $entry['item'];
            $cover = cms_xpath_first($xp, '//figure[contains(@class,"ls-article-cover")]//img');
            $author = cms_xpath_first($xp, '//*[contains(@class,"ls-byline")]/a');
            $tags = [];
            foreach ($xp->query('//a[contains(@href,"/tag/")]') as $node) {
                $tags[] = trim($node->textContent);
            }
            $record = [
                'id' => $id, 'type' => $entry['collection'] === 'posts' ? 'post' : 'page',
                'title' => $item['title'] ?? ($title ? trim($title->textContent) : 'Trang chủ'),
                'slug' => trim($path, '/'), 'excerpt' => $item['excerpt'] ?? cms_meta($xp, 'description'),
                'content' => cms_resolve_source_urls(cms_inner($content), $path),
                'cover' => $item['cover']['src'] ?? ($cover instanceof DOMElement ? cms_resolve_attribute($cover->getAttribute('src'), $path) : ''),
                'coverAlt' => $item['cover']['alt'] ?? ($cover instanceof DOMElement ? $cover->getAttribute('alt') : ''),
                'category' => $item['category'] ?? '', 'tags' => array_values(array_unique($tags)),
                'author' => $author ? trim($author->textContent) : 'Lazi Voyage',
                'publishedAt' => $item['date'] ?? date(DATE_ATOM, (int)filemtime($source)), 'status' => 'published',
                'seo' => ['title' => ($doc->getElementsByTagName('title')->item(0)?->textContent ?? ''), 'description' => cms_meta($xp, 'description'), 'canonical' => '', 'noindex' => str_contains(cms_meta($xp, 'robots'), 'noindex'), 'ogImage' => cms_meta($xp, 'og:image')],
                'aio' => ['summary' => '', 'sources' => [], 'faq' => [], 'reviewedAt' => ''], 'blocks' => [],
                'sourcePath' => $relative, 'sourceId' => $id, 'originalSearchId' => $item['id'] ?? null,
                'sourceContentHash' => hash('sha256', cms_resolve_source_urls(cms_inner($content), $path)),
            ];
            $saved = $store->save($entry['collection'], $record, null, true);
            $record = array_merge($record, $saved);
            $record['sourceContentHash'] = hash('sha256', (string)$record['content']);
            if (file_put_contents($sources . '/' . $id . '.html', $html, LOCK_EX) === false) {
                throw new RuntimeException('Không lưu được HTML gốc.');
            }
            chmod($sources . '/' . $id . '.html', 0600);
            $live['documents'][$entry['collection'] . '/' . $id] = $record;
            $counts[$entry['collection']]++;
        }
        foreach (['categories' => 'category', 'tags' => 'tag'] as $collection => $folder) {
            foreach (glob($store->siteRoot . '/' . $folder . '/*/index.html') ?: [] as $path) {
                if (is_link($path) || is_link(dirname($path))) {
                    continue;
                }
                $slug = basename(dirname($path));
                if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
                    continue;
                }
                $id = $folder . '-' . $slug;
                if ($store->get($collection, $id)) {
                    continue;
                }
                $doc = cms_dom((string)file_get_contents($path));
                $xp = new DOMXPath($doc);
                $h1 = cms_xpath_first($xp, '//h1');
                $store->save($collection, ['id' => $id, 'name' => $h1 ? trim($h1->textContent) : $slug, 'slug' => $slug, 'description' => cms_meta($xp, 'description'), 'seo' => ['title' => $doc->getElementsByTagName('title')->item(0)?->textContent ?? '', 'description' => cms_meta($xp, 'description')]], null, true);
                $counts[$collection]++;
            }
        }
        $media = cms_import_media_records($store);
        $counts['media'] = $media['added'];
        if (!is_file($store->dataDir . '/chrome-baseline.json')) {
            cms_private_json($store->dataDir . '/chrome-baseline.json', $store->settings());
        }
        cms_private_json($store->dataDir . '/published.json', $live);
        $store->audit('content.import', null, $counts);
        return ['imported' => $counts, 'media' => $media, 'message' => sprintf('Đã nhập dữ liệu và thêm %d ảnh vào thư viện. Website công khai chưa thay đổi.', $media['added'])];
    });
}

function cms_resolve_attribute(string $url, string $path): string
{
    $html = cms_resolve_source_urls('<img src="' . cms_html($url) . '">', $path);
    preg_match('/src="([^"]*)"/', $html, $m);
    return html_entity_decode($m[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function cms_document_path(array $record, string $collection): string
{
    $slug = trim((string)($record['slug'] ?? ''), '/');
    if ($collection === 'pages' && ($record['sourcePath'] ?? '') === 'index.html' && str_starts_with((string)($record['id'] ?? ''), 'legacy-')) {
        return 'index.html';
    }
    $reserved = ['admin','cms','uploads','assets','data','category','tag','blog','author','su-viet','tim-kiem','sitemap','cam-on','.git','.github'];
    if (!preg_match('~^[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*$~', $slug) || in_array(explode('/', $slug)[0], $reserved, true)) {
        throw new RuntimeException('Đường dẫn bài/trang không hợp lệ hoặc trùng thư mục hệ thống.');
    }
    return $slug . '/index.html';
}

function cms_record_url(array $record, string $collection, array $settings): string
{
    $path = cms_document_path($record, $collection);
    return rtrim((string)($settings['siteUrl'] ?? 'https://lazivoyage.com'), '/') . '/' . ($path === 'index.html' ? '' : substr($path, 0, -10));
}

function cms_header(array $settings): string
{
    $header = $settings['header'] ?? [];
    $name = (string)($settings['siteName'] ?? 'Lazi Voyage');
    $menu = $header['menu'] ?? [['label' => 'Home','url' => '/'],['label' => 'Blog','url' => '/blog/'],['label' => 'Sử Việt','url' => '/su-viet/'],['label' => 'Contact','url' => '/contact/']];
    $links = '';
    foreach ($menu as $item) {
        $url = cms_safe_url((string)($item['url'] ?? ''));
        if ($url !== '') {
            $links .= '<a href="' . cms_html($url) . '">' . cms_html((string)($item['label'] ?? '')) . '</a>';
        }
    }
    $logo = cms_safe_url((string)($header['logo'] ?? '/assets/images/logo.webp'), true);
    $html = '<header class="la-header"' . (empty($header['sticky']) ? ' style="position:relative"' : '') . '><div class="la-inner"><a class="la-brand" href="/" aria-label="' . cms_html($name) . ' — Trang chủ">';
    if ($logo !== '') {
        $html .= '<img src="' . cms_html($logo) . '" alt="" width="48" height="48">';
    }
    $html .= '<span class="la-wordmark">' . cms_html($name) . '</span></a><nav class="la-nav" aria-label="Điều hướng chính">' . $links . '</nav>';
    if (!empty($header['showSearch'])) {
        $html .= '<form class="la-search" role="search" action="/tim-kiem/" method="get"><label class="la-accessible" for="header-search">Tìm trên Lazi</label><input id="header-search" name="q" type="search" placeholder="Tìm kiếm…" maxlength="200"><button type="submit">Tìm</button></form>';
    }
    return $html . '<details class="la-menu"><summary>Menu</summary><nav aria-label="Điều hướng di động">' . $links . '</nav></details></div></header>';
}

function cms_footer(array $settings): string
{
    $footer = $settings['footer'] ?? [];
    $html = '<footer class="la-footer"><div class="la-inner"><p>' . cms_html((string)($footer['description'] ?? 'Những câu chuyện du lịch, ẩm thực và văn hóa Việt Nam.')) . '</p>';
    $email = filter_var($footer['email'] ?? '', FILTER_VALIDATE_EMAIL);
    if ($email) {
        $html .= '<p><a href="mailto:' . cms_html($email) . '">' . cms_html($email) . '</a></p>';
    }
    $html .= '<nav aria-label="Liên kết cuối trang">';
    foreach (($footer['links'] ?? []) as $item) {
        $url = cms_safe_url((string)($item['url'] ?? ''));
        if ($url !== '') {
            $html .= '<a href="' . cms_html($url) . '">' . cms_html((string)($item['label'] ?? '')) . '</a> ';
        }
    }
    return $html . '</nav><p>' . cms_html((string)($footer['copyright'] ?? '© Lazi Voyage')) . '</p></div></footer>';
}

function cms_aio_content(array $record): string
{
    $aio = $record['aio'] ?? [];
    $html = '';
    if (trim((string)($aio['summary'] ?? '')) !== '') {
        $html .= '<section aria-label="Tóm tắt"><h2>Tóm tắt</h2><p>' . nl2br(cms_html((string)$aio['summary'])) . '</p></section>';
    }
    return $html;
}

function cms_aio_tail(array $record): string
{
    $aio = $record['aio'] ?? [];
    $html = '';
    if (!empty($aio['faq'])) {
        $html .= '<section class="la-faq"><h2>Câu hỏi thường gặp</h2>';
        foreach ($aio['faq'] as $item) {
            if (empty($item['question']) || empty($item['answer'])) {
                continue;
            }
            $html .= '<details><summary>' . cms_html((string)$item['question']) . '</summary><div><p>' . nl2br(cms_html((string)$item['answer'])) . '</p></div></details>';
        }
        $html .= '</section>';
    }
    if (!empty($aio['sources'])) {
        $links = '';
        foreach ($aio['sources'] as $source) {
            $url = cms_safe_url((string)($source['url'] ?? ''));
            if (preg_match('~^https?://~i', $url)) {
                $links .= '<li><a href="' . cms_html($url) . '" rel="noopener noreferrer">' . cms_html((string)($source['title'] ?? $url)) . '</a></li>';
            }
        }
        if ($links !== '') {
            $html .= '<section><h2>Nguồn tham khảo</h2><ul>' . $links . '</ul></section>';
        }
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($aio['reviewedAt'] ?? ''))) {
        $html .= '<p>Nội dung được rà soát ngày <time datetime="' . cms_html($aio['reviewedAt']) . '">' . cms_html($aio['reviewedAt']) . '</time>.</p>';
    }
    return $html;
}

function cms_post_cards(array $records): string
{
    $html = '<div class="ls-archive-grid">';
    foreach ($records as $record) {
        $path = '/' . trim((string)$record['slug'], '/') . '/';
        $html .= '<article class="ls-story">';
        $cover = cms_safe_url((string)($record['cover'] ?? ''), true);
        if ($cover !== '') {
            $html .= '<a class="ls-story-image" href="' . cms_html($path) . '"><img src="' . cms_html($cover) . '" alt="' . cms_html((string)($record['coverAlt'] ?? '')) . '" loading="lazy" decoding="async"></a>';
        }
        $html .= '<div class="la-post-body"><p class="ls-story-topic">' . cms_html((string)($record['category'] ?? '')) . '</p><h2><a href="' . cms_html($path) . '">' . cms_html((string)$record['title']) . '</a></h2><p class="ls-story-excerpt">' . cms_html((string)($record['excerpt'] ?? '')) . '</p><p class="ls-story-date"><time datetime="' . cms_html((string)($record['publishedAt'] ?? '')) . '">' . cms_html(substr((string)($record['publishedAt'] ?? ''), 0, 10)) . '</time></p></div></article>';
    }
    return $html . '</div>';
}

function cms_blocks(array $record, array $posts): string
{
    $html = '';
    foreach (($record['blocks'] ?? []) as $block) {
        if (!empty($block['hidden'])) {
            continue;
        }
        $type = (string)($block['type'] ?? 'text');
        $title = cms_html((string)($block['title'] ?? ''));
        $body = cms_clean_content((string)($block['content'] ?? ''));
        $image = cms_safe_url((string)($block['image'] ?? ''), true);
        $url = cms_safe_url((string)($block['url'] ?? ''));
        $html .= '<section class="la-inner" style="padding-top:2rem;padding-bottom:2rem">';
        if ($title !== '') {
            $html .= '<h2>' . $title . '</h2>';
        }
        if (in_array($type, ['hero','image'], true) && $image !== '') {
            $html .= '<figure><img src="' . cms_html($image) . '" alt="' . cms_html((string)($block['imageAlt'] ?? $block['alt'] ?? '')) . '" loading="lazy" style="max-width:100%;height:auto"></figure>';
        }
        if ($type === 'gallery') {
            foreach (($block['images'] ?? []) as $galleryImage) {
                $src = cms_safe_url((string)$galleryImage, true);
                if ($src !== '') {
                    $html .= '<figure><img src="' . cms_html($src) . '" alt="' . cms_html((string)($block['imageAlt'] ?? $block['alt'] ?? '')) . '" loading="lazy" style="max-width:100%;height:auto"></figure>';
                }
            }
        } elseif ($type === 'posts') {
            $html .= cms_post_cards(array_slice($posts, 0, 6));
        } elseif ($type === 'faq') {
            foreach (($block['items'] ?? []) as $item) {
                $html .= '<details><summary>' . cms_html((string)($item['question'] ?? '')) . '</summary><p>' . nl2br(cms_html((string)($item['answer'] ?? ''))) . '</p></details>';
            }
        } else {
            $html .= $body;
        }
        if (in_array($type, ['hero','cta'], true) && $url !== '') {
            $html .= '<p><a class="la-primary" href="' . cms_html($url) . '">' . cms_html((string)($block['buttonText'] ?? $block['label'] ?? 'Tìm hiểu thêm')) . '</a></p>';
        }
        if ($type === 'contact') {
            $email = trim((string)($block['email'] ?? preg_replace('/^mailto:/', '', $block['url'] ?? '')));
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $html .= '<p><a class="la-primary" href="mailto:' . cms_html($email) . '">' . cms_html((string)($block['buttonText'] ?? $block['label'] ?? 'Gửi email cho Lazi')) . '</a></p>';
            } else {
                $html .= '<p>Liên hệ qua thông tin ở cuối trang.</p>';
            }
        }
        $html .= '</section>';
    }
    return $html;
}

function cms_replace_inner(DOMNode $target, string $html): void
{
    while ($target->firstChild) {
        $target->removeChild($target->firstChild);
    }
    $fragment = cms_dom('<div id="cms-replacement">' . $html . '</div>')->getElementById('cms-replacement');
    if ($fragment) {
        foreach (iterator_to_array($fragment->childNodes) as $child) {
            $target->appendChild($target->ownerDocument->importNode($child, true));
        }
    }
}

function cms_replace_node(DOMNode $target, string $html): void
{
    $fragment = cms_dom('<div id="cms-replacement">' . $html . '</div>')->getElementById('cms-replacement');
    if ($fragment && $target->parentNode) {
        foreach (iterator_to_array($fragment->childNodes) as $child) {
            $target->parentNode->insertBefore($target->ownerDocument->importNode($child, true), $target);
        }
        $target->parentNode->removeChild($target);
    }
}

function cms_render_document(CMSStore $store, array $record, string $collection, array $settings, array $posts, bool $preview = false): string
{
    $path = cms_document_path($record, $collection);
    $url = cms_record_url($record, $collection, $settings);
    $seo = $record['seo'] ?? [];
    $title = (string)($seo['title'] ?? '');
    if ($title === '') {
        $template = (string)($settings['seo']['titleTemplate'] ?? '{title} | {site}');
        $title = str_replace(['{title}','{site}','%title%','%site%'], [(string)$record['title'], (string)($settings['siteName'] ?? 'Lazi Voyage'), (string)$record['title'], (string)($settings['siteName'] ?? 'Lazi Voyage')], $template);
    }
    $description = (string)($seo['description'] ?? $record['excerpt'] ?? '');
    $canonical = cms_safe_url((string)($seo['canonical'] ?? ''));
    if (!preg_match('~^https?://~', $canonical)) {
        $canonical = $url;
    }
    $test = ($settings['environment'] ?? 'test') !== 'production';
    $noindex = $preview || $test || !empty($seo['noindex']);
    $source = '';
    $sourceId = (string)($record['sourceId'] ?? '');
    if (preg_match('/^legacy-[a-f0-9]{20}$/', $sourceId)) {
        $sourceFile = $store->dataDir . '/sources/' . $sourceId . '.html';
        if (is_file($sourceFile) && !is_link($sourceFile)) {
            $source = cms_resolve_source_urls((string)file_get_contents($sourceFile), '/' . ($path === 'index.html' ? '' : substr($path, 0, -10)));
        }
    }
    $content = cms_aio_content($record) . cms_clean_content((string)($record['content'] ?? '')) . cms_aio_tail($record);
    if (!empty($record['blocks'])) {
        $main = '<main id="main"><header class="la-inner"><h1>' . cms_html((string)$record['title']) . '</h1></header>' . cms_blocks($record, $posts) . '</main>';
    } elseif ($collection === 'posts') {
        $cover = cms_safe_url((string)($record['cover'] ?? ''), true);
        $main = '<main id="main" class="la-inner ls-bottom-space"><header class="ls-article-head"><p class="ls-kicker">' . cms_html((string)($record['category'] ?? '')) . '</p><h1 class="ls-article-title">' . cms_html((string)$record['title']) . '</h1><p class="ls-deck">' . cms_html((string)($record['excerpt'] ?? '')) . '</p><p class="ls-byline">' . cms_html((string)($record['author'] ?? 'Lazi Voyage')) . ' <time datetime="' . cms_html((string)($record['publishedAt'] ?? '')) . '">' . cms_html(substr((string)($record['publishedAt'] ?? ''), 0, 10)) . '</time></p></header>';
        if ($cover !== '') {
            $main .= '<figure class="ls-article-cover"><img src="' . cms_html($cover) . '" alt="' . cms_html((string)($record['coverAlt'] ?? '')) . '" fetchpriority="high"></figure>';
        }
        $main .= '<div class="ls-reading-grid"><article class="ls-reading-body article-content">' . $content . '</article></div></main>';
    } else {
        $main = '<main id="main" class="la-inner ls-bottom-space"><h1>' . cms_html((string)$record['title']) . '</h1><div class="ls-reading-body">' . $content . '</div></main>';
    }
    if ($source === '' || !empty($record['blocks'])) {
        $source = '<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/style.css"><link rel="icon" href="/favicon.png"><script src="/script.js" defer></script></head><body class="la-page ls-site"><a class="skip-link" href="#main">Đến nội dung chính</a>' . (empty($record['hideHeader']) ? cms_header($settings) : '') . $main . (empty($record['hideFooter']) ? cms_footer($settings) : '') . '</body></html>';
    }
    $doc = cms_dom($source);
    $xp = new DOMXPath($doc);
    if ($sourceId !== '' && empty($record['blocks'])) {
        if ($collection === 'posts') {
            $article = cms_xpath_first($xp, '//article[contains(concat(" ",normalize-space(@class)," ")," article-content ")]');
            if ($article) {
                cms_replace_inner($article, $content);
            }
            foreach (['//h1' => (string)$record['title'], '//p[contains(@class,"ls-deck")]' => (string)($record['excerpt'] ?? '')] as $query => $text) {
                $node = cms_xpath_first($xp, $query);
                if ($node) {
                    cms_replace_inner($node, cms_html($text));
                }
            }
            $byline = cms_xpath_first($xp, '//p[contains(@class,"ls-byline")]');
            if ($byline) {
                cms_replace_inner($byline, cms_html((string)($record['author'] ?? 'Lazi Voyage')) . ' <time datetime="' . cms_html((string)($record['publishedAt'] ?? '')) . '">' . cms_html(substr((string)($record['publishedAt'] ?? ''), 0, 10)) . '</time>');
            }
            $cover = cms_xpath_first($xp, '//figure[contains(@class,"ls-article-cover")]//img');
            if ($cover instanceof DOMElement) {
                $src = cms_safe_url((string)($record['cover'] ?? ''), true);
                if ($src !== '') {
                    $cover->setAttribute('src', $src);
                    $cover->setAttribute('alt', (string)($record['coverAlt'] ?? ''));
                }
            }
            // A new heading structure requires a fresh table of contents, rather than stale anchors.
            foreach (iterator_to_array($xp->query('//details[contains(@class,"ls-toc")]')) as $toc) {
                $toc->parentNode?->removeChild($toc);
            }
        } elseif (($record['sourceContentHash'] ?? '') !== hash('sha256', (string)($record['content'] ?? '')) || !empty($record['aio']['summary']) || !empty($record['aio']['sources']) || !empty($record['aio']['faq']) || !empty($record['aio']['reviewedAt'])) {
            $node = cms_xpath_first($xp, '//main');
            if ($node) {
                cms_replace_inner($node, $content);
            }
        }
    }
    $baselinePath = $store->dataDir . '/chrome-baseline.json';
    $baseline = is_file($baselinePath) ? json_decode((string)file_get_contents($baselinePath), true, 512, JSON_THROW_ON_ERROR) : [];
    if (!$sourceId || !empty($record['hideHeader']) || ($baseline['header'] ?? null) !== ($settings['header'] ?? null) || ($baseline['siteName'] ?? null) !== ($settings['siteName'] ?? null)) {
        foreach (iterator_to_array($xp->query('//header[contains(concat(" ",normalize-space(@class)," ")," la-header ")]')) as $node) {
            cms_replace_node($node, empty($record['hideHeader']) ? cms_header($settings) : '');
        }
    }
    if (!$sourceId || !empty($record['hideFooter']) || ($baseline['footer'] ?? null) !== ($settings['footer'] ?? null)) {
        foreach (iterator_to_array($xp->query('//footer[contains(concat(" ",normalize-space(@class)," ")," la-footer ")]')) as $node) {
            cms_replace_node($node, empty($record['hideFooter']) ? cms_footer($settings) : '');
        }
    }
    foreach (iterator_to_array($xp->query('//title | //meta[@name="description" or @name="robots" or starts-with(@name,"twitter:")] | //meta[starts-with(@property,"og:")] | //link[@rel="canonical"] | //script[@type="application/ld+json"]')) as $node) {
        $node->parentNode?->removeChild($node);
    }
    $head = $doc->getElementsByTagName('head')->item(0);
    if (!$head) {
        throw new RuntimeException('HTML không có head để cập nhật SEO.');
    }
    $titleNode = $doc->createElement('title');
    $titleNode->appendChild($doc->createTextNode($title));
    $head->appendChild($titleNode);
    $meta = function (string $key, string $value, bool $property = false) use ($doc, $head): void {
        $node = $doc->createElement('meta');
        $node->setAttribute($property ? 'property' : 'name', $key);
        $node->setAttribute('content', $value);
        $head->appendChild($node);
    };
    $meta('description', $description);
    $meta('robots', $noindex ? 'noindex,follow' : 'index,follow,max-image-preview:large');
    $meta('og:title', $title, true); $meta('og:description', $description, true); $meta('og:url', $canonical, true);
    $meta('og:site_name', (string)($settings['siteName'] ?? 'Lazi Voyage'), true);
    $meta('og:type', $collection === 'posts' ? 'article' : 'website', true);
    $meta('twitter:card', 'summary_large_image');
    foreach (['googleVerification' => 'google-site-verification','bingVerification' => 'msvalidate.01'] as $field => $name) {
        if (!empty($settings['seo'][$field])) {
            foreach (iterator_to_array($xp->query('//meta[@name="' . $name . '"]')) as $old) {
                $old->parentNode?->removeChild($old);
            }
            $meta($name, (string)$settings['seo'][$field]);
        }
    }
    $image = cms_safe_url((string)($seo['ogImage'] ?? $record['cover'] ?? ''), true);
    if ($image !== '') {
        if (str_starts_with($image, '/')) {
            $image = rtrim((string)$settings['siteUrl'], '/') . $image;
        }
        $meta('og:image', $image, true);
    }
    $link = $doc->createElement('link');
    $link->setAttribute('rel', 'canonical'); $link->setAttribute('href', $canonical); $head->appendChild($link);
    $organization = ['@type' => 'Organization','@id' => rtrim((string)$settings['siteUrl'], '/') . '/#organization','name' => (string)($settings['seo']['organizationName'] ?? $settings['siteName'] ?? 'Lazi Voyage'),'url' => rtrim((string)$settings['siteUrl'], '/') . '/'];
    if (!empty($settings['aio']['about'])) {
        $organization['description'] = (string)$settings['aio']['about'];
    }
    $sameAs = array_values(array_filter($settings['seo']['sameAs'] ?? [], fn($u) => is_string($u) && preg_match('~^https?://~', cms_safe_url($u))));
    if ($sameAs) {
        $organization['sameAs'] = $sameAs;
    }
    $schema = ['@type' => $collection === 'posts' ? 'BlogPosting' : 'WebPage','@id' => $canonical . '#document','url' => $canonical,'name' => (string)$record['title'],'description' => $description,'inLanguage' => 'vi','publisher' => ['@id' => $organization['@id']]];
    if ($collection === 'posts') {
        $schema['headline'] = (string)$record['title'];
        $authorName = (string)($record['author'] ?? $settings['siteName'] ?? 'Lazi Voyage');
        $schema['author'] = ['@type' => $authorName === ($settings['siteName'] ?? 'Lazi Voyage') ? 'Organization' : 'Person','name' => $authorName];
        if (strtotime((string)($record['publishedAt'] ?? '')) !== false) {
            $schema['datePublished'] = date(DATE_ATOM, strtotime($record['publishedAt']));
        }
        if (!empty($record['aio']['reviewedAt']) && strtotime($record['aio']['reviewedAt']) !== false) {
            $schema['dateModified'] = date(DATE_ATOM, strtotime($record['aio']['reviewedAt']));
        }
        if ($image !== '') {
            $schema['image'] = [$image];
        }
    }
    $graph = [$organization, $schema, ['@type' => 'BreadcrumbList','itemListElement' => [['@type' => 'ListItem','position' => 1,'name' => 'Trang chủ','item' => rtrim((string)$settings['siteUrl'], '/') . '/'],['@type' => 'ListItem','position' => 2,'name' => (string)$record['title'],'item' => $canonical]]]];
    $faq = [];
    foreach (($record['aio']['faq'] ?? []) as $item) {
        if (!empty($item['question']) && !empty($item['answer'])) {
            $faq[] = ['@type' => 'Question','name' => (string)$item['question'],'acceptedAnswer' => ['@type' => 'Answer','text' => (string)$item['answer']]];
        }
    }
    if ($faq) {
        $graph[] = ['@type' => 'FAQPage','mainEntity' => $faq];
    }
    $script = $doc->createElement('script');
    $script->setAttribute('type', 'application/ld+json');
    // ASCII JSON escapes survive DOMDocument::saveHTML without changing Unicode into HTML entities.
    $script->appendChild($doc->createTextNode((string)json_encode(['@context' => 'https://schema.org','@graph' => $graph], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR)));
    $head->appendChild($script);
    if ($preview) {
        foreach (iterator_to_array($xp->query('//script[not(@type="application/ld+json")] | //iframe | //form')) as $node) {
            $node->parentNode?->removeChild($node);
        }
    }
    $html = "<!doctype html>\n" . preg_replace('/^<!DOCTYPE[^>]*>\s*/i', '', $doc->saveHTML());
    return $preview ? $html : cms_apply_snippets($store, $html, $collection, '/' . ($path === 'index.html' ? '' : substr($path, 0, -10)), $settings);
}

function cms_apply_snippets(CMSStore $store, string $html, string $collection, string $urlPath, array $settings): string
{
    $html = (string)preg_replace('/<!-- CMS-SNIPPET:BEGIN (?:head|body_start|body_end) -->.*?<!-- CMS-SNIPPET:END -->/s', '', $html);
    $snippets = $store->all('snippets');
    usort($snippets, fn($a, $b) => ((int)($a['order'] ?? 0) <=> (int)($b['order'] ?? 0)) ?: strcmp((string)$a['id'], (string)$b['id']));
    $positions = ['head' => '', 'body_start' => '', 'body_end' => ''];
    foreach ($snippets as $snippet) {
        if (empty($snippet['enabled']) || (($settings['environment'] ?? 'test') !== 'production' && empty($snippet['allowTest']))) {
            continue;
        }
        $scope = $snippet['scope'] ?? 'all';
        if ($scope !== 'all' && $scope !== $collection && !($scope === 'urls' && in_array($urlPath, $snippet['urls'] ?? [], true))) {
            continue;
        }
        $position = $snippet['position'] ?? 'head';
        if (isset($positions[$position])) {
            $positions[$position] .= "\n" . (string)($snippet['code'] ?? '') . "\n";
        }
    }
    foreach ($positions as $position => $code) {
        if ($code !== '') {
            $positions[$position] = '<!-- CMS-SNIPPET:BEGIN ' . $position . ' -->' . $code . '<!-- CMS-SNIPPET:END -->';
        }
    }
    $html = str_ireplace('</head>', $positions['head'] . '</head>', $html);
    $html = (string)preg_replace_callback('/<body\b[^>]*>/i', fn($m) => $m[0] . $positions['body_start'], $html, 1);
    return str_ireplace('</body>', $positions['body_end'] . '</body>', $html);
}

/** Changes shared chrome only; custom tools retain byte-identical main and application scripts. */
function cms_global_chrome(CMSStore $store, string $html, string $urlPath, array $settings): string
{
    $baselinePath = $store->dataDir . '/chrome-baseline.json';
    $baseline = is_file($baselinePath) ? json_decode((string)file_get_contents($baselinePath), true, 512, JSON_THROW_ON_ERROR) : [];
    if (($baseline['header'] ?? null) !== ($settings['header'] ?? null) || ($baseline['siteName'] ?? null) !== ($settings['siteName'] ?? null)) {
        $html = (string)preg_replace_callback('~<header\b[^>]*\bclass\s*=\s*(["\'])[^"\']*\bla-header\b[^"\']*\1[^>]*>.*?</header>~si', fn() => cms_header($settings), $html, 1);
    }
    if (($baseline['footer'] ?? null) !== ($settings['footer'] ?? null)) {
        $html = (string)preg_replace_callback('~<footer\b[^>]*\bclass\s*=\s*(["\'])[^"\']*\bla-footer\b[^"\']*\1[^>]*>.*?</footer>~si', fn() => cms_footer($settings), $html, 1);
    }
    if (($settings['environment'] ?? 'test') !== 'production') {
        $html = cms_raw_meta($html, 'robots', 'noindex,follow');
    }
    foreach (['googleVerification' => 'google-site-verification','bingVerification' => 'msvalidate.01'] as $field => $name) {
        if (!empty($settings['seo'][$field])) {
            $html = cms_raw_meta($html, $name, (string)$settings['seo'][$field]);
        }
    }
    return cms_apply_snippets($store, $html, 'tools', $urlPath, $settings);
}

function cms_raw_meta(string $html, string $name, string $content): string
{
    $html = (string)preg_replace_callback('~<meta\b[^>]*>~si', function ($match) use ($name): string {
        return preg_match('~\bname\s*=\s*(["\'])' . preg_quote($name, '~') . '\1~i', $match[0]) ? '' : $match[0];
    }, $html);
    return (string)preg_replace_callback('~</head>~i', fn() => '<meta name="' . cms_html($name) . '" content="' . cms_html($content) . '"></head>', $html, 1);
}

function cms_unmanaged_files(CMSStore $store, array $managed, array $settings): array
{
    $files = [];
    $originals = $store->dataDir . '/unmanaged-sources';
    if (!is_dir($originals) && !mkdir($originals, 0700, true)) {
        throw new RuntimeException('Không tạo được bản gốc cho các trang công cụ.');
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($store->siteRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->isLink() || strtolower($file->getExtension()) !== 'html') {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($store->siteRoot) + 1);
        if (isset($managed[$relative]) || preg_match('~^(?:admin|cms|assets|uploads|tests|\.git|\.github)/~', $relative) || preg_match('~^(?:blog|category|tag)/~', $relative)) {
            continue;
        }
        cms_target($store, $relative); // Checks every path component for symlinks.
        $source = $originals . '/' . hash('sha256', $relative) . '.html';
        if (!is_file($source)) {
            if (!copy($file->getPathname(), $source)) {
                throw new RuntimeException('Không lưu được bản gốc của trang công cụ.');
            }
            chmod($source, 0600);
        }
        $html = (string)file_get_contents($source);
        $urlPath = '/' . (str_ends_with($relative, '/index.html') ? substr($relative, 0, -10) : $relative);
        $files[$relative] = cms_global_chrome($store, $html, $urlPath, $settings);
    }
    return $files;
}

function cms_preview(CMSStore $store, array $payload): string
{
    $collection = (string)($payload['collection'] ?? 'posts');
    if (!in_array($collection, ['posts','pages'], true)) {
        throw new RuntimeException('Không thể xem trước loại dữ liệu này.');
    }
    $record = $store->get($collection, (string)($payload['id'] ?? ''));
    if (!$record) {
        throw new RuntimeException('Không tìm thấy nội dung để xem trước.');
    }
    $records = cms_read_live($store)['documents'];
    $record = cms_source_metadata($record, $records[$collection . '/' . $record['id']] ?? []);
    $posts = array_values(array_filter($records, fn($r) => ($r['type'] ?? '') === 'post'));
    return cms_render_document($store, $record, $collection, $store->settings(), $posts, true);
}

function cms_target(CMSStore $store, string $relative, bool $create = false): string
{
    if ($relative === '' || str_contains($relative, '\\') || str_starts_with($relative, '/') || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $relative) || str_contains($relative, "\0")) {
        throw new RuntimeException('Đường dẫn xuất bản không an toàn.');
    }
    $parts = explode('/', $relative);
    $current = $store->siteRoot;
    foreach ($parts as $i => $part) {
        $current .= '/' . $part;
        if (is_link($current)) {
            throw new RuntimeException('Không xuất bản qua đường dẫn symbolic link.');
        }
        if ($i < count($parts) - 1) {
            if (file_exists($current) && !is_dir($current)) {
                throw new RuntimeException('Đường dẫn xuất bản trùng một file hiện có.');
            }
            if ($create && !is_dir($current) && !mkdir($current, 0755)) {
                throw new RuntimeException('Không tạo được thư mục xuất bản.');
            }
        }
    }
    return $current;
}

function cms_write_public(string $target, string $content): void
{
    $temp = dirname($target) . '/.cms-' . bin2hex(random_bytes(8)) . '.tmp';
    if (file_put_contents($temp, $content, LOCK_EX) === false) {
        throw new RuntimeException('Không ghi được file website.');
    }
    chmod($temp, 0644);
    if (!rename($temp, $target)) {
        @unlink($temp);
        throw new RuntimeException('Không thay thế được file website.');
    }
}

function cms_archive_files(CMSStore $store, array $posts, array $settings): array
{
    $files = [];
    $base = rtrim((string)$settings['siteUrl'], '/');
    $groups = [['path' => 'blog', 'title' => 'Blog', 'description' => (string)($settings['seo']['description'] ?? ''), 'posts' => $posts, 'seo' => []]];
    foreach (['categories' => 'category', 'tags' => 'tag'] as $collection => $folder) {
        foreach ($store->all($collection) as $term) {
            $slug = (string)($term['slug'] ?? '');
            if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
                continue;
            }
            $matched = array_values(array_filter($posts, function ($p) use ($collection, $term): bool {
                $candidates = $collection === 'categories' ? [(string)($p['category'] ?? '')] : ($p['tags'] ?? []);
                return in_array($term['name'] ?? '', $candidates, true) || in_array($term['id'] ?? '', $candidates, true) || in_array($term['slug'] ?? '', $candidates, true);
            }));
            $groups[] = ['path' => $folder . '/' . $slug, 'title' => $term['name'], 'description' => $term['description'] ?? '', 'posts' => $matched, 'seo' => $term['seo'] ?? []];
        }
    }
    foreach ($groups as $group) {
        $chunks = array_chunk($group['posts'], 12) ?: [[]];
        foreach ($chunks as $index => $chunk) {
            $page = $index + 1;
            $path = $group['path'] . ($page > 1 ? '/page/' . $page : '') . '/';
            $title = (string)($group['seo']['title'] ?? $group['title']);
            if ($title === '') {
                $title = (string)$group['title'];
            }
            $title .= $page > 1 ? ' — Trang ' . $page : '';
            $pagination = '<nav class="ls-pagination" aria-label="Phân trang">';
            for ($n = 1; $n <= count($chunks); $n++) {
                $link = '/' . $group['path'] . ($n > 1 ? '/page/' . $n : '') . '/';
                $pagination .= '<a href="' . cms_html($link) . '"' . ($page === $n ? ' aria-current="page"' : '') . '>' . $n . '</a> ';
            }
            $pagination .= '</nav>';
            $description = (string)($group['seo']['description'] ?? $group['description']);
            $noindex = ($settings['environment'] ?? 'test') !== 'production' || !empty($group['seo']['noindex']);
            $html = '<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . cms_html($title) . ' | ' . cms_html((string)$settings['siteName']) . '</title><meta name="description" content="' . cms_html($description) . '"><meta name="robots" content="' . ($noindex ? 'noindex,follow' : 'index,follow') . '"><link rel="canonical" href="' . cms_html($base . '/' . $path) . '"><link rel="stylesheet" href="/style.css"><script src="/script.js" defer></script></head><body class="la-page ls-site">' . cms_header($settings) . '<main id="main" class="la-inner ls-bottom-space"><header class="ls-page-head"><h1 class="ls-title">' . cms_html($title) . '</h1><p>' . cms_html((string)$group['description']) . '</p></header>' . cms_post_cards($chunk) . $pagination . '</main>' . cms_footer($settings) . '</body></html>';
            foreach (['googleVerification' => 'google-site-verification', 'bingVerification' => 'msvalidate.01'] as $field => $name) {
                if (!empty($settings['seo'][$field])) {
                    $html = cms_raw_meta($html, $name, (string)$settings['seo'][$field]);
                }
            }
            $files[$path . 'index.html'] = cms_apply_snippets($store, $html, 'archives', '/' . $path, $settings);
        }
    }
    return $files;
}

function cms_redirects_htaccess(CMSStore $store, array $settings): string
{
    $path = cms_target($store, '.htaccess');
    $existing = is_file($path) ? (string)file_get_contents($path) : '';
    $existing = (string)preg_replace('/\n?# BEGIN CMS REDIRECTS\R.*?# END CMS REDIRECTS\R?/s', '', $existing);
    $rules = [];
    $map = [];
    foreach (($settings['redirects'] ?? []) as $redirect) {
        $from = (string)($redirect['from'] ?? '');
        $to = (string)($redirect['to'] ?? '');
        $code = (int)($redirect['code'] ?? 301);
        foreach ([$from, $to] as $route) {
            if (!preg_match('~^/(?:[a-zA-Z0-9_-]+/)*[a-zA-Z0-9_-]*/?$~D', $route) || preg_match('~^/(?:admin|cms)(?:/|$)~i', $route)) {
                throw new RuntimeException('Đường dẫn chuyển hướng không hợp lệ hoặc trỏ vào CMS.');
            }
        }
        if ($from === $to || isset($map[$from]) || !in_array($code, [301,302], true)) {
            throw new RuntimeException('Chuyển hướng bị trùng hoặc không hợp lệ.');
        }
        $map[$from] = $to;
        $pattern = ltrim($from, '/');
        $pattern = preg_quote($pattern, '~');
        $rules[] = 'RewriteRule ^' . $pattern . '$ ' . $to . ' [R=' . $code . ',L,NE]';
    }
    foreach ($map as $from => $to) {
        $seen = [$from => true];
        while (isset($map[$to])) {
            if (isset($seen[$to])) {
                throw new RuntimeException('Chuyển hướng tạo vòng lặp.');
            }
            $seen[$to] = true;
            $to = $map[$to];
        }
    }
    if (!$rules) {
        return rtrim($existing) . "\n";
    }
    return rtrim($existing) . "\n# BEGIN CMS REDIRECTS\n<IfModule mod_rewrite.c>\nRewriteEngine On\n" . implode("\n", $rules) . "\n</IfModule>\n# END CMS REDIRECTS\n";
}

function cms_publish(CMSStore $store, array $payload): array
{
    return cms_locked($store, function () use ($store, $payload): array {
        $settings = $store->settings();
        $siteUrl = rtrim((string)($settings['siteUrl'] ?? ''), '/');
        if (!filter_var($siteUrl, FILTER_VALIDATE_URL) || !in_array(parse_url($siteUrl, PHP_URL_SCHEME), ['http','https'], true)) {
            throw new RuntimeException('Cần cấu hình domain chính hợp lệ trước khi xuất bản.');
        }
        $live = cms_read_live($store);
        $oldLive = $live;
        $selected = null;
        $collection = (string)($payload['collection'] ?? 'posts');
        if (!empty($payload['id'])) {
            if (!in_array($collection, ['posts','pages'], true)) {
                throw new RuntimeException('Loại nội dung xuất bản không hợp lệ.');
            }
            $selected = $store->get($collection, (string)$payload['id']);
            if (!$selected) {
                throw new RuntimeException('Không tìm thấy nội dung cần xuất bản.');
            }
            $key = $collection . '/' . $selected['id'];
            if (($selected['status'] ?? '') === 'trash') {
                unset($live['documents'][$key]);
            } else {
                $selected = cms_source_metadata($selected, $live['documents'][$key] ?? []);
                cms_document_path($selected, $collection);
                $selected['status'] = 'published';
                $selected['publishedAt'] = !empty($selected['publishedAt']) ? $selected['publishedAt'] : date(DATE_ATOM);
                $live['documents'][$key] = $selected;
            }
        }
        if (!$live['documents'] && !$selected) {
            throw new RuntimeException('Chưa có nội dung. Hãy nhập website hiện có hoặc xuất bản một bài/trang.');
        }
        $posts = array_values(array_filter($live['documents'], fn($r) => ($r['type'] ?? '') === 'post' && ($r['status'] ?? '') === 'published'));
        usort($posts, fn($a, $b) => strcmp((string)($b['publishedAt'] ?? ''), (string)($a['publishedAt'] ?? '')));
        $files = [];
        $urls = [];
        $paths = [];
        foreach ($live['documents'] as $key => $record) {
            $kind = str_starts_with($key, 'posts/') ? 'posts' : 'pages';
            $path = cms_document_path($record, $kind);
            if (isset($paths[$path])) {
                throw new RuntimeException('Hai nội dung đang dùng chung đường dẫn: ' . $path);
            }
            $knownPath = false;
            foreach ($oldLive['documents'] as $oldKey => $oldRecord) {
                $oldKind = str_starts_with($oldKey, 'posts/') ? 'posts' : 'pages';
                if (cms_document_path($oldRecord, $oldKind) === $path) {
                    $knownPath = true;
                    break;
                }
            }
            if (!$knownPath && is_file(cms_target($store, $path))) {
                throw new RuntimeException('URL đã có trên hosting. Nhập website hiện có trước để tránh ghi đè: ' . $path);
            }
            $paths[$path] = true;
            $files[$path] = cms_render_document($store, $record, $kind, $settings, $posts);
            $recordUrl = cms_record_url($record, $kind, $settings);
            $customCanonical = (string)($record['seo']['canonical'] ?? '');
            if (empty($record['seo']['noindex']) && ($customCanonical === '' || rtrim($customCanonical, '/') === rtrim($recordUrl, '/'))) {
                $urls[$recordUrl] = true;
            }
        }
        $archive = cms_archive_files($store, $posts, $settings);
        foreach ($archive as $path => $html) {
            $files[$path] = $html;
            if (!str_contains($html, 'content="noindex')) {
                $urls[$siteUrl . '/' . substr($path, 0, -10)] = true;
            }
        }
        $excluded = $files;
        foreach ($oldLive['documents'] as $oldKey => $oldRecord) {
            $excluded[cms_document_path($oldRecord, str_starts_with($oldKey, 'posts/') ? 'posts' : 'pages')] = '';
        }
        $unmanaged = cms_unmanaged_files($store, $excluded, $settings);
        foreach ($unmanaged as $path => $html) {
            $files[$path] = $html;
            if (str_ends_with($path, '/index.html') && !preg_match('~^(?:tim-kiem|cam-on)/~', $path)) {
                $urls[$siteUrl . '/' . substr($path, 0, -10)] = true;
            }
        }
        // Keep dedicated tools and their URLs; they are not rewritten as generic landing pages.
        if (is_file($store->siteRoot . '/sitemap.xml')) {
            $xml = simplexml_load_string((string)file_get_contents($store->siteRoot . '/sitemap.xml'), SimpleXMLElement::class, LIBXML_NONET);
            if ($xml) {
                foreach ($xml->url as $entry) {
                    $path = (string)parse_url((string)$entry->loc, PHP_URL_PATH);
                    if (str_starts_with($path, '/su-viet/')) {
                        $urls[$siteUrl . $path] = true;
                    }
                }
            }
        }
        $index = [];
        foreach ($posts as $post) {
            $text = trim(html_entity_decode(strip_tags((string)($post['content'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $search = strtolower((string)(function_exists('iconv') ? iconv('UTF-8','ASCII//TRANSLIT//IGNORE',str_replace(['đ','Đ'],['d','D'],$post['title'] . ' ' . ($post['excerpt'] ?? '') . ' ' . $text)) : $post['title'] . ' ' . $text));
            $index[] = ['id' => $post['originalSearchId'] ?? hexdec(substr(hash('sha256', (string)$post['id']), 0, 7)), 'title' => $post['title'], 'excerpt' => $post['excerpt'] ?? '', 'path' => '/' . $post['slug'] . '/', 'date' => $post['publishedAt'] ?? '', 'minutes' => max(1, (int)ceil(str_word_count($text) / 200)), 'cover' => ['src' => $post['cover'] ?? '', 'alt' => $post['coverAlt'] ?? '', 'width' => 1200, 'height' => 800], 'category' => $post['category'] ?? '', 'search' => $search];
        }
        $files['data/search-index.json'] = json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $sitemap = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach (array_keys($urls) as $url) {
            $sitemap .= '<url><loc>' . cms_html($url) . '</loc></url>';
        }
        $files['sitemap.xml'] = $sitemap . '</urlset>';
        $rss = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>' . cms_html((string)$settings['siteName']) . '</title><link>' . cms_html($siteUrl . '/') . '</link><description>' . cms_html((string)($settings['seo']['description'] ?? '')) . '</description><language>vi</language>';
        foreach (array_slice($posts, 0, 30) as $post) {
            if (!empty($post['seo']['noindex'])) {
                continue;
            }
            $url = cms_record_url($post, 'posts', $settings);
            $rss .= '<item><title>' . cms_html($post['title']) . '</title><link>' . cms_html($url) . '</link><guid isPermaLink="true">' . cms_html($url) . '</guid><description>' . cms_html((string)($post['excerpt'] ?? '')) . '</description><pubDate>' . gmdate(DATE_RSS, strtotime((string)($post['publishedAt'] ?? 'now')) ?: time()) . '</pubDate></item>';
        }
        $files['rss.xml'] = $rss . '</channel></rss>';
        $test = ($settings['environment'] ?? 'test') !== 'production';
        // Crawlers must be allowed to read noindex. Blocking all crawling hides that directive.
        $files['robots.txt'] = "User-agent: *\nAllow: /\nDisallow: /admin/\nDisallow: /cms/\nDisallow: /tim-kiem/\nDisallow: /cam-on/\n" . ($test ? "# Test environment: HTML sends noindex.\n" : 'Sitemap: ' . $siteUrl . "/sitemap.xml\n");
        $files['.htaccess'] = cms_redirects_htaccess($store, $settings);
        $remove = [];
        foreach ($oldLive['documents'] as $key => $old) {
            $kind = str_starts_with($key, 'posts/') ? 'posts' : 'pages';
            $oldPath = cms_document_path($old, $kind);
            if (!isset($files[$oldPath])) {
                $remove[] = $oldPath;
            }
        }
        foreach (($oldLive['generated'] ?? []) as $oldPath) {
            if (!isset($files[$oldPath]) && preg_match('~^(?:blog/|category/|tag/)~', (string)$oldPath)) {
                $remove[] = $oldPath;
            }
        }
        foreach (['blog', 'category', 'tag'] as $archiveFolder) {
            $folderPath = $store->siteRoot . '/' . $archiveFolder;
            if (!is_dir($folderPath) || is_link($folderPath)) { continue; }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folderPath, FilesystemIterator::SKIP_DOTS)) as $file) {
                $relative = substr($file->getPathname(), strlen($store->siteRoot) + 1);
                if ($file->isFile() && !$file->isLink() && str_ends_with($relative, '/index.html') && !isset($files[$relative])) {
                    $remove[] = $relative;
                }
            }
        }
        $remove = array_values(array_unique($remove));
        $live['generated'] = array_keys($files);
        $live['publishedAt'] = date(DATE_ATOM);
        $release = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $backup = $store->dataDir . '/releases/' . $release;
        if (!mkdir($backup, 0700, true)) {
            throw new RuntimeException('Không tạo được bản sao lưu trước xuất bản.');
        }
        $operations = array_values(array_unique(array_merge(array_keys($files), $remove)));
        $before = [];
        foreach ($operations as $relative) {
            $target = cms_target($store, $relative);
            $before[$relative] = is_file($target);
            if ($before[$relative]) {
                $saved = $backup . '/' . $relative;
                if (!is_dir(dirname($saved))) {
                    mkdir(dirname($saved), 0700, true);
                }
                if (!copy($target, $saved)) {
                    throw new RuntimeException('Không sao lưu được file trước xuất bản.');
                }
                chmod($saved, 0600);
            }
        }
        cms_private_json($backup . '/manifest.json', ['release' => $release, 'files' => $before, 'live' => $oldLive, 'settings' => $settings]);
        $applied = [];
        try {
            foreach ($files as $relative => $content) {
                $target = cms_target($store, $relative, true);
                cms_write_public($target, $content);
                $applied[] = $relative;
            }
            foreach ($remove as $relative) {
                $target = cms_target($store, $relative);
                if (is_file($target) && !unlink($target)) {
                    throw new RuntimeException('Không gỡ được URL đã bỏ xuất bản.');
                }
                $applied[] = $relative;
            }
            cms_private_json($store->dataDir . '/published.json', $live);
            if ($selected && ($selected['status'] ?? '') !== 'trash') {
                $store->save($collection, $selected);
            }
        } catch (Throwable $error) {
            $rollbackErrors = [];
            foreach (array_reverse($applied) as $relative) {
                try {
                    $target = cms_target($store, $relative, true);
                    if ($before[$relative]) {
                        cms_write_public($target, (string)file_get_contents($backup . '/' . $relative));
                    } elseif (is_file($target) && !unlink($target)) {
                        $rollbackErrors[] = $relative;
                    }
                } catch (Throwable $ignored) {
                    $rollbackErrors[] = $relative;
                }
            }
            cms_private_json($store->dataDir . '/published.json', $oldLive);
            if ($rollbackErrors) {
                throw new RuntimeException('Xuất bản thất bại; cần khôi phục thủ công các file: ' . implode(', ', $rollbackErrors) . '. Bản sao lưu: ' . $release, 0, $error);
            }
            throw new RuntimeException('Xuất bản thất bại, đã hoàn tác các file. ' . $error->getMessage(), 0, $error);
        }
        $store->audit('content.publish', null, ['release' => $release, 'files' => count($files), 'selected' => $payload['id'] ?? null]);
        return ['release' => $release, 'files' => count($files), 'posts' => count($posts), 'url' => rtrim((string)($settings['publishUrl'] ?? $siteUrl), '/') . '/', 'message' => 'Đã xuất bản và lưu bản sao trước thay đổi.'];
    });
}
