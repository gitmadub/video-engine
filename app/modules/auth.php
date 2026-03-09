<?php

declare(strict_types=1);

function ve_handle_login_ajax(): void
{
    ve_require_csrf(ve_request_csrf_token());
    $login = trim((string) ($_POST['login'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($login === '' || $password === '') {
        ve_json([
            'status' => 'fail',
            'message' => 'Enter your username/email and password.',
        ]);
    }

    $user = ve_find_user_by_login($login);

    if (!is_array($user) || $user['status'] !== 'active' || $user['deleted_at'] !== null || !password_verify($password, (string) $user['password_hash'])) {
        ve_json([
            'status' => 'fail',
            'message' => 'Invalid login credentials.',
        ]);
    }

    ve_login_user($user);
    ve_add_notification((int) $user['id'], 'New login', 'A new dashboard session was started successfully.');
    ve_json([
        'status' => 'redirect',
        'message' => ve_url('/dashboard'),
    ]);
}

function ve_handle_registration_ajax(): void
{
    ve_require_csrf(ve_request_csrf_token());
    $username = trim((string) ($_POST['usr_login'] ?? ''));
    $email = strtolower(trim((string) ($_POST['usr_email'] ?? '')));
    $password = (string) ($_POST['usr_password'] ?? '');
    $password2 = (string) ($_POST['usr_password2'] ?? '');

    $error = ve_validate_username($username)
        ?? ve_validate_email($email)
        ?? ve_validate_password($password, $password2);

    if ($error !== null) {
        ve_json([
            'status' => 'fail',
            'message' => $error,
        ]);
    }

    if (ve_find_user_by_login($username) !== null) {
        ve_json([
            'status' => 'fail',
            'message' => 'That username is already taken.',
        ]);
    }

    $stmt = ve_db()->prepare('SELECT id FROM users WHERE lower(email) = lower(:email) LIMIT 1');
    $stmt->execute([':email' => $email]);

    if ($stmt->fetchColumn() !== false) {
        ve_json([
            'status' => 'fail',
            'message' => 'That email address is already in use.',
        ]);
    }

    $user = ve_create_user($username, $email, $password);

    if (is_array($user) && function_exists('ve_referral_apply_pending_to_user')) {
        ve_referral_apply_pending_to_user((int) $user['id']);
    }

    ve_json([
        'status' => 'ok',
        'message' => 'Registration completed. You can log in now.',
    ]);
}

function ve_get_valid_reset_token(string $rawToken): ?array
{
    $stmt = ve_db()->prepare(
        'SELECT * FROM password_reset_tokens
         WHERE token_hash = :token_hash AND used_at IS NULL AND expires_at >= :now
         ORDER BY id DESC
         LIMIT 1'
    );
    $stmt->execute([
        ':token_hash' => hash('sha256', $rawToken),
        ':now' => ve_now(),
    ]);
    $token = $stmt->fetch();

    return is_array($token) ? $token : null;
}

function ve_handle_forgot_password_ajax(): void
{
    ve_require_csrf(ve_request_csrf_token());
    $token = trim((string) ($_POST['sess_id'] ?? ''));

    if ($token !== '') {
        $password = (string) ($_POST['password'] ?? '');
        $password2 = (string) ($_POST['password2'] ?? '');
        $error = ve_validate_password($password, $password2);

        if ($error !== null) {
            ve_json([
                'status' => 'fail',
                'message' => $error,
            ]);
        }

        $reset = ve_get_valid_reset_token($token);

        if (!is_array($reset)) {
            ve_json([
                'status' => 'fail',
                'message' => 'This password reset link is invalid or expired.',
            ]);
        }

        $stmt = ve_db()->prepare('UPDATE users SET password_hash = :password_hash, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ':updated_at' => ve_now(),
            ':id' => (int) $reset['user_id'],
        ]);

        $consume = ve_db()->prepare('UPDATE password_reset_tokens SET used_at = :used_at WHERE id = :id');
        $consume->execute([
            ':used_at' => ve_now(),
            ':id' => (int) $reset['id'],
        ]);

        ve_add_notification((int) $reset['user_id'], 'Password updated', 'Your account password was reset successfully.');

        ve_json([
            'status' => 'ok',
            'message' => 'Password updated successfully. You can log in now.',
        ]);
    }

    $login = trim((string) ($_POST['usr_login'] ?? ''));

    if ($login === '') {
        ve_json([
            'status' => 'fail',
            'message' => 'Enter your username or email address.',
        ]);
    }

    $user = ve_find_user_by_login($login);

    if (!is_array($user) || $user['deleted_at'] !== null) {
        ve_json([
            'status' => 'ok',
            'message' => 'If the account exists, password reset instructions were generated.',
        ]);
    }

    $rawToken = ve_random_token(24);
    $stmt = ve_db()->prepare(
        'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, used_at, created_at)
         VALUES (:user_id, :token_hash, :expires_at, NULL, :created_at)'
    );
    $stmt->execute([
        ':user_id' => (int) $user['id'],
        ':token_hash' => hash('sha256', $rawToken),
        ':expires_at' => gmdate('Y-m-d H:i:s', ve_timestamp() + 3600),
        ':created_at' => ve_now(),
    ]);

    $resetUrl = ve_absolute_url('/reset-password?token=' . rawurlencode($rawToken));
    ve_add_notification((int) $user['id'], 'Password reset requested', 'A password reset link was generated for your account.');

    ve_json([
        'status' => 'ok',
        'message' => 'Reset link generated. <a href="' . ve_h($resetUrl) . '">Open the password reset page</a>.',
    ]);
}
