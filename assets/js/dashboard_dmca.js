(function () {
    var root = document.querySelector('[data-dmca-manager]');
    var basePath = window.VE_BASE_PATH || '';

    if (!root) {
        return;
    }

    var state = {
        status: 'all',
        query: '',
        page: 1,
        hasMore: false,
        loading: false
    };

    var els = {
        error: root.querySelector('[data-dmca-error]'),
        updated: root.querySelector('[data-dmca-updated]'),
        open: root.querySelector('[data-dmca-open]'),
        disabled: root.querySelector('[data-dmca-disabled]'),
        counter: root.querySelector('[data-dmca-counter]'),
        strikes: root.querySelector('[data-dmca-strikes]'),
        email: root.querySelector('[data-dmca-email]'),
        threshold: root.querySelector('[data-dmca-threshold]'),
        counterWindow: root.querySelector('[data-dmca-counter-window]'),
        list: root.querySelector('[data-dmca-list]'),
        empty: root.querySelector('[data-dmca-empty]'),
        query: root.querySelector('[data-dmca-query]'),
        loadMoreWrap: root.querySelector('[data-dmca-load-more-wrap]'),
        loadMore: root.querySelector('[data-dmca-load-more]'),
        filterButtons: root.querySelectorAll('[data-filter-status]'),
        modalTitle: document.querySelector('[data-dmca-modal-title]'),
        modalBody: document.querySelector('[data-dmca-modal-body]')
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
            els.error.textContent = '';
            els.error.classList.remove('is-visible');
            return;
        }

        els.error.textContent = message;
        els.error.classList.add('is-visible');
    }

    function formatUpdated() {
        if (!els.updated) {
            return;
        }

        els.updated.textContent = 'Updated ' + new Date().toLocaleString();
    }

    function renderPolicy(policy) {
        if (!policy) {
            return;
        }

        if (els.email) {
            els.email.textContent = policy.dmca_email || 'dmca@doodstream.com';
        }

        if (els.threshold) {
            els.threshold.textContent = (policy.repeat_infringer_threshold || 3) + ' effective complaints in ' + (policy.repeat_infringer_window_months || 6) + ' months';
        }

        if (els.counterWindow) {
            var counterWindow = policy.counter_window_business_days || {};
            els.counterWindow.textContent = (counterWindow.min || 10) + ' to ' + (counterWindow.max || 14) + ' business days';
        }
    }

    function renderSummary(summary) {
        summary = summary || {};

        if (els.open) {
            els.open.textContent = String(summary.open_cases || 0);
        }

        if (els.disabled) {
            els.disabled.textContent = String(summary.content_disabled || 0);
        }

        if (els.counter) {
            els.counter.textContent = String(summary.counter_notice_pending || 0);
        }

        if (els.strikes) {
            els.strikes.textContent = String(summary.effective_strikes || 0);
        }
    }

    function setFilterButtons() {
        Array.prototype.forEach.call(els.filterButtons, function (button) {
            var isActive = button.getAttribute('data-filter-status') === state.status;
            button.classList.toggle('btn-primary', isActive);
            button.classList.toggle('btn-white', !isActive);
        });
    }

    function renderEmpty(isVisible) {
        if (!els.empty) {
            return;
        }

        els.empty.classList.toggle('d-none', !isVisible);
    }

    function renderLoadMore(hasMore) {
        state.hasMore = !!hasMore;

        if (!els.loadMoreWrap) {
            return;
        }

        els.loadMoreWrap.classList.toggle('d-none', !hasMore);
    }

    function statusPill(item) {
        return '<span class="dmca-status-pill tone-' + escapeHtml(item.status_tone || 'secondary') + '">' + escapeHtml(item.status_label || item.status || 'Open') + '</span>';
    }

    function itemActions(item) {
        var buttons = [
            '<button type="button" class="btn btn-sm btn-white" data-dmca-view="' + escapeHtml(item.case_code || '') + '">View case</button>'
        ];

        if (item.can_submit_counter_notice) {
            buttons.push('<button type="button" class="btn btn-sm btn-primary" data-dmca-view="' + escapeHtml(item.case_code || '') + '">Submit counter notice</button>');
        }

        return buttons.join(' ');
    }

    function renderList(items, append) {
        if (!els.list) {
            return;
        }

        if (!append) {
            els.list.innerHTML = '';
        }

        if (!Array.isArray(items) || !items.length) {
            if (!append) {
                renderEmpty(true);
            }

            return;
        }

        renderEmpty(false);

        var html = items.map(function (item) {
            var fileTitle = item.video && item.video.title ? item.video.title : 'Removed or unresolved file';
            var workRef = item.work_reference_url ? '<a href="' + escapeHtml(item.work_reference_url) + '" target="_blank" rel="noopener">reference</a>' : 'no reference supplied';
            var deadline = item.status === 'counter_submitted'
                ? 'Restoration window: ' + escapeHtml(item.restoration_earliest_label) + ' to ' + escapeHtml(item.restoration_latest_label)
                : (item.can_submit_counter_notice ? 'Counter notice available' : escapeHtml(item.resolved_label || item.updated_label || ''));

            return [
                '<li class="dmca-item">',
                '<div class="d-flex align-items-start justify-content-between flex-wrap">',
                '<div class="pr-lg-4">',
                '<div class="dmca-item-title">' + escapeHtml(item.case_code || '') + ' • ' + escapeHtml(fileTitle) + '</div>',
                '<div class="dmca-item-copy mb-2">' + escapeHtml(item.claimed_work || '') + '</div>',
                '<div class="dmca-item-meta">Received ' + escapeHtml(item.received_label || '') + ' • ' + workRef + '</div>',
                '<div class="dmca-item-meta">' + deadline + '</div>',
                '</div>',
                '<div class="mt-3 mt-lg-0 text-lg-right">',
                '<div class="mb-2">' + statusPill(item) + '</div>',
                itemActions(item),
                '</div>',
                '</div>',
                '</li>'
            ].join('');
        }).join('');

        els.list.insertAdjacentHTML('beforeend', html);
    }

    function requestJson(url, options) {
        return fetch(appUrl(url), options || {}).then(function (response) {
            return response.json().catch(function () {
                return {};
            }).then(function (payload) {
                if (!response.ok) {
                    var error = new Error(payload.message || 'Request failed.');
                    error.status = response.status;
                    error.payload = payload;
                    throw error;
                }

                return payload;
            });
        });
    }

    function renderModal(notice) {
        if (!notice || !els.modalTitle || !els.modalBody) {
            return;
        }

        var videoTitle = notice.video && notice.video.title ? notice.video.title : 'Removed or unresolved file';
        var timeline = Array.isArray(notice.timeline) && notice.timeline.length
            ? '<ul class="dmca-timeline">' + notice.timeline.map(function (eventItem) {
                return [
                    '<li>',
                    '<div class="dmca-modal-value">' + escapeHtml(eventItem.title || '') + '</div>',
                    '<div class="dmca-modal-copy">' + escapeHtml(eventItem.created_label || '') + '</div>',
                    '<div class="dmca-modal-copy">' + escapeHtml(eventItem.body || '') + '</div>',
                    '</li>'
                ].join('');
            }).join('') + '</ul>'
            : '<p class="dmca-modal-copy mb-0">No timeline entries were recorded for this case yet.</p>';
        var evidence = Array.isArray(notice.evidence_urls) && notice.evidence_urls.length
            ? '<ul>' + notice.evidence_urls.map(function (url) {
                return '<li><a href="' + escapeHtml(url) + '" target="_blank" rel="noopener">' + escapeHtml(url) + '</a></li>';
            }).join('') + '</ul>'
            : '<p class="dmca-modal-copy mb-0">No evidence URLs were attached.</p>';
        var counterBlock = '';

        if (notice.counter_notice) {
            counterBlock = [
                '<div class="the_box mt-4">',
                '<h5 class="mb-3">Counter notice</h5>',
                '<div class="dmca-modal-copy mb-2">Submitted ' + escapeHtml(notice.counter_notice.submitted_label || '') + '</div>',
                '<div class="dmca-modal-copy mb-2">Restoration window: ' + escapeHtml(notice.restoration_earliest_label || '') + ' to ' + escapeHtml(notice.restoration_latest_label || '') + '</div>',
                '<div class="dmca-modal-copy mb-0">' + escapeHtml(notice.counter_notice.mistake_statement || '') + '</div>',
                '</div>'
            ].join('');
        } else if (notice.can_submit_counter_notice) {
            counterBlock = [
                '<div class="the_box mt-4">',
                '<h5 class="mb-3">Submit counter notice</h5>',
                '<form data-dmca-counter-form data-case-code="' + escapeHtml(notice.case_code || '') + '">',
                '<div class="form-row">',
                '<div class="form-group col-md-6"><label>Full name</label><input class="form-control" name="full_name" required></div>',
                '<div class="form-group col-md-6"><label>Email</label><input class="form-control" name="email" type="email" required></div>',
                '</div>',
                '<div class="form-row">',
                '<div class="form-group col-md-6"><label>Phone</label><input class="form-control" name="phone" required></div>',
                '<div class="form-group col-md-6"><label>Postal code</label><input class="form-control" name="postal_code"></div>',
                '</div>',
                '<div class="form-group"><label>Street address</label><input class="form-control" name="address_line" required></div>',
                '<div class="form-row">',
                '<div class="form-group col-md-6"><label>City</label><input class="form-control" name="city" required></div>',
                '<div class="form-group col-md-6"><label>Country</label><input class="form-control" name="country" required></div>',
                '</div>',
                '<div class="form-group"><label>Material location before removal</label><input class="form-control" name="removed_material_location" value="' + escapeHtml(notice.reported_url || '') + '" required></div>',
                '<div class="form-group"><label>Good-faith statement</label><textarea class="form-control" name="mistake_statement" rows="3" required>I have a good-faith belief that the material was removed or disabled as a result of mistake or misidentification.</textarea></div>',
                '<div class="form-group"><label>Jurisdiction statement</label><textarea class="form-control" name="jurisdiction_statement" rows="3" required>I consent to the jurisdiction of the Federal District Court for my address, or if outside the United States, any judicial district in which the service provider may be found, and I will accept service of process from the complainant.</textarea></div>',
                '<div class="form-group"><label>Electronic signature</label><input class="form-control" name="signature_name" required></div>',
                '<button type="submit" class="btn btn-primary">Submit counter notice</button>',
                '</form>',
                '</div>'
            ].join('');
        }

        els.modalTitle.textContent = (notice.case_code || 'DMCA case') + ' • ' + videoTitle;
        els.modalBody.innerHTML = [
            '<div class="row">',
            '<div class="col-md-6">',
            '<div class="dmca-meta mb-2">Status</div>',
            '<div class="mb-3">' + statusPill(notice) + '</div>',
            '<div class="dmca-meta mb-2">Claimed work</div>',
            '<div class="dmca-modal-value mb-3">' + escapeHtml(notice.claimed_work || '') + '</div>',
            '<div class="dmca-meta mb-2">Reported URL</div>',
            '<div class="dmca-modal-copy mb-3"><a href="' + escapeHtml(notice.reported_url || '#') + '" target="_blank" rel="noopener">' + escapeHtml(notice.reported_url || '') + '</a></div>',
            '<div class="dmca-meta mb-2">Claimant</div>',
            '<div class="dmca-modal-copy mb-3">' + escapeHtml((notice.complainant && notice.complainant.name) || '') + (notice.complainant && notice.complainant.company ? ' • ' + escapeHtml(notice.complainant.company) : '') + '</div>',
            '<div class="dmca-meta mb-2">Claimant contact</div>',
            '<div class="dmca-modal-copy mb-3">' + escapeHtml((notice.complainant && notice.complainant.email) || '') + ((notice.complainant && notice.complainant.phone) ? ' • ' + escapeHtml(notice.complainant.phone) : '') + '</div>',
            '<div class="dmca-meta mb-2">Evidence</div>',
            evidence,
            '</div>',
            '<div class="col-md-6">',
            '<div class="dmca-meta mb-2">Timeline</div>',
            timeline,
            '</div>',
            '</div>',
            counterBlock
        ].join('');

        $('#dmca-case-modal').modal('show');
    }

    function loadCases(append) {
        if (state.loading) {
            return;
        }

        state.loading = true;
        var endpoint = root.getAttribute('data-endpoint') || '/api/dmca';
        var params = new URLSearchParams();

        if (state.status && state.status !== 'all') {
            params.set('status', state.status);
        }

        if (state.query) {
            params.set('q', state.query);
        }

        params.set('page', String(state.page));

        requestJson(endpoint + '?' + params.toString(), {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json'
            }
        }).then(function (payload) {
            renderSummary(payload.summary || {});
            renderPolicy(payload.policy || {});
            renderList(payload.items || [], append);
            renderLoadMore(payload.pagination && payload.pagination.has_more);
            setFilterButtons();
            formatUpdated();
            setError('');
        }).catch(function (error) {
            setError(error && error.status === 401
                ? 'Your session expired. Refresh the page and sign in again.'
                : (error && error.message ? error.message : 'DMCA data could not be loaded right now.'));
        }).finally(function () {
            state.loading = false;
        });
    }

    function reloadCases() {
        state.page = 1;
        loadCases(false);
    }

    root.addEventListener('click', function (event) {
        var filterButton = event.target.closest('[data-filter-status]');

        if (filterButton) {
            state.status = filterButton.getAttribute('data-filter-status') || 'all';
            reloadCases();
            return;
        }

        var loadMoreButton = event.target.closest('[data-dmca-load-more]');

        if (loadMoreButton && state.hasMore) {
            state.page += 1;
            loadCases(true);
            return;
        }

        var viewButton = event.target.closest('[data-dmca-view]');

        if (viewButton) {
            requestJson('/api/dmca/' + encodeURIComponent(viewButton.getAttribute('data-dmca-view') || ''), {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json'
                }
            }).then(function (payload) {
                renderModal(payload.notice || null);
            }).catch(function (error) {
                setError(error && error.message ? error.message : 'The selected DMCA case could not be opened.');
            });
        }
    });

    if (els.query) {
        var queryTimer = null;

        els.query.addEventListener('input', function () {
            window.clearTimeout(queryTimer);
            queryTimer = window.setTimeout(function () {
                state.query = els.query.value.trim();
                reloadCases();
            }, 250);
        });
    }

    document.addEventListener('submit', function (event) {
        var form = event.target.closest('[data-dmca-counter-form]');

        if (!form) {
            return;
        }

        event.preventDefault();
        var formData = new FormData(form);
        formData.append('token', window.VE_CSRF_TOKEN || '');

        requestJson('/api/dmca/' + encodeURIComponent(form.getAttribute('data-case-code') || '') + '/counter-notice', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json'
            },
            body: formData
        }).then(function (payload) {
            renderSummary(payload.summary || {});
            renderModal(payload.notice || null);
            reloadCases();
        }).catch(function (error) {
            window.alert(error && error.message ? error.message : 'Counter notice submission failed.');
        });
    });

    loadCases(false);
}());
