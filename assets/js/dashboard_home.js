(function () {
    var root = document.querySelector('[data-dashboard-home]');
    var basePath = window.VE_BASE_PATH || '';
    var pollIntervalMs = 10000;
    var chart = null;

    if (!root) {
        return;
    }

    var els = {
        online: root.querySelector('[data-dashboard-online]'),
        today: root.querySelector('[data-dashboard-today]'),
        yesterday: root.querySelector('[data-dashboard-yesterday]'),
        balance: root.querySelector('[data-dashboard-balance]'),
        storage: root.querySelector('[data-dashboard-storage]'),
        updated: root.querySelector('[data-dashboard-updated]'),
        range: root.querySelector('[data-dashboard-range]'),
        topFiles: root.querySelector('[data-dashboard-top-files]'),
        error: root.querySelector('[data-dashboard-error]')
    };

    function appUrl(path) {
        if (!path) {
            return basePath || '/';
        }

        if (/^(?:[a-z][a-z0-9+.-]*:)?\/\//i.test(path)) {
            return path;
        }

        if (path.charAt(0) !== '/') {
            path = '/' + path;
        }

        if (basePath && (path === basePath || path.indexOf(basePath + '/') === 0)) {
            return path;
        }

        return basePath + path;
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function setError(message) {
        if (!els.error) {
            return;
        }

        if (!message) {
            els.error.classList.remove('is-visible');
            els.error.textContent = '';
            return;
        }

        els.error.textContent = message;
        els.error.classList.add('is-visible');
    }

    function renderTopFiles(items) {
        if (!els.topFiles) {
            return;
        }

        if (!Array.isArray(items) || !items.length) {
            els.topFiles.innerHTML = '<p class="text-center mt-4 mb-3" style="color:#5c605f;font-weight:600;">No file activity yet</p>';
            return;
        }

        var html = ['<ul class="top-files-list">'];

        items.forEach(function (item) {
            var title = escapeHtml(item && item.title ? item.title : 'Untitled video');
            var watchUrl = escapeHtml(item && item.watch_url ? appUrl(item.watch_url) : '#');
            var views = Number(item && item.views ? item.views : 0);
            var earned = escapeHtml(item && item.earned ? item.earned : '$0.00000');
            var bandwidth = escapeHtml(item && item.bandwidth ? item.bandwidth : '0 B');

            html.push(
                '<li class="top-files-item">',
                '<div>',
                '<a class="top-files-title" href="' + watchUrl + '">' + title + '</a>',
                '<div class="top-files-meta">' + views + ' views, ' + bandwidth + ' traffic</div>',
                '</div>',
                '<div class="top-files-badge">' + earned + '</div>',
                '</li>'
            );
        });

        html.push('</ul>');
        els.topFiles.innerHTML = html.join('');
    }

    function renderChart(series) {
        if (!window.Morris || !Array.isArray(series)) {
            return;
        }

        var chartData = series.map(function (entry) {
            return {
                time: entry.time,
                views: Number(entry.views || 0),
                profit: Number(entry.profit || 0),
                traffic: Number(entry.traffic || 0)
            };
        });

        if (!chart) {
            chart = new Morris.Line({
                element: 'reports_chart',
                data: chartData,
                xkey: 'time',
                ykeys: ['views', 'profit', 'traffic'],
                labels: ['Views ', 'Profit (USD) ', 'Traffic (GB)'],
                hideHover: 'auto',
                behaveLikeLine: true,
                resize: true,
                smooth: true,
                pointFillColors: ['#ff9900', '#42b983', '#3699FF'],
                pointStrokeColors: ['#ff9900', '#42b983', '#3699FF'],
                lineColors: ['#ff9900', '#42b983', '#3699FF']
            });
            return;
        }

        chart.setData(chartData);
    }

    function formatTimestamp(value) {
        if (!value) {
            return 'Live data unavailable';
        }

        var normalized = String(value).replace(' ', 'T');

        if (normalized.indexOf('Z') === -1) {
            normalized += 'Z';
        }

        var date = new Date(normalized);

        if (Number.isNaN(date.getTime())) {
            return String(value);
        }

        return 'Updated ' + date.toLocaleString();
    }

    function renderRange(range) {
        if (!els.range || !range) {
            return;
        }

        var fromDate = range.from ? escapeHtml(range.from) : '';
        var toDate = range.to ? escapeHtml(range.to) : '';

        if (!fromDate || !toDate) {
            els.range.textContent = '';
            return;
        }

        els.range.textContent = fromDate + ' to ' + toDate + ' UTC';
    }

    function renderSummary(payload) {
        var widgets = payload && payload.widgets ? payload.widgets : {};

        if (els.online) {
            els.online.textContent = widgets.online && widgets.online.formatted ? widgets.online.formatted : String(payload.online || 0);
        }

        if (els.today) {
            els.today.textContent = widgets.today_earnings && widgets.today_earnings.formatted ? widgets.today_earnings.formatted : (payload.today || '$0.00000');
        }

        if (els.yesterday) {
            els.yesterday.textContent = widgets.yesterday_earnings && widgets.yesterday_earnings.formatted ? widgets.yesterday_earnings.formatted : '$0.00000';
        }

        if (els.balance) {
            els.balance.textContent = widgets.balance && widgets.balance.formatted ? widgets.balance.formatted : (payload.balance || '$0.00000');
        }

        if (els.storage) {
            els.storage.textContent = widgets.storage_used && widgets.storage_used.formatted ? widgets.storage_used.formatted : '0.00 GB';
        }

        if (els.updated) {
            els.updated.textContent = formatTimestamp(payload.generated_at || '');
        }

        renderRange(payload.range || null);
        renderChart(payload.chart || []);
        renderTopFiles(payload.top_files || []);
        setError('');
    }

    function loadSummary() {
        var endpoint = root.getAttribute('data-summary-endpoint') || '/api/dashboard/summary';

        fetch(appUrl(endpoint), {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json'
            }
        }).then(function (response) {
            return response.json().catch(function () {
                return {};
            }).then(function (payload) {
                if (!response.ok) {
                    var error = new Error(payload.message || 'Failed to load dashboard data.');
                    error.status = response.status;
                    throw error;
                }

                return payload;
            });
        }).then(function (payload) {
            renderSummary(payload || {});
        }).catch(function (error) {
            setError(error && error.status === 401
                ? 'Your session expired. Refresh the page and sign in again.'
                : 'Dashboard data could not be loaded right now.');
        });
    }

    loadSummary();
    window.setInterval(loadSummary, pollIntervalMs);
}());
