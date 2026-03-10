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
            initFolderShareModal();
        });
    }

    function insertAfter(parent, child, reference) {
        if (!parent || !child || !reference) {
            return;
        }

        if (reference.nextSibling === child) {
            return;
        }

        parent.insertBefore(child, reference.nextSibling);
    }

    function updateFolderShareOutput(root) {
        if (!root) {
            return;
        }

        var output = root.querySelector('[data-share-folder-output]');
        var toggle = root.querySelector('[data-share-folder-toggle]');
        var link = root.getAttribute('data-share-folder-link') || '';
        var title = root.getAttribute('data-share-folder-title') || 'Folder';

        if (!output) {
            return;
        }

        output.value = toggle && toggle.checked ? title + '\n' + link : link;
    }

    function initFolderShareModal() {
        var root = document.querySelector('#sharing [data-share-folder-root]');

        if (!root) {
            return;
        }

        var toggle = root.querySelector('[data-share-folder-toggle]');

        updateFolderShareOutput(root);

        if (!toggle || toggle.__veBound) {
            return;
        }

        toggle.__veBound = true;
        toggle.addEventListener('change', function () {
            updateFolderShareOutput(root);
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
            var nameColumn = item.querySelector('.name');
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

            if (existingPublic && existingPublic.parentNode === item) {
                item.removeChild(existingPublic);
            }

            existingSize.textContent = size;
            existingDate.textContent = created;
            existingViews.textContent = '-';

            if (nameColumn) {
                insertAfter(item, existingSize, nameColumn);
                insertAfter(item, existingDate, existingSize);
                insertAfter(item, existingViews, existingDate);
            }
        });
    }

    function decorateDraggableItems() {
        document.querySelectorAll('.file_list .item').forEach(function (item) {
            if (item.classList.contains('header')) {
                item.removeAttribute('draggable');
                return;
            }

            if (item.hasAttribute('data-video') || item.hasAttribute('data-folder')) {
                item.setAttribute('draggable', 'true');
                return;
            }

            item.removeAttribute('draggable');
        });
    }

    function dataTransferLooksLikeFiles(dataTransfer) {
        if (!dataTransfer) {
            return false;
        }

        if (dataTransfer.files && dataTransfer.files.length > 0) {
            return true;
        }

        if (!dataTransfer.types) {
            return false;
        }

        return Array.prototype.indexOf.call(dataTransfer.types, 'Files') !== -1;
    }

    function isAcceptedVideoFile(file) {
        return !!(
            file && (
                /^video\//i.test(file.type || '')
                || /\.(mp4|m4v|mov|mkv|webm|avi|wmv|flv|mpeg|mpg|ts|m2ts|mts|3gp)$/i.test(file.name || '')
            )
        );
    }

    function normalizeUploadFiles(items) {
        if (!items) {
            return [];
        }

        if (Array.isArray(items)) {
            return items.filter(Boolean);
        }

        return [items].filter(Boolean);
    }

    function applyUploadFolder(upload, files, folderId) {
        if (!upload || typeof upload.update !== 'function') {
            return;
        }

        normalizeUploadFiles(files).forEach(function (file) {
            var currentData = file && file.data && typeof file.data === 'object' ? file.data : {};
            upload.update(file, {
                data: Object.assign({}, currentData, {
                    fld_id: String(folderId || '0')
                })
            });
        });
    }

    function queueDroppedFiles(vm, dataTransfer, folderId) {
        var upload = vm && vm.$refs ? vm.$refs.upload : null;
        var beforeFiles;
        var queued;
        var validFiles;

        if (!upload || typeof upload.add !== 'function') {
            return false;
        }

        validFiles = Array.prototype.filter.call((dataTransfer && dataTransfer.files) || [], isAcceptedVideoFile);

        if (!validFiles.length) {
            showToast('info', 'Drop one or more supported video files.');
            return false;
        }

        beforeFiles = Array.isArray(upload.files) ? upload.files.slice() : [];

        function finalizeQueuedFiles(result) {
            var queuedFiles = Array.isArray(upload.files) ? upload.files.filter(function (file) {
                return beforeFiles.indexOf(file) === -1;
            }) : [];

            if (!queuedFiles.length) {
                queuedFiles = normalizeUploadFiles(result);
            }

            applyUploadFolder(upload, queuedFiles, folderId);

            if (vm.uploadAuto !== false && Object.prototype.hasOwnProperty.call(upload, 'active')) {
                upload.active = true;
            }
        }

        queued = validFiles.map(function (file) {
            return upload.add(file);
        });

        if (queued && typeof queued.then === 'function') {
            queued.then(function (result) {
                window.setTimeout(function () {
                    finalizeQueuedFiles(result);
                }, 50);
            });
        } else {
            window.setTimeout(function () {
                finalizeQueuedFiles(queued);
            }, 50);
        }

        return true;
    }

    function selectionFromItem(vm, item) {
        syncSelectionFromDom(vm);

        if (!item.classList.contains('active')) {
            document.querySelectorAll('.file_list .item.active').forEach(function (node) {
                setItemSelected(node, false);
            });
            setItemSelected(item, true);
            syncSelectionFromDom(vm);
        }

        return {
            fileIds: normalizeIdList(vm.file_ids),
            folderIds: normalizeIdList(vm.folder_ids)
        };
    }

    function moveItemsToFolder(vm, selection, targetFolderId, onComplete) {
        var fileIds = normalizeIdList(selection && selection.fileIds);
        var folderIds = normalizeIdList(selection && selection.folderIds);
        var pending = 0;
        var failed = false;
        var successMessages = [];
        var normalizedTargetFolderId = String(targetFolderId || '0');

        function finalize() {
            if (!failed && successMessages.length) {
                showToast('success', successMessages.join(' '));
            }

            if (typeof onComplete === 'function') {
                onComplete(failed);
            }
        }

        function handleResponse(response) {
            pending -= 1;

            if (response && response.status === 'fail') {
                failed = true;
                showToast('error', response.message || 'The move could not be completed.');
            } else if (response && response.status === 'ok' && response.message) {
                successMessages.push(response.message);
            }

            if (pending <= 0) {
                finalize();
            }
        }

        if (!fileIds.length && !folderIds.length) {
            if (typeof onComplete === 'function') {
                onComplete(false);
            }
            return;
        }

        if (fileIds.length) {
            pending += 1;
            requestJson('post', ENDPOINTS.actions, {
                file_move: 1,
                to_folder: normalizedTargetFolderId,
                file_id: fileIds,
                token: vm.token
            }, handleResponse);
        }

        if (folderIds.length) {
            pending += 1;
            requestJson('post', ENDPOINTS.actions, {
                folder_move: 1,
                to_folder_fld: normalizedTargetFolderId,
                fld_id1: folderIds,
                fld_id: vm.current_folder,
                token: vm.token
            }, handleResponse);
        }
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
            root.querySelectorAll('.ve-drag-folder-target').forEach(function (node) {
                node.classList.remove('ve-drag-folder-target');
            });
        }

        function highlightTarget(target) {
            clearHighlight();
            root.classList.add('ve-drag-over');

            if (target) {
                target.classList.add('ve-drag-folder-target');
            }
        }

        function folderDropTargetFromEvent(event) {
            var target = event.target && event.target.closest('.file_list .folder.item[data-folder]');

            return target && root.contains(target) ? target : null;
        }

        root.addEventListener('dragenter', function (event) {
            if (!dataTransferLooksLikeFiles(event.dataTransfer) && !vm.__veInternalDrag) {
                return;
            }

            event.preventDefault();
            highlightTarget(folderDropTargetFromEvent(event));
        });

        root.addEventListener('dragover', function (event) {
            if (!dataTransferLooksLikeFiles(event.dataTransfer) && !vm.__veInternalDrag) {
                return;
            }

            event.preventDefault();
            if (event.dataTransfer) {
                event.dataTransfer.dropEffect = 'move';
            }
            highlightTarget(folderDropTargetFromEvent(event));
        });

        root.addEventListener('dragleave', function (event) {
            if (event.target === root || !root.contains(event.relatedTarget)) {
                clearHighlight();
            }
        });

        root.addEventListener('dragstart', function (event) {
            var item = event.target && event.target.closest('.file_list .item');
            var selection;

            if (!item || item.classList.contains('header') || (!item.hasAttribute('data-video') && !item.hasAttribute('data-folder'))) {
                return;
            }

            selection = selectionFromItem(vm, item);

            vm.__veInternalDrag = {
                fileIds: selection.fileIds,
                folderIds: selection.folderIds
            };

            if (event.dataTransfer) {
                event.dataTransfer.effectAllowed = 'move';

                try {
                    event.dataTransfer.setData('text/plain', JSON.stringify(vm.__veInternalDrag));
                } catch (error) {
                    // Ignore browsers that block custom drag data here.
                }
            }
        });

        root.addEventListener('dragend', function () {
            vm.__veInternalDrag = null;
            clearHighlight();
        });

        root.addEventListener('drop', function (event) {
            var folderTarget = folderDropTargetFromEvent(event);
            var targetFolderId = folderTarget ? folderTarget.getAttribute('data-folder') : String(vm.current_folder || '0');

            event.preventDefault();
            clearHighlight();

            if (vm.__veInternalDrag) {
                moveItemsToFolder(vm, vm.__veInternalDrag, targetFolderId, function () {
                    vm.__veInternalDrag = null;
                    vm.__vePendingMove = null;
                    vm.file_ids = [];
                    vm.folder_ids = [];
                    vm.update();
                });
                return;
            }

            queueDroppedFiles(vm, event.dataTransfer, targetFolderId);
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
            decorateDraggableItems();
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

            window.jQuery('#move_files').modal('hide');
            vm.move_files.folders = null;
            moveItemsToFolder(vm, {
                fileIds: fileIds,
                folderIds: folderIds
            }, targetFolderId, function () {
                vm.__vePendingMove = null;
                vm.file_ids = [];
                vm.folder_ids = [];
                vm.update();
            });
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
