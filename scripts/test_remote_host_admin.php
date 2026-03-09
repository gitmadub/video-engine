<?php

declare(strict_types=1);

error_reporting(E_ALL);

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$dbPath = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'test-remote-host-admin.sqlite';

@unlink($dbPath);

putenv('VE_DB_DSN=sqlite:' . str_replace('\\', '/', $dbPath));
putenv('VE_APP_KEY=test-remote-host-admin-key');
putenv('VE_CUSTOM_DOMAIN_TARGET=127.0.0.1');
putenv('VE_REMOTE_HOST_MANAGER_IDS=1');

$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['HTTP_ACCEPT'] = 'application/json';

require __DIR__ . '/../app/frontend.php';

ve_bootstrap();

try {
    $user = ve_create_user('remote_admin_case', 'remote-admin@example.com', 'Start123');
    $userId = (int) ($user['id'] ?? 0);

    assert_true($userId === 1, 'The test manager user should be the first created account.');
    assert_true(ve_remote_host_can_manage($user), 'The first active user should be allowed to manage remote-upload hosts.');

    $snapshot = ve_remote_host_dashboard_snapshot($user);
    assert_true(($snapshot['can_manage'] ?? false) === true, 'Manager snapshot should report management access.');
    assert_true(str_contains((string) ($snapshot['panel_html'] ?? ''), '/account/remote-upload'), 'Manager snapshot should render the remote-upload settings form.');

    $defaultLabels = array_map(
        static fn (array $item): string => (string) ($item['label'] ?? ''),
        array_values(array_filter($snapshot['supported_hosts'] ?? [], static fn ($item): bool => is_array($item)))
    );
    assert_true(in_array('YouTube', $defaultLabels, true), 'Supported-host payload should include verified enabled providers.');
    assert_true(in_array('Google Drive', $defaultLabels, true), 'Supported-host payload should include Google Drive.');
    assert_true(!in_array('Uploaded', $defaultLabels, true), 'Supported-host payload should exclude inactive providers.');
    assert_true(!in_array('Zippyshare', $defaultLabels, true), 'Supported-host payload should exclude dead providers.');

    $youtubeUrl = 'https://www.youtube.com/watch?v=wPH5K-HoZLI';
    $youtubeJob = ve_remote_create_job($userId, $youtubeUrl, 0);
    $youtubeJobId = (int) ($youtubeJob['id'] ?? 0);
    assert_true($youtubeJobId > 0, 'A remote-upload job should be creatable for telemetry tests.');

    $youtubeLogId = ve_remote_host_log_submission([
        'user_id' => $userId,
        'source_url' => $youtubeUrl,
        'source_host' => 'www.youtube.com',
        'matched_host_key' => 'youtube',
        'submission_status' => 'queued',
    ]);
    ve_remote_host_attach_log_to_job($youtubeLogId, $youtubeJobId);
    ve_remote_update_job($youtubeJobId, [
        'host_key' => 'youtube',
        'resolved_url' => 'https://rr1---sn.googlevideo.com/videoplayback?id=test',
        'updated_at' => ve_now(),
    ]);
    ve_remote_host_mark_job_completed($youtubeJobId);

    $disabledUrl = 'https://uploaded.net/file/example';
    $disabledJob = ve_remote_create_job(
        $userId,
        $disabledUrl,
        0,
        VE_REMOTE_STATUS_ERROR,
        'Remote host disabled.',
        ve_remote_host_disabled_message('uploaded')
    );
    $disabledJobId = (int) ($disabledJob['id'] ?? 0);
    ve_remote_update_job($disabledJobId, [
        'host_key' => 'uploaded',
        'updated_at' => ve_now(),
    ]);
    ve_remote_host_log_submission([
        'user_id' => $userId,
        'remote_upload_id' => $disabledJobId,
        'source_url' => $disabledUrl,
        'source_host' => 'uploaded.net',
        'matched_host_key' => 'uploaded',
        'host_key' => 'uploaded',
        'submission_status' => 'disabled_host',
        'detail_message' => ve_remote_host_disabled_message('uploaded'),
    ]);

    ve_remote_host_log_submission([
        'user_id' => $userId,
        'source_url' => 'notaurl',
        'source_host' => '',
        'submission_status' => 'invalid_url',
        'detail_message' => 'Only fully qualified http:// or https:// URLs are supported.',
    ]);

    ve_remote_host_log_submission([
        'user_id' => $userId,
        'source_url' => 'https://unsupported.example/video',
        'source_host' => 'unsupported.example',
        'submission_status' => 'unsupported_host',
        'detail_message' => 'This remote host is not supported yet.',
    ]);

    $totals = ve_remote_host_summary_totals();
    assert_true(($totals['total_submissions'] ?? 0) === 4, 'Remote-host totals should count every submitted URL.');
    assert_true(($totals['completed_submissions'] ?? 0) === 1, 'Remote-host totals should count completed submissions.');
    assert_true(($totals['unsupported_submissions'] ?? 0) === 1, 'Remote-host totals should count unsupported submissions.');
    assert_true(($totals['disabled_submissions'] ?? 0) === 1, 'Remote-host totals should count disabled-host submissions.');

    $providerStats = ve_remote_host_provider_stats_map();
    assert_true(($providerStats['youtube']['submission_count'] ?? 0) === 1, 'Provider stats should count YouTube submissions.');
    assert_true(($providerStats['youtube']['completed_count'] ?? 0) === 1, 'Provider stats should count completed YouTube submissions.');
    assert_true(($providerStats['uploaded']['disabled_count'] ?? 0) === 1, 'Provider stats should count disabled Uploaded submissions.');

    $unsupportedRows = ve_remote_host_unsupported_domains();
    $unsupportedHosts = array_map(
        static fn (array $row): string => (string) ($row['source_host'] ?? ''),
        array_values(array_filter($unsupportedRows, static fn ($row): bool => is_array($row)))
    );
    assert_true(count($unsupportedRows) >= 1, 'Unsupported domain aggregation should include unsupported domain rows.');
    assert_true(in_array('unsupported.example', $unsupportedHosts, true), 'Unsupported domain aggregation should preserve the source host.');

    $providerRows = ve_remote_host_provider_rows();
    $myVidPlayRow = null;

    foreach ($providerRows as $row) {
        if ((string) ($row['key'] ?? '') === 'myvidplay') {
            $myVidPlayRow = $row;
            break;
        }
    }

    assert_true(is_array($myVidPlayRow), 'Provider rows should include the MyVidPlay/Doodstream host entry.');
    assert_true((string) ($myVidPlayRow['label'] ?? '') === 'Doodstream / MyVidPlay / Vidoy', 'The MyVidPlay host label should use the updated combined provider label.');

    ve_remote_host_persist_settings(['google_drive', 'dropbox', 'mega', 'vidi64', 'direct', 'myvidplay'], $userId);
    assert_true(ve_remote_host_is_enabled('myvidplay'), 'Persisted host settings should allow enabling non-default hosts.');
    assert_true(!ve_remote_host_is_enabled('youtube'), 'Persisted host settings should allow disabling default hosts.');

    $updatedSnapshot = ve_remote_host_dashboard_snapshot($user);
    $updatedLabels = array_map(
        static fn (array $item): string => (string) ($item['label'] ?? ''),
        array_values(array_filter($updatedSnapshot['supported_hosts'] ?? [], static fn ($item): bool => is_array($item)))
    );
    assert_true(!in_array('YouTube', $updatedLabels, true), 'Disabling YouTube should remove it from the supported-host payload.');
    assert_true(in_array('Google Drive', $updatedLabels, true), 'Enabled verified providers should stay visible in the supported-host payload.');
    assert_true(!in_array('Doodstream / MyVidPlay / Vidoy', $updatedLabels, true), 'Unverified hosts should stay hidden from the supported-host payload even if enabled.');

    echo "Remote host admin tests passed.\n";
} finally {
    @unlink($dbPath);
}
