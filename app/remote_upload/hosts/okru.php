<?php

declare(strict_types=1);

function ve_remote_okru_match(string $url): bool
{
    return ve_remote_url_matches_host($url, ['ok.ru']);
}

function ve_remote_okru_extract_video_id(string $url): string
{
    if (preg_match('#/videoembed/(\d+)#', $url, $matches) === 1) {
        return (string) $matches[1];
    }

    if (preg_match('#/video/(\d+)#', $url, $matches) === 1) {
        return (string) $matches[1];
    }

    return '';
}

function ve_remote_okru_unescape(string $value): string
{
    $decoded = json_decode('"' . addcslashes($value, "\"\n\r\t") . '"', true);

    if (is_string($decoded)) {
        return $decoded;
    }

    return str_replace(['\\\/', '\/'], '/', $value);
}

function ve_remote_okru_extract_metadata(string $html): array
{
    if (preg_match('/data-options="([^"]+)"/i', $html, $matches) !== 1
        && preg_match("/data-options='([^']+)'/i", $html, $matches) !== 1) {
        return [];
    }

    $decodedOptions = html_entity_decode((string) $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $options = json_decode($decodedOptions, true);

    if (!is_array($options)) {
        return [];
    }

    $metadataRaw = $options['flashvars']['metadata'] ?? null;

    if (!is_string($metadataRaw) || $metadataRaw === '') {
        return [];
    }

    $metadata = json_decode($metadataRaw, true);

    return is_array($metadata) ? $metadata : [];
}

function ve_remote_okru_resolve(string $url): array
{
    $videoId = ve_remote_okru_extract_video_id($url);

    if ($videoId === '') {
        throw new RuntimeException('Could not extract the OK.ru video id from the URL.');
    }

    $embedUrl = 'https://ok.ru/videoembed/' . $videoId;
    $response = ve_remote_http_request($embedUrl, [
        'follow_location' => true,
        'referer' => $url,
    ]);

    if (($response['status'] ?? 0) >= 400) {
        throw new RuntimeException('OK.ru denied access to the video page.');
    }

    $metadata = ve_remote_okru_extract_metadata((string) ($response['body'] ?? ''));
    $videos = $metadata['videos'] ?? null;

    $rank = [
        'mobile' => 1,
        'lowest' => 2,
        'low' => 3,
        'sd' => 4,
        'hd' => 5,
        'full' => 6,
    ];
    $bestScore = -1;
    $bestUrl = '';

    if (!is_array($videos)) {
        throw new RuntimeException('Could not extract a direct OK.ru video stream URL.');
    }

    foreach ($videos as $video) {
        if (!is_array($video)) {
            continue;
        }

        $name = strtolower((string) ($video['name'] ?? ''));
        $score = $rank[$name] ?? 0;

        if ($score < $bestScore) {
            continue;
        }

        $candidate = ve_remote_okru_unescape((string) ($video['url'] ?? ''));

        if (!ve_remote_is_http_url($candidate)) {
            continue;
        }

        $bestScore = $score;
        $bestUrl = $candidate;
    }

    if ($bestUrl === '') {
        throw new RuntimeException('OK.ru did not expose a downloadable MP4 stream.');
    }

    return [
        'normalized_url' => $embedUrl,
        'download_url' => $bestUrl,
        'filename' => 'okru-' . $videoId . '.mp4',
        'referer' => $embedUrl,
    ];
}

return [
    'key' => 'okru',
    'match' => 've_remote_okru_match',
    'resolve' => 've_remote_okru_resolve',
];
