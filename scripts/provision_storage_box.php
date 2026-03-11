<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/app/frontend.php';

ve_bootstrap();

$args = $_SERVER['argv'] ?? [];

if (count($args) < 8) {
    fwrite(STDERR, "Usage: php scripts/provision_storage_box.php <storage-host> <storage-user> <storage-password> <delivery-domain> <delivery-ip> <delivery-user> <delivery-password> [actor-user-id]\n");
    exit(1);
}

[$script, $storageHost, $storageUser, $storagePassword, $deliveryDomain, $deliveryIp, $deliveryUser, $deliveryPassword] = array_slice($args, 0, 8);
$actorUserId = isset($args[8]) ? max(0, (int) $args[8]) : 0;

try {
    $volumeId = ve_admin_upsert_storage_box([
        'storage_box_host' => $storageHost,
        'storage_box_username' => $storageUser,
        'storage_box_password' => $storagePassword,
        'delivery_domain' => $deliveryDomain,
        'delivery_ip_address' => $deliveryIp,
        'delivery_ssh_username' => $deliveryUser,
        'delivery_ssh_password' => $deliveryPassword,
    ], $actorUserId);
    $detail = ve_admin_storage_box_load($volumeId);

    fwrite(STDOUT, json_encode([
        'status' => 'ok',
        'storage_volume_id' => $volumeId,
        'volume' => $detail,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage() . PHP_EOL);
    exit(1);
}
