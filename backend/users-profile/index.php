<?php

declare(strict_types=1);

$_SERVER['PATH_INFO'] = '/users-profile' . (string) ($_SERVER['PATH_INFO'] ?? '');

require __DIR__ . '/../subview.php';
