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

    function legacyDateLabel(date) {
        var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        var value = date instanceof Date ? date : new Date(date);
        var day;

        if (!(value instanceof Date) || isNaN(value.getTime())) {
            value = new Date();
        }

        day = value.getDate();

        return months[value.getMonth()] + ' ' + (day < 10 ? '0' + day : String(day)) + ', ' + value.getFullYear();
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

    function appRootElement() {
        return document.getElementById('app');
    }

    function currentUploaderType() {
        var root = appRootElement();
        var value = root ? String(root.getAttribute('data-uploader-type') || '0') : '0';

        return /^(1|2|3)$/.test(value) ? value : '0';
    }

    function setCurrentUploaderType(value) {
        var root = appRootElement();
        var normalized = /^(1|2|3)$/.test(String(value || '')) ? String(value) : '0';

        if (root) {
            root.setAttribute('data-uploader-type', normalized);
        }
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function hydrateSavedContentType(vm) {
        if (!vm) {
            return;
        }

        var uploaderType = currentUploaderType();

        if (uploaderType !== '0') {
            vm.content_type = uploaderType;
            return;
        }

        if (vm.data && /^(1|2|3)$/.test(String(vm.data.uploader_type || ''))) {
            vm.content_type = String(vm.data.uploader_type);
            setCurrentUploaderType(vm.content_type);
        }
    }

    function persistPerPage(value) {
        var normalized = String(value || '25');
        var cookiePath = window.VE_BASE_PATH || '/';

        if (!cookiePath || cookiePath === '') {
            cookiePath = '/';
        } else if (cookiePath.charAt(cookiePath.length - 1) !== '/') {
            cookiePath += '/';
        }

        document.cookie = 'per_page=' + encodeURIComponent(normalized)
            + '; path=' + cookiePath
            + '; max-age=31536000; SameSite=Lax';
    }

    function folderPathItems(vm) {
        var items = [{
            id: '0',
            name: '/Videos'
        }];

        if (!vm || !vm.data || !Array.isArray(vm.data.folder_path)) {
            return items;
        }

        vm.data.folder_path.forEach(function (folder) {
            var folderId = folder && (folder.id || folder.fld_id);
            var folderName = folder && (folder.name || folder.fld_name);

            if (!folderId || !folderName) {
                return;
            }

            items.push({
                id: String(folderId),
                name: String(folderName)
            });
        });

        return items;
    }

    function isFormControlTarget(node) {
        return !!(node && node.closest('a, button, input, label, textarea, select, option, .close, .nav-link, .mobile, [role="button"]'));
    }

    function ensureBrowserToolbarStyles() {
        var existing = document.getElementById('ve-browser-toolbar-style');
        var style;

        if (existing) {
            return;
        }

        style = document.createElement('style');
        style.id = 've-browser-toolbar-style';
        style.textContent = [
            '.ve-browser-toolbar{margin:0 0 12px;padding:12px 15px;background:#1c1c1c;border:1px solid rgba(67,70,69,.7);border-radius:3px}',
            '.ve-browser-toolbar-main{display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap;min-width:0}',
            '.ve-folder-path-label{padding-top:9px;color:#434645;font-size:.75rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase}',
            '.ve-folder-path{display:flex;align-items:center;gap:8px;flex-wrap:wrap;min-width:0}',
            '.ve-folder-path-separator{color:#434645;font-size:.75rem;font-weight:700}',
            '.ve-folder-path-card{display:inline-flex;align-items:center;min-height:38px;padding:8px 12px;border:1px solid #434645;border-radius:3px;background:rgba(67,70,69,.18);color:rgba(255,255,255,.82);font-size:.9rem;font-weight:600;line-height:1;transition:border-color .16s ease,background .16s ease,color .16s ease,transform .16s ease}',
            '.ve-folder-path-card:hover,.ve-folder-path-card:focus-visible{text-decoration:none;outline:none;color:#fff;border-color:#f90;background:rgba(255,153,0,.12)}',
            '.ve-folder-path-card.is-current{color:#f90;border-color:rgba(255,153,0,.58);background:rgba(255,153,0,.08)}',
            '.ve-folder-path-card.ve-drag-folder-target,.ve-folder-path-card.ve-drop-commit{color:#fff;border-color:#f90;background:rgba(255,153,0,.18);transform:translateY(-1px)}',
            '.file_manager .files ul.file_list li.item{transition:background .14s ease,box-shadow .14s ease,transform .14s ease}',
            '.file_list .video.item,.file_list .folder.item{cursor:grab}',
            '.file_list .item.active,.file_list .item:active{cursor:grabbing}',
            '.file_list .item.ve-item-pending-move{opacity:.52;transform:translateX(14px) scale(.992);pointer-events:none;transition:opacity .16s ease,transform .16s ease}',
            '@media (min-width:769px){.file_manager .files ul.file_list li.header,.file_manager .files ul.file_list li.video.item,.file_manager .files ul.file_list li.folder.item{display:flex!important;align-items:center;flex-wrap:nowrap!important;width:100%}.file_manager .files ul.file_list li.header .custom-checkbox,.file_manager .files ul.file_list li.video.item .custom-checkbox,.file_manager .files ul.file_list li.folder.item .custom-checkbox{flex:0 0 34px;max-width:34px;width:34px;padding-right:12px}.file_manager .files ul.file_list li.header .name,.file_manager .files ul.file_list li.video.item .name,.file_manager .files ul.file_list li.folder.item .name{flex:1 1 auto;min-width:0;max-width:none;padding-right:18px}.file_manager .files ul.file_list li.header .size,.file_manager .files ul.file_list li.video.item .size,.file_manager .files ul.file_list li.folder.item .size{flex:0 0 110px;max-width:110px}.file_manager .files ul.file_list li.header .date,.file_manager .files ul.file_list li.video.item .date,.file_manager .files ul.file_list li.folder.item .date{flex:0 0 130px;max-width:130px}.file_manager .files ul.file_list li.header .views,.file_manager .files ul.file_list li.video.item .views,.file_manager .files ul.file_list li.folder.item .views{flex:0 0 74px;max-width:74px}.file_manager .files ul.file_list li.header .public,.file_manager .files ul.file_list li.video.item .public,.file_manager .files ul.file_list li.folder.item .public{flex:0 0 86px;max-width:86px}.file_manager .files ul.file_list li.header .size,.file_manager .files ul.file_list li.header .date,.file_manager .files ul.file_list li.header .views,.file_manager .files ul.file_list li.header .public,.file_manager .files ul.file_list li.video.item .size,.file_manager .files ul.file_list li.video.item .date,.file_manager .files ul.file_list li.video.item .views,.file_manager .files ul.file_list li.video.item .public,.file_manager .files ul.file_list li.folder.item .size,.file_manager .files ul.file_list li.folder.item .date,.file_manager .files ul.file_list li.folder.item .views,.file_manager .files ul.file_list li.folder.item .public{display:flex!important;align-items:center;justify-content:flex-end;text-align:right;padding-left:12px;white-space:nowrap}.file_manager .files ul.file_list li.header .mobile,.file_manager .files ul.file_list li.video.item .mobile,.file_manager .files ul.file_list li.folder.item .mobile{margin-left:12px}}',
            '.file_list .folder.item.ve-drag-folder-target,.file_list .folder.item.ve-drop-commit{box-shadow:0 0 0 1px rgba(255,153,0,.65) inset;background:rgba(255,153,0,.10);transform:translateY(-1px)}',
            '.file_list .folder.item.ve-drag-folder-target .name .title,.file_list .folder.item.ve-drop-commit .name .title{color:#fff}',
            '.ve-drag-ghost{position:fixed;top:-9999px;left:-9999px;max-width:260px;padding:10px 12px;border:1px solid #434645;border-radius:3px;background:#1c1c1c;box-shadow:0 8px 22px rgba(0,0,0,.28);color:#fff;pointer-events:none;z-index:2147483647}',
            '.ve-drag-ghost-count{font-size:.74rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#f90}',
            '.ve-drag-ghost-title{margin-top:4px;font-size:.88rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
            '.ve-folder-public{display:inline-flex;align-items:center;gap:6px;color:rgba(255,255,255,.86);font-weight:600}',
            '.ve-folder-public i{color:#f90;font-size:.9rem}',
            '.vue-simple-context-menu{background:#171717!important;border:1px solid rgba(67,70,69,.88)!important;box-shadow:0 18px 44px rgba(0,0,0,.38)!important;border-radius:6px!important;min-width:190px}',
            '.vue-simple-context-menu__item{display:flex!important;align-items:center!important;gap:12px!important;color:rgba(255,255,255,.88)!important;padding:9px 14px!important;font-family:inherit!important;font-weight:600!important}',
            '.vue-simple-context-menu__item i,.vue-simple-context-menu__item svg,.vue-simple-context-menu__item .icon{position:static!important;left:auto!important;right:auto!important;top:auto!important;transform:none!important;flex:0 0 16px;width:16px!important;min-width:16px;height:16px!important;line-height:16px!important;margin:0!important;text-align:center}',
            '.vue-simple-context-menu__item span,.vue-simple-context-menu__item strong{position:static!important;transform:none!important;flex:1 1 auto;min-width:0}',
            '.vue-simple-context-menu__item:hover{background:rgba(255,153,0,.16)!important;color:#fff!important}',
            '.vue-simple-context-menu li:first-of-type,.vue-simple-context-menu li:last-of-type{margin:4px 0!important}',
            '@media (max-width:991px){.ve-browser-toolbar{padding:10px 12px}.ve-browser-toolbar-main,.ve-folder-path{width:100%}.ve-folder-path-label{padding-top:0}}'
        ].join('');
        document.head.appendChild(style);
    }

    function renderBrowserToolbar(vm) {
        var filesPane = document.querySelector('.files');
        var list = filesPane ? filesPane.querySelector('.file_list') : null;
        var toolbar;
        var pathMarkup;
        var items;
        var currentFolderId;

        if (!filesPane || !list) {
            return;
        }

        toolbar = filesPane.querySelector('[data-videos-browser-toolbar]');

        if (!toolbar) {
            toolbar = document.createElement('div');
            toolbar.className = 've-browser-toolbar';
            toolbar.setAttribute('data-videos-browser-toolbar', 'true');
            filesPane.insertBefore(toolbar, list);
        }

        items = folderPathItems(vm);
        currentFolderId = String(vm && vm.current_folder || '0');
        pathMarkup = items.map(function (item, index) {
            var separator = index === 0 ? '' : '<span class="ve-folder-path-separator">/</span>';
            var currentClass = item.id === currentFolderId ? ' is-current' : '';

            return separator
                + '<button type="button" class="ve-folder-path-card' + currentClass + '"'
                + ' data-folder-path-card="true"'
                + ' data-folder-id="' + item.id + '">'
                + escapeHtml(item.name)
                + '</button>';
        }).join('');

        toolbar.innerHTML = ''
            + '<div class="ve-browser-toolbar-main">'
            + '  <div class="ve-folder-path-label">Path</div>'
            + '  <div class="ve-folder-path" data-folder-path>' + pathMarkup + '</div>'
            + '</div>';
    }

    function isPerPageSelect(select) {
        var values;

        if (!select || select.tagName !== 'SELECT') {
            return false;
        }

        values = Array.prototype.map.call(select.options || [], function (option) {
            return String(option.value || '').trim();
        }).filter(Boolean);

        return values.length >= 3 && values.every(function (value) {
            return ['25', '50', '100', '500', '1000'].indexOf(value) !== -1;
        });
    }

    function applyPerPageSelection(vm, value) {
        var normalized = String(value || '25');

        if (['25', '50', '100', '500', '1000'].indexOf(normalized) === -1) {
            normalized = '25';
        }

        vm.per_page = normalized;
        vm.page = 1;
        persistPerPage(normalized);
        vm.update();
    }

    function wirePerPageControls(vm) {
        document.querySelectorAll('select').forEach(function (select) {
            if (!isPerPageSelect(select)) {
                return;
            }

            select.value = String(vm && vm.per_page || '25');
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

    function folderIdFromDropTarget(target) {
        if (!target) {
            return '0';
        }

        if (target.hasAttribute('data-folder-id')) {
            return String(target.getAttribute('data-folder-id') || '0');
        }

        return String(target.getAttribute('data-folder') || '0');
    }

    function folderPublicMarkup(folder) {
        var isShared = !!(folder && folder.share_url);

        return ''
            + '<span class="ve-folder-public">'
            + '<i class="fad ' + (isShared ? 'fa-eye' : 'fa-eye-slash') + '"></i>'
            + '<span>' + (isShared ? 'Yes' : 'No') + '</span>'
            + '</span>';
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
            var mobileColumn = item.querySelector(':scope > .mobile');

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
            existingPublic.innerHTML = folderPublicMarkup(folder);

            if (nameColumn) {
                insertAfter(item, existingSize, nameColumn);
                insertAfter(item, existingDate, existingSize);
                insertAfter(item, existingViews, existingDate);
                insertAfter(item, existingPublic, existingViews);

                if (mobileColumn) {
                    insertAfter(item, mobileColumn, existingPublic);
                }
            }
        });
    }

    function decorateDraggableItems() {
        document.querySelectorAll('.file_list .item').forEach(function (item) {
            Array.prototype.forEach.call(item.querySelectorAll('*'), function (child) {
                if (!child || typeof child.setAttribute !== 'function') {
                    return;
                }

                if (child.closest('button, input, label, textarea, select, option, .close, .nav-link, .mobile, [role="button"]')) {
                    child.setAttribute('draggable', 'false');
                    return;
                }

                child.setAttribute('draggable', item.hasAttribute('data-video') || item.hasAttribute('data-folder') ? 'true' : 'false');
                child.setAttribute('data-drag-surface', 'true');
            });

            if (item.classList.contains('header')) {
                item.removeAttribute('draggable');
                return;
            }

            if (item.hasAttribute('data-video') || item.hasAttribute('data-folder')) {
                item.setAttribute('draggable', 'true');
                item.setAttribute('data-drag-surface', 'true');
                return;
            }

            item.removeAttribute('draggable');
        });
    }

    function itemDisplayTitle(item) {
        var titleNode = item && item.querySelector('h4 a, a.name .title, a.name, .title, .name');
        return titleNode ? String(titleNode.textContent || '').trim() : 'Selected item';
    }

    function selectionItemsFromDom(selection) {
        var fileLookup = Object.create(null);
        var folderLookup = Object.create(null);

        (selection && selection.fileIds || []).forEach(function (id) {
            fileLookup[String(id)] = true;
        });

        (selection && selection.folderIds || []).forEach(function (id) {
            folderLookup[String(id)] = true;
        });

        return Array.from(document.querySelectorAll('.file_list .item')).filter(function (item) {
            if (item.classList.contains('header')) {
                return false;
            }

            if (item.hasAttribute('data-video')) {
                return !!fileLookup[String(item.getAttribute('data-video') || '')];
            }

            if (item.hasAttribute('data-folder')) {
                return !!folderLookup[String(item.getAttribute('data-folder') || '')];
            }

            return false;
        });
    }

    function dragSummaryForItems(items, selection) {
        var total = (selection && selection.fileIds ? selection.fileIds.length : 0) + (selection && selection.folderIds ? selection.folderIds.length : 0);
        var primary = items.length ? itemDisplayTitle(items[0]) : 'Selected items';
        var suffix = total > 1 ? ' and ' + String(total - 1) + ' more' : '';

        return {
            total: total || items.length || 1,
            primary: primary,
            secondary: suffix ? primary + suffix : primary
        };
    }

    function destroyDragPreview(vm) {
        if (vm && vm.__veDragPreview && vm.__veDragPreview.parentNode) {
            vm.__veDragPreview.parentNode.removeChild(vm.__veDragPreview);
        }

        if (vm) {
            vm.__veDragPreview = null;
        }
    }

    function createDragPreview(vm, summary) {
        var preview = document.createElement('div');

        destroyDragPreview(vm);
        preview.className = 've-drag-ghost';
        preview.innerHTML = ''
            + '<div class="ve-drag-ghost-count">' + escapeHtml(String(summary.total)) + ' item' + (summary.total === 1 ? '' : 's') + '</div>'
            + '<div class="ve-drag-ghost-title">' + escapeHtml(summary.secondary) + '</div>';
        document.body.appendChild(preview);
        vm.__veDragPreview = preview;
        return preview;
    }

    function clearSelection(vm) {
        document.querySelectorAll('.file_list .item.active').forEach(function (item) {
            setItemSelected(item, false);
        });

        if (vm) {
            vm.file_ids = [];
            vm.folder_ids = [];

            if (vm.__veSelection && typeof vm.__veSelection.clearSelection === 'function') {
                vm.__veSelection.clearSelection();
            }
        }

        syncSelectionFromDom(vm);
    }

    function applyPendingMoveState(vm, selection, targetNode) {
        var items = selectionItemsFromDom(selection);

        if (!items.length) {
            return null;
        }

        items.forEach(function (item) {
            item.classList.add('ve-item-pending-move');
        });

        if (targetNode) {
            targetNode.classList.add('ve-drop-commit');
        }

        vm.__vePendingDragMove = {
            items: items,
            targetNode: targetNode || null
        };

        return vm.__vePendingDragMove;
    }

    function clearPendingMoveState(vm) {
        var state = vm && vm.__vePendingDragMove;

        if (!state) {
            return;
        }

        (state.items || []).forEach(function (item) {
            item.classList.remove('ve-item-pending-move');
        });

        if (state.targetNode) {
            state.targetNode.classList.remove('ve-drop-commit');
        }

        vm.__vePendingDragMove = null;
    }

    function removeEmptyFolderBars() {
        document.querySelectorAll('.d-flex.flex-wrap.align-items-center.justify-content-between.folder').forEach(function (node) {
            var hasContent;
            var parentFiles;

            if (!node) {
                return;
            }

            hasContent = !!node.querySelector('.item, [data-folder], a, button, .title, h1, h2, h3, h4, h5');
            parentFiles = node.closest('.files');

            if (!hasContent && String(node.textContent || '').replace(/\s+/g, '') === '') {
                node.remove();
                return;
            }

            if (parentFiles && !parentFiles.querySelector('.file_list .folder.item[data-folder]') && !hasContent) {
                node.remove();
            }
        });
    }

    function optimisticFolderPayload(name) {
        return {
            fld_id: 'tmp-folder-' + String(Date.now()) + '-' + String(Math.floor(Math.random() * 10000)),
            fld_code: '',
            fld_name: String(name || '').trim(),
            siz: '0 B',
            siz_bytes: 0,
            cre: legacyDateLabel(new Date()),
            share_url: '',
            __veOptimistic: true
        };
    }

    function ensureFolderCollection(vm) {
        if (!vm.data || typeof vm.data !== 'object') {
            vm.data = {};
        }

        if (!Array.isArray(vm.data.folders)) {
            vm.data.folders = [];
        }

        return vm.data.folders;
    }

    function ensureVideoCollection(vm) {
        if (!vm.data || typeof vm.data !== 'object') {
            vm.data = {};
        }

        if (!Array.isArray(vm.data.videos)) {
            vm.data.videos = [];
        }

        return vm.data.videos;
    }

    function insertOptimisticFolder(vm, folder) {
        var folders = ensureFolderCollection(vm);

        folders.unshift(folder);

        if (typeof vm.$forceUpdate === 'function') {
            vm.$forceUpdate();
        }

        afterRender(vm);
    }

    function replaceOptimisticFolder(vm, tempId, folder) {
        var folders = ensureFolderCollection(vm);
        var index = folders.findIndex(function (item) {
            return item && String(item.fld_id || '') === String(tempId || '');
        });

        if (index === -1) {
            folders.unshift(folder);
        } else {
            folders.splice(index, 1, folder);
        }

        if (typeof vm.$forceUpdate === 'function') {
            vm.$forceUpdate();
        }

        afterRender(vm);
    }

    function removeOptimisticFolder(vm, tempId) {
        var folders = ensureFolderCollection(vm);
        var index = folders.findIndex(function (item) {
            return item && String(item.fld_id || '') === String(tempId || '');
        });

        if (index === -1) {
            return;
        }

        folders.splice(index, 1);

        if (typeof vm.$forceUpdate === 'function') {
            vm.$forceUpdate();
        }

        afterRender(vm);
    }

    function applyOptimisticMove(vm, selection, targetFolderId) {
        var normalizedTargetFolderId = String(targetFolderId || '0');
        var currentFolderId = String(vm && vm.current_folder || '0');
        var fileIdLookup = Object.create(null);
        var folderIdLookup = Object.create(null);
        var videos;
        var folders;
        var snapshot;
        var removedVideos = 0;

        if (!vm || normalizedTargetFolderId === currentFolderId) {
            return null;
        }

        normalizeIdList(selection && selection.fileIds).forEach(function (id) {
            fileIdLookup[String(id)] = true;
        });

        normalizeIdList(selection && selection.folderIds).forEach(function (id) {
            folderIdLookup[String(id)] = true;
        });

        videos = ensureVideoCollection(vm);
        folders = ensureFolderCollection(vm);
        snapshot = {
            videos: videos.slice(),
            folders: folders.slice(),
            totalVideos: vm.total_videos,
            fileIds: Array.isArray(vm.file_ids) ? vm.file_ids.slice() : [],
            folderIds: Array.isArray(vm.folder_ids) ? vm.folder_ids.slice() : []
        };

        vm.data.videos = videos.filter(function (video) {
            return !video || !fileIdLookup[String(video.id || '')];
        });
        vm.data.folders = folders.filter(function (folder) {
            return !folder || !folderIdLookup[String(folder.fld_id || '')];
        });

        removedVideos = snapshot.videos.length - vm.data.videos.length;

        if (typeof vm.total_videos === 'number') {
            vm.total_videos = Math.max(0, vm.total_videos - removedVideos);
        }

        vm.file_ids = [];
        vm.folder_ids = [];

        if (typeof vm.$forceUpdate === 'function') {
            vm.$forceUpdate();
        }

        afterRender(vm);
        return snapshot;
    }

    function restoreOptimisticMove(vm, snapshot) {
        if (!vm || !snapshot) {
            return;
        }

        if (!vm.data || typeof vm.data !== 'object') {
            vm.data = {};
        }

        vm.data.videos = Array.isArray(snapshot.videos) ? snapshot.videos.slice() : [];
        vm.data.folders = Array.isArray(snapshot.folders) ? snapshot.folders.slice() : [];
        vm.total_videos = snapshot.totalVideos;
        vm.file_ids = Array.isArray(snapshot.fileIds) ? snapshot.fileIds.slice() : [];
        vm.folder_ids = Array.isArray(snapshot.folderIds) ? snapshot.folderIds.slice() : [];

        if (typeof vm.$forceUpdate === 'function') {
            vm.$forceUpdate();
        }

        afterRender(vm);
    }

    function hideLegacyBackButtons() {
        document.querySelectorAll('.files button, .files a, .files .btn').forEach(function (node) {
            var text;

            if (!node || node.closest('[data-videos-browser-toolbar]')) {
                return;
            }

            text = String(node.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();

            if (text === 'go back') {
                node.remove();
            }
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

                return !isFormControlTarget(source);
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
                node.classList.remove('ve-drop-commit');
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
            var target = event.target && event.target.closest('.file_list .folder.item[data-folder], [data-folder-path-card]');

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
            var items;
            var preview;

            if (!item || item.classList.contains('header') || (!item.hasAttribute('data-video') && !item.hasAttribute('data-folder'))) {
                return;
            }

            selection = selectionFromItem(vm, item);
            items = selectionItemsFromDom(selection);

            vm.__veInternalDrag = {
                fileIds: selection.fileIds,
                folderIds: selection.folderIds
            };

            if (event.dataTransfer) {
                event.dataTransfer.effectAllowed = 'move';
                preview = createDragPreview(vm, dragSummaryForItems(items, vm.__veInternalDrag));

                try {
                    event.dataTransfer.setData('text/plain', JSON.stringify(vm.__veInternalDrag));
                } catch (error) {
                    // Ignore browsers that block custom drag data here.
                }

                if (preview && typeof event.dataTransfer.setDragImage === 'function') {
                    event.dataTransfer.setDragImage(preview, 18, 18);
                }
            }
        });

        root.addEventListener('dragend', function () {
            vm.__veInternalDrag = null;

            if (!vm.__vePendingDragMove) {
                clearPendingMoveState(vm);
            }

            destroyDragPreview(vm);
            clearHighlight();
        });

        root.addEventListener('drop', function (event) {
            var folderTarget = folderDropTargetFromEvent(event);
            var targetFolderId = folderTarget ? folderIdFromDropTarget(folderTarget) : String(vm.current_folder || '0');
            var optimisticMove = null;

            event.preventDefault();
            clearHighlight();

            if (vm.__veInternalDrag) {
                if (String(targetFolderId) === String(vm.current_folder || '0')) {
                    vm.__veInternalDrag = null;
                    destroyDragPreview(vm);
                    return;
                }

                applyPendingMoveState(vm, vm.__veInternalDrag, folderTarget);
                optimisticMove = applyOptimisticMove(vm, vm.__veInternalDrag, targetFolderId);
                moveItemsToFolder(vm, vm.__veInternalDrag, targetFolderId, function (failed) {
                    if (failed) {
                        restoreOptimisticMove(vm, optimisticMove);
                        clearPendingMoveState(vm);
                    } else {
                        clearPendingMoveState(vm);
                    }

                    vm.__veInternalDrag = null;
                    vm.__vePendingMove = null;
                    vm.file_ids = [];
                    vm.folder_ids = [];
                    destroyDragPreview(vm);
                    vm.update({ silent: true });
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
            var pathCard = event.target && event.target.closest('[data-folder-path-card]');
            var item = event.target && event.target.closest('.file_list .item');

            if (!shareLink) {
                if (pathCard) {
                    event.preventDefault();
                    vm.open_folder(String(pathCard.getAttribute('data-folder-id') || '0'));
                    return;
                }

                if (!item && !event.target.closest('.vue-simple-context-menu, .modal, .modal-backdrop') && !isFormControlTarget(event.target)) {
                    clearSelection(vm);
                }

                return;
            }

            event.preventDefault();
            openFolderShare(vm, shareLink.getAttribute('data-folder-share'));
        });

        document.addEventListener('change', function (event) {
            var select = event.target;

            if (!isPerPageSelect(select)) {
                return;
            }

            applyPerPageSelection(vm, select.value);
        });

        vm.__veSelectionSyncReady = true;
    }

    function afterRender(vm) {
        window.setTimeout(function () {
            ensureBrowserToolbarStyles();
            renderBrowserToolbar(vm);
            wirePerPageControls(vm);
            ensureToolbarLabels(vm);
            ensureActionButtonTypes();
            decorateFolderRows(vm);
            decorateDraggableItems();
            hideLegacyBackButtons();
            removeEmptyFolderBars();
            hydrateSavedContentType(vm);
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

        target.update = function (options) {
            var vm = this;
            var silent = !!(options && options.silent);
            var requestId = (Number(vm.__veUpdateRequestId || 0) + 1);
            var payload = {
                page: vm.page,
                per_page: vm.per_page,
                sort: vm.sort,
                fld_id: vm.current_folder,
                key: vm.$root.search_filter || vm.search || '',
                sort_field: vm.sort_field,
                sort_order: vm.sort_order
            };

            vm.__veUpdateRequestId = requestId;

            if (!silent) {
                vm.loading = false;
                vm.loaded = false;
            }

            requestJson('get', ENDPOINTS.actions, payload, function (response) {
                if (requestId !== Number(vm.__veUpdateRequestId || 0)) {
                    return;
                }

                vm.data = response;
                vm.loaded = true;
                vm.loading = false;
                vm.total_videos = response.total_videos;
                vm.token = response.token;
                vm.per_page = String(response.per_page || vm.per_page || '25');
                vm.folder_ids = [];
                vm.file_ids = [];
                vm.folders = [];

                if (/^(1|2|3)$/.test(String(response.uploader_type || ''))) {
                    vm.content_type = String(response.uploader_type);
                    setCurrentUploaderType(vm.content_type);
                }

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
            var optimisticMove;

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
            optimisticMove = applyOptimisticMove(vm, {
                fileIds: fileIds,
                folderIds: folderIds
            }, targetFolderId);
            moveItemsToFolder(vm, {
                fileIds: fileIds,
                folderIds: folderIds
            }, targetFolderId, function (failed) {
                if (failed) {
                    restoreOptimisticMove(vm, optimisticMove);
                }

                vm.__vePendingMove = null;
                vm.file_ids = [];
                vm.folder_ids = [];
                vm.update({ silent: true });
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
                syncSelectionFromDom(vm);

                if (Array.isArray(vm.file_ids) && vm.file_ids.length > 1 && normalizeIdList(vm.file_ids).indexOf(String(fileId)) !== -1) {
                    vm.export_files();
                    return;
                }

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
            var folderName = String(vm.new_folder || '').trim();
            var optimisticFolder;

            if (!folderName) {
                showToast('info', 'Please enter a folder name.');
                return;
            }

            optimisticFolder = optimisticFolderPayload(folderName);
            window.jQuery('#add_folder').modal('hide');
            vm.new_folder = '';
            insertOptimisticFolder(vm, optimisticFolder);

            requestJson('post', ENDPOINTS.actions, {
                fld_id: vm.current_folder,
                create_new_folder: folderName,
                token: vm.token
            }, function (response) {
                if (response && response.status === 'fail') {
                    removeOptimisticFolder(vm, optimisticFolder.fld_id);
                    showToast('error', response.message);
                    return;
                }

                if (Array.isArray(response) && response[0]) {
                    replaceOptimisticFolder(vm, optimisticFolder.fld_id, response[0]);
                } else {
                    replaceOptimisticFolder(vm, optimisticFolder.fld_id, Object.assign({}, optimisticFolder, {
                        __veOptimistic: false
                    }));
                }

                window.setTimeout(function () {
                    vm.update();
                }, 0);
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

        target.add_content_type = function () {
            var vm = this;

            requestJson('post', ENDPOINTS.actions, {
                content_type: vm.content_type,
                token: vm.token
            }, function (response) {
                if (!response || response.status !== 'ok') {
                    showToast('error', response && response.message ? response.message : 'Unable to save your content type.');
                    return;
                }

                vm.content_type = String(response.uploader_type || vm.content_type || '1');
                setCurrentUploaderType(vm.content_type);
                window.jQuery('#content_type').modal('hide');
                showToast('success', response.message || 'Content type saved.');
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
        hydrateSavedContentType(instance);
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
