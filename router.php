<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$isBasePrefixed = false;

if (is_string($path) && ($path === '/video-engine' || str_starts_with($path, '/video-engine/'))) {
    $path = substr($path, strlen('/video-engine')) ?: '/';
    $isBasePrefixed = true;
}

if (is_string($path) && $path !== '/') {
    if ($path === '/backend' || str_starts_with($path, '/backend/')) {
        $_SERVER['SCRIPT_NAME'] = ($isBasePrefixed ? '/video-engine' : '') . '/backend/index.php';
        $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
        $_SERVER['PATH_INFO'] = $path === '/backend' ? '' : substr($path, strlen('/backend'));
        require __DIR__ . '/backend/index.php';
        return true;
    }

    if (str_starts_with($path, '/storage/private/')) {
        http_response_code(403);
        echo 'Forbidden';
        return true;
    }

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
