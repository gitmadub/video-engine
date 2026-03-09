(function () {
    var hosts = [
        'YouTube',
        'Google Drive',
        'Dropbox',
        'MEGA',
        'Vidi64 / WinVidPlay / Vidoy-style mirrors',
        'MyVidPlay',
        'Streamtape',
        'Mixdrop',
        'Waaw / Netu / HQQ',
        'OK.ru',
        'VideoBin',
        'Vidoza',
        'Vivo',
        'Xvideos',
        'YouPorn',
        'StreamSB',
        'Upstream',
        'Vidlox',
        'Fembed',
        '1fichier',
        'Uptobox',
        'Uptostream',
        'Uploaded',
        'Zippyshare',
        'Direct MP4 / M3U8 links'
    ];

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

    function patchSupportedHosts() {
        var modal = document.getElementById('supported-host');

        if (!modal) {
            return false;
        }

        var body = modal.querySelector('.modal-body');

        if (!body) {
            return false;
        }

        if (body.getAttribute('data-ve-hosts-patched') === '1') {
            return true;
        }

        body.textContent = '';
        body.setAttribute('data-ve-hosts-patched', '1');

        var note = document.createElement('p');
        note.className = 'small text-muted mb-3';
        note.textContent = 'Ordered by relevance. Mirror domains are also detected when they use a supported page structure.';
        body.appendChild(note);

        var list = document.createElement('ul');
        list.className = 'list-group';

        hosts.forEach(function (label) {
            list.appendChild(createItem(label));
        });

        body.appendChild(list);

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
