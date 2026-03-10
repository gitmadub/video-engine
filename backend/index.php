<?php

declare(strict_types=1);

$pathInfo = (string) ($_SERVER['PATH_INFO'] ?? '');
$pathInfo = $pathInfo === '' ? '' : '/' . ltrim($pathInfo, '/');
$_SERVER['PATH_INFO'] = '/backend' . $pathInfo;

require __DIR__ . '/../index.php';
