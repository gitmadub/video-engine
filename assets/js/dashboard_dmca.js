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
        loading: false,
        selectedCaseCode: ''
    };

    var els = {
        error: root.querySelector('[data-dmca-error]'),
        updated: root.querySelector('[data-dmca-updated]'),
        open: root.querySelector('[data-dmca-open]'),
        pendingDelete: root.querySelector('[data-dmca-pending-delete]'),
        response: root.querySelector('[data-dmca-response]'),
        deleted: root.querySelector('[data-dmca-deleted]'),
        window: root.querySelector('[data-dmca-window]'),
        windowCopy: root.querySelector('[data-dmca-window-copy]'),
        windowInline: root.querySelector('[data-dmca-window-inline]'),
        windowPolicy: root.querySelector('[data-dmca-window-policy]'),
        list: root.querySelector('[data-dmca-list]'),
        empty: root.querySelector('[data-dmca-empty]'),
        query: root.querySelector('[data-dmca-query]'),
        loadMoreWrap: root.querySelector('[data-dmca-load-more-wrap]'),
        loadMore: root.querySelector('[data-dmca-load-more]'),
        filterButtons: root.querySelectorAll('[data-filter-status]'),
        navLinks: root.querySelectorAll('.settings_menu a, [data-dmca-nav]'),
        panels: root.querySelectorAll('.settings_data .data'),
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

    function setText(element, value) {
        if (element) {
            element.textContent = String(value == null ? '' : value);
        }
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

    function showToast(type, title, message) {
        if (window.iziToast && typeof window.iziToast[type] === 'function') {
            window.iziToast[type]({
                title: title,
                message: message,
                position: 'topRight'
            });
            return;
        }

        if (type === 'error') {
            window.alert(message);
        }
    }

    function formatUpdated() {
        setText(els.updated, 'Updated ' + new Date().toLocaleString());
    }

    function activatePanel(hash, shouldUpdateLocation) {
        if (!hash || hash.charAt(0) !== '#') {
            hash = '#dmca_cases';
        }

        var panel = root.querySelector(hash);

        if (!panel) {
            hash = '#dmca_cases';
            panel = root.querySelector(hash);
        }

        Array.prototype.forEach.call(els.navLinks, function (link) {
            link.classList.toggle('active', link.getAttribute('href') === hash);
        });

        Array.prototype.forEach.call(els.panels, function (item) {
            item.classList.toggle('active', '#' + item.id === hash);
        });

        if (shouldUpdateLocation) {
            if (window.history && typeof window.history.replaceState === 'function') {
                window.history.replaceState(null, '', hash);
            } else {
                window.location.hash = hash;
            }
        }
    }

    function renderPolicy(policy) {
        policy = policy || {};
        var hours = String(policy.response_window_hours || 24);

        setText(els.window, hours);
        setText(els.windowCopy, hours);
        setText(els.windowInline, hours);
        setText(els.windowPolicy, hours);
    }

    function renderSummary(summary) {
        summary = summary || {};

        setText(els.open, summary.open_cases || 0);
        setText(els.pendingDelete, summary.pending_delete || 0);
        setText(els.response, summary.responses_received || 0);
        setText(els.deleted, summary.deleted_videos || 0);
    }

    function setFilterButtons() {
        Array.prototype.forEach.call(els.filterButtons, function (button) {
            var active = button.getAttribute('data-filter-status') === state.status;
            button.classList.toggle('btn-primary', active);
            button.classList.toggle('btn-white', !active);
        });
    }

    function renderEmpty(isVisible) {
        if (els.empty) {
            els.empty.classList.toggle('d-none', !isVisible);
        }
    }

    function renderLoadMore(hasMore) {
        state.hasMore = !!hasMore;

        if (els.loadMoreWrap) {
            els.loadMoreWrap.classList.toggle('d-none', !hasMore);
        }
    }

    function statusBadge(item) {
        return '<span class="dmca-status ' + escapeHtml(item.status_tone || 'secondary') + '">' + escapeHtml(item.status_label || item.status || 'Open') + '</span>';
    }

    function deadlineLabel(item) {
        if (!item) {
            return '';
        }

        if (item.status === 'content_disabled') {
            return escapeHtml(item.auto_delete_remaining_label || item.auto_delete_label || '24h');
        }

        if (item.status === 'pending_review') {
            return 'Review open - ' + escapeHtml(item.auto_delete_remaining_label || item.auto_delete_label || '24h');
        }

        if (item.status === 'response_submitted' || item.status === 'counter_submitted') {
            return 'Info sent ' + escapeHtml(item.response_submitted_label || item.updated_label || '');
        }

        if (item.status === 'uploader_deleted' || item.status === 'auto_deleted') {
            return 'Deleted ' + escapeHtml(item.deleted_video_label || item.resolved_label || '');
        }

        if (item.resolved_at) {
            return escapeHtml(item.resolved_label || item.updated_label || '');
        }

        return escapeHtml(item.updated_label || item.received_label || '');
    }

    function rowActions(item) {
        var buttons = [
            '<button type="button" class="btn btn-sm btn-white" data-dmca-view="' + escapeHtml(item.case_code || '') + '">View case</button>'
        ];

        if (item.can_submit_response) {
            buttons.push('<button type="button" class="btn btn-sm btn-primary" data-dmca-view="' + escapeHtml(item.case_code || '') + '">Add info</button>');
        }

        if (item.can_delete_video) {
            buttons.push('<button type="button" class="btn btn-sm btn-danger" data-dmca-delete-case="' + escapeHtml(item.case_code || '') + '">Delete video</button>');
        }

        return buttons.join('');
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

        var rows = items.map(function (item) {
            var videoTitle = item.video && item.video.title ? item.video.title : 'Deleted video';
            var reportedUrl = item.reported_url
                ? '<a href="' + escapeHtml(item.reported_url) + '" target="_blank" rel="noopener">reported link</a>'
                : 'No reported link';
            var claimant = item.complainant_company
                ? escapeHtml(item.complainant_name || '') + ' (' + escapeHtml(item.complainant_company) + ')'
                : escapeHtml(item.complainant_name || 'Unknown claimant');

            return [
                '<tr>',
                '<td>',
                '<strong class="d-block">' + escapeHtml(item.case_code || '') + '</strong>',
                '<small class="text-muted d-block mt-1">' + claimant + '</small>',
                '</td>',
                '<td>',
                '<strong class="d-block">' + escapeHtml(videoTitle) + '</strong>',
                '<small class="text-muted d-block mt-1">' + escapeHtml(item.claimed_work || 'No claimed work supplied') + '</small>',
                '<small class="text-muted d-block mt-1">' + reportedUrl + '</small>',
                '</td>',
                '<td>' + statusBadge(item) + '</td>',
                '<td>' + deadlineLabel(item) + '</td>',
                '<td class="dmca-table-actions">' + rowActions(item) + '</td>',
                '</tr>'
            ].join('');
        }).join('');

        els.list.insertAdjacentHTML('beforeend', rows);
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

    function renderEvidence(notice) {
        if (!Array.isArray(notice.evidence_urls) || !notice.evidence_urls.length) {
            return '<p class="mb-0 text-muted">No evidence URLs were attached to this complaint.</p>';
        }

        return '<ul class="mb-0">' + notice.evidence_urls.map(function (url) {
            return '<li><a href="' + escapeHtml(url) + '" target="_blank" rel="noopener">' + escapeHtml(url) + '</a></li>';
        }).join('') + '</ul>';
    }

    function renderTimeline(notice) {
        if (!Array.isArray(notice.timeline) || !notice.timeline.length) {
            return '<p class="mb-0 text-muted">No timeline entries were recorded for this case yet.</p>';
        }

        return '<ul class="dmca-timeline">' + notice.timeline.map(function (eventItem) {
            return [
                '<li>',
                '<strong>' + escapeHtml(eventItem.title || '') + '</strong>',
                '<span class="d-block mb-2">' + escapeHtml(eventItem.created_label || '') + '</span>',
                '<p>' + escapeHtml(eventItem.body || '') + '</p>',
                '</li>'
            ].join('');
        }).join('') + '</ul>';
    }

    function renderUploaderResponse(notice) {
        var response = notice.uploader_response || {};
        var contactEmail = response.contact_email || '';
        var contactPhone = response.contact_phone || '';
        var notes = response.notes || '';

        if (notice.can_submit_response) {
            return [
                '<div class="dmca-modal-card mt-4">',
                '<h5 class="mb-3">Optional uploader response</h5>',
                '<p class="dmca-modal-response-note">These fields are optional. The file stays online while the case is reviewed, and it is deleted automatically only if the review window expires without uploader action.</p>',
                '<form data-dmca-response-form data-case-code="' + escapeHtml(notice.case_code || '') + '">',
                '<div class="form-row">',
                '<div class="form-group col-md-6">',
                '<label>Contact email</label>',
                '<input type="email" class="form-control" name="contact_email" value="' + escapeHtml(contactEmail) + '" placeholder="Optional">',
                '</div>',
                '<div class="form-group col-md-6">',
                '<label>Contact phone</label>',
                '<input type="text" class="form-control" name="contact_phone" value="' + escapeHtml(contactPhone) + '" placeholder="Optional">',
                '</div>',
                '</div>',
                '<div class="form-group">',
                '<label>Notes</label>',
                '<textarea class="form-control" name="notes" rows="4" placeholder="Optional details for review">' + escapeHtml(notes) + '</textarea>',
                '</div>',
                '<div class="dmca-modal-actions">',
                '<button type="submit" class="btn btn-primary mr-2 mb-2">Send optional info</button>',
                (notice.can_delete_video ? '<button type="button" class="btn btn-danger mb-2" data-dmca-delete-case="' + escapeHtml(notice.case_code || '') + '">Delete video now</button>' : ''),
                '</div>',
                '</form>',
                '</div>'
            ].join('');
        }

        if (notice.status === 'response_submitted' || notice.status === 'counter_submitted') {
            return [
                '<div class="dmca-modal-card mt-4">',
                '<h5 class="mb-3">Uploader response</h5>',
                '<p class="dmca-modal-response-note">Submitted ' + escapeHtml(notice.response_submitted_label || '') + '</p>',
                '<dl class="dmca-detail-list mb-0">',
                '<dt>Contact email</dt><dd>' + escapeHtml(contactEmail || 'Not provided') + '</dd>',
                '<dt>Contact phone</dt><dd>' + escapeHtml(contactPhone || 'Not provided') + '</dd>',
                '<dt>Notes</dt><dd>' + escapeHtml(notes || 'No extra information was provided.') + '</dd>',
                '</dl>',
                '</div>'
            ].join('');
        }

        return '';
    }

    function renderModal(notice) {
        if (!notice || !els.modalTitle || !els.modalBody) {
            return;
        }

        var videoTitle = notice.video && notice.video.title ? notice.video.title : 'Deleted video';
        var complainant = notice.complainant_company
            ? escapeHtml(notice.complainant_name || '') + ' (' + escapeHtml(notice.complainant_company) + ')'
            : escapeHtml(notice.complainant_name || 'Unknown claimant');
        var complainantContact = [notice.complainant_email || '', notice.complainant_phone || '', notice.complainant_country || '']
            .filter(Boolean)
            .join(' | ');
        var deadline = deadlineLabel(notice);
        var watchUrl = notice.video && notice.video.watch_url ? notice.video.watch_url : '';
        var summaryItems = [
            '<div class="dmca-modal-summary-item"><span>Status</span><strong>' + statusBadge(notice) + '</strong></div>',
            '<div class="dmca-modal-summary-item"><span>Next step</span><strong>' + deadline + '</strong></div>',
            '<div class="dmca-modal-summary-item"><span>Received</span><strong>' + escapeHtml(notice.received_label || 'Not available') + '</strong></div>'
        ];

        if (notice.can_delete_video) {
            summaryItems.push('<div class="dmca-modal-summary-item"><span>Video action</span><strong>Uploader can delete this video now</strong></div>');
        }

        var reviewNote = notice.status === 'pending_review'
            ? '<div class="dmca-modal-alert">The reported file stays online while this complaint is under review. The uploader can add optional information or delete the file directly.</div>'
            : '';

        state.selectedCaseCode = notice.case_code || '';
        els.modalTitle.textContent = (notice.case_code || 'DMCA case') + ' - ' + videoTitle;
        els.modalBody.innerHTML = [
            reviewNote,
            '<div class="dmca-modal-intro">',
            '<span class="dmca-modal-intro-label">Uploader DMCA case</span>',
            '<h4>' + escapeHtml(videoTitle) + '</h4>',
            '<p>Review the claimant details, keep the file online while the case is reviewed, send optional information if you want, or delete the video directly from this case.</p>',
            '<div class="dmca-modal-summary">' + summaryItems.join('') + '</div>',
            '</div>',
            '<div class="row">',
            '<div class="col-lg-6 mb-4">',
            '<div class="dmca-modal-card">',
            '<h5>Case details</h5>',
            '<dl class="dmca-detail-list">',
            '<dt>Status</dt><dd>' + statusBadge(notice) + '</dd>',
            '<dt>Case code</dt><dd>' + escapeHtml(notice.case_code || 'Not provided') + '</dd>',
            '<dt>Claimed work</dt><dd>' + escapeHtml(notice.claimed_work || 'Not provided') + '</dd>',
            '<dt>Reported URL</dt><dd>' + (notice.reported_url ? '<a href="' + escapeHtml(notice.reported_url) + '" target="_blank" rel="noopener">' + escapeHtml(notice.reported_url) + '</a>' : 'Not provided') + '</dd>',
            '<dt>Reference URL</dt><dd>' + (notice.work_reference_url ? '<a href="' + escapeHtml(notice.work_reference_url) + '" target="_blank" rel="noopener">' + escapeHtml(notice.work_reference_url) + '</a>' : 'Not provided') + '</dd>',
            '<dt>Claimant</dt><dd>' + complainant + '</dd>',
            '<dt>Claimant contact</dt><dd>' + escapeHtml(complainantContact || 'Not provided') + '</dd>',
            '</dl>',
            '</div>',
            '</div>',
            '<div class="col-lg-6 mb-4">',
            '<div class="dmca-modal-card">',
            '<h5>File and review</h5>',
            '<dl class="dmca-detail-list mb-0">',
            '<dt>File</dt><dd>' + escapeHtml(videoTitle) + '</dd>',
            '<dt>Watch URL</dt><dd>' + (watchUrl ? '<a href="' + escapeHtml(watchUrl) + '" target="_blank" rel="noopener">' + escapeHtml(watchUrl) + '</a>' : 'Video is no longer available') + '</dd>',
            '<dt>Received</dt><dd>' + escapeHtml(notice.received_label || 'Not available') + '</dd>',
            '<dt>Next step</dt><dd>' + deadline + '</dd>',
            '<dt>Review window</dt><dd>' + escapeHtml((notice.auto_delete_remaining_label || '') ? notice.auto_delete_remaining_label : ('Auto remove after ' + (notice.auto_delete_label || '24 hours'))) + '</dd>',
            '</dl>',
            '</div>',
            '</div>',
            '</div>',
            '<div class="row">',
            '<div class="col-lg-6 mb-4">',
            '<div class="dmca-modal-card">',
            '<h5>Evidence</h5>',
            renderEvidence(notice),
            '</div>',
            '</div>',
            '<div class="col-lg-6 mb-4">',
            '<div class="dmca-modal-card">',
            '<h5>Timeline</h5>',
            renderTimeline(notice),
            '</div>',
            '</div>',
            '</div>',
            renderUploaderResponse(notice)
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

    function openCase(caseCode) {
        requestJson('/api/dmca/' + encodeURIComponent(caseCode || ''), {
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

    function deleteCase(caseCode) {
        var formData = new FormData();
        formData.append('token', window.VE_CSRF_TOKEN || '');

        requestJson('/api/dmca/' + encodeURIComponent(caseCode || '') + '/delete-video', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json'
            },
            body: formData
        }).then(function (payload) {
            renderSummary(payload.summary || {});
            reloadCases();

            if (payload.notice) {
                renderModal(payload.notice);
            } else {
                $('#dmca-case-modal').modal('hide');
            }

            showToast('success', 'Video deleted', payload.message || 'The reported video was deleted.');
        }).catch(function (error) {
            showToast('error', 'Delete failed', error && error.message ? error.message : 'The reported video could not be deleted.');
        });
    }

    root.addEventListener('click', function (event) {
        var navLink = event.target.closest('.settings_menu a, [data-dmca-nav]');

        if (navLink) {
            event.preventDefault();
            activatePanel(navLink.getAttribute('href') || '#dmca_cases', true);
            return;
        }

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
            activatePanel('#dmca_cases', true);
            openCase(viewButton.getAttribute('data-dmca-view') || '');
            return;
        }

        var deleteButton = event.target.closest('[data-dmca-delete-case]');

        if (deleteButton) {
            var caseCode = deleteButton.getAttribute('data-dmca-delete-case') || '';

            if (!window.confirm('Delete this reported video now? This action cannot be undone.')) {
                return;
            }

            deleteCase(caseCode);
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
        var form = event.target.closest('[data-dmca-response-form]');

        if (!form) {
            return;
        }

        event.preventDefault();
        var formData = new FormData(form);
        formData.append('token', window.VE_CSRF_TOKEN || '');

        requestJson('/api/dmca/' + encodeURIComponent(form.getAttribute('data-case-code') || '') + '/response', {
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
            showToast('success', 'Response saved', payload.message || 'Optional uploader information was saved.');
        }).catch(function (error) {
            showToast('error', 'Response failed', error && error.message ? error.message : 'Optional uploader information could not be saved.');
        });
    });

    window.addEventListener('hashchange', function () {
        activatePanel(window.location.hash, false);
    });

    activatePanel(window.location.hash || '#dmca_cases', false);
    loadCases(false);
}());
