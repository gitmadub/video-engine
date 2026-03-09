<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/frontend.php';

function ve_worker_run(string $name, callable $handler): void
{
    if (PHP_SAPI !== 'cli') {
        http_response_code(404);
        exit;
    }

    if (function_exists('ignore_user_abort')) {
        @ignore_user_abort(true);
    }

    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }

    try {
        $handler();
    } catch (Throwable $exception) {
        fwrite(STDERR, '[' . $name . '] ' . $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}
