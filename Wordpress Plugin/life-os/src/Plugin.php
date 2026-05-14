<?php

declare(strict_types=1);

namespace LifeOS;

use LifeOS\Admin\FinanceConnectionsPage;
use LifeOS\Admin\SettingsPage;
use LifeOS\Frontend\Shortcodes;
use LifeOS\Repositories\NonceRepository;
use LifeOS\Repositories\ProviderConnectionRepository;
use LifeOS\Repositories\TimelineRepository;
use LifeOS\Rest\RestApi;
use LifeOS\Services\AuditLogService;
use LifeOS\Services\DiscordOAuthService;
use LifeOS\Services\HealthImportService;
use LifeOS\Services\HeartbeatService;
use LifeOS\Services\MoodService;
use LifeOS\Services\PageProvisioner;
use LifeOS\Services\SimpleFinFinanceProvider;
use LifeOS\Services\TaskService;
use LifeOS\Services\VoiceMonkeyService;
use LifeOS\Support\Crypto;
use LifeOS\Support\FeatureRegistry;
use LifeOS\Support\GitHubReleaseUpdater;
use LifeOS\Support\Hmac;
use LifeOS\Support\Options;

final class Plugin
{
    public function boot(): void
    {
        Installer::maybeUpgrade();

        $options = new Options();
        $crypto = new Crypto();
        $hmac = new Hmac();
        $gitHubReleaseUpdater = new GitHubReleaseUpdater();
        $auditLogService = new AuditLogService();
        $featureRegistry = new FeatureRegistry();
        $timelineRepository = new TimelineRepository();
        $providerConnectionRepository = new ProviderConnectionRepository();
        $nonceRepository = new NonceRepository();

        $taskService = new TaskService($timelineRepository, $auditLogService);
        $moodService = new MoodService($timelineRepository, $auditLogService);
        $healthImportService = new HealthImportService($timelineRepository, $auditLogService);
        $discordOAuthService = new DiscordOAuthService($options, $crypto, $providerConnectionRepository, $auditLogService);
        $pageProvisioner = new PageProvisioner($options, $featureRegistry, $auditLogService);
        $voiceMonkeyService = new VoiceMonkeyService($options, $auditLogService);
        $financeProvider = new SimpleFinFinanceProvider($crypto, $providerConnectionRepository, $timelineRepository, $auditLogService);
        $heartbeatService = new HeartbeatService($taskService, $providerConnectionRepository, $auditLogService, $financeProvider, $voiceMonkeyService);

        $gitHubReleaseUpdater->boot();
        (new SettingsPage($options, $discordOAuthService, $pageProvisioner, $voiceMonkeyService))->boot();
        (new FinanceConnectionsPage($financeProvider))->boot();
        $pageProvisioner->boot();
        (new Shortcodes($taskService, $moodService, $healthImportService, $financeProvider, $timelineRepository, $pageProvisioner))->boot();
        (new RestApi(
            $options,
            $hmac,
            $nonceRepository,
            $timelineRepository,
            $taskService,
            $moodService,
            $healthImportService,
            $heartbeatService,
            $discordOAuthService,
            $financeProvider
        ))->boot();

        add_action('admin_post_life_os_discord_connect', [$discordOAuthService, 'begin']);
        add_action('admin_post_life_os_discord_oauth_callback', [$discordOAuthService, 'handleAdminCallback']);
        add_action('admin_post_nopriv_life_os_discord_oauth_callback', [$discordOAuthService, 'handleAdminCallback']);
    }
}
