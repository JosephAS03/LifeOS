<?php

declare(strict_types=1);

namespace LifeOS\Services;

use LifeOS\Repositories\TimelineRepository;
use LifeOS\Support\Tables;

final class MoodService
{
    public function __construct(
        private readonly TimelineRepository $timelineRepository,
        private readonly AuditLogService $auditLogService
    ) {
    }

    public function listEntries(int $userId, int $limit = 50): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . Tables::prefixed('mood_entries') . ' WHERE wp_user_id = %d ORDER BY happened_at DESC LIMIT %d',
                $userId,
                max(1, min(200, $limit))
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    public function createEntry(int $userId, array $payload, string $source = 'manual'): array
    {
        global $wpdb;

        $now = gmdate('Y-m-d H:i:s');
        $happenedAt = $this->normalizeDateTime($payload['happened_at'] ?? $payload['at'] ?? $now) ?? $now;
        $category = sanitize_key((string) ($payload['category'] ?? 'neutral'));
        $note = isset($payload['note']) ? sanitize_textarea_field((string) $payload['note']) : null;

        $wpdb->insert(
            Tables::prefixed('mood_entries'),
            [
                'wp_user_id' => $userId,
                'category' => $category,
                'note' => $note,
                'happened_at' => $happenedAt,
                'created_at' => $now,
                'metadata_json' => wp_json_encode(['source' => $source]),
            ]
        );

        $entryId = (int) $wpdb->insert_id;

        $this->timelineRepository->upsert('mood', $entryId, [
            'wp_user_id' => $userId,
            'domain' => 'mood',
            'title' => ucfirst($category) . ' mood',
            'summary' => $note,
            'occurred_at' => $happenedAt,
            'precision_type' => 'exact',
            'source' => $source,
            'metadata' => ['category' => $category],
        ]);

        $this->auditLogService->record('mood_created', 'mood', $entryId, [
            'category' => $category,
            'happened_at' => $happenedAt,
            'source' => $source,
        ], $userId);

        return [
            'id' => $entryId,
            'wp_user_id' => $userId,
            'category' => $category,
            'note' => $note,
            'happened_at' => $happenedAt,
        ];
    }

    private function normalizeDateTime(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $unixTime = strtotime($value);

        return $unixTime === false ? null : gmdate('Y-m-d H:i:s', $unixTime);
    }
}

