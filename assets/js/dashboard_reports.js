(function () {
    var root = document.querySelector('[data-dashboard-reports]');
    var basePath = window.VE_BASE_PATH || '';
    var chart = null;

    if (!root) {
        return;
    }

    var els = {
        form: root.querySelector('[data-reports-form]'),
        from: root.querySelector('[data-reports-from]'),
        to: root.querySelector('[data-reports-to]'),
        updated: root.querySelector('[data-reports-updated]'),
        error: root.querySelector('[data-reports-error]'),
        rows: root.querySelector('[data-reports-rows]'),
        totalViews: root.querySelector('[data-reports-total-views]'),
        totalProfit: root.querySelector('[data-reports-total-profit]'),
        totalReferral: root.querySelector('[data-reports-total-referral]'),
        totalRevenue: root.querySelector('[data-reports-total-revenue]'),
        totalTraffic: root.querySelector('[data-reports-total-traffic]'),
        footerViews: root.querySelector('[data-reports-footer-views]'),
        footerProfit: root.querySelector('[data-reports-footer-profit]'),
        footerReferral: root.querySelector('[data-reports-footer-referral]'),
        footerTraffic: root.querySelector('[data-reports-footer-traffic]')
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

        els.error.classList.add('is-visible');
        els.error.textContent = message;
    }

    function updateDateInputsFromQuery() {
        var params = new URLSearchParams(window.location.search);
        var today = new Date();
        var endDate = today.toISOString().slice(0, 10);
        var startDate = new Date(today.getTime() - (7 * 24 * 60 * 60 * 1000)).toISOString().slice(0, 10);

        if (els.from) {
            els.from.value = params.get('from') || startDate;
        }

        if (els.to) {
            els.to.value = params.get('to') || endDate;
        }
    }

    function renderTotals(totals) {
        var views = totals && typeof totals.views !== 'undefined' ? totals.views : 0;
        var profit = totals && totals.profit ? totals.profit : '$0.00000';
        var referral = totals && totals.referral_share ? totals.referral_share : '$0.00000';
        var revenue = totals && totals.total ? totals.total : '$0.00000';
        var traffic = totals && totals.traffic ? totals.traffic : '0 B';

        if (els.totalViews) {
            els.totalViews.textContent = String(views);
        }
        if (els.totalProfit) {
            els.totalProfit.textContent = profit;
        }
        if (els.totalReferral) {
            els.totalReferral.textContent = referral;
        }
        if (els.totalRevenue) {
            els.totalRevenue.textContent = revenue;
        }
        if (els.totalTraffic) {
            els.totalTraffic.textContent = traffic;
        }
        if (els.footerViews) {
            els.footerViews.textContent = String(views);
        }
        if (els.footerProfit) {
            els.footerProfit.textContent = profit;
        }
        if (els.footerReferral) {
            els.footerReferral.textContent = referral;
        }
        if (els.footerTraffic) {
            els.footerTraffic.textContent = traffic;
        }
    }

    function renderRows(rows) {
        if (!els.rows) {
            return;
        }

        if (!Array.isArray(rows) || !rows.length) {
            els.rows.innerHTML = '<tr><td colspan="5" class="text-center text-muted">No report data is available for this range.</td></tr>';
            return;
        }

        var html = [];

        rows.forEach(function (row) {
            html.push(
                '<tr>',
                '<td>' + escapeHtml(row.date || '') + '</td>',
                '<td>' + escapeHtml(row.views || 0) + '</td>',
                '<td>' + escapeHtml(row.profit || '$0.00000') + '</td>',
                '<td>' + escapeHtml(row.referral_share || '$0.00000') + '</td>',
                '<td>' + escapeHtml(row.traffic || '0 B') + '</td>',
                '</tr>'
            );
        });

        els.rows.innerHTML = html.join('');
    }

    function renderChart(series) {
        if (!window.Morris || !Array.isArray(series)) {
            return;
        }

        var chartData = series.map(function (entry) {
            return {
                time: entry.time,
                views: Number(entry.views || 0),
                refs: Number(entry.profit || 0)
            };
        });

        if (!chart) {
            chart = new Morris.Line({
                element: 'reports_chart',
                data: chartData,
                xkey: 'time',
                ykeys: ['views', 'refs'],
                labels: ['Views ', 'Profit '],
                hideHover: 'auto',
                behaveLikeLine: true,
                resize: true,
                pointFillColors: ['#ff9900', '#42b983'],
                pointStrokeColors: ['#ff9900', '#42b983'],
                lineColors: ['#ff9900', '#42b983']
            });
            return;
        }

        chart.setData(chartData);
    }

    function formatTimestamp(value) {
        if (!value) {
            return 'Waiting for live data...';
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

    function fetchReport() {
        var endpoint = root.getAttribute('data-report-endpoint') || '/api/dashboard/reports';
        var params = new URLSearchParams();

        if (els.from && els.from.value) {
            params.set('from', els.from.value);
        }

        if (els.to && els.to.value) {
            params.set('to', els.to.value);
        }

        var queryString = params.toString();
        var nextUrl = window.location.pathname + (queryString ? ('?' + queryString) : '');
        window.history.replaceState({}, '', nextUrl);

        fetch(appUrl(endpoint + (queryString ? ('?' + queryString) : '')), {
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
                    var error = new Error(payload.message || 'Failed to load reports.');
                    error.status = response.status;
                    throw error;
                }

                return payload;
            });
        }).then(function (payload) {
            renderChart(payload.chart || []);
            renderRows(payload.rows || []);
            renderTotals(payload.totals || {});
            if (els.updated) {
                els.updated.textContent = formatTimestamp(new Date().toISOString());
            }
            setError('');
        }).catch(function (error) {
            setError(error && error.status === 401
                ? 'Your session expired. Refresh the page and sign in again.'
                : 'Report data could not be loaded right now.');
        });
    }

    updateDateInputsFromQuery();

    if (els.form) {
        els.form.addEventListener('submit', function (event) {
            event.preventDefault();
            fetchReport();
        });
    }

    fetchReport();
}());
