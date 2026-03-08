(function () {
    var basePath = window.VE_BASE_PATH || "";
    var csrfToken = window.VE_CSRF_TOKEN || "";
    var portals = [];

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
            return "Processing";
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
            return "";
        }

        var date = new Date(String(value).replace(" ", "T") + "Z");

        if (Number.isNaN(date.getTime())) {
            return String(value);
        }

        return date.toLocaleString();
    }

    function openLoginModal() {
        if (window.jQuery && window.jQuery.fn && window.jQuery.fn.modal) {
            window.jQuery("#login").modal("show");
            return;
        }

        window.location.hash = "login";
    }

    function request(path, options) {
        var settings = options || {};
        var headers = settings.headers || {};

        if (csrfToken && !headers["X-CSRF-Token"]) {
            headers["X-CSRF-Token"] = csrfToken;
        }

        return fetch(appUrl(path), {
            method: settings.method || "GET",
            body: settings.body || null,
            credentials: "same-origin",
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

    function renderPortal(portal) {
        var root = portal.root;
        var auth = portal.auth;
        var scope = portal.scope;
        var state = portal.state;
        var videos = state.videos || [];
        var readyCount = videos.filter(function (video) { return video.status === "ready"; }).length;
        var activeCount = videos.filter(function (video) { return video.status === "processing" || video.status === "queued"; }).length;
        var totalStorage = videos.reduce(function (sum, video) { return sum + Number(video.processed_size_bytes || 0); }, 0);
        var limitLabel = state.capabilities && state.capabilities.max_upload_human ? state.capabilities.max_upload_human : "Server default";

        var libraryBody = "";

        if (!auth) {
            libraryBody = [
                '<div class="ve-empty-state">',
                '<strong>Sign in to upload videos</strong>',
                '<p class="ve-video-message">Uploads are compressed into protected HLS streams and only become playable through signed player sessions.</p>',
                '</div>'
            ].join("");
        } else if (!videos.length && !state.loading) {
            libraryBody = '<div class="ve-empty-state">No videos yet. Upload one and the dashboard will track compression, storage usage and playback status here.</div>';
        } else if (state.loading && !videos.length) {
            libraryBody = '<div class="ve-empty-state">Loading video library...</div>';
        } else {
            libraryBody = videos.map(function (video) {
                var saved = video.space_saved_percent !== null && video.space_saved_percent !== undefined
                    ? Math.max(0, Number(video.space_saved_percent)).toFixed(1) + "% saved"
                    : "Compression pending";
                var messageClass = video.status === "failed" ? "ve-video-message is-error" : "ve-video-message";
                var messageText = video.status === "failed"
                    ? (video.error || "The video could not be processed.")
                    : (video.status_message || "");

                return [
                    '<article class="ve-video-item">',
                    '<div>',
                    '<h3>' + escapeHtml(video.title) + '</h3>',
                    '<div class="ve-video-meta">',
                    '<span>' + escapeHtml(formatDuration(video.duration_seconds)) + '</span>',
                    '<span>' + escapeHtml(formatBytes(video.processed_size_bytes || video.original_size_bytes || 0)) + '</span>',
                    '<span>' + escapeHtml(saved) + '</span>',
                    '<span>' + escapeHtml(formatDate(video.created_at)) + '</span>',
                    '</div>',
                    '<div class="ve-video-status">',
                    '<span class="ve-status-pill ' + escapeHtml(video.status) + '">' + escapeHtml(video.status) + '</span>',
                    '</div>',
                    '<div class="' + messageClass + '">' + escapeHtml(messageText) + '</div>',
                    '</div>',
                    '<div class="ve-video-actions">',
                    '<a class="ve-link-button text-center" href="' + escapeHtml(appUrl(video.watch_url)) + '" target="_blank" rel="noopener">Watch</a>',
                    '<a class="ve-link-button text-center" href="' + escapeHtml(appUrl(video.embed_url)) + '" target="_blank" rel="noopener">Embed</a>',
                    '<button class="ve-danger-button" type="button" data-delete-video="' + escapeHtml(video.public_id) + '">Delete</button>',
                    '</div>',
                    '</article>'
                ].join("");
            }).join("");
        }

        root.innerHTML = [
            '<div class="ve-portal-shell">',
            '<div class="ve-portal-grid">',
            '<section class="ve-upload-card">',
            '<h2>' + (scope === "home" ? "Upload & Compress" : "New Video") + '</h2>',
            '<p class="ve-card-copy">Upload common video containers, compress them once and stream them back as token-protected HLS instead of exposing a raw MP4 file.</p>',
            '<form class="ve-upload-form">',
            '<label class="ve-upload-zone">',
            '<strong class="ve-file-name">' + escapeHtml(portal.selectedFileName || "Drop a video here or choose a file") + '</strong>',
            '<span>Supported formats: MP4, MKV, MOV, AVI, WMV, WEBM, MPEG, M4V, FLV, 3GP and similar containers.</span>',
            '<input type="file" name="video" accept="video/*">',
            '</label>',
            '<div class="ve-upload-actions">',
            '<input type="text" name="title" placeholder="Video title (optional)">',
            '<button class="ve-primary-button" type="submit"' + (portal.submitting ? " disabled" : "") + '>' + (auth ? (portal.submitting ? "Uploading..." : "Upload & compress") : "Sign in to upload") + '</button>',
            '</div>',
            '<div class="ve-upload-meta">Upload limit: ' + escapeHtml(limitLabel) + '. Encoding runs one job at a time with tuned compression to keep CPU, storage and bandwidth predictable at scale.</div>',
            (portal.feedback ? '<div class="ve-upload-feedback' + (portal.feedbackError ? ' is-error' : '') + '">' + escapeHtml(portal.feedback) + '</div>' : ''),
            '</form>',
            '</section>',
            '<section class="ve-library-card">',
            '<h2>' + (auth ? "Video Library" : "Secure Delivery") + '</h2>',
            '<p class="ve-card-copy">' + (auth ? "Your videos are listed with processing state, storage footprint and secure watch/embed pages." : "The player fetches short-lived manifests and encrypted chunks, so the network tab does not expose a normal downloadable MP4 URL.") + '</p>',
            (auth ? [
                '<div class="ve-stats-row">',
                '<div class="ve-stat"><strong>' + readyCount + '</strong><span>Ready videos</span></div>',
                '<div class="ve-stat"><strong>' + activeCount + '</strong><span>In queue / processing</span></div>',
                '<div class="ve-stat"><strong>' + escapeHtml(formatBytes(totalStorage)) + '</strong><span>Current storage usage</span></div>',
                '</div>'
            ].join("") : ""),
            '<div class="ve-video-list">' + libraryBody + '</div>',
            '</section>',
            '</div>',
            '</div>'
        ].join("");

        bindPortal(root, portal);
    }

    function bindPortal(root, portal) {
        var fileInput = root.querySelector('input[type="file"][name="video"]');
        var titleInput = root.querySelector('input[name="title"]');
        var form = root.querySelector(".ve-upload-form");

        if (fileInput) {
            fileInput.addEventListener("change", function () {
                var file = fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
                portal.selectedFileName = file ? file.name : "";

                if (file && titleInput && !titleInput.value.trim()) {
                    titleInput.value = file.name.replace(/\.[^.]+$/, "").replace(/[_\-.]+/g, " ").trim();
                }

                renderPortal(portal);
            });
        }

        if (form) {
            form.addEventListener("submit", function (event) {
                event.preventDefault();

                if (!portal.auth) {
                    openLoginModal();
                    return;
                }

                var activeFileInput = portal.root.querySelector('input[type="file"][name="video"]');
                var activeTitleInput = portal.root.querySelector('input[name="title"]');
                var file = activeFileInput && activeFileInput.files ? activeFileInput.files[0] : null;

                if (!file) {
                    portal.feedback = "Choose a video file first.";
                    portal.feedbackError = true;
                    renderPortal(portal);
                    return;
                }

                uploadVideo(portal, file, activeTitleInput ? activeTitleInput.value.trim() : "");
            });
        }

        root.querySelectorAll("[data-delete-video]").forEach(function (button) {
            button.addEventListener("click", function () {
                var publicId = button.getAttribute("data-delete-video");

                if (!publicId || !window.confirm("Delete this video and all generated stream files?")) {
                    return;
                }

                deleteVideo(portal, publicId);
            });
        });
    }

    function uploadVideo(portal, file, title) {
        var formData = new FormData();
        formData.append("token", csrfToken);
        formData.append("video", file);

        if (title) {
            formData.append("title", title);
        }

        portal.submitting = true;
        portal.feedback = "";
        portal.feedbackError = false;
        renderPortal(portal);

        request("/api/videos/upload", {
            method: "POST",
            body: formData,
            headers: {}
        }).then(function (payload) {
            portal.submitting = false;
            portal.selectedFileName = "";
            portal.feedback = payload.message || "Upload accepted.";
            portal.feedbackError = false;
            loadVideos(portal, true);
        }).catch(function (error) {
            portal.submitting = false;
            portal.feedback = (error && error.message) || "Upload failed.";
            portal.feedbackError = true;
            renderPortal(portal);
        });
    }

    function deleteVideo(portal, publicId) {
        request("/api/videos/" + encodeURIComponent(publicId), {
            method: "DELETE"
        }).then(function (payload) {
            portal.feedback = payload.message || "Video deleted.";
            portal.feedbackError = false;
            loadVideos(portal, true);
        }).catch(function (error) {
            portal.feedback = (error && error.message) || "Could not delete the video.";
            portal.feedbackError = true;
            renderPortal(portal);
        });
    }

    function loadVideos(portal, silent) {
        if (!portal.auth) {
            portal.state.loading = false;
            renderPortal(portal);
            return;
        }

        if (!silent) {
            portal.state.loading = true;
            renderPortal(portal);
        }

        request("/api/videos").then(function (payload) {
            portal.state.loading = false;
            portal.state.videos = Array.isArray(payload.videos) ? payload.videos : [];
            portal.state.capabilities = payload.capabilities || {};
            renderPortal(portal);
        }).catch(function (error) {
            portal.state.loading = false;
            portal.feedback = (error && error.message) || "Could not load videos.";
            portal.feedbackError = true;
            renderPortal(portal);
        });
    }

    function initPortal(root) {
        var portal = {
            root: root,
            auth: root.getAttribute("data-auth") === "1",
            scope: root.getAttribute("data-scope") || "dashboard",
            selectedFileName: "",
            feedback: "",
            feedbackError: false,
            submitting: false,
            state: {
                loading: true,
                videos: [],
                capabilities: {}
            }
        };

        portals.push(portal);
        renderPortal(portal);
        loadVideos(portal, false);
    }

    document.querySelectorAll(".ve-video-portal").forEach(initPortal);

    window.setInterval(function () {
        portals.forEach(function (portal) {
            if (!portal.auth) {
                return;
            }

            var hasActive = (portal.state.videos || []).some(function (video) {
                return video.status === "queued" || video.status === "processing";
            });

            if (hasActive) {
                loadVideos(portal, true);
            }
        });
    }, 8000);
}());
