<?php

declare(strict_types=1);

function ve_dispatch_legacy_route(string $op, string $path): bool
{
    switch ($op) {
        case 'notifications':
            ve_legacy_endpoint_removed('/api/notifications', ['GET']);

        case 'login_ajax':
            ve_legacy_endpoint_removed('/api/auth/login', ['POST']);

        case 'registration_ajax':
        case 'register_save':
            ve_legacy_endpoint_removed('/api/auth/register', ['POST']);

        case 'forgot_pass_ajax':
        case 'forgot_pass':
            ve_legacy_endpoint_removed('/api/auth/forgot', ['POST']);

        case 'reset_pass':
            ve_legacy_endpoint_removed('/api/auth/reset', ['POST']);

        case 'logout':
            ve_legacy_endpoint_removed('/api/auth/logout', ['POST']);

        case 'my_account':
            ve_legacy_endpoint_removed('/api/account/profile', ['POST']);

        case 'my_password':
            ve_legacy_endpoint_removed('/api/account/password', ['POST']);

        case 'my_email':
            ve_legacy_endpoint_removed('/api/account/email', ['POST']);

        case 'upload_logo':
            ve_legacy_endpoint_removed('/api/account/player', ['POST']);

        case 'premium_settings':
            ve_legacy_endpoint_removed('/api/account/ads', ['POST']);

        case 'api_settings':
            ve_legacy_endpoint_removed('/api/account/api', ['POST']);

        case 'custom_domain_list':
            ve_legacy_endpoint_removed('/api/domains', ['GET']);

        case 'custom_domain_add':
            ve_legacy_endpoint_removed('/api/domains', ['POST']);

        case 'custom_domain_delete':
            ve_legacy_endpoint_removed('/api/domains/{domain}', ['DELETE']);

        case 'delete_account':
            ve_legacy_endpoint_removed('/api/account/delete', ['POST']);

        case 'dmca_manager':
            ve_legacy_endpoint_removed('/api/dmca', ['GET']);

        case 'videos_json':
            ve_legacy_endpoint_removed('/api/videos/actions', ['GET', 'POST']);

        case 'remote_upload_json':
            ve_legacy_endpoint_removed('/api/remote/jobs', ['GET', 'POST']);

        case 'upload_get_srv':
            ve_legacy_endpoint_removed('/api/videos/upload-target', ['GET']);

        case 'pass_file':
            ve_legacy_endpoint_removed('/api/uploads/check', ['GET', 'POST']);

        case 'upload_results_json':
            ve_legacy_endpoint_removed('/api/uploads/result', ['POST']);

        case 'add_srt':
            ve_legacy_endpoint_removed('/api/videos/subtitles', ['GET', 'POST']);

        case 'change_thumbnail':
            ve_legacy_endpoint_removed('/api/videos/thumbnail', ['GET']);

        case 'folder_sharing':
            ve_legacy_endpoint_removed('/api/folders/share', ['GET']);

        case 'marker':
            ve_legacy_endpoint_removed('/api/videos/markers', ['GET']);

        case 'payments':
            ve_legacy_endpoint_removed('/api/billing/paypal', ['GET', 'POST']);

        case 'crypto_payments':
            ve_legacy_endpoint_removed('/api/billing/crypto', ['POST']);

        case 'my_reports':
            ve_legacy_endpoint_removed('/api/dashboard/reports', ['GET']);

        case 'request_money':
            ve_legacy_endpoint_removed('/api/payouts/request', ['POST']);

        default:
            return false;
    }
}
