<?php

declare(strict_types=1);

function ve_remote_google_drive_match(string $url): bool
{
    return ve_remote_url_matches_host($url, ['drive.google.com', 'docs.google.com', 'drive.usercontent.google.com']);
}

function ve_remote_google_drive_extract_file_id(string $url): string
{
    if (preg_match('#/file/d/([A-Za-z0-9_-]+)#', $url, $matches) === 1) {
        return (string) $matches[1];
    }

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    if (isset($query['id']) && is_string($query['id']) && $query['id'] !== '') {
        return $query['id'];
    }

    if (preg_match('#/uc\\?(?:[^#]+&)?id=([A-Za-z0-9_-]+)#', $url, $matches) === 1) {
        return (string) $matches[1];
    }

    return '';
}

function ve_remote_google_drive_extract_resource_key(string $url): string
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    if (isset($query['resourcekey']) && is_string($query['resourcekey']) && trim($query['resourcekey']) !== '') {
        return trim($query['resourcekey']);
    }

    return '';
}

function ve_remote_google_drive_confirm_form(string $html, string $baseUrl): ?string
{
    if (preg_match('/<form[^>]+id="download-form"[^>]+action="([^"]+)"/i', $html, $matches) !== 1
        && preg_match("/<form[^>]+id='download-form'[^>]+action='([^']+)'/i", $html, $matches) !== 1) {
        return null;
    }

    $action = ve_remote_absolute_url($baseUrl, html_entity_decode((string) $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    preg_match_all('/<input\b[^>]*>/i', $html, $fields, PREG_SET_ORDER);

    $query = [];

    foreach ($fields as $field) {
        if (!is_array($field) || !isset($field[0])) {
            continue;
        }

        $input = (string) $field[0];
        $type = '';
        $name = '';
        $value = '';

        if (preg_match('/\btype\s*=\s*["\']([^"\']+)["\']/i', $input, $typeMatch) === 1) {
            $type = strtolower(trim((string) $typeMatch[1]));
        }

        if ($type !== 'hidden') {
            continue;
        }

        if (preg_match('/\bname\s*=\s*["\']([^"\']+)["\']/i', $input, $nameMatch) === 1) {
            $name = trim((string) $nameMatch[1]);
        }

        if ($name === '') {
            continue;
        }

        if (preg_match('/\bvalue\s*=\s*["\']([^"\']*)["\']/i', $input, $valueMatch) === 1) {
            $value = html_entity_decode((string) $valueMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $query[$name] = $value;
    }

    if ($query === []) {
        return $action;
    }

    return $action . (str_contains($action, '?') ? '&' : '?') . http_build_query($query);
}

function ve_remote_google_drive_resolve(string $url): array
{
    $fileId = ve_remote_google_drive_extract_file_id($url);
    $resourceKey = ve_remote_google_drive_extract_resource_key($url);

    if ($fileId === '') {
        throw new RuntimeException('Could not extract the Google Drive file id from the URL.');
    }

    $probeUrl = 'https://drive.google.com/uc?export=download&id=' . rawurlencode($fileId);

    if ($resourceKey !== '') {
        $probeUrl .= '&resourcekey=' . rawurlencode($resourceKey);
    }

    $probe = ve_remote_http_request($probeUrl, [
        'follow_location' => false,
        'referer' => 'https://drive.google.com/',
    ]);

    if (($probe['status'] ?? 0) >= 400 && ($probe['status'] ?? 0) !== 303) {
        throw new RuntimeException('Google Drive denied access to the shared file.');
    }

    $headers = [];
    $cookies = ve_remote_cookie_header_from_response($probe);

    if ($cookies !== '') {
        $headers[] = 'Cookie: ' . $cookies;
    }

    $location = ve_remote_http_response_header($probe, 'location');
    $downloadUrl = '';

    if ($location !== '') {
        $redirectUrl = ve_remote_absolute_url($probeUrl, $location);
        $downloadUrl = $redirectUrl;

        try {
            $redirectProbe = ve_remote_http_request($redirectUrl, [
                'follow_location' => true,
                'referer' => 'https://drive.google.com/',
                'cookie' => $cookies,
                'range' => '0-0',
            ]);
        } catch (Throwable $throwable) {
            $redirectProbe = null;
        }

        if (is_array($redirectProbe)) {
            $cookies = ve_remote_merge_cookie_header($cookies, ve_remote_cookie_header_from_response($redirectProbe));
            $confirmUrl = ve_remote_google_drive_confirm_form(
                (string) ($redirectProbe['body'] ?? ''),
                (string) ($redirectProbe['effective_url'] ?? $redirectUrl)
            );

            if ($confirmUrl !== null) {
                $downloadUrl = $confirmUrl;
            }
        }
    } else {
        $confirmUrl = ve_remote_google_drive_confirm_form((string) ($probe['body'] ?? ''), (string) ($probe['effective_url'] ?? $probeUrl));

        if ($confirmUrl !== null) {
            $downloadUrl = $confirmUrl;
        } else {
            $downloadUrl = 'https://drive.usercontent.google.com/download?id='
                . rawurlencode($fileId)
                . '&export=download'
                . ($resourceKey !== '' ? '&resourcekey=' . rawurlencode($resourceKey) : '')
                . '&confirm=t';
        }
    }

    if ($downloadUrl === '') {
        throw new RuntimeException('Google Drive did not expose a downloadable file URL.');
    }

    $headers = [];

    if ($cookies !== '') {
        $headers[] = 'Cookie: ' . $cookies;
    }

    return [
        'normalized_url' => $url,
        'download_url' => $downloadUrl,
        'filename' => 'google-drive-' . $fileId,
        'headers' => $headers,
        'referer' => 'https://drive.google.com/',
    ];
}

return [
    'key' => 'google_drive',
    'match' => 've_remote_google_drive_match',
    'resolve' => 've_remote_google_drive_resolve',
];
