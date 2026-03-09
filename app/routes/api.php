<?php

declare(strict_types=1);

function ve_dispatch_api_routes(string $path): bool
{
    if ($path === '/api/auth/login' || $path === '/login') {
        if (!ve_is_method('POST')) {
            ve_method_not_allowed(['POST']);
        }

        ve_handle_login_ajax();
    }

    if ($path === '/api/auth/register' || $path === '/register') {
        if (!ve_is_method('POST')) {
            ve_method_not_allowed(['POST']);
        }

        ve_handle_registration_ajax();
    }

    if ($path === '/api/auth/forgot' || $path === '/password/forgot') {
        if (!ve_is_method('POST')) {
            ve_method_not_allowed(['POST']);
        }

        ve_handle_forgot_password_ajax();
    }

    if ($path === '/api/auth/reset' || $path === '/password/reset') {
        if (!ve_is_method('POST')) {
            ve_method_not_allowed(['POST']);
        }

        ve_handle_forgot_password_ajax();
    }

    if ($path === '/api/auth/logout' || $path === '/logout') {
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

    if ($path === '/api/dashboard/update' || $path === '/data/dashboard-update.json' || $path === '/dl') {
        if (!ve_is_method('GET')) {
            ve_method_not_allowed(['GET']);
        }

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

    if ($path === '/api/videos') {
        if (!ve_is_method('GET')) {
            ve_method_not_allowed(['GET']);
        }

        ve_handle_video_list_api();
    }

    if ($path === '/api/videos/actions') {
        if (!ve_is_method('GET') && !ve_is_method('POST')) {
            ve_method_not_allowed(['GET', 'POST']);
        }

        ve_handle_legacy_videos_json();
    }

    if ($path === '/api/videos/upload') {
        if (!ve_is_method('POST')) {
            ve_method_not_allowed(['POST']);
        }

        ve_handle_video_upload_api();
    }

    if (preg_match('#^/api/videos/([A-Za-z0-9_-]+)/download/request$#', $path, $matches) === 1) {
        ve_video_download_request_api($matches[1]);
    }

    if (preg_match('#^/api/videos/([A-Za-z0-9_-]+)/download/resolve$#', $path, $matches) === 1) {
        ve_video_download_resolve_api($matches[1]);
    }

    if ($path === '/api/videos/upload-target') {
        if (!ve_is_method('GET')) {
            ve_method_not_allowed(['GET']);
        }

        ve_handle_legacy_upload_get_server();
    }

    if ($path === '/api/uploads/check') {
        if (!ve_is_method('GET') && !ve_is_method('POST')) {
            ve_method_not_allowed(['GET', 'POST']);
        }

        ve_handle_legacy_pass_file();
    }

    if ($path === '/api/uploads/result') {
        if (!ve_is_method('POST')) {
            ve_method_not_allowed(['POST']);
        }

        ve_handle_legacy_upload_results();
    }

    if ($path === '/api/videos/subtitles') {
        if (!ve_is_method('GET') && !ve_is_method('POST')) {
            ve_method_not_allowed(['GET', 'POST']);
        }

        ve_handle_legacy_add_srt();
    }

    if ($path === '/api/videos/thumbnail') {
        if (!ve_is_method('GET')) {
            ve_method_not_allowed(['GET']);
        }

        ve_handle_legacy_change_thumbnail();
    }

    if ($path === '/api/folders/share') {
        if (!ve_is_method('GET')) {
            ve_method_not_allowed(['GET']);
        }

        ve_handle_legacy_folder_sharing();
    }

    if ($path === '/api/videos/markers') {
        if (!ve_is_method('GET')) {
            ve_method_not_allowed(['GET']);
        }

        ve_handle_legacy_marker();
    }

    if ($path === '/api/remote/jobs') {
        if (!ve_is_method('GET') && !ve_is_method('POST')) {
            ve_method_not_allowed(['GET', 'POST']);
        }

        foreach ($_POST as $key => $value) {
            if (!array_key_exists($key, $_GET)) {
                $_GET[$key] = $value;
            }
        }

        ve_handle_remote_upload_json();
    }

    if ($path === '/api/dmca') {
        if (!ve_is_method('GET')) {
            ve_method_not_allowed(['GET']);
        }

        if (isset($_GET['loadmore'])) {
            ve_html('NOK');
        }

        ve_json([
            'status' => 'ok',
            'items' => [],
        ]);
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

    if (preg_match('#^/api/domains/(.+)$#', $path, $matches) === 1) {
        if (!ve_is_method('DELETE')) {
            ve_method_not_allowed(['DELETE']);
        }

        $_POST['domain'] = rawurldecode($matches[1]);
        ve_handle_custom_domain_delete();
    }

    if ($path === '/api/account/profile' || $path === '/account/settings') {
        if (!ve_is_method('POST')) {
            ve_method_not_allowed(['POST']);
        }

        $user = ve_require_auth();
        ve_save_account_settings((int) $user['id']);
    }

    if ($path === '/api/account/password' || $path === '/account/password') {
        if (!ve_is_method('POST')) {
            ve_method_not_allowed(['POST']);
        }

        $user = ve_require_auth();
        ve_save_password((int) $user['id']);
    }

    if ($path === '/api/account/email' || $path === '/account/email') {
        if (!ve_is_method('POST')) {
            ve_method_not_allowed(['POST']);
        }

        $user = ve_require_auth();
        ve_save_email((int) $user['id']);
    }

    if ($path === '/api/account/player' || $path === '/account/player') {
        if (!ve_is_method('POST')) {
            ve_method_not_allowed(['POST']);
        }

        $user = ve_require_auth();
        ve_save_player_settings((int) $user['id']);
    }

    if ($path === '/api/account/player/preview' || $path === '/account/player/splash-preview') {
        if (!ve_is_method('GET')) {
            ve_method_not_allowed(['GET']);
        }

        $user = ve_require_auth();
        ve_render_player_splash_preview((int) $user['id']);
    }

    if ($path === '/api/account/ads' || $path === '/account/advertising') {
        if (!ve_is_method('POST')) {
            ve_method_not_allowed(['POST']);
        }

        $user = ve_require_auth();
        ve_save_ad_settings((int) $user['id']);
    }

    if ($path === '/api/account/api' || $path === '/account/api-settings') {
        if (!ve_is_method('POST')) {
            ve_method_not_allowed(['POST']);
        }

        $user = ve_require_auth();
        ve_save_api_settings((int) $user['id']);
    }

    if ($path === '/api/account/delete' || $path === '/account/delete') {
        if (!ve_is_method('POST')) {
            ve_method_not_allowed(['POST']);
        }

        ve_handle_delete_account();
    }

    if ($path === '/api/account/key' || $path === '/account/api-key/regenerate') {
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

    if ($path === '/api/payouts/request') {
        if (!ve_is_method('POST')) {
            ve_method_not_allowed(['POST']);
        }

        ve_require_auth();
        ve_require_csrf(ve_request_csrf_token());
        ve_json([
            'status' => 'warning',
            'message' => 'Payout requests are not enabled in this build yet.',
        ]);
    }

    if ($path === '/api/billing/quote' || $path === '/api/premium/checkout/quote') {
        if (!ve_is_method('POST')) {
            ve_method_not_allowed(['POST']);
        }

        ve_handle_premium_checkout_quote();
    }

    if ($path === '/api/billing/balance' || $path === '/api/premium/checkout/balance') {
        if (!ve_is_method('POST')) {
            ve_method_not_allowed(['POST']);
        }

        ve_handle_premium_checkout_balance();
    }

    if ($path === '/api/billing/crypto' || $path === '/api/premium/checkout/crypto') {
        if (!ve_is_method('POST')) {
            ve_method_not_allowed(['POST']);
        }

        ve_handle_premium_checkout_crypto();
    }

    if ($path === '/api/billing/paypal') {
        if (!ve_is_method('GET')) {
            ve_method_not_allowed(['GET']);
        }

        $amount = trim((string) ($_GET['amount'] ?? ''));
        $isBandwidth = isset($_GET['premium_bw']);
        $title = $isBandwidth ? 'Premium bandwidth checkout' : 'Premium account checkout';

        if ($amount !== '') {
            $title .= ' - $' . $amount;
        }

        ve_html(ve_payment_page($title));
    }

    if ($path === '/genrate-api/' || $path === '/genrate-api') {
        ve_legacy_endpoint_removed('/api/account/key', ['POST']);
    }

    return false;
}
