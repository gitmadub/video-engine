<?php

declare(strict_types=1);

function processing_node_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$root = dirname(__DIR__);
$dbPath = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'processing-node-test.sqlite';
@unlink($dbPath);

putenv('VE_DB_DSN=sqlite:' . str_replace('\\', '/', $dbPath));
putenv('VE_APP_KEY=processing-node-test-app-key');
$_ENV['VE_DB_DSN'] = 'sqlite:' . str_replace('\\', '/', $dbPath);
$_ENV['VE_APP_KEY'] = 'processing-node-test-app-key';

require $root . '/app/frontend.php';

ve_bootstrap();
ve_admin_run_migrations(ve_db());

$archiveDir = ve_video_storage_path('tmp', 'processing-node-test');
ve_ensure_directory($archiveDir);
$tarPath = $archiveDir . DIRECTORY_SEPARATOR . 'artifacts.tar';
$tgzPath = $archiveDir . DIRECTORY_SEPARATOR . 'artifacts.tar.gz';
@unlink($tarPath);
@unlink($tgzPath);
file_put_contents($archiveDir . DIRECTORY_SEPARATOR . 'stream.m3u8', "#EXTM3U\n#EXT-X-VERSION:3\n#EXTINF:1.0,\npart_00000.bin\n#EXT-X-ENDLIST\n");
file_put_contents($archiveDir . DIRECTORY_SEPARATOR . 'stream.key', random_bytes(16));
file_put_contents($archiveDir . DIRECTORY_SEPARATOR . 'part_00000.bin', random_bytes(64));
$tar = new PharData($tarPath);
$tar->addFile($archiveDir . DIRECTORY_SEPARATOR . 'stream.m3u8', 'stream.m3u8');
$tar->addFile($archiveDir . DIRECTORY_SEPARATOR . 'stream.key', 'stream.key');
$tar->addFile($archiveDir . DIRECTORY_SEPARATOR . 'part_00000.bin', 'part_00000.bin');
$tar->compress(Phar::GZ);
unset($tar);
@unlink($tarPath);
processing_node_assert(is_file($tgzPath), 'Expected processing-node artifact archive to be created.');

$GLOBALS['ve_processing_node_command_runner'] = static function (string $operation, array $payload) use ($tgzPath): array {
    if ($operation === 'upload') {
        return ['exit_code' => 0, 'output' => 'uploaded'];
    }

    if ($operation === 'download') {
        return [
            'exit_code' => 0,
            'output' => 'downloaded',
            'contents' => (string) file_get_contents($tgzPath),
        ];
    }

    if ($operation === 'remote') {
        $command = (string) ($payload['command'] ?? '');

        if (str_contains($command, ' snapshot ')) {
            return [
                'exit_code' => 0,
                'output' => json_encode([
                    'ok' => true,
                    'agent_version' => '1.0.0',
                    'current' => [
                        'captured_at' => ve_now(),
                        'hostname' => 'up-filehost-net',
                        'domain' => 'up.filehost.net',
                        'ip_address' => '46.62.224.160',
                        'ffmpeg_version' => 'ffmpeg version 6.1-test',
                        'cpu_cores' => 8,
                        'cpu_percent' => 24.2,
                        'load_1m' => 1.4,
                        'load_5m' => 1.1,
                        'load_15m' => 0.9,
                        'memory_total_bytes' => 17179869184,
                        'memory_used_bytes' => 8589934592,
                        'memory_available_bytes' => 8589934592,
                        'memory_percent' => 50.0,
                        'disk_total_bytes' => 214748364800,
                        'disk_used_bytes' => 64424509440,
                        'disk_free_bytes' => 150323855360,
                        'disk_percent' => 30.0,
                        'network_rx_bytes' => 1000,
                        'network_tx_bytes' => 2000,
                        'network_rx_rate_bytes' => 10240,
                        'network_tx_rate_bytes' => 5120,
                        'network_total_rate_bytes' => 15360,
                        'uptime_seconds' => 7200,
                        'processing_jobs' => 1,
                    ],
                    'history' => [[
                        'captured_at' => ve_now(),
                        'cpu_percent' => 24,
                        'memory_percent' => 50,
                        'disk_percent' => 30,
                        'network_rx_rate_bytes' => 10240,
                        'network_tx_rate_bytes' => 5120,
                        'network_total_rate_bytes' => 15360,
                        'processing_jobs' => 1,
                        'load_1m' => 1.4,
                    ]],
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ];
        }

        return ['exit_code' => 0, 'output' => 'ok'];
    }

    return ['exit_code' => 1, 'output' => 'unknown operation'];
};

$admin = ve_create_user('processingnodeadmin', 'processing-node-admin@example.com', 'StrongPass123!');
$user = ve_create_user('processingnodeuser', 'processing-node-user@example.com', 'StrongPass123!');
processing_node_assert(is_array($admin) && is_array($user), 'Expected fixture users to be created.');
$adminId = (int) ($admin['id'] ?? 0);
$userId = (int) ($user['id'] ?? 0);
ve_admin_bootstrap_accounts(ve_db());

$nodeId = ve_admin_upsert_processing_node([
    'domain' => 'up.filehost.net',
    'ip_address' => '46.62.224.160',
    'ssh_username' => 'root',
    'ssh_password' => 'SVEN!)/!',
], $adminId);
processing_node_assert($nodeId > 0, 'Expected processing node to be inserted.');
$node = ve_admin_processing_node_load($nodeId);
processing_node_assert(is_array($node), 'Expected processing node record to be loadable.');
processing_node_assert((string) ($node['provision_status'] ?? '') === 'ready', 'Provisioned processing node should be marked ready.');
processing_node_assert((string) ($node['health_status'] ?? '') === 'healthy', 'Provisioned processing node should be healthy after snapshot sync.');

$fixturePath = ve_video_storage_path('tmp', 'processing-node-source.mp4');
[$fixtureExitCode, $fixtureOutput] = ve_video_run_command([
    (string) ve_video_config()['ffmpeg'],
    '-y',
    '-hide_banner',
    '-loglevel',
    'error',
    '-f',
    'lavfi',
    '-i',
    'testsrc=size=1280x720:rate=24',
    '-f',
    'lavfi',
    '-i',
    'sine=frequency=1000:sample_rate=48000',
    '-t',
    '2',
    '-c:v',
    'libx264',
    '-pix_fmt',
    'yuv420p',
    '-c:a',
    'aac',
    '-shortest',
    $fixturePath,
]);
processing_node_assert($fixtureExitCode === 0 && is_file($fixturePath), 'Expected ffmpeg to create the processing source fixture. ' . $fixtureOutput);

$now = ve_now();
ve_db()->prepare(
    'INSERT INTO videos (
        user_id, folder_id, public_id, title, original_filename, source_extension,
        status, status_message, duration_seconds, width, height, video_codec, audio_codec,
        original_size_bytes, processed_size_bytes, compression_ratio, processing_error,
        created_at, updated_at, queued_at, processing_started_at, ready_at, deleted_at
     ) VALUES (
        :user_id, 0, :public_id, :title, :original_filename, :source_extension,
        :status, :status_message, 0, 0, 0, "", "", :original_size_bytes, 0, 0, "",
        :created_at, :updated_at, :queued_at, NULL, NULL, NULL
     )'
)->execute([
    ':user_id' => $userId,
    ':public_id' => 'procnode001',
    ':title' => 'Processing Node Fixture',
    ':original_filename' => 'processing-node-source.mp4',
    ':source_extension' => 'mp4',
    ':status' => VE_VIDEO_STATUS_QUEUED,
    ':status_message' => 'Queued by processing-node QA.',
    ':original_size_bytes' => (int) filesize($fixturePath),
    ':created_at' => $now,
    ':updated_at' => $now,
    ':queued_at' => $now,
]);
$videoId = (int) ve_db()->lastInsertId();
$video = ve_video_get_by_id($videoId);
processing_node_assert(is_array($video), 'Expected queued fixture video to exist.');
copy($fixturePath, ve_video_source_path($video));

ve_video_process_job($videoId);

$processedVideo = ve_video_get_by_id($videoId);
processing_node_assert(is_array($processedVideo), 'Processed fixture video should still exist.');
processing_node_assert((string) ($processedVideo['status'] ?? '') === VE_VIDEO_STATUS_READY, 'Remote processing-node fixture should reach ready status.');
processing_node_assert(is_file(ve_video_playlist_path($processedVideo)), 'Processed fixture should include a playlist.');

$jobCount = (int) (ve_db()->query('SELECT COUNT(*) FROM processing_node_jobs')->fetchColumn() ?: 0);
processing_node_assert($jobCount >= 1, 'Expected at least one processing-node job record.');

echo "Processing node admin tests passed.\n";
