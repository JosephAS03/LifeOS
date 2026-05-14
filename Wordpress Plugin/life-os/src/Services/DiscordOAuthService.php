<?php

declare(strict_types=1);

namespace LifeOS\Services;

use LifeOS\Repositories\ProviderConnectionRepository;
use LifeOS\Support\Crypto;
use LifeOS\Support\Options;

final class DiscordOAuthService
{
    private const STATE_PREFIX = 'life_os_discord_state_';

    public function __construct(
        private readonly Options $options,
        private readonly Crypto $crypto,
        private readonly ProviderConnectionRepository $providerConnectionRepository,
        private readonly AuditLogService $auditLogService
    ) {
    }

    public function begin(): void
    {
        if (! is_user_logged_in()) {
            wp_die('You must be logged in to connect Discord.', 403);
        }

        $clientId = $this->options->get('discord_client_id', '');
        $clientSecret = $this->options->get('discord_client_secret', '');

        if ($clientId === '' || $clientSecret === '') {
            wp_die('Discord OAuth is not configured yet.', 400);
        }

        $state = wp_generate_uuid4();
        set_transient(
            self::STATE_PREFIX . $state,
            [
                'wp_user_id' => get_current_user_id(),
                'created_at' => time(),
            ],
            10 * MINUTE_IN_SECONDS
        );

        $scopes = preg_split('/\s+/', trim((string) $this->options->get('discord_scopes', 'identify'))) ?: ['identify'];
        $scope = implode(' ', array_unique(array_filter($scopes)));

        $authorizeUrl = add_query_arg(
            [
                'response_type' => 'code',
                'client_id' => $clientId,
                'redirect_uri' => $this->callbackUrl(),
                'scope' => $scope,
                'state' => $state,
                'prompt' => 'consent',
            ],
            'https://discord.com/oauth2/authorize'
        );

        wp_safe_redirect($authorizeUrl);
        exit;
    }

    public function handleAdminCallback(): void
    {
        $error = isset($_GET['error']) ? sanitize_text_field(wp_unslash((string) $_GET['error'])) : '';
        $errorDescription = isset($_GET['error_description']) ? sanitize_text_field(wp_unslash((string) $_GET['error_description'])) : '';

        if ($error !== '') {
            $this->redirectToSettings('discord_error', $errorDescription !== '' ? $errorDescription : $error);
        }

        $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash((string) $_GET['code'])) : '';
        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash((string) $_GET['state'])) : '';

        if ($code === '' || $state === '') {
            $this->redirectToSettings('discord_error', 'Missing Discord OAuth code or state.');
        }

        $this->handleCallback($code, $state);
    }

    public function handleCallback(string $code, string $state): void
    {
        $stateData = get_transient(self::STATE_PREFIX . $state);
        delete_transient(self::STATE_PREFIX . $state);

        if (! is_array($stateData) || empty($stateData['wp_user_id'])) {
            $this->redirectToSettings('discord_error', 'Invalid or expired Discord OAuth state.');
        }

        $userId = (int) $stateData['wp_user_id'];
        $tokens = $this->exchangeCode($code);
        $profile = $this->fetchDiscordUser($tokens['access_token']);
        $scopeList = preg_split('/\s+/', trim((string) ($tokens['scope'] ?? 'identify'))) ?: ['identify'];

        $metadata = [
            'username' => $profile['username'] ?? null,
            'global_name' => $profile['global_name'] ?? null,
            'avatar' => $profile['avatar'] ?? null,
        ];

        if (in_array('guilds.members.read', $scopeList, true) && $this->options->get('discord_guild_id', '') !== '') {
            $metadata['guild_member'] = $this->fetchGuildMember(
                $tokens['access_token'],
                (string) $this->options->get('discord_guild_id', '')
            );
        }

        if (
            $this->options->get('discord_auto_join', '0') === '1'
            && in_array('guilds.join', $scopeList, true)
            && $this->options->get('discord_guild_id', '') !== ''
            && $this->options->get('discord_bot_token', '') !== ''
        ) {
            $metadata['join_result'] = $this->joinGuild(
                (string) $this->options->get('discord_guild_id', ''),
                (string) $profile['id'],
                $tokens['access_token']
            );
        }

        $expiresAt = null;
        if (! empty($tokens['expires_in'])) {
            $expiresAt = gmdate('Y-m-d H:i:s', time() + (int) $tokens['expires_in']);
        }

        $this->providerConnectionRepository->upsert($userId, 'discord', [
            'external_user_id' => (string) ($profile['id'] ?? ''),
            'scopes' => $scopeList,
            'access_token_encrypted' => $this->crypto->encrypt((string) ($tokens['access_token'] ?? '')),
            'refresh_token_encrypted' => $this->crypto->encrypt((string) ($tokens['refresh_token'] ?? '')),
            'token_expires_at' => $expiresAt,
            'metadata' => $metadata,
            'status' => 'active',
            'last_success_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $this->auditLogService->record('discord_linked', 'provider_connection', null, [
            'discord_user_id' => $profile['id'] ?? null,
            'scopes' => $scopeList,
        ], $userId);

        $this->redirectToSettings('discord_linked');
    }

    public function connectionSummary(int $userId): ?array
    {
        $connection = $this->providerConnectionRepository->findByUserAndProvider($userId, 'discord');
        if (! $connection) {
            return null;
        }

        $connection['metadata'] = $connection['metadata_json'] ? json_decode((string) $connection['metadata_json'], true) : [];
        $connection['scopes'] = $connection['scopes_json'] ? json_decode((string) $connection['scopes_json'], true) : [];

        return $connection;
    }

    public function callbackUrl(): string
    {
        return add_query_arg(
            ['action' => 'life_os_discord_oauth_callback'],
            admin_url('admin-post.php')
        );
    }

    private function exchangeCode(string $code): array
    {
        $response = wp_remote_post('https://discord.com/api/oauth2/token', [
            'body' => [
                'client_id' => (string) $this->options->get('discord_client_id', ''),
                'client_secret' => (string) $this->options->get('discord_client_secret', ''),
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->callbackUrl(),
            ],
        ]);

        if (is_wp_error($response)) {
            $this->redirectToSettings('discord_error', 'Discord token exchange failed: ' . $response->get_error_message());
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (! is_array($body) || empty($body['access_token'])) {
            $message = is_array($body) && ! empty($body['error_description'])
                ? (string) $body['error_description']
                : 'Discord token exchange returned an invalid response.';
            $this->redirectToSettings('discord_error', $message);
        }

        return $body;
    }

    private function fetchDiscordUser(string $accessToken): array
    {
        $response = wp_remote_get('https://discord.com/api/users/@me', [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
            ],
        ]);

        if (is_wp_error($response)) {
            $this->redirectToSettings('discord_error', 'Discord profile lookup failed: ' . $response->get_error_message());
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        return is_array($body) ? $body : [];
    }

    private function fetchGuildMember(string $accessToken, string $guildId): array
    {
        $response = wp_remote_get('https://discord.com/api/users/@me/guilds/' . rawurlencode($guildId) . '/member', [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
            ],
        ]);

        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        return is_array($body) ? $body : [];
    }

    private function joinGuild(string $guildId, string $discordUserId, string $accessToken): array
    {
        $response = wp_remote_request(
            'https://discord.com/api/guilds/' . rawurlencode($guildId) . '/members/' . rawurlencode($discordUserId),
            [
                'method' => 'PUT',
                'headers' => [
                    'Authorization' => 'Bot ' . (string) $this->options->get('discord_bot_token', ''),
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode([
                    'access_token' => $accessToken,
                ]),
            ]
        );

        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }

        return [
            'status' => wp_remote_retrieve_response_code($response),
            'body' => json_decode((string) wp_remote_retrieve_body($response), true),
        ];
    }

    private function redirectToSettings(string $notice, string $message = ''): never
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
