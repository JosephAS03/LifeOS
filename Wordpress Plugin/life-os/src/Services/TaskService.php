<?php

declare(strict_types=1);

namespace LifeOS\Services;

use LifeOS\Repositories\TimelineRepository;
use LifeOS\Support\Tables;

final class TaskService
{
    public function __construct(
        private readonly TimelineRepository $timelineRepository,
        private readonly AuditLogService $auditLogService
    ) {
    }

    public function listTasks(int $userId, ?string $status = null, int $limit = 100): array
    {
        global $wpdb;

        $table = Tables::prefixed('tasks');
        $limit = max(1, min(250, $limit));

        if ($status) {
            $sql = $wpdb->prepare(
                "SELECT * FROM {$table} WHERE wp_user_id = %d AND status = %s ORDER BY COALESCE(due_at, created_at) ASC LIMIT %d",
                $userId,
                $status,
                $limit
            );
        } else {
            $sql = $wpdb->prepare(
                "SELECT * FROM {$table} WHERE wp_user_id = %d ORDER BY COALESCE(due_at, created_at) ASC LIMIT %d",
                $userId,
                $limit
            );
        }

        $rows = $wpdb->get_results($sql, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    public function createTask(int $userId, array $payload, string $source = 'manual'): array
    {
        global $wpdb;

        $now = gmdate('Y-m-d H:i:s');
        $table = Tables::prefixed('tasks');
        $title = sanitize_text_field((string) ($payload['title'] ?? ''));
        $description = isset($payload['description']) ? sanitize_textarea_field((string) $payload['description']) : null;
        $dueAt = $this->normalizeOptionalDateTime($payload['due_at'] ?? null);
        $decayHours = isset($payload['decay_after_hours']) && $payload['decay_after_hours'] !== '' ? (int) $payload['decay_after_hours'] : null;
        $priority = sanitize_key((string) ($payload['priority'] ?? 'normal'));

        $wpdb->insert(
            $table,
            [
                'wp_user_id' => $userId,
                'title' => $title,
                'description' => $description,
                'status' => 'pending',
                'due_at' => $dueAt,
                'decay_after_hours' => $decayHours,
                'priority' => $priority,
                'source' => $source,
                'metadata_json' => wp_json_encode([
                    'recurrence' => $payload['recurrence'] ?? null,
                    'created_via' => $source,
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s']
        );

        $taskId = (int) $wpdb->insert_id;

        $this->timelineRepository->upsert('task', $taskId, [
            'wp_user_id' => $userId,
            'domain' => 'tasks',
            'title' => $title,
            'summary' => $description,
            'occurred_at' => $dueAt,
            'start_at' => $dueAt,
            'precision_type' => $dueAt ? 'exact' : 'date_only',
            'source' => $source,
            'metadata' => [
                'status' => 'pending',
                'priority' => $priority,
                'decay_after_hours' => $decayHours,
            ],
        ]);

        $this->auditLogService->record('task_created', 'task', $taskId, [
            'title' => $title,
            'due_at' => $dueAt,
            'source' => $source,
        ], $userId);

        return $this->getTask($taskId);
    }

    public function completeTask(int $taskId, int $userId, string $source = 'manual'): ?array
    {
        global $wpdb;

        $table = Tables::prefixed('tasks');
        $task = $this->getTask($taskId);
        if (! $task || (int) $task['wp_user_id'] !== $userId) {
            return null;
        }

        $completedAt = gmdate('Y-m-d H:i:s');
        $wpdb->update(
            $table,
            [
                'status' => 'completed',
                'completed_at' => $completedAt,
                'updated_at' => $completedAt,
            ],
            ['id' => $taskId],
            ['%s', '%s', '%s'],
            ['%d']
        );

        $this->timelineRepository->upsert('task', $taskId, [
            'wp_user_id' => $userId,
            'domain' => 'tasks',
            'title' => $task['title'],
            'summary' => $task['description'],
            'occurred_at' => $completedAt,
            'start_at' => $task['due_at'],
            'precision_type' => 'exact',
            'source' => $source,
            'metadata' => [
                'status' => 'completed',
                'completed_at' => $completedAt,
            ],
        ]);

        $this->auditLogService->record('task_completed', 'task', $taskId, [
            'completed_at' => $completedAt,
            'source' => $source,
        ], $userId);

        $this->auditLogService->appendTaskCompletionFile([
            'task_id' => $taskId,
            'wp_user_id' => $userId,
            'title' => $task['title'],
            'completed_at' => $completedAt,
            'source' => $source,
        ]);

        return $this->getTask($taskId);
    }

    public function snoozeTask(int $taskId, int $userId, int $minutes, string $source = 'manual'): ?array
    {
        global $wpdb;

        $task = $this->getTask($taskId);
        if (! $task || (int) $task['wp_user_id'] !== $userId) {
            return null;
        }

        $baseTime = $task['due_at'] ? strtotime($task['due_at'] . ' UTC') : time();
        $dueAt = gmdate('Y-m-d H:i:s', $baseTime + max(1, $minutes) * 60);

        $wpdb->update(
            Tables::prefixed('tasks'),
            [
                'due_at' => $dueAt,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['id' => $taskId],
            ['%s', '%s'],
            ['%d']
        );

        $this->timelineRepository->upsert('task', $taskId, [
            'wp_user_id' => $userId,
            'domain' => 'tasks',
            'title' => $task['title'],
            'summary' => $task['description'],
            'occurred_at' => $dueAt,
            'start_at' => $dueAt,
            'precision_type' => 'exact',
            'source' => $source,
            'metadata' => [
                'status' => $task['status'],
                'snoozed' => true,
            ],
        ]);

        $this->auditLogService->record('task_snoozed', 'task', $taskId, [
            'due_at' => $dueAt,
            'minutes' => $minutes,
            'source' => $source,
        ], $userId);

        return $this->getTask($taskId);
    }

    public function decayDueTasks(): int
    {
        global $wpdb;

        $table = Tables::prefixed('tasks');
        $rows = $wpdb->get_results(
            "SELECT * FROM {$table} WHERE status = 'pending' AND due_at IS NOT NULL AND decay_after_hours IS NOT NULL",
            ARRAY_A
        );

        $moved = 0;

        foreach ((array) $rows as $task) {
            $decayAt = strtotime($task['due_at'] . ' UTC') + (((int) $task['decay_after_hours']) * 3600);
            if ($decayAt > time()) {
                continue;
            }

            $decayedAt = gmdate('Y-m-d H:i:s');

            $wpdb->insert(
                Tables::prefixed('task_vault'),
                [
                    'task_id' => (int) $task['id'],
                    'wp_user_id' => (int) $task['wp_user_id'],
                    'title' => $task['title'],
                    'description' => $task['description'],
                    'status' => 'decayed',
                    'due_at' => $task['due_at'],
                    'decayed_at' => $decayedAt,
                    'restore_count' => 0,
                    'metadata_json' => $task['metadata_json'],
                ]
            );

            $wpdb->update(
                $table,
                [
                    'status' => 'decayed',
                    'updated_at' => $decayedAt,
                ],
                ['id' => (int) $task['id']]
            );

            $this->timelineRepository->upsert('task', (int) $task['id'], [
                'wp_user_id' => (int) $task['wp_user_id'],
                'domain' => 'tasks',
                'title' => $task['title'],
                'summary' => $task['description'],
                'occurred_at' => $decayedAt,
                'start_at' => $task['due_at'],
                'precision_type' => 'exact',
                'source' => 'system',
                'metadata' => [
                    'status' => 'decayed',
                    'decayed_at' => $decayedAt,
                ],
            ]);

            $this->auditLogService->record('task_decayed', 'task', (int) $task['id'], [
                'decayed_at' => $decayedAt,
            ], (int) $task['wp_user_id']);

            $moved++;
        }

        return $moved;
    }

    public function countDueWithinHours(int $userId, int $hours): int
    {
        global $wpdb;

        $table = Tables::prefixed('tasks');
        $start = gmdate('Y-m-d H:i:s');
        $end = gmdate('Y-m-d H:i:s', time() + ($hours * 3600));

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE wp_user_id = %d AND status = 'pending' AND due_at BETWEEN %s AND %s",
                $userId,
                $start,
                $end
            )
        );
    }

    public function getTask(int $taskId): ?array
    {
        global $wpdb;

        $task = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM " . Tables::prefixed('tasks') . ' WHERE id = %d LIMIT 1', $taskId),
            ARRAY_A
        );

        return is_array($task) ? $task : null;
    }

    private function normalizeOptionalDateTime(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $unixTime = strtotime($value);

        return $unixTime === false ? null : gmdate('Y-m-d H:i:s', $unixTime);
    }
}

