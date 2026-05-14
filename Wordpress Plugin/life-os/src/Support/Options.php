<?php

declare(strict_types=1);

namespace LifeOS\Support;

final class Options
{
    public const OPTION_NAME = 'life_os_settings';

    public function all(): array
    {
        $saved = get_option(self::OPTION_NAME, []);
        if (! is_array($saved)) {
            $saved = [];
        }

        return array_merge(self::defaults(), $saved);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $settings = $this->all();

        return $settings[$key] ?? $default;
    }

    public static function defaults(): array
    {
        return [
            'timezone' => 'America/New_York',
            'auto_create_feature_pages' => '1',
            'auto_retire_feature_pages' => '0',
            'discord_client_id' => '',
            'discord_client_secret' => '',
            'discord_bot_token' => '',
            'discord_guild_id' => '',
            'discord_scopes' => 'identify',
            'discord_auto_join' => '0',
            'bot_shared_secret' => '',
            'health_bridge_secret' => '',
            'google_client_id' => '',
            'google_client_secret' => '',
            'voice_monkey_enabled' => '0',
            'voice_monkey_token' => '',
            'voice_monkey_default_target' => '',
        ];
    }

    public static function sanitize(array $input): array
    {
        $defaults = self::defaults();
        $output = [];

        foreach ($defaults as $key => $default) {
            $value = $input[$key] ?? $default;

            if (in_array($key, ['auto_create_feature_pages', 'auto_retire_feature_pages', 'discord_auto_join', 'voice_monkey_enabled'], true)) {
                $output[$key] = $value ? '1' : '0';
                continue;
            }

            $output[$key] = is_string($value) ? trim(wp_unslash($value)) : $default;
        }

        return $output;
    }
}
