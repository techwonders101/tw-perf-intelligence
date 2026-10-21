<?php
// Run with: php tests/font-display-test.php
$root = sys_get_temp_dir() . '/twperf-font-test-' . bin2hex(random_bytes(6));
mkdir($root . '/assets/css', 0777, true);
define('ABSPATH', $root . '/');
define('WEEK_IN_SECONDS', 604800);
function site_url() { return 'https://example.test'; }
function untrailingslashit($s) { return rtrim($s, '/'); }
function wp_parse_url($s, $component) { return parse_url($s, $component); }
$cache = [];
function get_transient($key) { global $cache; return $cache[$key] ?? false; }
function set_transient($key, $value, $ttl) { global $cache; $cache[$key] = $value; }
require dirname(__DIR__) . '/includes/class-html-optimizer.php';
$optimizer = new TW_Perf_HTML_Optimizer();
$method = new ReflectionMethod($optimizer, 'apply_font_display');
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$file = $root . '/assets/css/fonts.css';
try {
    $css = <<<'CSS'
@font-face{font-family:star;src:url("../fonts/star.woff2?v=1#star"),url('../fonts/star.ttf'),url(../fonts/star.woff);font-style:normal}
@font-face{font-family:other;src:url("https://cdn.test/font.woff2"),url(//cdn.test/font.woff),url(/fonts/root.woff),url("data:font/woff;base64,AAAA"),url("font (1).woff"),url(?v=2),url(#font)}
CSS;
    file_put_contents($file, $css);
    $old = 'twperf_ffd_' . md5($file . '@' . filemtime($file));
    $cache[$old] = 'BROKEN_OLD_CACHE';
    $html = '<head><link rel="stylesheet" href="https://example.test/assets/css/fonts.css?v=1&amp;x=2"><style>@font-face{font-family:inline;src:url(inline.woff)}</style></head>';
    $output = $method->invoke($optimizer, $html);
    foreach ([
        'https://example.test/assets/css/../fonts/star.woff2?v=1#star',
        'https://example.test/assets/css/../fonts/star.ttf',
        'https://example.test/assets/css/../fonts/star.woff',
        'https://cdn.test/font.woff2', '//cdn.test/font.woff', '/fonts/root.woff',
        'data:font/woff;base64,AAAA', 'https://example.test/assets/css/font (1).woff',
        'https://example.test/assets/css/fonts.css?v=2',
        'https://example.test/assets/css/fonts.css?v=1&x=2#font',
        'font-style:normal;font-display:swap;', 'src:url(inline.woff)',
    ] as $expected) check(str_contains($output, $expected), 'Missing: ' . $expected);
    check(!str_contains($output, 'BROKEN_OLD_CACHE'), 'Old cache reused');
    check($method->invoke($optimizer, $html) === $output, 'Warm cache differs');
    $rootRelative = $method->invoke($optimizer, str_replace('https://example.test/assets', '/assets', $html));
    check(str_contains($rootRelative, 'url("/assets/css/../fonts/star.woff2?v=1#star")'), 'Root-relative CSS base lost');
    echo "PASS: external font paths, URL variants, inline CSS, declaration separator, old-cache invalidation and warm cache\n";
} finally {
    unlink($file);
    rmdir($root . '/assets/css');
    rmdir($root . '/assets');
    rmdir($root);
}
