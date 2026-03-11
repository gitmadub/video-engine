# Database Layout

Snapshot date: 2026-03-08

This document defines the recommended database layout for this project based on:

- `docs/frontend-audit.md`
- `docs/backend-endpoints.md`
- `docs/api-docs-audit.md`
- the current shipped dashboard pages under `dashboard/*.html`

It is written for a FileHost.net-style product: large video/file uploads, remote uploads, premium plans, referrals, payouts, DMCA handling, public API access, and a multi-file-server deployment that must remain workable as the platform grows to hundreds of thousands of users and very large media libraries.

This is a product/schema design document, not a literal migration file. It should drive the real migrations.

## 1. Target architecture

Use three data layers instead of forcing everything into one MySQL database:

- OLTP relational store: MySQL 8 with InnoDB for accounts, videos, folders, job state, billing, referrals, DMCA, and API keys.
- Ephemeral/cache layer: Redis for sessions, rate limits, upload chunk state, queues, and hot counters.
- Analytics/event store: ClickHouse for raw views, downloads, API usage, playback events, and large rollups. If ClickHouse is unavailable at first, keep only the essential daily aggregates in MySQL and move raw event storage out later.

Operational rule:

- Do not store video bytes, thumbnails, HLS segments, or upload chunks inside MySQL.
- Store only metadata, logical object records, placement records, and processing state in MySQL.
- Store media on storage nodes or object storage.

## 2. Design principles

- Internal primary keys: `BIGINT UNSIGNED`.
- Public IDs exposed to users and API clients: `CHAR(26)` ULID-style ids.
- All timestamps in UTC.
- User-visible entities use soft delete where recovery matters.
- Money uses integer minor units, not floating point.
- Secrets are stored as hashes or encrypted values, never plaintext.
- Every financial mutation is append-only in a ledger table.
- Every physical file copy is separate from the logical video record.
- Large event volumes belong in analytics storage, not the OLTP database.

Recommended numeric conventions:

- Fiat amounts: `BIGINT` in micro-USD or cents.
- Crypto display amounts: keep provider-facing values as `DECIMAL(36,18)` only where needed.
- Bandwidth/storage: `BIGINT UNSIGNED` bytes.

## 3. High-level entity map

Core relationships:

- `users` -> `folders` -> `videos`
- `videos` -> `media_objects` -> `media_object_copies`
- `users` -> `upload_sessions` -> `ingest_jobs` -> `videos`
- `users` -> `remote_upload_jobs` -> `ingest_jobs`
- `users` -> `wallet_accounts` -> `wallet_ledger`
- `users` -> `user_subscriptions` -> `payment_transactions`
- `users` -> `referral_relationships` -> `referral_commissions`
- `users` -> `api_keys` -> `api_usage_daily`
- `dmca_cases` -> `dmca_case_items` -> `videos`

## 4. Recommended logical schemas

Keep one MySQL database at first if needed, but group tables as if they belong to these logical schemas:

- `core`: users, folders, videos, settings, notifications
- `storage`: regions, nodes, volumes, upload endpoints, file placement
- `processing`: ingest, transcode, replication, remote upload jobs
- `billing`: subscriptions, payments, payouts, referrals, wallets
- `trust`: DMCA, abuse, audit, moderation
- `api`: API keys, scopes, usage aggregates
- `reporting`: daily/hourly aggregates if ClickHouse is not ready yet

## 5. Reference tables

These are low-churn lookup tables and should exist early.

| Table | Purpose | Key columns | Important indexes |
| --- | --- | --- | --- |
| `countries` | ISO country catalog for earnings, geo reports, compliance | `id`, `iso2`, `iso3`, `name`, `region_code`, `is_eu` | unique `iso2`, unique `iso3` |
| `currencies` | Fiat and crypto currency metadata | `id`, `code`, `name`, `type`, `decimals`, `is_active` | unique `code` |
| `languages` | Subtitle/player language support | `id`, `code`, `name`, `is_active` | unique `code` |
| `payout_methods` | Supported payout rails | `id`, `code`, `name`, `currency_id`, `min_payout_minor`, `fee_mode`, `fee_value`, `eta_text`, `is_active` | unique `code` |
| `payment_methods` | Supported inbound payment methods | `id`, `code`, `provider_code`, `name`, `supports_recurring`, `supports_bandwidth`, `is_active` | unique `code` |
| `plan_catalog` | Premium and add-on products | `id`, `code`, `name`, `plan_type`, `duration_days`, `bandwidth_bytes`, `storage_bytes`, `is_active` | unique `code`, `plan_type` |
| `earning_tiers` | Ad revenue / payout rates by country group or traffic class | `id`, `code`, `name`, `traffic_type`, `is_active` | unique `code` |
| `feature_flags` | Rollout toggles | `id`, `code`, `description`, `default_state`, `created_at`, `updated_at` | unique `code` |

## 6. Identity, auth, and account state

| Table | Purpose | Key columns | Important indexes |
| --- | --- | --- | --- |
| `users` | Primary account record | `id`, `public_id`, `username`, `email`, `password_hash`, `status`, `role_code`, `email_verified_at`, `last_login_at`, `last_seen_at`, `country_id`, `timezone`, `referred_by_user_id`, `plan_status`, `is_banned`, `created_at`, `updated_at`, `deleted_at` | unique `public_id`, unique `username`, unique `email`, `status, created_at`, `referred_by_user_id` |
| `user_profiles` | Non-auth profile data | `user_id`, `display_name`, `avatar_path`, `company_name`, `contact_email`, `support_notes`, `preferred_language_id`, `marketing_opt_in`, `created_at`, `updated_at` | primary `user_id` |
| `user_security` | Security posture and risk settings | `user_id`, `password_changed_at`, `force_password_reset`, `two_factor_required`, `login_risk_level`, `failed_login_count`, `locked_until`, `last_password_reset_at`, `created_at`, `updated_at` | primary `user_id`, `locked_until` |
| `user_sessions` | Persistent login sessions | `id`, `public_id`, `user_id`, `session_token_hash`, `ip_address`, `user_agent`, `device_label`, `last_seen_at`, `expires_at`, `revoked_at`, `created_at` | unique `public_id`, unique `session_token_hash`, `user_id, expires_at` |
| `user_2fa_methods` | TOTP, email OTP, backup codes | `id`, `user_id`, `method_type`, `secret_encrypted`, `label`, `is_primary`, `is_verified`, `last_used_at`, `created_at`, `deleted_at` | `user_id, method_type`, `user_id, is_primary` |
| `email_verification_tokens` | Email verification flow | `id`, `user_id`, `token_hash`, `expires_at`, `used_at`, `created_at` | unique `token_hash`, `user_id, expires_at` |
| `password_reset_tokens` | Password reset flow | `id`, `user_id`, `token_hash`, `expires_at`, `used_at`, `created_at` | unique `token_hash`, `user_id, expires_at` |
| `login_attempts` | Auth abuse and security review | `id`, `user_id`, `email_attempted`, `username_attempted`, `ip_address`, `user_agent`, `result_code`, `risk_score`, `created_at` | `user_id, created_at`, `ip_address, created_at`, `email_attempted, created_at` |
| `roles` | Administrative and service roles | `id`, `code`, `name`, `is_system`, `created_at` | unique `code` |
| `permissions` | Permission catalog | `id`, `code`, `name`, `group_code` | unique `code` |
| `role_permissions` | Role to permission map | `role_id`, `permission_id`, `created_at` | unique `role_id, permission_id` |
| `user_role_assignments` | Extra role assignments beyond base role | `id`, `user_id`, `role_id`, `granted_by_user_id`, `created_at`, `expires_at`, `revoked_at` | `user_id, role_id`, `role_id, expires_at` |
| `notifications` | In-app notices shown in dashboard | `id`, `public_id`, `user_id`, `type_code`, `title`, `body`, `action_url`, `severity`, `is_read_default`, `created_at`, `expires_at` | unique `public_id`, `user_id, created_at`, `user_id, expires_at` |
| `notification_receipts` | Per-user read/dismiss state | `notification_id`, `user_id`, `seen_at`, `read_at`, `dismissed_at` | unique `notification_id, user_id`, `user_id, read_at` |
| `audit_logs` | Administrative and sensitive change history | `id`, `actor_user_id`, `target_type`, `target_id`, `event_code`, `before_json`, `after_json`, `ip_address`, `created_at` | `target_type, target_id, created_at`, `actor_user_id, created_at`, `event_code, created_at` |

## 7. User settings, player config, payout profile, FTP

The current dashboard already implies these settings.

| Table | Purpose | Key columns | Important indexes |
| --- | --- | --- | --- |
| `user_settings` | Main account settings payload | `user_id`, `uploader_content_type`, `ads_mode`, `embed_access_only`, `disable_download`, `disable_adblock`, `extract_subtitles_from_mkv`, `allow_remote_upload`, `created_at`, `updated_at` | primary `user_id` |
| `user_embed_domain_rules` | Allowed embed hosts | `id`, `user_id`, `domain`, `match_type`, `is_active`, `created_at`, `updated_at` | unique `user_id, domain`, `domain` |
| `user_custom_domains` | User-managed redirect/watch domains from settings | `id`, `user_id`, `domain`, `redirect_domain_id`, `status`, `dns_last_checked_at`, `dns_check_error`, `created_at`, `updated_at`, `deleted_at` | unique `domain`, `user_id, status`, `redirect_domain_id, status` |
| `user_player_profiles` | Custom player settings and branding | `id`, `user_id`, `name`, `logo_media_object_id`, `poster_media_object_id`, `primary_color`, `intro_seconds`, `outro_seconds`, `allow_remote_poster`, `allow_remote_subtitles`, `created_at`, `updated_at` | `user_id, created_at`, `user_id, name` |
| `user_ad_profiles` | Own-advert settings | `id`, `user_id`, `profile_name`, `vast_tag_url`, `banner_html`, `click_url`, `status`, `created_at`, `updated_at` | `user_id, status`, `user_id, profile_name` |
| `user_payout_accounts` | Saved payout destination(s) | `id`, `user_id`, `payout_method_id`, `account_label`, `account_identifier_encrypted`, `account_last4`, `is_default`, `is_verified`, `created_at`, `updated_at`, `deleted_at` | `user_id, is_default`, `user_id, payout_method_id` |
| `user_api_preferences` | API and automation preferences | `user_id`, `default_folder_id`, `default_upload_server_id`, `webhook_url`, `webhook_signing_secret_hash`, `created_at`, `updated_at` | primary `user_id` |
| `ftp_servers` | FTP server catalog shown in settings | `id`, `code`, `hostname`, `port`, `region_id`, `data_center_name`, `status`, `is_global`, `created_at`, `updated_at` | unique `code`, unique `hostname`, `region_id, status` |
| `user_ftp_accounts` | FTP credentials or binding | `id`, `user_id`, `username`, `password_hash_or_secret_ref`, `home_prefix`, `status`, `created_at`, `rotated_at` | unique `user_id`, unique `username`, `status` |
| `user_limits` | Hard limits and quotas resolved for a user | `user_id`, `max_file_size_bytes`, `max_storage_bytes`, `max_remote_slots`, `max_api_requests_per_minute`, `max_concurrent_uploads`, `created_at`, `updated_at` | primary `user_id` |
| `account_deletion_requests` | Delete-account workflow from settings | `id`, `user_id`, `reason_code`, `requested_at`, `confirmed_at`, `cancelled_at`, `processed_at`, `status`, `review_notes`, `created_at`, `updated_at` | `user_id, status`, `requested_at`, `processed_at` |

## 8. Multi-file-server storage topology

This is the most important part for scale. Never tie a video directly to one server row only.

| Table | Purpose | Key columns | Important indexes |
| --- | --- | --- | --- |
| `regions` | Geographic regions | `id`, `code`, `name`, `is_active` | unique `code` |
| `data_centers` | Physical or cloud locations | `id`, `region_id`, `code`, `name`, `provider_name`, `city`, `country_id`, `is_active` | unique `code`, `region_id, is_active` |
| `storage_pools` | Logical pools for placement policies | `id`, `code`, `name`, `pool_type`, `region_id`, `priority`, `is_public_upload_pool`, `is_active`, `created_at` | unique `code`, `region_id, priority` |
| `storage_nodes` | File servers or object-storage mounts | `id`, `public_id`, `storage_pool_id`, `data_center_id`, `code`, `hostname`, `private_ip`, `public_base_url`, `upload_base_url`, `health_status`, `available_bytes`, `used_bytes`, `reserved_bytes`, `max_ingest_qps`, `max_stream_qps`, `created_at`, `updated_at` | unique `public_id`, unique `code`, unique `hostname`, `storage_pool_id, health_status`, `data_center_id, health_status` |
| `storage_volumes` | Disks/buckets attached to a node | `id`, `storage_node_id`, `code`, `mount_path`, `volume_type`, `capacity_bytes`, `used_bytes`, `reserved_bytes`, `health_status`, `created_at`, `updated_at` | unique `storage_node_id, code`, `storage_node_id, health_status` |
| `upload_endpoints` | Upload negotiation targets | `id`, `storage_node_id`, `code`, `protocol`, `host`, `path_prefix`, `weight`, `is_active`, `max_file_size_bytes`, `accepts_remote_upload`, `created_at`, `updated_at` | unique `code`, `storage_node_id, is_active`, `is_active, weight` |
| `delivery_domains` | Public watch/embed/download domains | `id`, `region_id`, `domain`, `purpose`, `status`, `tls_mode`, `created_at`, `updated_at` | unique `domain`, `region_id, purpose, status` |
| `node_bandwidth_counters` | Current-period bandwidth snapshot for placement and throttling | `id`, `storage_node_id`, `period_start`, `ingress_bytes`, `egress_bytes`, `request_count`, `updated_at` | unique `storage_node_id, period_start` |
| `storage_maintenance_windows` | Planned maintenance or drain state | `id`, `storage_node_id`, `starts_at`, `ends_at`, `mode`, `reason`, `created_by_user_id`, `created_at` | `storage_node_id, starts_at`, `ends_at` |

## 9. Folder and library structure

| Table | Purpose | Key columns | Important indexes |
| --- | --- | --- | --- |
| `folders` | User folder tree | `id`, `public_id`, `user_id`, `parent_folder_id`, `name`, `slug`, `path_cache`, `sort_order`, `is_deleted`, `created_at`, `updated_at`, `deleted_at` | unique `public_id`, unique `user_id, parent_folder_id, name`, `user_id, path_cache`, `user_id, deleted_at` |
| `folder_shares` | Sharing policy for folders | `id`, `folder_id`, `share_type`, `access_code_hash`, `expires_at`, `created_by_user_id`, `created_at`, `revoked_at` | `folder_id, revoked_at`, `share_type, expires_at` |
| `folder_members` | Shared folder access for team/admin use later | `id`, `folder_id`, `user_id`, `permission_code`, `created_at`, `revoked_at` | unique `folder_id, user_id`, `user_id, revoked_at` |
| `video_tags` | User-defined tags | `id`, `user_id`, `name`, `color`, `created_at` | unique `user_id, name` |
| `video_tag_links` | Tag mapping | `video_id`, `tag_id`, `created_at` | unique `video_id, tag_id`, `tag_id` |

## 10. Video catalog and logical media model

The logical video row must be separate from the physical file object and from replicated copies.

| Table | Purpose | Key columns | Important indexes |
| --- | --- | --- | --- |
| `videos` | Main user-visible file/video row | `id`, `public_id`, `user_id`, `folder_id`, `title`, `slug`, `description`, `status`, `visibility`, `source_type`, `content_rating`, `duration_seconds`, `file_size_bytes`, `mime_type`, `checksum_sha256`, `primary_media_object_id`, `primary_thumbnail_id`, `primary_subtitle_id`, `download_enabled`, `embed_enabled`, `direct_access_enabled`, `views_count_cached`, `downloads_count_cached`, `created_at`, `updated_at`, `deleted_at` | unique `public_id`, `user_id, folder_id, created_at`, `user_id, status, created_at`, `checksum_sha256`, `folder_id, deleted_at` |
| `video_versions` | Revision history when metadata changes or file is replaced | `id`, `video_id`, `version_number`, `title`, `description`, `status`, `created_by_user_id`, `created_at` | unique `video_id, version_number`, `video_id, created_at` |
| `media_objects` | Logical media asset for original file or derived file | `id`, `public_id`, `owner_user_id`, `object_type`, `lifecycle_status`, `canonical_filename`, `storage_class`, `size_bytes`, `mime_type`, `checksum_sha256`, `checksum_md5`, `created_at`, `updated_at` | unique `public_id`, `owner_user_id, object_type, created_at`, `checksum_sha256` |
| `media_object_copies` | Each physical copy of an object on a server/volume | `id`, `media_object_id`, `storage_node_id`, `storage_volume_id`, `relative_path`, `region_id`, `copy_role`, `copy_status`, `is_primary_serving_copy`, `checksum_sha256`, `size_bytes`, `last_verified_at`, `created_at`, `deleted_at` | unique `storage_node_id, relative_path`, `media_object_id, copy_status`, `media_object_id, region_id`, `storage_node_id, copy_status` |
| `video_assets` | Links a video to logical media objects | `id`, `video_id`, `media_object_id`, `asset_role`, `sort_order`, `is_active`, `created_at` | unique `video_id, asset_role, sort_order`, `media_object_id, asset_role` |
| `video_stream_variants` | Encoded playback variants | `id`, `video_id`, `media_object_id`, `variant_type`, `container`, `codec_video`, `codec_audio`, `width`, `height`, `bitrate_kbps`, `duration_seconds`, `status`, `created_at`, `updated_at` | `video_id, status`, `video_id, variant_type, height`, `media_object_id` |
| `video_manifests` | HLS/DASH manifest records | `id`, `video_id`, `media_object_id`, `manifest_type`, `status`, `created_at`, `updated_at` | `video_id, manifest_type, status` |
| `video_thumbnails` | Posters and preview images | `id`, `video_id`, `media_object_id`, `thumb_type`, `width`, `height`, `sort_order`, `created_at` | `video_id, thumb_type, sort_order`, `media_object_id` |
| `video_subtitles` | Uploaded, extracted, or remote-imported subtitles | `id`, `video_id`, `media_object_id`, `language_id`, `label`, `source_type`, `is_default`, `status`, `created_at`, `updated_at` | `video_id, language_id`, `video_id, is_default`, `media_object_id` |
| `video_markers` | Intro/outro/skip markers | `id`, `video_id`, `marker_type`, `start_ms`, `end_ms`, `created_by_user_id`, `created_at`, `updated_at` | `video_id, marker_type` |
| `video_checks` | Fast lookup for duplicate detection and API `file/check` | `id`, `video_id`, `checksum_sha256`, `checksum_md5`, `size_bytes`, `created_at` | unique `checksum_sha256`, `checksum_md5, size_bytes`, `video_id` |
| `video_public_links` | Watch/embed/download URLs and aliases | `id`, `video_id`, `link_type`, `domain_id`, `path_token`, `status`, `expires_at`, `created_at` | unique `domain_id, path_token`, `video_id, link_type, status` |
| `video_access_rules` | Per-video access rules | `video_id`, `password_hash`, `geo_allow_json`, `geo_block_json`, `require_embed_domain_match`, `require_signed_url`, `max_views_per_day`, `created_at`, `updated_at` | primary `video_id` |
| `video_moderation_flags` | Content flags and moderation state | `id`, `video_id`, `flag_code`, `source`, `severity`, `status`, `reviewed_by_user_id`, `created_at`, `updated_at` | `video_id, status`, `flag_code, status`, `created_at` |

## 11. Upload, ingest, processing, and replication

| Table | Purpose | Key columns | Important indexes |
| --- | --- | --- | --- |
| `upload_sessions` | Upload negotiation and front-end handoff | `id`, `public_id`, `user_id`, `upload_endpoint_id`, `target_folder_id`, `client_filename`, `expected_size_bytes`, `mime_type`, `session_status`, `resume_token_hash`, `expires_at`, `completed_at`, `created_at`, `updated_at` | unique `public_id`, unique `resume_token_hash`, `user_id, session_status, created_at`, `upload_endpoint_id, session_status` |
| `upload_session_files` | Multi-file upload membership | `id`, `upload_session_id`, `client_file_key`, `client_filename`, `expected_size_bytes`, `upload_status`, `created_at`, `updated_at` | unique `upload_session_id, client_file_key`, `upload_session_id, upload_status` |
| `ingest_jobs` | Canonical ingest pipeline job | `id`, `public_id`, `user_id`, `source_type`, `source_ref`, `target_video_id`, `target_folder_id`, `job_status`, `priority`, `error_code`, `error_message`, `started_at`, `finished_at`, `created_at`, `updated_at` | unique `public_id`, `user_id, job_status, created_at`, `target_video_id`, `source_type, created_at` |
| `ingest_artifacts` | Files discovered/generated during ingest | `id`, `ingest_job_id`, `media_object_id`, `artifact_role`, `status`, `created_at` | `ingest_job_id, artifact_role`, `media_object_id` |
| `transcode_jobs` | Video encoding jobs | `id`, `video_id`, `input_media_object_id`, `target_profile`, `job_status`, `priority`, `worker_node`, `attempt_count`, `error_code`, `started_at`, `finished_at`, `created_at`, `updated_at` | `video_id, job_status`, `job_status, priority, created_at`, `input_media_object_id` |
| `thumbnail_jobs` | Thumbnail extraction jobs | `id`, `video_id`, `job_status`, `frame_selection_mode`, `started_at`, `finished_at`, `created_at` | `video_id, job_status` |
| `subtitle_jobs` | Subtitle extraction/import jobs | `id`, `video_id`, `job_type`, `source_url`, `language_id`, `job_status`, `error_message`, `created_at`, `updated_at` | `video_id, job_status`, `job_type, created_at` |
| `replication_jobs` | Copy media to additional nodes/regions | `id`, `media_object_id`, `source_copy_id`, `target_storage_node_id`, `target_storage_volume_id`, `job_status`, `priority`, `started_at`, `finished_at`, `created_at` | `media_object_id, job_status`, `target_storage_node_id, job_status`, `priority, created_at` |
| `rebalancing_jobs` | Move media when nodes fill or fail | `id`, `media_object_id`, `from_copy_id`, `to_storage_node_id`, `reason_code`, `job_status`, `created_at`, `finished_at` | `job_status, created_at`, `to_storage_node_id, job_status` |
| `deletion_jobs` | Deferred hard delete pipeline | `id`, `video_id`, `media_object_id`, `job_status`, `delete_after`, `created_at`, `finished_at` | `delete_after, job_status`, `video_id` |
| `job_failures` | Dead-letter style failure history | `id`, `job_type`, `job_id`, `error_code`, `error_message`, `payload_json`, `first_seen_at`, `last_seen_at`, `retry_count` | `job_type, job_id`, `last_seen_at` |

Implementation note:

- Upload chunk manifests can live in Redis or object storage during active transfer.
- Keep only session-level state and final reconciliation in MySQL unless resumable uploads must survive cache loss.

## 12. Remote upload pipeline

The current frontend and API docs both require more than a simple queue table.

| Table | Purpose | Key columns | Important indexes |
| --- | --- | --- | --- |
| `remote_upload_jobs` | One remote URL upload request | `id`, `public_id`, `user_id`, `source_url`, `normalized_host`, `target_folder_id`, `target_filename`, `job_status`, `priority`, `slot_type`, `current_attempt_no`, `downloaded_bytes`, `total_bytes`, `error_code`, `error_message`, `created_at`, `updated_at`, `started_at`, `finished_at` | unique `public_id`, `user_id, job_status, created_at`, `normalized_host, created_at`, `target_folder_id, created_at` |
| `remote_upload_attempts` | Retry history and worker trace | `id`, `remote_upload_job_id`, `attempt_no`, `worker_node`, `request_headers_json`, `response_status`, `downloaded_bytes`, `error_code`, `error_message`, `started_at`, `finished_at`, `created_at` | unique `remote_upload_job_id, attempt_no`, `worker_node, started_at` |
| `remote_upload_slots` | User slot accounting for concurrent remote uploads | `id`, `user_id`, `slot_type`, `capacity`, `used`, `updated_at` | unique `user_id, slot_type` |
| `supported_remote_hosts` | Host allow/deny and slot policy | `id`, `hostname`, `status`, `requires_premium`, `max_concurrency`, `notes`, `created_at`, `updated_at` | unique `hostname`, `status` |

## 13. Billing, premium, bandwidth, wallets, payouts, referrals

Do not rely on mutable balances alone. Use a ledger.

| Table | Purpose | Key columns | Important indexes |
| --- | --- | --- | --- |
| `user_subscriptions` | Premium state and renewal info | `id`, `public_id`, `user_id`, `plan_id`, `status`, `started_at`, `renews_at`, `ends_at`, `cancel_at_period_end`, `provider_code`, `provider_subscription_ref`, `created_at`, `updated_at` | unique `public_id`, `user_id, status`, `provider_code, provider_subscription_ref` |
| `user_bandwidth_balances` | Purchased bandwidth buckets | `id`, `user_id`, `source_plan_id`, `granted_bytes`, `used_bytes`, `expires_at`, `created_at`, `updated_at` | `user_id, expires_at`, `user_id, created_at` |
| `payment_providers` | PayPal, crypto gateways, card processors | `id`, `code`, `name`, `provider_type`, `is_active`, `created_at` | unique `code` |
| `payment_intents` | Pre-checkout request state | `id`, `public_id`, `user_id`, `provider_id`, `payment_method_id`, `purchase_type`, `plan_id`, `amount_minor`, `currency_id`, `status`, `redirect_url`, `provider_payload_json`, `expires_at`, `created_at`, `updated_at` | unique `public_id`, `user_id, status, created_at`, `provider_id, status` |
| `payment_transactions` | Confirmed and attempted provider transactions | `id`, `public_id`, `user_id`, `payment_intent_id`, `provider_id`, `provider_transaction_ref`, `provider_status`, `amount_minor`, `fee_minor`, `currency_id`, `status`, `settled_at`, `created_at`, `updated_at` | unique `public_id`, unique `provider_id, provider_transaction_ref`, `user_id, created_at`, `status, settled_at` |
| `subscription_invoices` | Invoice trail for recurring billing | `id`, `user_subscription_id`, `payment_transaction_id`, `period_start`, `period_end`, `amount_minor`, `currency_id`, `status`, `created_at` | `user_subscription_id, period_start`, `payment_transaction_id` |
| `wallet_accounts` | User wallet summary rows | `id`, `user_id`, `wallet_type`, `currency_id`, `balance_minor_cached`, `pending_minor_cached`, `created_at`, `updated_at` | unique `user_id, wallet_type, currency_id` |
| `wallet_ledger` | Immutable ledger for earnings, purchases, payouts, referrals | `id`, `public_id`, `wallet_account_id`, `user_id`, `entry_type`, `direction`, `amount_minor`, `currency_id`, `source_type`, `source_id`, `reference_key`, `available_at`, `created_at` | unique `public_id`, `wallet_account_id, created_at`, `user_id, entry_type, created_at`, unique `reference_key` |
| `payout_requests` | User payout submissions | `id`, `public_id`, `user_id`, `wallet_account_id`, `user_payout_account_id`, `amount_minor`, `currency_id`, `status`, `reviewed_by_user_id`, `reviewed_at`, `rejection_reason`, `created_at`, `updated_at` | unique `public_id`, `user_id, status, created_at`, `reviewed_by_user_id, reviewed_at` |
| `payout_transfers` | Actual transfer execution | `id`, `payout_request_id`, `provider_id`, `provider_transfer_ref`, `gross_amount_minor`, `fee_minor`, `net_amount_minor`, `status`, `sent_at`, `settled_at`, `created_at`, `updated_at` | unique `provider_id, provider_transfer_ref`, `payout_request_id`, `status, sent_at` |
| `referral_codes` | Public invite/join codes | `id`, `user_id`, `code`, `status`, `created_at`, `updated_at` | unique `code`, unique `user_id` |
| `referral_relationships` | Referrer to referred user link | `id`, `referrer_user_id`, `referred_user_id`, `referral_code_id`, `joined_at`, `status` | unique `referred_user_id`, `referrer_user_id, joined_at` |
| `referral_commissions` | Earnings from referrals | `id`, `public_id`, `referrer_user_id`, `referred_user_id`, `source_type`, `source_id`, `commission_rate_bps`, `amount_minor`, `status`, `created_at`, `available_at` | unique `public_id`, `referrer_user_id, created_at`, `referred_user_id, created_at`, `source_type, source_id` |
| `earning_rate_cards` | Country-group and traffic monetization rates | `id`, `earning_tier_id`, `country_id`, `traffic_class`, `rate_per_thousand_minor`, `effective_from`, `effective_to`, `created_at` | `country_id, effective_from`, `earning_tier_id, traffic_class, effective_from` |

## 14. Public API platform

This schema must support the API surface currently advertised on `/api-docs`, even though that API is not implemented yet.

| Table | Purpose | Key columns | Important indexes |
| --- | --- | --- | --- |
| `api_keys` | API key issuance and rotation | `id`, `public_id`, `user_id`, `label`, `key_prefix`, `key_hash`, `status`, `last_used_at`, `expires_at`, `created_at`, `revoked_at` | unique `public_id`, unique `key_prefix`, unique `key_hash`, `user_id, status` |
| `api_key_scopes` | Scope grants per API key | `id`, `api_key_id`, `scope_code`, `created_at` | unique `api_key_id, scope_code`, `scope_code` |
| `api_rate_limit_policies` | Persistent policy definitions | `id`, `subject_type`, `subject_id`, `scope_code`, `window_seconds`, `max_requests`, `created_at`, `updated_at` | `subject_type, subject_id, scope_code` |
| `webhook_endpoints` | Outbound callbacks for future automation | `id`, `user_id`, `url`, `secret_hash`, `event_mask_json`, `status`, `created_at`, `updated_at` | `user_id, status`, `created_at` |
| `webhook_deliveries` | Delivery attempts and retries | `id`, `webhook_endpoint_id`, `event_type`, `payload_hash`, `response_code`, `status`, `attempt_no`, `scheduled_at`, `sent_at`, `created_at` | `webhook_endpoint_id, status`, `scheduled_at, status`, `payload_hash` |

## 15. DMCA, abuse, support, and trust operations

| Table | Purpose | Key columns | Important indexes |
| --- | --- | --- | --- |
| `dmca_cases` | One legal complaint/case | `id`, `public_id`, `complainant_name`, `complainant_email`, `complainant_company`, `complaint_source`, `claim_text`, `status`, `received_at`, `reviewed_by_user_id`, `reviewed_at`, `created_at`, `updated_at` | unique `public_id`, `status, received_at`, `complainant_email, received_at` |
| `dmca_case_items` | Per-URL or per-video item inside a case | `id`, `dmca_case_id`, `video_id`, `reported_url`, `claim_type`, `status`, `action_taken`, `created_at`, `updated_at` | `dmca_case_id, status`, `video_id, status`, `reported_url` |
| `abuse_reports` | Non-DMCA abuse reports | `id`, `public_id`, `reporter_email`, `report_type`, `video_id`, `reported_url`, `details`, `status`, `created_at`, `updated_at` | unique `public_id`, `report_type, status, created_at`, `video_id, status` |
| `repeat_infringer_actions` | Escalation and account actions | `id`, `user_id`, `dmca_case_id`, `action_type`, `reason`, `effective_at`, `expires_at`, `created_by_user_id`, `created_at` | `user_id, effective_at`, `action_type, effective_at` |
| `contact_messages` | Public contact form or mailbox ingestion | `id`, `public_id`, `name`, `email`, `subject`, `message`, `status`, `created_at` | unique `public_id`, `email, created_at`, `status, created_at` |
| `support_tickets` | User support cases | `id`, `public_id`, `user_id`, `category_code`, `priority`, `status`, `subject`, `assigned_to_user_id`, `created_at`, `updated_at`, `closed_at` | unique `public_id`, `user_id, status, created_at`, `assigned_to_user_id, status` |
| `support_ticket_messages` | Ticket thread messages | `id`, `support_ticket_id`, `sender_type`, `sender_user_id`, `body`, `attachment_media_object_id`, `created_at` | `support_ticket_id, created_at`, `sender_user_id, created_at` |
| `admin_notes` | Internal notes on users, videos, cases, payouts | `id`, `target_type`, `target_id`, `author_user_id`, `body`, `created_at` | `target_type, target_id, created_at`, `author_user_id, created_at` |

## 16. Reporting and aggregate tables in MySQL

If ClickHouse is not live on day one, keep only rollups here. Do not insert every raw view event into these tables.

| Table | Purpose | Key columns | Important indexes |
| --- | --- | --- | --- |
| `video_stats_hourly` | Hourly playback/download aggregate | `video_id`, `bucket_start`, `views`, `downloads`, `watch_seconds`, `earned_minor`, `bandwidth_bytes` | unique `video_id, bucket_start`, `bucket_start` |
| `video_stats_daily` | Daily aggregate by video | `video_id`, `stat_date`, `views`, `downloads`, `earned_minor`, `bandwidth_bytes`, `unique_viewers` | unique `video_id, stat_date`, `stat_date` |
| `user_stats_daily` | Daily account/report page aggregate | `user_id`, `stat_date`, `views`, `downloads`, `earned_minor`, `referral_earned_minor`, `bandwidth_bytes`, `storage_bytes` | unique `user_id, stat_date`, `stat_date` |
| `country_revenue_daily` | Revenue by owner and viewer country | `user_id`, `country_id`, `stat_date`, `views`, `earned_minor` | unique `user_id, country_id, stat_date`, `country_id, stat_date` |
| `api_usage_daily` | Daily API usage for dashboard and throttling review | `user_id`, `api_key_id`, `stat_date`, `request_count`, `error_count`, `upload_requests`, `remote_upload_requests` | unique `user_id, api_key_id, stat_date`, `stat_date` |
| `storage_usage_daily` | Daily storage footprint | `user_id`, `stat_date`, `video_count`, `media_object_count`, `logical_bytes`, `physical_bytes` | unique `user_id, stat_date`, `stat_date` |
| `node_usage_daily` | Storage node capacity and egress snapshots | `storage_node_id`, `stat_date`, `used_bytes`, `reserved_bytes`, `ingress_bytes`, `egress_bytes`, `request_count` | unique `storage_node_id, stat_date`, `stat_date` |

## 17. Raw analytics and event store

These should live in ClickHouse or another append-only analytics system.

Required event families:

- `view_events`
- `download_events`
- `player_events`
- `embed_events`
- `api_request_events`
- `upload_events`
- `remote_upload_events`
- `billing_events`

Recommended event columns:

- `event_time`
- `event_date`
- `user_id`
- `video_id`
- `media_object_id`
- `api_key_id`
- `country_code`
- `ip_hash`
- `user_agent_hash`
- `referrer_domain`
- `domain`
- `status_code`
- `bandwidth_bytes`
- `duration_ms`
- `revenue_minor`
- `payload_json`

Partitioning recommendation:

- Partition by day or month, depending on event volume.
- Order by `event_date`, `video_id`, `user_id`, `event_time`.

## 18. Status vocabularies

Keep status enums narrow and explicit.

Recommended user statuses:

- `pending_verification`
- `active`
- `suspended`
- `banned`
- `closed`

Recommended video statuses:

- `uploading`
- `ingesting`
- `processing`
- `ready`
- `quarantined`
- `dmca_blocked`
- `deleted`
- `failed`

Recommended job statuses:

- `queued`
- `running`
- `retrying`
- `completed`
- `failed`
- `cancelled`

Recommended payout statuses:

- `pending`
- `under_review`
- `approved`
- `rejected`
- `sent`
- `settled`
- `failed`

## 19. What this layout covers from the current frontend and API docs

Frontend-backed features covered by this schema:

- login, registration, sessions, OTP, notifications
- account settings, payout settings, ads mode, content type, embed restrictions
- API key generation and rotation
- FTP server listing and FTP account binding
- upload negotiation and multi-file uploads
- videos, folders, thumbnails, markers, subtitles, sharing
- remote upload queue with retries and slots
- premium plans, bandwidth packages, balance and checkout state
- referrals, lifetime commission tracking, payout requests
- DMCA manager, abuse handling, repeat infringer actions
- dashboard and reports by date, country, bandwidth, earnings

Advertised `/api-docs` surface covered by this schema:

- `/api/account/info` -> `users`, `user_profiles`, `user_settings`, `wallet_accounts`
- `/api/account/stats` -> `user_stats_daily`, `storage_usage_daily`, `api_usage_daily`
- `/api/dmca/list` -> `dmca_cases`, `dmca_case_items`
- `/api/upload/server` -> `upload_endpoints`, `storage_nodes`, `storage_pools`
- `/api/file/clone` -> `videos`, `media_objects`, `replication_jobs`
- `/api/upload/url` -> `remote_upload_jobs`, `remote_upload_attempts`, `remote_upload_slots`
- `/api/urlupload/list` -> `remote_upload_jobs`
- `/api/urlupload/status` -> `remote_upload_jobs`, `remote_upload_attempts`
- `/api/urlupload/slots` -> `remote_upload_slots`, `user_limits`
- `/api/urlupload/actions` -> `remote_upload_jobs`
- `/api/folder/create`, `/rename`, `/list` -> `folders`
- `/api/file/list` -> `videos`, `folders`, `video_thumbnails`
- `/api/file/check` -> `video_checks`
- `/api/file/info` -> `videos`, `video_stream_variants`, `video_public_links`
- `/api/file/image` -> `video_thumbnails`
- `/api/file/rename`, `/move` -> `videos`, `folders`, `audit_logs`
- `/api/search/videos` -> `videos`, optional search index

## 20. Scaling and partition strategy

For MySQL OLTP:

- Keep hot tables indexed by `user_id` and time.
- Partition the largest time-series rollup tables by month.
- Consider application-level sharding of `videos`, `media_objects`, `media_object_copies`, and `wallet_ledger` only after single-cluster MySQL becomes the bottleneck.
- Use read replicas for dashboard/report queries.

For storage placement:

- Place objects by `storage_pool_id` and region policy, not by random hardcoding.
- Maintain at least one logical object row and one or more physical copy rows.
- Keep health and capacity on nodes/volumes so upload negotiation can choose good targets.

For billing:

- `wallet_ledger` should be append-only and easy to archive by month.
- Cached wallet balances are derived convenience values, not the only source of truth.

For analytics:

- Raw events must leave the OLTP path.
- Rollups should be generated asynchronously from event data.

## 21. Non-negotiable implementation rules

- No plaintext API keys, FTP passwords, payout account secrets, or reset tokens in MySQL.
- No file blobs in MySQL.
- No direct coupling between a video and only one file server.
- No balance updates without a ledger row.
- No large dashboard counters computed from raw tables on every request.
- No public API without scoped API keys and rate limiting.

## 22. Suggested rollout order

Phase 1, required immediately:

- reference tables
- users and auth tables
- user settings and payout accounts
- folders, videos, media objects, media object copies
- upload sessions and ingest jobs
- remote upload tables
- wallet, payments, payouts, referrals
- API keys
- DMCA and audit tables
- daily aggregate tables

Phase 2, required soon after launch:

- player profiles and custom adverts
- FTP server/account tables
- transcode, thumbnail, subtitle, replication jobs
- node capacity and maintenance tables
- abuse/support tables
- webhook tables

Phase 3, scale-up:

- ClickHouse raw event pipeline
- advanced moderation flags
- rebalancing jobs
- search index jobs and separate search service
- application-level sharding for the largest ownership-based tables

## 23. Final recommendation

Build the first real migrations around this exact separation:

1. identity and settings
2. library and storage topology
3. upload and remote upload
4. billing, payouts, referrals
5. DMCA, audit, and API platform
6. aggregates and external analytics pipeline

That sequence matches the current frontend gaps, the advertised API surface, and the multi-file-server requirements of a FileHost.net-scale product.
