<?php

declare(strict_types=1);

namespace LifeOS\Services;

use LifeOS\Repositories\ProviderConnectionRepository;

final class HeartbeatService
{
    public function __construct(
        private readonly TaskService $taskService,
        private readonly ProviderConnectionRepository $providerConnectionRepository,
        private readonly AuditLogService $auditLogService,
        private readonly FinanceProviderInterface $financeProvider,
        private readonly VoiceMonkeyService $voiceMonkeyService
    ) {
    }

    public function handle(array $payload): array
    {
        $decayed = $this->taskService->decayDueTasks();
        $userId = isset($payload['wp_user_id']) ? (int) $payload['wp_user_id'] : 0;
        $financeSync = $this->financeProvider->syncDueProviders('heartbeat');
        $voiceMonkey = $this->voiceMonkeyService->processDueAnnouncements();

        $result = [
            'heartbeat_id' => sanitize_text_field((string) ($payload['heartbeat_id'] ?? '')),
            'decayed_tasks' => $decayed,
            'due_next_24h' => $userId > 0 ? $this->taskService->countDueWithinHours($userId, 24) : null,
            'stale_connections' => $this->providerConnectionRepository->staleConnections(),
            'finance_sync' => $financeSync,
            'voice_monkey' => $voiceMonkey,
            'processed_at' => gmdate('c'),
        ];

        $this->auditLogService->record('heartbeat_processed', 'heartbeat', null, $result, $userId > 0 ? $userId : null);

        return $result;
    }
}
