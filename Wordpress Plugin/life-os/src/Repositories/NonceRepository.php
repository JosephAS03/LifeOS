<?php

declare(strict_types=1);

namespace LifeOS\Repositories;

use LifeOS\Support\Tables;

final class NonceRepository
{
    public function remember(string $sourceType, string $sourceId, string $nonce, string $requestHash, int $ttlSeconds = 600): bool
    {
        global $wpdb;

        $table = Tables::prefixed('request_nonces');
        $now = gmdate('Y-m-d H:i:s');
        $expires = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);

        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE expires_at < %s", $now));

        $inserted = $wpdb->insert(
            $table,
            [
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'nonce' => $nonce,
                'request_hash' => $requestHash,
                'seen_at' => $now,
                'expires_at' => $expires,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s']
        );

        return $inserted !== false;
    }
}

