<?php

declare(strict_types=1);

function ve_remote_try_direct_or_fail(string $url, string $message): array
{
    try {
        return ve_remote_direct_resolve($url);
    } catch (Throwable $throwable) {
        throw new RuntimeException($message);
    }
}
