<?php

declare(strict_types=1);

const VE_DMCA_NOTICE_STATUS_PENDING_REVIEW = 'pending_review';
const VE_DMCA_NOTICE_STATUS_CONTENT_DISABLED = 'content_disabled';
const VE_DMCA_NOTICE_STATUS_COUNTER_SUBMITTED = 'counter_submitted';
const VE_DMCA_NOTICE_STATUS_RESPONSE_SUBMITTED = 'response_submitted';
const VE_DMCA_NOTICE_STATUS_RESTORED = 'restored';
const VE_DMCA_NOTICE_STATUS_REJECTED = 'rejected';
const VE_DMCA_NOTICE_STATUS_WITHDRAWN = 'withdrawn';
const VE_DMCA_NOTICE_STATUS_UPLOADER_DELETED = 'uploader_deleted';
const VE_DMCA_NOTICE_STATUS_AUTO_DELETED = 'auto_deleted';

const VE_DMCA_PAGE_SIZE = 10;

function ve_dmca_policy_snapshot(): array
{
    return [
        'dmca_email' => 'dmca@doodstream.com',
        'response_window_hours' => 24,
        'uploader_response_optional' => true,
    ];
}

function ve_dmca_notice_status_catalog(): array
{
    static $catalog;

    if (is_array($catalog)) {
        return $catalog;
    }

    $catalog = [
        VE_DMCA_NOTICE_STATUS_PENDING_REVIEW => [
            'label' => 'Under review',
            'tone' => 'secondary',
            'open' => true,
            'keeps_disabled' => false,
            'deletes_video' => false,
        ],
        VE_DMCA_NOTICE_STATUS_CONTENT_DISABLED => [
            'label' => 'Removal window open',
            'tone' => 'warning',
            'open' => true,
            'keeps_disabled' => true,
            'deletes_video' => false,
        ],
        VE_DMCA_NOTICE_STATUS_COUNTER_SUBMITTED => [
            'label' => 'Info sent',
            'tone' => 'info',
            'open' => true,
            'keeps_disabled' => false,
            'deletes_video' => false,
        ],
        VE_DMCA_NOTICE_STATUS_RESPONSE_SUBMITTED => [
            'label' => 'Info sent',
            'tone' => 'info',
            'open' => true,
            'keeps_disabled' => false,
            'deletes_video' => false,
        ],
        VE_DMCA_NOTICE_STATUS_RESTORED => [
            'label' => 'Restored',
            'tone' => 'success',
            'open' => false,
            'keeps_disabled' => false,
            'deletes_video' => false,
        ],
        VE_DMCA_NOTICE_STATUS_REJECTED => [
            'label' => 'Rejected',
            'tone' => 'secondary',
            'open' => false,
            'keeps_disabled' => false,
            'deletes_video' => false,
        ],
        VE_DMCA_NOTICE_STATUS_WITHDRAWN => [
            'label' => 'Withdrawn',
            'tone' => 'secondary',
            'open' => false,
            'keeps_disabled' => false,
            'deletes_video' => false,
        ],
        VE_DMCA_NOTICE_STATUS_UPLOADER_DELETED => [
            'label' => 'Deleted by you',
            'tone' => 'secondary',
            'open' => false,
            'keeps_disabled' => false,
            'deletes_video' => true,
        ],
        VE_DMCA_NOTICE_STATUS_AUTO_DELETED => [
            'label' => 'Auto deleted',
            'tone' => 'secondary',
            'open' => false,
            'keeps_disabled' => false,
            'deletes_video' => true,
        ],
    ];

    return $catalog;
}

function ve_dmca_notice_status_meta(string $status): array
{
    $catalog = ve_dmca_notice_status_catalog();
    return $catalog[$status] ?? $catalog[VE_DMCA_NOTICE_STATUS_PENDING_REVIEW];
}

function ve_dmca_notice_is_open(string $status): bool
{
    return (bool) (ve_dmca_notice_status_meta($status)['open'] ?? false);
}

function ve_dmca_notice_keeps_disabled(string $status): bool
{
    return (bool) (ve_dmca_notice_status_meta($status)['keeps_disabled'] ?? false);
}

function ve_dmca_notice_deletes_video(string $status): bool
{
    return (bool) (ve_dmca_notice_status_meta($status)['deletes_video'] ?? false);
}

function ve_dmca_generate_case_code(): string
{
    return 'DMCA-' . gmdate('Ymd') . '-' . strtoupper(substr(ve_random_token(6), 0, 8));
}

function ve_dmca_add_hours(string $timestamp, int $hours): string
{
    $date = new DateTimeImmutable($timestamp, new DateTimeZone('UTC'));
    return $date->modify('+' . max(0, $hours) . ' hours')->format('Y-m-d H:i:s');
}

function ve_dmca_seconds_until(string $timestamp): ?int
{
    $timestamp = trim($timestamp);

    if ($timestamp === '') {
        return null;
    }

    $deadline = strtotime($timestamp);

    if ($deadline === false) {
        return null;
    }

    return max(0, $deadline - ve_timestamp());
}

function ve_dmca_format_remaining_label(?int $seconds): string
{
    if ($seconds === null) {
        return 'No deadline';
    }

    if ($seconds <= 0) {
        return 'Due now';
    }

    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);

    if ($hours > 0) {
        return $hours . 'h ' . $minutes . 'm left';
    }

    return max(1, $minutes) . 'm left';
}

function ve_dmca_evidence_urls_json($value): string
{
    $urls = [];

    if (is_array($value)) {
        foreach ($value as $item) {
            if (!is_string($item)) {
                continue;
            }

            $item = trim($item);

            if ($item !== '') {
                $urls[] = $item;
            }
        }
    } elseif (is_string($value)) {
        foreach (preg_split('/\r\n|\r|\n|,/', $value) ?: [] as $item) {
            $item = trim((string) $item);

            if ($item !== '') {
                $urls[] = $item;
            }
        }
    }

    $encoded = json_encode(array_values(array_unique($urls)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return is_string($encoded) ? $encoded : '[]';
}

function ve_dmca_decode_evidence_urls($value): array
{
    if (!is_string($value) || trim($value) === '') {
        return [];
    }

    $decoded = json_decode($value, true);

    if (!is_array($decoded)) {
        return [];
    }

    return array_values(array_filter($decoded, static fn ($item): bool => is_string($item) && trim($item) !== ''));
}

function ve_dmca_response_json($value): string
{
    if (!is_array($value)) {
        $value = [];
    }

    $payload = [
        'contact_email' => trim((string) ($value['contact_email'] ?? '')),
        'contact_phone' => trim((string) ($value['contact_phone'] ?? '')),
        'notes' => trim((string) ($value['notes'] ?? '')),
    ];

    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return is_string($encoded) ? $encoded : '{}';
}

function ve_dmca_decode_response($value): ?array
{
    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    $decoded = json_decode($value, true);

    if (!is_array($decoded)) {
        return null;
    }

    return [
        'contact_email' => trim((string) ($decoded['contact_email'] ?? '')),
        'contact_phone' => trim((string) ($decoded['contact_phone'] ?? '')),
        'notes' => trim((string) ($decoded['notes'] ?? '')),
    ];
}

function ve_dmca_notice_by_id(int $noticeId): ?array
{
    $stmt = ve_db()->prepare('SELECT * FROM dmca_notices WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $noticeId]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

function ve_dmca_notice_by_case_code(int $userId, string $caseCode): ?array
{
    $stmt = ve_db()->prepare(
        'SELECT * FROM dmca_notices
         WHERE user_id = :user_id AND case_code = :case_code
         LIMIT 1'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':case_code' => $caseCode,
    ]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

function ve_dmca_notice_events(int $noticeId): array
{
    $stmt = ve_db()->prepare(
        'SELECT event_type, title, body, created_at
         FROM dmca_notice_events
         WHERE notice_id = :notice_id
         ORDER BY created_at ASC, id ASC'
    );
    $stmt->execute([':notice_id' => $noticeId]);
    $rows = $stmt->fetchAll();
    $events = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $events[] = [
            'event_type' => (string) ($row['event_type'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'body' => (string) ($row['body'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'created_label' => ve_format_datetime_label((string) ($row['created_at'] ?? '')),
        ];
    }

    return $events;
}

function ve_dmca_log_event(int $noticeId, string $eventType, string $title, string $body = '', ?string $createdAt = null): void
{
    $timestamp = is_string($createdAt) && trim($createdAt) !== '' ? $createdAt : ve_now();

    ve_db()->prepare(
        'INSERT INTO dmca_notice_events (notice_id, event_type, title, body, created_at)
         VALUES (:notice_id, :event_type, :title, :body, :created_at)'
    )->execute([
        ':notice_id' => $noticeId,
        ':event_type' => $eventType,
        ':title' => $title,
        ':body' => $body,
        ':created_at' => $timestamp,
    ]);
}

function ve_dmca_notification_subject(string $status): string
{
    return match ($status) {
        VE_DMCA_NOTICE_STATUS_CONTENT_DISABLED => 'DMCA complaint received',
        VE_DMCA_NOTICE_STATUS_RESPONSE_SUBMITTED,
        VE_DMCA_NOTICE_STATUS_COUNTER_SUBMITTED => 'Uploader response received',
        VE_DMCA_NOTICE_STATUS_UPLOADER_DELETED => 'Video deleted from DMCA manager',
        VE_DMCA_NOTICE_STATUS_AUTO_DELETED => 'DMCA video auto deleted',
        VE_DMCA_NOTICE_STATUS_RESTORED => 'DMCA video restored',
        VE_DMCA_NOTICE_STATUS_REJECTED => 'DMCA complaint rejected',
        VE_DMCA_NOTICE_STATUS_WITHDRAWN => 'DMCA complaint withdrawn',
        default => 'DMCA notice updated',
    };
}

function ve_dmca_notice_video_payload(array $notice): array
{
    $videoId = (int) ($notice['video_id'] ?? 0);
    $video = $videoId > 0 ? ve_video_get_by_id($videoId) : null;

    if (is_array($video)) {
        return [
            'id' => (int) ($video['id'] ?? 0),
            'public_id' => (string) ($video['public_id'] ?? ''),
            'title' => (string) ($video['title'] ?? 'Untitled video'),
            'watch_url' => ve_url('/d/' . rawurlencode((string) ($video['public_id'] ?? ''))),
            'is_public' => (int) ($video['is_public'] ?? 1),
            'status' => (string) ($video['status'] ?? ''),
            'status_message' => (string) ($video['status_message'] ?? ''),
            'exists' => true,
        ];
    }

    $publicId = trim((string) ($notice['video_public_id_snapshot'] ?? ''));

    return [
        'id' => 0,
        'public_id' => $publicId,
        'title' => trim((string) ($notice['video_title_snapshot'] ?? '')) ?: 'Removed video',
        'watch_url' => $publicId !== '' ? ve_url('/d/' . rawurlencode($publicId)) : '',
        'is_public' => 0,
        'status' => '',
        'status_message' => '',
        'exists' => false,
    ];
}

function ve_dmca_notice_payload(array $notice): array
{
    $status = (string) ($notice['status'] ?? VE_DMCA_NOTICE_STATUS_PENDING_REVIEW);
    $meta = ve_dmca_notice_status_meta($status);
    $video = ve_dmca_notice_video_payload($notice);
    $autoDeleteAt = trim((string) ($notice['auto_delete_at'] ?? ''));
    $remainingSeconds = $autoDeleteAt !== '' ? ve_dmca_seconds_until($autoDeleteAt) : null;
    $response = ve_dmca_decode_response($notice['uploader_response_json'] ?? '');
    $canRespond = in_array($status, [
        VE_DMCA_NOTICE_STATUS_PENDING_REVIEW,
        VE_DMCA_NOTICE_STATUS_CONTENT_DISABLED,
    ], true);
    $canDeleteVideo = ve_dmca_notice_is_open($status) && (bool) ($video['exists'] ?? false);

    return [
        'case_code' => (string) ($notice['case_code'] ?? ''),
        'status' => $status,
        'status_label' => (string) ($meta['label'] ?? 'Waiting for review'),
        'status_tone' => (string) ($meta['tone'] ?? 'secondary'),
        'is_open' => (bool) ($meta['open'] ?? false),
        'content_disabled' => ve_dmca_notice_keeps_disabled($status),
        'can_submit_response' => $canRespond,
        'can_delete_video' => $canDeleteVideo,
        'received_at' => (string) ($notice['received_at'] ?? ''),
        'received_label' => ve_format_datetime_label((string) ($notice['received_at'] ?? '')),
        'updated_at' => (string) ($notice['updated_at'] ?? ''),
        'updated_label' => ve_format_datetime_label((string) ($notice['updated_at'] ?? '')),
        'resolved_at' => (string) ($notice['resolved_at'] ?? ''),
        'resolved_label' => ve_format_datetime_label((string) ($notice['resolved_at'] ?? ''), 'Open'),
        'response_submitted_at' => (string) ($notice['response_submitted_at'] ?? ''),
        'response_submitted_label' => ve_format_datetime_label((string) ($notice['response_submitted_at'] ?? ''), 'Not sent'),
        'deleted_video_at' => (string) ($notice['video_deleted_at'] ?? ''),
        'deleted_video_label' => ve_format_datetime_label((string) ($notice['video_deleted_at'] ?? ''), 'Not deleted'),
        'auto_delete_at' => $autoDeleteAt,
        'auto_delete_label' => ve_format_datetime_label($autoDeleteAt, 'Not scheduled'),
        'auto_delete_remaining_seconds' => $remainingSeconds,
        'auto_delete_remaining_label' => ve_dmca_format_remaining_label($remainingSeconds),
        'claimed_work' => (string) ($notice['claimed_work'] ?? ''),
        'reported_url' => (string) ($notice['reported_url'] ?? ''),
        'work_reference_url' => (string) ($notice['work_reference_url'] ?? ''),
        'complainant_name' => (string) ($notice['complainant_name'] ?? ''),
        'complainant_company' => (string) ($notice['complainant_company'] ?? ''),
        'complainant_email' => (string) ($notice['complainant_email'] ?? ''),
        'complainant_phone' => (string) ($notice['complainant_phone'] ?? ''),
        'complainant_country' => (string) ($notice['complainant_country'] ?? ''),
        'evidence_urls' => ve_dmca_decode_evidence_urls($notice['evidence_urls_json'] ?? ''),
        'notes' => (string) ($notice['notes'] ?? ''),
        'video' => $video,
        'uploader_response' => $response,
        'timeline' => ve_dmca_notice_events((int) ($notice['id'] ?? 0)),
        'response_optional' => true,
    ];
}

function ve_dmca_sync_video_snapshot(array $notice): void
{
    $noticeId = (int) ($notice['id'] ?? 0);
    $videoId = (int) ($notice['video_id'] ?? 0);

    if ($noticeId <= 0 || $videoId <= 0) {
        return;
    }

    $video = ve_video_get_by_id($videoId);

    if (!is_array($video)) {
        return;
    }

    ve_db()->prepare(
        'UPDATE dmca_notices
         SET video_title_snapshot = CASE
                 WHEN video_title_snapshot = "" THEN :video_title_snapshot
                 ELSE video_title_snapshot
             END,
             video_public_id_snapshot = CASE
                 WHEN video_public_id_snapshot = "" THEN :video_public_id_snapshot
                 ELSE video_public_id_snapshot
             END,
             updated_at = :updated_at
         WHERE id = :id'
    )->execute([
        ':video_title_snapshot' => (string) ($video['title'] ?? ''),
        ':video_public_id_snapshot' => (string) ($video['public_id'] ?? ''),
        ':updated_at' => ve_now(),
        ':id' => $noticeId,
    ]);
}

function ve_dmca_disable_video_for_notice(array $notice): void
{
    $noticeId = (int) ($notice['id'] ?? 0);
    $videoId = (int) ($notice['video_id'] ?? 0);

    if ($noticeId <= 0 || $videoId <= 0 || trim((string) ($notice['content_disabled_at'] ?? '')) !== '') {
        return;
    }

    $video = ve_video_get_by_id($videoId);

    if (!is_array($video)) {
        return;
    }

    $holdCount = max(0, (int) ($video['dmca_hold_count'] ?? 0));
    $now = ve_now();

    ve_db()->prepare(
        'UPDATE videos
         SET is_public = 0,
             status_message = :status_message,
             dmca_hold_count = :dmca_hold_count,
             dmca_original_is_public = :dmca_original_is_public,
             dmca_original_status_message = :dmca_original_status_message,
             updated_at = :updated_at
         WHERE id = :id'
    )->execute([
        ':status_message' => 'Unavailable while this DMCA complaint is waiting for uploader action.',
        ':dmca_hold_count' => $holdCount + 1,
        ':dmca_original_is_public' => $holdCount === 0 ? (int) ($video['is_public'] ?? 1) : ($video['dmca_original_is_public'] ?? null),
        ':dmca_original_status_message' => $holdCount === 0 ? (string) ($video['status_message'] ?? '') : (string) ($video['dmca_original_status_message'] ?? ''),
        ':updated_at' => $now,
        ':id' => $videoId,
    ]);

    ve_db()->prepare(
        'UPDATE dmca_notices
         SET content_disabled_at = :content_disabled_at,
             effective_at = COALESCE(NULLIF(effective_at, ""), :effective_at),
             auto_delete_at = COALESCE(NULLIF(auto_delete_at, ""), :auto_delete_at),
             video_is_public_before_action = COALESCE(video_is_public_before_action, :video_is_public_before_action),
             video_status_message_before_action = CASE
                 WHEN video_status_message_before_action = "" THEN :video_status_message_before_action
                 ELSE video_status_message_before_action
             END,
             video_title_snapshot = CASE
                 WHEN video_title_snapshot = "" THEN :video_title_snapshot
                 ELSE video_title_snapshot
             END,
             video_public_id_snapshot = CASE
                 WHEN video_public_id_snapshot = "" THEN :video_public_id_snapshot
                 ELSE video_public_id_snapshot
             END,
             updated_at = :updated_at
         WHERE id = :id'
    )->execute([
        ':content_disabled_at' => $now,
        ':effective_at' => $now,
        ':auto_delete_at' => ve_dmca_add_hours($now, (int) ve_dmca_policy_snapshot()['response_window_hours']),
        ':video_is_public_before_action' => (int) ($video['is_public'] ?? 1),
        ':video_status_message_before_action' => (string) ($video['status_message'] ?? ''),
        ':video_title_snapshot' => (string) ($video['title'] ?? ''),
        ':video_public_id_snapshot' => (string) ($video['public_id'] ?? ''),
        ':updated_at' => $now,
        ':id' => $noticeId,
    ]);
}

function ve_dmca_restore_video_for_notice(array $notice): void
{
    $videoId = (int) ($notice['video_id'] ?? 0);

    if ($videoId <= 0 || trim((string) ($notice['content_disabled_at'] ?? '')) === '') {
        return;
    }

    $video = ve_video_get_by_id($videoId);

    if (!is_array($video)) {
        return;
    }

    $holdCount = max(0, (int) ($video['dmca_hold_count'] ?? 0));

    if ($holdCount <= 0) {
        return;
    }

    $remaining = max(0, $holdCount - 1);
    $restorePublic = $remaining === 0
        ? (($video['dmca_original_is_public'] ?? null) === null ? (int) ($notice['video_is_public_before_action'] ?? 1) : (int) ($video['dmca_original_is_public'] ?? 1))
        : 0;
    $restoreMessage = $remaining === 0
        ? ((string) ($video['dmca_original_status_message'] ?? '') !== '' ? (string) ($video['dmca_original_status_message'] ?? '') : (string) ($notice['video_status_message_before_action'] ?? ''))
        : 'Unavailable while this DMCA complaint is waiting for uploader action.';

    ve_db()->prepare(
        'UPDATE videos
         SET is_public = :is_public,
             status_message = :status_message,
             dmca_hold_count = :dmca_hold_count,
             dmca_original_is_public = :dmca_original_is_public,
             dmca_original_status_message = :dmca_original_status_message,
             updated_at = :updated_at
         WHERE id = :id'
    )->execute([
        ':is_public' => $restorePublic,
        ':status_message' => $restoreMessage,
        ':dmca_hold_count' => $remaining,
        ':dmca_original_is_public' => $remaining === 0 ? null : ($video['dmca_original_is_public'] ?? null),
        ':dmca_original_status_message' => $remaining === 0 ? '' : (string) ($video['dmca_original_status_message'] ?? ''),
        ':updated_at' => ve_now(),
        ':id' => $videoId,
    ]);
}

function ve_dmca_update_notice_status(int $noticeId, string $status, string $eventType, string $title, string $body = '', array $extraFields = []): array
{
    $notice = ve_dmca_notice_by_id($noticeId);

    if (!is_array($notice)) {
        throw new RuntimeException('DMCA notice not found.');
    }

    $oldStatus = (string) ($notice['status'] ?? VE_DMCA_NOTICE_STATUS_PENDING_REVIEW);
    $now = ve_now();

    if (!ve_dmca_notice_keeps_disabled($oldStatus) && ve_dmca_notice_keeps_disabled($status)) {
        ve_dmca_disable_video_for_notice($notice);
        $notice = ve_dmca_notice_by_id($noticeId) ?? $notice;
    }

    if (ve_dmca_notice_keeps_disabled($oldStatus) && !ve_dmca_notice_keeps_disabled($status) && !ve_dmca_notice_deletes_video($status)) {
        ve_dmca_restore_video_for_notice($notice);
    }

    $fields = array_merge([
        'status' => $status,
        'updated_at' => $now,
    ], $extraFields);

    if ($status === VE_DMCA_NOTICE_STATUS_RESPONSE_SUBMITTED || $status === VE_DMCA_NOTICE_STATUS_COUNTER_SUBMITTED) {
        $fields['response_submitted_at'] = $fields['response_submitted_at'] ?? $now;
        $fields['auto_delete_at'] = $fields['auto_delete_at'] ?? null;
    }

    if (!ve_dmca_notice_is_open($status)) {
        $fields['resolved_at'] = $fields['resolved_at'] ?? $now;
    }

    $assignments = [];
    $params = [':id' => $noticeId];

    foreach ($fields as $column => $value) {
        $assignments[] = $column . ' = :' . $column;
        $params[':' . $column] = $value;
    }

    ve_db()->prepare(
        'UPDATE dmca_notices
         SET ' . implode(', ', $assignments) . '
         WHERE id = :id'
    )->execute($params);

    ve_dmca_log_event($noticeId, $eventType, $title, $body, $now);
    $updated = ve_dmca_notice_by_id($noticeId);

    if (!is_array($updated)) {
        throw new RuntimeException('Unable to reload DMCA notice.');
    }

    ve_add_notification(
        (int) ($updated['user_id'] ?? 0),
        ve_dmca_notification_subject($status),
        'Case ' . (string) ($updated['case_code'] ?? '') . ' is now marked as ' . (ve_dmca_notice_status_meta($status)['label'] ?? $status) . '.'
    );

    return $updated;
}

function ve_dmca_delete_video_for_notice(array $notice, string $status, string $eventType, string $title, string $body): array
{
    $noticeId = (int) ($notice['id'] ?? 0);

    if ($noticeId <= 0) {
        throw new RuntimeException('DMCA notice not found.');
    }

    $videoId = (int) ($notice['video_id'] ?? 0);
    $video = $videoId > 0 ? ve_video_get_by_id($videoId) : null;
    $deletedAt = ve_now();

    if (is_array($video)) {
        ve_db()->prepare(
            'UPDATE dmca_notices
             SET video_title_snapshot = CASE
                     WHEN video_title_snapshot = "" THEN :video_title_snapshot
                     ELSE video_title_snapshot
                 END,
                 video_public_id_snapshot = CASE
                     WHEN video_public_id_snapshot = "" THEN :video_public_id_snapshot
                     ELSE video_public_id_snapshot
                 END,
                 updated_at = :updated_at
             WHERE id = :id'
        )->execute([
            ':video_title_snapshot' => (string) ($video['title'] ?? ''),
            ':video_public_id_snapshot' => (string) ($video['public_id'] ?? ''),
            ':updated_at' => $deletedAt,
            ':id' => $noticeId,
        ]);

        ve_video_delete_video_rows([$video]);
    }

    return ve_dmca_update_notice_status($noticeId, $status, $eventType, $title, $body, [
        'video_deleted_at' => $deletedAt,
        'auto_delete_at' => null,
    ]);
}

function ve_dmca_process_due_removals(): void
{
    $stmt = ve_db()->prepare(
        'SELECT *
         FROM dmca_notices
         WHERE status IN (:pending_review, :content_disabled)
           AND auto_delete_at IS NOT NULL
           AND auto_delete_at != ""
           AND auto_delete_at <= :now
         ORDER BY auto_delete_at ASC, id ASC'
    );
    $stmt->execute([
        ':pending_review' => VE_DMCA_NOTICE_STATUS_PENDING_REVIEW,
        ':content_disabled' => VE_DMCA_NOTICE_STATUS_CONTENT_DISABLED,
        ':now' => ve_now(),
    ]);

    foreach ($stmt->fetchAll() as $notice) {
        if (!is_array($notice)) {
            continue;
        }

        ve_dmca_delete_video_for_notice(
            $notice,
            VE_DMCA_NOTICE_STATUS_AUTO_DELETED,
            'auto_delete',
            'Video auto deleted',
            'No uploader response was submitted within 24 hours, so the file was permanently deleted.'
        );
    }
}

function ve_dmca_create_notice(array $payload): array
{
    $video = null;
    $videoId = (int) ($payload['video_id'] ?? 0);
    $videoPublicId = trim((string) ($payload['video_public_id'] ?? ''));

    if ($videoId > 0) {
        $video = ve_video_get_by_id($videoId);
    } elseif ($videoPublicId !== '') {
        $video = ve_video_get_by_public_id($videoPublicId);
        $videoId = is_array($video) ? (int) ($video['id'] ?? 0) : 0;
    }

    $userId = isset($payload['user_id']) ? (int) $payload['user_id'] : 0;

    if ($userId <= 0 && is_array($video)) {
        $userId = (int) ($video['user_id'] ?? 0);
    }

    if ($userId <= 0) {
        throw new InvalidArgumentException('A DMCA notice must resolve to a user account.');
    }

    $claimedWork = trim((string) ($payload['claimed_work'] ?? ''));
    $complainantName = trim((string) ($payload['complainant_name'] ?? ''));
    $reportedUrl = trim((string) ($payload['reported_url'] ?? ''));

    if ($claimedWork === '' || $complainantName === '') {
        throw new InvalidArgumentException('DMCA notices require a complainant and a claimed work description.');
    }

    if ($reportedUrl === '' && is_array($video)) {
        $reportedUrl = ve_absolute_url('/d/' . rawurlencode((string) ($video['public_id'] ?? '')));
    }

    if ($reportedUrl === '') {
        throw new InvalidArgumentException('DMCA notices require the reported location.');
    }

    $receivedAt = trim((string) ($payload['received_at'] ?? ''));
    $receivedAt = $receivedAt !== '' ? $receivedAt : ve_now();
    $caseCode = trim((string) ($payload['case_code'] ?? ''));
    $caseCode = $caseCode !== '' ? strtoupper($caseCode) : ve_dmca_generate_case_code();
    $requestedStatus = trim((string) ($payload['status'] ?? ''));
    $status = $requestedStatus !== '' ? $requestedStatus : VE_DMCA_NOTICE_STATUS_PENDING_REVIEW;

    if (!array_key_exists($status, ve_dmca_notice_status_catalog())) {
        $status = VE_DMCA_NOTICE_STATUS_PENDING_REVIEW;
    }

    $videoTitleSnapshot = is_array($video) ? (string) ($video['title'] ?? '') : trim((string) ($payload['video_title_snapshot'] ?? ''));
    $videoPublicSnapshot = is_array($video) ? (string) ($video['public_id'] ?? '') : trim((string) ($payload['video_public_id_snapshot'] ?? ''));

    $initialAutoDeleteAt = in_array($status, [
        VE_DMCA_NOTICE_STATUS_PENDING_REVIEW,
        VE_DMCA_NOTICE_STATUS_CONTENT_DISABLED,
    ], true)
        ? ve_dmca_add_hours($receivedAt, (int) ve_dmca_policy_snapshot()['response_window_hours'])
        : null;

    ve_db()->prepare(
        'INSERT INTO dmca_notices (
            case_code, user_id, video_id, source_type, status, complainant_name, complainant_company,
            complainant_email, complainant_phone, complainant_address, complainant_country,
            claimed_work, work_reference_url, reported_url, evidence_urls_json, notes,
            signature_name, effective_at, content_disabled_at, counter_notice_submitted_at,
            restoration_earliest_at, restoration_latest_at, resolved_at, video_is_public_before_action,
            video_status_message_before_action, received_at, updated_at, response_submitted_at,
            uploader_response_json, auto_delete_at, video_deleted_at, video_title_snapshot, video_public_id_snapshot
        ) VALUES (
            :case_code, :user_id, :video_id, :source_type, :status, :complainant_name, :complainant_company,
            :complainant_email, :complainant_phone, :complainant_address, :complainant_country,
            :claimed_work, :work_reference_url, :reported_url, :evidence_urls_json, :notes,
            :signature_name, NULL, NULL, NULL,
            NULL, NULL, NULL, NULL,
            "", :received_at, :updated_at, NULL,
            "", :auto_delete_at, NULL, :video_title_snapshot, :video_public_id_snapshot
        )'
    )->execute([
        ':case_code' => $caseCode,
        ':user_id' => $userId,
        ':video_id' => $videoId > 0 ? $videoId : null,
        ':source_type' => trim((string) ($payload['source_type'] ?? 'email')) ?: 'email',
        ':status' => VE_DMCA_NOTICE_STATUS_PENDING_REVIEW,
        ':complainant_name' => $complainantName,
        ':complainant_company' => trim((string) ($payload['complainant_company'] ?? '')),
        ':complainant_email' => trim((string) ($payload['complainant_email'] ?? '')),
        ':complainant_phone' => trim((string) ($payload['complainant_phone'] ?? '')),
        ':complainant_address' => trim((string) ($payload['complainant_address'] ?? '')),
        ':complainant_country' => trim((string) ($payload['complainant_country'] ?? '')),
        ':claimed_work' => $claimedWork,
        ':work_reference_url' => trim((string) ($payload['work_reference_url'] ?? '')),
        ':reported_url' => $reportedUrl,
        ':evidence_urls_json' => ve_dmca_evidence_urls_json($payload['evidence_urls'] ?? []),
        ':notes' => trim((string) ($payload['notes'] ?? '')),
        ':signature_name' => trim((string) ($payload['signature_name'] ?? '')),
        ':received_at' => $receivedAt,
        ':updated_at' => $receivedAt,
        ':auto_delete_at' => $initialAutoDeleteAt,
        ':video_title_snapshot' => $videoTitleSnapshot,
        ':video_public_id_snapshot' => $videoPublicSnapshot,
    ]);

    $noticeId = (int) ve_db()->lastInsertId();
    $notice = ve_dmca_notice_by_id($noticeId);

    if (!is_array($notice)) {
        throw new RuntimeException('Unable to reload DMCA notice.');
    }

    ve_dmca_log_event(
        $noticeId,
        'received',
        'Complaint received',
        'The complaint was logged, the file stays online during review, and the uploader can add optional information or delete the file directly.',
        $receivedAt
    );
    ve_add_notification($userId, 'DMCA complaint received', 'Case ' . $caseCode . ' has been added to your DMCA manager.');

    if ($status !== VE_DMCA_NOTICE_STATUS_PENDING_REVIEW) {
        $transitionBody = match ($status) {
            VE_DMCA_NOTICE_STATUS_CONTENT_DISABLED => 'After review, the reported file was removed from public access and will be deleted after 24 hours if no uploader action is taken.',
            VE_DMCA_NOTICE_STATUS_RESPONSE_SUBMITTED => 'The uploader sent optional information while the case remains under review.',
            VE_DMCA_NOTICE_STATUS_UPLOADER_DELETED => 'The uploader deleted the reported file.',
            VE_DMCA_NOTICE_STATUS_AUTO_DELETED => 'The reported file was auto deleted after the response window expired.',
            VE_DMCA_NOTICE_STATUS_RESTORED => 'The reported file was restored.',
            VE_DMCA_NOTICE_STATUS_REJECTED => 'The complaint was rejected after review.',
            VE_DMCA_NOTICE_STATUS_WITHDRAWN => 'The complaint was withdrawn by the complainant.',
            default => '',
        };

        $notice = ve_dmca_update_notice_status($noticeId, $status, 'status_change', ve_dmca_notice_status_meta($status)['label'] ?? 'Status updated', $transitionBody);
    }

    return $notice;
}

function ve_dmca_validate_uploader_response_input(): array
{
    $fields = [
        'contact_email' => strtolower(trim((string) ($_POST['contact_email'] ?? ''))),
        'contact_phone' => trim((string) ($_POST['contact_phone'] ?? '')),
        'notes' => trim((string) ($_POST['notes'] ?? '')),
    ];

    if ($fields['contact_email'] !== '' && !filter_var($fields['contact_email'], FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Enter a valid contact email or leave it blank.');
    }

    return $fields;
}

function ve_dmca_submit_uploader_response(int $userId, string $caseCode): array
{
    ve_dmca_process_due_removals();

    $notice = ve_dmca_notice_by_case_code($userId, $caseCode);

    if (!is_array($notice)) {
        throw new RuntimeException('DMCA case not found.');
    }

    if (!in_array((string) ($notice['status'] ?? ''), [
        VE_DMCA_NOTICE_STATUS_PENDING_REVIEW,
        VE_DMCA_NOTICE_STATUS_CONTENT_DISABLED,
    ], true)) {
        throw new InvalidArgumentException('This case is no longer accepting uploader information.');
    }

    $fields = ve_dmca_validate_uploader_response_input();
    $summary = trim(implode(' ', array_filter([
        $fields['notes'] !== '' ? 'Uploader notes: ' . $fields['notes'] : '',
        $fields['contact_email'] !== '' ? 'Contact email: ' . $fields['contact_email'] : '',
        $fields['contact_phone'] !== '' ? 'Contact phone: ' . $fields['contact_phone'] : '',
    ])));

    if ($summary === '') {
        $summary = 'The uploader responded without adding extra information.';
    }

    return ve_dmca_update_notice_status(
        (int) ($notice['id'] ?? 0),
        VE_DMCA_NOTICE_STATUS_RESPONSE_SUBMITTED,
        'uploader_response',
        'Uploader response received',
        $summary,
        [
            'uploader_response_json' => ve_dmca_response_json($fields),
        ]
    );
}

function ve_dmca_delete_video_by_case(int $userId, string $caseCode): array
{
    ve_dmca_process_due_removals();

    $notice = ve_dmca_notice_by_case_code($userId, $caseCode);

    if (!is_array($notice)) {
        throw new RuntimeException('DMCA case not found.');
    }

    if (!ve_dmca_notice_is_open((string) ($notice['status'] ?? ''))) {
        throw new InvalidArgumentException('This case is already closed.');
    }

    $video = ve_dmca_notice_video_payload($notice);

    if (!(bool) ($video['exists'] ?? false)) {
        throw new InvalidArgumentException('The reported file was already deleted.');
    }

    return ve_dmca_delete_video_for_notice(
        $notice,
        VE_DMCA_NOTICE_STATUS_UPLOADER_DELETED,
        'uploader_delete',
        'Video deleted by uploader',
        'The uploader deleted the reported file from the DMCA manager.'
    );
}

function ve_dmca_list_filters(string $statusFilter, string $queryFilter): array
{
    $clauses = ['n.user_id = :user_id'];
    $params = [];

    if ($statusFilter === 'open') {
        $clauses[] = 'n.status IN ("' . VE_DMCA_NOTICE_STATUS_PENDING_REVIEW . '", "' . VE_DMCA_NOTICE_STATUS_CONTENT_DISABLED . '", "' . VE_DMCA_NOTICE_STATUS_COUNTER_SUBMITTED . '", "' . VE_DMCA_NOTICE_STATUS_RESPONSE_SUBMITTED . '")';
    } elseif ($statusFilter === 'resolved') {
        $clauses[] = 'n.status IN ("' . VE_DMCA_NOTICE_STATUS_RESTORED . '", "' . VE_DMCA_NOTICE_STATUS_REJECTED . '", "' . VE_DMCA_NOTICE_STATUS_WITHDRAWN . '", "' . VE_DMCA_NOTICE_STATUS_UPLOADER_DELETED . '", "' . VE_DMCA_NOTICE_STATUS_AUTO_DELETED . '")';
    } elseif ($statusFilter !== '' && array_key_exists($statusFilter, ve_dmca_notice_status_catalog())) {
        $clauses[] = 'n.status = :status_filter';
        $params[':status_filter'] = $statusFilter;
    }

    if ($queryFilter !== '') {
        $clauses[] = '(lower(n.case_code) LIKE :query_filter OR lower(n.claimed_work) LIKE :query_filter OR lower(COALESCE(n.video_title_snapshot, "")) LIKE :query_filter OR lower(COALESCE(v.title, "")) LIKE :query_filter)';
        $params[':query_filter'] = '%' . strtolower($queryFilter) . '%';
    }

    return [
        'where_sql' => implode(' AND ', $clauses),
        'params' => $params,
    ];
}

function ve_dmca_list_notices(int $userId, string $statusFilter = '', string $queryFilter = '', int $page = 1, int $pageSize = VE_DMCA_PAGE_SIZE): array
{
    $page = max(1, $page);
    $pageSize = max(1, min(50, $pageSize));
    $offset = ($page - 1) * $pageSize;
    $filters = ve_dmca_list_filters($statusFilter, $queryFilter);
    $params = array_merge([':user_id' => $userId], $filters['params']);

    $countStmt = ve_db()->prepare(
        'SELECT COUNT(*)
         FROM dmca_notices n
         LEFT JOIN videos v ON v.id = n.video_id
         WHERE ' . $filters['where_sql']
    );
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $stmt = ve_db()->prepare(
        'SELECT
            n.*,
            v.public_id AS video_public_id,
            v.title AS video_title,
            v.is_public AS video_is_public,
            v.status AS video_status,
            v.status_message AS video_status_message
         FROM dmca_notices n
         LEFT JOIN videos v ON v.id = n.video_id
         WHERE ' . $filters['where_sql'] . '
         ORDER BY n.received_at DESC, n.id DESC
         LIMIT :limit OFFSET :offset'
    );

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $items = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $items[] = ve_dmca_notice_payload($row);
    }

    return [
        'items' => $items,
        'pagination' => [
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
            'has_more' => ($offset + $pageSize) < $total,
        ],
    ];
}

function ve_dmca_summary(int $userId): array
{
    $stmt = ve_db()->prepare(
        'SELECT status, auto_delete_at
         FROM dmca_notices
         WHERE user_id = :user_id'
    );
    $stmt->execute([':user_id' => $userId]);

    $openCount = 0;
    $pendingDeleteCount = 0;
    $responseCount = 0;
    $deletedCount = 0;

    foreach ($stmt->fetchAll() as $item) {
        if (!is_array($item)) {
            continue;
        }

        $status = (string) ($item['status'] ?? VE_DMCA_NOTICE_STATUS_PENDING_REVIEW);

        if (ve_dmca_notice_is_open($status)) {
            $openCount++;
        }

        if (ve_dmca_notice_is_open($status) && trim((string) ($item['auto_delete_at'] ?? '')) !== '') {
            $pendingDeleteCount++;
        }

        if ($status === VE_DMCA_NOTICE_STATUS_RESPONSE_SUBMITTED || $status === VE_DMCA_NOTICE_STATUS_COUNTER_SUBMITTED) {
            $responseCount++;
        }

        if ($status === VE_DMCA_NOTICE_STATUS_UPLOADER_DELETED || $status === VE_DMCA_NOTICE_STATUS_AUTO_DELETED) {
            $deletedCount++;
        }
    }

    return [
        'open_cases' => $openCount,
        'pending_delete' => $pendingDeleteCount,
        'responses_received' => $responseCount,
        'deleted_videos' => $deletedCount,
    ];
}

function ve_dmca_snapshot(int $userId, string $statusFilter = '', string $queryFilter = '', int $page = 1): array
{
    ve_dmca_process_due_removals();
    $list = ve_dmca_list_notices($userId, $statusFilter, $queryFilter, $page);

    return [
        'status' => 'ok',
        'summary' => ve_dmca_summary($userId),
        'policy' => ve_dmca_policy_snapshot(),
        'items' => $list['items'],
        'pagination' => $list['pagination'],
        'filters' => [
            'status' => $statusFilter,
            'query' => $queryFilter,
        ],
    ];
}

function ve_dmca_case_detail(int $userId, string $caseCode): ?array
{
    ve_dmca_process_due_removals();
    $notice = ve_dmca_notice_by_case_code($userId, $caseCode);
    return is_array($notice) ? ve_dmca_notice_payload($notice) : null;
}

function ve_handle_dmca_index_api(): void
{
    $user = ve_require_auth();
    $statusFilter = trim((string) ($_GET['status'] ?? ''));
    $queryFilter = trim((string) ($_GET['q'] ?? ''));
    $page = max(1, (int) ($_GET['page'] ?? 1));

    ve_json(ve_dmca_snapshot((int) $user['id'], $statusFilter, $queryFilter, $page));
}

function ve_handle_dmca_detail_api(string $caseCode): void
{
    $user = ve_require_auth();
    $detail = ve_dmca_case_detail((int) $user['id'], $caseCode);

    if ($detail === null) {
        ve_json([
            'status' => 'fail',
            'message' => 'DMCA case not found.',
        ], 404);
    }

    ve_json([
        'status' => 'ok',
        'notice' => $detail,
    ]);
}

function ve_handle_dmca_response_api(string $caseCode): void
{
    $user = ve_require_auth();
    ve_require_csrf(ve_request_csrf_token());

    try {
        $notice = ve_dmca_submit_uploader_response((int) $user['id'], $caseCode);
    } catch (InvalidArgumentException $exception) {
        ve_json([
            'status' => 'fail',
            'message' => $exception->getMessage(),
        ], 422);
    } catch (Throwable $exception) {
        ve_json([
            'status' => 'fail',
            'message' => $exception->getMessage(),
        ], 404);
    }

    ve_json([
        'status' => 'ok',
        'message' => 'Optional uploader information was saved.',
        'notice' => ve_dmca_notice_payload($notice),
        'summary' => ve_dmca_summary((int) $user['id']),
    ]);
}

function ve_handle_dmca_delete_video_api(string $caseCode): void
{
    $user = ve_require_auth();
    ve_require_csrf(ve_request_csrf_token());

    try {
        $notice = ve_dmca_delete_video_by_case((int) $user['id'], $caseCode);
    } catch (InvalidArgumentException $exception) {
        ve_json([
            'status' => 'fail',
            'message' => $exception->getMessage(),
        ], 422);
    } catch (Throwable $exception) {
        ve_json([
            'status' => 'fail',
            'message' => $exception->getMessage(),
        ], 404);
    }

    ve_json([
        'status' => 'ok',
        'message' => 'Video deleted successfully.',
        'notice' => ve_dmca_notice_payload($notice),
        'summary' => ve_dmca_summary((int) $user['id']),
    ]);
}

function ve_handle_dmca_counter_notice_api(string $caseCode): void
{
    ve_handle_dmca_response_api($caseCode);
}
