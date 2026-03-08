<?php

declare(strict_types=1);

/**
 * Lightweight route verification for the imported player page.
 */
function verify_route(string $path): array
{
    $_GET = [];
    $_POST = [];
    $_REQUEST = [];
    $_COOKIE = [];
    $_SERVER['REQUEST_URI'] = $path;
    $_SERVER['HTTP_HOST'] = '127.0.0.1:8765';

    ob_start();

    register_shutdown_function(static function () use ($path): void {
        $output = ob_get_contents();

        if ($output !== false) {
            ob_end_clean();
        }

        $payload = ['path' => $path, 'status' => http_response_code()];

        if ($path === '/d/vvr9qyou7zyi') {
            preg_match_all('#(?:href|src)="([^"]+)"#', (string) $output, $matches);
            $assets = array_values(array_filter(
                $matches[1] ?? [],
                static fn (string $url): bool => str_contains($url, '/assets/')
            ));

            $payload['checks'] = [
                'has_iframe' => str_contains((string) $output, '<iframe src="https://myvidplay.com/e/vvr9qyou7zyi"'),
                'has_download_url' => str_contains((string) $output, 'https://myvidplay.com/download/623g0tbgd6jj4c4f1az7e8or/o/250726470-23-234-1772986664-515867180bbecaf238d58b8b6c36f5c4'),
                'has_local_embed_url' => str_contains((string) $output, 'http://127.0.0.1:8765/e/vvr9qyou7zyi'),
                'has_local_assets' => in_array('/assets/theme/css/style.min.css', $assets, true)
                    && in_array('/assets/theme/css/bootstrap.min.css', $assets, true),
            ];
            $payload['assets'] = $assets;
        } else {
            $payload['headers'] = headers_list();
            $payload['expected_redirect'] = ve_player_file('vvr9qyou7zyi')['embed_url'] ?? null;
        }

        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    });

    require __DIR__ . '/../index.php';

    return [];
}

$path = $argv[1] ?? '/d/vvr9qyou7zyi';
verify_route($path);
