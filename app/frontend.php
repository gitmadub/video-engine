<?php

declare(strict_types=1);

const VE_SITE_PAGES = [
    'api-docs' => 'pages/api-docs.html',
    'contact' => 'pages/contact.html',
    'copyright' => 'pages/copyright.html',
    'earn-money' => 'pages/earn-money.html',
    'premium' => 'pages/premium.html',
    'terms-and-conditions' => 'pages/terms-and-conditions.html',
];

const VE_DASHBOARD_PAGES = [
    '' => 'dashboard/index.html',
    'videos' => 'dashboard/videos.html',
    'settings' => 'dashboard/settings.html',
    'reports' => 'dashboard/reports.html',
    'remote-upload' => 'dashboard/remote-upload.html',
    'referrals' => 'dashboard/referrals.html',
    'premium-plans' => 'dashboard/premium-plans.html',
    'request-payout' => 'dashboard/request-payout.html',
    'dmca-manager' => 'dashboard/dmca-manager.html',
];

const VE_LEGACY_DASHBOARD_ROUTES = [
    'videos' => 'videos',
    'settings' => 'settings',
    'reports' => 'reports',
    'remote-upload' => 'remote-upload',
    'referrals' => 'referrals',
    'premium-plans' => 'premium-plans',
    'request-payout' => 'request-payout',
    'dmca-manager' => 'dmca-manager',
];

function ve_root_path(string ...$parts): string
{
    return __DIR__ . '/../' . implode('/', $parts);
}

function ve_base_path(): string
{
    static $basePath;

    if (is_string($basePath)) {
        return $basePath;
    }

    $configured = getenv('VE_BASE_PATH');

    if (is_string($configured) && $configured !== '') {
        $configured = '/' . trim($configured, '/');
        $basePath = $configured === '/' ? '' : $configured;
        return $basePath;
    }

    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

    if (is_string($requestPath) && ($requestPath === '/video-engine' || str_starts_with($requestPath, '/video-engine/'))) {
        $basePath = '/video-engine';
        return $basePath;
    }

    $basePath = '';
    return $basePath;
}

function ve_url(string $path = '/'): string
{
    if ($path === '') {
        $path = '/';
    }

    if (
        preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $path) === 1
        || str_starts_with($path, '#')
        || str_starts_with($path, 'mailto:')
        || str_starts_with($path, 'tel:')
    ) {
        return $path;
    }

    $basePath = ve_base_path();

    if ($path === '/') {
        return $basePath === '' ? '/' : $basePath . '/';
    }

    if ($path[0] !== '/') {
        $path = '/' . $path;
    }

    if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
        return $path;
    }

    return $basePath . $path;
}

function ve_request_path(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

    if (!is_string($path) || $path === '') {
        return '/';
    }

    $path = rawurldecode($path);
    $basePath = ve_base_path();

    if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
        $path = substr($path, strlen($basePath)) ?: '/';
    }

    $scriptPath = parse_url((string) ($_SERVER['SCRIPT_NAME'] ?? ''), PHP_URL_PATH);
    $scriptPrefix = '';

    if (is_string($scriptPath) && $scriptPath !== '') {
        $scriptPath = rawurldecode($scriptPath);

        if ($basePath !== '' && ($scriptPath === $basePath || str_starts_with($scriptPath, $basePath . '/'))) {
            $scriptPath = substr($scriptPath, strlen($basePath)) ?: '/';
        }

        if ($scriptPath !== '/' && $scriptPath !== '' && str_ends_with($scriptPath, '.php')) {
            $scriptPrefix = str_ends_with($scriptPath, '.php')
                ? rtrim(str_replace('\\', '/', dirname($scriptPath)), '/.')
                : $scriptPath;

            if ($scriptPrefix === '\\' || $scriptPrefix === '.') {
                $scriptPrefix = '';
            }
        }

        if ($scriptPath !== '/' && $scriptPath !== '' && str_ends_with($scriptPath, '.php')) {
            if ($path === $scriptPath) {
                $path = '/';
            } elseif (str_starts_with($path, $scriptPath . '/')) {
                $path = substr($path, strlen($scriptPath)) ?: '/';
            }
        }
    }

    $pathInfo = (string) ($_SERVER['PATH_INFO'] ?? '');

    if ($pathInfo !== '' && $pathInfo[0] === '/') {
        $pathInfo = rawurldecode($pathInfo);
        $normalizedPathInfo = $pathInfo;
        $pathInfoRemainder = $pathInfo;

        if ($scriptPrefix !== '') {
            if ($pathInfo !== $scriptPrefix && !str_starts_with($pathInfo, $scriptPrefix . '/')) {
                $normalizedPathInfo = $scriptPrefix . ($pathInfo === '/' ? '' : $pathInfo);
            }

            if ($normalizedPathInfo === $scriptPrefix || str_starts_with($normalizedPathInfo, $scriptPrefix . '/')) {
                $pathInfoRemainder = substr($normalizedPathInfo, strlen($scriptPrefix)) ?: '/';
            }
        }

        if ($path === '/' || $path === $pathInfo || $path === $pathInfoRemainder) {
            $path = $normalizedPathInfo;
        }
    }

    if ($path !== '/') {
        $path = rtrim($path, '/');
    }

    return $path === '' ? '/' : $path;
}

function ve_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function ve_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ve_origin(): string
{
    $scheme = 'http';

    if (
        (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    ) {
        $scheme = 'https';
    }

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return $scheme . '://' . $host;
}

function ve_absolute_url(string $path = '/'): string
{
    return ve_origin() . ve_url($path);
}

function ve_html(string $html, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    echo $html;
    exit;
}

function ve_redirect(string $location, int $status = 302): void
{
    if ($location !== '' && ($location[0] === '/' || $location[0] === '?')) {
        $location = $location[0] === '/' ? ve_url($location) : ve_url('/' . $location);
    }

    http_response_code($status);
    header('Location: ' . $location);
    exit;
}

function ve_rewrite_html_paths(string $html): string
{
    $basePath = ve_base_path();

    if ($basePath === '') {
        return $html;
    }

    $quotedReplacements = [
        'href="../index.html"' => 'href="' . ve_url('/') . '"',
        "href='../index.html'" => "href='" . ve_url('/') . "'",
        'action="../index.html"' => 'action="' . ve_url('/') . '"',
        "action='../index.html'" => "action='" . ve_url('/') . "'",
        'src="/assets/js/dood_load.js"' => 'src="' . ve_url('/assets/js/dood_load.js') . '"',
        "src='/assets/js/dood_load.js'" => "src='" . ve_url('/assets/js/dood_load.js') . "'",
        'href="/"' => 'href="' . ve_url('/') . '"',
        "href='/'" => "href='" . ve_url('/') . "'",
        'action="/"' => 'action="' . ve_url('/') . '"',
        "action='/'" => "action='" . ve_url('/') . "'",
    ];

    $html = strtr($html, $quotedReplacements);

    $prefixes = [
        '/assets/',
        '/js/',
        '/api/',
        '/account/',
        '/dashboard',
        '/videos',
        '/settings',
        '/reports',
        '/remote-upload',
        '/referrals',
        '/premium-plans',
        '/request-payout',
        '/dmca-manager',
        '/api-docs',
        '/contact',
        '/copyright',
        '/earn-money',
        '/premium',
        '/terms-and-conditions',
        '/login',
        '/register',
        '/logout',
        '/password/',
        '/genrate-api',
        '/subscene/',
        '/data/',
        '/dl',
        '/?op=',
    ];

    foreach ($prefixes as $prefix) {
        $target = ve_url($prefix);
        $html = str_replace('="' . $prefix, '="' . $target, $html);
        $html = str_replace("='" . $prefix, "='" . $target, $html);
        $html = str_replace('("' . $prefix, '("' . $target, $html);
        $html = str_replace("('" . $prefix, "('" . $target, $html);
        $html = str_replace('url(' . $prefix, 'url(' . $target, $html);
        $html = str_replace('url("' . $prefix, 'url("' . $target, $html);
        $html = str_replace("url('" . $prefix, "url('" . $target, $html);
    }

    return $html;
}

function ve_render_file(string $relativePath, int $status = 200): void
{
    $fullPath = ve_root_path($relativePath);

    if (!is_file($fullPath)) {
        ve_not_found();
    }

    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    echo ve_rewrite_html_paths(ve_runtime_html_transform((string) file_get_contents($fullPath), $relativePath));
    exit;
}

function ve_not_found(): void
{
    ve_render_file('pages/404.html', 404);
}

require __DIR__ . '/backend.php';
require __DIR__ . '/modules/auth.php';
require __DIR__ . '/modules/reports.php';
require __DIR__ . '/modules/public_api.php';
require __DIR__ . '/referrals.php';
require __DIR__ . '/video.php';
require __DIR__ . '/modules/dmca.php';
require __DIR__ . '/modules/admin.php';
require __DIR__ . '/remote_upload.php';
require __DIR__ . '/routes/api.php';
require __DIR__ . '/routes/legacy.php';

function ve_dashboard_stats(?int $userId = null): array
{
    if ($userId === null) {
        $user = ve_current_user();

        if (!is_array($user)) {
            return [
                'status' => 'ok',
                'online' => 0,
                'today' => '$0.00000',
                'balance' => '$0.00000',
                'widgets' => [
                    'online' => ['value' => 0, 'formatted' => '0'],
                    'today_earnings' => ['micro_usd' => 0, 'formatted' => '$0.00000'],
                    'yesterday_earnings' => ['micro_usd' => 0, 'formatted' => '$0.00000'],
                    'balance' => ['micro_usd' => 0, 'formatted' => '$0.00000'],
                    'storage_used' => ['bytes' => 0, 'formatted' => '0.00 GB'],
                ],
                'chart' => [],
                'top_files' => [],
                'range' => ve_dashboard_normalize_date_range(null, null, 7),
            ];
        }

        $userId = (int) $user['id'];
    }

    return ve_dashboard_summary($userId);
}

function ve_player_files(): array
{
    static $files;

    if (is_array($files)) {
        return $files;
    }

    $path = ve_root_path('api', 'player-files.json');

    if (!is_file($path)) {
        $files = [];
        return $files;
    }

    $payload = json_decode((string) file_get_contents($path), true);
    $files = is_array($payload) ? $payload : [];

    return $files;
}

function ve_player_file(string $slug): ?array
{
    $files = ve_player_files();
    $file = $files[$slug] ?? null;

    return is_array($file) ? $file : null;
}

function ve_render_player_page(string $slug): void
{
    $file = ve_player_file($slug);

    if ($file === null) {
        ve_not_found();
    }

    $title = trim((string) ($file['title'] ?? 'Untitled video'));
    $length = trim((string) ($file['length'] ?? ''));
    $size = trim((string) ($file['size'] ?? ''));
    $uploadDate = trim((string) ($file['upload_date'] ?? ''));
    $poster = trim((string) ($file['poster'] ?? ''));
    $downloadUrl = trim((string) ($file['download_url'] ?? ''));
    $embedUrl = trim((string) ($file['embed_url'] ?? ''));
    $ownFile = (bool) ($file['own_file'] ?? false);
    $countdown = max(0, (int) ($file['countdown_seconds'] ?? 5));

    $pageTitle = ve_h($title . ' - DoodStream');
    $safeTitle = ve_h($title);
    $safeLength = ve_h($length);
    $safeSize = ve_h($size);
    $safeUploadDate = ve_h($uploadDate);
    $safePoster = ve_h($poster);
    $safeDownloadUrl = ve_h($downloadUrl);
    $safeEmbedUrl = ve_h($embedUrl);
    $downloadPageUrl = ve_h(ve_absolute_url('/d/' . rawurlencode($slug)));
    $localEmbedUrl = ve_h(ve_absolute_url('/e/' . rawurlencode($slug)));
    $jqueryUrl = ve_h('https://cdnjs.cloudflare.com/ajax/libs/jquery/3.4.1/jquery.min.js');
    $bootstrapCssUrl = ve_h(ve_url('/assets/css/bootstrap.min.css'));
    $styleCssUrl = ve_h(ve_url('/assets/css/style.min.css'));
    $bootstrapJsUrl = ve_h('https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/4.2.1/js/bootstrap.min.js');
    $ownFileBanner = '';

    if ($ownFile) {
        $ownFileBanner = <<<HTML
        <div class="container mt-4">
            <div class="row">
                <div class="col-md-12 pt-2">
                    <p class="own-file text-center"><i class="fad fa-smile"></i><b>WoW!</b> as it is your own file we will not show any ads or adblock warnings, you can enjoy your file ad-free.</p>
                </div>
            </div>
        </div>
HTML;
    }

    ve_html(<<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>{$pageTitle}</title>
    <meta name="og:title" content="{$safeTitle}">
    <meta name="og:sitename" content="DoodStream.com">
    <meta name="og:image" content="{$safePoster}">
    <meta name="twitter:image" content="{$safePoster}">
    <meta name="robots" content="nofollow, noindex">
    <script src="{$jqueryUrl}"></script>
    <link rel="stylesheet" href="{$bootstrapCssUrl}">
    <link rel="stylesheet" href="{$styleCssUrl}">
    <style>
        [style*="--aspect-ratio"] > :first-child { width: 100%; }
        [style*="--aspect-ratio"] > img { height: auto; }
        @supports (--custom: property) {
            [style*="--aspect-ratio"] { position: relative; }
            [style*="--aspect-ratio"]::before {
                content: "";
                display: block;
                padding-bottom: calc(100% / (var(--aspect-ratio)));
            }
            [style*="--aspect-ratio"] > :first-child {
                position: absolute;
                top: 0;
                left: 0;
                height: 100%;
            }
        }
        .player-wrap iframe {
            width: 100%;
            height: 100%;
            min-height: 260px;
            border: 0;
        }
        .own-file {
            color: #12a701;
            border: 2px dashed #15bf00;
            padding: 10px 0 10px 40px;
            font-size: 15px;
            background: transparent;
        }
        .own-file .fad {
            font-size: 25px;
            position: absolute;
            margin-top: -1px;
            margin-left: -30px;
        }
        .title-wrap { background: #1c1c1c; }
        .nav-pills .nav-item { margin-right: 15px; }
        .nav-pills .nav-item .nav-link.active { background: #f90; color: #fff; }
        .nav-pills .nav-item .nav-link {
            font-weight: 600;
            color: #fff;
            background: #434645;
            border-radius: 1px;
            transition: color .3s ease, background .3s ease;
        }
        .v-owner {
            position: absolute;
            right: 8px;
            top: 5px;
            font-size: 12px;
            color: #6d6d6d;
        }
        .buttonInside {
            position: relative;
            margin-bottom: 10px;
        }
        .copy-in {
            position: absolute;
            right: 5px;
            top: 5px;
            border: none;
            outline: 0;
            text-align: center;
            font-weight: 700;
            padding: 2px 10px;
        }
        .copy-in:hover { cursor: pointer; }
        .export-txt {
            height: 42px !important;
            resize: none;
            padding-right: 68px;
        }
        .download-content { display: none; }
        .download-content .btn { gap: 12px; }
        .spinner-inline {
            display: inline-block;
            width: 18px;
            height: 18px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin .75s linear infinite;
            vertical-align: middle;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        @media (max-width: 767.98px) {
            .copy-in {
                position: static;
                width: 100%;
                margin-top: 8px;
            }
            .export-txt {
                padding-right: 12px;
                height: 60px !important;
            }
        }
    </style>
</head>
<body>
    {$ownFileBanner}
    <div class="player-wrap container">
        <div style="--aspect-ratio: 16/9;" id="os_player">
            <iframe src="{$safeEmbedUrl}" scrolling="no" frameborder="0" allowfullscreen="true"></iframe>
        </div>
    </div>

    <div class="container">
        <div class="title-wrap">
            <div class="d-flex flex-wrap align-items-center justify-content-between">
                <div class="info">
                    <h4>{$safeTitle}</h4>
                    <div class="d-flex flex-wrap align-items-center text-muted font-weight-bold">
                        <div class="length"><i class="fad fa-clock mr-1"></i>{$safeLength}</div>
                        <span class="mx-2"></span>
                        <div class="size"><i class="fad fa-save mr-1"></i>{$safeSize}</div>
                        <span class="mx-2"></span>
                        <div class="uploadate"><i class="fad fa-calendar-alt mr-1"></i>{$safeUploadDate}</div>
                    </div>
                </div>
                <a href="#lights" class="btn btn-white player_lights off">
                    <i class="fad fa-lightbulb-on"></i>
                </a>
            </div>
        </div>
    </div>

    <div class="container my-3">
        <div class="video-content text-center">
            <ul class="nav nav-pills mb-3" id="pills-tab" role="tablist">
                <li class="nav-item">
                    <a class="nav-link active" id="pills-dr-tab" data-toggle="pill" href="#pills-dr" role="tab" aria-controls="pills-dr" aria-selected="true">Download link</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="pills-el-tab" data-toggle="pill" href="#pills-el" role="tab" aria-controls="pills-el" aria-selected="false">Embed link</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="pills-elc-tab" data-toggle="pill" href="#pills-elc" role="tab" aria-controls="pills-elc" aria-selected="false">Embed code</a>
                </li>
            </ul>
            <div class="tab-content" id="pills-tabContent">
                <div class="v-owner">only visible to the file owner</div>
                <div class="tab-pane fade show active buttonInside" id="pills-dr" role="tabpanel" aria-labelledby="pills-dr-tab">
                    <textarea id="code_txt" class="form-control export-txt">{$downloadPageUrl}</textarea>
                    <button class="copy-in btn btn-primary btn-sm" type="button" data-copy-target="code_txt">copy</button>
                </div>
                <div class="tab-pane fade buttonInside" id="pills-el" role="tabpanel" aria-labelledby="pills-el-tab">
                    <textarea id="code_txt_e" class="form-control export-txt">{$localEmbedUrl}</textarea>
                    <button class="copy-in btn btn-primary btn-sm" type="button" data-copy-target="code_txt_e">copy</button>
                </div>
                <div class="tab-pane fade buttonInside" id="pills-elc" role="tabpanel" aria-labelledby="pills-elc-tab">
                    <textarea id="code_txt_ec" class="form-control export-txt">&lt;iframe width="600" height="480" src="{$localEmbedUrl}" scrolling="no" frameborder="0" allowfullscreen="true"&gt;&lt;/iframe&gt;</textarea>
                    <button class="copy-in btn btn-primary btn-sm" type="button" data-copy-target="code_txt_ec">copy</button>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="video-content text-center">
            <div class="countdown">Please wait <span id="seconds">{$countdown}</span> seconds</div>
            <a href="#download_now" class="btn btn-primary download_vd">Download Now <i class="fad fa-arrow-right ml-2"></i></a>
            <div class="download-content">
                <label class="label-playlist d-block">Download video</label>
                <a href="{$safeDownloadUrl}" class="btn btn-primary d-flex align-items-center justify-content-between">
                    <span>Original<small class="d-block">{$safeSize}</small></span>
                    <i class="fad fa-cloud-download"></i>
                </a>
            </div>
        </div>
    </div>

    <script src="{$bootstrapJsUrl}"></script>
    <script>
        (function () {
            function copyText(targetId, button) {
                var field = document.getElementById(targetId);
                if (!field) {
                    return;
                }

                field.focus();
                field.select();
                field.setSelectionRange(0, field.value.length);

                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(field.value);
                } else {
                    document.execCommand('copy');
                }

                var original = button.textContent;
                button.textContent = 'copied';
                window.setTimeout(function () {
                    button.textContent = original;
                }, 1200);
            }

            document.querySelectorAll('[data-copy-target]').forEach(function (button) {
                button.addEventListener('click', function () {
                    copyText(button.getAttribute('data-copy-target'), button);
                });
            });

            $('.download_vd').on('click', function (event) {
                event.preventDefault();

                var button = $(this);
                var seconds = {$countdown};
                button.prop('disabled', true)
                    .addClass('loading disabled')
                    .html('<span class="spinner-inline" aria-hidden="true"></span>');

                var timer = window.setInterval(function () {
                    seconds -= 1;
                    $('.countdown').show();
                    $('#seconds').text(Math.max(seconds, 0));

                    if (seconds <= 0) {
                        window.clearInterval(timer);
                        $('.countdown').remove();
                        button.remove();
                        $('.download-content').show('slow');
                    }
                }, 1000);
            });

            $(document).on('click', '.player_lights', function (event) {
                event.preventDefault();
                var button = $(this);

                if (button.hasClass('off')) {
                    button.removeClass('off').addClass('on');
                    button.html('<i class="fad fa-lightbulb"></i>');
                    $('body').append('<div class="modal-backdrop fade" id="player-page-fade"></div>');
                    $('#player-page-fade').fadeTo('slow', 0.8);
                    return;
                }

                button.removeClass('on').addClass('off');
                button.html('<i class="fad fa-lightbulb-on"></i>');
                $('#player-page-fade').fadeTo('slow', 0, function () {
                    $('#player-page-fade').remove();
                });
            });
        }());
    </script>
</body>
</html>
HTML);
}

function ve_settings_panel(string $title, string $description, string $buttonLabel): string
{
    return <<<HTML
<div class="the_box">
    <h4 class="mb-3">{$title}</h4>
    <p class="text-muted mb-4">{$description}</p>
    <div class="form-group">
        <input type="text" class="form-control" placeholder="Demo value">
    </div>
    <button type="button" class="btn btn-primary">{$buttonLabel}</button>
</div>
HTML;
}

function ve_payment_page(string $title): string
{
    $backUrl = ve_url('/premium-plans');
    $bootstrapUrl = ve_url('/assets/css/bootstrap.min.css');

    return <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$title}</title>
    <link rel="stylesheet" href="{$bootstrapUrl}">
    <style>
        body { background:#111; color:#fff; font-family:Arial,sans-serif; }
        .box { max-width:720px; margin:80px auto; background:#1c1c1c; padding:32px; border-radius:12px; }
        .btn-primary { background:#ff9900; border-color:#ff9900; }
    </style>
</head>
<body>
    <div class="box">
        <h1 class="h3 mb-3">{$title}</h1>
        <p class="mb-4">This checkout endpoint is stubbed for frontend integration. Replace it with a real payment flow when the backend is ready.</p>
        <a class="btn btn-primary" href="{$backUrl}">Back to premium plans</a>
    </div>
</body>
</html>
HTML;
}

function ve_back_redirect(string $fallback): void
{
    $target = $_SERVER['HTTP_REFERER'] ?? $fallback;
    ve_redirect($target);
}

function ve_legacy_endpoint_removed(string $replacementPath, array $allowedMethods = ['POST']): void
{
    http_response_code(410);

    if ($replacementPath !== '') {
        header('X-Replacement-Endpoint: ' . ve_url($replacementPath));
    }

    if ($allowedMethods !== []) {
        header('Allow: ' . implode(', ', $allowedMethods));
    }

    ve_json([
        'status' => 'fail',
        'message' => 'Legacy endpoint removed. Use ' . ve_url($replacementPath) . '.',
    ], 410);
}

function ve_handle_op(string $op, string $path): bool
{
    return ve_dispatch_legacy_route($op, $path);
}

function ve_dispatch(): void
{
    ve_bootstrap();
    $path = ve_request_path();
    $op = $_REQUEST['op'] ?? null;

    if (ve_dispatch_api_routes($path)) {
        return;
    }

    if ($path === '/login') {
        if (!ve_is_method('POST')) {
            ve_method_not_allowed(['POST']);
        }

        ve_handle_login_ajax();
    }

    if ($path === '/register') {
        if (!ve_is_method('POST')) {
            ve_method_not_allowed(['POST']);
        }

        ve_handle_registration_ajax();
    }

    if ($path === '/password/forgot') {
        if (!ve_is_method('POST')) {
            ve_method_not_allowed(['POST']);
        }

        ve_handle_forgot_password_ajax();
    }

    if ($path === '/password/reset') {
        if (!ve_is_method('POST')) {
            ve_method_not_allowed(['POST']);
        }

        ve_handle_forgot_password_ajax();
    }

    if ($path === '/logout') {
        if (!ve_is_method('POST')) {
            ve_method_not_allowed(['POST']);
        }

        ve_require_csrf(ve_request_csrf_token());
        ve_logout_current_user();
        ve_redirect('/');
    }

    if ($path === '/api/notifications') {
        if (ve_is_method('GET')) {
            $user = ve_current_user();
            ve_json(is_array($user) ? ve_fetch_notifications((int) $user['id']) : []);
        }

        if (ve_is_method('DELETE')) {
            $user = ve_require_auth();
            ve_require_csrf(ve_request_csrf_token());
            $deletedCount = ve_clear_notifications((int) $user['id']);
            ve_json([
                'status' => 'ok',
                'message' => $deletedCount > 0 ? 'All notifications deleted.' : 'There were no notifications to delete.',
            ]);
        }

        ve_method_not_allowed(['GET', 'DELETE']);
    }

    if (preg_match('#^/api/notifications/(\d+)/read$#', $path, $matches) === 1) {
        if (!ve_is_method('POST')) {
            ve_method_not_allowed(['POST']);
        }

        $user = ve_require_auth();
        ve_require_csrf(ve_request_csrf_token());
        ve_mark_notification_read((int) $user['id'], (int) $matches[1]);
        ve_json([
            'status' => 'ok',
        ]);
    }

    if (preg_match('#^/api/notifications/(\d+)$#', $path, $matches) === 1) {
        if (!ve_is_method('DELETE')) {
            ve_method_not_allowed(['DELETE']);
        }

        $user = ve_require_auth();
        ve_require_csrf(ve_request_csrf_token());
        $deleted = ve_delete_notification((int) $user['id'], (int) $matches[1]);
        ve_json([
            'status' => $deleted ? 'ok' : 'fail',
            'message' => $deleted ? 'Notification deleted.' : 'Notification not found.',
        ], $deleted ? 200 : 404);
    }

    if ($path === '/api/dashboard/summary') {
        if (!ve_is_method('GET')) {
            ve_method_not_allowed(['GET']);
        }

        $user = ve_current_user();

        if (!is_array($user)) {
            ve_json([
                'status' => 'fail',
                'message' => 'Authentication required.',
            ], 401);
        }

        ve_json(ve_dashboard_summary((int) $user['id']));
    }

    if ($path === '/api/dashboard/reports') {
        if (!ve_is_method('GET')) {
            ve_method_not_allowed(['GET']);
        }

        $user = ve_current_user();

        if (!is_array($user)) {
            ve_json([
                'status' => 'fail',
                'message' => 'Authentication required.',
            ], 401);
        }

        ve_json(ve_dashboard_reports_snapshot(
            (int) $user['id'],
            isset($_GET['from']) ? (string) $_GET['from'] : (isset($_GET['date1']) ? (string) $_GET['date1'] : null),
            isset($_GET['to']) ? (string) $_GET['to'] : (isset($_GET['date2']) ? (string) $_GET['date2'] : null)
        ));
    }

    if ($path === '/api/dashboard/referrals') {
        if (!ve_is_method('GET')) {
            ve_method_not_allowed(['GET']);
        }

        $user = ve_current_user();

        if (!is_array($user)) {
            ve_json([
                'status' => 'fail',
                'message' => 'Authentication required.',
            ], 401);
        }

        ve_json(ve_referral_snapshot((int) $user['id']));
    }

    if ($path === '/api/videos') {
        if (!ve_is_method('GET')) {
            ve_method_not_allowed(['GET']);
        }

        ve_handle_video_list_api();
    }

    if ($path === '/api/videos/upload') {
        if (!ve_is_method('POST')) {
            ve_method_not_allowed(['POST']);
        }

        ve_handle_video_upload_api();
    }

    if (preg_match('#^/upload/([A-Za-z0-9_-]+)$#', $path, $matches) === 1) {
        if (!ve_is_method('POST')) {
            ve_method_not_allowed(['POST']);
        }

        if ($matches[1] !== 'local') {
            ve_not_found();
        }

        ve_handle_legacy_upload_endpoint();
    }

    if (preg_match('#^/api/videos/([A-Za-z0-9_-]+)/poster\.jpg$#', $path, $matches) === 1) {
        if (!ve_is_method('GET')) {
            ve_method_not_allowed(['GET']);
        }

        ve_video_owner_stream_poster($matches[1]);
    }

    if (preg_match('#^/api/videos/([A-Za-z0-9_-]+)$#', $path, $matches) === 1) {
        if (!ve_is_method('DELETE')) {
            ve_method_not_allowed(['DELETE']);
        }

        ve_handle_video_delete_api($matches[1]);
    }

    if ($path === '/api/domains') {
        if (ve_is_method('GET')) {
            ve_handle_custom_domain_list();
        }

        if (ve_is_method('POST')) {
            ve_handle_custom_domain_add();
        }

        ve_method_not_allowed(['GET', 'POST']);
    }

    if ($path === '/api/premium/summary') {
        if (!ve_is_method('GET')) {
            ve_method_not_allowed(['GET']);
        }

        $user = ve_require_auth();
        ve_json([
            'status' => 'ok',
            'summary' => ve_premium_page_payload((int) $user['id']),
        ]);
    }

    if ($path === '/api/premium/checkout/quote') {
        if (!ve_is_method('POST')) {
            ve_method_not_allowed(['POST']);
        }

        ve_handle_premium_checkout_quote();
    }

    if ($path === '/api/premium/checkout/balance') {
        if (!ve_is_method('POST')) {
            ve_method_not_allowed(['POST']);
        }

        ve_handle_premium_checkout_balance();
    }

    if ($path === '/api/premium/checkout/crypto') {
        if (!ve_is_method('POST')) {
            ve_method_not_allowed(['POST']);
        }

        ve_handle_premium_checkout_crypto();
    }

    if (preg_match('#^/api/domains/(.+)$#', $path, $matches) === 1) {
        if (!ve_is_method('DELETE')) {
            ve_method_not_allowed(['DELETE']);
        }

        $_POST['domain'] = rawurldecode($matches[1]);
        ve_handle_custom_domain_delete();
    }

    if ($path === '/api/account/api-usage') {
        if (!ve_is_method('GET')) {
            ve_method_not_allowed(['GET']);
        }

        $user = ve_require_auth();
        ve_json([
            'status' => 'ok',
            'api' => ve_api_usage_snapshot((int) $user['id']),
        ]);
    }

    if ($path === '/api/account/remote-upload') {
        if (!ve_is_method('GET')) {
            ve_method_not_allowed(['GET']);
        }

        $user = ve_require_auth();
        ve_json([
            'status' => 'ok',
            'remote_upload' => ve_remote_host_dashboard_snapshot($user),
        ]);
    }

    if ($path === '/account/settings') {
        if (!ve_is_method('POST')) {
            ve_method_not_allowed(['POST']);
        }

        $user = ve_require_auth();
        ve_save_account_settings((int) $user['id']);
    }

    if ($path === '/account/password') {
        if (!ve_is_method('POST')) {
            ve_method_not_allowed(['POST']);
        }

        $user = ve_require_auth();
        ve_save_password((int) $user['id']);
    }

    if ($path === '/account/email') {
        if (!ve_is_method('POST')) {
            ve_method_not_allowed(['POST']);
        }

        $user = ve_require_auth();
        ve_save_email((int) $user['id']);
    }

    if ($path === '/account/player') {
        if (!ve_is_method('POST')) {
            ve_method_not_allowed(['POST']);
        }

        $user = ve_require_auth();
        ve_save_player_settings((int) $user['id']);
    }

    if ($path === '/account/player/splash-preview') {
        if (!ve_is_method('GET')) {
            ve_method_not_allowed(['GET']);
        }

        $user = ve_require_auth();
        ve_render_player_splash_preview((int) $user['id']);
    }

    if ($path === '/account/advertising') {
        if (!ve_is_method('POST')) {
            ve_method_not_allowed(['POST']);
        }

        $user = ve_require_auth();
        ve_save_ad_settings((int) $user['id']);
    }

    if ($path === '/account/api-settings') {
        if (!ve_is_method('POST')) {
            ve_method_not_allowed(['POST']);
        }

        $user = ve_require_auth();
        ve_save_api_settings((int) $user['id']);
    }

    if ($path === '/account/remote-upload') {
        if (!ve_is_method('POST')) {
            ve_method_not_allowed(['POST']);
        }

        $user = ve_remote_host_require_manager();
        ve_save_remote_host_settings((int) $user['id']);
    }

    if ($path === '/account/delete') {
        if (!ve_is_method('POST')) {
            ve_method_not_allowed(['POST']);
        }

        ve_handle_delete_account();
    }

    if ($path === '/account/api-key/regenerate') {
        if (!ve_is_method('POST')) {
            ve_method_not_allowed(['POST']);
        }

        $user = ve_require_auth();
        ve_require_csrf(ve_request_csrf_token());
        $apiKey = ve_regenerate_api_key_for_user((int) $user['id']);
        ve_json([
            'status' => 'ok',
            'message' => 'API key regenerated successfully.',
            'api_key' => $apiKey,
            'api' => ve_api_usage_snapshot((int) $user['id']),
        ]);
    }

    if (is_string($op) && $op !== '' && ve_handle_op($op, $path)) {
        return;
    }

    if ($path === '/data/dashboard-update.json' || $path === '/dl') {
        if ($path === '/dl' && (($_GET['op'] ?? null) !== 'dashboard' || !isset($_GET['update']))) {
            ve_not_found();
        }

        $user = ve_current_user();

        if (!is_array($user)) {
            ve_json([
                'status' => 'fail',
                'message' => 'Authentication required.',
            ], 401);
        }

        ve_json(ve_dashboard_stats((int) $user['id']));
    }

    if ($path === '/genrate-api/' || $path === '/genrate-api') {
        ve_legacy_endpoint_removed('/account/api-key/regenerate', ['POST']);
    }

    if ($path === '/backend' || str_starts_with($path, '/backend/')) {
        ve_handle_backend_request();
    }

    if (str_starts_with($path, '/subscene/')) {
        ve_json([
            'status' => 'ok',
            'results' => [],
        ]);
    }

    if ($path === '/' || $path === '/index.php') {
        ve_render_home_page();
    }

    if ($path === '/index.html') {
        ve_redirect('/');
    }

    if ($path === '/reset-password') {
        $token = trim((string) ($_GET['token'] ?? ''));
        ve_render_reset_password_page($token);
    }

    if (preg_match('#^/d/([A-Za-z0-9_-]+)$#', $path, $matches) === 1) {
        $video = ve_video_get_by_public_id($matches[1]);

        if (is_array($video)) {
            ve_render_secure_watch_page($matches[1]);
        }

        ve_render_player_page($matches[1]);
    }

    if (preg_match('#^/e/([A-Za-z0-9_-]+)$#', $path, $matches) === 1) {
        $video = ve_video_get_by_public_id($matches[1]);

        if (is_array($video)) {
            ve_render_secure_video_page($matches[1], true);
        }

        $file = ve_player_file($matches[1]);

        if ($file === null || !isset($file['embed_url']) || !is_string($file['embed_url']) || $file['embed_url'] === '') {
            ve_not_found();
        }

        ve_redirect($file['embed_url']);
    }

    if (preg_match('#^/download/([A-Za-z0-9_-]+)$#', $path, $matches) === 1) {
        ve_video_download_file($matches[1]);
    }

    if (preg_match('#^/stream/([A-Za-z0-9_-]+)/manifest\.m3u8$#', $path, $matches) === 1) {
        ve_video_stream_manifest($matches[1]);
    }

    if (preg_match('#^/stream/([A-Za-z0-9_-]+)/key$#', $path, $matches) === 1) {
        ve_video_stream_key($matches[1]);
    }

    if (preg_match('#^/stream/([A-Za-z0-9_-]+)/poster\.jpg$#', $path, $matches) === 1) {
        ve_video_stream_poster($matches[1]);
    }

    if (preg_match('#^/thumbs/([A-Za-z0-9_-]+)/(single|splash)\.jpg$#', $path, $matches) === 1) {
        if (!ve_is_method('GET')) {
            ve_method_not_allowed(['GET']);
        }

        ve_video_stream_public_thumbnail($matches[1], $matches[2] === 'splash' ? 'splash' : 'single');
    }

    if (preg_match('#^/stream/([A-Za-z0-9_-]+)/preview\.vtt$#', $path, $matches) === 1) {
        ve_video_stream_preview_vtt($matches[1]);
    }

    if (preg_match('#^/stream/([A-Za-z0-9_-]+)/preview\.jpg$#', $path, $matches) === 1) {
        ve_video_stream_preview_sprite($matches[1]);
    }

    if (preg_match('#^/stream/([A-Za-z0-9_-]+)/segment/([^/]+)$#', $path, $matches) === 1) {
        ve_video_stream_segment($matches[1], rawurldecode($matches[2]));
    }

    if (preg_match('#^/join/([A-Za-z0-9_-]+)(?:\.html)?$#', $path, $matches) === 1) {
        if (!ve_is_method('GET')) {
            ve_method_not_allowed(['GET']);
        }

        ve_referral_handle_join($matches[1]);
    }

    if ($path === '/premium-plans' || $path === '/premium-plans.html') {
        ve_require_auth();
        ve_render_dashboard_file(VE_DASHBOARD_PAGES['premium-plans']);
    }

    if ($path === '/dashboard/videos' || $path === '/dashboard/videos.html') {
        $query = trim((string) ($_SERVER['QUERY_STRING'] ?? ''));
        ve_redirect('/videos' . ($query !== '' ? '?' . $query : ''));
    }

    if ($path === '/videos' || $path === '/videos/' || $path === '/videos.html') {
        ve_require_auth();
        ve_render_videos_dashboard_page();
    }

    if (preg_match('#^/videos/shared/([A-Za-z0-9_-]+)$#', $path, $matches) === 1) {
        if (!ve_is_method('GET')) {
            ve_method_not_allowed(['GET']);
        }

        ve_render_public_folder_page($matches[1]);
    }

    foreach (VE_LEGACY_DASHBOARD_ROUTES as $legacy => $target) {
        if ($legacy === 'videos') {
            continue;
        }

        if ($path === '/' . $legacy || $path === '/' . $legacy . '.html') {
            $query = trim((string) ($_SERVER['QUERY_STRING'] ?? ''));
            ve_redirect('/dashboard/' . $target . ($query !== '' ? '?' . $query : ''));
        }
    }

    foreach (VE_SITE_PAGES as $slug => $file) {
        if ($path === '/' . $slug || $path === '/' . $slug . '.html') {
            ve_render_file($file);
        }
    }

    if ($path === '/dashboard' || $path === '/dashboard/' || $path === '/dashboard/index.html') {
        ve_require_auth();
        ve_render_dashboard_file(VE_DASHBOARD_PAGES['']);
    }

    if (str_starts_with($path, '/dashboard/')) {
        $slug = trim(substr($path, strlen('/dashboard/')), '/');
        $slug = preg_replace('/\.html$/', '', $slug ?? '');

        if (is_string($slug) && array_key_exists($slug, VE_DASHBOARD_PAGES)) {
            ve_require_auth();

            if ($slug === 'settings') {
                ve_render_settings_page();
            }

            if ($slug === 'videos') {
                ve_render_videos_dashboard_page();
            }

            if ($slug === 'reports') {
                ve_render_reports_page();
            }

            ve_render_dashboard_file(VE_DASHBOARD_PAGES[$slug]);
        }
    }

    ve_not_found();
}
