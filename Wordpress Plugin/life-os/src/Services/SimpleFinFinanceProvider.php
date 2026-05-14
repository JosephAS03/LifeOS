<?php

declare(strict_types=1);

namespace LifeOS\Services;

use LifeOS\Repositories\ProviderConnectionRepository;
use LifeOS\Repositories\TimelineRepository;
use LifeOS\Support\Crypto;
use LifeOS\Support\Tables;
use RuntimeException;
use Throwable;

final class SimpleFinFinanceProvider implements FinanceProviderInterface
{
    private const PROVIDER = 'simplefin';
    private const CSV_PROVIDER = 'csv_import';
    private const SIMPLEFIN_CREATE_URL = 'https://bridge.simplefin.org/simplefin/create';
    private const SYNC_RESOURCE_TYPE = 'finance_provider_sync';
    private const SYNC_INTERVAL_SECONDS = 23 * HOUR_IN_SECONDS;
    private const ERROR_RETRY_SECONDS = HOUR_IN_SECONDS;
    private const LOOKBACK_DAYS = 90;

    public function __construct(
        private readonly Crypto $crypto,
        private readonly ProviderConnectionRepository $providerConnectionRepository,
        private readonly TimelineRepository $timelineRepository,
        private readonly AuditLogService $auditLogService
    ) {
    }

    public function providerKey(): string
    {
        return self::PROVIDER;
    }

    public function createUrl(): string
    {
        return self::SIMPLEFIN_CREATE_URL;
    }

    public function connectWithSetupToken(int $userId, string $setupToken): array
    {
        $setupToken = trim($setupToken);
        if ($setupToken === '') {
            throw new RuntimeException('A SimpleFIN setup token is required.');
        }

        $claimUrl = $this->decodeSetupToken($setupToken);
        $accessUrl = $this->claimAccessUrl($claimUrl);
        $accessMeta = $this->safeAccessMetadata($accessUrl);
        $now = gmdate('Y-m-d H:i:s');

        $connectionId = $this->providerConnectionRepository->upsert($userId, self::PROVIDER, [
            'external_account_id' => $accessMeta['root_url'],
            'access_token_encrypted' => $this->crypto->encrypt($accessUrl),
            'status' => 'active',
            'metadata' => [
                'provider' => self::PROVIDER,
                'bridge_host' => $accessMeta['host'],
                'bridge_root_url' => $accessMeta['root_url'],
                'connected_via' => 'setup_token',
                'protocol_version' => '2',
            ],
            'linked_at' => $now,
            'revoked_at' => null,
            'last_error_at' => null,
        ]);

        $sync = $this->syncConnection($connectionId, true, 'simplefin_connect');

        $this->auditLogService->record('simplefin_connected', 'provider_connection', $connectionId, [
            'provider' => self::PROVIDER,
            'bridge_host' => $accessMeta['host'],
        ], $userId);

        return [
            'connection_id' => $connectionId,
            'sync' => $sync,
            'status' => $this->status($userId),
        ];
    }

    public function manualSync(int $userId, string $triggerSource = 'manual'): array
    {
        $connection = $this->providerConnectionRepository->findByUserAndProvider($userId, self::PROVIDER);
        if (! is_array($connection)) {
            throw new RuntimeException('SimpleFIN is not connected yet.');
        }

        return $this->syncConnection((int) $connection['id'], true, $triggerSource);
    }

    public function syncDueProviders(string $triggerSource = 'heartbeat'): array
    {
        $connections = $this->providerConnectionRepository->activeByProvider(self::PROVIDER);
        $results = [];
        $synced = 0;
        $skipped = 0;

        foreach ($connections as $connection) {
            if (! $this->isSyncDue($connection)) {
                $skipped++;
                continue;
            }

            try {
                $result = $this->syncConnection((int) $connection['id'], false, $triggerSource);
                if (! empty($result['skipped'])) {
                    $skipped++;
                } else {
                    $synced++;
                }
                $results[] = $result;
            } catch (Throwable $exception) {
                $results[] = [
                    'connection_id' => (int) $connection['id'],
                    'provider' => self::PROVIDER,
                    'error' => $exception->getMessage(),
                ];
            }
        }

        return [
            'provider' => self::PROVIDER,
            'synced' => $synced,
            'skipped' => $skipped,
            'results' => $results,
        ];
    }

    public function disconnect(int $userId, bool $purgeHistory = false): array
    {
        global $wpdb;

        $connection = $this->providerConnectionRepository->findByUserAndProvider($userId, self::PROVIDER);
        if (! is_array($connection)) {
            return [
                'disconnected' => false,
                'purged' => false,
                'message' => 'No SimpleFIN connection was found.',
            ];
        }

        $connectionId = (int) $connection['id'];
        $now = gmdate('Y-m-d H:i:s');

        if ($purgeHistory) {
            $this->purgeConnectionData($connectionId, $userId);
            $wpdb->delete(Tables::prefixed('provider_connections'), ['id' => $connectionId], ['%d']);
        } else {
            $wpdb->update(
                Tables::prefixed('provider_connections'),
                [
                    'access_token_encrypted' => '',
                    'refresh_token_encrypted' => '',
                    'status' => 'inactive',
                    'revoked_at' => $now,
                ],
                ['id' => $connectionId],
                ['%s', '%s', '%s', '%s'],
                ['%d']
            );
        }

        delete_transient($this->syncLockKey($connectionId));

        $this->auditLogService->record('simplefin_disconnected', 'provider_connection', $connectionId, [
            'purged_history' => $purgeHistory,
        ], $userId);

        return [
            'disconnected' => true,
            'purged' => $purgeHistory,
        ];
    }

    public function status(int $userId): array
    {
        global $wpdb;

        $connection = $this->providerConnectionRepository->findByUserAndProvider($userId, self::PROVIDER);
        if (! is_array($connection)) {
            return [
                'connected' => false,
                'provider' => self::PROVIDER,
                'setup_url' => self::SIMPLEFIN_CREATE_URL,
                'due_for_sync' => false,
                'counts' => [
                    'accounts' => 0,
                    'transactions' => 0,
                    'balance_snapshots' => 0,
                    'raw_logs' => 0,
                ],
                'accounts' => [],
                'latest_batch' => null,
            ];
        }

        $connectionId = (int) $connection['id'];
        $latestBatch = $this->latestBatch($connectionId);

        return [
            'connected' => true,
            'provider' => self::PROVIDER,
            'setup_url' => self::SIMPLEFIN_CREATE_URL,
            'connection_id' => $connectionId,
            'status' => (string) $connection['status'],
            'linked_at' => $connection['linked_at'] ?: null,
            'revoked_at' => $connection['revoked_at'] ?: null,
            'last_success_at' => $connection['last_success_at'] ?: null,
            'last_error_at' => $connection['last_error_at'] ?: null,
            'due_for_sync' => $this->isSyncDue($connection),
            'next_due_at' => $this->nextDueAt($connection),
            'counts' => [
                'accounts' => (int) $wpdb->get_var($wpdb->prepare(
                    'SELECT COUNT(*) FROM ' . Tables::prefixed('finance_accounts') . ' WHERE provider_connection_id = %d',
                    $connectionId
                )),
                'transactions' => (int) $wpdb->get_var($wpdb->prepare(
                    'SELECT COUNT(*) FROM ' . Tables::prefixed('finance_transactions') . ' WHERE provider_connection_id = %d',
                    $connectionId
                )),
                'balance_snapshots' => (int) $wpdb->get_var($wpdb->prepare(
                    'SELECT COUNT(*) FROM ' . Tables::prefixed('finance_balance_snapshots') . ' WHERE provider_connection_id = %d',
                    $connectionId
                )),
                'raw_logs' => (int) $wpdb->get_var($wpdb->prepare(
                    'SELECT COUNT(*) FROM ' . Tables::prefixed('finance_raw_logs') . ' WHERE provider_connection_id = %d',
                    $connectionId
                )),
            ],
            'accounts' => $this->accountsForConnection($connectionId, 25),
            'latest_batch' => $latestBatch,
        ];
    }

    public function recentTransactions(int $userId, int $days = 30, int $limit = 25): array
    {
        global $wpdb;

        $since = gmdate('Y-m-d', time() - max(1, $days) * DAY_IN_SECONDS);
        $transactionsTable = Tables::prefixed('finance_transactions');
        $connectionsTable = Tables::prefixed('provider_connections');

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT t.*, c.provider
                FROM {$transactionsTable} t
                INNER JOIN {$connectionsTable} c ON c.id = t.provider_connection_id
                WHERE c.wp_user_id = %d
                    AND c.provider IN (%s, %s)
                    AND t.date_posted >= %s
                ORDER BY COALESCE(t.datetime_posted, CONCAT(t.date_posted, ' 00:00:00')) DESC, t.id DESC
                LIMIT %d",
                $userId,
                self::PROVIDER,
                self::CSV_PROVIDER,
                $since,
                max(1, min(250, $limit))
            ),
            ARRAY_A
        );

        return is_array($rows) ? array_map([$this, 'decorateTransactionRow'], $rows) : [];
    }

    public function dashboardData(int $userId): array
    {
        $transactions = $this->recentTransactions($userId, 90, 200);
        $categoryTotals = $this->categoryTotals($transactions, 30);
        $recurring = $this->recurringCandidates($transactions);
        $subscriptions = $this->subscriptionCandidates($transactions);

        return [
            'status' => $this->status($userId),
            'accounts' => $this->accountsForUser($userId),
            'transactions' => array_slice($transactions, 0, 15),
            'category_totals' => $categoryTotals,
            'budget_projection' => $this->budgetProjection($categoryTotals),
            'recurring' => $recurring,
            'subscriptions' => $subscriptions,
            'timeline' => $this->timelineRepository->recent($userId, 12, ['finance']),
        ];
    }

    public function importCsv(int $userId, string $csvText, array $options = []): array
    {
        $csvText = trim($csvText);
        if ($csvText === '') {
            throw new RuntimeException('CSV import requires CSV text in the request body.');
        }

        $rows = $this->parseCsvRows($csvText);
        if ($rows === []) {
            throw new RuntimeException('CSV import did not contain any rows.');
        }

        $connectionId = $this->providerConnectionRepository->upsert($userId, self::CSV_PROVIDER, [
            'external_account_id' => 'manual-csv',
            'status' => 'active',
            'metadata' => [
                'provider' => self::CSV_PROVIDER,
                'label' => 'Manual CSV Import',
            ],
            'linked_at' => gmdate('Y-m-d H:i:s'),
            'last_success_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $windowStart = null;
        $windowEnd = null;
        $batch = $this->startBatch($connectionId, self::CSV_PROVIDER, 'manual_csv', $windowStart, $windowEnd);
        $counts = [
            'accounts_imported' => 0,
            'balance_snapshots_written' => 0,
            'transactions_seen' => 0,
            'transactions_inserted' => 0,
            'transactions_updated' => 0,
            'transactions_unchanged' => 0,
            'raw_logs_written' => 0,
            'timeline_items_updated' => 0,
            'warnings' => [],
        ];
        $seenAccounts = [];

        try {
            foreach ($rows as $row) {
                $accountLabel = trim((string) ($row['account'] ?? ($options['account_name'] ?? 'Manual Import')));
                $accountId = 'csv:' . md5($accountLabel);
                $currency = trim((string) ($row['currency'] ?? ($options['currency_code'] ?? 'USD')));
                $accountPayload = [
                    'id' => $accountId,
                    'name' => $accountLabel,
                    'currency' => $currency,
                    'balance' => $row['balance'] ?? null,
                    'available-balance' => $row['available_balance'] ?? null,
                    'balance-date' => null,
                    'extra' => [
                        'source' => 'csv_import',
                    ],
                ];

                if (! isset($seenAccounts[$accountId])) {
                    $this->upsertAccount($connectionId, $accountPayload, ['name' => 'Manual CSV Import']);
                    $seenAccounts[$accountId] = true;
                    $counts['accounts_imported']++;
                }

                $postedAt = $this->parseDateToTimestamp((string) ($row['date'] ?? $row['posted'] ?? ''));
                $transactionPayload = [
                    'id' => (string) ($row['transaction_id'] ?? md5(wp_json_encode($row))),
                    'posted' => $postedAt ?? 0,
                    'amount' => (string) ($row['amount'] ?? '0'),
                    'description' => (string) ($row['description'] ?? $row['name'] ?? 'Imported transaction'),
                    'transacted_at' => $postedAt,
                    'pending' => false,
                    'extra' => [
                        'merchant' => (string) ($row['merchant'] ?? ''),
                        'category' => (string) ($row['category'] ?? ''),
                    ],
                ];

                $counts['raw_logs_written'] += $this->writeRawLog(
                    (int) $batch['id'],
                    $connectionId,
                    self::CSV_PROVIDER,
                    'csv_transaction',
                    $accountId,
                    (string) $transactionPayload['id'],
                    $this->transactionDedupeKey($accountId, (string) $transactionPayload['id']),
                    $row
                );

                $transactionResult = $this->upsertTransaction(
                    $connectionId,
                    $userId,
                    $accountPayload,
                    $transactionPayload,
                    self::CSV_PROVIDER
                );

                $counts['transactions_seen']++;
                $counts['transactions_inserted'] += $transactionResult['inserted'];
                $counts['transactions_updated'] += $transactionResult['updated'];
                $counts['transactions_unchanged'] += $transactionResult['unchanged'];
                $counts['timeline_items_updated'] += $transactionResult['timeline_items_updated'];
            }

            $counts['import_batch_id'] = (int) $batch['id'];
            $this->finishBatch((int) $batch['id'], 'success', $counts);
            $this->touchConnectionSuccess($connectionId);
            $this->auditLogService->record('finance_csv_imported', 'provider_connection', $connectionId, $counts, $userId);

            return $counts;
        } catch (Throwable $exception) {
            $this->finishBatch((int) $batch['id'], 'failed', $counts, $exception->getMessage());
            $this->touchConnectionError($connectionId);
            throw $exception;
        }
    }

    private function syncConnection(int $connectionId, bool $force, string $triggerSource): array
    {
        $connection = $this->providerConnectionRepository->findById($connectionId);
        if (! is_array($connection)) {
            throw new RuntimeException('Finance provider connection not found.');
        }

        if ((string) $connection['status'] !== 'active') {
            return [
                'provider' => self::PROVIDER,
                'connection_id' => $connectionId,
                'skipped' => true,
                'reason' => 'inactive',
            ];
        }

        if (! $force && ! $this->isSyncDue($connection)) {
            return [
                'provider' => self::PROVIDER,
                'connection_id' => $connectionId,
                'skipped' => true,
                'reason' => 'not_due',
            ];
        }

        if (! $this->acquireSyncLock($connectionId)) {
            return [
                'provider' => self::PROVIDER,
                'connection_id' => $connectionId,
                'skipped' => true,
                'reason' => 'locked',
            ];
        }

        $windowStart = gmdate('Y-m-d H:i:s', time() - (self::LOOKBACK_DAYS * DAY_IN_SECONDS));
        $windowEnd = gmdate('Y-m-d H:i:s');
        $batch = $this->startBatch($connectionId, self::PROVIDER, $triggerSource, $windowStart, $windowEnd);
        $this->updateSyncState($connectionId, [
            'last_sync_started_at' => gmdate('Y-m-d H:i:s'),
            'last_error_code' => null,
            'last_error_message_sanitized' => null,
            'sync_token' => (string) $batch['batch_uuid'],
        ]);

        try {
            $accountSet = $this->fetchAccountSet($connection);
            $result = $this->importAccountSet($connection, $accountSet, (int) $batch['id']);

            $status = $result['warnings'] === [] ? 'success' : 'warning';
            $result['import_batch_id'] = (int) $batch['id'];
            $result['provider'] = self::PROVIDER;
            $result['connection_id'] = $connectionId;
            $this->finishBatch((int) $batch['id'], $status, $result);
            $this->touchConnectionSuccess($connectionId);
            $this->updateSyncState($connectionId, [
                'last_sync_completed_at' => gmdate('Y-m-d H:i:s'),
                'last_error_code' => null,
                'last_error_message_sanitized' => null,
                'sync_token' => (string) $batch['batch_uuid'],
            ]);

            $this->auditLogService->record('finance_provider_sync', 'provider_connection', $connectionId, [
                'provider' => self::PROVIDER,
                'trigger_source' => $triggerSource,
                'result' => $result,
            ], (int) $connection['wp_user_id']);

            return $result;
        } catch (Throwable $exception) {
            $this->finishBatch((int) $batch['id'], 'failed', [
                'provider' => self::PROVIDER,
                'connection_id' => $connectionId,
            ], $exception->getMessage());
            $this->touchConnectionError($connectionId);
            $this->updateSyncState($connectionId, [
                'last_sync_completed_at' => gmdate('Y-m-d H:i:s'),
                'last_error_code' => 'sync_failed',
                'last_error_message_sanitized' => sanitize_text_field($exception->getMessage()),
                'sync_token' => (string) $batch['batch_uuid'],
            ]);
            throw $exception;
        } finally {
            $this->releaseSyncLock($connectionId);
        }
    }

    private function importAccountSet(array $connection, array $accountSet, int $batchId): array
    {
        $connectionsById = [];
        foreach ((array) ($accountSet['connections'] ?? []) as $connectionMeta) {
            $connectionId = (string) ($connectionMeta['conn_id'] ?? '');
            if ($connectionId === '') {
                continue;
            }

            $connectionsById[$connectionId] = is_array($connectionMeta) ? $connectionMeta : [];
        }

        $counts = [
            'accounts_imported' => 0,
            'balance_snapshots_written' => 0,
            'transactions_seen' => 0,
            'transactions_inserted' => 0,
            'transactions_updated' => 0,
            'transactions_unchanged' => 0,
            'raw_logs_written' => 0,
            'timeline_items_updated' => 0,
            'warnings' => $this->sanitizeProviderErrors((array) ($accountSet['errlist'] ?? [])),
        ];

        $counts['raw_logs_written'] += $this->writeRawLog(
            $batchId,
            (int) $connection['id'],
            self::PROVIDER,
            'account_set',
            null,
            null,
            null,
            $accountSet
        );

        foreach ((array) ($accountSet['accounts'] ?? []) as $account) {
            if (! is_array($account)) {
                continue;
            }

            $accountId = trim((string) ($account['id'] ?? ''));
            if ($accountId === '') {
                $counts['warnings'][] = 'SimpleFIN returned an account without an id.';
                continue;
            }

            $connectionMeta = $connectionsById[(string) ($account['conn_id'] ?? '')] ?? [];
            $this->upsertAccount((int) $connection['id'], $account, $connectionMeta);
            $counts['accounts_imported']++;
            $counts['balance_snapshots_written'] += $this->storeBalanceSnapshot($batchId, (int) $connection['id'], $account);
            $counts['raw_logs_written'] += $this->writeRawLog(
                $batchId,
                (int) $connection['id'],
                self::PROVIDER,
                'account',
                $accountId,
                null,
                null,
                $account
            );

            foreach ((array) ($account['transactions'] ?? []) as $transaction) {
                if (! is_array($transaction)) {
                    continue;
                }

                $providerTransactionId = trim((string) ($transaction['id'] ?? ''));
                $dedupeKey = $this->transactionDedupeKey($accountId, $providerTransactionId !== '' ? $providerTransactionId : md5(wp_json_encode($transaction)));
                $counts['raw_logs_written'] += $this->writeRawLog(
                    $batchId,
                    (int) $connection['id'],
                    self::PROVIDER,
                    'transaction',
                    $accountId,
                    $providerTransactionId !== '' ? $providerTransactionId : null,
                    $dedupeKey,
                    $transaction
                );

                $transactionResult = $this->upsertTransaction(
                    (int) $connection['id'],
                    (int) $connection['wp_user_id'],
                    $account,
                    $transaction,
                    self::PROVIDER
                );

                $counts['transactions_seen']++;
                $counts['transactions_inserted'] += $transactionResult['inserted'];
                $counts['transactions_updated'] += $transactionResult['updated'];
                $counts['transactions_unchanged'] += $transactionResult['unchanged'];
                $counts['timeline_items_updated'] += $transactionResult['timeline_items_updated'];
            }
        }

        return $counts;
    }

    private function upsertAccount(int $connectionId, array $account, array $connectionMeta): int
    {
        global $wpdb;

        $table = Tables::prefixed('finance_accounts');
        $accountId = trim((string) ($account['id'] ?? ''));
        $balanceTimestamp = isset($account['balance-date']) ? (int) $account['balance-date'] : 0;
        $balanceDate = $balanceTimestamp > 0 ? gmdate('Y-m-d H:i:s', $balanceTimestamp) : null;
        $currencyRaw = trim((string) ($account['currency'] ?? ''));

        $row = [
            'provider_connection_id' => $connectionId,
            'account_id' => $accountId,
            'institution_name' => $this->bestInstitutionName($account, $connectionMeta),
            'account_name' => sanitize_text_field((string) ($account['name'] ?? 'Account')),
            'subtype' => $this->normalizeSubtype($account),
            'mask' => $this->normalizeMask($account),
            'current_balance' => $this->decimalOrNull($account['balance'] ?? null),
            'available_balance' => $this->decimalOrNull($account['available-balance'] ?? $account['balance'] ?? null),
            'currency_code' => $currencyRaw !== '' ? $currencyRaw : null,
            'balance_date' => $balanceDate,
            'metadata_json' => wp_json_encode([
                'provider' => self::PROVIDER,
                'connection' => $connectionMeta,
                'raw' => $account,
            ]),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];

        $existingId = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE provider_connection_id = %d AND account_id = %s LIMIT 1",
                $connectionId,
                $accountId
            )
        );

        if ($existingId) {
            $wpdb->update($table, $row, ['id' => (int) $existingId]);
            return (int) $existingId;
        }

        $wpdb->insert($table, $row);
        return (int) $wpdb->insert_id;
    }

    private function storeBalanceSnapshot(int $batchId, int $connectionId, array $account): int
    {
        global $wpdb;

        $timestamp = isset($account['balance-date']) ? (int) $account['balance-date'] : 0;

        $wpdb->insert(
            Tables::prefixed('finance_balance_snapshots'),
            [
                'import_batch_id' => $batchId,
                'provider_connection_id' => $connectionId,
                'account_id' => (string) ($account['id'] ?? ''),
                'balance_date' => $timestamp > 0 ? gmdate('Y-m-d H:i:s', $timestamp) : null,
                'current_balance' => $this->decimalOrNull($account['balance'] ?? null),
                'available_balance' => $this->decimalOrNull($account['available-balance'] ?? $account['balance'] ?? null),
                'currency_code' => trim((string) ($account['currency'] ?? '')) ?: null,
                'payload_json' => wp_json_encode($account),
                'captured_at' => gmdate('Y-m-d H:i:s'),
            ]
        );

        return 1;
    }

    private function upsertTransaction(int $connectionId, int $userId, array $account, array $transaction, string $source): array
    {
        global $wpdb;

        $table = Tables::prefixed('finance_transactions');
        $accountId = (string) ($account['id'] ?? '');
        $providerTransactionId = trim((string) ($transaction['id'] ?? ''));
        $dedupeKey = $this->transactionDedupeKey(
            $accountId,
            $providerTransactionId !== '' ? $providerTransactionId : md5(wp_json_encode($transaction))
        );
        $timing = $this->transactionTiming($transaction);
        $classification = $this->classifyTransaction(
            (string) ($transaction['description'] ?? 'Transaction'),
            (float) ($transaction['amount'] ?? 0),
            (array) ($transaction['extra'] ?? []),
            (string) ($account['name'] ?? '')
        );

        $categoryPayload = [
            'provider_category' => $transaction['extra']['category'] ?? null,
            'local_category' => $classification['local_category'],
            'merchant_clean' => $classification['merchant_clean'],
            'recurring_key' => $classification['recurring_key'],
            'is_subscription_candidate' => $classification['is_subscription_candidate'],
        ];

        $row = [
            'provider_connection_id' => $connectionId,
            'account_id' => $accountId,
            'transaction_id' => $dedupeKey,
            'pending_transaction_id' => $providerTransactionId !== '' ? $providerTransactionId : null,
            'name' => sanitize_text_field((string) ($transaction['description'] ?? 'Transaction')),
            'merchant_name' => $classification['merchant_clean'] !== '' ? $classification['merchant_clean'] : null,
            'amount' => (float) ($transaction['amount'] ?? 0),
            'iso_currency_code' => $this->normalizeTransactionCurrency((string) ($account['currency'] ?? '')),
            'category_json' => wp_json_encode($categoryPayload),
            'authorized_date' => $timing['authorized_date'],
            'authorized_datetime' => $timing['authorized_datetime'],
            'date_posted' => $timing['date_posted'],
            'datetime_posted' => $timing['datetime_posted'],
            'is_pending' => $timing['is_pending'],
            'metadata_json' => wp_json_encode([
                'provider' => $source,
                'provider_account_id' => $accountId,
                'provider_transaction_id' => $providerTransactionId,
                'classification' => $classification,
                'raw' => $transaction,
            ]),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];

        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE provider_connection_id = %d AND transaction_id = %s LIMIT 1",
                $connectionId,
                $dedupeKey
            ),
            ARRAY_A
        );

        $inserted = 0;
        $updated = 0;
        $unchanged = 0;

        if (is_array($existing)) {
            $hasChanges = $this->transactionRowChanged($existing, $row);
            if ($hasChanges) {
                $wpdb->update($table, $row, ['id' => (int) $existing['id']]);
                $updated = 1;
            } else {
                $unchanged = 1;
            }
            $localTransactionId = (int) $existing['id'];
        } else {
            $wpdb->insert($table, $row);
            $localTransactionId = (int) $wpdb->insert_id;
            $inserted = 1;
        }

        $this->timelineRepository->upsert('finance_transaction', $localTransactionId, [
            'wp_user_id' => $userId,
            'domain' => 'finance',
            'title' => $classification['merchant_clean'] !== '' ? $classification['merchant_clean'] : (string) $row['name'],
            'summary' => $this->financeSummaryLine($account, $row, $classification),
            'occurred_at' => null,
            'start_at' => $timing['timeline_start_at'],
            'precision_type' => 'date_only',
            'source' => $source,
            'metadata' => [
                'provider' => $source,
                'provider_account_id' => $accountId,
                'provider_transaction_id' => $providerTransactionId,
                'amount' => (float) $row['amount'],
                'currency' => (string) ($row['iso_currency_code'] ?? ''),
                'category' => $classification['local_category'],
                'is_subscription_candidate' => $classification['is_subscription_candidate'],
            ],
        ]);

        return [
            'inserted' => $inserted,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'timeline_items_updated' => 1,
        ];
    }

    private function writeRawLog(
        int $batchId,
        int $connectionId,
        string $provider,
        string $payloadType,
        ?string $providerAccountId,
        ?string $providerTransactionId,
        ?string $dedupeKey,
        array $payload
    ): int {
        global $wpdb;

        $payloadJson = wp_json_encode($payload);
        $wpdb->insert(
            Tables::prefixed('finance_raw_logs'),
            [
                'import_batch_id' => $batchId,
                'provider_connection_id' => $connectionId,
                'provider' => $provider,
                'payload_type' => $payloadType,
                'provider_account_id' => $providerAccountId,
                'provider_transaction_id' => $providerTransactionId,
                'dedupe_key' => $dedupeKey,
                'payload_hash' => hash('sha256', (string) $payloadJson),
                'payload_json' => $payloadJson,
                'synced_at' => gmdate('Y-m-d H:i:s'),
            ]
        );

        return 1;
    }

    private function startBatch(int $connectionId, string $provider, string $triggerSource, ?string $windowStart, ?string $windowEnd): array
    {
        global $wpdb;

        $batchUuid = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('lifeos-', true);
        $row = [
            'provider_connection_id' => $connectionId,
            'provider' => $provider,
            'batch_uuid' => $batchUuid,
            'status' => 'running',
            'trigger_source' => $triggerSource,
            'requested_window_start' => $windowStart,
            'requested_window_end' => $windowEnd,
            'started_at' => gmdate('Y-m-d H:i:s'),
        ];

        $wpdb->insert(Tables::prefixed('finance_import_batches'), $row);
        $row['id'] = (int) $wpdb->insert_id;

        return $row;
    }

    private function finishBatch(int $batchId, string $status, array $summary, ?string $errorMessage = null): void
    {
        global $wpdb;

        $wpdb->update(
            Tables::prefixed('finance_import_batches'),
            [
                'status' => $status,
                'summary_json' => wp_json_encode($summary),
                'error_message_sanitized' => $errorMessage !== null ? sanitize_text_field($errorMessage) : null,
                'completed_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['id' => $batchId],
            ['%s', '%s', '%s', '%s'],
            ['%d']
        );
    }

    private function latestBatch(int $connectionId): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . Tables::prefixed('finance_import_batches') . ' WHERE provider_connection_id = %d ORDER BY id DESC LIMIT 1',
                $connectionId
            ),
            ARRAY_A
        );

        if (! is_array($row)) {
            return null;
        }

        $row['summary'] = $this->decodeJsonField($row['summary_json'] ?? null);

        return $row;
    }

    private function fetchAccountSet(array $connection): array
    {
        $accessUrl = $this->crypto->decrypt((string) ($connection['access_token_encrypted'] ?? ''));
        if ($accessUrl === '') {
            throw new RuntimeException('SimpleFIN access token is missing or could not be decrypted.');
        }

        [$baseUrl, $username, $password] = $this->accessUrlParts($accessUrl);

        $query = http_build_query([
            'version' => '2',
            'pending' => '1',
            'start-date' => (string) (time() - (self::LOOKBACK_DAYS * DAY_IN_SECONDS)),
        ], '', '&', PHP_QUERY_RFC3986);

        $response = wp_remote_get($baseUrl . '/accounts?' . $query, [
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($username . ':' . $password),
            ],
            'timeout' => 30,
            'sslverify' => true,
        ]);

        if (is_wp_error($response)) {
            throw new RuntimeException($response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($status === 403) {
            throw new RuntimeException('SimpleFIN rejected the access token. The connection may have been revoked.');
        }

        if (! is_array($body)) {
            throw new RuntimeException('SimpleFIN returned a non-JSON response.');
        }

        if ($status >= 400) {
            $errors = $this->sanitizeProviderErrors((array) ($body['errlist'] ?? []));
            throw new RuntimeException($errors !== [] ? implode(' | ', $errors) : 'SimpleFIN request failed with status ' . $status);
        }

        return $body;
    }

    private function decodeSetupToken(string $setupToken): string
    {
        $decoded = base64_decode($setupToken, true);
        if ($decoded === false) {
            throw new RuntimeException('SimpleFIN setup token is not valid base64.');
        }

        $claimUrl = trim($decoded);
        if (! $this->isHttpsUrl($claimUrl)) {
            throw new RuntimeException('SimpleFIN setup token did not decode to a valid HTTPS claim URL.');
        }

        return $claimUrl;
    }

    private function claimAccessUrl(string $claimUrl): string
    {
        $response = wp_remote_post($claimUrl, [
            'headers' => [
                'Accept' => 'text/plain',
                'Content-Length' => '0',
            ],
            'body' => '',
            'timeout' => 20,
            'sslverify' => true,
        ]);

        if (is_wp_error($response)) {
            throw new RuntimeException($response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = trim((string) wp_remote_retrieve_body($response));

        if ($status === 403) {
            throw new RuntimeException('SimpleFIN rejected the setup token. It may already have been used or compromised.');
        }

        if ($status >= 400 || $body === '') {
            throw new RuntimeException('SimpleFIN did not return an access token URL.');
        }

        if (! $this->isHttpsUrl($body)) {
            throw new RuntimeException('SimpleFIN returned an invalid access URL.');
        }

        $parts = wp_parse_url($body);
        if (! is_array($parts) || empty($parts['user']) || empty($parts['pass'])) {
            throw new RuntimeException('SimpleFIN returned an access URL without credentials.');
        }

        return $body;
    }

    private function safeAccessMetadata(string $accessUrl): array
    {
        [$baseUrl] = $this->accessUrlParts($accessUrl);
        $parts = wp_parse_url($baseUrl);

        return [
            'root_url' => $baseUrl,
            'host' => is_array($parts) ? (string) ($parts['host'] ?? '') : '',
        ];
    }

    private function accessUrlParts(string $accessUrl): array
    {
        $parts = wp_parse_url($accessUrl);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
            throw new RuntimeException('SimpleFIN access URL is invalid.');
        }

        $host = (string) ($parts['host'] ?? '');
        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        if ($host === '' || $path === '') {
            throw new RuntimeException('SimpleFIN access URL is missing host or path.');
        }

        $baseUrl = 'https://' . $host;
        if (isset($parts['port'])) {
            $baseUrl .= ':' . (int) $parts['port'];
        }
        $baseUrl .= $path;

        return [
            $baseUrl,
            rawurldecode((string) ($parts['user'] ?? '')),
            rawurldecode((string) ($parts['pass'] ?? '')),
        ];
    }

    private function updateSyncState(int $connectionId, array $attributes): void
    {
        global $wpdb;

        $table = Tables::prefixed('sync_state');
        $existingId = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE provider_connection_id = %d AND resource_type = %s AND resource_key = %s LIMIT 1",
                $connectionId,
                self::SYNC_RESOURCE_TYPE,
                self::PROVIDER
            )
        );

        $payload = array_merge([
            'provider_connection_id' => $connectionId,
            'resource_type' => self::SYNC_RESOURCE_TYPE,
            'resource_key' => self::PROVIDER,
        ], $attributes);

        if ($existingId) {
            $wpdb->update($table, $payload, ['id' => (int) $existingId]);
            return;
        }

        $wpdb->insert($table, $payload);
    }

    private function touchConnectionSuccess(int $connectionId): void
    {
        global $wpdb;

        $wpdb->update(
            Tables::prefixed('provider_connections'),
            [
                'last_success_at' => gmdate('Y-m-d H:i:s'),
                'last_error_at' => null,
            ],
            ['id' => $connectionId],
            ['%s', '%s'],
            ['%d']
        );
    }

    private function touchConnectionError(int $connectionId): void
    {
        global $wpdb;

        $wpdb->update(
            Tables::prefixed('provider_connections'),
            [
                'last_error_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['id' => $connectionId],
            ['%s'],
            ['%d']
        );
    }

    private function acquireSyncLock(int $connectionId): bool
    {
        $key = $this->syncLockKey($connectionId);
        if (get_transient($key)) {
            return false;
        }

        set_transient($key, '1', 10 * MINUTE_IN_SECONDS);
        return true;
    }

    private function releaseSyncLock(int $connectionId): void
    {
        delete_transient($this->syncLockKey($connectionId));
    }

    private function syncLockKey(int $connectionId): string
    {
        return 'life_os_finance_sync_lock_' . $connectionId;
    }

    private function isSyncDue(array $connection): bool
    {
        if ((string) ($connection['status'] ?? '') !== 'active') {
            return false;
        }

        $lastSuccess = $this->parseMysqlTimestamp($connection['last_success_at'] ?? null);
        $lastError = $this->parseMysqlTimestamp($connection['last_error_at'] ?? null);
        $linkedAt = $this->parseMysqlTimestamp($connection['linked_at'] ?? null);

        if ($lastSuccess === null && $lastError === null) {
            return true;
        }

        if ($lastError !== null && ($lastSuccess === null || $lastError > $lastSuccess)) {
            return (time() - $lastError) >= self::ERROR_RETRY_SECONDS;
        }

        if ($lastSuccess === null) {
            return true;
        }

        return (time() - $lastSuccess) >= self::SYNC_INTERVAL_SECONDS
            || ($linkedAt !== null && $lastSuccess < $linkedAt);
    }

    private function nextDueAt(array $connection): ?string
    {
        $lastSuccess = $this->parseMysqlTimestamp($connection['last_success_at'] ?? null);
        $lastError = $this->parseMysqlTimestamp($connection['last_error_at'] ?? null);
        $linkedAt = $this->parseMysqlTimestamp($connection['linked_at'] ?? null);

        if ($lastError !== null && ($lastSuccess === null || $lastError > $lastSuccess)) {
            return gmdate('c', $lastError + self::ERROR_RETRY_SECONDS);
        }

        if ($lastSuccess !== null) {
            return gmdate('c', $lastSuccess + self::SYNC_INTERVAL_SECONDS);
        }

        if ($linkedAt !== null) {
            return gmdate('c', $linkedAt);
        }

        return null;
    }

    private function transactionTiming(array $transaction): array
    {
        $postedTimestamp = isset($transaction['posted']) ? (int) $transaction['posted'] : 0;
        $transactedTimestamp = isset($transaction['transacted_at']) ? (int) $transaction['transacted_at'] : 0;
        $isPending = ! empty($transaction['pending']) || $postedTimestamp === 0;

        $effectiveTimestamp = $postedTimestamp > 0 ? $postedTimestamp : ($transactedTimestamp > 0 ? $transactedTimestamp : time());
        $datePosted = gmdate('Y-m-d', $effectiveTimestamp);

        return [
            'date_posted' => $datePosted,
            'datetime_posted' => $postedTimestamp > 0 ? gmdate('Y-m-d H:i:s', $postedTimestamp) : null,
            'authorized_date' => $transactedTimestamp > 0 ? gmdate('Y-m-d', $transactedTimestamp) : $datePosted,
            'authorized_datetime' => $transactedTimestamp > 0 ? gmdate('Y-m-d H:i:s', $transactedTimestamp) : null,
            'timeline_start_at' => $datePosted . ' 00:00:00',
            'is_pending' => $isPending ? 1 : 0,
        ];
    }

    private function classifyTransaction(string $description, float $amount, array $extra, string $accountName): array
    {
        $cleaned = trim(preg_replace('/\s+/', ' ', $description) ?: $description);
        $upper = strtoupper($cleaned);
        $merchant = preg_replace('/[^A-Z0-9 ]+/', ' ', $upper) ?: $upper;
        $merchant = trim(preg_replace('/\b(POS|DBT|ACH|DEBIT|CREDIT|PURCHASE|PAYMENT|CHECKCARD|WITHDRAWAL)\b/', ' ', $merchant) ?: $merchant);
        $merchant = trim(preg_replace('/\s+/', ' ', $merchant) ?: $merchant);

        $category = 'uncategorized';
        $subscription = false;

        $map = [
            'income' => ['PAYROLL', 'PAYCHECK', 'DIRECT DEPOSIT', 'SALARY', 'VENMO CASHOUT'],
            'housing' => ['RENT', 'MORTGAGE', 'LANDLORD', 'PROPERTY'],
            'groceries' => ['GROCERY', 'WHOLE FOODS', 'KROGER', 'SAFEWAY', 'ALDI', 'COSTCO', 'PUBLIX', 'TRADER JOE'],
            'transport' => ['UBER', 'LYFT', 'SHELL', 'EXXON', 'CHEVRON', 'MOBIL', 'SUNOCO', 'PARKING'],
            'dining' => ['RESTAURANT', 'CAFE', 'COFFEE', 'STARBUCKS', 'DOORDASH', 'UBER EATS', 'GRUBHUB'],
            'utilities' => ['UTILITY', 'ELECTRIC', 'WATER', 'INTERNET', 'VERIZON', 'COMCAST', 'AT&T', 'T MOBILE'],
            'debt' => ['LOAN', 'LENDING', 'CREDIT CARD PAYMENT', 'STUDENT LOAN'],
            'health' => ['PHARMACY', 'DOCTOR', 'HOSPITAL', 'DENTAL', 'VISION'],
            'shopping' => ['AMAZON', 'TARGET', 'WALMART', 'BEST BUY', 'ETSY'],
            'subscriptions' => ['NETFLIX', 'SPOTIFY', 'HULU', 'DISNEY', 'APPLE.COM/BILL', 'APPLE BILL', 'GOOGLE', 'PATREON', 'SUBSTACK', 'ADOBE', 'MICROSOFT 365', 'DROPBOX', 'GYM'],
        ];

        foreach ($map as $candidate => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($upper, $needle)) {
                    $category = $candidate;
                    $subscription = $candidate === 'subscriptions';
                    break 2;
                }
            }
        }

        if ($subscription === false && isset($extra['category']) && is_string($extra['category'])) {
            $extraCategory = strtolower(trim($extra['category']));
            if (str_contains($extraCategory, 'subscription')) {
                $subscription = true;
            }
        }

        $recurringKey = strtolower(trim($merchant !== '' ? $merchant : $cleaned));
        $merchantClean = ucwords(strtolower($merchant !== '' ? $merchant : $cleaned));
        if ($merchantClean === '') {
            $merchantClean = sanitize_text_field($description);
        }

        if ($amount > 0 && $category === 'uncategorized') {
            $category = 'income';
        }

        return [
            'merchant_clean' => sanitize_text_field($merchantClean),
            'local_category' => sanitize_key($category),
            'recurring_key' => sanitize_title($recurringKey !== '' ? $recurringKey : $accountName . '-' . $description),
            'is_subscription_candidate' => $subscription,
        ];
    }

    private function financeSummaryLine(array $account, array $row, array $classification): string
    {
        $amount = number_format((float) $row['amount'], 2);
        $accountName = sanitize_text_field((string) ($account['name'] ?? 'Account'));
        $category = (string) ($classification['local_category'] ?? 'uncategorized');

        return sprintf('%s on %s (%s)', $amount, $accountName, $category);
    }

    private function decorateTransactionRow(array $row): array
    {
        $row['classification'] = [];
        $row['raw_provider'] = (string) ($row['provider'] ?? '');
        $row['category'] = null;

        $categoryData = $this->decodeJsonField($row['category_json'] ?? null);
        if ($categoryData !== []) {
            $row['classification'] = $categoryData;
            $row['category'] = $categoryData['local_category'] ?? null;
        }

        $metadata = $this->decodeJsonField($row['metadata_json'] ?? null);
        if ($metadata !== []) {
            $row['provider_transaction_id'] = $metadata['provider_transaction_id'] ?? null;
            $row['provider_account_id'] = $metadata['provider_account_id'] ?? null;
        }

        return $row;
    }

    private function accountsForUser(int $userId): array
    {
        global $wpdb;

        $accountsTable = Tables::prefixed('finance_accounts');
        $connectionsTable = Tables::prefixed('provider_connections');

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT a.*, c.provider, c.status
                FROM {$accountsTable} a
                INNER JOIN {$connectionsTable} c ON c.id = a.provider_connection_id
                WHERE c.wp_user_id = %d
                    AND c.provider IN (%s, %s)
                ORDER BY a.account_name ASC",
                $userId,
                self::PROVIDER,
                self::CSV_PROVIDER
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    private function accountsForConnection(int $connectionId, int $limit): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . Tables::prefixed('finance_accounts') . ' WHERE provider_connection_id = %d ORDER BY account_name ASC LIMIT %d',
                $connectionId,
                max(1, min(100, $limit))
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    private function categoryTotals(array $transactions, int $days): array
    {
        $since = strtotime('-' . max(1, $days) . ' days');
        $totals = [];

        foreach ($transactions as $transaction) {
            $posted = strtotime((string) ($transaction['date_posted'] ?? ''));
            if ($posted === false || $posted < $since) {
                continue;
            }

            $amount = (float) ($transaction['amount'] ?? 0);
            if ($amount >= 0) {
                continue;
            }

            $category = (string) (($transaction['classification']['local_category'] ?? $transaction['category']) ?: 'uncategorized');
            if (! isset($totals[$category])) {
                $totals[$category] = 0.0;
            }

            $totals[$category] += abs($amount);
        }

        arsort($totals);
        $rows = [];
        foreach (array_slice($totals, 0, 8, true) as $category => $amount) {
            $rows[] = [
                'category' => $category,
                'actual_30d' => round($amount, 2),
            ];
        }

        return $rows;
    }

    private function budgetProjection(array $categoryTotals): array
    {
        $rows = [];

        foreach ($categoryTotals as $category) {
            $actual = (float) ($category['actual_30d'] ?? 0);
            $rows[] = [
                'category' => (string) ($category['category'] ?? 'uncategorized'),
                'actual_30d' => $actual,
                'projected_monthly' => round($actual, 2),
            ];
        }

        return $rows;
    }

    private function recurringCandidates(array $transactions): array
    {
        $groups = [];

        foreach ($transactions as $transaction) {
            $classification = (array) ($transaction['classification'] ?? []);
            $key = (string) ($classification['recurring_key'] ?? '');
            if ($key === '') {
                continue;
            }

            $groups[$key][] = $transaction;
        }

        $results = [];
        foreach ($groups as $key => $items) {
            if (count($items) < 2) {
                continue;
            }

            usort($items, static function (array $left, array $right): int {
                return strcmp((string) $left['date_posted'], (string) $right['date_posted']);
            });

            $amounts = array_map(static fn (array $item): float => abs((float) ($item['amount'] ?? 0)), $items);
            if (max($amounts) - min($amounts) > 5) {
                continue;
            }

            $intervals = [];
            for ($index = 1; $index < count($items); $index++) {
                $prev = strtotime((string) $items[$index - 1]['date_posted']);
                $next = strtotime((string) $items[$index]['date_posted']);
                if ($prev !== false && $next !== false) {
                    $intervals[] = (int) round(($next - $prev) / DAY_IN_SECONDS);
                }
            }

            $isRecurring = false;
            foreach ($intervals as $interval) {
                if (($interval >= 25 && $interval <= 35) || ($interval >= 6 && $interval <= 8) || ($interval >= 13 && $interval <= 15)) {
                    $isRecurring = true;
                    break;
                }
            }

            if (! $isRecurring) {
                continue;
            }

            $latest = end($items);
            $results[] = [
                'merchant' => (string) ($latest['merchant_name'] ?? $latest['name'] ?? $key),
                'amount' => round((float) ($latest['amount'] ?? 0), 2),
                'count' => count($items),
                'latest_date' => (string) ($latest['date_posted'] ?? ''),
            ];
        }

        usort($results, static function (array $left, array $right): int {
            return strcmp((string) $right['latest_date'], (string) $left['latest_date']);
        });

        return array_slice($results, 0, 8);
    }

    private function subscriptionCandidates(array $transactions): array
    {
        $results = [];
        $seen = [];

        foreach ($transactions as $transaction) {
            $classification = (array) ($transaction['classification'] ?? []);
            if (empty($classification['is_subscription_candidate'])) {
                continue;
            }

            $merchant = (string) ($transaction['merchant_name'] ?? $transaction['name'] ?? 'Subscription');
            if (isset($seen[$merchant])) {
                continue;
            }

            $seen[$merchant] = true;
            $results[] = [
                'merchant' => $merchant,
                'amount' => round((float) ($transaction['amount'] ?? 0), 2),
                'date_posted' => (string) ($transaction['date_posted'] ?? ''),
            ];
        }

        return array_slice($results, 0, 8);
    }

    private function parseCsvRows(string $csvText): array
    {
        $lines = preg_split('/\r\n|\n|\r/', $csvText) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), static fn (string $line): bool => $line !== ''));
        if (count($lines) < 2) {
            return [];
        }

        $headers = str_getcsv(array_shift($lines));
        if (! is_array($headers)) {
            return [];
        }

        $normalizedHeaders = array_map(static fn (string $header): string => sanitize_key(str_replace([' ', '-'], '_', strtolower($header))), $headers);
        $rows = [];

        foreach ($lines as $line) {
            $values = str_getcsv($line);
            if (! is_array($values) || $values === []) {
                continue;
            }

            $row = [];
            foreach ($normalizedHeaders as $index => $header) {
                $row[$header] = $values[$index] ?? '';
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function parseDateToTimestamp(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);
        return $timestamp === false ? null : $timestamp;
    }

    private function transactionDedupeKey(string $accountId, string $providerTransactionId): string
    {
        return 'txn_' . md5($accountId . '|' . $providerTransactionId);
    }

    private function transactionRowChanged(array $existing, array $row): bool
    {
        $keys = [
            'pending_transaction_id',
            'name',
            'merchant_name',
            'amount',
            'iso_currency_code',
            'category_json',
            'authorized_date',
            'authorized_datetime',
            'date_posted',
            'datetime_posted',
            'is_pending',
            'metadata_json',
        ];

        foreach ($keys as $key) {
            if ((string) ($existing[$key] ?? '') !== (string) ($row[$key] ?? '')) {
                return true;
            }
        }

        return false;
    }

    private function purgeConnectionData(int $connectionId, int $userId): void
    {
        global $wpdb;

        $wpdb->delete(Tables::prefixed('finance_raw_logs'), ['provider_connection_id' => $connectionId], ['%d']);
        $wpdb->delete(Tables::prefixed('finance_balance_snapshots'), ['provider_connection_id' => $connectionId], ['%d']);
        $wpdb->delete(Tables::prefixed('finance_import_batches'), ['provider_connection_id' => $connectionId], ['%d']);
        $wpdb->delete(Tables::prefixed('finance_transactions'), ['provider_connection_id' => $connectionId], ['%d']);
        $wpdb->delete(Tables::prefixed('finance_accounts'), ['provider_connection_id' => $connectionId], ['%d']);
        $wpdb->delete(Tables::prefixed('sync_state'), ['provider_connection_id' => $connectionId], ['%d']);

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM " . Tables::prefixed('timeline_items') . " WHERE wp_user_id = %d AND domain = 'finance' AND source IN (%s, %s)",
                $userId,
                self::PROVIDER,
                self::CSV_PROVIDER
            )
        );
    }

    private function bestInstitutionName(array $account, array $connectionMeta): ?string
    {
        $candidates = [
            $connectionMeta['org_name'] ?? null,
            $connectionMeta['name'] ?? null,
            $account['conn_name'] ?? null,
            $account['name'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return sanitize_text_field($candidate);
            }
        }

        return null;
    }

    private function normalizeSubtype(array $account): ?string
    {
        $extra = is_array($account['extra'] ?? null) ? $account['extra'] : [];
        $candidate = $extra['account-type'] ?? $extra['type'] ?? null;

        if (! is_string($candidate) || trim($candidate) === '') {
            return null;
        }

        return sanitize_key($candidate);
    }

    private function normalizeMask(array $account): ?string
    {
        $extra = is_array($account['extra'] ?? null) ? $account['extra'] : [];
        $candidate = $extra['mask'] ?? null;

        if (! is_string($candidate) || trim($candidate) === '') {
            return null;
        }

        return sanitize_text_field(substr($candidate, -4));
    }

    private function decimalOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    private function normalizeTransactionCurrency(string $currency): ?string
    {
        $currency = trim($currency);
        if ($currency === '') {
            return null;
        }

        return strlen($currency) <= 16 ? $currency : null;
    }

    private function sanitizeProviderErrors(array $errors): array
    {
        $sanitized = [];

        foreach ($errors as $error) {
            if (is_string($error) && trim($error) !== '') {
                $sanitized[] = sanitize_text_field($error);
                continue;
            }

            if (! is_array($error)) {
                continue;
            }

            $message = $error['msg'] ?? $error['message'] ?? '';
            if (is_string($message) && trim($message) !== '') {
                $sanitized[] = sanitize_text_field($message);
            }
        }

        return array_values(array_unique($sanitized));
    }

    private function decodeJsonField(mixed $payload): array
    {
        if (! is_string($payload) || $payload === '') {
            return [];
        }

        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function parseMysqlTimestamp(mixed $value): ?int
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $timestamp = strtotime($value . ' UTC');
        return $timestamp === false ? null : $timestamp;
    }

    private function isHttpsUrl(string $url): bool
    {
        $parts = wp_parse_url($url);
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && ! empty($parts['host']);
    }
}
