<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require $root . '/app/frontend.php';

ve_bootstrap();
ve_admin_run_migrations(ve_db());

$args = $argv;
array_shift($args);

if (count($args) < 4) {
    fwrite(STDERR, "Usage: php scripts/provision_processing_node.php <domain> <ip> <ssh-user> <ssh-password> [actor-user-id]\n");
    exit(1);
}

[$domain, $ipAddress, $sshUser, $sshPassword] = array_slice($args, 0, 4);
$actorUserId = isset($args[4]) ? max(0, (int) $args[4]) : 0;

try {
    $nodeId = ve_admin_upsert_processing_node([
        'domain' => (string) $domain,
        'ip_address' => (string) $ipAddress,
        'ssh_username' => (string) $sshUser,
        'ssh_password' => (string) $sshPassword,
    ], $actorUserId);

    $detail = ve_admin_processing_node_detail($nodeId);
    echo json_encode([
        'status' => 'ok',
        'processing_node_id' => $nodeId,
        'node' => is_array($detail) ? ($detail['node'] ?? []) : [],
        'current' => is_array($detail) ? ($detail['current'] ?? []) : [],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage() . "\n");
    exit(1);
}
