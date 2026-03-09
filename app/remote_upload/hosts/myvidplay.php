<?php

declare(strict_types=1);

function ve_remote_myvidplay_match(string $url): bool
{
    return ve_remote_url_matches_host($url, ['myvidplay.com']);
}

function ve_remote_myvidplay_title_from_html(string $html): string
{
    if (preg_match('/<meta[^>]+(?:property|name)=["\']og:title["\'][^>]+content=["\']([^"\']+)/i', $html, $matches) === 1) {
        return trim(html_entity_decode((string) $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    if (preg_match('/<h4>\s*([^<]+?)\s*<\/h4>/i', $html, $matches) === 1) {
        return trim(html_entity_decode((string) $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    if (preg_match('/<title>\s*([^<]+?)\s*-\s*DoodStream\s*<\/title>/i', $html, $matches) === 1) {
        return trim(html_entity_decode((string) $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    return '';
}

function ve_remote_myvidplay_embed_url(string $html, string $pageUrl): string
{
    if (preg_match('/<iframe[^>]+src=["\']([^"\']+)["\']/i', $html, $matches) === 1) {
        $embedUrl = ve_remote_decode_escaped_url((string) $matches[1], $pageUrl);

        if ($embedUrl !== '' && ve_remote_is_http_url($embedUrl)) {
            return $embedUrl;
        }
    }

    if (preg_match('#/(?:d|e)/([A-Za-z0-9]+)#', $pageUrl, $matches) === 1) {
        return ve_remote_absolute_url($pageUrl, '/e/' . $matches[1]);
    }

    return '';
}

function ve_remote_myvidplay_pass_url(string $html, string $pageUrl): string
{
    if (preg_match('#\$\.get\(\s*([\'"])(/pass_md5/[^\'"]+)\1#i', $html, $matches) === 1) {
        return ve_remote_absolute_url($pageUrl, (string) $matches[2]);
    }

    if (preg_match('#(/pass_md5/[^\'"\s<]+)#i', $html, $matches) === 1) {
        return ve_remote_absolute_url($pageUrl, (string) $matches[1]);
    }

    return '';
}

function ve_remote_myvidplay_pass_token(string $passUrl): string
{
    $path = trim((string) parse_url($passUrl, PHP_URL_PATH), '/');

    if ($path === '') {
        return '';
    }

    $segments = explode('/', $path);

    return trim((string) end($segments));
}

function ve_remote_myvidplay_random_suffix(): string
{
    $value = preg_replace('/[^A-Za-z0-9]/', '', ve_random_token(12)) ?? '';
    $value = substr($value, 0, 10);

    return $value !== '' ? $value : 'abcdefghij';
}

function ve_remote_myvidplay_resolve(string $url): array
{
    $pageResponse = ve_remote_http_request($url, [
        'follow_location' => true,
        'referer' => $url,
        'timeout' => 25,
    ]);

    if (($pageResponse['status'] ?? 0) >= 400) {
        throw new RuntimeException('MyVidPlay denied access to the page.');
    }

    $pageUrl = (string) ($pageResponse['effective_url'] ?? $url);
    $pageHtml = (string) ($pageResponse['body'] ?? '');
    $cookie = ve_remote_cookie_header_from_response($pageResponse);
    $title = ve_remote_myvidplay_title_from_html($pageHtml);
    $embedUrl = ve_remote_myvidplay_embed_url($pageHtml, $pageUrl);

    if ($embedUrl === '') {
        throw new RuntimeException('Could not find the MyVidPlay embed page.');
    }

    $embedResponse = ve_remote_http_request($embedUrl, [
        'follow_location' => true,
        'referer' => $pageUrl,
        'cookie' => $cookie,
        'timeout' => 25,
    ]);

    if (($embedResponse['status'] ?? 0) >= 400) {
        throw new RuntimeException('MyVidPlay denied access to the embed page.');
    }

    $cookie = ve_remote_merge_cookie_header($cookie, ve_remote_cookie_header_from_response($embedResponse));
    $embedPageUrl = (string) ($embedResponse['effective_url'] ?? $embedUrl);
    $embedHtml = (string) ($embedResponse['body'] ?? '');
    $passUrl = ve_remote_myvidplay_pass_url($embedHtml, $embedPageUrl);

    if ($passUrl === '') {
        throw new RuntimeException('Could not extract the MyVidPlay stream token.');
    }

    $passResponse = ve_remote_http_request($passUrl, [
        'follow_location' => true,
        'referer' => $embedPageUrl,
        'cookie' => $cookie,
        'timeout' => 25,
    ]);

    if (($passResponse['status'] ?? 0) >= 400) {
        throw new RuntimeException('MyVidPlay denied access to the stream manifest endpoint.');
    }

    $cookie = ve_remote_merge_cookie_header($cookie, ve_remote_cookie_header_from_response($passResponse));
    $baseStreamUrl = trim((string) ($passResponse['body'] ?? ''));
    $token = ve_remote_myvidplay_pass_token($passUrl);

    if ($baseStreamUrl === '' || !ve_remote_is_http_url($baseStreamUrl) || $token === '') {
        throw new RuntimeException('MyVidPlay returned an invalid stream URL.');
    }

    $downloadUrl = $baseStreamUrl
        . ve_remote_myvidplay_random_suffix()
        . '?token=' . rawurlencode($token)
        . '&expiry=' . (int) round(microtime(true) * 1000);

    $filename = $title !== ''
        ? ve_remote_sanitize_filename($title . '.mp4', 'myvidplay-video.mp4')
        : 'myvidplay-video.mp4';

    return [
        'normalized_url' => $pageUrl,
        'download_url' => $downloadUrl,
        'filename' => $filename,
        'referer' => $embedPageUrl,
        'cookie' => $cookie,
    ];
}

return [
    'key' => 'myvidplay',
    'match' => 've_remote_myvidplay_match',
    'resolve' => 've_remote_myvidplay_resolve',
];
