(function () {
    var mountAttempts = 0;
    var maxMountAttempts = 80;
    var pollDelayMs = 50;

    function isVueReady() {
        return !!(
            window.Vue &&
            window.Vue.options &&
            window.Vue.options.components &&
            window.Vue.options.components['video-manager']
        );
    }

    function mountDashboard() {
        var app = document.getElementById('app');

        if (!app) {
            return true;
        }

        if (app.__vue__) {
            return true;
        }

        if (!isVueReady()) {
            return false;
        }

        window.veVideoDashboardApp = new window.Vue({
            el: '#app',
            data: function () {
                return {
                    is_page: 'videos',
                    selected_folder: '0'
                };
            }
        });

        return true;
    }

    function boot() {
        var timer = window.setInterval(function () {
            mountAttempts += 1;

            if (mountDashboard() || mountAttempts >= maxMountAttempts) {
                window.clearInterval(timer);
            }
        }, pollDelayMs);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}());
