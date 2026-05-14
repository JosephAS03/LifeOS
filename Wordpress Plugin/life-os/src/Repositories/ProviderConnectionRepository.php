<?php

declare(strict_types=1);

namespace LifeOS\Repositories;

use LifeOS\Support\Tables;

final class ProviderConnectionRepository
{
    public function upsert(int $userId, string $provider, array $attributes): int
    {
        global $wpdb;

        $table = Tables::prefixed('provider_connections');
        $existing = $this->findByUserAndProvider($userId, $provider);
        $now = gmdate('Y-m-d H:i:s');

        $data = [
            'wp_user_id' => $userId,
            'provider' => $provider,
            'external_user_id' => $attributes['external_user_id'] ?? null,
            'external_account_id' => $attributes['external_account_id'] ?? null,
            'scopes_json' => isset($attributes['scopes']) ? wp_json_encode(array_values((array) $attributes['scopes'])) : null,
            'access_token_encrypted' => $attributes['access_token_encrypted'] ?? null,
            'refresh_token_encrypted' => $attributes['refresh_token_encrypted'] ?? null,
            'token_expires_at' => $attributes['token_expires_at'] ?? null,
            'status' => $attributes['status'] ?? 'active',
            'metadata_json' => isset($attributes['metadata']) ? wp_json_encode($attributes['metadata']) : null,
            'linked_at' => $attributes['linked_at'] ?? $now,
            'revoked_at' => $attributes['revoked_at'] ?? null,
            'last_success_at' => $attributes['last_success_at'] ?? null,
            'last_error_at' => $attributes['last_error_at'] ?? null,
        ];

        if ($existing) {
            $wpdb->update($table, $data, ['id' => $existing['id']]);

            return (int) $existing['id'];
        }

        $wpdb->insert($table, $data);

        return (int) $wpdb->insert_id;
    }

    public function findByUserAndProvider(int $userId, string $provider): ?array
    {
        global $wpdb;

        $table = Tables::prefixed('provider_connections');
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE wp_user_id = %d AND provider = %s LIMIT 1", $userId, $provider),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    public function findByExternalAccountId(string $provider, string $externalAccountId): ?array
    {
        global $wpdb;

        $table = Tables::prefixed('provider_connections');
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE provider = %s AND external_account_id = %s LIMIT 1", $provider, $externalAccountId),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    public function staleConnections(int $minutes = 60): array
    {
        global $wpdb;

        $table = Tables::prefixed('provider_connections');
        $threshold = gmdate('Y-m-d H:i:s', time() - ($minutes * 60));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT provider, status, last_success_at FROM {$table} WHERE status = 'active' AND (last_success_at IS NULL OR last_success_at < %s)",
                $threshold
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    public function findById(int $id): ?array
    {
        global $wpdb;

        $table = Tables::prefixed('provider_connections');
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    public function activeByProvider(string $provider): array
    {
        global $wpdb;

        $table = Tables::prefixed('provider_connections');
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE provider = %s AND status = 'active' ORDER BY id ASC",
                $provider
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }
}
