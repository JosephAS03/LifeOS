<?php

declare(strict_types=1);

namespace LifeOS\Services;

interface FinanceProviderInterface
{
    public function providerKey(): string;

    public function connectWithSetupToken(int $userId, string $setupToken): array;

    public function manualSync(int $userId, string $triggerSource = 'manual'): array;

    public function syncDueProviders(string $triggerSource = 'heartbeat'): array;

    public function disconnect(int $userId, bool $purgeHistory = false): array;

    public function status(int $userId): array;

    public function recentTransactions(int $userId, int $days = 30, int $limit = 25): array;

    public function dashboardData(int $userId): array;

    public function importCsv(int $userId, string $csvText, array $options = []): array;
}
