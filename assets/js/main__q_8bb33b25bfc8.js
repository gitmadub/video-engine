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

$(document).ready(function() {
    var pathname = window.location.pathname;
    var l_first = true;

    $('.sidebar .nav .nav-item .nav-link').each(function() {
        var path = $(this).attr('href');

        if (pathname == path) {
            $(this).addClass('active');
        }
    });

    $('[data-toggle="tooltip"]').tooltip();

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
            url = veAppUrl('/?op=login_ajax');
            formData = {
                'login': login,
                'password': password,
                'loginotp': loginotp
            };
            button = 'Login <i class="fad fa-arrow-right ml-2"></i>';
        } else if (op == 'register_save') {
            url = veAppUrl('/?op=registration_ajax');
            formData = {
                'usr_login': _form.find('input[name="usr_login"]').val(),
                'usr_email': _form.find('input[name="usr_email"]').val(),
                'usr_password': _form.find('input[name="usr_password"]').val(),
                'usr_password2': _form.find('input[name="usr_password2"]').val()
            };
            button = 'Sign up <i class="fad fa-arrow-right ml-2"></i>';
        } else if (op == 'forgot_pass') {
            url = veAppUrl('/?op=forgot_pass_ajax');
            formData = {
                'usr_login': _form.find('input[name="usr_login"]').val()
            };
            button = 'Send me instructions <i class="fad fa-arrow-right ml-2"></i>';
        } else if (op == 'reset_pass') {
            url = veAppUrl('/?op=forgot_pass_ajax');
            formData = {
                'sess_id': _form.find('input[name="sess_id"]').val(),
                'password': _form.find('input[name="password"]').val(),
                'password2': _form.find('input[name="password2"]').val()
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
            type: 'get',
            url: url,
            data: formData,
            dataType: 'json',
            cache: false,
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
    $(document).on('click', '#openNotification', function(e) {
        e.preventDefault();
        var subject = $(this).data('subject'),
            message = $(this).data('message'),
            date = $(this).data('date'),
            id = $(this).data('id');

        $('#notifications .modal-content').html('<div class="modal-header">\n' +
            '                    <h5 class="modal-title" id="notificationsLabel">' + subject + '</h5>\n' +
            '                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">\n' +
            '                        <span aria-hidden="true">&times;</span>\n' +
            '                    </button>\n' +
            '                </div>\n' +
            '                <div class="modal-body text-dark"><p>\n' +
            message +
            '                    </p><small class="d-block font-weight-bold text-muted">\n' +
            date +
            '                        </small>\n' +
            '                </div>');
        $('#notifications').modal('show');

        getNotifications(id);
    });
});

function removeTags(value) {
    return value.replace(/(<([^>]+)>)/ig, '');
}

function truncateText(value, limit) {
    if (value.length > limit) {
        value = value.substring(0, limit - 3) + '...';
    }

    return value;
}

function getNotifications(open_msg = '') {
    $('.dropdown.notifications #notifications .count, .dropdown.notifications .notifications-box .empty').remove();
    $('.dropdown.notifications .notifications-list').html('');

    var url = veAppUrl('/?op=notifications'),
        formData = {
            'open': open_msg
        };

    $.ajax({
        type: 'get',
        url: url,
        data: formData,
        dataType: 'json',
        cache: false,
        success: function(response) {
            if (response.length > 0) {
                $.each(response, function(i) {
                    var _class;
                    var _readIcon;

                    if (response[i].read == 0) {
                        _class = 'position-relative new';
                        _readIcon = '<i class="fad fa-envelope"></i> Unread';
                    } else {
                        _class = 'position-relative';
                        _readIcon = '<i class="fad fa-envelope-open"></i> Read';
                    }

                    $('.dropdown.notifications .notifications-list').append('<li class="' + _class + '"><a href="#" id="openNotification" class="description" data-date="' + response[i].cr + '" data-message="' + response[i].message + '" data-subject="' + response[i].subject + '" data-id="' + response[i].id + '"><strong>' + response[i].subject + '</strong><p class="mb-1">' + truncateText(removeTags(response[i].message), 65) + '</p><span><i class="fad fa-clock"></i> ' + response[i].cr + '<i class="d-inline-block mx-2"></i>' + _readIcon + '</span></a></li>');
                });
            } else {
                $('.dropdown.notifications .notifications-box').html('<div class="empty p-3 text-center text-muted font-weight-bold">No notifications</div>');
            }

            var total_unread = response.filter(function(item) {
                return item.read == 0;
            });

            if (total_unread.length > 0) {
                $('.dropdown.notifications #notifications').append('<span class="count">' + total_unread.length + '</span>');
            }
        }
    });
}
