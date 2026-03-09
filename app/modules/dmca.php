<?php

declare(strict_types=1);

const VE_DMCA_NOTICE_STATUS_PENDING_REVIEW = 'pending_review';
const VE_DMCA_NOTICE_STATUS_CONTENT_DISABLED = 'content_disabled';
const VE_DMCA_NOTICE_STATUS_COUNTER_SUBMITTED = 'counter_submitted';
const VE_DMCA_NOTICE_STATUS_RESTORED = 'restored';
const VE_DMCA_NOTICE_STATUS_REJECTED = 'rejected';
const VE_DMCA_NOTICE_STATUS_WITHDRAWN = 'withdrawn';

const VE_DMCA_COUNTER_STATUS_SUBMITTED = 'submitted';
const VE_DMCA_PAGE_SIZE = 10;

function ve_dmca_policy_snapshot(): array
{
    return [
        'dmca_email' => 'dmca@doodstream.com',
        'repeat_infringer_threshold' => 3,
        'repeat_infringer_window_months' => 6,
        'counter_window_business_days' => [
            'min' => 10,
            'max' => 14,
        ],
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
            'tone' => 'warning',
            'open' => true,
            'keeps_disabled' => false,
        ],
        VE_DMCA_NOTICE_STATUS_CONTENT_DISABLED => [
            'label' => 'Content disabled',
            'tone' => 'danger',
            'open' => true,
            'keeps_disabled' => true,
        ],
        VE_DMCA_NOTICE_STATUS_COUNTER_SUBMITTED => [
            'label' => 'Counter notice sent',
            'tone' => 'info',
            'open' => true,
            'keeps_disabled' => true,
        ],
        VE_DMCA_NOTICE_STATUS_RESTORED => [
            'label' => 'Restored',
            'tone' => 'success',
            'open' => false,
            'keeps_disabled' => false,
        ],
        VE_DMCA_NOTICE_STATUS_REJECTED => [
            'label' => 'Rejected',
            'tone' => 'secondary',
            'open' => false,
            'keeps_disabled' => false,
        ],
        VE_DMCA_NOTICE_STATUS_WITHDRAWN => [
            'label' => 'Withdrawn',
            'tone' => 'secondary',
            'open' => false,
            'keeps_disabled' => false,
        ],
    ];

    return $catalog;
}

function ve_dmca_counter_status_catalog(): array
{
    return [
        VE_DMCA_COUNTER_STATUS_SUBMITTED => [
            'label' => 'Submitted',
            'tone' => 'info',
        ],
    ];
}

function ve_dmca_notice_status_meta(string $status): array
{
    $catalog = ve_dmca_notice_status_catalog();
    return $catalog[$status] ?? $catalog[VE_DMCA_NOTICE_STATUS_PENDING_REVIEW];
}

function ve_dmca_counter_status_meta(string $status): array
{
    $catalog = ve_dmca_counter_status_catalog();
    return $catalog[$status] ?? $catalog[VE_DMCA_COUNTER_STATUS_SUBMITTED];
}

function ve_dmca_notice_is_open(string $status): bool
{
    return (bool) (ve_dmca_notice_status_meta($status)['open'] ?? false);
}

function ve_dmca_notice_keeps_disabled(string $status): bool
{
    return (bool) (ve_dmca_notice_status_meta($status)['keeps_disabled'] ?? false);
}

function ve_dmca_generate_case_code(): string
{
    return 'DMCA-' . gmdate('Ymd') . '-' . strtoupper(substr(ve_random_token(6), 0, 8));
}

function ve_dmca_mask_email(string $email): string
{
    $email = trim($email);

    if ($email === '' || !str_contains($email, '@')) {
        return '';
    }

    [$local, $domain] = explode('@', $email, 2);
    $localLength = strlen($local);

    if ($localLength <= 2) {
        $local = substr($local, 0, 1) . '*';
    } else {
        $local = substr($local, 0, 1) . str_repeat('*', max(1, $localLength - 2)) . substr($local, -1);
    }

    return $local . '@' . $domain;
}

function ve_dmca_mask_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';

    if ($digits === '') {
        return '';
    }

    $suffix = substr($digits, -4);
    return '***-***-' . str_pad($suffix, 4, '*', STR_PAD_LEFT);
}

function ve_dmca_add_business_days(string $timestamp, int $days): string
{
    $date = new DateTimeImmutable($timestamp, new DateTimeZone('UTC'));
    $remaining = max(0, $days);

    while ($remaining > 0) {
        $date = $date->modify('+1 day');
        $dayOfWeek = (int) $date->format('N');

        if ($dayOfWeek >= 6) {
            continue;
        }

        $remaining--;
    }

    return $date->format('Y-m-d H:i:s');
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

function ve_dmca_counter_notice(int $noticeId): ?array
{
    $stmt = ve_db()->prepare(
        'SELECT * FROM dmca_counter_notices
         WHERE notice_id = :notice_id
         LIMIT 1'
    );
    $stmt->execute([':notice_id' => $noticeId]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

function ve_dmca_notification_subject(string $status): string
{
    return match ($status) {
        VE_DMCA_NOTICE_STATUS_CONTENT_DISABLED => 'DMCA claim applied',
        VE_DMCA_NOTICE_STATUS_COUNTER_SUBMITTED => 'Counter notice submitted',
        VE_DMCA_NOTICE_STATUS_RESTORED => 'Content restored',
        VE_DMCA_NOTICE_STATUS_REJECTED => 'DMCA claim rejected',
        VE_DMCA_NOTICE_STATUS_WITHDRAWN => 'DMCA claim withdrawn',
        default => 'DMCA notice received',
    };
}

function ve_dmca_notice_video_payload(array $notice): ?array
{
    $videoId = (int) ($notice['video_id'] ?? 0);

    if ($videoId <= 0) {
        return null;
    }

    $video = ve_video_get_by_id($videoId);

    if (!is_array($video)) {
        return null;
    }

    return [
        'id' => (int) ($video['id'] ?? 0),
        'public_id' => (string) ($video['public_id'] ?? ''),
        'title' => (string) ($video['title'] ?? 'Untitled video'),
        'watch_url' => ve_url('/d/' . rawurlencode((string) ($video['public_id'] ?? ''))),
        'is_public' => (int) ($video['is_public'] ?? 1),
        'status' => (string) ($video['status'] ?? ''),
        'status_message' => (string) ($video['status_message'] ?? ''),
    ];
}

function ve_dmca_notice_payload(array $notice): array
{
    $status = (string) ($notice['status'] ?? VE_DMCA_NOTICE_STATUS_PENDING_REVIEW);
    $meta = ve_dmca_notice_status_meta($status);
    $video = ve_dmca_notice_video_payload($notice);
    $counter = ve_dmca_counter_notice((int) ($notice['id'] ?? 0));
    $effectiveAt = trim((string) ($notice['effective_at'] ?? ''));
    $policy = ve_dmca_policy_snapshot();
    $strikeWindowStart = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->sub(new DateInterval('P' . (int) $policy['repeat_infringer_window_months'] . 'M'))
        ->format('Y-m-d H:i:s');

    return [
        'case_code' => (string) ($notice['case_code'] ?? ''),
        'status' => $status,
        'status_label' => (string) ($meta['label'] ?? 'Under review'),
        'status_tone' => (string) ($meta['tone'] ?? 'secondary'),
        'is_open' => (bool) ($meta['open'] ?? false),
        'content_disabled' => ve_dmca_notice_keeps_disabled($status),
        'can_submit_counter_notice' => $counter === null && $status === VE_DMCA_NOTICE_STATUS_CONTENT_DISABLED,
        'received_at' => (string) ($notice['received_at'] ?? ''),
        'received_label' => ve_format_datetime_label((string) ($notice['received_at'] ?? '')),
        'updated_at' => (string) ($notice['updated_at'] ?? ''),
        'updated_label' => ve_format_datetime_label((string) ($notice['updated_at'] ?? '')),
        'effective_at' => $effectiveAt,
        'effective_label' => ve_format_datetime_label($effectiveAt, 'Not effective yet'),
        'resolved_at' => (string) ($notice['resolved_at'] ?? ''),
        'resolved_label' => ve_format_datetime_label((string) ($notice['resolved_at'] ?? ''), 'Open'),
        'counter_notice_submitted_at' => (string) ($notice['counter_notice_submitted_at'] ?? ''),
        'counter_notice_submitted_label' => ve_format_datetime_label((string) ($notice['counter_notice_submitted_at'] ?? ''), 'Not submitted'),
        'restoration_earliest_at' => (string) ($notice['restoration_earliest_at'] ?? ''),
        'restoration_earliest_label' => ve_format_datetime_label((string) ($notice['restoration_earliest_at'] ?? ''), 'Not scheduled'),
        'restoration_latest_at' => (string) ($notice['restoration_latest_at'] ?? ''),
        'restoration_latest_label' => ve_format_datetime_label((string) ($notice['restoration_latest_at'] ?? ''), 'Not scheduled'),
        'claimed_work' => (string) ($notice['claimed_work'] ?? ''),
        'work_reference_url' => (string) ($notice['work_reference_url'] ?? ''),
        'reported_url' => (string) ($notice['reported_url'] ?? ''),
        'evidence_urls' => ve_dmca_decode_evidence_urls($notice['evidence_urls_json'] ?? '[]'),
        'notes' => (string) ($notice['notes'] ?? ''),
        'source_type' => (string) ($notice['source_type'] ?? 'email'),
        'signature_name' => (string) ($notice['signature_name'] ?? ''),
        'complainant' => [
            'name' => (string) ($notice['complainant_name'] ?? ''),
            'company' => (string) ($notice['complainant_company'] ?? ''),
            'email' => ve_dmca_mask_email((string) ($notice['complainant_email'] ?? '')),
            'phone' => ve_dmca_mask_phone((string) ($notice['complainant_phone'] ?? '')),
            'country' => (string) ($notice['complainant_country'] ?? ''),
        ],
        'video' => $video,
        'counter_notice' => $counter === null ? null : [
            'status' => (string) ($counter['status'] ?? VE_DMCA_COUNTER_STATUS_SUBMITTED),
            'status_label' => (string) (ve_dmca_counter_status_meta((string) ($counter['status'] ?? VE_DMCA_COUNTER_STATUS_SUBMITTED))['label'] ?? 'Submitted'),
            'submitted_at' => (string) ($counter['submitted_at'] ?? ''),
            'submitted_label' => ve_format_datetime_label((string) ($counter['submitted_at'] ?? '')),
            'full_name' => (string) ($counter['full_name'] ?? ''),
            'email' => (string) ($counter['email'] ?? ''),
            'phone' => (string) ($counter['phone'] ?? ''),
            'address_line' => (string) ($counter['address_line'] ?? ''),
            'city' => (string) ($counter['city'] ?? ''),
            'country' => (string) ($counter['country'] ?? ''),
            'postal_code' => (string) ($counter['postal_code'] ?? ''),
            'removed_material_location' => (string) ($counter['removed_material_location'] ?? ''),
            'mistake_statement' => (string) ($counter['mistake_statement'] ?? ''),
            'jurisdiction_statement' => (string) ($counter['jurisdiction_statement'] ?? ''),
            'signature_name' => (string) ($counter['signature_name'] ?? ''),
        ],
        'strike_active' => $effectiveAt !== '' && $effectiveAt >= $strikeWindowStart,
        'timeline' => ve_dmca_notice_events((int) ($notice['id'] ?? 0)),
    ];
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
        ':status_message' => 'Unavailable due to an active DMCA complaint.',
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
             video_is_public_before_action = COALESCE(video_is_public_before_action, :video_is_public_before_action),
             video_status_message_before_action = CASE
                 WHEN video_status_message_before_action = "" THEN :video_status_message_before_action
                 ELSE video_status_message_before_action
             END,
             updated_at = :updated_at
         WHERE id = :id'
    )->execute([
        ':content_disabled_at' => $now,
        ':effective_at' => $now,
        ':video_is_public_before_action' => (int) ($video['is_public'] ?? 1),
        ':video_status_message_before_action' => (string) ($video['status_message'] ?? ''),
        ':updated_at' => $now,
        ':id' => $noticeId,
    ]);
}

function ve_dmca_restore_video_for_notice(array $notice): void
{
    $noticeId = (int) ($notice['id'] ?? 0);
    $videoId = (int) ($notice['video_id'] ?? 0);

    if ($noticeId <= 0 || $videoId <= 0 || trim((string) ($notice['content_disabled_at'] ?? '')) === '') {
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
    $restorePublic = $remaining === 0 ? (($video['dmca_original_is_public'] ?? null) === null ? (int) ($notice['video_is_public_before_action'] ?? 1) : (int) ($video['dmca_original_is_public'] ?? 1)) : 0;
    $restoreMessage = $remaining === 0
        ? ((string) ($video['dmca_original_status_message'] ?? '') !== '' ? (string) ($video['dmca_original_status_message'] ?? '') : (string) ($notice['video_status_message_before_action'] ?? ''))
        : 'Unavailable due to an active DMCA complaint.';

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

function ve_dmca_update_notice_status(int $noticeId, string $status, string $eventType, string $title, string $body = ''): array
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

    if (ve_dmca_notice_keeps_disabled($oldStatus) && !ve_dmca_notice_keeps_disabled($status)) {
        ve_dmca_restore_video_for_notice($notice);
    }

    $fields = [
        'status' => $status,
        'updated_at' => $now,
    ];

    if ($status === VE_DMCA_NOTICE_STATUS_COUNTER_SUBMITTED) {
        $fields['counter_notice_submitted_at'] = $now;
        $fields['restoration_earliest_at'] = ve_dmca_add_business_days($now, 10);
        $fields['restoration_latest_at'] = ve_dmca_add_business_days($now, 14);
    }

    if (!ve_dmca_notice_is_open($status)) {
        $fields['resolved_at'] = $now;
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

    if (in_array($status, [
        VE_DMCA_NOTICE_STATUS_CONTENT_DISABLED,
        VE_DMCA_NOTICE_STATUS_COUNTER_SUBMITTED,
        VE_DMCA_NOTICE_STATUS_RESTORED,
        VE_DMCA_NOTICE_STATUS_REJECTED,
        VE_DMCA_NOTICE_STATUS_WITHDRAWN,
    ], true)) {
        ve_add_notification(
            (int) ($updated['user_id'] ?? 0),
            ve_dmca_notification_subject($status),
            'Case ' . (string) ($updated['case_code'] ?? '') . ' is now marked as ' . (ve_dmca_notice_status_meta($status)['label'] ?? $status) . '.'
        );
    }

    return $updated;
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
    $status = trim((string) ($payload['status'] ?? VE_DMCA_NOTICE_STATUS_PENDING_REVIEW));

    if (!array_key_exists($status, ve_dmca_notice_status_catalog())) {
        $status = VE_DMCA_NOTICE_STATUS_PENDING_REVIEW;
    }

    ve_db()->prepare(
        'INSERT INTO dmca_notices (
            case_code, user_id, video_id, source_type, status, complainant_name, complainant_company,
            complainant_email, complainant_phone, complainant_address, complainant_country,
            claimed_work, work_reference_url, reported_url, evidence_urls_json, notes,
            signature_name, effective_at, content_disabled_at, counter_notice_submitted_at,
            restoration_earliest_at, restoration_latest_at, resolved_at, video_is_public_before_action,
            video_status_message_before_action, received_at, updated_at
        ) VALUES (
            :case_code, :user_id, :video_id, :source_type, :status, :complainant_name, :complainant_company,
            :complainant_email, :complainant_phone, :complainant_address, :complainant_country,
            :claimed_work, :work_reference_url, :reported_url, :evidence_urls_json, :notes,
            :signature_name, NULL, NULL, NULL,
            NULL, NULL, NULL, NULL,
            "", :received_at, :updated_at
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
    ]);

    $noticeId = (int) ve_db()->lastInsertId();
    $notice = ve_dmca_notice_by_id($noticeId);

    if (!is_array($notice)) {
        throw new RuntimeException('Unable to reload DMCA notice.');
    }

    ve_dmca_log_event($noticeId, 'received', 'Notice received', 'The complaint was logged by the compliance system.', $receivedAt);
    ve_add_notification($userId, 'DMCA notice received', 'Case ' . $caseCode . ' has been added to your DMCA manager.');

    if ($status !== VE_DMCA_NOTICE_STATUS_PENDING_REVIEW) {
        $transitionTitle = match ($status) {
            VE_DMCA_NOTICE_STATUS_CONTENT_DISABLED => 'Content disabled',
            VE_DMCA_NOTICE_STATUS_COUNTER_SUBMITTED => 'Counter notice sent',
            VE_DMCA_NOTICE_STATUS_RESTORED => 'Content restored',
            VE_DMCA_NOTICE_STATUS_REJECTED => 'Notice rejected',
            VE_DMCA_NOTICE_STATUS_WITHDRAWN => 'Notice withdrawn',
            default => 'Status updated',
        };

        $transitionBody = match ($status) {
            VE_DMCA_NOTICE_STATUS_CONTENT_DISABLED => 'The reported file was removed from public access pending resolution.',
            VE_DMCA_NOTICE_STATUS_COUNTER_SUBMITTED => 'The uploader submitted a counter notice and the file remains disabled until review completes.',
            VE_DMCA_NOTICE_STATUS_RESTORED => 'The reported file was restored to its prior availability state.',
            VE_DMCA_NOTICE_STATUS_REJECTED => 'The complaint was rejected after review.',
            VE_DMCA_NOTICE_STATUS_WITHDRAWN => 'The complainant withdrew the complaint.',
            default => '',
        };

        $notice = ve_dmca_update_notice_status($noticeId, $status, 'status_change', $transitionTitle, $transitionBody);
    }

    return $notice;
}

function ve_dmca_validate_counter_notice_input(): array
{
    $fields = [
        'full_name' => trim((string) ($_POST['full_name'] ?? '')),
        'email' => strtolower(trim((string) ($_POST['email'] ?? ''))),
        'phone' => trim((string) ($_POST['phone'] ?? '')),
        'address_line' => trim((string) ($_POST['address_line'] ?? '')),
        'city' => trim((string) ($_POST['city'] ?? '')),
        'country' => trim((string) ($_POST['country'] ?? '')),
        'postal_code' => trim((string) ($_POST['postal_code'] ?? '')),
        'removed_material_location' => trim((string) ($_POST['removed_material_location'] ?? '')),
        'mistake_statement' => trim((string) ($_POST['mistake_statement'] ?? '')),
        'jurisdiction_statement' => trim((string) ($_POST['jurisdiction_statement'] ?? '')),
        'signature_name' => trim((string) ($_POST['signature_name'] ?? '')),
    ];

    foreach ([
        'full_name' => 'full name',
        'email' => 'email address',
        'phone' => 'phone number',
        'address_line' => 'street address',
        'city' => 'city',
        'country' => 'country',
        'removed_material_location' => 'removed material location',
        'mistake_statement' => 'mistake statement',
        'jurisdiction_statement' => 'jurisdiction statement',
        'signature_name' => 'signature',
    ] as $key => $label) {
        if ($fields[$key] === '') {
            throw new InvalidArgumentException('Enter your ' . $label . '.');
        }
    }

    if (!filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Enter a valid email address.');
    }

    return $fields;
}

function ve_dmca_submit_counter_notice(int $userId, string $caseCode): array
{
    $notice = ve_dmca_notice_by_case_code($userId, $caseCode);

    if (!is_array($notice)) {
        throw new RuntimeException('DMCA case not found.');
    }

    if ((string) ($notice['status'] ?? '') !== VE_DMCA_NOTICE_STATUS_CONTENT_DISABLED) {
        throw new InvalidArgumentException('This case is not currently eligible for a counter notice.');
    }

    if (ve_dmca_counter_notice((int) ($notice['id'] ?? 0)) !== null) {
        throw new InvalidArgumentException('A counter notice has already been submitted for this case.');
    }

    $fields = ve_dmca_validate_counter_notice_input();
    $now = ve_now();

    ve_db()->prepare(
        'INSERT INTO dmca_counter_notices (
            notice_id, user_id, status, full_name, email, phone, address_line, city, country,
            postal_code, removed_material_location, mistake_statement, jurisdiction_statement,
            signature_name, submitted_at, updated_at
         ) VALUES (
            :notice_id, :user_id, :status, :full_name, :email, :phone, :address_line, :city, :country,
            :postal_code, :removed_material_location, :mistake_statement, :jurisdiction_statement,
            :signature_name, :submitted_at, :updated_at
         )'
    )->execute([
        ':notice_id' => (int) ($notice['id'] ?? 0),
        ':user_id' => $userId,
        ':status' => VE_DMCA_COUNTER_STATUS_SUBMITTED,
        ':full_name' => $fields['full_name'],
        ':email' => $fields['email'],
        ':phone' => $fields['phone'],
        ':address_line' => $fields['address_line'],
        ':city' => $fields['city'],
        ':country' => $fields['country'],
        ':postal_code' => $fields['postal_code'],
        ':removed_material_location' => $fields['removed_material_location'],
        ':mistake_statement' => $fields['mistake_statement'],
        ':jurisdiction_statement' => $fields['jurisdiction_statement'],
        ':signature_name' => $fields['signature_name'],
        ':submitted_at' => $now,
        ':updated_at' => $now,
    ]);

    $body = 'The counter notice was logged and the standard 10 to 14 business day restoration window now applies unless the complainant reports court action.';
    return ve_dmca_update_notice_status(
        (int) ($notice['id'] ?? 0),
        VE_DMCA_NOTICE_STATUS_COUNTER_SUBMITTED,
        'counter_notice',
        'Counter notice submitted',
        $body
    );
}

function ve_dmca_list_filters(string $statusFilter, string $queryFilter): array
{
    $clauses = ['n.user_id = :user_id'];
    $params = [];

    if ($statusFilter === 'open') {
        $clauses[] = 'n.status IN ("' . VE_DMCA_NOTICE_STATUS_PENDING_REVIEW . '", "' . VE_DMCA_NOTICE_STATUS_CONTENT_DISABLED . '", "' . VE_DMCA_NOTICE_STATUS_COUNTER_SUBMITTED . '")';
    } elseif ($statusFilter === 'resolved') {
        $clauses[] = 'n.status IN ("' . VE_DMCA_NOTICE_STATUS_RESTORED . '", "' . VE_DMCA_NOTICE_STATUS_REJECTED . '", "' . VE_DMCA_NOTICE_STATUS_WITHDRAWN . '")';
    } elseif ($statusFilter !== '' && array_key_exists($statusFilter, ve_dmca_notice_status_catalog())) {
        $clauses[] = 'n.status = :status_filter';
        $params[':status_filter'] = $statusFilter;
    }

    if ($queryFilter !== '') {
        $clauses[] = '(lower(n.case_code) LIKE :query_filter OR lower(n.claimed_work) LIKE :query_filter OR lower(n.complainant_name) LIKE :query_filter OR lower(COALESCE(v.title, "")) LIKE :query_filter)';
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
    $rows = ve_db()->prepare(
        'SELECT status, effective_at
         FROM dmca_notices
         WHERE user_id = :user_id'
    );
    $rows->execute([':user_id' => $userId]);
    $items = $rows->fetchAll();
    $openCount = 0;
    $disabledCount = 0;
    $counterCount = 0;
    $restoredCount = 0;
    $strikeCount = 0;
    $policy = ve_dmca_policy_snapshot();
    $windowStart = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->sub(new DateInterval('P' . (int) $policy['repeat_infringer_window_months'] . 'M'))
        ->format('Y-m-d H:i:s');

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $status = (string) ($item['status'] ?? VE_DMCA_NOTICE_STATUS_PENDING_REVIEW);
        $effectiveAt = trim((string) ($item['effective_at'] ?? ''));

        if (ve_dmca_notice_is_open($status)) {
            $openCount++;
        }

        if ($status === VE_DMCA_NOTICE_STATUS_CONTENT_DISABLED) {
            $disabledCount++;
        }

        if ($status === VE_DMCA_NOTICE_STATUS_COUNTER_SUBMITTED) {
            $counterCount++;
        }

        if ($status === VE_DMCA_NOTICE_STATUS_RESTORED) {
            $restoredCount++;
        }

        if ($effectiveAt !== '' && $effectiveAt >= $windowStart) {
            $strikeCount++;
        }
    }

    $threshold = (int) $policy['repeat_infringer_threshold'];

    return [
        'open_cases' => $openCount,
        'content_disabled' => $disabledCount,
        'counter_notice_pending' => $counterCount,
        'restored_cases' => $restoredCount,
        'effective_strikes' => $strikeCount,
        'risk_state' => $strikeCount >= $threshold ? 'threshold_reached' : ($strikeCount === max(0, $threshold - 1) ? 'warning' : 'normal'),
    ];
}

function ve_dmca_snapshot(int $userId, string $statusFilter = '', string $queryFilter = '', int $page = 1): array
{
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

function ve_handle_dmca_counter_notice_api(string $caseCode): void
{
    $user = ve_require_auth();
    ve_require_csrf(ve_request_csrf_token());

    try {
        $notice = ve_dmca_submit_counter_notice((int) $user['id'], $caseCode);
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
        'message' => 'Counter notice submitted successfully.',
        'notice' => ve_dmca_notice_payload($notice),
        'summary' => ve_dmca_summary((int) $user['id']),
    ]);
}
