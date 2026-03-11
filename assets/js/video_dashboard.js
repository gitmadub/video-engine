(function () {
    var root = document.getElementById("ve-dashboard-videos");
    var basePath = window.VE_BASE_PATH || "";
    var csrfToken = window.VE_CSRF_TOKEN || "";
    var queueId = 0;

    if (!root) {
        return;
    }

    var state = {
        loading: true,
        videos: [],
        capabilities: {},
        selectedFiles: [],
        queue: [],
        uploading: false,
        uploadPanelCollapsed: false,
        feedback: null
    };

    var els = {
        uploadInput: root.querySelector("[data-upload-input]"),
        titleInput: root.querySelector("[data-title-input]"),
        feedback: root.querySelector("[data-feedback]"),
        list: root.querySelector("[data-video-list]"),
        selectedTitle: root.querySelector("[data-selected-title]"),
        selectedFiles: root.querySelector("[data-selected-files]"),
        uploadLimitCopy: root.querySelector("[data-upload-limit-copy]"),
        statReady: root.querySelector("[data-stat-ready]"),
        statActive: root.querySelector("[data-stat-active]"),
        statStorage: root.querySelector("[data-stat-storage]"),
        statPoster: root.querySelector("[data-stat-poster]"),
        modalTitle: root.querySelector("[data-modal-title]"),
        modalPoster: root.querySelector("[data-modal-poster]"),
        modalMeta: root.querySelector("[data-modal-meta]"),
        modalWatch: root.querySelector("#ve-link-watch"),
        modalEmbed: root.querySelector("#ve-link-embed"),
        modalIframe: root.querySelector("#ve-link-iframe"),
        uploadPanel: root.querySelector("[data-upload-panel]"),
        uploadHeader: root.querySelector("[data-upload-header]"),
        uploadList: root.querySelector("[data-upload-list]")
    };

    var HEADER_ROW = [
        '<li class="header d-flex align-items-center">',
        '<div class="name">Name</div>',
        '<div class="size">Storage</div>',
        '<div class="date">Updated</div>',
        '<div class="views">Actions</div>',
        "</li>"
    ].join("");

    function appUrl(path) {
        if (!path) {
            return basePath || "/";
        }

        if (/^(?:[a-z][a-z0-9+.-]*:)?\/\//i.test(path)) {
            return path;
        }

        if (path.charAt(0) !== "/") {
            path = "/" + path;
        }

        return basePath + path;
    }

    function absoluteUrl(path) {
        if (!path) {
            return window.location.href;
        }

        if (/^(?:[a-z][a-z0-9+.-]*:)?\/\//i.test(path)) {
            return path;
        }

        return window.location.origin + appUrl(path);
    }

    function escapeHtml(value) {
        return String(value || "")
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function formatBytes(bytes) {
        var value = Number(bytes || 0);

        if (!value) {
            return "0 B";
        }

        var units = ["B", "KB", "MB", "GB", "TB"];
        var power = Math.min(Math.floor(Math.log(value) / Math.log(1024)), units.length - 1);
        var scaled = value / Math.pow(1024, power);

        return scaled.toFixed(power === 0 ? 0 : 2) + " " + units[power];
    }

    function formatDuration(seconds) {
        var value = Math.round(Number(seconds || 0));

        if (!value) {
            return "";
        }

        var hours = Math.floor(value / 3600);
        var minutes = Math.floor((value % 3600) / 60);
        var secs = value % 60;

        if (hours > 0) {
            return String(hours).padStart(2, "0") + ":" + String(minutes).padStart(2, "0") + ":" + String(secs).padStart(2, "0");
        }

        return String(minutes).padStart(2, "0") + ":" + String(secs).padStart(2, "0");
    }

    function formatDate(value) {
        if (!value) {
            return "Pending";
        }

        var normalized = String(value).replace(" ", "T");

        if (normalized.indexOf("Z") === -1) {
            normalized += "Z";
        }

        var date = new Date(normalized);

        if (Number.isNaN(date.getTime())) {
            return String(value);
        }

        return date.toLocaleString();
    }

    function statusLabel(status) {
        var value = String(status || "queued");
        return value.charAt(0).toUpperCase() + value.slice(1);
    }

    function request(path, options) {
        var settings = options || {};
        var headers = settings.headers || {};

        if (csrfToken && !headers["X-CSRF-Token"]) {
            headers["X-CSRF-Token"] = csrfToken;
        }

        return fetch(appUrl(path), {
            method: settings.method || "GET",
            credentials: "same-origin",
            body: settings.body || null,
            headers: headers
        }).then(function (response) {
            return response.json().catch(function () {
                return {};
            }).then(function (payload) {
                if (!response.ok) {
                    var error = new Error(payload.message || "Request failed.");
                    error.payload = payload;
                    throw error;
                }

                return payload;
            });
        });
    }

    function defaultTitle(fileName) {
        return String(fileName || "")
            .replace(/\.[^.]+$/, "")
            .replace(/[_\-.]+/g, " ")
            .trim();
    }

    function isProcessingAvailable() {
        return root.getAttribute("data-processing-ready") === "1";
    }

    function setFeedback(message, type) {
        if (!message) {
            state.feedback = null;
            renderFeedback();
            return;
        }

        state.feedback = {
            message: String(message),
            type: type || "info"
        };
        renderFeedback();
    }

    function renderFeedback() {
        if (!els.feedback) {
            return;
        }

        if (!state.feedback) {
            els.feedback.innerHTML = "";
            return;
        }

        var tone = state.feedback.type;
        var alertClass = "alert-info";

        if (tone === "success") {
            alertClass = "alert-success";
        } else if (tone === "danger") {
            alertClass = "alert-danger";
        } else if (tone === "warning") {
            alertClass = "alert-warning";
        }

        els.feedback.innerHTML = '<div class="alert ' + alertClass + ' mb-4">' + escapeHtml(state.feedback.message) + "</div>";
    }

    function renderSelectedFiles() {
        var count = state.selectedFiles.length;

        if (els.selectedTitle) {
            els.selectedTitle.textContent = count ? (count === 1 ? state.selectedFiles[0].name : count + " files selected") : "Select one or more video files";
        }

        if (els.selectedFiles) {
            if (!count) {
                els.selectedFiles.textContent = "MP4, MKV, MOV, AVI, WEBM and similar containers are accepted.";
            } else if (count === 1) {
                els.selectedFiles.textContent = state.selectedFiles[0].name;
            } else {
                els.selectedFiles.textContent = state.selectedFiles.map(function (file) {
                    return file.name;
                }).join(", ");
            }
        }

        if (els.titleInput) {
            els.titleInput.disabled = count > 1 || !count;

            if (count === 1 && !els.titleInput.value.trim()) {
                els.titleInput.value = defaultTitle(state.selectedFiles[0].name);
            }

            if (!count) {
                els.titleInput.value = "";
            }
        }

        root.querySelectorAll('[data-action="upload-selected"]').forEach(function (button) {
            button.disabled = !count || state.uploading || !isProcessingAvailable();
        });
    }

    function renderStats() {
        var videos = state.videos || [];
        var readyCount = 0;
        var activeCount = 0;
        var totalStorage = 0;

        videos.forEach(function (video) {
            if (video.status === "ready") {
                readyCount += 1;
            }

            if (video.status === "queued" || video.status === "processing") {
                activeCount += 1;
            }

            totalStorage += Number(video.processed_size_bytes || video.original_size_bytes || 0);
        });

        if (els.statReady) {
            els.statReady.textContent = String(readyCount);
        }

        if (els.statActive) {
            els.statActive.textContent = String(activeCount);
        }

        if (els.statStorage) {
            els.statStorage.textContent = formatBytes(totalStorage);
        }

        if (els.statPoster) {
            els.statPoster.textContent = root.getAttribute("data-player-mode-label") || "Default artwork";
        }
    }

    function renderPosterFallbacks() {
        root.querySelectorAll("[data-poster-image]").forEach(function (image) {
            image.addEventListener("error", function onError() {
                image.removeEventListener("error", onError);
                image.src = root.getAttribute("data-no-poster") || "";
            });
        });
    }

    function renderList() {
        if (!els.list) {
            return;
        }

        var html = [HEADER_ROW];

        if (state.loading) {
            html.push('<li class="d-flex align-items-center ve-list-placeholder"><div class="name"><h4>Loading videos...</h4></div></li>');
            els.list.innerHTML = html.join("");
            return;
        }

        if (!state.videos.length) {
            html.push('<li class="d-flex align-items-center ve-list-placeholder"><div class="name"><h4>No videos uploaded yet.</h4><span>Your compressed HLS library will appear here once the first upload is accepted.</span></div></li>');
            els.list.innerHTML = html.join("");
            return;
        }

        state.videos.forEach(function (video) {
            var meta = [];
            var note = video.status === "failed"
                ? (video.error || "This video could not be processed.")
                : (video.status_message || "Secure streaming and preview assets are managed automatically.");
            var displayBytes = Number(video.processed_size_bytes || video.original_size_bytes || 0);
            var originalBytes = Number(video.original_size_bytes || 0);
            var storageSubline = originalBytes > 0 && displayBytes > 0 && originalBytes !== displayBytes
                ? "from " + formatBytes(originalBytes)
                : (video.original_filename || "private source");
            var dateLabel = formatDate(video.updated_at || video.created_at);
            var dateSubline = video.ready_at ? "ready " + formatDate(video.ready_at) : statusLabel(video.status);
            var compressionText = video.space_saved_percent !== null && video.space_saved_percent !== undefined
                ? Number(video.space_saved_percent).toFixed(1) + "% saved"
                : statusLabel(video.status);

            if (video.duration_seconds) {
                meta.push(formatDuration(video.duration_seconds));
            }

            if (video.width && video.height) {
                meta.push(String(video.width) + "x" + String(video.height));
            }

            if (video.video_codec) {
                meta.push(String(video.video_codec).toUpperCase());
            }

            if (video.audio_codec) {
                meta.push(String(video.audio_codec).toUpperCase());
            }

            if (!meta.length && video.original_filename) {
                meta.push(video.original_filename);
            }

            html.push([
                '<li class="video d-flex align-items-center flex-wrap" data-video-id="' + escapeHtml(video.public_id) + '">',
                '<div class="name d-flex align-items-center">',
                '<div class="icon ve-video-thumb">',
                '<img data-poster-image loading="lazy" src="' + escapeHtml(appUrl(video.poster_url)) + '" alt="' + escapeHtml(video.title) + '">',
                '<i class="fad fa-film-alt" aria-hidden="true"></i>',
                "</div>",
                "<h4>",
                '<a href="' + escapeHtml(appUrl(video.watch_url)) + '" target="_blank" rel="noopener">' + escapeHtml(video.title) + "</a>",
                '<span>' + escapeHtml(meta.join(" · ")) + "</span>",
                '<span class="encoded"><span class="ve-status-badge is-' + escapeHtml(video.status) + '">' + escapeHtml(statusLabel(video.status)) + "</span> " + escapeHtml(compressionText) + "</span>",
                "</h4>",
                "</div>",
                '<div class="size"><strong>' + escapeHtml(formatBytes(displayBytes)) + "</strong><small>" + escapeHtml(storageSubline) + "</small></div>",
                '<div class="date"><strong>' + escapeHtml(dateLabel) + "</strong><small>" + escapeHtml(dateSubline) + "</small></div>",
                '<div class="views ve-video-row-actions">',
                '<button type="button" class="btn btn-white btn-sm" data-open-links="' + escapeHtml(video.public_id) + '">Get links</button>',
                '<a class="btn btn-primary btn-sm" href="' + escapeHtml(appUrl(video.watch_url)) + '" target="_blank" rel="noopener">Watch</a>',
                '<button type="button" class="btn btn-white btn-sm ve-btn-delete" data-delete-video="' + escapeHtml(video.public_id) + '">Delete</button>',
                "</div>",
                '<div class="ve-video-note ' + (video.status === "failed" ? "is-error" : "") + '">' + escapeHtml(note) + "</div>",
                "</li>"
            ].join(""));
        });

        els.list.innerHTML = html.join("");
        renderPosterFallbacks();
    }

    function renderQueue() {
        if (!els.uploadPanel || !els.uploadHeader || !els.uploadList) {
            return;
        }

        if (!state.queue.length) {
            els.uploadPanel.classList.add("is-hidden");
            els.uploadList.innerHTML = "";
            return;
        }

        els.uploadPanel.classList.remove("is-hidden");
        els.uploadPanel.classList.toggle("is-collapsed", state.uploadPanelCollapsed);
        els.uploadHeader.textContent = state.queue.length === 1 ? "1 upload" : state.queue.length + " uploads";

        els.uploadList.innerHTML = state.queue.map(function (item) {
            var label = item.status === "uploading"
                ? (item.speed || "Uploading...")
                : item.status === "done"
                    ? "Processing started"
                    : item.status === "error"
                        ? (item.message || "Upload failed")
                        : item.status === "cancelled"
                            ? "Cancelled"
                            : "Waiting";

            return [
                '<div class="file d-flex align-items-center ' + (item.status === "error" ? "is-error" : "") + " " + (item.status === "done" ? "is-done" : "") + '">',
                '<div class="circle text-center"><span class="percent">' + Math.max(0, Math.min(100, Math.round(item.progress || 0))) + '%</span></div>',
                '<div class="name">',
                "<p>" + escapeHtml(item.name) + "</p>",
                '<span class="status">' + escapeHtml(label) + "</span>",
                "</div>",
                '<div class="remove">',
                (item.status === "uploading" || item.status === "queued"
                    ? '<button class="cancel" type="button" data-remove-upload="' + escapeHtml(item.id) + '"><i class="fad fa-times"></i></button>'
                    : ""),
                "</div>",
                "</div>"
            ].join("");
        }).join("");
    }

    function renderAll() {
        renderFeedback();
        renderSelectedFiles();
        renderStats();
        renderList();
        renderQueue();
    }

    function setSelectedFiles(files) {
        state.selectedFiles = Array.isArray(files) ? files : [];
        renderSelectedFiles();
    }

    function showModal(selector) {
        if (window.jQuery && window.jQuery.fn && window.jQuery.fn.modal) {
            window.jQuery(selector).modal("show");
            return;
        }

        var modal = document.querySelector(selector);

        if (modal) {
            modal.style.display = "block";
            modal.classList.add("show");
        }
    }

    function copyField(fieldId, button) {
        var field = document.getElementById(fieldId);

        if (!field) {
            return;
        }

        field.focus();
        field.select();
        field.setSelectionRange(0, field.value.length);

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(field.value);
        } else {
            document.execCommand("copy");
        }

        if (button) {
            var original = button.textContent;
            button.textContent = "copied";

            window.setTimeout(function () {
                button.textContent = original;
            }, 1200);
        }
    }

    function openLinks(publicId) {
        var video = state.videos.find(function (entry) {
            return entry.public_id === publicId;
        });

        if (!video) {
            return;
        }

        var watchUrl = absoluteUrl(video.watch_url);
        var embedUrl = absoluteUrl(video.embed_url);
        var iframeCode = '<iframe width="' + root.getAttribute("data-embed-width") + '" height="' + root.getAttribute("data-embed-height") + '" src="' + embedUrl + '" scrolling="no" frameborder="0" allowfullscreen="true"></iframe>';
        var modalText = statusLabel(video.status) + " · " + (root.getAttribute("data-player-mode-label") || "Default artwork") + " · " + (root.getAttribute("data-player-mode-description") || "");

        if (els.modalTitle) {
            els.modalTitle.textContent = video.title;
        }

        if (els.modalMeta) {
            els.modalMeta.textContent = modalText;
        }

        if (els.modalWatch) {
            els.modalWatch.value = watchUrl;
        }

        if (els.modalEmbed) {
            els.modalEmbed.value = embedUrl;
        }

        if (els.modalIframe) {
            els.modalIframe.value = iframeCode;
        }

        if (els.modalPoster) {
            els.modalPoster.src = appUrl(video.poster_url);
            els.modalPoster.onerror = function () {
                els.modalPoster.src = root.getAttribute("data-no-poster") || "";
            };
        }

        showModal("#ve-video-links-modal");
    }

    function removeQueueItem(id) {
        var index = state.queue.findIndex(function (entry) {
            return String(entry.id) === String(id);
        });

        if (index === -1) {
            return;
        }

        var item = state.queue[index];

        if (item.status === "uploading" && item.xhr) {
            item.status = "cancelled";
            item.xhr.abort();
        } else {
            state.queue.splice(index, 1);
            renderQueue();
        }
    }

    function startNextUpload() {
        if (state.uploading) {
            return;
        }

        var item = state.queue.find(function (entry) {
            return entry.status === "queued";
        });

        if (!item) {
            renderQueue();
            return;
        }

        state.uploading = true;
        item.status = "uploading";
        item.progress = 0;
        item.speed = "Preparing upload...";
        renderQueue();

        var formData = new FormData();
        var xhr = new XMLHttpRequest();
        var lastLoaded = 0;
        var lastTick = Date.now();

        item.xhr = xhr;
        formData.append("token", csrfToken);
        formData.append("video", item.file);

        if (item.title) {
            formData.append("title", item.title);
        }

        xhr.open("POST", appUrl("/api/videos/upload"));
        xhr.withCredentials = true;

        if (csrfToken) {
            xhr.setRequestHeader("X-CSRF-Token", csrfToken);
        }

        xhr.upload.addEventListener("progress", function (event) {
            if (!event.lengthComputable) {
                return;
            }

            var now = Date.now();
            var delta = Math.max(1, now - lastTick);
            var speed = ((event.loaded - lastLoaded) / delta) * 1000;

            item.progress = (event.loaded / event.total) * 100;
            item.speed = formatBytes(speed) + "/s";
            lastLoaded = event.loaded;
            lastTick = now;
            renderQueue();
        });

        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) {
                return;
            }

            item.xhr = null;
            state.uploading = false;

            var payload = {};

            try {
                payload = JSON.parse(xhr.responseText || "{}");
            } catch (error) {
                payload = {};
            }

            if (xhr.status >= 200 && xhr.status < 300) {
                item.status = "done";
                item.progress = 100;
                item.speed = "Upload accepted";
                item.message = payload.message || "Upload accepted.";
                setFeedback(item.message, "success");
                loadVideos(true);
            } else if (item.status !== "cancelled") {
                item.status = "error";
                item.message = payload.message || "Upload failed.";
                item.speed = item.message;
                setFeedback(item.message, "danger");
            }

            renderQueue();
            startNextUpload();
        };

        xhr.onerror = function () {
            item.xhr = null;
            state.uploading = false;
            item.status = "error";
            item.message = "Upload failed.";
            item.speed = item.message;
            setFeedback(item.message, "danger");
            renderQueue();
            startNextUpload();
        };

        xhr.onabort = function () {
            item.xhr = null;
            state.uploading = false;
            item.status = "cancelled";
            item.speed = "Cancelled";
            renderQueue();
            startNextUpload();
        };

        xhr.send(formData);
    }

    function beginUpload() {
        if (!isProcessingAvailable()) {
            setFeedback(root.getAttribute("data-processing-copy") || "Video processing is not available yet.", "warning");
            return;
        }

        if (!state.selectedFiles.length) {
            setFeedback("Choose one or more video files first.", "warning");
            return;
        }

        var customTitle = els.titleInput ? els.titleInput.value.trim() : "";
        var selected = state.selectedFiles.slice();

        selected.forEach(function (file, index) {
            queueId += 1;
            state.queue.push({
                id: String(queueId),
                file: file,
                name: file.name,
                title: selected.length === 1 && customTitle ? customTitle : defaultTitle(file.name),
                status: "queued",
                progress: 0,
                speed: "Waiting",
                message: ""
            });
        });

        state.selectedFiles = [];

        if (els.uploadInput) {
            els.uploadInput.value = "";
        }

        if (els.titleInput) {
            els.titleInput.value = "";
        }

        renderSelectedFiles();
        renderQueue();
        startNextUpload();
    }

    function loadVideos(silent) {
        if (!silent) {
            state.loading = true;
            renderList();
        }

        request("/api/videos").then(function (payload) {
            state.loading = false;
            state.videos = Array.isArray(payload.videos) ? payload.videos : [];
            state.capabilities = payload.capabilities || {};

            if (payload.capabilities && payload.capabilities.max_upload_human && els.uploadLimitCopy) {
                els.uploadLimitCopy.textContent = payload.capabilities.max_upload_human;
            }

            renderAll();
        }).catch(function (error) {
            state.loading = false;
            renderAll();
            setFeedback((error && error.message) || "Could not load videos.", "danger");
        });
    }

    root.addEventListener("click", function (event) {
        var actionButton = event.target.closest("[data-action]");
        var copyButton = event.target.closest("[data-copy-target]");
        var linkButton = event.target.closest("[data-open-links]");
        var deleteButton = event.target.closest("[data-delete-video]");
        var removeButton = event.target.closest("[data-remove-upload]");

        if (copyButton) {
            event.preventDefault();
            copyField(copyButton.getAttribute("data-copy-target"), copyButton);
            return;
        }

        if (linkButton) {
            event.preventDefault();
            openLinks(linkButton.getAttribute("data-open-links"));
            return;
        }

        if (deleteButton) {
            event.preventDefault();

            if (!window.confirm("Delete this video and all generated stream files?")) {
                return;
            }

            request("/api/videos/" + encodeURIComponent(deleteButton.getAttribute("data-delete-video")), {
                method: "DELETE"
            }).then(function (payload) {
                setFeedback(payload.message || "Video deleted.", "success");
                loadVideos(true);
            }).catch(function (error) {
                setFeedback((error && error.message) || "Could not delete the video.", "danger");
            });
            return;
        }

        if (removeButton) {
            event.preventDefault();
            removeQueueItem(removeButton.getAttribute("data-remove-upload"));
            return;
        }

        if (!actionButton) {
            return;
        }

        event.preventDefault();

        switch (actionButton.getAttribute("data-action")) {
            case "select-files":
                if (els.uploadInput) {
                    els.uploadInput.click();
                }
                break;
            case "upload-selected":
                beginUpload();
                break;
            case "clear-selected":
                setSelectedFiles([]);
                if (els.uploadInput) {
                    els.uploadInput.value = "";
                }
                break;
            case "refresh":
                loadVideos(false);
                break;
            case "toggle-upload-panel":
                state.uploadPanelCollapsed = !state.uploadPanelCollapsed;
                renderQueue();
                break;
            default:
                break;
        }
    });

    if (els.uploadInput) {
        els.uploadInput.addEventListener("change", function () {
            var files = Array.prototype.slice.call(els.uploadInput.files || []);
            setSelectedFiles(files);
        });
    }

    root.querySelectorAll(".ve-drop-zone").forEach(function (dropZone) {
        ["dragenter", "dragover"].forEach(function (name) {
            dropZone.addEventListener(name, function (event) {
                event.preventDefault();
                dropZone.classList.add("is-dragover");
            });
        });

        ["dragleave", "dragend", "drop"].forEach(function (name) {
            dropZone.addEventListener(name, function (event) {
                event.preventDefault();
                dropZone.classList.remove("is-dragover");
            });
        });

        dropZone.addEventListener("drop", function (event) {
            var files = Array.prototype.slice.call((event.dataTransfer && event.dataTransfer.files) || []);

            if (files.length) {
                setSelectedFiles(files);
            }
        });
    });

    renderAll();

    if (!isProcessingAvailable()) {
        setFeedback(root.getAttribute("data-processing-copy") || "Video processing is not available yet.", "warning");
    }

    loadVideos(false);

    window.setInterval(function () {
        var hasActiveVideo = state.videos.some(function (video) {
            return video.status === "queued" || video.status === "processing";
        });
        var hasActiveUpload = state.queue.some(function (item) {
            return item.status === "queued" || item.status === "uploading";
        });

        if (!document.hidden && (hasActiveVideo || hasActiveUpload)) {
            loadVideos(true);
        }
    }, 3000);
}());
