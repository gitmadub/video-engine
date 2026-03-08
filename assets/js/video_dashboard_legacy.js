(function () {
    function buildLinks(vm, targetField, sourceField) {
        if (!vm || !vm.videos_export) {
            return;
        }

        vm.videos_export[targetField] = '';

        if (!Array.isArray(vm.videos_export.list)) {
            return;
        }

        vm.videos_export.list.forEach(function (item) {
            var url = item && item[sourceField] ? item[sourceField] : '';
            var name = item && item.file_title ? item.file_title : 'Untitled video';

            if (!url) {
                return;
            }

            if (typeof vm.direct_links === 'function') {
                vm.videos_export[targetField] += vm.direct_links({ dl: url, name: name });
                return;
            }

            vm.videos_export[targetField] += url + '\n';
        });
    }

    function generateSingleImageLinks() {
        buildLinks(this, 'single_img', 'single_img_url');
    }

    function generateSplashImageLinks() {
        buildLinks(this, 'splash_img', 'splash_img_url');
    }

    function patchMethods(target) {
        if (!target || target.__veLegacyImagesPatched) {
            return;
        }

        target.generate_single_img = generateSingleImageLinks;
        target.generate_splash_img = generateSplashImageLinks;
        target.__veLegacyImagesPatched = true;
    }

    function findVideoManagerInstance(root) {
        if (!root || !root.$children) {
            return null;
        }

        var queue = root.$children.slice();

        while (queue.length > 0) {
            var child = queue.shift();

            if (
                child &&
                child.$options &&
                (child.$options._componentTag === 'video-manager' || child.$options.name === 'video-manager')
            ) {
                return child;
            }

            if (child && child.$children && child.$children.length > 0) {
                Array.prototype.push.apply(queue, child.$children);
            }
        }

        return null;
    }

    function patchComponentRegistry() {
        if (!window.Vue || !Vue.options || !Vue.options.components) {
            return;
        }

        var component = Vue.options.components['video-manager'];
        var options = component && (component.options || component);

        if (!options || !options.methods) {
            return;
        }

        patchMethods(options.methods);
    }

    function patchMountedInstance() {
        var app = document.getElementById('app');
        var root = app && app.__vue__;
        var instance = findVideoManagerInstance(root);

        if (!instance) {
            return false;
        }

        patchMethods(instance);
        return true;
    }

    function patchAll() {
        patchComponentRegistry();
        patchMountedInstance();
    }

    document.addEventListener('DOMContentLoaded', function () {
        var attempts = 0;
        var timer = window.setInterval(function () {
            patchAll();
            attempts += 1;

            if (attempts >= 40) {
                window.clearInterval(timer);
            }
        }, 250);
    });
}());
