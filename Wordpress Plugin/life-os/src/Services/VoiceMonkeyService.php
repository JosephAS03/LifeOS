<?php

declare(strict_types=1);

namespace LifeOS\Services;

use LifeOS\Support\Options;
use LifeOS\Support\Tables;
use RuntimeException;

final class VoiceMonkeyService
{
    private const BASE_URL = 'https://api-v3.voicemonkey.io';

    public function __construct(
        private readonly Options $options,
        private readonly AuditLogService $auditLogService
    ) {
    }

    public function configured(): bool
    {
        return $this->token() !== '' && $this->defaultTarget() !== '';
    }

    public function sendTestAnnouncement(?string $message = null): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('Voice Monkey token or default target is missing.');
        }

        $payload = [
            'token' => $this->token(),
            'device' => $this->defaultTarget(),
            'speech' => $message ?: 'This is a test announcement from LIFE OS.',
        ];

        $response = $this->request('POST', '/announce', $payload);

        $this->auditLogService->record('voice_monkey_test_sent', 'notification', null, [
            'target' => $this->defaultTarget(),
            'message' => $payload['speech'],
        ]);

        return $response;
    }

    public function processDueAnnouncements(int $limit = 10): array
    {
        global $wpdb;

        if ((string) $this->options->get('voice_monkey_enabled', '0') !== '1' || ! $this->configured()) {
            return [
                'queued' => 0,
                'sent' => 0,
                'failed' => 0,
            ];
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . Tables::prefixed('notification_log') . " WHERE channel = 'voice_monkey' AND status IN ('queued', 'retry') ORDER BY created_at ASC LIMIT %d",
                max(1, min(50, $limit))
            ),
            ARRAY_A
        );

        $queued = is_array($rows) ? count($rows) : 0;
        $sent = 0;
        $failed = 0;
        $now = time();

        foreach ((array) $rows as $row) {
            $metadata = is_string($row['metadata_json'] ?? null) ? json_decode((string) $row['metadata_json'], true) : [];
            if (! is_array($metadata)) {
                $metadata = [];
            }

            $scheduledFor = isset($metadata['scheduled_for']) ? strtotime((string) $metadata['scheduled_for']) : strtotime((string) $row['created_at'] . ' UTC');
            if ($scheduledFor !== false && $scheduledFor > $now) {
                continue;
            }

            try {
                $response = $this->request('POST', '/announce', [
                    'token' => $this->token(),
                    'device' => (string) ($metadata['target'] ?? $this->defaultTarget()),
                    'speech' => (string) ($row['message'] ?: $row['title']),
                ]);

                $wpdb->update(
                    Tables::prefixed('notification_log'),
                    [
                        'status' => 'sent',
                        'provider_reference' => isset($response['id']) ? sanitize_text_field((string) $response['id']) : null,
                    ],
                    ['id' => (int) $row['id']],
                    ['%s', '%s'],
                    ['%d']
                );

                $this->auditLogService->record('voice_monkey_notification_sent', 'notification', (int) $row['id'], [
                    'target' => (string) ($metadata['target'] ?? $this->defaultTarget()),
                    'title' => (string) $row['title'],
                ], (int) $row['wp_user_id']);

                $sent++;
            } catch (RuntimeException $exception) {
                $wpdb->update(
                    Tables::prefixed('notification_log'),
                    [
                        'status' => 'failed',
                        'metadata_json' => wp_json_encode(array_merge($metadata, [
                            'last_error' => sanitize_text_field($exception->getMessage()),
                            'last_attempt_at' => gmdate('c'),
                        ])),
                    ],
                    ['id' => (int) $row['id']],
                    ['%s', '%s'],
                    ['%d']
                );

                $failed++;
            }
        }

        return [
            'queued' => $queued,
            'sent' => $sent,
            'failed' => $failed,
        ];
    }

    private function request(string $method, string $path, array $payload = []): array
    {
        $response = wp_remote_request(self::BASE_URL . $path, [
            'method' => $method,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => $payload !== [] ? wp_json_encode($payload) : null,
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            throw new RuntimeException($response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if (! is_array($body)) {
            $body = [
                'raw' => (string) wp_remote_retrieve_body($response),
            ];
        }

        if ($status >= 400) {
            $message = (string) ($body['message'] ?? $body['error'] ?? ('Voice Monkey request failed with status ' . $status));
            throw new RuntimeException($message);
        }

        return $body;
    }

    private function token(): string
    {
        return trim((string) $this->options->get('voice_monkey_token', ''));
    }

    private function defaultTarget(): string
    {
        return trim((string) $this->options->get('voice_monkey_default_target', ''));
    }
}
