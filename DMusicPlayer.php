<?php
/**
 * DMusicPlayer.php — Music library home + audio file inspector
 * Usage: DMusicPlayer.php            (trang chủ: thư viện, URL, offline)
 *        DMusicPlayer.php?file=myfile.mp3
 * Requires PHP 7.4+ and ffmpeg + ffprobe on PATH. No Composer dependencies.
 *
 * Reads container/codec info via ffprobe (bitrate, sample rate, duration,
 * tags), pulls the embedded cover art via ffmpeg, and renders a themed
 * info card. Requires ffmpeg + ffprobe on PATH. No Composer dependencies.
 */

declare(strict_types=1);

// ---- Config -----------------------------------------------------------
// Folder the script is allowed to serve files from. Change this to your
// music/media folder if DMusicPlayer.php doesn't live next to the audio files.
define('MEDIA_DIR', __DIR__);

define('ALLOWED_EXT', ['mp3', 'm4a', 'flac', 'wav', 'ogg', 'oga', 'opus', 'wma', 'aac']);

// ---- Helpers ------------------------------------------------------------

function human_filesize(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $size = (float) $bytes;
    while ($size >= 1024 && $i < count($units) - 1) {
        $size /= 1024;
        $i++;
    }
    return ($i === 0 ? (string) $bytes : number_format($size, 2)) . ' ' . $units[$i];
}

function human_duration(?float $seconds): string
{
    if ($seconds === null) {
        return '—';
    }
    $seconds = (int) round($seconds);
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    return $h > 0
        ? sprintf('%d:%02d:%02d', $h, $m, $s)
        : sprintf('%d:%02d', $m, $s);
}

/** Case-insensitive lookup across a tags array, trying several key aliases. */
function tag(array $tags, array $keys): ?string
{
    $lower = [];
    foreach ($tags as $k => $v) {
        $lower[strtolower($k)] = $v;
    }
    foreach ($keys as $key) {
        $key = strtolower($key);
        if (isset($lower[$key]) && $lower[$key] !== '') {
            return (string) $lower[$key];
        }
    }
    return null;
}

function tool_available(string $bin): bool
{
    $which = trim((string) shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null'));
    return $which !== '';
}

function render_error(string $message): void
{
    http_response_code(400);
    ?>
    <!doctype html>
    <html lang="vi">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <title>Không thể xem trước</title>
        <style>
            body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
                background:#16151c;color:#f1ede4;font-family:system-ui,-apple-system,sans-serif;}
            .box{max-width:420px;padding:32px 36px;background:#201f29;border-radius:14px;
                border:1px solid #322f3c;text-align:center;}
            .box h1{margin:0 0 10px;font-size:18px;color:#e8934a;}
            .box p{margin:0;line-height:1.5;font-size:14px;color:#a9a4b3;}
        </style>
    </head>
    <body>
        <div class="box">
            <h1>Không thể tạo bản xem trước</h1>
            <p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

function media_relative_path(string $requested): string
{
    $candidate = trim((string) $requested);
    $candidate = str_replace('\\', '/', $candidate);
    $candidate = preg_replace('#/+#', '/', $candidate) ?? $candidate;

    if ($candidate === '' || $candidate === '.' || $candidate === '/') {
        throw new InvalidArgumentException('Đường dẫn tệp không hợp lệ.');
    }

    if (preg_match('/^([A-Za-z]:|\\\\|\/)/', $candidate) === 1) {
        throw new InvalidArgumentException('Đường dẫn tuyệt đối không được phép.');
    }

    $parts = [];
    foreach (explode('/', $candidate) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            if ($parts !== []) {
                array_pop($parts);
            }
            continue;
        }
        $parts[] = $segment;
    }

    return implode('/', $parts);
}

function is_within_root(string $fullPath, string $rootPath): bool
{
    $normalizedRoot = rtrim(str_replace('\\', '/', $rootPath), '/');
    $normalizedFull = str_replace('\\', '/', $fullPath);

    return $normalizedFull === $normalizedRoot || strpos($normalizedFull, $normalizedRoot . '/') === 0;
}

function is_remote_media_url(string $value): bool
{
    return preg_match('#^https?://#i', $value) === 1;
}

function fetch_remote_stream(string $url): ?array
{
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 30,
            'ignore_errors' => true,
            'header' => "Accept: */*\r\nUser-Agent: DMusicPlayer/1.0\r\n",
        ],
        'https' => [
            'method' => 'GET',
            'timeout' => 30,
            'ignore_errors' => true,
            'header' => "Accept: */*\r\nUser-Agent: DMusicPlayer/1.0\r\n",
        ],
    ]);

    $body = @file_get_contents($url, false, $ctx);
    if ($body === false || $body === '') {
        return null;
    }

    $headers = $http_response_header ?? [];
    $contentType = 'application/octet-stream';
    $contentLength = null;
    foreach ($headers as $header) {
        $lower = strtolower($header);
        if (stripos($lower, 'content-type:') === 0) {
            $contentType = trim(substr($header, strlen('Content-Type:')));
        }
        if (stripos($lower, 'content-length:') === 0) {
            $contentLength = (int) trim(substr($header, strlen('Content-Length:')));
        }
    }

    return [
        'content_type' => $contentType,
        'content_length' => $contentLength,
        'body' => $body,
    ];
}

/** Scan MEDIA_DIR for audio files (hidden entries skipped, depth-limited). */
function scan_media_library(string $root, int $limit = 2000): array
{
    $rootReal = realpath($root);
    if ($rootReal === false) {
        return [];
    }
    $rootNorm = rtrim(str_replace('\\', '/', $rootReal), '/');
    $items = [];

    try {
        $dirIt = new RecursiveDirectoryIterator($rootReal, FilesystemIterator::SKIP_DOTS);
        $filter = new RecursiveCallbackFilterIterator($dirIt, function ($current) {
            return substr($current->getFilename(), 0, 1) !== '.';
        });
        $it = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST);
        $it->setMaxDepth(6);

        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $ext = strtolower($file->getExtension());
            if (!in_array($ext, ALLOWED_EXT, true)) {
                continue;
            }
            $full = str_replace('\\', '/', $file->getPathname());
            $rel = ltrim(substr($full, strlen($rootNorm)), '/');
            $dir = dirname($rel);
            $items[] = [
                'path'  => $rel,
                'title' => pathinfo($rel, PATHINFO_FILENAME),
                'dir'   => $dir === '.' ? '' : $dir,
                'ext'   => strtoupper($ext),
                'size'  => (int) $file->getSize(),
            ];
            if (count($items) >= $limit) {
                break;
            }
        }
    } catch (Throwable $e) {
        // Unreadable folder — return whatever was collected.
    }

    usort($items, function ($a, $b) {
        return strnatcasecmp($a['path'], $b['path']);
    });
    return $items;
}

function render_home(): void
{
    $library = scan_media_library(MEDIA_DIR);
    $json = json_encode($library, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    ?>
<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title>DMusicPlayer — Trang chủ</title>
<link rel="manifest" href="DMusicPlayer.php?manifest=1">
<meta name="theme-color" content="#16151c">
<link rel="apple-touch-icon" href="DMusicPlayer.php?icon=180">
<link rel="icon" type="image/png" sizes="192x192" href="DMusicPlayer.php?icon=192">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
<style>
    :root{
        --bg:#16151c; --surface:#201f29; --surface-2:#262532; --line:#322f3c;
        --amber:#e8934a; --teal:#4fa8a0; --text:#f1ede4; --text-muted:#a9a4b3;
    }
    *{box-sizing:border-box;}
    body{
        margin:0; min-height:100vh;
        padding:calc(32px + env(safe-area-inset-top)) calc(20px + env(safe-area-inset-right)) calc(150px + env(safe-area-inset-bottom)) calc(20px + env(safe-area-inset-left));
        background:
            radial-gradient(circle at 15% -10%, rgba(232,147,74,.08), transparent 45%),
            radial-gradient(circle at 100% 110%, rgba(79,168,160,.08), transparent 45%),
            var(--bg);
        color:var(--text); font-family:'Inter',system-ui,-apple-system,sans-serif;
    }
    .wrap{ max-width:760px; margin:0 auto; animation:rise .5s ease both; }
    @keyframes rise{ from{opacity:0; transform:translateY(10px);} to{opacity:1; transform:none;} }
    @media (prefers-reduced-motion: reduce){ .wrap{animation:none;} }

    .eyebrow{ font-family:'JetBrains Mono',monospace; font-size:11px; letter-spacing:.14em; color:var(--teal); text-transform:uppercase; margin-bottom:8px; }
    h1{ font-family:'Oswald',sans-serif; font-weight:600; text-transform:uppercase; font-size:clamp(26px,6vw,38px); line-height:1.1; margin:0 0 6px; }
    .lead{ margin:0 0 22px; color:var(--text-muted); font-size:14.5px; }

    .panel{ background:var(--surface); border:1px solid var(--line); border-radius:18px; padding:18px; box-shadow:0 30px 60px -30px rgba(0,0,0,.6); }
    .row{ display:flex; gap:10px; flex-wrap:wrap; }
    .field{
        flex:1; min-width:180px; border-radius:999px; border:1px solid rgba(255,255,255,.12);
        background:rgba(255,255,255,.02); color:var(--text); padding:10px 16px; font:inherit; font-size:14px; outline:none;
    }
    .field:focus{ border-color:rgba(232,147,74,.6); }
    .action{
        appearance:none; border:1px solid rgba(232,147,74,.35); background:rgba(232,147,74,.12);
        color:var(--text); border-radius:999px; padding:10px 16px; font:inherit; font-weight:600; font-size:14px; cursor:pointer;
        transition:transform .15s ease, filter .15s ease;
    }
    .action:hover{ transform:translateY(-1px); filter:brightness(1.05); }
    .action.secondary{ border-color:rgba(79,168,160,.4); background:rgba(79,168,160,.08); }

    .tabs{ display:flex; gap:8px; margin:18px 0 12px; align-items:center; }
    .tab{
        appearance:none; background:transparent; border:1px solid var(--line); color:var(--text-muted);
        border-radius:999px; padding:7px 14px; font:inherit; font-size:13px; font-weight:600; cursor:pointer;
    }
    .tab.active{ color:var(--text); border-color:rgba(232,147,74,.5); background:rgba(232,147,74,.12); }
    .count{ margin-left:auto; font-family:'JetBrains Mono',monospace; font-size:12px; color:var(--text-muted); }

    .list{ list-style:none; margin:0; padding:0; display:grid; gap:8px; }
    .item{
        display:flex; align-items:center; gap:12px; padding:10px 12px; border-radius:12px;
        background:rgba(255,255,255,.02); border:1px solid rgba(255,255,255,.04);
    }
    .item.active{ border-color:rgba(232,147,74,.5); background:rgba(232,147,74,.08); }
    .play{
        flex:0 0 auto; width:38px; height:38px; border-radius:50%; cursor:pointer;
        border:1px solid rgba(232,147,74,.4); background:rgba(232,147,74,.12); color:var(--amber); font-size:14px;
    }
    .info{ flex:1; min-width:0; }
    .info a, .info .name{
        display:block; color:var(--text); text-decoration:none; font-weight:500; font-size:14.5px;
        overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
    }
    .info a:hover{ color:var(--amber); }
    .info span{ display:block; margin-top:2px; font-size:12px; color:var(--text-muted); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .tag{ flex:0 0 auto; font-family:'JetBrains Mono',monospace; font-size:11px; color:var(--teal); }
    .ghost{ flex:0 0 auto; background:transparent; border:1px solid rgba(232,147,74,.4); color:var(--amber); border-radius:999px; padding:6px 10px; font-size:12px; cursor:pointer; }
    .empty{ color:var(--text-muted); font-size:13.5px; line-height:1.6; padding:18px 6px; text-align:center; }
    .empty code{ color:var(--amber); }

    .lead-link{ color:var(--amber); text-decoration:none; white-space:nowrap; }
    .lead-link:hover{ text-decoration:underline; }

    .guide{ margin-top:22px; scroll-margin-top:16px; }
    .guide-head{ display:flex; align-items:center; gap:12px; margin-bottom:12px; }
    .guide-head h2{ font-family:'Oswald',sans-serif; font-weight:600; text-transform:uppercase; font-size:20px; margin:0; }
    .guide-head .ghost{ margin-left:auto; }
    .guide details{ background:var(--surface); border:1px solid var(--line); border-radius:14px; margin-bottom:8px; }
    .guide summary{
        cursor:pointer; list-style:none; padding:14px 16px; font-weight:600; font-size:14.5px;
        display:flex; align-items:center; gap:12px;
    }
    .guide summary::-webkit-details-marker{ display:none; }
    .guide summary::after{ content:'+'; margin-left:auto; color:var(--amber); font-family:'JetBrains Mono',monospace; font-size:18px; }
    .guide details[open] summary::after{ content:'−'; }
    .guide details[open]{ border-color:rgba(232,147,74,.35); }
    .num{
        flex:0 0 auto; width:26px; height:26px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center;
        font-family:'JetBrains Mono',monospace; font-size:12px; font-weight:700; color:var(--amber);
        border:1px solid rgba(232,147,74,.4); background:rgba(232,147,74,.1);
    }
    .guide .body{ padding:0 18px 16px 54px; color:var(--text-muted); font-size:13.5px; line-height:1.65; }
    .guide .body p{ margin:0 0 8px; }
    .guide .body ol, .guide .body ul{ margin:0 0 8px; padding-left:20px; }
    .guide .body li{ margin-bottom:4px; }
    .guide .body b{ color:var(--text); font-weight:600; }
    .guide .body code{ font-family:'JetBrains Mono',monospace; font-size:12px; color:var(--amber); background:rgba(232,147,74,.08); padding:1px 6px; border-radius:6px; }
    .guide .note{
        margin-top:8px; padding:9px 12px; border-radius:8px; font-size:12.5px;
        background:rgba(79,168,160,.08); border:1px solid rgba(79,168,160,.3); color:var(--teal);
    }
    @media (max-width:480px){ .guide .body{ padding-left:18px; } }

    .mini{
        position:fixed; left:0; right:0; bottom:0; z-index:5; display:none;
        padding:12px calc(16px + env(safe-area-inset-right)) calc(12px + env(safe-area-inset-bottom)) calc(16px + env(safe-area-inset-left));
        background:rgba(32,31,41,.94); border-top:1px solid var(--line); backdrop-filter:blur(12px); -webkit-backdrop-filter:blur(12px);
    }
    .mini.visible{ display:block; }
    .mini-inner{ max-width:760px; margin:0 auto; }
    .mini-title{ font-weight:600; font-size:14px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .mini-sub{ font-size:12px; color:var(--text-muted); margin-bottom:8px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .mini audio{ width:100%; accent-color:var(--amber); }
</style>
</head>
<body>
<div class="wrap">
    <div class="eyebrow">DMusicPlayer</div>
    <h1>Thư viện nhạc</h1>
    <p class="lead">Chọn bài để nghe, xem thông tin tệp hoặc phát nhạc đã lưu offline. <a class="lead-link" href="#guide">Xem hướng dẫn sử dụng ↓</a></p>

    <div class="panel">
        <div class="row">
            <input id="remoteUrl" class="field" type="url" placeholder="Dán URL nhạc: https://example.com/song.mp3">
            <button type="button" id="remoteBtn" class="action secondary">Nghe từ URL</button>
        </div>

        <div class="tabs">
            <button type="button" class="tab active" data-tab="library">Thư viện</button>
            <button type="button" class="tab" data-tab="offline">Offline</button>
            <span class="count" id="count"></span>
        </div>

        <input id="search" class="field" type="search" placeholder="Tìm theo tên bài hoặc thư mục..." style="width:100%; margin-bottom:12px;">
        <ul id="list" class="list"></ul>
    </div>

    <section class="guide" id="guide">
        <div class="guide-head">
            <h2>Hướng dẫn sử dụng</h2>
            <button type="button" class="ghost" id="guideToggleAll">Mở tất cả</button>
        </div>

        <details open>
            <summary><span class="num">1</span>Thêm nhạc vào thư viện</summary>
            <div class="body">
                <ol>
                    <li>Chép các tệp nhạc vào <b>cùng thư mục với <code>DMusicPlayer.php</code></b> (có thể để trong thư mục con, ví dụ <code>album/bai-hat.mp3</code>).</li>
                    <li>Nếu nhạc nằm ở nơi khác, mở <code>DMusicPlayer.php</code> và sửa dòng <code>define('MEDIA_DIR', __DIR__);</code> sang đường dẫn thư mục nhạc của bạn.</li>
                    <li>Tải lại trang, danh sách ở tab <b>Thư viện</b> sẽ tự cập nhật.</li>
                </ol>
                <p>Định dạng hỗ trợ: <code>mp3</code> <code>m4a</code> <code>flac</code> <code>wav</code> <code>ogg</code> <code>oga</code> <code>opus</code> <code>wma</code> <code>aac</code>.</p>
                <div class="note">Trang chủ quét tối đa 6 cấp thư mục và 2000 bài, bỏ qua tệp/thư mục có tên bắt đầu bằng dấu chấm (ví dụ <code>.cache</code>). Trình duyệt có phát được hay không còn tùy định dạng (ví dụ <code>wma</code> thường không phát được trên nhiều trình duyệt).</div>
            </div>
        </details>

        <details>
            <summary><span class="num">2</span>Nghe nhạc và tìm bài</summary>
            <div class="body">
                <ul>
                    <li>Bấm nút <b>▶</b> bên trái mỗi bài để phát ngay. Trình phát nhỏ sẽ hiện cố định ở cuối màn hình, có đủ nút phát/tạm dừng, tua và âm lượng.</li>
                    <li>Khi một bài kết thúc, app tự phát <b>bài kế tiếp trong danh sách đang hiển thị</b>. Nếu bạn đang lọc bằng ô tìm kiếm, chỉ các bài khớp mới nằm trong hàng đợi.</li>
                    <li>Gõ vào ô <b>Tìm theo tên bài hoặc thư mục</b> để lọc danh sách; tìm không phân biệt hoa thường.</li>
                    <li>Bài đang phát được tô viền màu cam trong danh sách.</li>
                </ul>
            </div>
        </details>

        <details>
            <summary><span class="num">3</span>Xem chi tiết bài hát</summary>
            <div class="body">
                <p>Bấm vào <b>tên bài</b> để mở trang chi tiết, gồm ảnh bìa, tiêu đề, nghệ sĩ, album, năm phát hành, tốc độ bit, tần số lấy mẫu, định dạng, thời lượng, kích thước và thời gian sửa đổi.</p>
                <p>Dùng nút <b>← Trang chủ</b> ở đầu trang chi tiết để quay lại.</p>
                <div class="note">Thông tin thẻ (ID3), bitrate và ảnh bìa cần máy chủ cài sẵn <code>ffprobe</code> và <code>ffmpeg</code>. Nếu thiếu, trang chi tiết vẫn phát được nhạc nhưng chỉ hiện kích thước và ngày sửa đổi.</div>
            </div>
        </details>

        <details>
            <summary><span class="num">4</span>Nghe nhạc từ URL</summary>
            <div class="body">
                <ol>
                    <li>Dán đường dẫn trực tiếp tới tệp nhạc vào ô đầu trang, ví dụ <code>https://example.com/song.mp3</code>.</li>
                    <li>Bấm <b>Nghe từ URL</b> (hoặc nhấn Enter). App sẽ mở trang phát của bài đó.</li>
                </ol>
                <div class="note">URL phải bắt đầu bằng <code>http://</code> hoặc <code>https://</code> và kết thúc bằng một định dạng được hỗ trợ. Máy chủ sẽ tải toàn bộ tệp về rồi phát lại cho bạn nên bài dài có thể mất vài giây để bắt đầu; với nhạc từ URL, trang chi tiết không đọc được thẻ ID3 và ảnh bìa.</div>
            </div>
        </details>

        <details>
            <summary><span class="num">5</span>Lưu nhạc để nghe offline</summary>
            <div class="body">
                <ol>
                    <li>Mở trang chi tiết của bài muốn lưu (bấm tên bài hoặc dùng Nghe từ URL).</li>
                    <li>Bấm <b>Tải về offline</b>. Khi nút đổi thành <b>Đã lưu offline</b> là bài đã nằm trong trình duyệt của bạn.</li>
                    <li>Quay lại trang chủ, mở tab <b>Offline</b> để xem, phát (▶) hoặc <b>Xóa</b> các bài đã lưu. Trong trang chi tiết, nút <b>Offline Music</b> cũng mở danh sách này.</li>
                </ol>
                <div class="note">Nhạc được lưu trong bộ nhớ của <b>trình duyệt trên thiết bị này</b>, không đồng bộ giữa các máy và sẽ mất nếu bạn xóa dữ liệu trang web. Bài lớn (như tệp flac/wav) chiếm nhiều dung lượng. Hãy mở trang chủ ít nhất một lần khi còn mạng (và đã cài service worker qua HTTPS): app sẽ lưu bản trang chủ, sau đó dù mất mạng vẫn mở được và phát các bài trong tab Offline. Danh sách ở tab Thư viện lúc đó là bản đã lưu lần cuối.</div>
            </div>
        </details>

        <details>
            <summary><span class="num">6</span>Cài như một ứng dụng (PWA)</summary>
            <div class="body">
                <ul>
                    <li><b>Android / Chrome:</b> menu ⋮ → <b>Cài đặt ứng dụng</b> hoặc <b>Thêm vào màn hình chính</b>.</li>
                    <li><b>iPhone / Safari:</b> nút Chia sẻ → <b>Thêm vào Màn hình chính</b>.</li>
                    <li><b>Máy tính / Chrome, Edge:</b> bấm biểu tượng cài đặt ở thanh địa chỉ.</li>
                </ul>
                <p>Sau khi cài, app mở toàn màn hình và vào thẳng trang chủ này.</p>
                <div class="note">Tính năng cài đặt và lưu cache offline cần trang chạy trên <b>HTTPS</b> (hoặc <code>localhost</code> khi thử trên máy).</div>
            </div>
        </details>

        <details>
            <summary><span class="num">7</span>Gặp sự cố?</summary>
            <div class="body">
                <ul>
                    <li><b>Thư viện trống:</b> kiểm tra nhạc có nằm trong <code>MEDIA_DIR</code> không, và PHP có quyền đọc thư mục đó.</li>
                    <li><b>"Không tìm thấy tệp" / "Định dạng không được hỗ trợ":</b> kiểm tra tên tệp, đuôi tệp và đường dẫn trong URL.</li>
                    <li><b>Không có ảnh bìa hoặc thông tin bitrate:</b> máy chủ chưa có <code>ffmpeg</code>/<code>ffprobe</code>, hoặc tệp không nhúng sẵn ảnh bìa.</li>
                    <li><b>Không lưu được offline:</b> trình duyệt đang ở chế độ ẩn danh hoặc chặn IndexedDB; thử cửa sổ thường.</li>
                    <li><b>Chrome báo "Không thể cài đặt ứng dụng này":</b> trang phải chạy trên HTTPS, không ở chế độ ẩn danh và app chưa được cài. Sau khi cập nhật file, tải lại trang vài lần (hoặc xóa dữ liệu trang trong Cài đặt trang web) để Chrome nhận manifest và icon mới. Nếu vẫn chưa được, dùng <b>Tạo lối tắt</b> để thêm biểu tượng ra màn hình chính.</li>
                    <li><b>Danh sách chưa cập nhật sau khi thêm nhạc:</b> tải lại trang (Ctrl+F5 / kéo để làm mới) vì app có service worker.</li>
                </ul>
            </div>
        </details>
    </section>
</div>

<div class="mini" id="mini">
    <div class="mini-inner">
        <div class="mini-title" id="miniTitle">—</div>
        <div class="mini-sub" id="miniSub"></div>
        <audio id="player" controls preload="metadata"></audio>
    </div>
</div>

<script>
    const LIB = <?= $json ?: '[]' ?>;
    const DB_NAME = 'DMusicPlayerDB';
    const STORE_NAME = 'tracks';
    const CACHE_NAME = 'dmusic-player-audio-v1';

    const listEl = document.getElementById('list');
    const searchEl = document.getElementById('search');
    const countEl = document.getElementById('count');
    const playerEl = document.getElementById('player');
    const miniEl = document.getElementById('mini');
    const miniTitleEl = document.getElementById('miniTitle');
    const miniSubEl = document.getElementById('miniSub');

    const state = { tab: 'library', offline: [], queue: [], index: -1, currentKey: null, blobUrl: null };

    function openDatabase() {
        return new Promise((resolve, reject) => {
            if (!('indexedDB' in window)) { reject(new Error('IndexedDB không được hỗ trợ.')); return; }
            const req = indexedDB.open(DB_NAME, 1);
            req.onupgradeneeded = () => {
                if (!req.result.objectStoreNames.contains(STORE_NAME)) {
                    req.result.createObjectStore(STORE_NAME, { keyPath: 'id' });
                }
            };
            req.onsuccess = () => resolve(req.result);
            req.onerror = () => reject(req.error || new Error('Không thể mở IndexedDB.'));
        });
    }
    async function getAllSongs() {
        const db = await openDatabase();
        return new Promise((resolve, reject) => {
            const req = db.transaction(STORE_NAME, 'readonly').objectStore(STORE_NAME).getAll();
            req.onsuccess = () => resolve(req.result || []);
            req.onerror = () => reject(req.error);
        });
    }
    async function deleteSong(id) {
        const db = await openDatabase();
        return new Promise((resolve, reject) => {
            const req = db.transaction(STORE_NAME, 'readwrite').objectStore(STORE_NAME).delete(id);
            req.onsuccess = () => resolve();
            req.onerror = () => reject(req.error);
        });
    }

    function fmtSize(bytes) {
        const units = ['B', 'KB', 'MB', 'GB'];
        let i = 0, n = bytes;
        while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
        return (i === 0 ? n : n.toFixed(1)) + ' ' + units[i];
    }
    function streamUrl(path) {
        return 'DMusicPlayer.php?file=' + encodeURIComponent(path) + '&stream=1';
    }

    function buildItems() {
        const q = searchEl.value.trim().toLowerCase();
        if (state.tab === 'library') {
            return LIB
                .filter((t) => !q || t.path.toLowerCase().includes(q))
                .map((t) => ({
                    key: 'lib:' + t.path,
                    title: t.title,
                    sub: t.dir || 'Thư mục gốc',
                    tag: t.ext + ' · ' + fmtSize(t.size),
                    href: 'DMusicPlayer.php?file=' + encodeURIComponent(t.path),
                    getSrc: async () => streamUrl(t.path)
                }));
        }
        return state.offline
            .filter((s) => !q || ((s.title || '') + ' ' + (s.artist || '')).toLowerCase().includes(q))
            .map((s) => ({
                key: 'off:' + s.id,
                title: s.title || 'Không rõ tên',
                sub: s.artist || 'Không rõ nghệ sĩ',
                tag: s.size ? fmtSize(s.size) : '',
                song: s,
                getSrc: async () => URL.createObjectURL(s.audioBlob)
            }));
    }

    function render() {
        const items = buildItems();
        state.queue = items;
        listEl.innerHTML = '';
        countEl.textContent = items.length + ' bài';

        if (!items.length) {
            const li = document.createElement('li');
            li.className = 'empty';
            if (state.tab === 'offline') {
                li.textContent = searchEl.value ? 'Không tìm thấy bài phù hợp.' : 'Chưa có bài nào được lưu offline. Mở một bài và bấm "Tải về offline".';
            } else if (!LIB.length) {
                li.innerHTML = 'Chưa tìm thấy tệp nhạc nào trong thư mục này.<br>Hãy chép nhạc vào cùng thư mục với <code>DMusicPlayer.php</code> hoặc sửa hằng số <code>MEDIA_DIR</code>.';
            } else {
                li.textContent = 'Không tìm thấy bài phù hợp.';
            }
            listEl.appendChild(li);
            return;
        }

        items.forEach((item, i) => {
            const li = document.createElement('li');
            li.className = 'item' + (item.key === state.currentKey ? ' active' : '');

            const play = document.createElement('button');
            play.type = 'button';
            play.className = 'play';
            play.textContent = '▶';
            play.setAttribute('aria-label', 'Phát ' + item.title);
            play.addEventListener('click', () => playIndex(i));

            const info = document.createElement('div');
            info.className = 'info';
            if (item.href) {
                const a = document.createElement('a');
                a.href = item.href;
                a.textContent = item.title;
                info.appendChild(a);
            } else {
                const n = document.createElement('div');
                n.className = 'name';
                n.textContent = item.title;
                info.appendChild(n);
            }
            const sub = document.createElement('span');
            sub.textContent = item.sub;
            info.appendChild(sub);

            li.appendChild(play);
            li.appendChild(info);

            if (item.tag) {
                const tag = document.createElement('div');
                tag.className = 'tag';
                tag.textContent = item.tag;
                li.appendChild(tag);
            }

            if (item.song) {
                const del = document.createElement('button');
                del.type = 'button';
                del.className = 'ghost';
                del.textContent = 'Xóa';
                del.addEventListener('click', async () => {
                    try {
                        await deleteSong(item.song.id);
                        if ('caches' in window) {
                            const cache = await caches.open(CACHE_NAME);
                            const url = item.song.streamUrl || streamUrl(item.song.path || '');
                            await cache.delete(new Request(url, { cache: 'reload' }));
                        }
                    } catch (e) { console.warn(e); }
                    await loadOffline();
                });
                li.appendChild(del);
            }
            listEl.appendChild(li);
        });
    }

    async function playIndex(i) {
        const item = state.queue[i];
        if (!item) return;
        try {
            const src = await item.getSrc();
            if (state.blobUrl) { URL.revokeObjectURL(state.blobUrl); state.blobUrl = null; }
            if (src.startsWith('blob:')) state.blobUrl = src;
            state.index = i;
            state.currentKey = item.key;
            playerEl.src = src;
            miniTitleEl.textContent = item.title;
            miniSubEl.textContent = item.sub;
            miniEl.classList.add('visible');
            await playerEl.play().catch(() => {});
            render();
        } catch (e) {
            console.error(e);
            alert('Không thể phát bài này.');
        }
    }

    async function loadOffline() {
        try { state.offline = await getAllSongs(); } catch (e) { state.offline = []; }
        const tab = document.querySelector('.tab[data-tab="offline"]');
        tab.textContent = 'Offline' + (state.offline.length ? ' (' + state.offline.length + ')' : '');
        render();
    }

    document.querySelectorAll('.tab').forEach((btn) => {
        btn.addEventListener('click', () => {
            state.tab = btn.dataset.tab;
            document.querySelectorAll('.tab').forEach((b) => b.classList.toggle('active', b === btn));
            render();
        });
    });
    searchEl.addEventListener('input', render);

    playerEl.addEventListener('ended', () => {
        // Queue is rebuilt on render; advance relative to the playing track.
        const idx = state.queue.findIndex((it) => it.key === state.currentKey);
        if (idx >= 0 && idx + 1 < state.queue.length) playIndex(idx + 1);
    });

    function openRemote() {
        const value = document.getElementById('remoteUrl').value.trim();
        if (!/^https?:\/\//i.test(value)) {
            alert('Vui lòng nhập URL nhạc bắt đầu bằng http:// hoặc https://');
            return;
        }
        window.location.href = 'DMusicPlayer.php?file=' + encodeURIComponent(value);
    }
    document.getElementById('remoteBtn').addEventListener('click', openRemote);
    document.getElementById('remoteUrl').addEventListener('keydown', (e) => { if (e.key === 'Enter') openRemote(); });

    const guideToggleBtn = document.getElementById('guideToggleAll');
    const guideItems = document.querySelectorAll('#guide details');
    guideToggleBtn.addEventListener('click', () => {
        const openAll = Array.from(guideItems).some((d) => !d.open);
        guideItems.forEach((d) => { d.open = openAll; });
        guideToggleBtn.textContent = openAll ? 'Thu gọn tất cả' : 'Mở tất cả';
    });
    if (location.hash === '#guide') {
        guideItems.forEach((d) => { d.open = true; });
        guideToggleBtn.textContent = 'Thu gọn tất cả';
    }

    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('DMusicPlayer.php?serviceworker=1', { scope: './' }).catch(() => {});
    }
    window.addEventListener('online', loadOffline);
    window.addEventListener('offline', loadOffline);

    render();
    loadOffline();
</script>
</body>
</html>
    <?php
    exit;
}

if (isset($_GET['serviceworker'])) {
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo <<<'JS'
const CACHE_NAME = 'dmusic-player-audio-v1';
const SHELL_URL = new URL('DMusicPlayer.php', self.location.href).href;

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => cache.add(new Request(SHELL_URL, { cache: 'reload' })).catch(() => {}))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key)))).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') {
        return;
    }
    const url = new URL(req.url);
    if (url.searchParams.get('serviceworker') === '1' || url.pathname.endsWith('.webmanifest')) {
        return;
    }

    // Home page: network first, fall back to the cached copy when offline.
    if (req.mode === 'navigate' && url.origin === self.location.origin
        && url.href.split('?')[0] === SHELL_URL.split('?')[0]
        && !url.searchParams.has('file') && !url.searchParams.has('icon')) {
        event.respondWith(
            fetch(req).then((res) => {
                if (res && res.ok) {
                    const copy = res.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(SHELL_URL, copy)).catch(() => {});
                }
                return res;
            }).catch(() => caches.match(SHELL_URL).then((hit) => hit || Response.error()))
        );
        return;
    }

    if (req.headers.has('range')) {
        return;
    }

    if (url.origin === self.location.origin && url.searchParams.get('file')) {
        event.respondWith(
            caches.open(CACHE_NAME).then(async (cache) => {
                const cached = await cache.match(req);
                if (cached) {
                    return cached;
                }
                const networkResponse = await fetch(req);
                if (networkResponse && networkResponse.ok && networkResponse.status === 200) {
                    try {
                        await cache.put(req, networkResponse.clone());
                    } catch (cacheError) {
                        console.warn('Cache PUT ignored for audio request:', cacheError);
                    }
                }
                return networkResponse;
            }).catch(() => fetch(req))
        );
    }
});
JS;
    exit;
}

/** Render the app icon as a PNG (pure PHP + zlib; no GD needed). */
function render_icon(int $size): void
{
    $allowed = [96, 144, 180, 192, 256, 512];
    if (!in_array($size, $allowed, true)) {
        $size = 192;
    }
    $bg = [22, 21, 28];
    $amber = [232, 147, 74];
    $ss = 2; // samples per axis for smooth edges

    // Amber disc with a dark music note cut out of it (normalised 0..1 coords).
    $isAmber = static function (float $x, float $y): bool {
        if (($x - 0.5) ** 2 + ($y - 0.5) ** 2 > 0.38 * 0.38) {
            return false;
        }
        // note head
        if ((($x - 0.43) / 0.10) ** 2 + (($y - 0.64) / 0.075) ** 2 <= 1.0) {
            return false;
        }
        // stem
        if ($x >= 0.515 && $x <= 0.55 && $y >= 0.28 && $y <= 0.64) {
            return false;
        }
        // flag
        if ($x > 0.55 && $x <= 0.63) {
            $off = ($x - 0.55) * 0.9;
            if ($y >= 0.28 + $off && $y <= 0.36 + $off) {
                return false;
            }
        }
        return true;
    };

    $raw = '';
    for ($py = 0; $py < $size; $py++) {
        $raw .= "\x00";
        for ($px = 0; $px < $size; $px++) {
            $hit = 0;
            for ($sy = 0; $sy < $ss; $sy++) {
                for ($sx = 0; $sx < $ss; $sx++) {
                    if ($isAmber(($px + ($sx + 0.5) / $ss) / $size, ($py + ($sy + 0.5) / $ss) / $size)) {
                        $hit++;
                    }
                }
            }
            $k = $hit / ($ss * $ss);
            $raw .= chr((int) round($bg[0] + ($amber[0] - $bg[0]) * $k))
                . chr((int) round($bg[1] + ($amber[1] - $bg[1]) * $k))
                . chr((int) round($bg[2] + ($amber[2] - $bg[2]) * $k))
                . "\xff";
        }
    }

    $chunk = static function (string $type, string $data): string {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    };
    $png = "\x89PNG\r\n\x1a\n"
        . $chunk('IHDR', pack('NNCCCCC', $size, $size, 8, 6, 0, 0, 0))
        . $chunk('IDAT', gzcompress($raw, 9))
        . $chunk('IEND', '');

    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    header('Content-Length: ' . strlen($png));
    echo $png;
    exit;
}

if (isset($_GET['icon'])) {
    render_icon((int) $_GET['icon']);
}

if (isset($_GET['manifest'])) {
    header('Content-Type: application/manifest+json; charset=utf-8');
    echo json_encode([
        'name' => 'DMusicPlayer Offline',
        'short_name' => 'DMusic',
        'id' => './DMusicPlayer.php',
        'start_url' => './DMusicPlayer.php',
        'scope' => './',
        'lang' => 'vi',
        'display' => 'standalone',
        'background_color' => '#16151c',
        'theme_color' => '#16151c',
        'description' => 'PWA music player for browsing and saving offline tracks.',
        'icons' => [
            ['src' => 'DMusicPlayer.php?icon=192', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => 'DMusicPlayer.php?icon=512', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => 'DMusicPlayer.php?icon=192', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
            ['src' => 'DMusicPlayer.php?icon=512', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// ---- Resolve & validate requested file ---------------------------------

$requested = $_GET['file'] ?? '';
if ($requested === '') {
    render_home();
}

$remoteMediaUrl = null;
$relativePath = null;
$fullPath = null;
$fileSize = null;
$modifiedTs = null;

if (is_remote_media_url($requested)) {
    $remoteMediaUrl = $requested;
    $safeName = basename(parse_url($requested, PHP_URL_PATH) ?: 'remote-track');
    if ($safeName === '' || $safeName === '/' || $safeName === '.') {
        $safeName = 'remote-track';
    }
    $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXT, true)) {
        render_error('Định dạng tệp không được hỗ trợ (.' . htmlspecialchars($ext) . ').');
    }
} else {
    try {
        $relativePath = media_relative_path($requested);
    } catch (InvalidArgumentException $e) {
        render_error($e->getMessage());
    }

    $safeName = basename($relativePath);
    $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));

    if (!in_array($ext, ALLOWED_EXT, true)) {
        render_error('Định dạng tệp không được hỗ trợ (.' . htmlspecialchars($ext) . ').');
    }

    $mediaRoot = realpath(MEDIA_DIR);
    $fullPath = realpath(MEDIA_DIR . DIRECTORY_SEPARATOR . $relativePath);

    if ($mediaRoot === false || $fullPath === false || !is_within_root($fullPath, $mediaRoot) || !is_file($fullPath)) {
        render_error('Không tìm thấy tệp "' . htmlspecialchars($safeName) . '".');
    }

    $fileSize = filesize($fullPath);
    $modifiedTs = filemtime($fullPath);
}

// ---- Probe with ffprobe --------------------------------------------------

$title = pathinfo($safeName, PATHINFO_FILENAME);
$artist = null;
$album = null;
$year = null;
$bitrate = null;
$sampleRate = null;
$codecName = strtoupper($ext);
$duration = null;
$coverDataUri = null;
$probeAvailable = tool_available('ffprobe');

if ($fullPath !== null && $probeAvailable) {
    $cmd = 'ffprobe -v quiet -print_format json -show_format -show_streams '
        . escapeshellarg($fullPath);
    $json = shell_exec($cmd . ' 2>/dev/null');
    $data = $json ? json_decode($json, true) : null;

    if (is_array($data)) {
        $formatTags = $data['format']['tags'] ?? [];
        $duration = isset($data['format']['duration']) ? (float) $data['format']['duration'] : null;
        $formatBitrate = isset($data['format']['bit_rate']) ? (int) $data['format']['bit_rate'] : null;

        $audioStream = null;
        $pictureStreamIndex = null;
        $pictureCodec = null;

        foreach ($data['streams'] ?? [] as $stream) {
            if (($stream['codec_type'] ?? '') === 'audio' && $audioStream === null) {
                $audioStream = $stream;
            }
            $isAttachedPic = ($stream['disposition']['attached_pic'] ?? 0) == 1;
            if (($stream['codec_type'] ?? '') === 'video' && ($isAttachedPic || $pictureStreamIndex === null)) {
                $pictureStreamIndex = $stream['index'];
                $pictureCodec = $stream['codec_name'] ?? null;
            }
        }

        if ($audioStream !== null) {
            $bitrate = isset($audioStream['bit_rate']) ? (int) $audioStream['bit_rate'] : $formatBitrate;
            $sampleRate = isset($audioStream['sample_rate']) ? (int) $audioStream['sample_rate'] : null;
            $codecName = strtoupper((string) ($audioStream['codec_name'] ?? $ext));
        } else {
            $bitrate = $formatBitrate;
        }

        $title = tag($formatTags, ['title']) ?? $title;
        $artist = tag($formatTags, ['artist', 'album_artist']);
        $album = tag($formatTags, ['album']);
        $year = tag($formatTags, ['date', 'year']);
        if ($year !== null) {
            // Trim full dates like "2026-03-01" down to just the year.
            $year = substr($year, 0, 4);
        }

        // --- Extract embedded cover art, if present ---
        if ($pictureStreamIndex !== null && tool_available('ffmpeg')) {
            $extMap = ['mjpeg' => 'jpg', 'png' => 'png', 'jpg' => 'jpg', 'jpeg' => 'jpg'];
            $coverExt = $extMap[$pictureCodec] ?? 'jpg';
            $tmpCover = tempnam(sys_get_temp_dir(), 'cover_') . '.' . $coverExt;
            $extractCmd = sprintf(
                'ffmpeg -y -i %s -map 0:%d -c copy %s',
                escapeshellarg($fullPath),
                $pictureStreamIndex,
                escapeshellarg($tmpCover)
            );
            shell_exec($extractCmd . ' 2>/dev/null');
            if (is_file($tmpCover) && filesize($tmpCover) > 0) {
                $mime = $coverExt === 'png' ? 'image/png' : 'image/jpeg';
                $coverDataUri = 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($tmpCover));
            }
            if (is_file($tmpCover)) {
                unlink($tmpCover);
            }
        }
    }
}

// ---- Prepare display values ---------------------------------------------

$displayTitle = $title !== '' ? $title : $safeName;
$displayArtist = $artist ?? 'Không rõ nghệ sĩ';
$displayAlbum = $album;
$displayYear = $year;

$bitrateText = $bitrate ? number_format(intdiv($bitrate, 1000)) . ' kbps' : '—';
$sampleRateText = $sampleRate ? number_format($sampleRate) . ' Hz' : '—';
$sizeText = $fileSize !== null ? human_filesize($fileSize) : '—';
$modifiedText = $modifiedTs !== null ? date('d/m/Y H:i:s', $modifiedTs) : '—';
$durationText = human_duration($duration);

// Audio src: serve the file itself for the <audio> tag while preserving the same root folder.
$requestedForStream = $remoteMediaUrl ?? $relativePath;
$audioSrc = 'DMusicPlayer.php?file=' . urlencode((string) $requestedForStream) . '&stream=1';
$mimeMap = [
    'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'flac' => 'audio/flac',
    'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'oga' => 'audio/ogg',
    'opus' => 'audio/opus', 'wma' => 'audio/x-ms-wma', 'aac' => 'audio/aac',
];

if (isset($_GET['stream'])) {
    // Raw byte-serving branch so the same script can back the <audio src>.
    if ($remoteMediaUrl !== null) {
        $response = fetch_remote_stream($remoteMediaUrl);
        if ($response === null) {
            render_error('Không thể tải tệp âm thanh từ URL được cung cấp.');
        }
        $contentType = $response['content_type'];
        if (isset($mimeMap[$ext]) && $mimeMap[$ext] !== '') {
            $contentType = $mimeMap[$ext];
        }
        header('Content-Type: ' . $contentType);
        if ($response['content_length'] !== null) {
            header('Content-Length: ' . (string) $response['content_length']);
        }
        header('Accept-Ranges: bytes');
        echo $response['body'];
        exit;
    }

    header('Content-Type: ' . ($mimeMap[$ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . $fileSize);
    header('Accept-Ranges: bytes');
    readfile($fullPath);
    exit;
}
?>
<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=1, user-scalable=no">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title><?= htmlspecialchars($displayTitle, ENT_QUOTES, 'UTF-8') ?> — Xem trước tệp âm thanh</title>
<link rel="manifest" href="DMusicPlayer.php?manifest=1">
<meta name="theme-color" content="#16151c">
<link rel="apple-touch-icon" href="DMusicPlayer.php?icon=180">
<link rel="icon" type="image/png" sizes="192x192" href="DMusicPlayer.php?icon=192">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
<style>
    :root{
        --bg: #16151c;
        --surface: #201f29;
        --surface-2: #262532;
        --line: #322f3c;
        --amber: #e8934a;
        --teal: #4fa8a0;
        --text: #f1ede4;
        --text-muted: #a9a4b3;
    }
    *{box-sizing:border-box;}
    body{
        margin:0; min-height:100vh; padding:40px 20px;
        display:flex; align-items:center; justify-content:center;
        background:
            radial-gradient(circle at 15% -10%, rgba(232,147,74,.08), transparent 45%),
            radial-gradient(circle at 100% 110%, rgba(79,168,160,.08), transparent 45%),
            var(--bg);
        color:var(--text);
        font-family:'Inter',system-ui,-apple-system,sans-serif;
    }
    .card{
        width:100%; max-width:760px;
        background:var(--surface);
        border:1px solid var(--line);
        border-radius:18px;
        padding:34px;
        box-shadow:0 30px 60px -30px rgba(0,0,0,.6);
        animation:rise .5s ease both;
    }
    @keyframes rise{ from{opacity:0; transform:translateY(10px);} to{opacity:1; transform:none;} }
    @media (prefers-reduced-motion: reduce){ .card{animation:none;} }

    @supports (-webkit-touch-callout: none) {
        body {
            padding-top: calc(24px + env(safe-area-inset-top));
            padding-right: calc(20px + env(safe-area-inset-right));
            padding-bottom: calc(24px + env(safe-area-inset-bottom));
            padding-left: calc(20px + env(safe-area-inset-left));
        }
        .card {
            border-radius: 22px;
            padding: 22px 18px 20px;
        }
        audio {
            margin-top: 18px;
        }
    }

    .back{
        display:inline-block; margin-bottom:18px; color:var(--text-muted); text-decoration:none;
        font-size:13px; font-weight:500;
    }
    .back:hover{ color:var(--amber); }
    .top{ display:flex; gap:28px; }
    @media (max-width:560px){ .top{flex-direction:column;} }
    @media (max-width:420px){
        body { padding: 18px 12px; }
        .card { padding: 20px 14px 18px; }
        .plate { grid-template-columns: 1fr 1fr; }
    }

    .art-frame{
        position:relative; flex:0 0 auto;
        width:190px; height:190px;
        border-radius:12px;
        background:var(--surface-2);
        border:1px solid var(--line);
        overflow:hidden;
        display:flex; align-items:center; justify-content:center;
    }
    .art-frame img{ width:100%; height:100%; object-fit:cover; display:block; }
    .art-frame .placeholder{ color:var(--text-muted); font-size:12px; text-align:center; padding:10px; }
    .rivet{
        position:absolute; width:6px; height:6px; border-radius:50%;
        background:rgba(241,237,228,.14);
        box-shadow: inset 0 1px 1px rgba(0,0,0,.4);
    }
    .rivet.tl{ top:8px; left:8px; } .rivet.tr{ top:8px; right:8px; }
    .rivet.bl{ bottom:8px; left:8px; } .rivet.br{ bottom:8px; right:8px; }

    .meta{ flex:1; min-width:0; display:flex; flex-direction:column; justify-content:center; }
    .eyebrow{
        font-family:'JetBrains Mono',monospace; font-size:11px; letter-spacing:.14em;
        color:var(--teal); text-transform:uppercase; margin-bottom:8px;
    }
    h1{
        font-family:'Oswald',sans-serif; font-weight:600; text-transform:uppercase;
        letter-spacing:.01em; font-size:clamp(20px,4vw,28px); line-height:1.15;
        margin:0 0 8px; color:var(--text);
        overflow-wrap:anywhere;
    }
    .sub{ color:var(--text-muted); font-size:14.5px; margin:0; }
    .sub b{ color:var(--text); font-weight:500; }
    .badge{
        display:inline-block; margin-top:14px; padding:4px 10px;
        font-family:'JetBrains Mono',monospace; font-size:12px; font-weight:700;
        color:var(--amber); border:1px solid rgba(232,147,74,.4); border-radius:999px;
        width:fit-content;
    }

    audio{ width:100%; margin-top:26px; accent-color:var(--amber); }

    .plate{
        margin-top:26px; padding:20px 0 0;
        border-top:1px dashed var(--line);
        display:grid; grid-template-columns:repeat(3,1fr); gap:20px 16px;
    }
    @media (max-width:560px){ .plate{grid-template-columns:repeat(2,1fr);} }
    .stat{ min-width:0; }
    .stat .value{
        font-family:'JetBrains Mono',monospace; font-weight:700; font-size:18px;
        color:var(--text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    }
    .stat .label{
        margin-top:4px; font-size:10.5px; letter-spacing:.1em; text-transform:uppercase;
        color:var(--text-muted);
    }

    .notice{
        margin-top:18px; padding:10px 14px; border-radius:8px;
        background:rgba(232,147,74,.08); border:1px solid rgba(232,147,74,.25);
        color:var(--amber); font-size:12.5px;
    }
    .toolbar{
        display:flex; gap:12px; flex-wrap:wrap; margin-top:20px;
    }
    .action{
        appearance:none; border:1px solid rgba(232,147,74,.35); background:rgba(232,147,74,.12);
        color:var(--text); border-radius:999px; padding:9px 14px; font-weight:600; cursor:pointer;
        transition:transform .15s ease, filter .15s ease, border-color .15s ease;
    }
    .action:hover{ transform:translateY(-1px); filter:brightness(1.05); }
    .action.secondary{ border-color: rgba(79,168,160,.4); background: rgba(79,168,160,.08); }
    .action:disabled{ opacity:.6; cursor:not-allowed; }
    .offline-panel{
        margin-top:18px; padding:14px; border-radius:12px; border:1px solid var(--line);
        background:rgba(38,37,50,.75); display:none;
    }
    .offline-panel.visible{ display:block; }
    .offline-head{
        display:flex; justify-content:space-between; gap:12px; align-items:center; margin-bottom:12px;
        color:var(--text-muted); font-size:12px; text-transform:uppercase; letter-spacing:.08em;
    }
    .offline-list{ list-style:none; padding:0; margin:0; display:grid; gap:10px; }
    .offline-item{
        display:flex; justify-content:space-between; align-items:center; gap:12px; padding:10px 12px;
        border-radius:10px; background:rgba(255,255,255,.02); border:1px solid rgba(255,255,255,.04);
    }
    .offline-meta{ min-width:0; }
    .offline-meta strong{ display:block; color:var(--text); font-size:14px; overflow-wrap:anywhere; }
    .offline-meta span{ font-size:12px; color:var(--text-muted); }
    .offline-btn{ background:transparent; border:1px solid rgba(232,147,74,.4); color:var(--amber); border-radius:999px; padding:7px 10px; font-size:12px; cursor:pointer; }
    .offline-empty{ color:var(--text-muted); font-size:13px; margin:0; }
</style>
</head>
<body>
    <div class="card">
        <a class="back" href="DMusicPlayer.php">← Trang chủ</a>
        <div class="top">
            <div class="art-frame">
                <div class="rivet tl"></div><div class="rivet tr"></div>
                <div class="rivet bl"></div><div class="rivet br"></div>
                <?php if ($coverDataUri): ?>
                    <img src="<?= $coverDataUri ?>" alt="Ảnh bìa">
                <?php else: ?>
                    <div class="placeholder">Không có<br>ảnh bìa</div>
                <?php endif; ?>
            </div>
            <div class="meta">
                <div class="eyebrow">Xem trước tệp âm thanh</div>
                <h1><?= htmlspecialchars($displayTitle, ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="sub">
                    <b><?= htmlspecialchars($displayArtist, ENT_QUOTES, 'UTF-8') ?></b>
                    <?php if ($displayAlbum): ?> · <?= htmlspecialchars($displayAlbum, ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
                </p>
                <?php if ($displayYear): ?>
                    <span class="badge"><?= htmlspecialchars($displayYear, ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
            </div>
        </div>

        <audio controls preload="metadata" src="<?= htmlspecialchars($audioSrc, ENT_QUOTES, 'UTF-8') ?>"></audio>

        <div class="toolbar" style="margin-bottom:12px;">
            <input id="remoteUrlInput" type="url" placeholder="https://example.com/song.mp3" style="flex:1; min-width:200px; border-radius:999px; border:1px solid rgba(255,255,255,.12); background:rgba(255,255,255,.02); color:var(--text); padding:9px 14px;">
            <button type="button" id="openRemoteBtn" class="action secondary">Nghe từ URL</button>
        </div>

        <div class="toolbar">
            <button type="button" id="downloadOfflineBtn" class="action">Tải về offline</button>
            <button type="button" id="offlineToggleBtn" class="action secondary">Offline Music</button>
        </div>

        <div id="offlinePanel" class="offline-panel">
            <div class="offline-head">
                <span>Danh sách nhạc đã lưu</span>
                <span id="offlineStatus">Chưa có dữ liệu</span>
            </div>
            <ul id="offlineList" class="offline-list"></ul>
        </div>

        <div class="plate">
            <div class="stat"><div class="value"><?= htmlspecialchars($bitrateText, ENT_QUOTES, 'UTF-8') ?></div><div class="label">Tốc độ bit</div></div>
            <div class="stat"><div class="value"><?= htmlspecialchars($sampleRateText, ENT_QUOTES, 'UTF-8') ?></div><div class="label">Tần số lấy mẫu</div></div>
            <div class="stat"><div class="value"><?= htmlspecialchars($codecName, ENT_QUOTES, 'UTF-8') ?></div><div class="label">Định dạng</div></div>
            <div class="stat"><div class="value"><?= htmlspecialchars($durationText, ENT_QUOTES, 'UTF-8') ?></div><div class="label">Thời lượng</div></div>
            <div class="stat"><div class="value"><?= htmlspecialchars($sizeText, ENT_QUOTES, 'UTF-8') ?></div><div class="label">Kích thước</div></div>
            <div class="stat"><div class="value"><?= htmlspecialchars($modifiedText, ENT_QUOTES, 'UTF-8') ?></div><div class="label">Sửa đổi lần cuối</div></div>
        </div>

        <?php if (!$probeAvailable): ?>
            <div class="notice">Không tìm thấy <code>ffprobe</code> trên máy chủ nên không thể đọc tốc độ bit / tần số lấy mẫu / thẻ ID3. Vẫn hiển thị kích thước và thời gian sửa đổi từ hệ thống tệp.</div>
        <?php endif; ?>
    </div>
<script>
    const CURRENT_SONG = {
        remoteUrl: <?= json_encode($remoteMediaUrl ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        relativePath: <?= json_encode($relativePath, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        title: <?= json_encode($displayTitle, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        artist: <?= json_encode($displayArtist, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        album: <?= json_encode($displayAlbum ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        year: <?= json_encode($displayYear ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        streamUrl: <?= json_encode($audioSrc, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        cover: <?= json_encode($coverDataUri ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        mimeType: <?= json_encode($mimeMap[$ext] ?? 'audio/mpeg', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
    };
    const TRACK_ID = CURRENT_SONG.relativePath || CURRENT_SONG.remoteUrl;
    const DB_NAME = 'DMusicPlayerDB';
    const STORE_NAME = 'tracks';
    const CACHE_NAME = 'dmusic-player-audio-v1';
    const audioEl = document.querySelector('audio');
    const offlineListEl = document.getElementById('offlineList');
    const downloadBtn = document.getElementById('downloadOfflineBtn');
    const offlinePanel = document.getElementById('offlinePanel');
    const offlineToggleBtn = document.getElementById('offlineToggleBtn');
    const offlineStatusEl = document.getElementById('offlineStatus');

    function openDatabase() {
        return new Promise((resolve, reject) => {
            if (!('indexedDB' in window)) {
                reject(new Error('IndexedDB không được hỗ trợ trên trình duyệt này.'));
                return;
            }
            const request = indexedDB.open(DB_NAME, 1);
            request.onupgradeneeded = () => {
                const db = request.result;
                if (!db.objectStoreNames.contains(STORE_NAME)) {
                    db.createObjectStore(STORE_NAME, { keyPath: 'id' });
                }
            };
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error || new Error('Không thể mở IndexedDB.'));
        });
    }

    async function getAllSongs() {
        const db = await openDatabase();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_NAME, 'readonly');
            const req = tx.objectStore(STORE_NAME).getAll();
            req.onsuccess = () => resolve(req.result || []);
            req.onerror = () => reject(req.error || new Error('Không tải được danh sách nhạc offline.'));
        });
    }

    async function putSong(song) {
        const db = await openDatabase();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_NAME, 'readwrite');
            const req = tx.objectStore(STORE_NAME).put(song);
            req.onsuccess = () => resolve();
            req.onerror = () => reject(req.error || new Error('Không lưu được nhạc offline.'));
        });
    }

    async function deleteSong(id) {
        const db = await openDatabase();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_NAME, 'readwrite');
            const req = tx.objectStore(STORE_NAME).delete(id);
            req.onsuccess = () => resolve();
            req.onerror = () => reject(req.error || new Error('Không xóa được nhạc offline.'));
        });
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/\"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    async function saveCurrentTrack() {
        try {
            downloadBtn.disabled = true;
            downloadBtn.textContent = 'Đang lưu...';

            const response = await fetch(CURRENT_SONG.streamUrl, { cache: 'no-store' });
            if (!response.ok) {
                throw new Error('Không thể tải bài này để lưu offline.');
            }
            const blob = await response.blob();
            const song = {
                id: TRACK_ID,
                path: TRACK_ID,
                streamUrl: CURRENT_SONG.streamUrl,
                title: CURRENT_SONG.title,
                artist: CURRENT_SONG.artist,
                album: CURRENT_SONG.album,
                year: CURRENT_SONG.year,
                cover: CURRENT_SONG.cover,
                createdAt: new Date().toISOString(),
                mimeType: CURRENT_SONG.mimeType || blob.type || 'audio/mpeg',
                audioBlob: blob,
                size: blob.size
            };

            await putSong(song);

            if ('caches' in window) {
                const cache = await caches.open(CACHE_NAME);
                await cache.put(new Request(CURRENT_SONG.streamUrl, { cache: 'reload' }), new Response(blob, {
                    headers: { 'Content-Type': song.mimeType, 'Content-Length': String(blob.size) }
                }));
            }

            downloadBtn.textContent = 'Đã lưu offline';
            await renderOfflineList();
        } catch (error) {
            downloadBtn.textContent = 'Tải về offline';
            console.error(error);
            alert(error.message || 'Không thể lưu nhạc offline.');
        } finally {
            downloadBtn.disabled = false;
        }
    }

    async function renderOfflineList() {
        if (!('indexedDB' in window)) {
            offlineStatusEl.textContent = 'Không hỗ trợ IndexedDB';
            return;
        }

        try {
            const songs = await getAllSongs();
            offlineListEl.innerHTML = '';
            if (!songs.length) {
                offlineListEl.innerHTML = '<li class="offline-empty">Chưa có bài nhạc nào được lưu để phát offline.</li>';
                offlineStatusEl.textContent = 'Chưa có dữ liệu';
                downloadBtn.textContent = 'Tải về offline';
                return;
            }

            offlineStatusEl.textContent = songs.length + ' bài đã lưu';
            songs.forEach((song) => {
                const li = document.createElement('li');
                li.className = 'offline-item';
                const meta = document.createElement('div');
                meta.className = 'offline-meta';
                meta.innerHTML = '<strong>' + escapeHtml(song.title || 'Không rõ tên') + '</strong><span>' + escapeHtml(song.artist || 'Không rõ nghệ sĩ') + '</span>';

                const actions = document.createElement('div');
                actions.style.display = 'flex';
                actions.style.gap = '8px';

                const playBtn = document.createElement('button');
                playBtn.type = 'button';
                playBtn.className = 'offline-btn';
                playBtn.textContent = 'Phát';
                playBtn.addEventListener('click', () => {
                    if (song.audioBlob) {
                        const objectUrl = URL.createObjectURL(song.audioBlob);
                        audioEl.src = objectUrl;
                        audioEl.play().catch(() => {});
                    }
                });

                const deleteBtn = document.createElement('button');
                deleteBtn.type = 'button';
                deleteBtn.className = 'offline-btn';
                deleteBtn.textContent = 'Xóa';
                deleteBtn.addEventListener('click', async () => {
                    await deleteSong(song.id);
                    if ('caches' in window) {
                        const cache = await caches.open(CACHE_NAME);
                        await cache.delete(new Request(song.streamUrl || ('DMusicPlayer.php?file=' + encodeURIComponent(song.path) + '&stream=1'), { cache: 'reload' }));
                    }
                    await renderOfflineList();
                });

                actions.appendChild(playBtn);
                actions.appendChild(deleteBtn);
                li.appendChild(meta);
                li.appendChild(actions);
                offlineListEl.appendChild(li);
            });

            const currentSaved = songs.some((song) => song.id === TRACK_ID);
            downloadBtn.textContent = currentSaved ? 'Đã lưu offline' : 'Tải về offline';
        } catch (error) {
            console.warn('Không đọc được kho nhạc offline.', error);
            offlineStatusEl.textContent = 'Lỗi đọc dữ liệu';
        }
    }

    async function restoreSavedTrackIfNeeded() {
        if (!('indexedDB' in window)) {
            return;
        }
        try {
            const songs = await getAllSongs();
            const saved = songs.find((song) => song.id === TRACK_ID);
            if (!saved || !saved.audioBlob) {
                return;
            }
            if (!navigator.onLine) {
                const objectUrl = URL.createObjectURL(saved.audioBlob);
                audioEl.src = objectUrl;
            }
        } catch (error) {
            console.warn('Không khôi phục nhạc offline.', error);
        }
    }

    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('DMusicPlayer.php?serviceworker=1', { scope: './' }).catch((error) => {
            console.warn('Service worker không được đăng ký:', error);
        });
    }

    const remoteUrlInput = document.getElementById('remoteUrlInput');
    const openRemoteBtn = document.getElementById('openRemoteBtn');

    openRemoteBtn.addEventListener('click', () => {
        const value = (remoteUrlInput.value || '').trim();
        if (!value) {
            alert('Vui lòng nhập URL nhạc trực tuyến.');
            return;
        }
        window.location.href = 'DMusicPlayer.php?file=' + encodeURIComponent(value);
    });

    remoteUrlInput.value = <?= json_encode($remoteMediaUrl ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    downloadBtn.addEventListener('click', saveCurrentTrack);
    offlineToggleBtn.addEventListener('click', async () => {
        offlinePanel.classList.toggle('visible');
        if (offlinePanel.classList.contains('visible')) {
            await renderOfflineList();
        }
    });

    window.addEventListener('online', () => renderOfflineList());
    window.addEventListener('offline', () => renderOfflineList());
    audioEl.addEventListener('error', async () => {
        if (!navigator.onLine) {
            await restoreSavedTrackIfNeeded();
        }
    });

    renderOfflineList().then(() => restoreSavedTrackIfNeeded());
</script>
</body>
</html>