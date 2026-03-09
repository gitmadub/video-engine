<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

ve_worker_run('video_queue', static function (): void {
    ve_video_process_pending_jobs();
});
