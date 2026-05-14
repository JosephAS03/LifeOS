<?php

declare(strict_types=1);

namespace LifeOS\Admin;

use LifeOS\Services\DiscordOAuthService;
use LifeOS\Services\PageProvisioner;
use LifeOS\Services\VoiceMonkeyService;
use LifeOS\Support\Options;
use LifeOS\Support\Tables;

final class SettingsPage
{
    public function __construct(
        private readonly Options $options,
        private readonly DiscordOAuthService $discordOAuthService,
        private readonly PageProvisioner $pageProvisioner,
        private readonly VoiceMonkeyService $voiceMonkeyService
    ) {
    }

    public function boot(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_post_life_os_voice_monkey_test', [$this, 'handleVoiceMonkeyTest']);
    }

    public function registerMenu(): void
    {
        add_menu_page(
            'LIFE OS',
            'LIFE OS',
            'manage_life_os',
            'life-os',
            [$this, 'render'],
            'dashicons-chart-area',
            58
        );
    }

    public function registerSettings(): void
    {
        register_setting('life_os', Options::OPTION_NAME, [
            'type' => 'array',
            'sanitize_callback' => [Options::class, 'sanitize'],
            'default' => Options::defaults(),
        ]);
    }

    public function render(): void
    {
        if (! current_user_can('manage_life_os')) {
            wp_die('You do not have permission to manage LIFE OS.', 403);
        }

        $settings = $this->options->all();
        $discord = $this->discordOAuthService->connectionSummary(get_current_user_id());

        echo '<div class="wrap">';
        echo '<h1>LIFE OS</h1>';

        if (isset($_GET['life_os_notice']) && $_GET['life_os_notice'] === 'discord_linked') {
            echo '<div class="notice notice-success"><p>Discord account linked successfully.</p></div>';
        }
        if (isset($_GET['life_os_notice']) && $_GET['life_os_notice'] === 'discord_error') {
            $message = isset($_GET['life_os_message']) ? sanitize_text_field(wp_unslash((string) $_GET['life_os_message'])) : 'Discord connection failed.';
            echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
        }
        if (isset($_GET['life_os_notice']) && $_GET['life_os_notice'] === 'feature_pages_synced') {
            echo '<div class="notice notice-success"><p>'
                . esc_html(sprintf(
                    'Feature pages synced. Created: %d, updated: %d, retired: %d.',
                    (int) ($_GET['created'] ?? 0),
                    (int) ($_GET['updated'] ?? 0),
                    (int) ($_GET['retired'] ?? 0)
                ))
                . '</p></div>';
        }
        if (isset($_GET['life_os_notice']) && $_GET['life_os_notice'] === 'voice_monkey_test_success') {
            echo '<div class="notice notice-success"><p>Voice Monkey test announcement sent successfully.</p></div>';
        }
        if (isset($_GET['life_os_notice']) && $_GET['life_os_notice'] === 'voice_monkey_test_error') {
            $message = isset($_GET['life_os_message']) ? sanitize_text_field(wp_unslash((string) $_GET['life_os_message'])) : 'Voice Monkey test failed.';
            echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
        }

        echo '<h2>Overview</h2>';
        echo '<p>Private, timeline-first personal dashboard with signed integrations and a companion Discord bot.</p>';
        echo '<p><strong>Project Repo:</strong> <a href="' . esc_url(LIFE_OS_GITHUB_REPO_URL) . '" target="_blank" rel="noreferrer noopener">' . esc_html(LIFE_OS_GITHUB_REPO_URL) . '</a><br><small>Plugin updates are sourced from GitHub Releases when a release includes a plugin ZIP asset.</small></p>';

        echo '<h2>Connections</h2>';
        echo '<table class="widefat striped" style="max-width:900px"><tbody>';
        echo '<tr><th style="width:220px">Discord</th><td>';
        if ($discord) {
            $name = esc_html((string) (($discord['metadata']['global_name'] ?? '') ?: ($discord['metadata']['username'] ?? $discord['external_user_id'])));
            echo 'Connected as <strong>' . $name . '</strong>';
            if (! empty($discord['scopes'])) {
                echo '<br><small>Scopes: ' . esc_html(implode(', ', (array) $discord['scopes'])) . '</small>';
            }
        } else {
            echo 'Not connected.';
        }
        echo '<br><a class="button button-secondary" href="' . esc_url(admin_url('admin-post.php?action=life_os_discord_connect')) . '">Connect Discord</a>';
        echo '<br><small>Redirect URI: <code>' . esc_html($this->discordOAuthService->callbackUrl()) . '</code></small>';
        echo '</td></tr>';
        echo '<tr><th>Finance</th><td><a class="button button-secondary" href="' . esc_url(admin_url('admin.php?page=life-os-finance')) . '">Open Finance Connections</a><br><small>SimpleFIN is the primary read-only finance provider and archives its data locally.</small></td></tr>';
        echo '<tr><th>Google Calendar</th><td>Schema and settings are prepared for OAuth and incremental sync.</td></tr>';
        echo '<tr><th>Voice Monkey</th><td>' . ($settings['voice_monkey_enabled'] === '1' ? 'Enabled' : 'Disabled') . '</td></tr>';
        echo '</tbody></table>';

        echo '<h2>Feature Pages</h2>';
        echo '<p>LIFE OS can auto-create pages for new features and optionally retire managed pages when a feature is removed from the registry.</p>';
        echo '<p><a class="button button-secondary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=life_os_sync_feature_pages'), 'life_os_sync_feature_pages')) . '">Sync Feature Pages Now</a></p>';
        echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Feature</th><th>Page</th><th>Status</th><th>Shortcode</th><th>Actions</th></tr></thead><tbody>';
        foreach ($this->pageProvisioner->pages() as $page) {
            echo '<tr>';
            echo '<td><strong>' . esc_html($page['title']) . '</strong><br><small>' . esc_html($page['description']) . '</small></td>';
            echo '<td>' . ($page['exists'] ? esc_html((string) $page['slug']) . ' (#' . (int) $page['id'] . ')' : 'Missing') . '</td>';
            echo '<td>' . esc_html((string) $page['status']) . '</td>';
            echo '<td><code>' . esc_html((string) $page['shortcode']) . '</code></td>';
            echo '<td>';
            if ($page['exists']) {
                echo '<a class="button button-small" href="' . esc_url((string) $page['url']) . '" target="_blank" rel="noreferrer noopener">Open</a> ';
                echo '<a class="button button-small" href="' . esc_url((string) $page['edit_url']) . '">Edit</a>';
            } else {
                echo '<em>Will be created on next sync.</em>';
            }
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '<h2>Quick Stats</h2>';
        echo '<table class="widefat striped" style="max-width:900px"><tbody>';
        echo '<tr><th>Tasks</th><td>' . (int) $this->countRows('tasks') . '</td></tr>';
        echo '<tr><th>Mood entries</th><td>' . (int) $this->countRows('mood_entries') . '</td></tr>';
        echo '<tr><th>Health records</th><td>' . (int) $this->countRows('health_records') . '</td></tr>';
        echo '<tr><th>Timeline items</th><td>' . (int) $this->countRows('timeline_items') . '</td></tr>';
        echo '<tr><th>Notifications logged</th><td>' . (int) $this->countRows('notification_log') . '</td></tr>';
        echo '</tbody></table>';

        echo '<h2>Settings</h2>';
        echo '<form method="post" action="options.php">';
        settings_fields('life_os');
        echo '<table class="form-table" role="presentation">';
        $this->textRow('timezone', 'Timezone', $settings['timezone']);
        $this->checkboxRow('auto_create_feature_pages', 'Auto Create Feature Pages', $settings['auto_create_feature_pages'] === '1');
        $this->checkboxRow('auto_retire_feature_pages', 'Auto Retire Old Feature Pages', $settings['auto_retire_feature_pages'] === '1');
        $this->textRow('bot_shared_secret', 'Bot Shared Secret', $settings['bot_shared_secret']);
        $this->textRow('health_bridge_secret', 'Health Bridge Secret', $settings['health_bridge_secret']);
        $this->textRow('discord_client_id', 'Discord Client ID', $settings['discord_client_id']);
        $this->textRow('discord_client_secret', 'Discord Client Secret', $settings['discord_client_secret']);
        $this->textRow('discord_bot_token', 'Discord Bot Token', $settings['discord_bot_token']);
        $this->textRow('discord_guild_id', 'Discord Guild ID', $settings['discord_guild_id']);
        $this->textRow('discord_scopes', 'Discord Scopes', $settings['discord_scopes']);
        $this->checkboxRow('discord_auto_join', 'Discord Auto Join', $settings['discord_auto_join'] === '1');
        $this->textRow('google_client_id', 'Google Client ID', $settings['google_client_id']);
        $this->textRow('google_client_secret', 'Google Client Secret', $settings['google_client_secret']);
        $this->checkboxRow('voice_monkey_enabled', 'Voice Monkey Enabled', $settings['voice_monkey_enabled'] === '1');
        $this->textRow('voice_monkey_token', 'Voice Monkey Token', $settings['voice_monkey_token']);
        $this->textRow('voice_monkey_default_target', 'Voice Monkey Default Target', $settings['voice_monkey_default_target']);
        echo '</table>';
        submit_button('Save LIFE OS Settings');
        echo '</form>';

        echo '<h2>Voice Monkey Test</h2>';
        echo '<p>Use this to confirm the current Voice Monkey token and default target can deliver an announcement.</p>';
        echo '<p>';
        if ($this->voiceMonkeyService->configured()) {
            echo '<a class="button button-secondary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=life_os_voice_monkey_test'), 'life_os_voice_monkey_test')) . '">Send Test Voice Monkey Announcement</a>';
        } else {
            echo '<em>Add a Voice Monkey token and default target first.</em>';
        }
        echo '</p>';
        echo '</div>';
    }

    public function handleVoiceMonkeyTest(): void
    {
        if (! current_user_can('manage_life_os')) {
            wp_die('You do not have permission to test Voice Monkey.', 403);
        }

        check_admin_referer('life_os_voice_monkey_test');

        try {
            $this->voiceMonkeyService->sendTestAnnouncement();
            $this->redirectWithNotice('voice_monkey_test_success');
        } catch (\RuntimeException $exception) {
            $this->redirectWithNotice('voice_monkey_test_error', $exception->getMessage());
        }
    }

    private function countRows(string $suffix): int
    {
        global $wpdb;

        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Tables::prefixed($suffix));
    }

    private function textRow(string $key, string $label, string $value): void
    {
        echo '<tr>';
        echo '<th scope="row"><label for="life_os_' . esc_attr($key) . '">' . esc_html($label) . '</label></th>';
        echo '<td><input class="regular-text" type="text" id="life_os_' . esc_attr($key) . '" name="' . esc_attr(Options::OPTION_NAME) . '[' . esc_attr($key) . ']" value="' . esc_attr($value) . '" /></td>';
        echo '</tr>';
    }

    private function checkboxRow(string $key, string $label, bool $checked): void
    {
        echo '<tr>';
        echo '<th scope="row">' . esc_html($label) . '</th>';
        echo '<td><label><input type="checkbox" name="' . esc_attr(Options::OPTION_NAME) . '[' . esc_attr($key) . ']" value="1" ' . checked($checked, true, false) . ' /> Enable</label></td>';
        echo '</tr>';
    }

    private function redirectWithNotice(string $notice, string $message = ''): never
    {
        $args = [
            'page' => 'life-os',
            'life_os_notice' => $notice,
        ];

        if ($message !== '') {
            $args['life_os_message'] = $message;
        }

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }
}
