<?php

declare(strict_types=1);

require_once __DIR__ . '/_generic_html_host.php';

function ve_remote_vivo_match(string $url): bool
{
    return ve_remote_url_matches_host($url, ['vivo.sx']);
}

function ve_remote_vivo_resolve(string $url): array
{
    $extraUrls = [];

    if (preg_match('#/(?:embed-|e/)?([A-Za-z0-9]+)(?:\.html)?#', $url, $matches) === 1) {
        $extraUrls[] = 'https://vivo.sx/embed-' . $matches[1] . '.html';
    }

    return ve_remote_generic_html_resolve($url, [
        'label' => 'Vivo',
        'extra_urls' => $extraUrls,
    ]);
}

return [
    'key' => 'vivo',
    'match' => 've_remote_vivo_match',
    'resolve' => 've_remote_vivo_resolve',
];
