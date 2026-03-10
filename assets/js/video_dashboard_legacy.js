(function () {
    'use strict';

    var ENDPOINTS = {
        actions: '/videos/actions',
        uploadTarget: '/videos/upload-target',
        check: '/videos/check',
        result: '/videos/result',
        subtitles: '/videos/subtitles',
        thumbnail: '/videos/thumbnail',
        share: '/videos/share',
        markers: '/videos/markers'
    };

    function appUrl(path) {
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

        if (basePath && (path === basePath || path.indexOf(basePath + '/') === 0)) {
            return path;
        }

        return basePath + path;
    }

    function showToast(kind, message) {
        if (!message) {
            return;
        }

        if (window.iziToast && typeof window.iziToast[kind] === 'function') {
            window.iziToast[kind]({
                message: message,
                position: 'topRight',
                transitionIn: 'fadeInLeft',
                transitionOut: 'fadeOutLeft'
            });
            return;
        }

        if (window.console && typeof window.console.log === 'function') {
            window.console.log(message);
        }
    }

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

    function normalizeIdList(values) {
        var seen = Object.create(null);
        var output = [];

        (values || []).forEach(function (value) {
            var normalized = String(value || '').trim();

            if (!normalized || seen[normalized]) {
                return;
            }

            seen[normalized] = true;
            output.push(normalized);
        });

        return output;
    }

    function syncSelectionFromDom(vm) {
        var fileIds = [];
        var folderIds = [];
        var fileSet = Object.create(null);
        var folderSet = Object.create(null);

        document.querySelectorAll('.file_list .item').forEach(function (item) {
            var checkbox = item.querySelector('input.custom-control-input');
            var isSelected = item.classList.contains('active') || (checkbox && checkbox.checked);
            var videoId = item.getAttribute('data-video');
            var folderId = item.getAttribute('data-folder');

            if (!isSelected) {
                if (checkbox) {
                    checkbox.checked = false;
                }

                item.classList.remove('active');
                return;
            }

            item.classList.add('active');

            if (checkbox) {
                checkbox.checked = true;
            }

            if (videoId && !fileSet[videoId]) {
                fileSet[videoId] = true;
                fileIds.push(videoId);
            }

            if (folderId && !folderSet[folderId]) {
                folderSet[folderId] = true;
                folderIds.push(folderId);
            }
        });

        vm.file_ids = fileIds;
        vm.folder_ids = folderIds;

        var allToggle = document.getElementById('all');

        if (allToggle) {
            var selectable = document.querySelectorAll('.file_list .item');
            allToggle.checked = selectable.length > 0 && selectable.length === (fileIds.length + folderIds.length);
        }
    }

    function setItemSelected(item, selected) {
        if (!item) {
            return;
        }

        var checkbox = item.querySelector('input.custom-control-input');
        item.classList.toggle('active', !!selected);

        if (checkbox) {
            checkbox.checked = !!selected;
        }
    }

    function requestJson(method, url, data, onSuccess) {
        if (!window.jQuery) {
            return;
        }

        window.jQuery.ajax({
            type: method,
            url: appUrl(url),
            data: data,
            dataType: 'json',
            cache: false,
            success: onSuccess
        });
    }

    function requestHtml(url, data, onSuccess) {
        if (!window.jQuery) {
            return;
        }

        window.jQuery.ajax({
            type: 'get',
            url: appUrl(url),
            data: data,
            cache: false,
            success: onSuccess
        });
    }

    function openFolderShare(vm, folderId) {
        if (!folderId) {
            showToast('info', 'Open or select a folder to share it.');
            return;
        }

        requestHtml(ENDPOINTS.share, { folder_id: folderId }, function (html) {
            window.jQuery('#sharing').modal('show');
            window.jQuery('#sharing .modal-body').html(html);
        });
    }

    function folderPayloadById(vm, folderId) {
        var folders = (vm && vm.data && Array.isArray(vm.data.folders)) ? vm.data.folders : [];
        var normalizedId = String(folderId || '');

        for (var index = 0; index < folders.length; index += 1) {
            var folder = folders[index];

            if (folder && String(folder.fld_id || '') === normalizedId) {
                return folder;
            }
        }

        return null;
    }

    function decorateFolderRows(vm) {
        document.querySelectorAll('.file_list .folder.item').forEach(function (item) {
            var folderId = item.getAttribute('data-folder');
            var folder = folderPayloadById(vm, folderId);
            var size = folder && folder.siz ? folder.siz : '0 B';
            var created = folder && folder.cre ? folder.cre : '-';
            var existingSize = item.querySelector(':scope > .size');
            var existingDate = item.querySelector(':scope > .date');
            var existingViews = item.querySelector(':scope > .views');
            var existingPublic = item.querySelector(':scope > .public');

            if (!existingSize) {
                existingSize = document.createElement('div');
                existingSize.className = 'size d-none d-sm-block';
                item.appendChild(existingSize);
            }

            if (!existingDate) {
                existingDate = document.createElement('div');
                existingDate.className = 'date d-none d-sm-block';
                item.appendChild(existingDate);
            }

            if (!existingViews) {
                existingViews = document.createElement('div');
                existingViews.className = 'views d-none d-sm-block';
                item.appendChild(existingViews);
            }

            if (!existingPublic) {
                existingPublic = document.createElement('div');
                existingPublic.className = 'public d-none d-sm-block';
                item.appendChild(existingPublic);
            }

            existingSize.textContent = size;
            existingDate.textContent = created;
            existingViews.textContent = '-';
            existingPublic.innerHTML = '<a href="#sharing" data-folder-share="' + String(folderId || '') + '">Share</a>';
        });
    }

    function ensureToolbarLabels(vm) {
        if (vm && Array.isArray(vm.video_options)) {
            vm.video_options.forEach(function (option) {
                if (!option) {
                    return;
                }

                if (option.slug === 'get-links') {
                    option.name = 'Share';
                }
            });
        }

        if (vm && Array.isArray(vm.folder_options)) {
            vm.folder_options.forEach(function (option) {
                if (!option) {
                    return;
                }

                if (option.slug === 'sharing') {
                    option.name = 'Share';
                }
            });
        }

        var shareButton = Array.from(document.querySelectorAll('.title_wrap .btn-group .btn')).find(function (button) {
            return button.textContent && button.textContent.indexOf('Export') !== -1;
        });

        if (shareButton) {
            shareButton.innerHTML = shareButton.innerHTML.replace(/Export/g, 'Share');
        }

        document.querySelectorAll('.modal-title').forEach(function (title) {
            title.textContent = title.textContent
                .replace('Export links', 'Share links')
                .replace('Get links', 'Share')
                .replace('Folder sharing', 'Share folder');
        });
    }

    function ensureActionButtonTypes() {
        [
            '#add_folder .modal-footer .btn.btn-primary',
            '#rename .modal-footer .btn.btn-primary',
            '#subtitle .modal-footer .btn.btn-primary',
            '#move_files .modal-footer .btn.btn-primary',
            '#content_type .modal-footer .btn.btn-primary'
        ].forEach(function (selector) {
            document.querySelectorAll(selector).forEach(function (button) {
                button.setAttribute('type', 'button');
            });
        });
    }

    function installSelection(vm) {
        if (!window.Selection || vm.__veSelectionReady) {
            return;
        }

        vm.__veSelectionReady = true;
        vm.__veSelection = window.Selection.create({
            class: 'selection',
            selectables: ['.file_list > .item'],
            boundaries: ['.file_manager.d-flex.flex-wrap'],
            singleClick: true,
            disableTouch: true
        })
            .on('beforestart', function (event) {
                var source = event.oe && event.oe.target;

                if (!source) {
                    return true;
                }

                return !source.closest('a, button, input, label, textarea, .close, .nav-link, .mobile');
            })
            .on('start', function (event) {
                if (!event.oe.ctrlKey && !event.oe.metaKey) {
                    document.querySelectorAll('.file_list .item.active').forEach(function (item) {
                        setItemSelected(item, false);
                    });
                    syncSelectionFromDom(vm);
                }
            })
            .on('move', function (event) {
                event.changed.added.forEach(function (item) {
                    setItemSelected(item, true);
                });

                event.changed.removed.forEach(function (item) {
                    setItemSelected(item, false);
                });

                syncSelectionFromDom(vm);
            })
            .on('stop', function (event) {
                if (event.inst && typeof event.inst.keepSelection === 'function') {
                    event.inst.keepSelection();
                }

                syncSelectionFromDom(vm);
            });
    }

    function installDropZone(vm) {
        if (!vm || vm.__veDropZoneReady) {
            return;
        }

        var root = document.querySelector('.file_manager.d-flex.flex-wrap');

        if (!root) {
            return;
        }

        function clearHighlight() {
            root.classList.remove('ve-drag-over');
        }

        function addFiles(files) {
            var list = Array.prototype.filter.call(files || [], function (file) {
                return !!(
                    file && (
                        /^video\//i.test(file.type || '')
                        || /\.(mp4|m4v|mov|mkv|webm|avi|wmv|flv|mpeg|mpg|ts|m2ts|mts|3gp)$/i.test(file.name || '')
                    )
                );
            });

            if (!list.length) {
                return;
            }

            if (vm.$refs && vm.$refs.upload && typeof vm.$refs.upload.add === 'function') {
                list.forEach(function (file) {
                    vm.$refs.upload.add(file);
                });

                if (vm.uploadAuto !== false && Object.prototype.hasOwnProperty.call(vm.$refs.upload, 'active')) {
                    vm.$refs.upload.active = true;
                }
            }
        }

        root.addEventListener('dragenter', function (event) {
            event.preventDefault();
            root.classList.add('ve-drag-over');
        });

        root.addEventListener('dragover', function (event) {
            event.preventDefault();
            root.classList.add('ve-drag-over');
        });

        root.addEventListener('dragleave', function (event) {
            if (event.target === root || !root.contains(event.relatedTarget)) {
                clearHighlight();
            }
        });

        root.addEventListener('drop', function (event) {
            event.preventDefault();
            clearHighlight();
            addFiles(event.dataTransfer && event.dataTransfer.files);
        });

        vm.__veDropZoneReady = true;
    }

    function installSelectionSync(vm) {
        if (!vm || vm.__veSelectionSyncReady) {
            return;
        }

        document.addEventListener('change', function (event) {
            if (!event.target || !event.target.closest('.file_list')) {
                return;
            }

            if (event.target.id === 'all') {
                var checked = !!event.target.checked;

                document.querySelectorAll('.file_list .item').forEach(function (item) {
                    setItemSelected(item, checked);
                });
            } else {
                var item = event.target.closest('.item');
                setItemSelected(item, !!event.target.checked);
            }

            syncSelectionFromDom(vm);
        });

        document.addEventListener('click', function (event) {
            var shareLink = event.target && event.target.closest('[data-folder-share]');

            if (!shareLink) {
                return;
            }

            event.preventDefault();
            openFolderShare(vm, shareLink.getAttribute('data-folder-share'));
        });

        vm.__veSelectionSyncReady = true;
    }

    function afterRender(vm) {
        window.setTimeout(function () {
            ensureToolbarLabels(vm);
            ensureActionButtonTypes();
            decorateFolderRows(vm);
            installSelection(vm);
            installDropZone(vm);
            installSelectionSync(vm);
            syncSelectionFromDom(vm);
        }, 0);
    }

    function wrapMethods(target) {
        if (!target || target.__veLegacyDashboardPatched) {
            return;
        }

        target.generate_single_img = generateSingleImageLinks;
        target.generate_splash_img = generateSplashImageLinks;

        target.select_all = function () {
            var allToggle = document.getElementById('all');
            var shouldSelect = !!(allToggle && allToggle.checked);

            document.querySelectorAll('.file_list .item').forEach(function (item) {
                setItemSelected(item, shouldSelect);
            });

            syncSelectionFromDom(this);
        };

        target.startDragSelect = function (event) {
            if (event && event.button === 2) {
                return;
            }

            installSelection(this);
        };

        target.update = function () {
            var vm = this;
            var payload = {
                page: vm.page,
                sort: vm.sort,
                fld_id: vm.current_folder,
                key: vm.$root.search_filter || vm.search || '',
                sort_field: vm.sort_field,
                sort_order: vm.sort_order
            };

            vm.loading = false;
            vm.loaded = false;

            requestJson('get', ENDPOINTS.actions, payload, function (response) {
                vm.data = response;
                vm.loaded = true;
                vm.loading = false;
                vm.total_videos = response.total_videos;
                vm.token = response.token;
                vm.folder_ids = [];
                vm.file_ids = [];
                vm.folders = [];

                if (Array.isArray(response.folders)) {
                    response.folders.forEach(function (folder) {
                        vm.folders.push({ id: folder.fld_id, title: folder.fld_name });
                    });
                }

                afterRender(vm);
            });
        };

        target.ajax = function (payload, refresh) {
            var vm = this;
            var shouldRefresh = refresh !== false;

            syncSelectionFromDom(vm);

            requestJson('post', ENDPOINTS.actions, payload, function (response) {
                if (response.status === 'fail') {
                    showToast('error', response.message);
                    return;
                }

                if (response.status === 'ok') {
                    showToast('success', response.message);
                }

                if (payload.del_selected_fld) {
                    vm.folder_ids = [];
                }

                if (payload.del_selected) {
                    vm.file_ids = [];
                }

                if (shouldRefresh) {
                    vm.update();
                    return;
                }

                afterRender(vm);
            });
        };

        target.move = function () {
            syncSelectionFromDom(this);

            if ((Array.isArray(this.file_ids) && this.file_ids.length) || (Array.isArray(this.folder_ids) && this.folder_ids.length)) {
                this.__vePendingMove = {
                    fileIds: normalizeIdList(this.file_ids),
                    folderIds: normalizeIdList(this.folder_ids)
                };
                window.jQuery('#move_files').modal('show');
                this.get_folders();
                return;
            }

            showToast('info', 'Please select folders or files first');
        };

        target.move_to_folder = function () {
            var vm = this;
            var fileIds;
            var folderIds;
            var targetFolderId;

            syncSelectionFromDom(vm);
            fileIds = normalizeIdList(vm.file_ids);
            folderIds = normalizeIdList(vm.folder_ids);
            targetFolderId = String(vm.$root.selected_folder || '0');

            if (!fileIds.length && !folderIds.length && vm.__vePendingMove) {
                fileIds = normalizeIdList(vm.__vePendingMove.fileIds);
                folderIds = normalizeIdList(vm.__vePendingMove.folderIds);
            }

            if (!fileIds.length && !folderIds.length) {
                vm.__vePendingMove = null;
                showToast('info', 'Please select folders or files first');
                return;
            }

            if (fileIds.length) {
                vm.ajax({
                    file_move: 1,
                    to_folder: targetFolderId,
                    file_id: fileIds,
                    token: vm.token
                }, false);
            }

            if (folderIds.length) {
                vm.ajax({
                    folder_move: 1,
                    to_folder_fld: targetFolderId,
                    fld_id1: folderIds,
                    fld_id: vm.current_folder,
                    token: vm.token
                }, false);
            }

            window.jQuery('#move_files').modal('hide');
            vm.move_files.folders = null;
            vm.__vePendingMove = null;
            window.setTimeout(function () {
                vm.update();
            }, 50);
        };

        target.export_files = function () {
            var vm = this;
            var selectedFolderId = null;

            syncSelectionFromDom(vm);
            vm.videos_export.list = null;

            if (Array.isArray(vm.file_ids) && vm.file_ids.length) {
                requestJson('post', ENDPOINTS.actions, {
                    file_id: normalizeIdList(vm.file_ids),
                    file_export: '1'
                }, function (response) {
                    vm.videos_export.list = response;
                    vm.generate_direct_links();
                    vm.generate_embed_link();
                    vm.generate_iframe_code();
                    vm.generate_splash_img();
                    vm.generate_single_img();
                    window.jQuery('#export_videos').modal('show');
                });
                return;
            }

            if (Array.isArray(vm.folder_ids) && vm.folder_ids.length === 1) {
                selectedFolderId = vm.folder_ids[0];
            } else if (String(vm.current_folder || '0') !== '0') {
                selectedFolderId = vm.current_folder;
            }

            if (selectedFolderId) {
                openFolderShare(vm, selectedFolderId);
                return;
            }

            showToast('info', 'Select files to share them, or open a folder to share that folder link.');
        };

        target.export_files2 = function () {
            var vm = this;

            requestJson('post', ENDPOINTS.actions, {
                file_id: vm.file_ids2,
                file_export: '1'
            }, function (response) {
                vm.videos_export.list = response;
                vm.generate_direct_links();
                vm.generate_embed_link();
                vm.generate_iframe_code();
                vm.generate_splash_img();
                vm.generate_single_img();
                window.jQuery('#export_videos2').modal('show');
            });
        };

        target.video_clicked = function (payload) {
            var vm = this;
            var option = payload.option.slug;
            var video = vm.data.videos[payload.item];
            var fileId = video.id;
            var fileCode = video.fid;
            var fileName = video.fn;
            var fileUrl = video.dl;

            if (option === 'delete') {
                vm.confirm('delete_file', video, 'Are you sure delete this video?');
            }

            if (option === 'view') {
                window.open(fileUrl, '_blank');
            }

            if (option === 'rename') {
                vm.rename.name = fileName;
                vm.rename.id = fileId;
                vm.rename.index = payload.item;
                window.jQuery('#rename').modal('show');
            }

            if (option === 'add-subtitle') {
                vm.subtitle.name = fileName;
                vm.subtitle.id = fileId;
                vm.subtitle.index = payload.item;
                vm.subtitle.code = fileCode;
                vm.rename.is_folder = false;
                vm.get_subtitles();
                window.jQuery('#subtitle').modal('show');
                vm.subscene_search_term = fileName;
            }

            if (option === 'change-thumbnail') {
                requestHtml(ENDPOINTS.thumbnail, { file_id: fileId }, function (html) {
                    window.jQuery('#change_thumbnail').modal('show');
                    window.jQuery('#change_thumbnail .modal-body').html(html);
                });
            }

            if (option === 'add-marker') {
                requestHtml(ENDPOINTS.markers, { file_id: fileId }, function (html) {
                    window.jQuery('#view').modal('show');
                    window.jQuery('#view .modal-body').html(html);
                });
            }

            if (option === 'copy-link' && typeof vm.$copyText === 'function') {
                vm.$copyText(fileUrl).then(function () {
                    showToast('success', 'Link copied');
                }, function () {
                    showToast('info', 'Oops, something went wrong');
                });
            }

            if (option === 'get-links') {
                vm.file_ids2 = fileId;
                vm.export_files2();
            }
        };

        target.folder_clicked = function (payload) {
            var vm = this;
            var option = payload.option.slug;
            var folder = vm.data.folders[payload.item];

            if (option === 'delete') {
                vm.confirm('delete_folder', folder, 'Delete this folder with all files inside?');
            }

            if (option === 'rename') {
                vm.rename.name = folder.fld_name;
                vm.rename.id = folder.fld_id;
                vm.rename.index = payload.item;
                vm.rename.is_folder = true;
                window.jQuery('#rename').modal('show');
            }

            if (option === 'sharing') {
                openFolderShare(vm, folder.fld_id);
            }
        };

        target.add_folder = function () {
            var vm = this;

            requestJson('post', ENDPOINTS.actions, {
                fld_id: vm.current_folder,
                create_new_folder: vm.new_folder,
                token: vm.token
            }, function (response) {
                if (response.status === 'fail') {
                    window.jQuery('#add_folder').modal('hide');
                    showToast('error', response.message);
                    return;
                }

                window.jQuery('#add_folder').modal('hide');
                vm.new_folder = '';
                vm.update();
            });
        };

        target.rename_file = function () {
            var vm = this;
            var payload = {
                rename: vm.rename.name,
                token: vm.token
            };

            if (vm.rename.is_folder) {
                payload.fld_id = vm.rename.id;
            } else {
                payload.file_id = vm.rename.id;
            }

            requestJson('post', ENDPOINTS.actions, payload, function () {
                window.jQuery('#rename').modal('hide');
                vm.update();
            });
        };

        target.delete_selected = function () {
            var vm = this;

            syncSelectionFromDom(vm);

            if (vm.file_ids.length) {
                vm.ajax({
                    fld_id: vm.current_folder,
                    file_id: normalizeIdList(vm.file_ids),
                    del_selected: 'Delete selected',
                    token: vm.token
                }, false);
            }

            if (vm.folder_ids.length) {
                vm.ajax({
                    fld_id: vm.current_folder,
                    del_selected_fld: 1,
                    fld_id1: normalizeIdList(vm.folder_ids),
                    token: vm.token
                }, false);
            }

            window.setTimeout(function () {
                vm.update();
            }, 50);
        };

        target.set_public = function () {
            syncSelectionFromDom(this);
            this.ajax({
                set_public: 1,
                file_id: normalizeIdList(this.file_ids),
                token: this.token
            });
        };

        target.set_private = function () {
            syncSelectionFromDom(this);
            this.ajax({
                set_private: 1,
                file_id: normalizeIdList(this.file_ids),
                token: this.token
            });
        };

        target.open_folder = function (folderId) {
            this.current_folder = folderId;
            this.page = 1;
            this.update();
        };

        target.__veLegacyDashboardPatched = true;
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

        wrapMethods(options.methods);
    }

    function patchMountedInstance() {
        var app = document.getElementById('app');
        var root = app && app.__vue__;
        var instance = findVideoManagerInstance(root);

        if (!instance) {
            return false;
        }

        wrapMethods(instance);
        afterRender(instance);
        return true;
    }

    function patchAll() {
        patchComponentRegistry();
        patchMountedInstance();
    }

    function bootPatcher() {
        var attempts = 0;
        var timer = window.setInterval(function () {
            patchAll();
            attempts += 1;

            if (attempts >= 60) {
                window.clearInterval(timer);
            }
        }, 250);
    }

    patchAll();
    bootPatcher();

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            patchAll();
        });
    }
}());
