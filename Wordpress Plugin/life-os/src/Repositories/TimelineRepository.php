<?php

declare(strict_types=1);

namespace LifeOS\Repositories;

use LifeOS\Support\Tables;

final class TimelineRepository
{
    public function upsert(string $entityType, int $entityId, array $payload): void
    {
        global $wpdb;

        $table = Tables::prefixed('timeline_items');
        $existingId = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE entity_type = %s AND entity_id = %d LIMIT 1",
                $entityType,
                $entityId
            )
        );

        $now = gmdate('Y-m-d H:i:s');
        $data = [
            'wp_user_id' => (int) ($payload['wp_user_id'] ?? get_current_user_id()),
            'domain' => $payload['domain'],
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'title' => $payload['title'],
            'summary' => $payload['summary'] ?? null,
            'occurred_at' => $payload['occurred_at'] ?? null,
            'start_at' => $payload['start_at'] ?? null,
            'end_at' => $payload['end_at'] ?? null,
            'precision_type' => $payload['precision_type'] ?? 'exact',
            'source' => $payload['source'] ?? 'system',
            'metadata_json' => isset($payload['metadata']) ? wp_json_encode($payload['metadata']) : null,
            'updated_at' => $now,
        ];

        if ($existingId > 0) {
            $wpdb->update($table, $data, ['id' => $existingId]);
            return;
        }

        $data['created_at'] = $now;
        $wpdb->insert($table, $data);
    }

    public function moment(int $userId, string $atUtc, int $radiusSeconds, array $domains = []): array
    {
        global $wpdb;

        $table = Tables::prefixed('timeline_items');
        $start = gmdate('Y-m-d H:i:s', strtotime($atUtc) - $radiusSeconds);
        $end = gmdate('Y-m-d H:i:s', strtotime($atUtc) + $radiusSeconds);
        $sql = "
            SELECT *,
                ABS(TIMESTAMPDIFF(SECOND, COALESCE(occurred_at, start_at), %s)) AS proximity_seconds
            FROM {$table}
            WHERE wp_user_id = %d
                AND COALESCE(occurred_at, start_at) BETWEEN %s AND %s
        ";
        $params = [$atUtc, $userId, $start, $end];

        if ($domains !== []) {
            $sql .= ' AND domain IN (' . implode(',', array_fill(0, count($domains), '%s')) . ')';
            $params = array_merge($params, $domains);
        }

        $sql .= ' ORDER BY proximity_seconds ASC, COALESCE(occurred_at, start_at) ASC LIMIT 100';
        $prepared = $wpdb->prepare($sql, ...$params);

        $rows = $wpdb->get_results($prepared, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    public function recent(int $userId, int $limit = 20, array $domains = []): array
    {
        global $wpdb;

        $table = Tables::prefixed('timeline_items');
        $sql = "
            SELECT *
            FROM {$table}
            WHERE wp_user_id = %d
        ";
        $params = [$userId];

        if ($domains !== []) {
            $sql .= ' AND domain IN (' . implode(',', array_fill(0, count($domains), '%s')) . ')';
            $params = array_merge($params, $domains);
        }

        $sql .= ' ORDER BY COALESCE(occurred_at, start_at, created_at) DESC LIMIT %d';
        $params[] = max(1, min(100, $limit));

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }
}
