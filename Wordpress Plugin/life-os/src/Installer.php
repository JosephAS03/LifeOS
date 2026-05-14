<?php

declare(strict_types=1);

namespace LifeOS;

use LifeOS\Support\Options;
use LifeOS\Support\Tables;

final class Installer
{
    public static function activate(): void
    {
        self::addCapabilities();
        self::createTables();
        update_option('life_os_db_version', LIFE_OS_VERSION);

        if (! get_option(Options::OPTION_NAME)) {
            add_option(Options::OPTION_NAME, Options::defaults());
        }
    }

    public static function maybeUpgrade(): void
    {
        $installedVersion = (string) get_option('life_os_db_version', '');
        if ($installedVersion === LIFE_OS_VERSION) {
            return;
        }

        self::activate();
    }

    private static function addCapabilities(): void
    {
        $role = get_role('administrator');
        if (! $role) {
            return;
        }

        $role->add_cap('manage_life_os');
        $role->add_cap('connect_life_os_sources');
    }

    private static function createTables(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charsetCollate = $wpdb->get_charset_collate();

        $sql = [];

        $sql[] = 'CREATE TABLE ' . Tables::prefixed('tasks') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            title VARCHAR(190) NOT NULL,
            description LONGTEXT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'pending',
            due_at DATETIME NULL,
            decay_after_hours INT NULL,
            priority VARCHAR(16) NOT NULL DEFAULT 'normal',
            source VARCHAR(64) NOT NULL DEFAULT 'manual',
            metadata_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            completed_at DATETIME NULL,
            PRIMARY KEY  (id),
            KEY status_due (status, due_at),
            KEY wp_user_id (wp_user_id)
        ) $charsetCollate;";

        $sql[] = 'CREATE TABLE ' . Tables::prefixed('task_vault') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            task_id BIGINT UNSIGNED NOT NULL,
            wp_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            title VARCHAR(190) NOT NULL,
            description LONGTEXT NULL,
            status VARCHAR(32) NOT NULL,
            due_at DATETIME NULL,
            decayed_at DATETIME NOT NULL,
            restore_count INT NOT NULL DEFAULT 0,
            metadata_json LONGTEXT NULL,
            PRIMARY KEY  (id),
            KEY task_id (task_id),
            KEY wp_user_id (wp_user_id)
        ) $charsetCollate;";

        $sql[] = 'CREATE TABLE ' . Tables::prefixed('mood_entries') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            category VARCHAR(64) NOT NULL,
            note LONGTEXT NULL,
            happened_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            metadata_json LONGTEXT NULL,
            PRIMARY KEY  (id),
            KEY wp_user_id_happened_at (wp_user_id, happened_at)
        ) $charsetCollate;";

        $sql[] = 'CREATE TABLE ' . Tables::prefixed('health_records') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            metric_type VARCHAR(64) NOT NULL,
            value_decimal DECIMAL(18,4) NULL,
            unit VARCHAR(32) NULL,
            start_at DATETIME NOT NULL,
            end_at DATETIME NULL,
            source VARCHAR(64) NOT NULL,
            source_id VARCHAR(190) NULL,
            timezone VARCHAR(64) NULL,
            raw_payload LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY source_dedupe (source, source_id),
            KEY metric_time (metric_type, start_at),
            KEY wp_user_id_time (wp_user_id, start_at)
        ) $charsetCollate;";

        $sql[] = 'CREATE TABLE ' . Tables::prefixed('timeline_items') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            domain VARCHAR(32) NOT NULL,
            entity_type VARCHAR(64) NOT NULL,
            entity_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            title VARCHAR(190) NOT NULL,
            summary LONGTEXT NULL,
            occurred_at DATETIME NULL,
            start_at DATETIME NULL,
            end_at DATETIME NULL,
            precision_type VARCHAR(32) NOT NULL DEFAULT 'exact',
            source VARCHAR(64) NOT NULL,
            metadata_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY wp_user_id_domain_time (wp_user_id, domain, occurred_at),
            KEY entity_lookup (entity_type, entity_id)
        ) $charsetCollate;";

        $sql[] = 'CREATE TABLE ' . Tables::prefixed('provider_connections') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            provider VARCHAR(64) NOT NULL,
            external_user_id VARCHAR(190) NULL,
            external_account_id VARCHAR(190) NULL,
            scopes_json LONGTEXT NULL,
            access_token_encrypted LONGTEXT NULL,
            refresh_token_encrypted LONGTEXT NULL,
            token_expires_at DATETIME NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'inactive',
            metadata_json LONGTEXT NULL,
            linked_at DATETIME NULL,
            revoked_at DATETIME NULL,
            last_success_at DATETIME NULL,
            last_error_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY user_provider (wp_user_id, provider)
        ) $charsetCollate;";

        $sql[] = 'CREATE TABLE ' . Tables::prefixed('sync_state') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            provider_connection_id BIGINT UNSIGNED NOT NULL,
            resource_type VARCHAR(64) NOT NULL,
            resource_key VARCHAR(190) NOT NULL,
            sync_cursor LONGTEXT NULL,
            sync_token LONGTEXT NULL,
            channel_id VARCHAR(190) NULL,
            channel_token_hash VARCHAR(190) NULL,
            resource_id VARCHAR(190) NULL,
            channel_expires_at DATETIME NULL,
            last_sync_started_at DATETIME NULL,
            last_sync_completed_at DATETIME NULL,
            last_webhook_at DATETIME NULL,
            last_error_code VARCHAR(64) NULL,
            last_error_message_sanitized LONGTEXT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY resource_key (provider_connection_id, resource_type, resource_key)
        ) $charsetCollate;";

        $sql[] = 'CREATE TABLE ' . Tables::prefixed('request_nonces') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_type VARCHAR(64) NOT NULL,
            source_id VARCHAR(190) NOT NULL,
            nonce VARCHAR(190) NOT NULL,
            request_hash VARCHAR(190) NOT NULL,
            seen_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY source_nonce (source_type, source_id, nonce),
            KEY expires_at (expires_at)
        ) $charsetCollate;";

        $sql[] = 'CREATE TABLE ' . Tables::prefixed('notification_log') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            alert_type VARCHAR(64) NOT NULL,
            channel VARCHAR(64) NOT NULL,
            status VARCHAR(32) NOT NULL,
            title VARCHAR(190) NOT NULL,
            message LONGTEXT NULL,
            dedupe_key VARCHAR(190) NULL,
            provider_reference VARCHAR(190) NULL,
            metadata_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY channel_status (channel, status),
            KEY dedupe_key (dedupe_key)
        ) $charsetCollate;";

        $sql[] = 'CREATE TABLE ' . Tables::prefixed('audit_log') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            action VARCHAR(128) NOT NULL,
            object_type VARCHAR(64) NULL,
            object_id BIGINT UNSIGNED NULL,
            details_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY action_created_at (action, created_at)
        ) $charsetCollate;";

        $sql[] = 'CREATE TABLE ' . Tables::prefixed('finance_accounts') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            provider_connection_id BIGINT UNSIGNED NOT NULL,
            account_id VARCHAR(190) NOT NULL,
            institution_name VARCHAR(190) NULL,
            account_name VARCHAR(190) NOT NULL,
            subtype VARCHAR(64) NULL,
            mask VARCHAR(16) NULL,
            current_balance DECIMAL(18,4) NULL,
            available_balance DECIMAL(18,4) NULL,
            currency_code VARCHAR(190) NULL,
            balance_date DATETIME NULL,
            metadata_json LONGTEXT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY account_id (provider_connection_id, account_id)
        ) $charsetCollate;";

        $sql[] = 'CREATE TABLE ' . Tables::prefixed('finance_transactions') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            provider_connection_id BIGINT UNSIGNED NOT NULL,
            account_id VARCHAR(190) NOT NULL,
            transaction_id VARCHAR(190) NOT NULL,
            pending_transaction_id VARCHAR(190) NULL,
            name VARCHAR(190) NOT NULL,
            merchant_name VARCHAR(190) NULL,
            amount DECIMAL(18,4) NOT NULL,
            iso_currency_code VARCHAR(16) NULL,
            category_json LONGTEXT NULL,
            authorized_date DATE NULL,
            authorized_datetime DATETIME NULL,
            date_posted DATE NOT NULL,
            datetime_posted DATETIME NULL,
            is_pending TINYINT(1) NOT NULL DEFAULT 0,
            metadata_json LONGTEXT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY transaction_id (provider_connection_id, transaction_id),
            KEY account_posted (account_id, date_posted)
        ) $charsetCollate;";

        $sql[] = 'CREATE TABLE ' . Tables::prefixed('finance_import_batches') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            provider_connection_id BIGINT UNSIGNED NOT NULL,
            provider VARCHAR(64) NOT NULL,
            batch_uuid CHAR(36) NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'running',
            trigger_source VARCHAR(64) NOT NULL DEFAULT 'manual',
            requested_window_start DATETIME NULL,
            requested_window_end DATETIME NULL,
            summary_json LONGTEXT NULL,
            error_message_sanitized LONGTEXT NULL,
            started_at DATETIME NOT NULL,
            completed_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY batch_uuid (batch_uuid),
            KEY provider_connection_started (provider_connection_id, started_at)
        ) $charsetCollate;";

        $sql[] = 'CREATE TABLE ' . Tables::prefixed('finance_balance_snapshots') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            import_batch_id BIGINT UNSIGNED NOT NULL,
            provider_connection_id BIGINT UNSIGNED NOT NULL,
            account_id VARCHAR(190) NOT NULL,
            balance_date DATETIME NULL,
            current_balance DECIMAL(18,4) NULL,
            available_balance DECIMAL(18,4) NULL,
            currency_code VARCHAR(190) NULL,
            payload_json LONGTEXT NULL,
            captured_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY import_batch_id (import_batch_id),
            KEY account_balance_date (provider_connection_id, account_id, balance_date)
        ) $charsetCollate;";

        $sql[] = 'CREATE TABLE ' . Tables::prefixed('finance_raw_logs') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            import_batch_id BIGINT UNSIGNED NOT NULL,
            provider_connection_id BIGINT UNSIGNED NOT NULL,
            provider VARCHAR(64) NOT NULL,
            payload_type VARCHAR(64) NOT NULL,
            provider_account_id VARCHAR(190) NULL,
            provider_transaction_id VARCHAR(190) NULL,
            dedupe_key VARCHAR(190) NULL,
            payload_hash VARCHAR(64) NOT NULL,
            payload_json LONGTEXT NOT NULL,
            synced_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY batch_type (import_batch_id, payload_type),
            KEY provider_object (provider_connection_id, provider_account_id, provider_transaction_id),
            KEY dedupe_key (dedupe_key)
        ) $charsetCollate;";

        $sql[] = 'CREATE TABLE ' . Tables::prefixed('calendar_events') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            provider_connection_id BIGINT UNSIGNED NOT NULL,
            calendar_id VARCHAR(190) NOT NULL,
            google_event_id VARCHAR(190) NOT NULL,
            summary VARCHAR(190) NOT NULL,
            description LONGTEXT NULL,
            location_text VARCHAR(255) NULL,
            start_at DATETIME NOT NULL,
            end_at DATETIME NULL,
            is_all_day TINYINT(1) NOT NULL DEFAULT 0,
            event_status VARCHAR(32) NOT NULL DEFAULT 'confirmed',
            recurring_event_id VARCHAR(190) NULL,
            etag VARCHAR(190) NULL,
            visibility VARCHAR(32) NULL,
            metadata_json LONGTEXT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY event_id (provider_connection_id, google_event_id),
            KEY calendar_start (calendar_id, start_at)
        ) $charsetCollate;";

        foreach ($sql as $statement) {
            dbDelta($statement);
        }
    }
}
