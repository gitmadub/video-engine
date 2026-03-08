<?php

declare(strict_types=1);

require_once __DIR__ . '/_generic_html_host.php';

function ve_remote_mixdrop_match(string $url): bool
{
    return ve_remote_url_matches_host($url, ['mixdrop.co', 'mixdrop.ag', 'mixdrop.sx', 'mixdrop.club', 'm1xdrop.bz']);
}

function ve_remote_mixdrop_resolve(string $url): array
{
    $extraUrls = [];
    $domains = ['mixdrop.ag', 'mixdrop.sx', 'mixdrop.club', 'm1xdrop.bz', 'mixdrop.co'];

    if (preg_match('#/(?:f|e)/([A-Za-z0-9]+)#', $url, $matches) === 1) {
        foreach ($domains as $domain) {
            $extraUrls[] = 'https://' . $domain . '/e/' . $matches[1];
            $extraUrls[] = 'https://' . $domain . '/f/' . $matches[1];
        }
    }

    return ve_remote_generic_html_resolve($url, [
        'label' => 'Mixdrop',
        'extra_urls' => $extraUrls,
        'referer_mode' => 'origin',
    ]);
}

return [
    'key' => 'mixdrop',
    'match' => 've_remote_mixdrop_match',
    'resolve' => 've_remote_mixdrop_resolve',
];
