<?php

declare(strict_types=1);

require_once __DIR__ . '/_generic_html_host.php';

function ve_remote_vidlox_match(string $url): bool
{
    return ve_remote_url_matches_host($url, ['vidlox.me', 'vidlox.tv']);
}

function ve_remote_vidlox_resolve(string $url): array
{
    $extraUrls = [];

    if (preg_match('#/(?:embed-|e/)?([A-Za-z0-9]+)(?:\.html)?#', $url, $matches) === 1) {
        $extraUrls[] = 'https://vidlox.me/embed-' . $matches[1] . '.html';
    }

    return ve_remote_generic_html_resolve($url, [
        'label' => 'Vidlox',
        'extra_urls' => $extraUrls,
    ]);
}

return [
    'key' => 'vidlox',
    'match' => 've_remote_vidlox_match',
    'resolve' => 've_remote_vidlox_resolve',
];
