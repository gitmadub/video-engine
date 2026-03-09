(function () {
    var fallbackHosts = [
        'YouTube',
        'Google Drive',
        'Dropbox',
        'MEGA',
        'Vidi64 / WinVidPlay / Vidoy',
        'Direct MP4 / M3U8 links'
    ];
    var noteText = 'Mirror sites and domains are also supported.';
    var emptyText = 'No remote upload hosts are currently enabled.';
    var script = document.currentScript || document.querySelector('script[src*="remote_upload_supported_hosts.js"]');
    var endpoint = script ? (script.getAttribute('data-hosts-endpoint') || '') : '';
    var requestedRemoteHosts = false;

    function createBadge() {
        var icon = document.createElement('i');
        icon.className = 'fad fa-badge-check text-success';

        var wrapper = document.createElement('span');
        wrapper.className = 'float-right';
        wrapper.appendChild(icon);

        return wrapper;
    }

    function createItem(label) {
        var item = document.createElement('li');
        item.className = 'list-group-item';
        item.appendChild(document.createTextNode(label));
        item.appendChild(createBadge());

        return item;
    }

    function renderSupportedHosts(hosts) {
        var modal = document.getElementById('supported-host');

        if (!modal) {
            return false;
        }

        var body = modal.querySelector('.modal-body');

        if (!body) {
            return false;
        }

        body.textContent = '';
        body.setAttribute('data-ve-hosts-patched', '1');

        var note = document.createElement('p');
        note.className = 'small text-muted mb-3';
        note.textContent = noteText;
        body.appendChild(note);

        if (!hosts.length) {
            var empty = document.createElement('p');
            empty.className = 'small mb-0';
            empty.textContent = emptyText;
            body.appendChild(empty);
            return true;
        }

        var list = document.createElement('ul');
        list.className = 'list-group';

        hosts.forEach(function (label) {
            list.appendChild(createItem(label));
        });

        body.appendChild(list);

        return true;
    }

    function extractRemoteHosts(response) {
        if (!response || response.status !== 'ok' || !response.remote_upload || !Array.isArray(response.remote_upload.supported_hosts)) {
            return null;
        }

        return response.remote_upload.supported_hosts
            .map(function (item) {
                return item && typeof item.label === 'string' ? item.label.trim() : '';
            })
            .filter(function (label) {
                return label !== '';
            });
    }

    function requestRemoteHosts() {
        if (!endpoint || requestedRemoteHosts) {
            return;
        }

        requestedRemoteHosts = true;

        window.fetch(endpoint, {
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).then(function (response) {
            return response.ok ? response.json() : null;
        }).then(function (payload) {
            var hosts = extractRemoteHosts(payload);

            if (hosts !== null) {
                renderSupportedHosts(hosts);
            }
        }).catch(function () {
        });
    }

    function patchSupportedHosts() {
        if (!renderSupportedHosts(fallbackHosts)) {
            return false;
        }

        requestRemoteHosts();
        return true;
    }

    if (patchSupportedHosts()) {
        return;
    }

    var observer = new MutationObserver(function () {
        if (patchSupportedHosts()) {
            observer.disconnect();
        }
    });

    observer.observe(document.documentElement, {
        childList: true,
        subtree: true
    });

    window.addEventListener('load', function () {
        if (patchSupportedHosts()) {
            observer.disconnect();
        }
    }, { once: true });
}());
