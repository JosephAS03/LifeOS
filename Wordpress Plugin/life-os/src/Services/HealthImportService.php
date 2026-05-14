<?php

declare(strict_types=1);

namespace LifeOS\Services;

use LifeOS\Repositories\TimelineRepository;
use LifeOS\Support\Tables;

final class HealthImportService
{
    public function __construct(
        private readonly TimelineRepository $timelineRepository,
        private readonly AuditLogService $auditLogService
    ) {
    }

    public function importRecords(int $userId, string $source, string $timezone, array $records): array
    {
        global $wpdb;

        $inserted = 0;
        $rejected = [];

        foreach ($records as $index => $record) {
            $type = sanitize_key((string) ($record['type'] ?? ''));
            $startAt = $this->normalizeDateTime($record['start_at'] ?? null);

            if ($type === '' || $startAt === null) {
                $rejected[] = [
                    'index' => $index,
                    'reason' => 'Missing required metric type or start_at.',
                ];
                continue;
            }

            $endAt = $this->normalizeDateTime($record['end_at'] ?? null);
            $value = isset($record['value']) ? (float) $record['value'] : null;
            $unit = isset($record['unit']) ? sanitize_text_field((string) $record['unit']) : null;
            $sourceId = $this->determineSourceId($source, $record);

            $existing = null;
            if ($sourceId !== '') {
                $existing = $wpdb->get_var(
                    $wpdb->prepare(
                        'SELECT id FROM ' . Tables::prefixed('health_records') . ' WHERE source = %s AND source_id = %s LIMIT 1',
                        $source,
                        $sourceId
                    )
                );
            }

            if ($existing) {
                continue;
            }

            $payloadJson = wp_json_encode($record);
            $wpdb->insert(
                Tables::prefixed('health_records'),
                [
                    'wp_user_id' => $userId,
                    'metric_type' => $type,
                    'value_decimal' => $value,
                    'unit' => $unit,
                    'start_at' => $startAt,
                    'end_at' => $endAt,
                    'source' => $source,
                    'source_id' => $sourceId,
                    'timezone' => $timezone,
                    'raw_payload' => $payloadJson,
                    'created_at' => gmdate('Y-m-d H:i:s'),
                ]
            );

            $recordId = (int) $wpdb->insert_id;

            $this->timelineRepository->upsert('health_record', $recordId, [
                'wp_user_id' => $userId,
                'domain' => 'health',
                'title' => ucwords(str_replace('_', ' ', $type)),
                'summary' => $value !== null ? (string) $value . ($unit ? ' ' . $unit : '') : null,
                'occurred_at' => $endAt ?? $startAt,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'precision_type' => $endAt ? 'interval' : 'exact',
                'source' => $source,
                'metadata' => [
                    'metric_type' => $type,
                    'value' => $value,
                    'unit' => $unit,
                ],
            ]);

            $inserted++;
        }

        $this->auditLogService->record('health_import', 'health_record', null, [
            'source' => $source,
            'inserted' => $inserted,
            'rejected_count' => count($rejected),
        ], $userId);

        return [
            'inserted' => $inserted,
            'rejected' => $rejected,
        ];
    }

    public function summaryForDate(int $userId, string $date): array
    {
        global $wpdb;

        $table = Tables::prefixed('health_records');
        $day = gmdate('Y-m-d', strtotime($date));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT metric_type, value_decimal, unit, start_at, end_at
                FROM {$table}
                WHERE wp_user_id = %d AND DATE(start_at) = %s
                ORDER BY start_at ASC",
                $userId,
                $day
            ),
            ARRAY_A
        );

        $steps = 0.0;
        $sleepSeconds = 0;
        $heartRates = [];
        $latestWeight = null;

        foreach ((array) $rows as $row) {
            if ($row['metric_type'] === 'step_count') {
                $steps += (float) $row['value_decimal'];
            }

            if ($row['metric_type'] === 'sleep_interval' && $row['end_at']) {
                $sleepSeconds += max(0, strtotime($row['end_at'] . ' UTC') - strtotime($row['start_at'] . ' UTC'));
            }

            if (in_array($row['metric_type'], ['heart_rate', 'resting_heart_rate'], true) && $row['value_decimal'] !== null) {
                $heartRates[] = (float) $row['value_decimal'];
            }

            if ($row['metric_type'] === 'weight' && $row['value_decimal'] !== null) {
                $latestWeight = (float) $row['value_decimal'];
            }
        }

        return [
            'date' => $day,
            'steps' => (int) round($steps),
            'sleep_hours' => round($sleepSeconds / 3600, 2),
            'average_heart_rate' => $heartRates !== [] ? round(array_sum($heartRates) / count($heartRates), 1) : null,
            'latest_weight' => $latestWeight,
            'records_count' => count((array) $rows),
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

    private function determineSourceId(string $source, array $record): string
    {
        if (! empty($record['source_id'])) {
            return sanitize_text_field((string) $record['source_id']);
        }

        return hash('sha256', wp_json_encode([
            'source' => $source,
            'type' => $record['type'] ?? null,
            'value' => $record['value'] ?? null,
            'start_at' => $record['start_at'] ?? null,
            'end_at' => $record['end_at'] ?? null,
            'unit' => $record['unit'] ?? null,
        ]));
    }
}

