function veAppUrl(path) {
    var basePath = window.VE_BASE_PATH || '';

    if (!path) {
        return basePath || '/';
    }

    if (/^(?:[a-z][a-z0-9+.-]*:)?\/\//i.test(path)) {
        return path;
    }

    if (path.charAt(0) !== '/') {
        path = '/' + path;
    }

    return basePath + path;
}

function veCsrfToken() {
    return window.VE_CSRF_TOKEN || '';
}

$(document).ready(function() {
    var pathname = window.location.pathname;
    var l_first = true;
    var adminRequest = null;
    var adminPayloadCache = {};
    var adminPrefetchQueue = {};
    var adminLiveRefreshTimer = null;

    function isLogoutHref(href) {
        var logoutUrl = veAppUrl('/logout');

        if (!href) {
            return false;
        }

        return href === logoutUrl || href === '/logout';
    }

    $('.sidebar .nav .nav-item .nav-link').each(function() {
        var path = $(this).attr('href');

        if (pathname == path) {
            $(this).addClass('active');
        }
    });

    if ($.fn.tooltip) {
        $('[data-toggle="tooltip"]').tooltip();
    }

    function getAdminBasePath() {
        return veAppUrl('/backend');
    }

    function getAdminBasePathname() {
        var configured = getAdminBasePath();

        try {
            configured = new URL(configured, window.location.origin).pathname;
        } catch (err) {
            configured = '/backend';
        }

        configured = (configured || '/backend').replace(/\/+$/, '');
        return configured || '/backend';
    }

    function hasAdminShell() {
        return $('[data-admin-shell="1"]').length > 0;
    }

    function isModifiedNavigation(event) {
        return !!(event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.which === 2);
    }

    function isAdminNavigationHref(href) {
        var url;
        var backendBase = getAdminBasePathname();

        if (!href || href.charAt(0) === '#') {
            return false;
        }

        try {
            url = new URL(href, window.location.origin);
        } catch (err) {
            return false;
        }

        if (url.origin !== window.location.origin) {
            return false;
        }

        return url.pathname === backendBase || url.pathname.indexOf(backendBase + '/') === 0;
    }

    function getAdminConfig() {
        var node = document.getElementById('admin-backend-config');

        if (!node) {
            return null;
        }

        if (!window.__veAdminConfigCache) {
            try {
                window.__veAdminConfigCache = JSON.parse(node.textContent || '{}');
            } catch (err) {
                window.__veAdminConfigCache = null;
            }
        }

        return window.__veAdminConfigCache;
    }

    function getAdminRoute(nextUrl) {
        var config = getAdminConfig();
        var url;
        var path;
        var basePath = getAdminBasePathname();
        var suffix;
        var segments;
        var firstSegment;
        var sectionEntry;
        var entry;
        var resource = '';

        if (!config || !config.base_path) {
            return null;
        }

        try {
            url = new URL(nextUrl, window.location.origin);
        } catch (err) {
            return null;
        }

        path = (url.pathname || '').replace(/\/+$/, '') || '/';

        if (path !== basePath && path.indexOf(basePath + '/') !== 0) {
            return null;
        }

        suffix = path.slice(basePath.length).replace(/^\/+/, '');
        segments = suffix ? suffix.split('/').filter(Boolean) : [];
        firstSegment = segments[0] || '';

        if (firstSegment && config.catalog && config.catalog[firstSegment]) {
            entry = config.catalog[firstSegment];
            resource = segments[1] || '';

            return {
                section: entry.section || 'overview',
                subview: entry.canonical || firstSegment,
                sidebarSubview: null,
                resource: resource,
                url: url.toString()
            };
        }

        sectionEntry = config.sections && config.sections[firstSegment || 'overview'];
        resource = segments[1] || '';

        if (!sectionEntry) {
            sectionEntry = config.sections && config.sections.overview;
            firstSegment = 'overview';
        }

        return {
            section: firstSegment || 'overview',
            subview: resource ? sectionEntry.default_detail : sectionEntry.default_list,
            sidebarSubview: null,
            resource: resource,
            url: url.toString()
        };
    }

    function getAdminPanel(subview) {
        return document.querySelector('[data-admin-view="' + subview + '"]');
    }

    function normalizeAdminUrl(nextUrl) {
        var url;

        try {
            url = new URL(nextUrl, window.location.origin);
        } catch (err) {
            return '';
        }

        return (url.pathname || '').replace(/\/+$/, '') + url.search;
    }

    function resolveAdminSidebarSubview(route) {
        var config = getAdminConfig();
        var section;
        var sidebarViews;
        var index;

        if (!config || !route) {
            return '';
        }

        section = config.sections && config.sections[route.section];
        sidebarViews = section && section.sidebar_views ? section.sidebar_views : [];

        for (index = 0; index < sidebarViews.length; index += 1) {
            if (sidebarViews[index] && sidebarViews[index].slug === route.subview) {
                return route.subview;
            }
        }

        return section ? section.default_list : route.subview;
    }

    function adminButtonClass(tone) {
        if (tone === 'primary') {
            return 'btn btn-sm btn-primary';
        }

        if (tone === 'danger') {
            return 'btn btn-sm btn-danger';
        }

        return 'btn btn-sm btn-secondary';
    }

    function renderHiddenInputs(fields) {
        var html = '';

        (fields || []).forEach(function(field) {
            if (!field || field.type !== 'hidden') {
                return;
            }

            html += '<input type="hidden" name="' + escapeHtml(field.name || '') + '" value="' + escapeHtml(field.value || '') + '">';
        });

        return html;
    }

    function renderAdminAction(action) {
        if (!action) {
            return '';
        }

        if (action.type === 'form') {
            return '<form method="' + escapeHtml(action.method || 'POST') + '" action="' + escapeHtml(action.action || '#') + '" class="admin-stop-form"' + (action.confirm ? ' data-confirm="' + escapeHtml(action.confirm) + '"' : '') + '>' + renderHiddenInputs(action.hidden) + '<button type="submit" class="' + adminButtonClass(action.tone || 'secondary') + '">' + (action.icon ? '<i class="fad ' + escapeHtml(action.icon) + '"></i>' : '') + '<span>' + escapeHtml(action.label || 'Submit') + '</span></button></form>';
        }

        return '<a href="' + escapeHtml(action.href || '#') + '"' + (action.admin_nav ? ' data-admin-nav="1"' : '') + ' class="' + adminButtonClass(action.tone || 'secondary') + '">' + (action.icon ? '<i class="fad ' + escapeHtml(action.icon) + '"></i>' : '') + '<span>' + escapeHtml(action.label || 'Open') + '</span></a>';
    }

    function renderToolbarField(field) {
        var optionsHtml = '';
        var html = '';

        if (!field) {
            return '';
        }

        if (field.type === 'hidden') {
            return '<input type="hidden" name="' + escapeHtml(field.name || '') + '" value="' + escapeHtml(field.value || '') + '">';
        }

        if (field.type === 'submit') {
            return '<div class="form-group form-group--action"><button type="submit" class="' + adminButtonClass(field.tone || 'primary') + '">' + escapeHtml(field.label || 'Submit') + '</button></div>';
        }

        if (field.type === 'link') {
            return '<div class="form-group form-group--action"><a href="' + escapeHtml(field.href || '#') + '"' + (field.admin_nav ? ' data-admin-nav="1"' : '') + ' class="' + adminButtonClass(field.tone || 'secondary') + '">' + escapeHtml(field.label || 'Open') + '</a></div>';
        }

        html += '<div class="form-group">';
        html += '<label>' + escapeHtml(field.label || '') + '</label>';

        if (field.type === 'select') {
            (field.options || []).forEach(function(option) {
                optionsHtml += '<option value="' + escapeHtml(option.value || '') + '"' + (String(option.value || '') === String(field.value || '') ? ' selected="selected"' : '') + '>' + escapeHtml(option.label || option.value || '') + '</option>';
            });
            html += '<select name="' + escapeHtml(field.name || '') + '" class="form-control">' + optionsHtml + '</select>';
        } else {
            html += '<input type="text" name="' + escapeHtml(field.name || '') + '" value="' + escapeHtml(field.value || '') + '" class="form-control"' + (field.placeholder ? ' placeholder="' + escapeHtml(field.placeholder) + '"' : '') + '>';
        }

        html += '</div>';
        return html;
    }

    function renderAdminToolbar(block) {
        var html = '<form method="' + escapeHtml(block.method || 'GET') + '" action="' + escapeHtml(block.action || '#') + '" class="admin-toolbar" data-admin-filter="1">';

        (block.items || []).forEach(function(field) {
            html += renderToolbarField(field);
        });

        html += '</form>';
        return html;
    }

    function renderAdminStatusBadge(cell) {
        var tone = cell && cell.tone ? cell.tone : 'secondary';
        var classMap = {
            success: 'badge badge-success',
            danger: 'badge badge-danger',
            warning: 'badge badge-warning',
            info: 'badge badge-info',
            primary: 'badge badge-primary',
            secondary: 'badge badge-secondary'
        };

        return '<span class="' + (classMap[tone] || classMap.secondary) + '">' + escapeHtml(cell.label || '') + '</span>';
    }

    function renderAdminCell(cell) {
        if (!cell) {
            return '';
        }

        if (cell.type === 'link') {
            return '<a href="' + escapeHtml(cell.href || '#') + '"' + (cell.admin_nav ? ' data-admin-nav="1"' : '') + '>' + escapeHtml(cell.label || '') + '</a>' + (cell.secondary ? '<small>' + escapeHtml(cell.secondary) + '</small>' : '');
        }

        if (cell.type === 'status') {
            return renderAdminStatusBadge(cell);
        }

        if (cell.type === 'code') {
            return '<code>' + escapeHtml(cell.primary || '') + '</code>' + (cell.secondary ? '<small>' + escapeHtml(cell.secondary) + '</small>' : '');
        }

        if (cell.type === 'actions') {
            return '<div class="admin-table-actions">' + (cell.actions || []).map(renderAdminAction).join('') + '</div>';
        }

        return '<span>' + escapeHtml(cell.primary || '') + '</span>' + (cell.secondary ? '<small>' + escapeHtml(cell.secondary) + '</small>' : '');
    }

    function renderAdminTable(table) {
        var html = '<div class="settings-table-wrap"><table class="table"><thead><tr>';
        var pagination = '';

        (table.columns || []).forEach(function(column) {
            html += '<th>' + escapeHtml(column || '') + '</th>';
        });

        html += '</tr></thead><tbody>';

        if (!table.rows || !table.rows.length) {
            html += '<tr><td colspan="' + (table.columns ? table.columns.length : 1) + '" class="text-center text-muted">' + escapeHtml(table.empty || 'No rows.') + '</td></tr>';
        } else {
            (table.rows || []).forEach(function(row) {
                html += '<tr>';
                (row.cells || []).forEach(function(cell) {
                    html += '<td>' + renderAdminCell(cell) + '</td>';
                });
                html += '</tr>';
            });
        }

        html += '</tbody></table></div>';

        if (table.pagination && table.pagination.length) {
            pagination += '<nav><ul class="pagination">';
            table.pagination.forEach(function(item) {
                var classes = 'page-item';

                if (item.active) {
                    classes += ' active';
                }

                if (item.disabled) {
                    classes += ' disabled';
                }

                pagination += '<li class="' + classes + '"><a class="page-link" href="' + escapeHtml(item.href || '#') + '"' + (!item.disabled ? ' data-admin-nav="1"' : '') + '>' + escapeHtml(item.label || '') + '</a></li>';
            });
            pagination += '</ul></nav>';
        }

        return html + pagination;
    }

    function renderAdminList(items) {
        var html = '<ul class="admin-mini-list admin-list-tight">';

        if (!items || !items.length) {
            return '<p class="admin-empty">No records yet.</p>';
        }

        items.forEach(function(item) {
            html += '<li>';
            if (item.href) {
                html += '<a href="' + escapeHtml(item.href) + '" data-admin-nav="1">' + escapeHtml(item.primary || '') + '</a>';
            } else {
                html += '<strong>' + escapeHtml(item.primary || '') + '</strong>';
            }
            if (item.secondary) {
                html += '<small>' + escapeHtml(item.secondary) + '</small>';
            }
            html += '</li>';
        });

        return html + '</ul>';
    }

    function renderAdminForm(block) {
        var html = '<form method="' + escapeHtml(block.method || 'POST') + '" action="' + escapeHtml(block.action || '#') + '" class="admin-stack"' + (block.confirm ? ' data-confirm="' + escapeHtml(block.confirm) + '"' : '') + '>';
        html += renderHiddenInputs(block.hidden);

        if (block.fields && block.fields.length) {
            html += '<div class="admin-form-grid">';
            block.fields.forEach(function(field) {
                var optionsHtml = '';
                if (field.type === 'heading') {
                    html += '<div class="admin-form-heading" style="grid-column:1 / -1;">';
                    html += '<h6>' + escapeHtml(field.label || '') + '</h6>';
                    if (field.description) {
                        html += '<p>' + escapeHtml(field.description) + '</p>';
                    }
                    html += '</div>';
                    return;
                }
                html += '<div class="form-group"' + (field.type === 'textarea' ? ' style="grid-column:1 / -1;"' : '') + '>';
                if (field.type !== 'hidden' && field.type !== 'checkbox') {
                    html += '<label>' + escapeHtml(field.label || '') + '</label>';
                }

                if (field.type === 'select') {
                    (field.options || []).forEach(function(option) {
                        optionsHtml += '<option value="' + escapeHtml(option.value || '') + '"' + (String(option.value || '') === String(field.value || '') ? ' selected="selected"' : '') + '>' + escapeHtml(option.label || option.value || '') + '</option>';
                    });
                    html += '<select name="' + escapeHtml(field.name || '') + '" class="form-control">' + optionsHtml + '</select>';
                } else if (field.type === 'textarea') {
                    html += '<textarea name="' + escapeHtml(field.name || '') + '" class="form-control">' + escapeHtml(field.value || '') + '</textarea>';
                } else if (field.type === 'checkbox') {
                    var checkboxId = escapeHtml(field.id || ('admin_field_' + String(field.name || '').replace(/[^a-z0-9_]+/ig, '_')));
                    html += '<div class="custom-control custom-checkbox"><input type="checkbox" class="custom-control-input" id="' + checkboxId + '" name="' + escapeHtml(field.name || '') + '" value="' + escapeHtml(field.value || '1') + '"' + (field.checked ? ' checked="checked"' : '') + '><label class="custom-control-label" for="' + checkboxId + '">' + escapeHtml(field.label || '') + '</label></div>';
                } else if (field.type === 'hidden') {
                    html += '<input type="hidden" name="' + escapeHtml(field.name || '') + '" value="' + escapeHtml(field.value || '') + '">';
                } else {
                    html += '<input type="text" name="' + escapeHtml(field.name || '') + '" value="' + escapeHtml(field.value || '') + '" class="form-control"' + (field.placeholder ? ' placeholder="' + escapeHtml(field.placeholder) + '"' : '') + '>';
                }
                if (field.help) {
                    html += '<small class="admin-form-help">' + escapeHtml(field.help) + '</small>';
                }
                html += '</div>';
            });
            html += '</div>';
        }

        if (block.actions && block.actions.length) {
            html += '<div class="admin-actions">';
            block.actions.forEach(function(action) {
                if (action.type === 'submit') {
                    html += '<button type="submit" class="' + adminButtonClass(action.tone || 'primary') + '">' + escapeHtml(action.label || 'Save') + '</button>';
                } else {
                    html += renderAdminAction(action);
                }
            });
            html += '</div>';
        }

        return html + '</form>';
    }

    function renderAdminCards(block) {
        var wrapperClass = block.layout === 'stack' ? 'admin-stack' : 'admin-section-grid';
        var html = '<div class="' + wrapperClass + '">';

        (block.cards || []).forEach(function(card) {
            html += '<div class="admin-detail-panel">';
            if (card.title) {
                html += '<h5>' + escapeHtml(card.title) + '</h5>';
            }
            if (card.description) {
                html += '<p class="admin-chart-copy">' + escapeHtml(card.description) + '</p>';
            }
            if (card.text) {
                html += '<p>' + escapeHtml(card.text) + '</p>';
            }
            if (card.items && card.items.length) {
                html += '<div class="admin-meta-grid">';
                card.items.forEach(function(item) {
                    html += '<div class="admin-meta-item"><span>' + escapeHtml(item.label || '') + '</span><strong>' + escapeHtml(item.value || '') + '</strong></div>';
                });
                html += '</div>';
            }
            if (card.list) {
                html += renderAdminList(card.list);
            }
            if (card.table) {
                html += renderAdminTable(card.table);
            }
            if (card.form) {
                html += renderAdminForm(card.form);
            }
            if (card.code) {
                html += '<div class="admin-code-block"><pre class="mb-0 small">' + escapeHtml(card.code) + '</pre></div>';
            }
            html += '</div>';
        });

        return html + '</div>';
    }

    function renderAdminGroupGrid(block) {
        var html = '<div class="admin-group-grid">';

        (block.cards || []).forEach(function(card) {
            html += '<div class="admin-group-card"><h6>' + escapeHtml(card.title || '') + '</h6><p>' + escapeHtml(card.description || '') + '</p><ul>';
            (card.items || []).forEach(function(item) {
                html += '<li><span>' + escapeHtml(item.label || '') + '</span><strong>' + escapeHtml(item.value || '') + '</strong></li>';
            });
            html += '</ul></div>';
        });

        return html + '</div>';
    }

    function renderAdminChart(block) {
        var charts = block.cards || [];
        var layoutClass = '';
        var html = '';

        if (charts.length > 1 || block.columns) {
            layoutClass = 'admin-chart-grid-layout';
            if (block.columns) {
                layoutClass += ' admin-chart-grid-layout--' + String(block.columns);
            }
        }

        if (block.title || block.subtitle || (block.period && block.period.length)) {
            html += '<div class="admin-actions justify-content-between align-items-start mb-3">';
            html += '<div>';
            if (block.title) {
                html += '<h5 class="mb-1">' + escapeHtml(block.title) + '</h5>';
            }
            if (block.subtitle) {
                html += '<p class="admin-chart-copy mb-0">' + escapeHtml(block.subtitle) + '</p>';
            }
            html += '</div>';
            if (block.period && block.period.length) {
                html += '<div class="admin-actions admin-period-switch">';
                block.period.forEach(function(action) {
                    html += '<a href="' + escapeHtml(action.href || '#') + '" data-admin-nav="1" class="' + adminButtonClass(action.tone || 'secondary') + '">' + escapeHtml(action.label || '') + '</a>';
                });
                html += '</div>';
            }
            html += '</div>';
        }

        html += '<div class="' + layoutClass + '">';
        charts.forEach(function(chartCard) {
            html += '<div class="admin-chart-card">';
            if (chartCard.title) {
                html += '<h5 class="mb-2">' + escapeHtml(chartCard.title) + '</h5>';
            }
            if (chartCard.subtitle) {
                html += '<p class="admin-chart-copy">' + escapeHtml(chartCard.subtitle) + '</p>';
            }
            html += renderAdminChartSvg(chartCard.chart || {points: [], series: []});
            if (chartCard.legend && chartCard.legend.length) {
                html += '<div class="admin-chart-legend">';
                chartCard.legend.forEach(function(item) {
                    html += '<div class="admin-chart-legend-item"><span><i class="admin-chart-swatch" style="background:' + escapeHtml(item.color || '#ff9900') + ';"></i>' + escapeHtml(item.label || '') + '</span><strong>' + escapeHtml(item.value || '') + '</strong></div>';
                });
                html += '</div>';
            }
            html += '</div>';
        });
        html += '</div>';

        return html;
    }

    function renderAdminChartSvg(chart) {
        var points = chart.points || [];
        var series = chart.series || [];
        var width = 820;
        var height = 310;
        var padding = {left: 54, right: 24, top: 18, bottom: 42};
        var plotWidth = width - padding.left - padding.right;
        var plotHeight = height - padding.top - padding.bottom;
        var maxValue = 0;
        var gridHtml = '';
        var seriesHtml = '';
        var hitsHtml = '';
        var labelsHtml = '';
        var axisHtml = '';
        var stepX = points.length > 1 ? plotWidth / (points.length - 1) : 0;
        var sharedFormat = '';
        var visibleLabels = {};

        series.forEach(function(definition) {
            points.forEach(function(point) {
                maxValue = Math.max(maxValue, Number(point[definition.key] || 0));
            });
        });

        if (!points.length || !series.length) {
            return '<div class="admin-chart-empty">No chart data available.</div>';
        }

        maxValue = Math.max(maxValue, 1);
        sharedFormat = series.length && series.every(function(definition) {
            return (definition.format || 'number') === (series[0].format || 'number');
        }) ? (series[0].format || 'number') : '';

        [0, 1, 2, 3, 4].forEach(function(index) {
            var value = Math.round(maxValue * ((4 - index) / 4));
            var y = padding.top + ((plotHeight / 4) * index);
            gridHtml += '<line x1="' + padding.left + '" y1="' + y + '" x2="' + (width - padding.right) + '" y2="' + y + '"></line>';

            if (sharedFormat) {
                axisHtml += '<text x="' + (padding.left - 10) + '" y="' + (y + 4) + '" text-anchor="end">' + escapeHtml(formatChartValue(value, sharedFormat)) + '</text>';
            }
        });

        series.forEach(function(definition) {
            var polylinePoints = [];
            var pointHtml = '';

            points.forEach(function(point, index) {
                var value = Number(point[definition.key] || 0);
                var x = padding.left + (stepX * index);
                var y = padding.top + plotHeight - ((value / maxValue) * plotHeight);
                polylinePoints.push(x.toFixed(2) + ',' + y.toFixed(2));
                pointHtml += '<circle cx="' + x.toFixed(2) + '" cy="' + y.toFixed(2) + '" r="3.4" fill="#151515" stroke="' + escapeHtml(definition.stroke || '#ff9900') + '" stroke-width="2"></circle>';
            });

            if (definition.fill && definition.fill !== 'none') {
                seriesHtml += '<polygon points="' + escapeHtml(polylinePoints.concat([(padding.left + (stepX * (points.length - 1))).toFixed(2) + ',' + (padding.top + plotHeight).toFixed(2), padding.left.toFixed(2) + ',' + (padding.top + plotHeight).toFixed(2)]).join(' ')) + '" fill="' + escapeHtml(definition.fill) + '"></polygon>';
            }

            seriesHtml += '<polyline points="' + escapeHtml(polylinePoints.join(' ')) + '" stroke="' + escapeHtml(definition.stroke || '#ff9900') + '" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" fill="none"></polyline>';
            seriesHtml += pointHtml;
        });

        points.forEach(function(point, index) {
            var x = padding.left + (stepX * index);
            var tooltipLines = [];
            var label = String(point.label || point.date || '');
            var labelKey = String(index);

            series.forEach(function(definition) {
                tooltipLines.push({
                    color: definition.stroke || '#ff9900',
                    label: definition.label || definition.key || '',
                    value: formatChartValue(Number(point[definition.key] || 0), definition.format || 'number')
                });
            });

            hitsHtml += '<rect class="admin-chart-hit" x="' + (x - 14) + '" y="' + padding.top + '" width="28" height="' + plotHeight + '" fill="transparent" data-date-label="' + escapeHtml(String(point.date || point.label || '')) + '" data-tooltip-lines="' + escapeHtml(JSON.stringify(tooltipLines)) + '"></rect>';

            if (index === 0 || index === points.length - 1 || index === Math.floor(points.length / 2) || (points.length > 6 && index % Math.max(1, Math.floor(points.length / 6)) === 0)) {
                if (!visibleLabels[labelKey]) {
                    labelsHtml += '<text x="' + x + '" y="' + (height - 12) + '" text-anchor="middle">' + escapeHtml(label) + '</text>';
                    visibleLabels[labelKey] = true;
                }
            }
        });

        return '<div class="admin-chart-frame"><svg viewBox="0 0 ' + width + ' ' + height + '" class="admin-chart-svg" preserveAspectRatio="xMidYMid meet"><g class="admin-chart-grid">' + gridHtml + '</g><g class="admin-chart-axis">' + axisHtml + '</g><g>' + seriesHtml + '</g><g class="admin-chart-labels">' + labelsHtml + '</g><g>' + hitsHtml + '</g></svg><div class="admin-chart-tooltip" hidden></div></div>';
    }

    function formatChartValue(value, format) {
        if (format === 'bytes') {
            return humanFileSize(value);
        }

        if (format === 'currency') {
            return '$' + (Number(value || 0) / 1000000).toFixed(2);
        }

        if (format === 'percent') {
            return Math.round(Number(value || 0)) + '%';
        }

        return String(Math.round(Number(value || 0)));
    }

    function humanFileSize(bytes) {
        var value = Number(bytes || 0);
        var units = ['B', 'KB', 'MB', 'GB', 'TB'];
        var index = 0;

        while (value >= 1024 && index < units.length - 1) {
            value /= 1024;
            index += 1;
        }

        return value.toFixed(index === 0 ? 0 : 1) + ' ' + units[index];
    }
    function setAdminLoadingState(isLoading, panel) {
        var $shell = $('[data-admin-shell="1"]').first();
        var $panel = panel ? $(panel) : $();

        if ($shell.length) {
            $shell.toggleClass('is-loading', !!isLoading);
        }

        if ($panel.length) {
            $panel.toggleClass('is-loading', !!isLoading);
            $panel.attr('data-admin-loading', isLoading ? '1' : '0');
        }
    }

    function cacheAdminPayload(nextUrl, payload) {
        var key = normalizeAdminUrl(nextUrl);

        if (!key || !payload || payload.status !== 'ok') {
            return;
        }

        adminPayloadCache[key] = {
            payload: payload,
            cachedAt: Date.now()
        };
    }

    function getCachedAdminPayload(nextUrl) {
        var key = normalizeAdminUrl(nextUrl);
        var cached = key && adminPayloadCache[key] ? adminPayloadCache[key] : null;
        var liveRefreshSeconds;

        if (!cached) {
            return null;
        }

        liveRefreshSeconds = Number((cached.payload && cached.payload.view && cached.payload.view.live_refresh_seconds) || 0);
        if (liveRefreshSeconds > 0 && (Date.now() - cached.cachedAt) > 5000) {
            delete adminPayloadCache[key];
            return null;
        }

        return cached.payload;
    }

    function prefetchAdminView(nextUrl) {
        var key = normalizeAdminUrl(nextUrl);

        if (!key || adminPayloadCache[key] || adminPrefetchQueue[key] || !isAdminNavigationHref(nextUrl)) {
            return;
        }

        adminPrefetchQueue[key] = $.ajax({
            url: nextUrl,
            method: 'GET',
            dataType: 'json',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).done(function(payload) {
            cacheAdminPayload(nextUrl, payload);
        }).always(function() {
            delete adminPrefetchQueue[key];
        });
    }

    function buildAdminSubviewUrl(subview, search) {
        var base = getAdminBasePathname().replace(/\/+$/, '');
        var path = base + '/' + encodeURIComponent(subview);

        return path + (search || '');
    }

    function prefetchAdminSection(section, search) {
        var config = getAdminConfig();
        var sectionEntry = config && config.sections ? config.sections[section] : null;

        if (!sectionEntry || !sectionEntry.sidebar_views) {
            return;
        }

        (sectionEntry.sidebar_views || []).forEach(function(view) {
            if (!view || !view.slug) {
                return;
            }

            prefetchAdminView(buildAdminSubviewUrl(view.slug, search));
        });
    }

    function renderAdminBlocks(blocks) {
        var html = '';

        (blocks || []).forEach(function(block) {
            if (!block || !block.type) {
                return;
            }

            if (block.type === 'toolbar') {
                html += '<div class="admin-render-block">' + renderAdminToolbar(block) + '</div>';
            } else if (block.type === 'table') {
                html += '<div class="admin-render-block">' + renderAdminTable(block) + '</div>';
            } else if (block.type === 'group_grid') {
                html += '<div class="admin-render-block">' + renderAdminGroupGrid(block) + '</div>';
            } else if (block.type === 'cards') {
                html += '<div class="admin-render-block">' + renderAdminCards(block) + '</div>';
            } else if (block.type === 'chart_cards') {
                html += '<div class="admin-render-block">' + renderAdminChart(block) + '</div>';
            } else if (block.type === 'subnav') {
                html += '<div class="admin-render-block"><div class="admin-subnav">' + (block.items || []).map(function(item) {
                    return '<a href="' + escapeHtml(item.href || '#') + '" data-admin-nav="1" class="' + (item.active ? 'active' : '') + '">' + (item.icon ? '<i class="fad ' + escapeHtml(item.icon) + '"></i>' : '') + '<span>' + escapeHtml(item.label || '') + '</span></a>';
                }).join('') + '</div></div>';
            } else if (block.type === 'notice') {
                html += '<div class="admin-render-block"><div class="admin-subsection"><p class="admin-empty">' + escapeHtml(block.message || '') + '</p></div></div>';
            }
        });

        return html;
    }

    function renderAdminView(panel, payload) {
        var $panel = $(panel);
        var $actions = $panel.find('[data-admin-actions]').first();
        var $metrics = $panel.find('[data-admin-metrics]').first();
        var $body = $panel.find('[data-admin-body]').first();
        var metricsHtml = '';

        $panel.attr('data-admin-loaded', '1');
        $panel.find('[data-admin-title]').text(payload.title || '');
        $panel.find('[data-admin-subtitle]').text(payload.subtitle || '');
        $panel.find('[data-admin-feedback]').empty();

        (payload.metrics || []).forEach(function(item) {
            metricsHtml += '<div class="admin-kv__item"><span>' + escapeHtml(item.label || '') + '</span><strong>' + escapeHtml(item.value || '') + '</strong>' + (item.meta ? '<small>' + escapeHtml(item.meta) + '</small>' : '') + '</div>';
        });

        $actions.html((payload.actions || []).map(renderAdminAction).join(''));
        $metrics.html(metricsHtml ? '<div class="admin-kv">' + metricsHtml + '</div>' : '');
        $body.html(renderAdminBlocks(payload.blocks || []));
    }

    function activateAdminRoute(route, sidebarSubview) {
        var sidebarSlug = sidebarSubview || resolveAdminSidebarSubview(route);
        var $headerLinks = $('.admin-header-nav .nav-link[data-admin-nav="1"]');
        var $sidebarLinks = $('.settings_menu a[data-admin-nav="1"]');

        $('[data-admin-view]').removeClass('is-active');
        $('[data-admin-sidebar-section]').removeClass('active');
        $headerLinks.removeClass('active');

        if (route && route.section) {
            $('[data-admin-sidebar-section="' + route.section + '"]').addClass('active');
            $headerLinks.each(function() {
                var candidateSection = $(this).attr('data-admin-section-link') || '';
                var candidate = candidateSection ? {section: candidateSection} : getAdminRoute(this.href);

                if (candidate && candidate.section === route.section) {
                    $(this).addClass('active');
                }
            });
        }

        $sidebarLinks.removeClass('active');
        $sidebarLinks.each(function() {
            var candidateSubview = $(this).attr('data-admin-subview-link') || '';
            var candidate = candidateSubview ? {subview: candidateSubview} : getAdminRoute(this.href);

            if (candidate && candidate.subview === sidebarSlug) {
                $(this).addClass('active');
            }
        });

        if (route && route.subview) {
            $(getAdminPanel(route.subview)).addClass('is-active');
        }
    }

    function clearAdminLiveRefresh() {
        if (adminLiveRefreshTimer) {
            window.clearTimeout(adminLiveRefreshTimer);
            adminLiveRefreshTimer = null;
        }
    }

    function scheduleAdminLiveRefresh(payload, nextUrl) {
        var refreshSeconds = Number((payload && payload.view && payload.view.live_refresh_seconds) || 0);

        clearAdminLiveRefresh();

        if (!refreshSeconds || refreshSeconds < 5) {
            return;
        }

        adminLiveRefreshTimer = window.setTimeout(function() {
            if (document.hidden) {
                scheduleAdminLiveRefresh(payload, nextUrl);
                return;
            }

            $.ajax({
                url: nextUrl,
                method: 'GET',
                dataType: 'json',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Cache-Control': 'no-cache'
                }
            }).done(function(freshPayload) {
                syncAdminShell(freshPayload, nextUrl, false);
            }).fail(function() {
                scheduleAdminLiveRefresh(payload, nextUrl);
            });
        }, refreshSeconds * 1000);
    }

    function syncAdminShell(payload, nextUrl, pushState) {
        var route = getAdminRoute(nextUrl);
        var panel;

        if (!payload || payload.status !== 'ok' || !route) {
            window.location.href = nextUrl;
            return;
        }

        route.section = payload.active_section || route.section;
        route.subview = payload.active_subview || route.subview;
        panel = getAdminPanel(route.subview);

        if (!panel) {
            window.location.href = nextUrl;
            return;
        }

        activateAdminRoute(route, payload.sidebar_subview);
        renderAdminView(panel, payload.view || {});
        cacheAdminPayload(nextUrl, payload);
        prefetchAdminSection(route.section, new URL(nextUrl, window.location.origin).search);
        scheduleAdminLiveRefresh(payload, nextUrl);
        document.title = payload.title || document.title;
        $('#menu').removeClass('show');

        if (pushState) {
            window.history.pushState({adminShell: true, url: nextUrl}, '', nextUrl);
        } else {
            window.history.replaceState({adminShell: true, url: nextUrl}, '', nextUrl);
        }
    }

    function loadAdminView(nextUrl, pushState) {
        var route = getAdminRoute(nextUrl);
        var panel;
        var cachedPayload;

        if (!hasAdminShell() || !isAdminNavigationHref(nextUrl) || !route) {
            window.location.href = nextUrl;
            return;
        }

        panel = getAdminPanel(route.subview);

        if (!panel) {
            window.location.href = nextUrl;
            return;
        }

        activateAdminRoute(route, resolveAdminSidebarSubview(route));

        cachedPayload = getCachedAdminPayload(nextUrl);

        if (cachedPayload) {
            syncAdminShell(cachedPayload, nextUrl, pushState);
            return;
        }

        if (adminRequest && typeof adminRequest.abort === 'function') {
            adminRequest.abort();
        }

        setAdminLoadingState(true, panel);
        adminRequest = $.ajax({
            url: nextUrl,
            method: 'GET',
            dataType: 'json',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).done(function(payload) {
            syncAdminShell(payload, nextUrl, pushState);
        }).fail(function(xhr, status) {
            if (status !== 'abort') {
                window.location.href = nextUrl;
            }
        }).always(function() {
            adminRequest = null;
            setAdminLoadingState(false, panel);
        });
    }

    function updateAdminChartTooltip($hit, event) {
        var $frame = $hit.closest('.admin-chart-frame');
        var $tooltip = $frame.find('.admin-chart-tooltip').first();
        var frameRect;
        var left;
        var top;
        var dateLabel;
        var tooltipLines = [];
        var tooltipHtml = '';

        if (!$frame.length || !$tooltip.length || !$frame[0].getBoundingClientRect) {
            return;
        }

        frameRect = $frame[0].getBoundingClientRect();
        left = Math.max(70, Math.min(frameRect.width - 70, (event.clientX || frameRect.left) - frameRect.left));
        top = Math.max(38, (event.clientY || frameRect.top) - frameRect.top);
        dateLabel = $hit.attr('data-date-label') || '';
        try {
            tooltipLines = JSON.parse($hit.attr('data-tooltip-lines') || '[]');
        } catch (err) {
            tooltipLines = [];
        }

        if (!tooltipLines.length) {
            tooltipLines = [{
                color: '#ff9900',
                label: $hit.attr('data-series-label') || '',
                value: $hit.attr('data-value-label') || ''
            }];
        }

        tooltipHtml += '<small>' + escapeHtml(dateLabel) + '</small>';
        tooltipLines.forEach(function(line, index) {
            tooltipHtml += '<div class="admin-chart-tooltip-row' + (index === 0 ? ' mt-1' : '') + '"><span><i class="admin-chart-swatch" style="background:' + escapeHtml(line.color || '#ff9900') + ';"></i>' + escapeHtml(line.label || '') + '</span><strong>' + escapeHtml(line.value || '') + '</strong></div>';
        });

        $tooltip.html(tooltipHtml);
        $tooltip.css({
            left: left + 'px',
            top: top + 'px'
        }).prop('hidden', false);
    }

    $(document).on('click', 'a[href]', function(e) {
        var href = $(this).attr('href');
        var target = $(this).attr('target');

        if (!hasAdminShell() || isModifiedNavigation(e) || target === '_blank' || isLogoutHref(href)) {
            return;
        }

        if (!isAdminNavigationHref(href)) {
            return;
        }

        e.preventDefault();
        loadAdminView(href, true);
    });

    $(document).on('submit', 'form[data-admin-filter="1"]', function(e) {
        var action = $(this).attr('action') || window.location.href;
        var query = $(this).serialize();
        var nextUrl = action + (query ? (action.indexOf('?') === -1 ? '?' : '&') + query : '');

        if (!hasAdminShell() || !isAdminNavigationHref(nextUrl)) {
            return;
        }

        e.preventDefault();
        loadAdminView(nextUrl, true);
    });

    $(document).on('submit', 'form[data-confirm]', function(e) {
        if (!window.confirm($(this).data('confirm') || 'Are you sure?')) {
            e.preventDefault();
        }
    });

    window.addEventListener('popstate', function() {
        if (!hasAdminShell() || !isAdminNavigationHref(window.location.href)) {
            return;
        }

        loadAdminView(window.location.href, false);
    });

    if (hasAdminShell() && isAdminNavigationHref(window.location.href)) {
        window.history.replaceState({adminShell: true, url: window.location.href}, '', window.location.href);
        loadAdminView(window.location.href, false);
    }

    $(document).on('mouseenter mousemove focus', '.admin-chart-hit', function(e) {
        updateAdminChartTooltip($(this), e);
    });

    $(document).on('mouseleave blur', '.admin-chart-hit', function() {
        $(this).closest('.admin-chart-frame').find('.admin-chart-tooltip').prop('hidden', true);
    });

    $('.settings_menu li a').on('click', function(e) {
        var $this = $(this),
            is_ajax = $this.data('ajax'),
            target = $this.attr('href'),
            link = $this.data('url');

        if (!target || target.charAt(0) !== '#') {
            return;
        }

        e.preventDefault();

        $('.settings_menu li a').not($this).removeClass('active');
        $this.addClass('active');
        $('.settings_data .data').not($(target)).removeClass('active');
        $(target).addClass('active');

        if (is_ajax) {
            $(target).load(link, function() {
                // feather.replace();
            });
        }

        $('html, body').animate({
            scrollTop: $(target).offset().top
        }, 2000);
    });

    $(document).on('submit', '.js_auth', function(e) {
        e.preventDefault();

        var _form = $(this),
            op = _form.find('input[name="op"]').val(),
            login = _form.find('input[name="login"]').val(),
            password = _form.find('input[name="password"]').val(),
            alerts = _form.find('.alert'),
            submit = _form.find('button[type="submit"]'),
            loginotp = _form.find('input[name="loginotp"]').val(),
            url,
            formData,
            button;

        if (l_first) {
            loginotp = '';
        }

        if (op == 'login_ajax') {
            url = veAppUrl('/api/auth/login');
            formData = {
                'login': login,
                'password': password,
                'loginotp': loginotp,
                'token': veCsrfToken()
            };
            button = 'Login <i class="fad fa-arrow-right ml-2"></i>';
        } else if (op == 'register_save') {
            url = veAppUrl('/api/auth/register');
            formData = {
                'usr_login': _form.find('input[name="usr_login"]').val(),
                'usr_email': _form.find('input[name="usr_email"]').val(),
                'usr_password': _form.find('input[name="usr_password"]').val(),
                'usr_password2': _form.find('input[name="usr_password2"]').val(),
                'token': veCsrfToken()
            };
            button = 'Sign up <i class="fad fa-arrow-right ml-2"></i>';
        } else if (op == 'forgot_pass') {
            url = veAppUrl('/api/auth/forgot');
            formData = {
                'usr_login': _form.find('input[name="usr_login"]').val(),
                'token': veCsrfToken()
            };
            button = 'Send me instructions <i class="fad fa-arrow-right ml-2"></i>';
        } else if (op == 'reset_pass') {
            url = veAppUrl('/api/auth/reset');
            formData = {
                'sess_id': _form.find('input[name="sess_id"]').val(),
                'password': _form.find('input[name="password"]').val(),
                'password2': _form.find('input[name="password2"]').val(),
                'token': veCsrfToken()
            };
            button = 'Reset password <i class="fad fa-arrow-right ml-2"></i>';
        } else {
            return;
        }

        if (alerts.length) {
            alerts.remove();
        }

        submit.prop('disabled', true).addClass('loading disabled').html('<img src="/assets/img/loader.svg">');

        $.ajax({
            type: 'post',
            url: url,
            data: formData,
            dataType: 'json',
            success: function(response) {
                console.log(response);

                if (response.status == 'fail') {
                    _form.prepend('<div class="alert alert-danger mb-4">' + response.message + '</div>');
                }

                if (response.status == 'otp_sent') {
                    _form.prepend('<div class="alert alert-success mb-3">' + response.message + ' </br> If you did not receive OTP <a href="' + veAppUrl('/contact') + '" style="color:#ff9a00;">Contact us</a></div>');
                    $('.reqOTP').show();
                    l_first = false;
                }

                if (response.status == 'ok') {
                    _form.html('<div class="alert alert-success mb-4">' + response.message + '</div><hr><div class="action"><a href="#login" data-dismiss="modal" data-toggle="modal" data-target="#login" class="btn btn-default btn-block">Login<i class="fad fa-arrow-right ml-2"></i></a></div>');
                }

                if (response.msg) {
                    _form.prepend('<div class="alert alert-danger mb-4">' + response.msg + '</div>');
                }

                if (response.status == 'redirect') {
                    window.location.href = response.message;
                    return;
                }

                submit.prop('disabled', false).removeClass('loading disabled').html(button);
            },
            error: function(xhr) {
                if (xhr.readyState == 4) {
                    window.location.href = veAppUrl('/dashboard');
                }
            }
        });
    });

    getNotifications();
    $(document).on('click', '.open-notification', function(e) {
        e.preventDefault();
        var subject = $(this).data('subject'),
            message = $(this).data('message'),
            date = $(this).data('date'),
            id = $(this).data('id'),
            isRead = Number($(this).data('read')) === 1;

        $('#notifications .modal-content').html('<div class="modal-header">\n' +
            '                    <h5 class="modal-title" id="notificationsLabel">' + escapeHtml(subject) + '</h5>\n' +
            '                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">\n' +
            '                        <span aria-hidden="true">&times;</span>\n' +
            '                    </button>\n' +
            '                </div>\n' +
            '                <div class="modal-body text-dark"><p>\n' +
            escapeHtml(message) +
            '                    </p><small class="d-block font-weight-bold text-muted">\n' +
            escapeHtml(date) +
            '                        </small>\n' +
            '                </div>\n' +
            '                <div class="modal-footer justify-content-between">\n' +
            '                    <button type="button" class="btn btn-outline-danger notification-delete" data-id="' + id + '">\n' +
            '                        <i class="fad fa-trash-alt mr-2"></i>Delete\n' +
            '                    </button>\n' +
            '                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>\n' +
            '                </div>');
        $('#notifications').modal('show');

        if (!isRead) {
            markNotificationRead(id);
        }
    });

    $(document).on('click', '.notification-delete', function(e) {
        e.preventDefault();
        e.stopPropagation();
        deleteNotification($(this).data('id'), true);
    });

    $(document).on('click', '.notification-clear-all', function(e) {
        e.preventDefault();
        clearNotifications();
    });

    $(document).on('click', 'a.logout, a[href="/logout"], a[href$="/logout"]', function(e) {
        var href = $(this).attr('href') || '';

        if (!isLogoutHref(href)) {
            return;
        }

        e.preventDefault();

        var form = $('<form method="POST" action="' + veAppUrl('/api/auth/logout') + '"></form>');
        form.append('<input type="hidden" name="token" value="' + veCsrfToken() + '">');
        $('body').append(form);
        form.trigger('submit');
    });
});

function removeTags(value) {
    return value.replace(/(<([^>]+)>)/ig, '');
}

function escapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, function(character) {
        return {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        }[character];
    });
}

function truncateText(value, limit) {
    if (value.length > limit) {
        value = value.substring(0, limit - 3) + '...';
    }

    return value;
}

function renderNotifications(response) {
    var items = Array.isArray(response) ? response : [];
    var totalUnread = items.filter(function(item) {
        return item.read == 0;
    }).length;
    var headerHtml = '<div class="title d-flex flex-wrap align-items-center justify-content-between"><span>Notifications</span>';

    if (items.length > 0) {
        headerHtml += '<button type="button" class="btn btn-link p-0 text-muted notification-clear-all">Clear all</button>';
    }

    headerHtml += '</div>';

    $('.dropdown.notifications .count').remove();

    $('.dropdown.notifications .notifications-box').each(function() {
        var boxHtml = headerHtml;

        if (items.length > 0) {
            var listHtml = '<ul class="notifications-list m-0 p-0">';

            $.each(items, function(i) {
                var notification = items[i];
                var itemClass = notification.read == 0 ? 'position-relative new' : 'position-relative';
                var readIcon = notification.read == 0 ? '<i class="fad fa-envelope"></i> Unread' : '<i class="fad fa-envelope-open"></i> Read';

                listHtml += '<li class="' + itemClass + '"><div class="d-flex align-items-start justify-content-between"><a href="#" class="description open-notification flex-grow-1 mr-3" data-date="' + escapeHtml(notification.cr) + '" data-message="' + escapeHtml(notification.message) + '" data-subject="' + escapeHtml(notification.subject) + '" data-id="' + notification.id + '" data-read="' + notification.read + '"><strong>' + escapeHtml(notification.subject) + '</strong><p class="mb-1">' + escapeHtml(truncateText(removeTags(String(notification.message || '')), 65)) + '</p><span><i class="fad fa-clock"></i> ' + escapeHtml(notification.cr) + '<i class="d-inline-block mx-2"></i>' + readIcon + '</span></a><button type="button" class="btn btn-link text-danger p-0 notification-delete" data-id="' + notification.id + '" aria-label="Delete notification"><i class="fad fa-trash-alt"></i></button></div></li>';
            });

            listHtml += '</ul>';
            boxHtml += listHtml;
        } else {
            boxHtml += '<div class="empty p-3 text-center text-muted font-weight-bold">No notifications</div>';
        }

        $(this).html(boxHtml);
    });

    if (totalUnread > 0) {
        $('.dropdown.notifications .nav-link.dropdown-toggle').append('<span class="count">' + totalUnread + '</span>');
    }
}

function getNotifications() {
    $.ajax({
        type: 'get',
        url: veAppUrl('/api/notifications'),
        dataType: 'json',
        success: function(response) {
            renderNotifications(response);
        }
    });
}

function markNotificationRead(notificationId) {
    return $.ajax({
        type: 'post',
        url: veAppUrl('/api/notifications/' + encodeURIComponent(notificationId) + '/read'),
        dataType: 'json',
        data: {
            token: veCsrfToken()
        },
        complete: function() {
            getNotifications();
        }
    });
}

function deleteNotification(notificationId, closeModal) {
    return $.ajax({
        type: 'DELETE',
        url: veAppUrl('/api/notifications/' + encodeURIComponent(notificationId)),
        dataType: 'json',
        data: {
            token: veCsrfToken()
        },
        headers: {
            'X-CSRF-Token': veCsrfToken()
        },
        complete: function() {
            if (closeModal) {
                $('#notifications').modal('hide');
            }

            getNotifications();
        }
    });
}

function clearNotifications() {
    return $.ajax({
        type: 'DELETE',
        url: veAppUrl('/api/notifications'),
        dataType: 'json',
        data: {
            token: veCsrfToken()
        },
        headers: {
            'X-CSRF-Token': veCsrfToken()
        },
        complete: function() {
            $('#notifications').modal('hide');
            getNotifications();
        }
    });
}
