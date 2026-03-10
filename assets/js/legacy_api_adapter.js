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

    function paramsFromData(data) {
        var params = new URLSearchParams();

        if (!data) {
            return params;
        }

        if (typeof data === 'string') {
            return new URLSearchParams(data);
        }

        if (data instanceof URLSearchParams) {
            return new URLSearchParams(data.toString());
        }

        if (window.FormData && data instanceof window.FormData) {
            data.forEach(function (value, key) {
                params.append(key, value);
            });
            return params;
        }

        if (typeof data === 'object') {
            Object.keys(data).forEach(function (key) {
                var value = data[key];

                if (Array.isArray(value)) {
                    value.forEach(function (entry) {
                        params.append(key, entry);
                    });
                    return;
                }

                if (value !== undefined && value !== null) {
                    params.append(key, value);
                }
            });
        }

        return params;
    }

    function routeLegacyRootPath(path, params) {
        var op = params.get('op');

        if (op) {
            params.delete('op');

            switch (op) {
                case 'videos_json':
                    return buildReplacementUrl('/videos/actions', params);
                case 'remote_upload_json':
                    return buildReplacementUrl('/api/remote/jobs', params);
                case 'upload_get_srv':
                    return buildReplacementUrl('/videos/upload-target', params);
                case 'pass_file':
                    return buildReplacementUrl('/videos/check', params);
                case 'upload_results_json':
                    return buildReplacementUrl('/videos/result', params);
                case 'add_srt':
                    return buildReplacementUrl('/videos/subtitles', params);
                case 'change_thumbnail':
                    return buildReplacementUrl('/videos/thumbnail', params);
                case 'folder_sharing':
                    return buildReplacementUrl('/videos/share', params);
                case 'marker':
                    return buildReplacementUrl('/videos/markers', params);
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

        if (params.has('file_move')
            || params.has('folder_move')
            || params.has('fld_select')
            || params.has('file_export')
            || params.has('create_new_folder')
            || params.has('del_selected')
            || params.has('del_selected_fld')
            || params.has('set_public')
            || params.has('set_private')
            || params.has('rename')
            || params.has('content_type')) {
            return buildReplacementUrl('/videos/actions', params);
        }

        return null;
    }

    function rewriteVideosDashboardPath(path, params) {
        switch (path) {
            case '/api/videos/actions':
                return buildReplacementUrl('/videos/actions', params);
            case '/api/videos/upload-target':
                return buildReplacementUrl('/videos/upload-target', params);
            case '/api/uploads/check':
                return buildReplacementUrl('/videos/check', params);
            case '/api/uploads/result':
                return buildReplacementUrl('/videos/result', params);
            case '/api/videos/subtitles':
                return buildReplacementUrl('/videos/subtitles', params);
            case '/api/videos/thumbnail':
                return buildReplacementUrl('/videos/thumbnail', params);
            case '/api/folders/share':
                return buildReplacementUrl('/videos/share', params);
            case '/api/videos/markers':
                return buildReplacementUrl('/videos/markers', params);
            default:
                return null;
        }
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
        var dashboardUrl = rewriteVideosDashboardPath(path, params);

        if (dashboardUrl) {
            return dashboardUrl;
        }

        if (path === '/' || path === '/index.php') {
            var legacyRootUrl = routeLegacyRootPath(path, params);

            if (legacyRootUrl) {
                return legacyRootUrl;
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
                var rawUrl = options && options.url ? options.url : (originalOptions && originalOptions.url ? originalOptions.url : '');
                var dataUrl;

                try {
                    dataUrl = new URL(rawUrl || '/', window.location.origin);
                } catch (error) {
                    dataUrl = null;
                }

                if (dataUrl) {
                    var dataPath = normalizedPath(dataUrl.pathname);

                    if (dataPath === '/' || dataPath === '/index.php') {
                        replacementUrl = routeLegacyRootPath(dataPath, paramsFromData(options && options.data ? options.data : (originalOptions && originalOptions.data ? originalOptions.data : null)));
                    }
                }
            }

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
