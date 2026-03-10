<?php

declare(strict_types=1);

$_SERVER['PATH_INFO'] = '/infra-endpoints' . (string) ($_SERVER['PATH_INFO'] ?? '');

require __DIR__ . '/../subview.php';
