<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

ve_worker_run('remote_upload_queue', static function (): void {
    ve_remote_process_pending_jobs();
});
