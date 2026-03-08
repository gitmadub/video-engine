<?php

declare(strict_types=1);

require_once __DIR__ . '/_direct_fallback_host.php';

function ve_remote_1fichier_match(string $url): bool
{
    return ve_remote_url_matches_host($url, ['1fichier.com']);
}

function ve_remote_1fichier_resolve(string $url): array
{
    return ve_remote_try_direct_or_fail(
        $url,
        '1fichier remote upload is marked inactive by the upstream platform and no direct video file could be extracted from the supplied URL.'
    );
}

return [
    'key' => '1fichier',
    'match' => 've_remote_1fichier_match',
    'resolve' => 've_remote_1fichier_resolve',
];
