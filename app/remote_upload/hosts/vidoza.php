<?php

declare(strict_types=1);

require_once __DIR__ . '/_generic_html_host.php';

function ve_remote_vidoza_match(string $url): bool
{
    return ve_remote_url_matches_host($url, ['vidoza.net', 'videzz.net']);
}

function ve_remote_vidoza_resolve(string $url): array
{
    $extraUrls = [];

    if (preg_match('#/(?:embed-)?([A-Za-z0-9]{8,})(?:\.html)?#', $url, $matches) === 1) {
        $extraUrls[] = 'https://vidoza.net/embed-' . $matches[1] . '.html';
        $extraUrls[] = 'https://videzz.net/embed-' . $matches[1] . '.html';
    }

    return ve_remote_generic_html_resolve($url, [
        'label' => 'Vidoza',
        'extra_urls' => $extraUrls,
    ]);
}

return [
    'key' => 'vidoza',
    'match' => 've_remote_vidoza_match',
    'resolve' => 've_remote_vidoza_resolve',
];
