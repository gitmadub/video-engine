(function () {
    function hasVisibleModal() {
        return Array.prototype.some.call(document.querySelectorAll('.modal'), function (modal) {
            if (!modal) {
                return false;
            }

            var style = window.getComputedStyle(modal);
            return modal.classList.contains('show') || style.display !== 'none';
        });
    }

    function findRemoteUploadInstance(root) {
        var queue = root && root.$children ? root.$children.slice() : [];

        while (queue.length > 0) {
            var child = queue.shift();

            if (
                child &&
                child.$options &&
                (child.$options._componentTag === 'remote-upload' || child.$options.name === 'remote-upload')
            ) {
                return child;
            }

            if (child && child.$children && child.$children.length > 0) {
                Array.prototype.push.apply(queue, child.$children);
            }
        }

        return null;
    }

    function installLiveRefresh(instance) {
        if (!instance || instance.__veLiveRefreshTimer || typeof instance.update !== 'function') {
            return;
        }

        instance.__veLiveRefreshTimer = window.setInterval(function () {
            if (document.hidden || hasVisibleModal() || instance.__veRefreshPending) {
                return;
            }

            instance.__veRefreshPending = true;
            instance.update();
            window.setTimeout(function () {
                instance.__veRefreshPending = false;
            }, 1000);
        }, 2000);
    }

    function patchMountedInstance() {
        var app = document.getElementById('app');
        var root = app && app.__vue__;
        var instance = findRemoteUploadInstance(root);

        if (!instance) {
            return false;
        }

        installLiveRefresh(instance);
        return true;
    }

    function boot() {
        var attempts = 0;
        var timer = window.setInterval(function () {
            attempts += 1;

            if (patchMountedInstance() || attempts >= 60) {
                window.clearInterval(timer);
            }
        }, 250);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}());
