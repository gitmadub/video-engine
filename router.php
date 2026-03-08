<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$isBasePrefixed = false;

if (is_string($path) && ($path === '/video-engine' || str_starts_with($path, '/video-engine/'))) {
    $path = substr($path, strlen('/video-engine')) ?: '/';
    $isBasePrefixed = true;
}

if (is_string($path) && $path !== '/') {
    $file = __DIR__ . $path;

    if (is_file($file)) {
        if (!$isBasePrefixed) {
            return false;
        }

        $contentType = function_exists('mime_content_type') ? mime_content_type($file) : null;

        if (is_string($contentType) && $contentType !== '') {
            header('Content-Type: ' . $contentType);
        }

        readfile($file);
        return true;
    }
}

require __DIR__ . '/index.php';
