<?php

declare(strict_types=1);

namespace LifeOS\Admin;

use LifeOS\Services\SimpleFinFinanceProvider;

final class FinanceConnectionsPage
{
    public function __construct(
        private readonly SimpleFinFinanceProvider $financeProvider
    ) {
    }

    public function boot(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_post_life_os_simplefin_connect', [$this, 'handleConnect']);
        add_action('admin_post_life_os_simplefin_sync', [$this, 'handleSync']);
        add_action('admin_post_life_os_simplefin_disconnect', [$this, 'handleDisconnect']);
    }

    public function registerMenu(): void
    {
        add_submenu_page(
            'life-os',
            'Finance Connections',
            'Finance Connections',
            'manage_life_os',
            'life-os-finance',
            [$this, 'render']
        );
    }

    public function render(): void
    {
        if (! current_user_can('manage_life_os')) {
            wp_die('You do not have permission to manage finance connections.', 403);
        }

        $status = $this->financeProvider->status(get_current_user_id());
        $latestBatch = $status['latest_batch'] ?? null;

        echo '<div class="wrap">';
        echo '<h1>Finance Connections</h1>';
        $this->renderNotices();
        echo '<p>SimpleFIN is the primary read-only finance feed for LIFE OS. It updates roughly once per day, can only fetch a rolling window from the provider, and LIFE OS archives every imported account, balance snapshot, and transaction locally for long-term history.</p>';

        echo '<h2>SimpleFIN Setup</h2>';
        echo '<p><a class="button button-secondary" href="' . esc_url($this->financeProvider->createUrl()) . '" target="_blank" rel="noreferrer noopener">Open SimpleFIN Setup Token Page</a></p>';
        echo '<p>Generate a one-time setup token, paste it below, and LIFE OS will exchange it for an encrypted access token. Bank login credentials are never shown to LIFE OS during this flow.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:980px">';
        wp_nonce_field('life_os_simplefin_connect');
        echo '<input type="hidden" name="action" value="life_os_simplefin_connect" />';
        echo '<textarea class="large-text code" name="setup_token" rows="4" placeholder="Paste SimpleFIN setup token here"></textarea>';
        submit_button($status['connected'] ? 'Reconnect SimpleFIN' : 'Connect SimpleFIN', 'primary', 'submit', false);
        echo '</form>';

        echo '<h2>Connection Status</h2>';
        echo '<table class="widefat striped" style="max-width:1100px"><tbody>';
        echo '<tr><th style="width:260px">Connected</th><td>' . ($status['connected'] ? 'Yes' : 'No') . '</td></tr>';
        echo '<tr><th>Status</th><td>' . esc_html((string) ($status['status'] ?? 'inactive')) . '</td></tr>';
        echo '<tr><th>Linked At</th><td>' . esc_html((string) ($status['linked_at'] ?? 'n/a')) . '</td></tr>';
        echo '<tr><th>Last Successful Sync</th><td>' . esc_html((string) ($status['last_success_at'] ?? 'n/a')) . '</td></tr>';
        echo '<tr><th>Last Error</th><td>' . esc_html((string) ($status['last_error_at'] ?? 'n/a')) . '</td></tr>';
        echo '<tr><th>Next Recommended Sync</th><td>' . esc_html((string) ($status['next_due_at'] ?? 'n/a')) . '</td></tr>';
        echo '<tr><th>Due For Sync</th><td>' . (! empty($status['due_for_sync']) ? 'Yes' : 'No') . '</td></tr>';
        echo '<tr><th>Stored Accounts</th><td>' . (int) (($status['counts']['accounts'] ?? 0)) . '</td></tr>';
        echo '<tr><th>Stored Transactions</th><td>' . (int) (($status['counts']['transactions'] ?? 0)) . '</td></tr>';
        echo '<tr><th>Balance Snapshots</th><td>' . (int) (($status['counts']['balance_snapshots'] ?? 0)) . '</td></tr>';
        echo '<tr><th>Append-Only Raw Logs</th><td>' . (int) (($status['counts']['raw_logs'] ?? 0)) . '</td></tr>';
        echo '</tbody></table>';

        echo '<h2>Actions</h2>';
        echo '<div style="display:flex;gap:12px;align-items:flex-start;flex-wrap:wrap">';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('life_os_simplefin_sync');
        echo '<input type="hidden" name="action" value="life_os_simplefin_sync" />';
        if ($status['connected']) {
            submit_button('Run Finance Sync Now', 'secondary', 'submit', false);
        } else {
            submit_button('Run Finance Sync Now', 'secondary', 'submit', false, ['disabled' => 'disabled']);
        }
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Disconnect SimpleFIN from LIFE OS?\')">';
        wp_nonce_field('life_os_simplefin_disconnect');
        echo '<input type="hidden" name="action" value="life_os_simplefin_disconnect" />';
        echo '<label style="display:block;margin:0 0 8px"><input type="checkbox" name="purge_history" value="1" /> Purge locally stored finance history too</label>';
        if ($status['connected']) {
            submit_button('Disconnect SimpleFIN', 'delete', 'submit', false);
        } else {
            submit_button('Disconnect SimpleFIN', 'delete', 'submit', false, ['disabled' => 'disabled']);
        }
        echo '</form>';
        echo '</div>';

        echo '<h2>Latest Import Batch</h2>';
        if (is_array($latestBatch)) {
            $summary = is_array($latestBatch['summary'] ?? null) ? $latestBatch['summary'] : [];
            echo '<table class="widefat striped" style="max-width:1100px"><tbody>';
            echo '<tr><th style="width:260px">Batch UUID</th><td><code>' . esc_html((string) ($latestBatch['batch_uuid'] ?? '')) . '</code></td></tr>';
            echo '<tr><th>Status</th><td>' . esc_html((string) ($latestBatch['status'] ?? '')) . '</td></tr>';
            echo '<tr><th>Trigger Source</th><td>' . esc_html((string) ($latestBatch['trigger_source'] ?? '')) . '</td></tr>';
            echo '<tr><th>Started</th><td>' . esc_html((string) ($latestBatch['started_at'] ?? '')) . '</td></tr>';
            echo '<tr><th>Completed</th><td>' . esc_html((string) ($latestBatch['completed_at'] ?? '')) . '</td></tr>';
            echo '<tr><th>Accounts Imported</th><td>' . (int) ($summary['accounts_imported'] ?? 0) . '</td></tr>';
            echo '<tr><th>Transactions Seen</th><td>' . (int) ($summary['transactions_seen'] ?? 0) . '</td></tr>';
            echo '<tr><th>Transactions Inserted</th><td>' . (int) ($summary['transactions_inserted'] ?? 0) . '</td></tr>';
            echo '<tr><th>Transactions Updated</th><td>' . (int) ($summary['transactions_updated'] ?? 0) . '</td></tr>';
            echo '<tr><th>Transactions Unchanged</th><td>' . (int) ($summary['transactions_unchanged'] ?? 0) . '</td></tr>';
            echo '<tr><th>Raw Logs Written</th><td>' . (int) ($summary['raw_logs_written'] ?? 0) . '</td></tr>';
            echo '<tr><th>Warnings</th><td>' . esc_html(implode(' | ', (array) ($summary['warnings'] ?? []))) . '</td></tr>';
            echo '</tbody></table>';
        } else {
            echo '<p>No finance import batches have been recorded yet.</p>';
        }

        echo '<h2>Imported Accounts</h2>';
        $accounts = is_array($status['accounts'] ?? null) ? $status['accounts'] : [];
        if ($accounts === []) {
            echo '<p>No archived finance accounts are available yet.</p>';
        } else {
            echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Institution</th><th>Account</th><th>Balance</th><th>Available</th><th>Balance Date</th><th>Currency</th></tr></thead><tbody>';
            foreach ($accounts as $account) {
                echo '<tr>';
                echo '<td>' . esc_html((string) ($account['institution_name'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($account['account_name'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($account['current_balance'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($account['available_balance'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($account['balance_date'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($account['currency_code'] ?? '')) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        echo '</div>';
    }

    public function handleConnect(): void
    {
        $this->requireFinanceAccess();
        check_admin_referer('life_os_simplefin_connect');

        $setupToken = isset($_POST['setup_token']) ? sanitize_textarea_field(wp_unslash((string) $_POST['setup_token'])) : '';

        try {
            $this->financeProvider->connectWithSetupToken(get_current_user_id(), $setupToken);
            $this->redirectWithNotice('simplefin_connected');
        } catch (\RuntimeException $exception) {
            $this->redirectWithNotice('simplefin_error', $exception->getMessage());
        }
    }

    public function handleSync(): void
    {
        $this->requireFinanceAccess();
        check_admin_referer('life_os_simplefin_sync');

        try {
            $result = $this->financeProvider->manualSync(get_current_user_id(), 'admin_manual');
            $this->redirectWithNotice(
                'simplefin_synced',
                sprintf(
                    'Accounts: %d. Transactions seen: %d. Inserted: %d. Updated: %d.',
                    (int) ($result['accounts_imported'] ?? 0),
                    (int) ($result['transactions_seen'] ?? 0),
                    (int) ($result['transactions_inserted'] ?? 0),
                    (int) ($result['transactions_updated'] ?? 0)
                )
            );
        } catch (\RuntimeException $exception) {
            $this->redirectWithNotice('simplefin_error', $exception->getMessage());
        }
    }

    public function handleDisconnect(): void
    {
        $this->requireFinanceAccess();
        check_admin_referer('life_os_simplefin_disconnect');

        $purgeHistory = isset($_POST['purge_history']) && (string) $_POST['purge_history'] === '1';

        try {
            $this->financeProvider->disconnect(get_current_user_id(), $purgeHistory);
            $this->redirectWithNotice(
                'simplefin_disconnected',
                $purgeHistory ? 'SimpleFIN disconnected and local finance history purged.' : 'SimpleFIN disconnected. Local finance history was kept.'
            );
        } catch (\RuntimeException $exception) {
            $this->redirectWithNotice('simplefin_error', $exception->getMessage());
        }
    }

    private function renderNotices(): void
    {
        $notice = isset($_GET['life_os_notice']) ? sanitize_text_field(wp_unslash((string) $_GET['life_os_notice'])) : '';
        $message = isset($_GET['life_os_message']) ? sanitize_text_field(wp_unslash((string) $_GET['life_os_message'])) : '';

        if ($notice === '') {
            return;
        }

        if (in_array($notice, ['simplefin_connected', 'simplefin_synced', 'simplefin_disconnected'], true)) {
            $default = match ($notice) {
                'simplefin_connected' => 'SimpleFIN connected successfully.',
                'simplefin_synced' => 'SimpleFIN sync completed successfully.',
                'simplefin_disconnected' => 'SimpleFIN disconnected successfully.',
                default => '',
            };
            echo '<div class="notice notice-success"><p>' . esc_html($message !== '' ? $message : $default) . '</p></div>';
            return;
        }

        if ($notice === 'simplefin_error') {
            echo '<div class="notice notice-error"><p>' . esc_html($message !== '' ? $message : 'SimpleFIN action failed.') . '</p></div>';
        }
    }

    private function requireFinanceAccess(): void
    {
        if (! current_user_can('manage_life_os')) {
            wp_die('You do not have permission to manage finance connections.', 403);
        }
    }

    private function redirectWithNotice(string $notice, string $message = ''): never
    {
        $args = [
            'page' => 'life-os-finance',
            'life_os_notice' => $notice,
        ];

        if ($message !== '') {
            $args['life_os_message'] = $message;
        }

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }
}
