<?php

declare(strict_types=1);

function ve_remote_vidi64_match(string $url): bool
{
    return ve_remote_url_matches_host($url, ['vidi64.com', 'winvidplay.com']);
}

function ve_remote_vidi64_title_from_html(string $html): string
{
    if (preg_match('/<h4>\s*([^<]+?)\s*<\/h4>/i', $html, $matches) === 1) {
        return trim(html_entity_decode((string) $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    if (preg_match('/<title>\s*([^<]+?)\s*<\/title>/i', $html, $matches) === 1) {
        return trim(html_entity_decode((string) $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    return '';
}

function ve_remote_vidi64_player_candidates(string $html, string $pageUrl): array
{
    $candidates = [];

    foreach ([
        '/playerPath\s*=\s*["\']([^"\']+)["\']/i',
        '/fullURL\s*=\s*["\']([^"\']+)["\']/i',
        '/<iframe[^>]+src=["\']([^"\']+)["\']/i',
    ] as $pattern) {
        if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER) !== 1 && ($matches === [])) {
            continue;
        }

        foreach ($matches as $match) {
            if (!isset($match[1])) {
                continue;
            }

            $candidate = ve_remote_decode_escaped_url((string) $match[1], $pageUrl);

            if ($candidate !== '' && ve_remote_is_http_url($candidate)) {
                $candidates[$candidate] = true;
            }
        }
    }

    if (preg_match('/iframeId\s*=\s*["\']([^"\']+)["\']/i', $html, $matches) === 1) {
        $iframeId = trim((string) $matches[1]);

        if ($iframeId !== '') {
            $candidates[ve_remote_absolute_url($pageUrl, '/ip129jk?id=' . rawurlencode($iframeId))] = true;
        }
    }

    return array_keys($candidates);
}

function ve_remote_vidi64_source_from_page(string $html, string $pageUrl): ?array
{
    $sources = ve_remote_media_sources_from_html($html, $pageUrl);
    $source = ve_remote_pick_media_source($sources);

    return is_array($source) && !empty($source['url']) ? $source : null;
}

function ve_remote_vidi64_resolve(string $url): array
{
    $pageResponse = ve_remote_http_request($url, [
        'follow_location' => true,
        'referer' => $url,
        'timeout' => 25,
    ]);

    if (($pageResponse['status'] ?? 0) >= 400) {
        throw new RuntimeException('Vidi64 denied access to the page.');
    }

    $pageUrl = (string) ($pageResponse['effective_url'] ?? $url);
    $pageHtml = (string) ($pageResponse['body'] ?? '');
    $cookie = ve_remote_cookie_header_from_response($pageResponse);
    $title = ve_remote_vidi64_title_from_html($pageHtml);

    $source = null;
    $sourcePageUrl = $pageUrl;
    $seen = [];
    $queue = [[$pageUrl, $pageHtml]];

    while ($queue !== [] && count($seen) < 6) {
        [$currentUrl, $currentHtml] = array_shift($queue);

        if (isset($seen[$currentUrl])) {
            continue;
        }

        $seen[$currentUrl] = true;
        $source = ve_remote_vidi64_source_from_page($currentHtml, $currentUrl);

        if ($source !== null) {
            $sourcePageUrl = $currentUrl;
            break;
        }

        foreach (ve_remote_vidi64_player_candidates($currentHtml, $currentUrl) as $candidate) {
            if (isset($seen[$candidate])) {
                continue;
            }

            try {
                $playerResponse = ve_remote_http_request($candidate, [
                    'follow_location' => true,
                    'referer' => $currentUrl,
                    'cookie' => $cookie,
                    'timeout' => 25,
                ]);
            } catch (Throwable $throwable) {
                continue;
            }

            if (($playerResponse['status'] ?? 0) >= 400) {
                continue;
            }

            $cookie = ve_remote_merge_cookie_header($cookie, ve_remote_cookie_header_from_response($playerResponse));
            $playerUrl = (string) ($playerResponse['effective_url'] ?? $candidate);
            $playerHtml = (string) ($playerResponse['body'] ?? '');
            $queue[] = [$playerUrl, $playerHtml];
        }
    }

    if ($source === null || empty($source['url'])) {
        throw new RuntimeException('Could not extract a Vidi64 video source from the page.');
    }

    $downloadUrl = trim((string) $source['url']);
    $defaultFilename = 'vidi64-video.mp4';
    $sourceFilename = ve_remote_filename_from_url($downloadUrl);

    if ($title !== '') {
        $extension = strtolower(pathinfo($sourceFilename, PATHINFO_EXTENSION));
        $filename = ve_remote_sanitize_filename(
            $title . ($extension !== '' ? '.' . $extension : '.mp4'),
            $defaultFilename
        );
    } else {
        $filename = $sourceFilename !== '' ? $sourceFilename : $defaultFilename;
    }

    return [
        'normalized_url' => $pageUrl,
        'download_url' => $downloadUrl,
        'download_method' => (string) ($source['download_method'] ?? 'curl'),
        'filename' => $filename,
        'referer' => $sourcePageUrl,
        'cookie' => $cookie,
    ];
}

return [
    'key' => 'vidi64',
    'match' => 've_remote_vidi64_match',
    'resolve' => 've_remote_vidi64_resolve',
];
