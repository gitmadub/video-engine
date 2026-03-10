(function () {
    'use strict';

    function appUrl(path) {
        var basePath = window.VE_BASE_PATH || '';

        if (!path) {
            return basePath || '/';
        }

        if (/^(?:[a-z][a-z0-9+.-]*:)?\/\//i.test(path)) {
            return path;
        }

        if (basePath && (path === basePath || path.indexOf(basePath + '/') === 0)) {
            return path;
        }

        if (path.charAt(0) !== '/') {
            path = '/' + path;
        }

        return basePath + path;
    }

    function normalizedPath(pathname) {
        var basePath = window.VE_BASE_PATH || '';

        if (basePath && pathname.indexOf(basePath) === 0) {
            pathname = pathname.slice(basePath.length) || '/';
        }

        return pathname;
    }

    function buildReplacementUrl(path, params) {
        var query = params.toString();
        return appUrl(path) + (query ? '?' + query : '');
    }

    function rewriteDirectAppUrl(rawUrl) {
        var basePath = window.VE_BASE_PATH || '';

        if (!rawUrl || !basePath) {
            return null;
        }

        var url;

        try {
            url = new URL(rawUrl, window.location.origin);
        } catch (error) {
            return null;
        }

        if (url.origin !== window.location.origin) {
            return null;
        }

        var path = url.pathname;

        if (path === basePath || path.indexOf(basePath + '/') === 0) {
            return null;
        }

        var knownExactPaths = ['/', '/index.php'];
        if (knownExactPaths.indexOf(path) !== -1) {
            return buildReplacementUrl(path, new URLSearchParams(url.search));
        }

        var knownPrefixes = ['/api/', '/subscene/'];
        var matchesKnownPrefix = knownPrefixes.some(function (prefix) {
            return path === prefix.slice(0, -1) || path.indexOf(prefix) === 0;
        });

        if (!matchesKnownPrefix) {
            return null;
        }

        return buildReplacementUrl(path, new URLSearchParams(url.search));
    }

    function rewriteLegacyUrl(rawUrl) {
        if (!rawUrl) {
            return null;
        }

        var url;

        try {
            url = new URL(rawUrl, window.location.origin);
        } catch (error) {
            return null;
        }

        var path = normalizedPath(url.pathname);
        var params = new URLSearchParams(url.search);
        var op = params.get('op');

        if (op && (path === '/' || path === '/index.php')) {
            params.delete('op');

            switch (op) {
                case 'videos_json':
                    return buildReplacementUrl('/api/videos/actions', params);
                case 'remote_upload_json':
                    return buildReplacementUrl('/api/remote/jobs', params);
                case 'upload_get_srv':
                    return buildReplacementUrl('/api/videos/upload-target', params);
                case 'pass_file':
                    return buildReplacementUrl('/api/uploads/check', params);
                case 'upload_results_json':
                    return buildReplacementUrl('/api/uploads/result', params);
                case 'add_srt':
                    return buildReplacementUrl('/api/videos/subtitles', params);
                case 'change_thumbnail':
                    return buildReplacementUrl('/api/videos/thumbnail', params);
                case 'folder_sharing':
                    return buildReplacementUrl('/api/folders/share', params);
                case 'marker':
                    return buildReplacementUrl('/api/videos/markers', params);
                case 'dmca_manager':
                    return buildReplacementUrl('/api/dmca', params);
                case 'payments':
                    return buildReplacementUrl('/api/billing/paypal', params);
                case 'crypto_payments':
                    return buildReplacementUrl('/api/billing/crypto', params);
                default:
                    break;
            }
        }

        return rewriteDirectAppUrl(rawUrl);
    }

    function installJqueryAdapter() {
        var $ = window.jQuery;

        if (!$ || $.__veLegacyApiAdapterInstalled) {
            return !!$;
        }

        $.ajaxPrefilter(function (options, originalOptions) {
            var replacementUrl = rewriteLegacyUrl(
                options && options.url ? options.url : (originalOptions && originalOptions.url ? originalOptions.url : '')
            );

            if (!replacementUrl) {
                return;
            }

            options.url = replacementUrl;
        });

        $.__veLegacyApiAdapterInstalled = true;
        return true;
    }

    function installXmlHttpRequestAdapter() {
        if (!window.XMLHttpRequest || window.__veLegacyXmlHttpRequestAdapterInstalled) {
            return;
        }

        var originalOpen = window.XMLHttpRequest.prototype.open;

        window.XMLHttpRequest.prototype.open = function (method, url) {
            var replacementUrl = rewriteLegacyUrl(url);
            var nextUrl = replacementUrl || url;

            return originalOpen.apply(this, [method, nextUrl].concat(Array.prototype.slice.call(arguments, 2)));
        };

        window.__veLegacyXmlHttpRequestAdapterInstalled = true;
    }

    function installWindowOpenAdapter() {
        if (window.__veLegacyWindowOpenAdapterInstalled) {
            return;
        }

        var originalOpen = window.open;

        window.open = function (url, target, features) {
            var replacementUrl = rewriteLegacyUrl(url);
            return originalOpen.call(window, replacementUrl || url, target, features);
        };

        window.__veLegacyWindowOpenAdapterInstalled = true;
    }

    function boot() {
        installXmlHttpRequestAdapter();
        installWindowOpenAdapter();

        if (installJqueryAdapter()) {
            return;
        }

        var attempts = 0;
        var timer = window.setInterval(function () {
            attempts += 1;

            if (installJqueryAdapter() || attempts > 80) {
                window.clearInterval(timer);
            }
        }, 100);
    }

    boot();

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    }
}());
