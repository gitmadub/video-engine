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

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (character) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            }[character];
        });
    }

    function formatFixedGigabytes(bytes, fallbackLabel) {
        if (typeof bytes === 'number' && isFinite(bytes)) {
            return (bytes / (1024 * 1024 * 1024)).toFixed(2) + ' GB';
        }

        return fallbackLabel || '0.00 GB';
    }

    function formatPremiumBandwidthLabel(bytes, fallbackLabel) {
        if (typeof bytes === 'number' && isFinite(bytes) && bytes <= 0) {
            return formatFixedGigabytes(bytes, fallbackLabel);
        }

        if (!fallbackLabel || fallbackLabel === '0 B') {
            return formatFixedGigabytes(typeof bytes === 'number' ? bytes : 0, fallbackLabel);
        }

        return fallbackLabel;
    }

    function copyText(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text);
        }

        return new Promise(function (resolve, reject) {
            var input = document.createElement('textarea');
            input.value = text;
            input.setAttribute('readonly', 'readonly');
            input.style.position = 'fixed';
            input.style.opacity = '0';
            document.body.appendChild(input);
            input.focus();
            input.select();

            try {
                if (!document.execCommand('copy')) {
                    throw new Error('Copy failed.');
                }

                document.body.removeChild(input);
                resolve();
            } catch (error) {
                document.body.removeChild(input);
                reject(error);
            }
        });
    }

    function showCopyState(button, copied) {
        if (!button) {
            return;
        }

        if (copied) {
            button.classList.add('is-copied');
            button.textContent = button.getAttribute('data-copy-success') || 'Copied';
            window.setTimeout(function () {
                button.classList.remove('is-copied');
                button.textContent = button.getAttribute('data-copy-label') || 'Copy';
            }, 1400);
            return;
        }

        button.classList.add('is-copy-error');
        window.setTimeout(function () {
            button.classList.remove('is-copy-error');
        }, 1400);
    }

    function insertAfter(referenceNode, node) {
        if (!referenceNode || !referenceNode.parentNode) {
            return;
        }

        if (referenceNode.nextSibling) {
            referenceNode.parentNode.insertBefore(node, referenceNode.nextSibling);
            return;
        }

        referenceNode.parentNode.appendChild(node);
    }

    function findChartInstance(component) {
        if (!component) {
            return null;
        }

        if (component.$data && component.$data._chart) {
            return component.$data._chart;
        }

        var children = Array.isArray(component.$children) ? component.$children : [];
        var index;

        for (index = 0; index < children.length; index += 1) {
            var chart = findChartInstance(children[index]);

            if (chart) {
                return chart;
            }
        }

        return null;
    }

    function enhanceModalBody(modal) {
        if (!modal || !modal.body) {
            return;
        }

        Array.prototype.forEach.call(modal.body.querySelectorAll('.ve-premium-copy-input'), function (field) {
            field.addEventListener('focus', function () {
                field.select();
            });
        });

        Array.prototype.forEach.call(modal.body.querySelectorAll('.ve-premium-qr-frame'), function (frame) {
            var image = frame.querySelector('.ve-premium-qr-image');

            if (!image) {
                return;
            }

            function markLoaded() {
                frame.classList.remove('is-loading');
                frame.classList.add('is-loaded');
            }

            function markError() {
                frame.classList.remove('is-loading');
                frame.classList.add('is-error');
            }

            frame.classList.add('is-loading');

            if (image.complete && image.naturalWidth > 0) {
                markLoaded();
                return;
            }

            image.addEventListener('load', markLoaded, { once: true });
            image.addEventListener('error', markError, { once: true });
        });
    }

    function applyPremiumChartFloor(vm) {
        if (!vm) {
            return;
        }

        var options = vm.chart_options || {};
        var tooltips = options.tooltips || {};
        var hover = options.hover || {};
        var scales = options.scales || {};
        var yAxis = (Array.isArray(scales.yAxes) && scales.yAxes[0]) ? scales.yAxes[0] : {};
        var xAxis = (Array.isArray(scales.xAxes) && scales.xAxes[0]) ? scales.xAxes[0] : {};

        tooltips.enabled = true;
        tooltips.mode = 'index';
        tooltips.intersect = false;
        options.tooltips = tooltips;

        hover.mode = 'index';
        hover.intersect = false;
        hover.animationDuration = 0;
        options.hover = hover;

        yAxis.ticks = Object.assign({}, yAxis.ticks || {}, {
            beginAtZero: true,
            min: 0,
            suggestedMin: 0,
            precision: 0
        });
        yAxis.gridLines = Object.assign({}, yAxis.gridLines || {}, {
            color: 'rgba(255, 255, 255, 0.08)',
            zeroLineColor: 'rgba(255, 153, 0, 0.28)'
        });
        xAxis.gridLines = Object.assign({}, xAxis.gridLines || {}, {
            display: false
        });
        xAxis.ticks = Object.assign({}, xAxis.ticks || {}, {
            fontColor: '#8b8b8b'
        });

        scales.yAxes = [yAxis];
        scales.xAxes = [xAxis];
        options.scales = scales;
        options.maintainAspectRatio = false;
        vm.chart_options = options;

        var chart = findChartInstance(vm);

        if (chart) {
            chart.options = Object.assign({}, chart.options || {}, options);
            chart.update(0);
        }
    }

    function ensureCheckoutModal() {
        if (window.__vePremiumCheckoutModal) {
            return window.__vePremiumCheckoutModal;
        }

        var root = document.createElement('div');
        root.id = 've-premium-checkout-modal';
        root.className = 've-premium-modal';
        root.setAttribute('aria-hidden', 'true');
        root.innerHTML = ''
            + '<div class="ve-premium-modal-backdrop" data-close-modal="1"></div>'
            + '<div class="ve-premium-modal-shell">'
            + '  <div class="ve-premium-modal-card the_box" role="dialog" aria-modal="true" aria-labelledby="ve-premium-checkout-title">'
            + '    <div class="ve-premium-modal-header">'
            + '      <div>'
            + '        <span class="ve-premium-modal-kicker">Premium checkout</span>'
            + '        <h3 class="ve-premium-modal-title" id="ve-premium-checkout-title">Checkout</h3>'
            + '        <p class="ve-premium-modal-subtitle"></p>'
            + '      </div>'
            + '      <button type="button" class="ve-premium-modal-close" data-close-modal="1" aria-label="Close checkout">&times;</button>'
            + '    </div>'
            + '    <div class="ve-premium-modal-notice is-hidden"></div>'
            + '    <div class="ve-premium-modal-body"></div>'
            + '    <div class="ve-premium-modal-footer"></div>'
            + '  </div>'
            + '</div>';
        document.body.appendChild(root);

        var modal = {
            root: root,
            shell: root.querySelector('.ve-premium-modal-shell'),
            title: root.querySelector('.ve-premium-modal-title'),
            subtitle: root.querySelector('.ve-premium-modal-subtitle'),
            notice: root.querySelector('.ve-premium-modal-notice'),
            body: root.querySelector('.ve-premium-modal-body'),
            footer: root.querySelector('.ve-premium-modal-footer')
        };

        root.addEventListener('click', function (event) {
            if (event.target.closest('[data-close-modal="1"]') || event.target === modal.shell) {
                closeCheckoutModal();
            }
        });

        modal.body.addEventListener('click', function (event) {
            var copyButton = event.target.closest('[data-copy-text]');

            if (!copyButton) {
                return;
            }

            event.preventDefault();

            var copyValue = copyButton.getAttribute('data-copy-text') || '';
            if (!copyValue) {
                return;
            }

            copyText(copyValue).then(function () {
                showCopyState(copyButton, true);
            }).catch(function () {
                showCopyState(copyButton, false);
            });
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && modal.root.classList.contains('is-open')) {
                closeCheckoutModal();
            }
        });

        window.__vePremiumCheckoutModal = modal;
        return modal;
    }

    function computeHeaderOffset() {
        var header = document.querySelector('.main-menu');

        if (!header) {
            return 96;
        }

        var rect = header.getBoundingClientRect();
        return Math.max(72, Math.round(rect.bottom) + 12);
    }

    function openCheckoutModal() {
        var modal = ensureCheckoutModal();
        modal.root.style.setProperty('--ve-premium-header-offset', computeHeaderOffset() + 'px');
        modal.root.classList.add('is-open');
        modal.root.setAttribute('aria-hidden', 'false');
        document.body.classList.add('ve-premium-modal-active');
    }

    function closeCheckoutModal() {
        var modal = ensureCheckoutModal();
        modal.root.classList.remove('is-open');
        modal.root.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('ve-premium-modal-active');
    }

    function setCheckoutNotice(type, message) {
        var modal = ensureCheckoutModal();
        modal.notice.className = 've-premium-modal-notice';

        if (!message) {
            modal.notice.classList.add('is-hidden');
            modal.notice.textContent = '';
            return;
        }

        modal.notice.classList.add('is-' + (type || 'info'));
        modal.notice.textContent = message;
    }

    function setCheckoutActions(actions) {
        var modal = ensureCheckoutModal();
        modal.footer.innerHTML = '';

        (actions || []).forEach(function (action) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = action.primary ? 'btn btn-primary' : 'btn ve-premium-btn-secondary';
            button.textContent = action.label || 'Continue';
            button.disabled = !!action.disabled;

            if (typeof action.onClick === 'function') {
                button.addEventListener('click', action.onClick);
            }

            modal.footer.appendChild(button);
        });
    }

    function showCheckoutModal(title, subtitle, bodyHtml, noticeType, noticeMessage, actions) {
        var modal = ensureCheckoutModal();
        modal.title.textContent = title || 'Checkout';
        modal.subtitle.textContent = subtitle || '';
        modal.body.innerHTML = bodyHtml || '';
        enhanceModalBody(modal);
        setCheckoutNotice(noticeType, noticeMessage || '');
        setCheckoutActions(actions || []);
        openCheckoutModal();
    }

    function showCheckoutLoading(title, subtitle) {
        showCheckoutModal(
            title,
            subtitle,
            '<div class="ve-premium-checkout-loading">'
                + '<div><strong class="d-block mb-2">Preparing your checkout</strong><span class="text-muted">Fetching the latest pricing, balance, and entitlement state.</span></div>'
            + '</div>',
            '',
            '',
            [
                {
                    label: 'Close',
                    primary: false,
                    onClick: closeCheckoutModal
                }
            ]
        );
    }

    function requestJson(path, payload) {
        var formData = new URLSearchParams();
        payload = payload || {};

        Object.keys(payload).forEach(function (key) {
            if (payload[key] !== undefined && payload[key] !== null) {
                formData.append(key, String(payload[key]));
            }
        });

        if (!formData.has('token')) {
            formData.append('token', window.VE_CSRF_TOKEN || '');
        }

        return fetch(appUrl(path), {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': window.VE_CSRF_TOKEN || ''
            },
            credentials: 'same-origin',
            body: formData.toString()
        }).then(function (response) {
            return response.text().then(function (text) {
                var parsed = {};

                try {
                    parsed = text ? JSON.parse(text) : {};
                } catch (error) {
                    var parseError = new Error(text || 'The server returned an invalid JSON response.');
                    parseError.status = response.status;
                    throw parseError;
                }

                if (!response.ok || parsed.status === 'fail') {
                    var requestError = new Error(parsed.message || 'The request could not be completed.');
                    requestError.status = response.status;
                    requestError.payload = parsed;
                    throw requestError;
                }

                return parsed;
            });
        });
    }

    function buildPremiumSelection(vm, purchaseType) {
        var isAccount = purchaseType === 'account';
        var selection = isAccount
            ? vm.premium_plans[vm.premium_selected.index]
            : vm.packages[vm.package_selected.index];
        var paymentCode = isAccount ? vm.payment_selected : vm.payment_selected_bw;

        return {
            purchase_type: purchaseType,
            package_id: selection && selection.id ? selection.id : '',
            package_title: selection && selection.title ? selection.title : '',
            payment_method: paymentCode || 'balance'
        };
    }

    function currentPremiumPage(vm) {
        if (vm && vm.data && vm.data.page) {
            return vm.data.page;
        }

        if (vm && vm.page) {
            return vm.page;
        }

        return {};
    }

    function renderPremiumOverview(vm) {
        var root = document.querySelector('.my-premium');

        if (!root) {
            return;
        }

        var page = currentPremiumPage(vm);
        var accountStatus = page.plan_label || 'Free account';
        var accountDetail = page.premium_until_label || 'No active renewal';
        var bandwidthStatus = formatPremiumBandwidthLabel(page.purchased_bw, page.purchased_bw_label || '');
        var bandwidthDetail = 'Remaining ' + escapeHtml(formatPremiumBandwidthLabel(page.available_bw, page.available_bw_label || '')) + ' • ' + escapeHtml(page.premium_bandwidth_status_label || 'Inactive');
        var usageStatus = formatPremiumBandwidthLabel(page.used_bw, page.used_bw_label || '');
        var usageDetail = page.premium_bandwidth_status_detail || 'Only traffic served while own adverts are enabled counts against premium bandwidth.';
        var overview = root.querySelector('[data-premium-overview]');

        if (!overview) {
            overview = document.createElement('section');
            overview.className = 've-premium-overview';
            overview.setAttribute('data-premium-overview', '1');
            root.insertBefore(overview, root.firstChild);
        }

        overview.innerHTML = ''
            + '<div class="ve-premium-overview-card the_box" data-premium-card="account">'
            + '  <span class="ve-premium-overview-kicker" data-premium-card-title="account">Premium account</span>'
            + '  <strong>' + escapeHtml(accountStatus) + '</strong>'
            + '  <p>' + escapeHtml(accountDetail) + '</p>'
            + '</div>'
            + '<div class="ve-premium-overview-card the_box" data-premium-card="bandwidth">'
            + '  <span class="ve-premium-overview-kicker" data-premium-card-title="bandwidth">Premium bandwidth</span>'
            + '  <strong>' + escapeHtml(bandwidthStatus) + '</strong>'
            + '  <p>' + bandwidthDetail + '</p>'
            + '</div>'
            + '<div class="ve-premium-overview-card the_box" data-premium-card="usage">'
            + '  <span class="ve-premium-overview-kicker" data-premium-card-title="usage">Premium bandwidth usage</span>'
            + '  <strong>' + escapeHtml(usageStatus) + '</strong>'
            + '  <p>' + escapeHtml(usageDetail) + '</p>'
            + '</div>';
    }

    function renderUsageSummary(vm) {
        var root = document.querySelector('.my-premium');

        if (!root) {
            return;
        }

        var page = currentPremiumPage(vm);
        var usageCard = root.querySelector('.ve-premium-usage-row .col-md-2 .the_box.usage');

        if (!usageCard) {
            return;
        }

        var totals = usageCard.querySelectorAll('.used strong');

        if (totals[0]) {
            totals[0].textContent = formatPremiumBandwidthLabel(page.used_bw, page.used_bw_label || '');
        }

        if (totals[1]) {
            totals[1].textContent = formatPremiumBandwidthLabel(page.available_bw, page.available_bw_label || '');
        }
    }

    function reorderPremiumSections(vm) {
        var root = document.querySelector('.my-premium');

        if (!root) {
            return;
        }

        var directChildren = Array.prototype.slice.call(root.children || []);
        var mainRow = directChildren.find(function (element) {
            return element && element.classList && element.classList.contains('ve-premium-usage-row');
        }) || directChildren.find(function (element) {
            return element && element.classList
                && element.classList.contains('row')
                && !element.classList.contains('premium-plans');
        }) || null;
        var accountTitle = directChildren.find(function (element) {
            return element && element.tagName === 'H2' && element.classList && element.classList.contains('title');
        }) || null;
        var accountRow = directChildren.find(function (element) {
            return element && element.classList
                && element.classList.contains('row')
                && element.classList.contains('premium-plans')
                && !element.classList.contains('ve-premium-usage-row')
                && !element.classList.contains('ve-premium-bandwidth-row');
        }) || null;

        if (accountTitle && accountRow && mainRow) {
            root.insertBefore(accountRow, mainRow);
            root.insertBefore(accountTitle, accountRow);
        }

        if (!mainRow) {
            return;
        }

        mainRow.classList.add('premium-plans', 'mb-4', 've-premium-usage-row');

        var mainRowChildren = Array.prototype.slice.call(mainRow.children || []);
        var usageTitle = mainRowChildren.find(function (element) {
            return element && element.classList
                && element.classList.contains('col-md-12')
                && !element.classList.contains('mt-4');
        }) || null;
        var usageCard = mainRowChildren.find(function (element) {
            return element && element.classList && element.classList.contains('col-md-2');
        }) || null;
        var usageChart = mainRowChildren.find(function (element) {
            return element && element.classList && element.classList.contains('col-md-10');
        }) || null;
        var bandwidthTitle = mainRowChildren.find(function (element) {
            return element && element.classList
                && element.classList.contains('col-md-12')
                && element.classList.contains('mt-4');
        }) || null;
        var bandwidthPlans = bandwidthTitle ? bandwidthTitle.nextElementSibling : null;
        var bandwidthRow = root.querySelector('.ve-premium-bandwidth-row');

        if (!bandwidthRow) {
            bandwidthRow = document.createElement('div');
            bandwidthRow.className = 'row premium-plans mb-4 ve-premium-bandwidth-row';
            insertAfter(mainRow, bandwidthRow);
        } else if (mainRow.nextElementSibling !== bandwidthRow) {
            insertAfter(mainRow, bandwidthRow);
        }

        if (bandwidthTitle) {
            bandwidthTitle.classList.remove('mt-4');
        }

        [usageTitle, usageCard, usageChart].forEach(function (element) {
            if (element && element.parentNode === mainRow) {
                mainRow.appendChild(element);
            }
        });

        [bandwidthTitle, bandwidthPlans].forEach(function (element) {
            if (element && element.parentNode !== bandwidthRow) {
                bandwidthRow.appendChild(element);
            }
        });
    }

    function renderPremiumLayout(vm) {
        reorderPremiumSections(vm);
        renderPremiumOverview(vm);
        renderUsageSummary(vm);
    }

    function applyPremiumSummary(vm, summary) {
        if (!vm || !summary || !vm.data || !vm.data.page) {
            return;
        }

        Object.keys(summary).forEach(function (key) {
            if (typeof vm.$set === 'function') {
                vm.$set(vm.data.page, key, summary[key]);
            } else {
                vm.data.page[key] = summary[key];
            }

            if (vm.page) {
                vm.page[key] = summary[key];
            }
        });

        if (vm.chart_data) {
            vm.chart_data.labels = Array.isArray(summary.labels) ? summary.labels.slice() : [];

            if (Array.isArray(vm.chart_data.datasets) && vm.chart_data.datasets[0]) {
                vm.chart_data.datasets[0].data = Array.isArray(summary.stats) ? summary.stats.slice() : [];
            }
        }

        applyPremiumChartFloor(vm);

        if (typeof vm.$forceUpdate === 'function') {
            vm.$forceUpdate();
        }

        renderPremiumLayout(vm);
    }

    function renderBalanceCheckoutBody(quote) {
        var hasShortfall = (quote.remaining_balance_micro_usd || 0) < 0;
        var balanceOutcomeLabel = hasShortfall ? 'Additional funds needed' : 'Balance after';
        var balanceOutcomeValue = hasShortfall
            ? (quote.shortfall_label || (quote.remaining_balance_label || '').replace('-', '') || '$0.00000')
            : (quote.remaining_balance_label || '$0.00000');
        var expiryCard = '';

        if (quote.purchase_type === 'account') {
            expiryCard = ''
                + '<div class="ve-premium-checkout-cardline">'
                + '  <h6>After purchase</h6>'
                + '  <p>Premium access will run until <strong>' + escapeHtml(quote.projected_premium_until_label || 'the updated renewal date') + '</strong>.</p>'
                + '</div>';
        } else {
            expiryCard = ''
                + '<div class="ve-premium-checkout-cardline">'
                + '  <h6>Bandwidth credit</h6>'
                + '  <p><strong>' + escapeHtml(quote.bandwidth_label || quote.package_title) + '</strong> will be added immediately after confirmation.</p>'
                + '</div>';
        }

        return ''
            + '<div class="ve-premium-checkout-grid">'
            + '  <div class="ve-premium-checkout-stat">'
            + '    <span>Current balance</span>'
            + '    <strong>' + escapeHtml(quote.balance_label || '$0.00000') + '</strong>'
            + '  </div>'
            + '  <div class="ve-premium-checkout-stat">'
            + '    <span>Charge now</span>'
            + '    <strong>' + escapeHtml(quote.amount_label || '$0.00000') + '</strong>'
            + '  </div>'
            + '  <div class="ve-premium-checkout-stat' + (hasShortfall ? ' negative' : '') + '">'
            + '    <span>' + escapeHtml(balanceOutcomeLabel) + '</span>'
            + '    <strong>' + escapeHtml(balanceOutcomeValue) + '</strong>'
            + '  </div>'
            + '</div>'
            + '<div class="ve-premium-checkout-meta">'
            + '  <div class="ve-premium-checkout-cardline">'
            + '    <h6>Selected package</h6>'
            + '    <p>' + escapeHtml(quote.package_title || '') + ' via ' + escapeHtml(quote.payment_label || 'Balance') + '. This purchase is processed instantly and keeps you on the same page.</p>'
            + '  </div>'
            +      expiryCard
            + '</div>';
    }

    function renderCryptoInvoiceBody(quote, invoice) {
        var amountValue = invoice.amount || '';
        var currencyCode = invoice.currency_code || '';

        return ''
            + '<div class="ve-premium-invoice-layout">'
            + '  <div class="ve-premium-qr-wrap">'
            + '    <div class="ve-premium-qr-frame">'
            + '      <div class="ve-premium-qr-loader" aria-hidden="true"></div>'
            + '      <img class="ve-premium-qr-image" alt="Crypto payment QR" src="' + escapeHtml(invoice.qr || '') + '">'
            + '    </div>'
            + '    <div class="ve-premium-inline-actions">'
            + '      <a class="btn btn-primary btn-block" target="_blank" rel="noopener" href="' + escapeHtml(invoice.payment_uri || '#') + '">Click here to pay</a>'
            + '    </div>'
            + '  </div>'
            + '  <div class="ve-premium-invoice-details">'
            + '    <p class="text-muted mb-3">Send exactly <strong>' + escapeHtml(amountValue) + ' ' + escapeHtml(currencyCode) + '</strong> to the wallet below.</p>'
            + '    <div class="ve-premium-copy-stack">'
            + '      <div class="ve-premium-copy-field">'
            + '        <label class="ve-premium-copy-label" for="ve-premium-copy-amount">Amount <span class="ve-premium-copy-unit">' + escapeHtml(currencyCode) + '</span></label>'
            + '        <div class="ve-premium-copy-box">'
            + '          <input id="ve-premium-copy-amount" class="ve-premium-copy-input" type="text" readonly value="' + escapeHtml(amountValue) + '">'
            + '          <button type="button" class="btn ve-premium-btn-secondary ve-premium-copy-button" data-copy-text="' + escapeHtml(amountValue) + '" data-copy-label="Copy" data-copy-success="Done">Copy</button>'
            + '        </div>'
            + '      </div>'
            + '      <div class="ve-premium-copy-field">'
            + '        <label class="ve-premium-copy-label" for="ve-premium-copy-wallet">Wallet address</label>'
            + '        <div class="ve-premium-copy-box ve-premium-copy-box-address">'
            + '          <input id="ve-premium-copy-wallet" class="ve-premium-copy-input ve-premium-copy-wallet" type="text" readonly value="' + escapeHtml(invoice.address || '') + '">'
            + '          <button type="button" class="btn ve-premium-btn-secondary ve-premium-copy-button" data-copy-text="' + escapeHtml(invoice.address || '') + '" data-copy-label="Copy" data-copy-success="Done">Copy</button>'
            + '        </div>'
            + '      </div>'
            + '    </div>'
            + '    <p class="text-muted">This is a sandbox invoice in the same product flow. Real crypto settlement and automatic crediting will be wired in when the payment gateway is connected.</p>'
            + '    <div class="ve-premium-invoice-meta">'
            + '      <div class="ve-premium-checkout-stat">'
            + '        <span>Invoice</span>'
            + '        <strong>' + escapeHtml(invoice.order_code || '') + '</strong>'
            + '      </div>'
            + '      <div class="ve-premium-checkout-stat">'
            + '        <span>Package</span>'
            + '        <strong>' + escapeHtml(quote.package_title || '') + '</strong>'
            + '      </div>'
            + '    </div>'
            + '  </div>'
            + '</div>';
    }

    function renderBalanceConfirmation(vm, quote, noticeType, noticeMessage) {
        var title = (quote.package_title || 'Premium') + ' - ' + (quote.purchase_type === 'account' ? 'Plan' : 'Package') + ' - Balance payment';
        var subtitle = quote.purchase_type === 'account'
            ? 'Confirm the balance charge to activate premium without leaving the page.'
            : 'Confirm the balance charge to add premium bandwidth instantly.';

        showCheckoutModal(
            title,
            subtitle,
            renderBalanceCheckoutBody(quote),
            noticeType || (quote.can_pay ? 'info' : 'warning'),
            noticeMessage || (quote.can_pay ? 'Balance payments are applied immediately after confirmation.' : (quote.insufficient_balance_message || 'Your current balance is not high enough for this purchase.')),
            [
                {
                    label: 'Cancel',
                    primary: false,
                    onClick: closeCheckoutModal
                },
                {
                    label: quote.can_pay ? 'Confirm purchase' : 'Balance too low',
                    primary: true,
                    disabled: !quote.can_pay,
                    onClick: function () {
                        confirmBalanceCheckout(vm, quote);
                    }
                }
            ]
        );
    }

    function renderPurchaseSuccess(vm, quote, response) {
        applyPremiumSummary(vm, response.summary || null);

        var bodyHtml = ''
            + '<div class="ve-premium-checkout-grid">'
            + '  <div class="ve-premium-checkout-stat">'
            + '    <span>Order</span>'
            + '    <strong>' + escapeHtml(response.order_code || '') + '</strong>'
            + '  </div>'
            + '  <div class="ve-premium-checkout-stat">'
            + '    <span>Package</span>'
            + '    <strong>' + escapeHtml(quote.package_title || '') + '</strong>'
            + '  </div>'
            + '  <div class="ve-premium-checkout-stat">'
            + '    <span>Charged</span>'
            + '    <strong>' + escapeHtml(quote.amount_label || '') + '</strong>'
            + '  </div>'
            + '</div>';

        showCheckoutModal(
            'Purchase completed',
            'Your account has been updated without a page reload.',
            bodyHtml,
            'success',
            response.message || 'Premium purchase completed successfully.',
            [
                {
                    label: 'Done',
                    primary: true,
                    onClick: closeCheckoutModal
                }
            ]
        );
    }

    function showCheckoutFailure(title, message) {
        showCheckoutModal(
            title,
            'The checkout request did not complete.',
            '<p class="text-muted mb-0">Review the message below and try again.</p>',
            'danger',
            message || 'Unable to complete the checkout right now.',
            [
                {
                    label: 'Close',
                    primary: false,
                    onClick: closeCheckoutModal
                }
            ]
        );
    }

    function confirmBalanceCheckout(vm, quote) {
        showCheckoutLoading('Processing balance purchase', 'Applying your premium purchase and refreshing the account state.');

        requestJson('/api/billing/balance', {
            purchase_type: quote.purchase_type,
            package_id: quote.package_id,
            payment_method: 'balance'
        }).then(function (response) {
            renderPurchaseSuccess(vm, quote, response);
        }).catch(function (error) {
            var payload = error && error.payload ? error.payload : null;

            if (payload && payload.checkout) {
                renderBalanceConfirmation(vm, payload.checkout, 'warning', payload.message || error.message);
                return;
            }

            showCheckoutFailure('Balance checkout failed', error.message || 'Unable to complete the balance purchase right now.');
        });
    }

    function openBalanceCheckout(vm, purchaseType) {
        var selection = buildPremiumSelection(vm, purchaseType);
        showCheckoutLoading('Preparing balance checkout', 'Fetching your live balance and premium entitlement state.');

        requestJson('/api/billing/quote', {
            purchase_type: selection.purchase_type,
            package_id: selection.package_id,
            payment_method: 'balance'
        }).then(function (response) {
            renderBalanceConfirmation(vm, response.checkout || {}, '', '');
        }).catch(function (error) {
            showCheckoutFailure('Unable to prepare balance checkout', error.message || 'Unable to prepare the balance checkout right now.');
        });
    }

    function openCryptoCheckout(vm, purchaseType, paymentMethod) {
        var selection = buildPremiumSelection(vm, purchaseType);
        showCheckoutLoading('Generating crypto invoice', 'Creating a sandbox payment invoice in the same premium flow.');

        requestJson('/api/billing/crypto', {
            purchase_type: selection.purchase_type,
            package_id: selection.package_id,
            payment_method: paymentMethod
        }).then(function (response) {
            var quote = response.checkout || {};
            var invoice = response.invoice || {};
            var title = (quote.package_title || 'Premium') + ' - ' + (quote.purchase_type === 'account' ? 'Plan' : 'Package') + ' - ' + (quote.payment_label || paymentMethod) + ' payment';
            var subtitle = 'Sandbox crypto invoice generated in the native premium checkout flow.';
            var noticeMessage = response.message || 'This invoice is pending. No balance will change until a real crypto gateway is connected.';

            showCheckoutModal(
                title,
                subtitle,
                renderCryptoInvoiceBody(quote, invoice),
                'info',
                noticeMessage,
                [
                    {
                        label: 'Close',
                        primary: true,
                        onClick: closeCheckoutModal
                    }
                ]
            );
        }).catch(function (error) {
            showCheckoutFailure('Unable to generate crypto invoice', error.message || 'Unable to generate the crypto invoice right now.');
        });
    }

    function bindPremiumCheckout(vm) {
        if (!vm || vm.__vePremiumCheckoutBound) {
            return;
        }

        vm.__vePremiumCheckoutBound = true;
        applyPremiumChartFloor(vm);
        renderPremiumLayout(vm);
        var originalPayPlan = typeof vm.pay_plan === 'function' ? vm.pay_plan : null;
        var originalPayBw = typeof vm.pay_bw === 'function' ? vm.pay_bw : null;

        vm.pay_ajax_balance = function (payload) {
            openBalanceCheckout(vm, payload && payload.premium === 'bandwidth' ? 'bandwidth' : 'account');
        };

        vm.pay_ajax = function (payload) {
            openCryptoCheckout(
                vm,
                payload && payload.premium === 'bandwidth' ? 'bandwidth' : 'account',
                payload && (payload.coin || payload.submethod) ? (payload.coin || payload.submethod) : 'BTC'
            );
        };

        vm.pay_plan = function () {
            if (vm.payment_selected === 'paypal') {
                var plan = vm.premium_plans && vm.premium_plans[vm.premium_selected.index] ? vm.premium_plans[vm.premium_selected.index] : null;
                var planUrl = new URL(appUrl('/api/billing/paypal'), window.location.origin);

                if (plan && plan.price) {
                    planUrl.searchParams.set('amount', plan.price);
                }

                if (vm.data && vm.data.page && vm.data.page.rand) {
                    planUrl.searchParams.set('r', vm.data.page.rand);
                }

                window.open(planUrl.toString(), '_blank');
                return;
            }

            if (originalPayPlan) {
                return originalPayPlan.apply(vm, arguments);
            }
        };

        vm.pay_bw = function () {
            if (vm.payment_selected_bw === 'paypal') {
                var bandwidthPackage = vm.packages && vm.packages[vm.package_selected.index] ? vm.packages[vm.package_selected.index] : null;
                var bandwidthUrl = new URL(appUrl('/api/billing/paypal'), window.location.origin);

                if (bandwidthPackage && bandwidthPackage.price) {
                    bandwidthUrl.searchParams.set('amount', bandwidthPackage.price);
                }

                bandwidthUrl.searchParams.set('premium_bw', '1');

                if (vm.data && vm.data.page && vm.data.page.rand) {
                    bandwidthUrl.searchParams.set('r', vm.data.page.rand);
                }

                window.open(bandwidthUrl.toString(), '_blank');
                return;
            }

            if (originalPayBw) {
                return originalPayBw.apply(vm, arguments);
            }
        };
    }

    function tryBindPremiumCheckout() {
        var root = document.querySelector('.my-premium');

        if (!root || !root.__vue__) {
            return false;
        }

        bindPremiumCheckout(root.__vue__);
        return true;
    }

    function initPremiumCheckout() {
        ensureCheckoutModal();

        if (tryBindPremiumCheckout()) {
            return;
        }

        var attempts = 0;
        var timer = window.setInterval(function () {
            attempts += 1;

            if (tryBindPremiumCheckout() || attempts > 120) {
                window.clearInterval(timer);
            }
        }, 100);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPremiumCheckout);
    } else {
        initPremiumCheckout();
    }
})();
