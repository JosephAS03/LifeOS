<?php

declare(strict_types=1);

namespace LifeOS\Services;

use LifeOS\Support\Tables;

final class AuditLogService
{
    public function record(string $action, ?string $objectType = null, ?int $objectId = null, array $details = [], ?int $userId = null): void
    {
        global $wpdb;

        $wpdb->insert(
            Tables::prefixed('audit_log'),
            [
                'wp_user_id' => $userId ?? get_current_user_id(),
                'action' => $action,
                'object_type' => $objectType,
                'object_id' => $objectId,
                'details_json' => wp_json_encode($details),
                'created_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['%d', '%s', '%s', '%d', '%s', '%s']
        );
    }

    public function appendTaskCompletionFile(array $payload): void
    {
        $uploads = wp_upload_dir();
        if (! is_dir($uploads['basedir'] . '/life-os')) {
            wp_mkdir_p($uploads['basedir'] . '/life-os');
        }

        $line = wp_json_encode($payload) . PHP_EOL;
        file_put_contents($uploads['basedir'] . '/life-os/task-completions.log', $line, FILE_APPEND | LOCK_EX);
    }
}

