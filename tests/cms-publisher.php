<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('CMS_INTERNAL', true);
require_once dirname(__DIR__) . '/cms/lib/Store.php';
require_once dirname(__DIR__) . '/cms/lib/Publisher.php';

function publisher_assert(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
    echo "PASS: $message\n";
}

function publisher_remove_tree(string $path): void
{
    if (!is_dir($path)) { return; }
    foreach (new DirectoryIterator($path) as $entry) {
        if ($entry->isDot()) { continue; }
        $name = $entry->getPathname();
        if ($entry->isDir() && !$entry->isLink()) { publisher_remove_tree($name); } else { unlink($name); }
    }
    rmdir($path);
}

/** Optional corpus regression, run with --real-content. Copies only text assets into a disposable root. */
function publisher_real_content(): void
{
    $repo = dirname(__DIR__);
    $temp = sys_get_temp_dir() . '/lazi-real-content-' . bin2hex(random_bytes(8));
    $root = $temp . '/site';
    mkdir($root, 0700, true);
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repo, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $relative = substr($file->getPathname(), strlen($repo) + 1);
        if (preg_match('~^(?:\.git|admin|cms|tests|uploads|assets)/~', $relative) || $file->isLink() || !preg_match('/\.(?:html|json|xml|css|js)$|^\.htaccess$/', $relative)) { continue; }
        $target = $root . '/' . $relative;
        if (!is_dir(dirname($target))) { mkdir(dirname($target), 0700, true); }
        copy($file->getPathname(), $target);
    }
    try {
        $store = new CMSStore($root, ['dataDir' => $temp . '/private']);
        $expected = count(json_decode((string)file_get_contents($repo . '/data/search-index.json'), true, 512, JSON_THROW_ON_ERROR));
        $toolBefore = (string)file_get_contents($root . '/su-viet/index.html');
        preg_match('~<main\b.*?</main>~s', $toolBefore, $toolMainBefore);
        preg_match_all('~<script\b.*?</script>~s', $toolBefore, $toolScriptsBefore);
        $homeBefore = cms_dom(cms_resolve_source_urls((string)file_get_contents($root . '/index.html'), '/'));
        $homeMainBefore = cms_inner(cms_xpath_first(new DOMXPath($homeBefore), '//main'));
        $sourceVerification = [
            'google-site-verification' => cms_meta(new DOMXPath($homeBefore), 'google-site-verification'),
            'msvalidate.01' => cms_meta(new DOMXPath($homeBefore), 'msvalidate.01'),
        ];
        // Mirrors the production configuration command, which persists blank default SEO fields.
        $store->save('settings', $store->settings());
        $import = cms_import_existing($store);
        publisher_assert($import['imported']['posts'] === $expected && $expected === 59, 'Real corpus imports all 59 posts');
        publisher_assert($import['imported']['pages'] === 6, 'Real corpus imports home, contact, about and legal pages');
        $importedSettings = $store->settings();
        publisher_assert($sourceVerification['google-site-verification'] !== '' && $sourceVerification['msvalidate.01'] !== '' && $importedSettings['seo']['googleVerification'] === $sourceVerification['google-site-verification'] && $importedSettings['seo']['bingVerification'] === $sourceVerification['msvalidate.01'], 'Existing Google and Bing verification migrate from real homepage despite saved blank defaults');
        $articleBefore = [];
        foreach (cms_read_live($store)['documents'] as $key => $record) {
            if (!str_starts_with($key, 'posts/')) { continue; }
            $doc = cms_dom((string)file_get_contents($root . '/' . $record['slug'] . '/index.html'));
            $xp = new DOMXPath($doc);
            $article = cms_xpath_first($xp, '//article[contains(@class,"article-content")]');
            $articleBefore[$record['id']] = [
                'h2' => $xp->query('.//h2', $article)->length,
                'table' => $xp->query('.//table', $article)->length,
                'img' => $xp->query('.//img', $article)->length,
                'iframe' => $xp->query('.//iframe', $article)->length,
                'text' => trim((string)preg_replace('/\s+/u', ' ', $article->textContent)),
            ];
        }
        $release = cms_publish($store, []);
        publisher_assert($release['posts'] === 59, 'Real corpus publication includes all imported live posts');
        $badUrls = []; $missingMedia = []; $lost = []; $missingNoindex = []; $badSchema = [];
        foreach (cms_read_live($store)['documents'] as $key => $record) {
            $collection = str_starts_with($key, 'posts/') ? 'posts' : 'pages';
            $relative = cms_document_path($record, $collection);
            $html = (string)file_get_contents($root . '/' . $relative);
            $doc = cms_dom($html);
            $xp = new DOMXPath($doc);
            if (cms_meta($xp, 'robots') !== 'noindex,follow') { $missingNoindex[] = $relative; }
            foreach ($xp->query('//*[@href or @src]') as $node) {
                foreach (['href','src'] as $attr) {
                    $url = $node->getAttribute($attr);
                    if (str_starts_with($url, '../') || str_starts_with($url, './')) { $badUrls[] = $relative . ':' . $url; }
                    if (str_starts_with($url, '/assets/')) {
                        $local = $repo . (string)parse_url($url, PHP_URL_PATH);
                        if (!is_file($local)) { $missingMedia[] = $relative . ':' . $url; }
                    }
                }
            }
            $schemaNode = cms_xpath_first($xp, '//script[@type="application/ld+json"]');
            $schema = json_decode($schemaNode?->textContent ?? '', true, 512, JSON_THROW_ON_ERROR);
            if (($schema['@graph'][1]['name'] ?? '') !== $record['title']) { $badSchema[] = $relative; }
            if ($collection === 'posts') {
                $article = cms_xpath_first($xp, '//article[contains(@class,"article-content")]');
                foreach (['h2','table','img','iframe'] as $tag) {
                    if ($xp->query('.//' . $tag, $article)->length < $articleBefore[$record['id']][$tag]) { $lost[] = $relative . ':' . $tag; }
                }
                $text = trim((string)preg_replace('/\s+/u', ' ', $article->textContent));
                if ($text !== $articleBefore[$record['id']]['text']) { $lost[] = $relative . ':text'; }
            }
        }
        publisher_assert(!$badUrls, 'Imported article assets and links use root-relative URLs: ' . implode(', ', array_slice($badUrls, 0, 3)));
        publisher_assert(!$missingMedia, 'All local referenced media still exist in source repository: ' . implode(', ', array_slice($missingMedia, 0, 3)));
        publisher_assert(!$missingNoindex, 'Every managed real page sends test noindex');
        publisher_assert(!$badSchema, 'Every real page retains exact Vietnamese schema name');
        publisher_assert(!$lost, 'Real article text, headings, tables, images and embeds are retained: ' . implode(', ', array_slice($lost, 0, 5)));
        $homeAfter = cms_dom((string)file_get_contents($root . '/index.html'));
        $homeMainAfter = cms_inner(cms_xpath_first(new DOMXPath($homeAfter), '//main'));
        foreach ([$homeBefore, $homeAfter] as $homeDoc) {
            $homeXp = new DOMXPath($homeDoc);
            foreach (iterator_to_array($homeXp->query('//*[contains(concat(" ",normalize-space(@class)," ")," la-faq-section ")]')) as $faqSection) { $faqSection->parentNode->removeChild($faqSection); }
        }
        $homeOutsideBefore = cms_inner(cms_xpath_first(new DOMXPath($homeBefore), '//main'));
        $homeOutsideAfter = cms_inner(cms_xpath_first(new DOMXPath($homeAfter), '//main'));
        publisher_assert($homeOutsideBefore === $homeOutsideAfter, 'Real homepage main outside the managed FAQ is preserved exactly after URL normalization');
        foreach (['index.html', 'blog/index.html'] as $verifiedPage) {
            $verifiedXp = new DOMXPath(cms_dom((string)file_get_contents($root . '/' . $verifiedPage)));
            publisher_assert(cms_meta($verifiedXp, 'google-site-verification') === $sourceVerification['google-site-verification'] && cms_meta($verifiedXp, 'msvalidate.01') === $sourceVerification['msvalidate.01'], 'Original Google and Bing verification survive first global publication: ' . $verifiedPage);
        }
        $toolAfter = (string)file_get_contents($root . '/su-viet/index.html');
        preg_match('~<main\b.*?</main>~s', $toolAfter, $toolMainAfter);
        preg_match_all('~<script\b.*?</script>~s', $toolAfter, $toolScriptsAfter);
        publisher_assert($toolMainBefore === $toolMainAfter && $toolScriptsBefore === $toolScriptsAfter, 'Real Sử Việt main and original scripts remain byte-identical');
        publisher_assert(str_contains($toolAfter, '<meta name="robots" content="noindex,follow">'), 'Global test noindex covers unmanaged tools');
        $settings = $store->settings();
        $settings['header']['menu'][] = ['label' => 'Mục thử nghiệm', 'url' => '/thu-nghiem/'];
        $settings['footer']['description'] = 'Footer được cập nhật toàn website';
        $store->save('settings', $settings);
        $store->save('snippets', ['name' => 'Global test', 'enabled' => true, 'allowTest' => true, 'position' => 'head', 'scope' => 'all', 'code' => '<!-- GLOBAL-TOOL-SNIPPET -->']);
        cms_publish($store, []);
        $toolUpdated = (string)file_get_contents($root . '/su-viet/index.html');
        preg_match('~<main\b.*?</main>~s', $toolUpdated, $toolMainUpdated);
        preg_match_all('~<script\b.*?</script>~s', $toolUpdated, $toolScriptsUpdated);
        publisher_assert($toolMainBefore === $toolMainUpdated && $toolScriptsBefore === $toolScriptsUpdated, 'Global chrome edits preserve exact Sử Việt main and application scripts');
        publisher_assert(str_contains($toolUpdated, 'Mục thử nghiệm') && str_contains($toolUpdated, 'Footer được cập nhật toàn website') && str_contains($toolUpdated, 'GLOBAL-TOOL-SNIPPET'), 'Header, footer and global snippets update custom tool pages');
        cms_publish($store, []);
        publisher_assert(substr_count((string)file_get_contents($root . '/su-viet/index.html'), 'GLOBAL-TOOL-SNIPPET') === 1, 'Repeated global publication does not duplicate snippets');
        $settings = $store->settings();
        $settings['seo']['googleVerification'] = 'administrator-override';
        $settings['seo']['bingVerification'] = '';
        $store->save('settings', $settings);
        cms_import_existing($store);
        $retained = $store->settings();
        publisher_assert($retained['seo']['googleVerification'] === 'administrator-override' && $retained['seo']['bingVerification'] === '', 'Repeated import preserves saved override and intentionally cleared verification');
        echo "Real-corpus checks passed.\n";
    } finally {
        if (isset($store)) { $store->db->exec('PRAGMA wal_checkpoint(TRUNCATE); PRAGMA journal_mode=DELETE;'); $store->db = new PDO('sqlite::memory:'); }
        unset($store);
        publisher_remove_tree($temp);
    }
}

$temp = sys_get_temp_dir() . '/lazi-publisher-' . bin2hex(random_bytes(8));
$root = $temp . '/site';
mkdir($root . '/data', 0700, true);
mkdir($root . '/bai-mot', 0700, true);
mkdir($root . '/bai-hai', 0700, true);
mkdir($root . '/category/du-lich', 0700, true);
mkdir($root . '/su-viet', 0700, true);
$fixture = static fn(string $title, string $content): string => '<!doctype html><html lang="vi"><head><meta charset="utf-8"><title>' . $title . '</title><meta name="description" content="Mô tả gốc"><meta name="google-site-verification" content="original-token"><link rel="stylesheet" href="../style.css"><script src="../script.js" defer></script></head><body><header class="la-header"><a href="../">Header cũ</a></header><main id="main"><h1>' . $title . '</h1><p class="ls-deck">Mô tả gốc</p><article class="ls-reading-body article-content">' . $content . '</article></main><footer class="la-footer"><form id="original-newsletter"><input name="email"></form>Footer cũ</footer></body></html>';
file_put_contents($root . '/bai-mot/index.html', $fixture('Bài một', '<h2 id="muc">Điểm đến</h2><p>Nội dung gốc một.</p><a href="../tag/ha-giang/">Hà Giang</a>'));
file_put_contents($root . '/bai-hai/index.html', $fixture('Bài hai', '<p>Nội dung gốc hai.</p>'));
file_put_contents($root . '/index.html', '<!doctype html><html><head><title>Lazi Home</title><script type="application/ld+json">{"@type":"FAQPage","mainEntity":[]}</script></head><body><header class="la-header">Header cũ</header><main id="main" data-original="keep"><h1>Trang chủ</h1><form id="home-form"><input name="search"></form><section id="complex-home-block">Khối phức tạp</section><section class="la-inner la-faq-section"><div class="la-faq-heading"><h2 id="faq-title">Bạn muốn biết?</h2></div><div class="la-faq"><details><summary>Đi đâu?</summary><div class="la-faq-answer"><p>Đi <a href="/bai-mot/">Hà Giang.</a></p></div></details></div></section></main><footer class="la-footer">Footer cũ</footer></body></html>');
file_put_contents($root . '/category/du-lich/index.html', '<html><head><title>Du lịch</title></head><body><h1>Du lịch</h1></body></html>');
file_put_contents($root . '/su-viet/index.html', 'TOOL-DO-NOT-OVERWRITE');
file_put_contents($root . '/sitemap.xml', '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://lazivoyage.com/su-viet/</loc></url></urlset>');
file_put_contents($root . '/.htaccess', "# EXISTING HOST RULE\nOptions -Indexes\n");
file_put_contents($root . '/data/search-index.json', json_encode([
    ['id' => 1, 'path' => '/bai-mot/', 'title' => 'Bài một', 'excerpt' => 'Mô tả một', 'category' => 'Du lịch', 'date' => '2026-01-01T00:00:00Z', 'cover' => ['src' => '/assets/test.webp', 'alt' => 'Ảnh một']],
    ['id' => 2, 'path' => '/bai-hai/', 'title' => 'Bài hai', 'excerpt' => 'Mô tả hai', 'category' => 'Du lịch', 'date' => '2026-01-02T00:00:00Z', 'cover' => ['src' => '', 'alt' => '']],
], JSON_UNESCAPED_UNICODE));

try {
    $store = new CMSStore($root, ['dataDir' => $temp . '/private']);
    $before = hash_file('sha256', $root . '/bai-mot/index.html');
    $result = cms_import_existing($store);
    publisher_assert($result['imported']['posts'] === 2 && $result['imported']['pages'] === 1, 'Import posts and homepage with original URLs');
    publisher_assert(hash_file('sha256', $root . '/bai-mot/index.html') === $before, 'Import does not modify public HTML');
    $initialFaq = $store->settings()['homeFaq'];
    publisher_assert($initialFaq['initialized'] && count($initialFaq['items']) === 1 && $initialFaq['items'][0]['question'] === 'Đi đâu?' && $initialFaq['items'][0]['answer'] === 'Đi Hà Giang.', 'Explicit site import initializes homepage FAQ from visible questions and plain answers');
    publisher_assert(cms_import_existing($store)['imported']['posts'] === 0, 'Import is idempotent');
    $faqSettings = $store->settings();
    $brand = CMSStore::brandSeo();
    $faqSettings['homeFaq'] = ['initialized' => true, 'enabled' => true, 'title' => 'Lazi giải đáp', 'items' => [
        ['id' => 'faq-later', 'question' => 'Câu sau?', 'answer' => 'Trả lời sau.', 'enabled' => true, 'order' => 5],
        ['id' => 'faq-first', 'question' => 'Câu đầu?', 'answer' => 'Câu trả lời <script>được coi là văn bản</script>.', 'enabled' => true, 'order' => 0],
        ['id' => 'faq-hidden', 'question' => 'Câu ẩn?', 'answer' => 'Không được xuất bản.', 'enabled' => false, 'order' => 1],
    ]];
    $faqSettings['seo'] = [...$faqSettings['seo'], 'homeTitle' => $brand['homeTitle'], 'description' => $brand['description'], 'longDescription' => $brand['longDescription'], 'focusKeywords' => $brand['focusKeywords']];
    $faqSettings['aio']['about'] = $brand['about'];
    $faqSettings = $store->save('settings', $faqSettings);
    $faqVersionBefore = $faqSettings['version'];
    $faqReimport = cms_import_home_faq($store);
    publisher_assert($faqReimport['record']['homeFaq'] === $faqSettings['homeFaq'] && $faqReimport['record']['version'] === $faqVersionBefore, 'Repeated FAQ import never overwrites saved edits or creates a revision');
    $posts = $store->all('posts');
    $one = array_values(array_filter($posts, fn($p) => $p['slug'] === 'bai-mot'))[0];
    $two = array_values(array_filter($posts, fn($p) => $p['slug'] === 'bai-hai'))[0];
    $one['title'] = 'Tiêu đề bản nháp';
    $one['content'] = '<p>Nội dung bản nháp.</p><script>alert(1)</script><a href="javascript:alert(1)">Link</a>';
    $one['status'] = 'draft';
    $one['aio'] = ['summary' => 'Tóm tắt có kiểm chứng.', 'sources' => [['title' => 'Nguồn', 'url' => 'https://example.com/source']], 'faq' => [['question' => 'Đi đâu?', 'answer' => 'Đi Hà Giang.']], 'reviewedAt' => '2026-10-28'];
    $one = $store->save('posts', $one);
    $two['content'] = '<p>SECRET-UNPUBLISHED-DRAFT</p>';
    $two['status'] = 'draft';
    $store->save('posts', $two);
    publisher_assert(!str_contains($one['content'], '<script') && !str_contains($one['content'], 'javascript:'), 'Unsafe content is removed');
    $preview = html_entity_decode(cms_preview($store, ['collection' => 'posts', 'id' => $one['id']]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    publisher_assert(str_contains($preview, 'Tiêu đề bản nháp') && !str_contains($preview, 'script.js'), 'Authenticated preview shows draft without active scripts');
    cms_publish($store, []);
    $full = html_entity_decode((string)file_get_contents($root . '/bai-mot/index.html'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    publisher_assert(str_contains($full, 'Bài một') && !str_contains($full, 'Tiêu đề bản nháp'), 'Global publish uses live snapshots, not saved drafts');
    publisher_assert(str_contains((string)file_get_contents($root . '/index.html'), 'id="home-form"'), 'Untouched complex homepage content is preserved');
    $homeDoc = cms_dom((string)file_get_contents($root . '/index.html')); $homeXp = new DOMXPath($homeDoc);
    $homeVisibleFaq = cms_home_faq_data($homeDoc);
    publisher_assert(array_column($homeVisibleFaq['items'], 'question') === ['Câu đầu?', 'Câu sau?'] && $homeVisibleFaq['title'] === 'Lazi giải đáp', 'Homepage FAQ add/edit/order/hide controls render exactly the visible selection');
    publisher_assert($homeXp->query('//*[@id="complex-home-block"]')->item(0)?->textContent === 'Khối phức tạp' && $homeXp->query('//script[not(@type="application/ld+json")]')->length === 0, 'FAQ publication preserves unrelated homepage sections and does not execute answer markup');
    $homeGraph = json_decode(cms_xpath_first($homeXp, '//script[@type="application/ld+json"]')->textContent, true, 512, JSON_THROW_ON_ERROR)['@graph'];
    $faqGraphs = array_values(array_filter($homeGraph, fn($node) => $node['@type'] === 'FAQPage'));
    publisher_assert(count($faqGraphs) === 1 && count($faqGraphs[0]['mainEntity']) === 2 && array_column($faqGraphs[0]['mainEntity'], 'name') === array_column($homeVisibleFaq['items'], 'question') && $faqGraphs[0]['mainEntity'][0]['acceptedAnswer']['text'] === $homeVisibleFaq['items'][0]['answer'], 'Homepage FAQPage schema is unique and matches exact visible questions and answers');
    publisher_assert($homeDoc->getElementsByTagName('title')->item(0)->textContent === $brand['homeTitle'] && cms_meta($homeXp, 'description') === $brand['description'] && cms_meta($homeXp, 'og:description') === $brand['longDescription'] && cms_meta($homeXp, 'twitter:description') === $brand['longDescription'], 'Homepage short and social SEO descriptions use the shared brand preset');
    $websiteGraphs = array_values(array_filter($homeGraph, fn($node) => $node['@type'] === 'WebSite'));
    publisher_assert($homeGraph[0]['description'] === $brand['about'] && $websiteGraphs[0]['keywords'] === $brand['focusKeywords'] && $homeXp->query('//meta[@name="keywords"]')->length === 0, 'Organization and WebSite schema carry plain brand context without meta-keywords');
    $articleMeta = new DOMXPath(cms_dom((string)file_get_contents($root . '/bai-mot/index.html')));
    publisher_assert(cms_meta($articleMeta, 'description') === 'Mô tả gốc', 'Homepage SEO preset preserves individual article descriptions');
    publisher_assert(str_contains($full, 'id="original-newsletter"'), 'Unchanged footer retains existing newsletter behavior');
    $published = cms_publish($store, ['collection' => 'posts', 'id' => $one['id']]);
    $html = html_entity_decode((string)file_get_contents($root . '/bai-mot/index.html'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    publisher_assert(str_contains($html, 'Tiêu đề bản nháp') && str_contains($html, 'Nội dung bản nháp.'), 'Selected draft publishes real HTML');
    publisher_assert(str_contains($html, 'Tóm tắt có kiểm chứng.') && str_contains($html, 'Đi Hà Giang.') && str_contains($html, 'FAQPage'), 'AIO summary and FAQ match visible content and JSON-LD');
    $publishedDom = cms_dom((string)file_get_contents($root . '/bai-mot/index.html'));
    $publishedXp = new DOMXPath($publishedDom);
    $schema = json_decode(cms_xpath_first($publishedXp, '//script[@type="application/ld+json"]')->textContent, true, 512, JSON_THROW_ON_ERROR);
    publisher_assert($schema['@graph'][1]['headline'] === 'Tiêu đề bản nháp', 'Vietnamese JSON-LD retains exact Unicode text');
    publisher_assert(str_starts_with((string)file_get_contents($root . '/bai-mot/index.html'), "<!doctype html>\n<html"), 'Generated document begins with a real newline');
    publisher_assert(str_contains($html, 'content="noindex,follow"') && str_contains($html, 'https://lazivoyage.com/bai-mot/'), 'Test HTML is noindex and keeps production canonical');
    publisher_assert(!str_contains((string)file_get_contents($root . '/data/search-index.json'), 'SECRET-UNPUBLISHED-DRAFT'), 'Unrelated draft does not leak into search index');
    publisher_assert((string)file_get_contents($root . '/su-viet/index.html') === 'TOOL-DO-NOT-OVERWRITE', 'Dedicated Sử Việt tool is preserved');
    publisher_assert(str_contains((string)file_get_contents($root . '/sitemap.xml'), '/su-viet/'), 'Dedicated tool remains in sitemap');
    publisher_assert(is_file($temp . '/private/releases/' . $published['release'] . '/manifest.json'), 'Publication creates private backup and manifest');
    $settings = $store->settings();
    $settings['redirects'] = [['from' => '/duong-cu/', 'to' => '/bai-mot/', 'code' => 301]];
    $settings['seo']['googleVerification'] = 'new-verification';
    $store->save('settings', $settings);
    cms_publish($store, []);
    $access = (string)file_get_contents($root . '/.htaccess');
    publisher_assert(str_contains($access, '# EXISTING HOST RULE') && str_contains($access, 'RewriteRule ^duong\-cu/$ /bai-mot/ [R=301,L,NE]'), 'Managed redirects preserve existing server configuration');
    publisher_assert(str_contains((string)file_get_contents($root . '/bai-mot/index.html'), 'new-verification'), 'SEO verification is generated');
    $bad = false;
    try { cms_document_path(['slug' => '../admin'], 'posts'); } catch (RuntimeException $e) { $bad = true; }
    publisher_assert($bad, 'Traversal slugs cannot be published');
    $bad = false;
    try { cms_document_path(['slug' => 'admin'], 'pages'); } catch (RuntimeException $e) { $bad = true; }
    publisher_assert($bad, 'Reserved application paths cannot be published');
    $bad = false;
    try { cms_redirects_htaccess($store, ['redirects' => [['from' => '/a/', 'to' => '/b/'], ['from' => '/b/', 'to' => '/a/']]]); } catch (RuntimeException $e) { $bad = true; }
    publisher_assert($bad, 'Redirect cycles are rejected');
    $store->save('snippets', ['name' => 'Production only', 'enabled' => true, 'position' => 'head', 'scope' => 'all', 'code' => '<meta name="production-only" content="yes">', 'order' => 0]);
    $store->save('snippets', ['name' => 'First allowed', 'enabled' => true, 'allowTest' => true, 'position' => 'body_start', 'scope' => 'all', 'code' => '<!-- FIRST-ALLOWED -->', 'order' => 1]);
    $store->save('snippets', ['name' => 'Second allowed', 'enabled' => true, 'allowTest' => true, 'position' => 'body_start', 'scope' => 'all', 'code' => '<!-- SECOND-ALLOWED -->', 'order' => 2]);
    cms_publish($store, []);
    $snippetHtml = (string)file_get_contents($root . '/bai-mot/index.html');
    publisher_assert(!str_contains($snippetHtml, 'production-only'), 'Tracking snippets default to disabled on test environment');
    publisher_assert(strpos($snippetHtml, 'FIRST-ALLOWED') < strpos($snippetHtml, 'SECOND-ALLOWED'), 'Body-start snippets respect ascending order');
    publisher_assert(str_contains((string)file_get_contents($root . '/blog/index.html'), 'FIRST-ALLOWED'), 'Global snippets apply to public archives');
    publisher_assert(!str_contains(cms_preview($store, ['collection' => 'posts', 'id' => $one['id']]), 'FIRST-ALLOWED'), 'Preview never injects trusted code snippets');
    $faqDisabled = $store->settings(); $faqDisabled['homeFaq']['enabled'] = false; $store->save('settings', $faqDisabled); cms_publish($store, []);
    $disabledHome = (string)file_get_contents($root . '/index.html');
    publisher_assert(!str_contains($disabledHome, 'la-faq-section') && !str_contains($disabledHome, 'FAQPage') && str_contains($disabledHome, 'complex-home-block'), 'Disabling homepage FAQ removes its section and schema while preserving other content');
    $faqRemoved = $store->settings(); $faqRemoved['homeFaq']['enabled'] = true; $faqRemoved['homeFaq']['items'] = []; $store->save('settings', $faqRemoved); cms_publish($store, []);
    publisher_assert(!str_contains((string)file_get_contents($root . '/index.html'), 'FAQPage'), 'Removing all homepage questions removes stale FAQ schema');
    $publicBeforeFailure = hash_file('sha256', $root . '/bai-mot/index.html');
    $snapshotBeforeFailure = hash_file('sha256', $temp . '/private/published.json');
    rename($root . '/blog/index.html', $temp . '/blog-before.html');
    mkdir($root . '/blog/index.html', 0700);
    $failed = false;
    set_error_handler(static fn() => true);
    try { cms_publish($store, []); } catch (RuntimeException $error) { $failed = true; } finally { restore_error_handler(); }
    publisher_assert($failed && hash_file('sha256', $root . '/bai-mot/index.html') === $publicBeforeFailure && hash_file('sha256', $temp . '/private/published.json') === $snapshotBeforeFailure, 'A mid-publication write failure restores public files and live snapshots');
    rmdir($root . '/blog/index.html');
    rename($temp . '/blog-before.html', $root . '/blog/index.html');
    echo "All publisher checks passed.\n";
} finally {
    if (isset($store)) {
        $store->db->exec('PRAGMA wal_checkpoint(TRUNCATE); PRAGMA journal_mode=DELETE;');
        $store->db = new PDO('sqlite::memory:');
    }
    unset($store);
    publisher_remove_tree($temp);
}

if (in_array('--real-content', $argv ?? [], true)) { publisher_real_content(); }

// Existing-image import regression checks share the publisher test entry point.
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
