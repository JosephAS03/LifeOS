<?php

declare(strict_types=1);

namespace LifeOS\Rest;

use LifeOS\Repositories\NonceRepository;
use LifeOS\Repositories\TimelineRepository;
use LifeOS\Services\DiscordOAuthService;
use LifeOS\Services\FinanceProviderInterface;
use LifeOS\Services\HealthImportService;
use LifeOS\Services\HeartbeatService;
use LifeOS\Services\MoodService;
use LifeOS\Services\TaskService;
use LifeOS\Support\Hmac;
use LifeOS\Support\Options;
use LifeOS\Support\Response;
use WP_REST_Request;

final class RestApi
{
    public function __construct(
        private readonly Options $options,
        private readonly Hmac $hmac,
        private readonly NonceRepository $nonceRepository,
        private readonly TimelineRepository $timelineRepository,
        private readonly TaskService $taskService,
        private readonly MoodService $moodService,
        private readonly HealthImportService $healthImportService,
        private readonly HeartbeatService $heartbeatService,
        private readonly DiscordOAuthService $discordOAuthService,
        private readonly FinanceProviderInterface $financeProvider
    ) {
    }

    public function boot(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route('life-os/v1', '/tasks', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'listTasks'],
                'permission_callback' => [$this, 'allowSignedOrLoggedIn'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'createTask'],
                'permission_callback' => [$this, 'allowSignedOrLoggedIn'],
            ],
        ]);

        register_rest_route('life-os/v1', '/tasks/(?P<id>\d+)/complete', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'completeTask'],
                'permission_callback' => [$this, 'allowSignedOrLoggedIn'],
            ],
        ]);

        register_rest_route('life-os/v1', '/tasks/(?P<id>\d+)/snooze', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'snoozeTask'],
                'permission_callback' => [$this, 'allowSignedOrLoggedIn'],
            ],
        ]);

        register_rest_route('life-os/v1', '/mood', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'listMood'],
                'permission_callback' => [$this, 'allowSignedOrLoggedIn'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'createMood'],
                'permission_callback' => [$this, 'allowSignedOrLoggedIn'],
            ],
        ]);

        register_rest_route('life-os/v1', '/health/import', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'importHealth'],
                'permission_callback' => '__return_true',
            ],
        ]);

        register_rest_route('life-os/v1', '/health/summary', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'healthSummary'],
                'permission_callback' => [$this, 'allowSignedOrLoggedIn'],
            ],
        ]);

        register_rest_route('life-os/v1', '/timeline/moment', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'timelineMoment'],
                'permission_callback' => [$this, 'allowSignedOrLoggedIn'],
            ],
        ]);

        register_rest_route('life-os/v1', '/finance/simplefin/connect', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'connectSimpleFin'],
                'permission_callback' => fn (): bool => current_user_can('manage_life_os'),
            ],
        ]);

        register_rest_route('life-os/v1', '/finance/simplefin/sync', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'syncSimpleFin'],
                'permission_callback' => fn (): bool => current_user_can('manage_life_os'),
            ],
        ]);

        register_rest_route('life-os/v1', '/finance/simplefin/disconnect', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'disconnectSimpleFin'],
                'permission_callback' => fn (): bool => current_user_can('manage_life_os'),
            ],
        ]);

        register_rest_route('life-os/v1', '/finance/simplefin/status', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'simpleFinStatus'],
                'permission_callback' => fn (): bool => current_user_can('manage_life_os'),
            ],
        ]);

        register_rest_route('life-os/v1', '/finance/import/csv', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'importFinanceCsv'],
                'permission_callback' => fn (): bool => current_user_can('manage_life_os'),
            ],
        ]);

        register_rest_route('life-os/v1', '/finance/recent', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'recentFinance'],
                'permission_callback' => [$this, 'allowSignedOrLoggedIn'],
            ],
        ]);

        register_rest_route('life-os/v1', '/heartbeat', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'heartbeat'],
                'permission_callback' => '__return_true',
            ],
        ]);

        register_rest_route('life-os/v1', '/discord/oauth/callback', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'discordCallback'],
                'permission_callback' => '__return_true',
            ],
        ]);
    }

    public function allowSignedOrLoggedIn(WP_REST_Request $request): bool
    {
        if (current_user_can('read')) {
            return true;
        }

        return $this->isSignedRequest($request, (string) $this->options->get('bot_shared_secret', ''))
            || $this->isSignedRequest($request, (string) $this->options->get('health_bridge_secret', ''));
    }

    public function listTasks(WP_REST_Request $request)
    {
        if (($error = $this->requireBotSignatureWhenLoggedOut($request)) !== null) {
            return $error;
        }

        $userId = current_user_can('read') ? get_current_user_id() : (int) ($request->get_param('wp_user_id') ?: 1);
        $status = $request->get_param('status');
        $limit = (int) ($request->get_param('limit') ?: 50);

        return Response::success([
            'tasks' => $this->taskService->listTasks($userId, is_string($status) ? $status : null, $limit),
        ]);
    }

    public function createTask(WP_REST_Request $request)
    {
        if (($error = $this->requireBotSignatureWhenLoggedOut($request)) !== null) {
            return $error;
        }

        $userId = current_user_can('read') ? get_current_user_id() : (int) ($request->get_param('wp_user_id') ?: 1);
        $task = $this->taskService->createTask(
            $userId,
            (array) $request->get_json_params(),
            current_user_can('read') ? 'manual' : 'discord_bot'
        );

        return Response::success(['task' => $task], 201);
    }

    public function completeTask(WP_REST_Request $request)
    {
        if (($error = $this->requireBotSignatureWhenLoggedOut($request)) !== null) {
            return $error;
        }

        $userId = current_user_can('read') ? get_current_user_id() : (int) ($request->get_param('wp_user_id') ?: 1);
        $task = $this->taskService->completeTask((int) $request['id'], $userId, current_user_can('read') ? 'manual' : 'discord_bot');

        if (! $task) {
            return Response::error('life_os_task_not_found', 'Task not found or not owned by the current user.', 404);
        }

        return Response::success(['task' => $task]);
    }

    public function snoozeTask(WP_REST_Request $request)
    {
        if (($error = $this->requireBotSignatureWhenLoggedOut($request)) !== null) {
            return $error;
        }

        $userId = current_user_can('read') ? get_current_user_id() : (int) ($request->get_param('wp_user_id') ?: 1);
        $minutes = (int) ($request->get_param('minutes') ?: 15);
        $task = $this->taskService->snoozeTask((int) $request['id'], $userId, $minutes, current_user_can('read') ? 'manual' : 'discord_bot');

        if (! $task) {
            return Response::error('life_os_task_not_found', 'Task not found or not owned by the current user.', 404);
        }

        return Response::success(['task' => $task]);
    }

    public function listMood(WP_REST_Request $request)
    {
        if (($error = $this->requireBotSignatureWhenLoggedOut($request)) !== null) {
            return $error;
        }

        $userId = current_user_can('read') ? get_current_user_id() : (int) ($request->get_param('wp_user_id') ?: 1);
        $limit = (int) ($request->get_param('limit') ?: 50);

        return Response::success([
            'entries' => $this->moodService->listEntries($userId, $limit),
        ]);
    }

    public function createMood(WP_REST_Request $request)
    {
        if (($error = $this->requireBotSignatureWhenLoggedOut($request)) !== null) {
            return $error;
        }

        $userId = current_user_can('read') ? get_current_user_id() : (int) ($request->get_param('wp_user_id') ?: 1);
        $entry = $this->moodService->createEntry($userId, (array) $request->get_json_params(), current_user_can('read') ? 'manual' : 'discord_bot');

        return Response::success(['entry' => $entry], 201);
    }

    public function importHealth(WP_REST_Request $request)
    {
        if (! $this->verifySignedRequest($request, (string) $this->options->get('health_bridge_secret', ''), 'health_bridge', (string) $request->get_header('x-lifeos-bridge-id'))) {
            return Response::error('life_os_invalid_signature', 'Request signature is invalid or expired.', 401);
        }

        $payload = (array) $request->get_json_params();
        $result = $this->healthImportService->importRecords(
            (int) ($payload['wp_user_id'] ?? 1),
            sanitize_key((string) ($payload['source'] ?? 'health_bridge')),
            sanitize_text_field((string) ($payload['timezone'] ?? 'UTC')),
            is_array($payload['records'] ?? null) ? $payload['records'] : []
        );

        return Response::success($result, 202);
    }

    public function healthSummary(WP_REST_Request $request)
    {
        if (($error = $this->requireBotSignatureWhenLoggedOut($request)) !== null) {
            return $error;
        }

        $userId = current_user_can('read') ? get_current_user_id() : (int) ($request->get_param('wp_user_id') ?: 1);
        $date = (string) ($request->get_param('date') ?: gmdate('Y-m-d'));

        return Response::success([
            'summary' => $this->healthImportService->summaryForDate($userId, $date),
        ]);
    }

    public function timelineMoment(WP_REST_Request $request)
    {
        if (($error = $this->requireBotSignatureWhenLoggedOut($request)) !== null) {
            return $error;
        }

        $userId = current_user_can('read') ? get_current_user_id() : (int) ($request->get_param('wp_user_id') ?: 1);
        $at = (string) ($request->get_param('at') ?: gmdate('c'));
        $radiusMinutes = max(1, (int) ($request->get_param('radius_minutes') ?: 60));
        $domains = $request->get_param('domains');

        return Response::success([
            'items' => $this->timelineRepository->moment(
                $userId,
                gmdate('Y-m-d H:i:s', strtotime($at)),
                $radiusMinutes * 60,
                is_array($domains) ? array_map('sanitize_key', $domains) : []
            ),
        ]);
    }

    public function connectSimpleFin(WP_REST_Request $request)
    {
        try {
            return Response::success([
                'finance' => $this->financeProvider->connectWithSetupToken(
                    get_current_user_id(),
                    (string) $request->get_param('setup_token')
                ),
            ]);
        } catch (\RuntimeException $exception) {
            return Response::error('life_os_simplefin_error', $exception->getMessage(), 400);
        }
    }

    public function syncSimpleFin(WP_REST_Request $request)
    {
        try {
            return Response::success([
                'finance' => $this->financeProvider->manualSync(get_current_user_id(), 'rest_manual'),
            ]);
        } catch (\RuntimeException $exception) {
            return Response::error('life_os_simplefin_error', $exception->getMessage(), 400);
        }
    }

    public function disconnectSimpleFin(WP_REST_Request $request)
    {
        try {
            return Response::success([
                'finance' => $this->financeProvider->disconnect(
                    get_current_user_id(),
                    (bool) $request->get_param('purge_history')
                ),
            ]);
        } catch (\RuntimeException $exception) {
            return Response::error('life_os_simplefin_error', $exception->getMessage(), 400);
        }
    }

    public function simpleFinStatus(WP_REST_Request $request)
    {
        return Response::success([
            'finance' => $this->financeProvider->status(get_current_user_id()),
        ]);
    }

    public function importFinanceCsv(WP_REST_Request $request)
    {
        $csvText = (string) ($request->get_param('csv_text') ?: $request->get_body());

        try {
            return Response::success([
                'finance' => $this->financeProvider->importCsv(get_current_user_id(), $csvText, (array) $request->get_json_params()),
            ], 201);
        } catch (\RuntimeException $exception) {
            return Response::error('life_os_finance_csv_error', $exception->getMessage(), 400);
        }
    }

    public function recentFinance(WP_REST_Request $request)
    {
        if (($error = $this->requireBotSignatureWhenLoggedOut($request)) !== null) {
            return $error;
        }

        $userId = current_user_can('read') ? get_current_user_id() : (int) ($request->get_param('wp_user_id') ?: 1);
        $days = (int) ($request->get_param('days') ?: 30);
        $limit = (int) ($request->get_param('limit') ?: 25);

        return Response::success([
            'transactions' => $this->financeProvider->recentTransactions($userId, $days, $limit),
        ]);
    }

    public function heartbeat(WP_REST_Request $request)
    {
        if (! $this->verifySignedRequest($request, (string) $this->options->get('bot_shared_secret', ''), 'discord_bot', (string) $request->get_header('x-lifeos-bot-id'))) {
            return Response::error('life_os_invalid_signature', 'Request signature is invalid or expired.', 401);
        }

        $payload = (array) $request->get_json_params();

        return Response::success($this->heartbeatService->handle($payload));
    }

    public function discordCallback(WP_REST_Request $request)
    {
        $code = (string) $request->get_param('code');
        $state = (string) $request->get_param('state');

        if ($code === '' || $state === '') {
            return Response::error('life_os_invalid_callback', 'Missing Discord OAuth code or state.', 400);
        }

        $this->discordOAuthService->handleCallback($code, $state);

        return Response::success(['message' => 'Redirecting...']);
    }

    private function verifySignedRequest(WP_REST_Request $request, string $secret, string $sourceType, string $sourceId): bool
    {
        if ($sourceId === '') {
            return false;
        }

        $signature = strtolower((string) $request->get_header('x-lifeos-signature'));
        $timestamp = (string) $request->get_header('x-lifeos-timestamp');
        $nonce = (string) $request->get_header('x-lifeos-nonce');
        $body = (string) $request->get_body();
        $method = (string) $request->get_method();
        $target = $this->canonicalRequestTarget($request);

        if (! $this->hmac->verify($signature, $secret, $timestamp, $nonce, $method, $target, $body)) {
            return false;
        }

        return $this->nonceRepository->remember($sourceType, $sourceId, $nonce, $this->hmac->requestHash($method, $target, $body));
    }

    private function isSignedRequest(WP_REST_Request $request, string $secret): bool
    {
        $signature = strtolower((string) $request->get_header('x-lifeos-signature'));
        $timestamp = (string) $request->get_header('x-lifeos-timestamp');
        $nonce = (string) $request->get_header('x-lifeos-nonce');
        $body = (string) $request->get_body();
        $method = (string) $request->get_method();
        $target = $this->canonicalRequestTarget($request);

        return $this->hmac->verify($signature, $secret, $timestamp, $nonce, $method, $target, $body);
    }

    private function requireBotSignatureWhenLoggedOut(WP_REST_Request $request): ?\WP_REST_Response
    {
        if (current_user_can('read')) {
            return null;
        }

        $sourceId = (string) $request->get_header('x-lifeos-bot-id');

        if (! $this->verifySignedRequest($request, (string) $this->options->get('bot_shared_secret', ''), 'discord_bot', $sourceId)) {
            return Response::error('life_os_invalid_signature', 'Request signature is invalid or expired.', 401);
        }

        return null;
    }

    private function canonicalRequestTarget(WP_REST_Request $request): string
    {
        $route = (string) $request->get_route();
        $prefix = '/life-os/v1';

        if (str_starts_with($route, $prefix)) {
            $route = substr($route, strlen($prefix));
        }

        $route = $route !== '' ? $route : '/';

        $query = $request->get_query_params();
        if ($query === []) {
            return $route;
        }

        ksort($query);
        $encoded = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $encoded === '' ? $route : $route . '?' . $encoded;
    }
}
