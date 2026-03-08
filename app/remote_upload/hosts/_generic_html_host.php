<?php

declare(strict_types=1);

function ve_remote_generic_html_pages(string $url, array $config = []): array
{
    $pages = [];
    $candidates = [$url];

    foreach (($config['extra_urls'] ?? []) as $candidate) {
        if (is_string($candidate) && trim($candidate) !== '') {
            $candidates[] = trim($candidate);
        }
    }

    $seen = [];
    $maxCandidates = max(1, (int) ($config['max_candidates'] ?? 8));
    $cookieHeader = '';

    while ($candidates !== [] && count($seen) < $maxCandidates) {
        $candidate = (string) array_shift($candidates);

        if (isset($seen[$candidate])) {
            continue;
        }

        $seen[$candidate] = true;

        try {
            $response = ve_remote_http_request($candidate, [
                'follow_location' => true,
                'referer' => (string) ($config['referer'] ?? $url),
                'timeout' => (int) ($config['timeout'] ?? 25),
                'verify_ssl' => (bool) ($config['verify_ssl'] ?? true),
                'cookie' => $cookieHeader,
            ]);
        } catch (Throwable $throwable) {
            continue;
        }

        if (($response['status'] ?? 0) >= 400) {
            continue;
        }

        $effectiveUrl = (string) ($response['effective_url'] ?? $candidate);
        $body = (string) ($response['body'] ?? '');
        $cookieHeader = ve_remote_merge_cookie_header($cookieHeader, ve_remote_cookie_header_from_response($response));
        $pages[$effectiveUrl] = [
            'body' => $body,
            'cookie' => $cookieHeader,
        ];

        $redirectUrl = ve_remote_html_redirect_url($body, $effectiveUrl);

        if ($redirectUrl !== '' && !isset($seen[$redirectUrl])) {
            $candidates[] = $redirectUrl;
        }
    }

    return $pages;
}

function ve_remote_generic_html_resolve(string $url, array $config = []): array
{
    $pages = ve_remote_generic_html_pages($url, $config);

    foreach ($pages as $pageUrl => $pageData) {
        $html = is_array($pageData) ? (string) ($pageData['body'] ?? '') : (string) $pageData;
        $cookie = is_array($pageData) ? trim((string) ($pageData['cookie'] ?? '')) : '';
        $sources = ve_remote_media_sources_from_html($html, $pageUrl);
        $source = ve_remote_pick_media_source($sources);

        if (!is_array($source) || empty($source['url'])) {
            continue;
        }

        $filename = trim((string) ($config['filename'] ?? ''));

        if ($filename === '') {
            $filename = ve_remote_filename_from_url($pageUrl);
        }

        $refererMode = strtolower(trim((string) ($config['referer_mode'] ?? 'page')));
        $referer = $refererMode === 'origin' ? ve_remote_origin_url($pageUrl) : $pageUrl;

        if ($referer === '') {
            $referer = $pageUrl;
        }

        $headers = [];

        foreach (($config['extra_headers'] ?? []) as $header) {
            if (is_string($header) && trim($header) !== '') {
                $headers[] = trim($header);
            }
        }

        return [
            'normalized_url' => $pageUrl,
            'download_url' => (string) $source['url'],
            'download_method' => (string) ($source['download_method'] ?? 'curl'),
            'filename' => $filename,
            'referer' => $referer,
            'cookie' => $cookie,
            'headers' => $headers,
            'verify_ssl' => (bool) ($config['verify_ssl'] ?? true),
        ];
    }

    $label = trim((string) ($config['label'] ?? 'remote host'));
    throw new RuntimeException('Could not extract a downloadable video source from ' . $label . '.');
}
