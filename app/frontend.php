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

function ve_request_path(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

    if (!is_string($path) || $path === '') {
        return '/';
    }

    $path = rawurldecode($path);

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

function ve_html(string $html, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    echo $html;
    exit;
}

function ve_redirect(string $location, int $status = 302): void
{
    http_response_code($status);
    header('Location: ' . $location);
    exit;
}

function ve_render_file(string $relativePath, int $status = 200): void
{
    $fullPath = ve_root_path($relativePath);

    if (!is_file($fullPath)) {
        ve_not_found();
    }

    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    readfile($fullPath);
    exit;
}

function ve_not_found(): void
{
    ve_html(
        '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Not Found</title></head><body style="font-family:sans-serif;background:#111;color:#fff;padding:40px"><h1>404</h1><p>Route not found.</p></body></html>',
        404
    );
}

function ve_dashboard_stats(): array
{
    $file = ve_root_path('api', 'dashboard-update.json');

    if (!is_file($file)) {
        return [
            'online' => 0,
            'today' => '$0.00000',
            'balance' => '$0.00000',
        ];
    }

    $payload = json_decode((string) file_get_contents($file), true);

    return is_array($payload) ? $payload : [
        'online' => 0,
        'today' => '$0.00000',
        'balance' => '$0.00000',
    ];
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
    $backUrl = '/dashboard/premium-plans';

    return <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$title}</title>
    <link rel="stylesheet" href="/assets/i.doodcdn.io/theme_2/css/bootstrap.min.css">
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

function ve_handle_op(string $op, string $path): bool
{
    switch ($op) {
        case 'notifications':
            ve_json([]);

        case 'login_ajax':
            ve_json([
                'status' => 'redirect',
                'message' => '/dashboard',
            ]);

        case 'registration_ajax':
            ve_json([
                'status' => 'ok',
                'message' => 'Frontend registration stub completed. Continue in the dashboard.',
            ]);

        case 'forgot_pass_ajax':
            ve_json([
                'status' => 'ok',
                'message' => 'Password reset is not wired yet. Backend endpoint is stubbed for frontend testing.',
            ]);

        case 'logout':
            ve_redirect('/');

        case 'my_password':
            ve_html(ve_settings_panel('Change password', 'Stubbed response for the settings drawer.', 'Save password'));

        case 'my_email':
            ve_html(ve_settings_panel('Change email', 'Stubbed response for the settings drawer.', 'Save email'));

        case 'upload_logo':
            ve_html(ve_settings_panel('Player settings', 'Upload handling is not implemented yet, but the UI endpoint is live.', 'Save player settings'));

        case 'premium_settings':
            ve_html(ve_settings_panel('Own adverts', 'Premium advert settings will be backed by a real API later.', 'Save advert settings'));

        case 'dmca_manager':
            if (isset($_GET['loadmore'])) {
                ve_html('NOK');
            }
            return false;

        case 'videos_json':
            ve_json([
                'draw' => (int) ($_GET['draw'] ?? 1),
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => [],
                'list' => [],
                'folders' => [],
                'folders_tree' => [],
                'folder_id' => 0,
                'bli' => 0,
                'max' => 0,
                'slo' => 0,
            ]);

        case 'remote_upload_json':
            ve_json([
                'list' => [],
                'bli' => 0,
                'folders_tree' => [],
                'slo' => 0,
                'max' => 0,
            ]);

        case 'upload_get_srv':
            ve_json([
                'status' => 'ok',
                'server' => 'local',
                'upload_url' => '/dashboard/remote-upload',
            ]);

        case 'pass_file':
        case 'change_thumbnail':
        case 'folder_sharing':
        case 'marker':
            ve_json([
                'status' => 'ok',
                'message' => 'Stubbed endpoint response.',
            ]);

        case 'payments':
            if (isset($_GET['amount'])) {
                ve_html(ve_payment_page('Payment checkout'));
            }

            ve_json([
                'status' => 'ok',
                'message' => 'Payment endpoint is stubbed.',
            ]);

        case 'crypto_payments':
            ve_json([
                'status' => 'ok',
                'message' => 'Crypto payment endpoint is stubbed.',
            ]);

        case 'register_save':
        case 'forgot_pass':
        case 'my_account':
        case 'my_reports':
        case 'request_money':
            ve_back_redirect($path === '/' ? '/dashboard' : $path);

        default:
            return false;
    }
}

function ve_dispatch(): void
{
    $path = ve_request_path();
    $op = $_REQUEST['op'] ?? null;

    if (is_string($op) && $op !== '' && ve_handle_op($op, $path)) {
        return;
    }

    if ($path === '/data/dashboard-update.json' || $path === '/dl') {
        if ($path === '/dl' && (($_GET['op'] ?? null) !== 'dashboard' || !isset($_GET['update']))) {
            ve_not_found();
        }

        ve_json(ve_dashboard_stats());
    }

    if ($path === '/genrate-api/' || $path === '/genrate-api') {
        ve_redirect('/dashboard/settings');
    }

    if (str_starts_with($path, '/subscene/')) {
        ve_json([
            'status' => 'ok',
            'results' => [],
        ]);
    }

    if ($path === '/' || $path === '/index.php') {
        ve_render_file('index.html');
    }

    if ($path === '/index.html') {
        ve_redirect('/');
    }

    foreach (VE_LEGACY_DASHBOARD_ROUTES as $legacy => $target) {
        if ($path === '/' . $legacy || $path === '/' . $legacy . '.html') {
            ve_redirect('/dashboard/' . $target);
        }
    }

    foreach (VE_SITE_PAGES as $slug => $file) {
        if ($path === '/' . $slug || $path === '/' . $slug . '.html') {
            ve_render_file($file);
        }
    }

    if ($path === '/dashboard' || $path === '/dashboard/' || $path === '/dashboard/index.html') {
        ve_render_file(VE_DASHBOARD_PAGES['']);
    }

    if (str_starts_with($path, '/dashboard/')) {
        $slug = trim(substr($path, strlen('/dashboard/')), '/');
        $slug = preg_replace('/\.html$/', '', $slug ?? '');

        if (is_string($slug) && array_key_exists($slug, VE_DASHBOARD_PAGES)) {
            ve_render_file(VE_DASHBOARD_PAGES[$slug]);
        }
    }

    ve_not_found();
}
