<?php

declare(strict_types=1);

function storage_box_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$root = dirname(__DIR__);
$dbPath = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'storage-box-test.sqlite';
@unlink($dbPath);

putenv('VE_DB_DSN=sqlite:' . str_replace('\\', '/', $dbPath));
putenv('VE_APP_KEY=storage-box-test-app-key');
$_ENV['VE_DB_DSN'] = 'sqlite:' . str_replace('\\', '/', $dbPath);
$_ENV['VE_APP_KEY'] = 'storage-box-test-app-key';

require $root . '/app/frontend.php';

ve_bootstrap();
ve_admin_run_migrations(ve_db());

$GLOBALS['ve_processing_node_command_runner'] = static function (string $operation, array $payload): array {
    if ($operation === 'upload') {
        return ['exit_code' => 0, 'output' => 'uploaded'];
    }

    if ($operation === 'remote') {
        $command = (string) ($payload['command'] ?? '');

        if (str_contains($command, '/usr/local/bin/ve-storage-box-agent snapshot')) {
            return [
                'exit_code' => 0,
                'output' => json_encode([
                    'ok' => true,
                    'agent_version' => '1.0.0',
                    'backend_type' => 'webdav_box',
                    'mount_path' => '/mnt/video-engine-storage/u560296',
                    'library_root' => '/mnt/video-engine-storage/u560296/video-library',
                    'mount_ok' => 1,
                    'capacity_bytes' => 107374182400,
                    'used_bytes' => 21474836480,
                    'available_bytes' => 85899345920,
                    'library_bytes' => 21474836480,
                    'file_count' => 8,
                    'health_status' => 'healthy',
                    'captured_at' => ve_now(),
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ];
        }

        return ['exit_code' => 0, 'output' => 'ok'];
    }

    return ['exit_code' => 1, 'output' => 'unsupported test operation'];
};

$admin = ve_create_user('storageboxadmin', 'storage-box-admin@example.com', 'StrongPass123!');
storage_box_assert(is_array($admin), 'Expected fixture admin to be created.');
$adminId = (int) ($admin['id'] ?? 0);
ve_admin_bootstrap_accounts(ve_db());

$volumeId = ve_admin_upsert_storage_box([
    'storage_box_host' => 'u560296.your-storagebox.de',
    'storage_box_username' => 'u560296',
    'storage_box_password' => 'test-pass',
    'delivery_domain' => 'down.filehost.net',
    'delivery_ip_address' => '46.62.224.160',
    'delivery_ssh_username' => 'root',
    'delivery_ssh_password' => 'test-delivery-pass',
], $adminId);

storage_box_assert($volumeId > 0, 'Expected storage box volume to be inserted.');

$volume = ve_admin_storage_box_load($volumeId);
storage_box_assert(is_array($volume), 'Expected storage volume to be loadable.');
storage_box_assert((string) ($volume['backend_type'] ?? '') === 'webdav_box', 'Expected webdav-backed volume type.');
storage_box_assert((string) ($volume['provision_status'] ?? '') === 'ready', 'Expected storage box to be marked ready.');
storage_box_assert((string) ($volume['health_status'] ?? '') === 'healthy', 'Expected storage box to be healthy.');

$deliveryDomainId = (int) (ve_db()->query("SELECT id FROM delivery_domains WHERE domain = 'down.filehost.net' LIMIT 1")->fetchColumn() ?: 0);
storage_box_assert($deliveryDomainId > 0, 'Expected delivery domain to be registered.');

$video = ve_video_insert_queued_record($adminId, [
    'filename' => 'fixture.mp4',
    'extension' => 'mp4',
    'size' => 2048,
], 'Storage Box Fixture');

storage_box_assert(is_array($video), 'Expected queued video fixture to be created.');
storage_box_assert((int) ($video['storage_volume_id'] ?? 0) === $volumeId, 'Expected new videos to use the active storage box.');
storage_box_assert(trim((string) ($video['storage_relative_dir'] ?? '')) !== '', 'Expected a storage relative directory to be stored.');
storage_box_assert(
    (string) ($video['storage_relative_dir'] ?? '') === ve_video_default_storage_relative_dir((string) ($video['public_id'] ?? '')),
    'Expected new videos to use the hierarchical storage-box directory layout.'
);

$assignment = ve_admin_storage_box_assignment_for_video($video);
storage_box_assert((int) ($assignment['storage_volume_id'] ?? 0) === $volumeId, 'Expected storage assignment helper to resolve the storage box.');
storage_box_assert(str_contains((string) ($assignment['root'] ?? ''), 'video-library'), 'Expected the storage root to point to the mounted video library.');

echo "Storage box admin tests passed.\n";
