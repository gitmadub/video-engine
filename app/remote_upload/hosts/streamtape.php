<?php

declare(strict_types=1);

function ve_remote_streamtape_match(string $url): bool
{
    return ve_remote_url_matches_host($url, ['streamtape.com']);
}

function ve_remote_streamtape_normalize_download_url(string $value): string
{
    $value = html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    if ($value === '') {
        return '';
    }

    if (str_starts_with($value, '//')) {
        $value = 'https:' . $value;
    } elseif (str_starts_with($value, '/')) {
        if (preg_match('#^/([A-Za-z0-9.-]+\.[A-Za-z]{2,})(/.*)$#', $value, $matches) === 1) {
            $value = 'https://' . $matches[1] . $matches[2];
        } else {
            $value = 'https://streamtape.com' . $value;
        }
    }

    if (!str_contains($value, 'stream=1')) {
        $value .= (str_contains($value, '?') ? '&' : '?') . 'stream=1';
    }

    return $value;
}

function ve_remote_streamtape_evaluate_expression(string $expression): string
{
    $parts = preg_split('/\s*\+\s*/', trim($expression)) ?: [];
    $result = '';

    foreach ($parts as $part) {
        $part = trim($part);

        if ($part === '') {
            continue;
        }

        if ((preg_match("/^'([^']*)'$/", $part, $matches) === 1) || (preg_match('/^"([^"]*)"$/', $part, $matches) === 1)) {
            $result .= (string) $matches[1];
            continue;
        }

        if (
            preg_match("/^\\('([^']*)'\\)((?:\\.substring\\(\\d+\\))+)$/" , $part, $matches) === 1
            || preg_match('/^\\("([^"]*)"\\)((?:\\.substring\\(\\d+\\))+)$/' , $part, $matches) === 1
        ) {
            $segment = (string) $matches[1];
            preg_match_all('/\.substring\((\d+)\)/', (string) $matches[2], $substringMatches);

            foreach (($substringMatches[1] ?? []) as $offset) {
                $segment = substr($segment, (int) $offset);
            }

            $result .= $segment;
        }
    }

    return $result;
}

function ve_remote_streamtape_extract_candidates(string $html): array
{
    $candidates = [];

    foreach (['captchalink', 'ideoooolink', 'norobotlink'] as $id) {
        if (preg_match("/document\\.getElementById\\('" . preg_quote($id, '/') . "'\\)\\.innerHTML\\s*=\\s*(.+?);/i", $html, $matches) === 1) {
            $evaluated = ve_remote_streamtape_evaluate_expression((string) $matches[1]);

            if ($evaluated !== '') {
                $candidates[] = ve_remote_streamtape_normalize_download_url($evaluated);
            }
        }

        if (preg_match('/id="' . preg_quote($id, '/') . '"[^>]*>([^<]+)</i', $html, $matches) === 1) {
            $candidates[] = ve_remote_streamtape_normalize_download_url((string) $matches[1]);
        }
    }

    return array_values(array_unique(array_filter($candidates, static function (string $value): bool {
        return $value !== '' && ve_remote_url_matches_host($value, ['streamtape.com']);
    })));
}

function ve_remote_streamtape_pick_candidate(array $candidates, string $referer): string
{
    foreach ($candidates as $candidate) {
        try {
            $probe = ve_remote_http_request($candidate, [
                'method' => 'GET',
                'range' => '0-1',
                'follow_location' => true,
                'referer' => $referer,
                'timeout' => 15,
            ]);

            if (($probe['status'] ?? 0) < 400 && str_starts_with(strtolower((string) ($probe['content_type'] ?? '')), 'video/')) {
                return $candidate;
            }
        } catch (Throwable $throwable) {
        }
    }

    return $candidates[0] ?? '';
}

function ve_remote_streamtape_resolve(string $url): array
{
    $response = ve_remote_http_request($url, [
        'follow_location' => true,
        'referer' => $url,
    ]);

    if (($response['status'] ?? 0) >= 400) {
        throw new RuntimeException('Streamtape denied access to the page.');
    }

    $html = (string) ($response['body'] ?? '');
    $candidates = ve_remote_streamtape_extract_candidates($html);

    if ($candidates === [] && preg_match('#(https?:)?//[^"\']+/get_video\?[^"\']+#i', $html, $matches) === 1) {
        $candidates[] = ve_remote_streamtape_normalize_download_url((string) $matches[0]);
    }

    $downloadUrl = ve_remote_streamtape_pick_candidate($candidates, (string) ($response['effective_url'] ?? $url));

    if ($downloadUrl !== '' && ve_remote_is_http_url($downloadUrl)) {
        return [
            'normalized_url' => (string) ($response['effective_url'] ?? $url),
            'download_url' => $downloadUrl,
            'filename' => ve_remote_filename_from_url((string) ($response['effective_url'] ?? $url)),
            'referer' => (string) ($response['effective_url'] ?? $url),
        ];
    }

    throw new RuntimeException('Could not extract a Streamtape download URL from the page.');
}

return [
    'key' => 'streamtape',
    'match' => 've_remote_streamtape_match',
    'resolve' => 've_remote_streamtape_resolve',
];
