function veAppUrl(path) {
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

    return basePath + path;
}

function veCsrfToken() {
    return window.VE_CSRF_TOKEN || '';
}

$(document).ready(function() {
    var pathname = window.location.pathname;
    var l_first = true;

    function isLogoutHref(href) {
        var logoutUrl = veAppUrl('/logout');

        if (!href) {
            return false;
        }

        return href === logoutUrl || href === '/logout';
    }

    $('.sidebar .nav .nav-item .nav-link').each(function() {
        var path = $(this).attr('href');

        if (pathname == path) {
            $(this).addClass('active');
        }
    });

    if ($.fn.tooltip) {
        $('[data-toggle="tooltip"]').tooltip();
    }

    $('.settings_menu li a').on('click', function(e) {
        e.preventDefault();

        var $this = $(this),
            is_ajax = $this.data('ajax'),
            target = $this.attr('href'),
            link = $this.data('url');

        $('.settings_menu li a').not($this).removeClass('active');
        $this.addClass('active');
        $('.settings_data .data').not($(target)).removeClass('active');
        $(target).addClass('active');

        if (is_ajax) {
            $(target).load(link, function() {
                // feather.replace();
            });
        }

        $('html, body').animate({
            scrollTop: $(target).offset().top
        }, 2000);
    });

    $(document).on('submit', '.js_auth', function(e) {
        e.preventDefault();

        var _form = $(this),
            op = _form.find('input[name="op"]').val(),
            login = _form.find('input[name="login"]').val(),
            password = _form.find('input[name="password"]').val(),
            alerts = _form.find('.alert'),
            submit = _form.find('button[type="submit"]'),
            loginotp = _form.find('input[name="loginotp"]').val(),
            url,
            formData,
            button;

        if (l_first) {
            loginotp = '';
        }

        if (op == 'login_ajax') {
            url = veAppUrl('/api/auth/login');
            formData = {
                'login': login,
                'password': password,
                'loginotp': loginotp,
                'token': veCsrfToken()
            };
            button = 'Login <i class="fad fa-arrow-right ml-2"></i>';
        } else if (op == 'register_save') {
            url = veAppUrl('/api/auth/register');
            formData = {
                'usr_login': _form.find('input[name="usr_login"]').val(),
                'usr_email': _form.find('input[name="usr_email"]').val(),
                'usr_password': _form.find('input[name="usr_password"]').val(),
                'usr_password2': _form.find('input[name="usr_password2"]').val(),
                'token': veCsrfToken()
            };
            button = 'Sign up <i class="fad fa-arrow-right ml-2"></i>';
        } else if (op == 'forgot_pass') {
            url = veAppUrl('/api/auth/forgot');
            formData = {
                'usr_login': _form.find('input[name="usr_login"]').val(),
                'token': veCsrfToken()
            };
            button = 'Send me instructions <i class="fad fa-arrow-right ml-2"></i>';
        } else if (op == 'reset_pass') {
            url = veAppUrl('/api/auth/reset');
            formData = {
                'sess_id': _form.find('input[name="sess_id"]').val(),
                'password': _form.find('input[name="password"]').val(),
                'password2': _form.find('input[name="password2"]').val(),
                'token': veCsrfToken()
            };
            button = 'Reset password <i class="fad fa-arrow-right ml-2"></i>';
        } else {
            return;
        }

        if (alerts.length) {
            alerts.remove();
        }

        submit.prop('disabled', true).addClass('loading disabled').html('<img src="/assets/img/loader.svg">');

        $.ajax({
            type: 'post',
            url: url,
            data: formData,
            dataType: 'json',
            success: function(response) {
                console.log(response);

                if (response.status == 'fail') {
                    _form.prepend('<div class="alert alert-danger mb-4">' + response.message + '</div>');
                }

                if (response.status == 'otp_sent') {
                    _form.prepend('<div class="alert alert-success mb-3">' + response.message + ' </br> If you did not receive OTP <a href="' + veAppUrl('/contact') + '" style="color:#ff9a00;">Contact us</a></div>');
                    $('.reqOTP').show();
                    l_first = false;
                }

                if (response.status == 'ok') {
                    _form.html('<div class="alert alert-success mb-4">' + response.message + '</div><hr><div class="action"><a href="#login" data-dismiss="modal" data-toggle="modal" data-target="#login" class="btn btn-default btn-block">Login<i class="fad fa-arrow-right ml-2"></i></a></div>');
                }

                if (response.msg) {
                    _form.prepend('<div class="alert alert-danger mb-4">' + response.msg + '</div>');
                }

                if (response.status == 'redirect') {
                    window.location.href = response.message;
                    return;
                }

                submit.prop('disabled', false).removeClass('loading disabled').html(button);
            },
            error: function(xhr) {
                if (xhr.readyState == 4) {
                    window.location.href = veAppUrl('/dashboard');
                }
            }
        });
    });

    getNotifications();
    $(document).on('click', '.open-notification', function(e) {
        e.preventDefault();
        var subject = $(this).data('subject'),
            message = $(this).data('message'),
            date = $(this).data('date'),
            id = $(this).data('id'),
            isRead = Number($(this).data('read')) === 1;

        $('#notifications .modal-content').html('<div class="modal-header">\n' +
            '                    <h5 class="modal-title" id="notificationsLabel">' + escapeHtml(subject) + '</h5>\n' +
            '                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">\n' +
            '                        <span aria-hidden="true">&times;</span>\n' +
            '                    </button>\n' +
            '                </div>\n' +
            '                <div class="modal-body text-dark"><p>\n' +
            escapeHtml(message) +
            '                    </p><small class="d-block font-weight-bold text-muted">\n' +
            escapeHtml(date) +
            '                        </small>\n' +
            '                </div>\n' +
            '                <div class="modal-footer justify-content-between">\n' +
            '                    <button type="button" class="btn btn-outline-danger notification-delete" data-id="' + id + '">\n' +
            '                        <i class="fad fa-trash-alt mr-2"></i>Delete\n' +
            '                    </button>\n' +
            '                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>\n' +
            '                </div>');
        $('#notifications').modal('show');

        if (!isRead) {
            markNotificationRead(id);
        }
    });

    $(document).on('click', '.notification-delete', function(e) {
        e.preventDefault();
        e.stopPropagation();
        deleteNotification($(this).data('id'), true);
    });

    $(document).on('click', '.notification-clear-all', function(e) {
        e.preventDefault();
        clearNotifications();
    });

    $(document).on('click', 'a.logout, a[href="/logout"], a[href$="/logout"]', function(e) {
        var href = $(this).attr('href') || '';

        if (!isLogoutHref(href)) {
            return;
        }

        e.preventDefault();

        var form = $('<form method="POST" action="' + veAppUrl('/api/auth/logout') + '"></form>');
        form.append('<input type="hidden" name="token" value="' + veCsrfToken() + '">');
        $('body').append(form);
        form.trigger('submit');
    });
});

function removeTags(value) {
    return value.replace(/(<([^>]+)>)/ig, '');
}

function escapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, function(character) {
        return {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        }[character];
    });
}

function truncateText(value, limit) {
    if (value.length > limit) {
        value = value.substring(0, limit - 3) + '...';
    }

    return value;
}

function renderNotifications(response) {
    var items = Array.isArray(response) ? response : [];
    var totalUnread = items.filter(function(item) {
        return item.read == 0;
    }).length;
    var headerHtml = '<div class="title d-flex flex-wrap align-items-center justify-content-between"><span>Notifications</span>';

    if (items.length > 0) {
        headerHtml += '<button type="button" class="btn btn-link p-0 text-muted notification-clear-all">Clear all</button>';
    }

    headerHtml += '</div>';

    $('.dropdown.notifications .count').remove();

    $('.dropdown.notifications .notifications-box').each(function() {
        var boxHtml = headerHtml;

        if (items.length > 0) {
            var listHtml = '<ul class="notifications-list m-0 p-0">';

            $.each(items, function(i) {
                var notification = items[i];
                var itemClass = notification.read == 0 ? 'position-relative new' : 'position-relative';
                var readIcon = notification.read == 0 ? '<i class="fad fa-envelope"></i> Unread' : '<i class="fad fa-envelope-open"></i> Read';

                listHtml += '<li class="' + itemClass + '"><div class="d-flex align-items-start justify-content-between"><a href="#" class="description open-notification flex-grow-1 mr-3" data-date="' + escapeHtml(notification.cr) + '" data-message="' + escapeHtml(notification.message) + '" data-subject="' + escapeHtml(notification.subject) + '" data-id="' + notification.id + '" data-read="' + notification.read + '"><strong>' + escapeHtml(notification.subject) + '</strong><p class="mb-1">' + escapeHtml(truncateText(removeTags(String(notification.message || '')), 65)) + '</p><span><i class="fad fa-clock"></i> ' + escapeHtml(notification.cr) + '<i class="d-inline-block mx-2"></i>' + readIcon + '</span></a><button type="button" class="btn btn-link text-danger p-0 notification-delete" data-id="' + notification.id + '" aria-label="Delete notification"><i class="fad fa-trash-alt"></i></button></div></li>';
            });

            listHtml += '</ul>';
            boxHtml += listHtml;
        } else {
            boxHtml += '<div class="empty p-3 text-center text-muted font-weight-bold">No notifications</div>';
        }

        $(this).html(boxHtml);
    });

    if (totalUnread > 0) {
        $('.dropdown.notifications .nav-link.dropdown-toggle').append('<span class="count">' + totalUnread + '</span>');
    }
}

function getNotifications() {
    $.ajax({
        type: 'get',
        url: veAppUrl('/api/notifications'),
        dataType: 'json',
        success: function(response) {
            renderNotifications(response);
        }
    });
}

function markNotificationRead(notificationId) {
    return $.ajax({
        type: 'post',
        url: veAppUrl('/api/notifications/' + encodeURIComponent(notificationId) + '/read'),
        dataType: 'json',
        data: {
            token: veCsrfToken()
        },
        complete: function() {
            getNotifications();
        }
    });
}

function deleteNotification(notificationId, closeModal) {
    return $.ajax({
        type: 'DELETE',
        url: veAppUrl('/api/notifications/' + encodeURIComponent(notificationId)),
        dataType: 'json',
        data: {
            token: veCsrfToken()
        },
        headers: {
            'X-CSRF-Token': veCsrfToken()
        },
        complete: function() {
            if (closeModal) {
                $('#notifications').modal('hide');
            }

            getNotifications();
        }
    });
}

function clearNotifications() {
    return $.ajax({
        type: 'DELETE',
        url: veAppUrl('/api/notifications'),
        dataType: 'json',
        data: {
            token: veCsrfToken()
        },
        headers: {
            'X-CSRF-Token': veCsrfToken()
        },
        complete: function() {
            $('#notifications').modal('hide');
            getNotifications();
        }
    });
}
