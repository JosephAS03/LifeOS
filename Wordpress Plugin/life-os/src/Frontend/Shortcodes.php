<?php

declare(strict_types=1);

namespace LifeOS\Frontend;

use DateTimeImmutable;
use LifeOS\Services\FinanceProviderInterface;
use LifeOS\Services\HealthImportService;
use LifeOS\Services\MoodService;
use LifeOS\Services\PageProvisioner;
use LifeOS\Services\TaskService;
use LifeOS\Repositories\TimelineRepository;

final class Shortcodes
{
    public function __construct(
        private readonly TaskService $taskService,
        private readonly MoodService $moodService,
        private readonly HealthImportService $healthImportService,
        private readonly FinanceProviderInterface $financeProvider,
        private readonly TimelineRepository $timelineRepository,
        private readonly PageProvisioner $pageProvisioner
    ) {
    }

    public function boot(): void
    {
        add_shortcode('life_os_dashboard', [$this, 'renderDashboard']);
        add_shortcode('life_os_tasks', [$this, 'renderTasks']);
        add_shortcode('life_os_health', [$this, 'renderHealth']);
        add_shortcode('life_os_finance', [$this, 'renderFinance']);
        add_shortcode('life_os_mood', [$this, 'renderMood']);
        add_shortcode('life_os_timeline', [$this, 'renderTimeline']);

        add_action('admin_post_life_os_front_task_create', [$this, 'handleTaskCreate']);
        add_action('admin_post_life_os_front_mood_create', [$this, 'handleMoodCreate']);
    }

    public function renderDashboard(): string
    {
        if (! is_user_logged_in()) {
            return '<p>LIFE OS pages are private.</p>';
        }

        $userId = get_current_user_id();
        $tasks = $this->taskService->listTasks($userId, 'pending', 5);
        $moods = $this->moodService->listEntries($userId, 3);
        $summary = $this->healthImportService->summaryForDate($userId, gmdate('Y-m-d'));
        $finance = $this->financeProvider->dashboardData($userId);
        $transactions = array_slice((array) ($finance['transactions'] ?? []), 0, 5);
        $timelineItems = $this->timelineRepository->recent($userId, 8);

        $html = $this->styles();
        $html .= $this->featureNav('dashboard');
        $html .= '<section class="life-os-panel"><h2>LIFE OS Dashboard</h2><p>Your private command center for tasks, health, finance, mood, and recent timeline context.</p></section>';
        $html .= '<section class="life-os-grid life-os-grid-4">';
        $html .= $this->statCard('Pending Tasks', (string) count($tasks), 'Open work due soon');
        $html .= $this->statCard('Steps Today', (string) ((int) $summary['steps']), 'Imported health summary');
        $html .= $this->statCard('Mood Entries', (string) count($moods), 'Recent check-ins');
        $html .= $this->statCard('Recent Transactions', (string) count($transactions), 'Last 30 days');
        $html .= '</section>';
        $html .= '<section class="life-os-grid life-os-grid-2">';
        $html .= $this->cardList('Tasks Due', array_map(fn (array $task): string => $this->formatTaskLine($task), $tasks), 'Use the Tasks page to add or complete work.');
        $html .= $this->cardList('Recent Timeline', array_map(fn (array $item): string => $this->formatTimelineLine($item), $timelineItems), 'Moment lookup is available from the Timeline page.');
        $html .= '</section>';
        $html .= '<section class="life-os-grid life-os-grid-2">';
        $html .= $this->cardList('Recent Mood', array_map(fn (array $mood): string => $this->formatMoodLine($mood), $moods), 'Track emotional context next to tasks and health.');
        $html .= $this->cardList('Recent Finance', array_map(fn (array $transaction): string => $this->formatFinanceLine($transaction), $transactions), 'SimpleFIN-backed finance history appears here once synced and archived locally.');
        $html .= '</section>';

        return $html;
    }

    public function renderTasks(): string
    {
        if (! is_user_logged_in()) {
            return '<p>LIFE OS tasks are private.</p>';
        }

        $tasks = $this->taskService->listTasks(get_current_user_id(), null, 25);
        $html = $this->styles();
        $html .= $this->featureNav('tasks');
        $html .= $this->renderNotices();
        $html .= '<section class="life-os-panel"><h2>Tasks</h2><p>Add tasks from the site and keep your Discord and dashboard views aligned.</p></section>';
        $html .= '<section class="life-os-panel"><h3>Quick Add Task</h3>';
        $html .= '<form class="life-os-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        $html .= wp_nonce_field('life_os_front_task_create', '_wpnonce', true, false);
        $html .= '<input type="hidden" name="action" value="life_os_front_task_create" />';
        $html .= '<label>Title<input type="text" name="title" required /></label>';
        $html .= '<label>Due At<input type="datetime-local" name="due_at" /></label>';
        $html .= '<label>Decay After Hours<input type="number" min="1" name="decay_after_hours" /></label>';
        $html .= '<button type="submit">Add Task</button>';
        $html .= '</form></section>';
        $html .= $this->tableWrap(['Title', 'Due', 'Status'], array_map(fn (array $task): array => [
            esc_html((string) $task['title']),
            ! empty($task['due_at']) ? esc_html(get_date_from_gmt((string) $task['due_at'], 'Y-m-d g:i A')) : 'No due date',
            esc_html((string) $task['status']),
        ], $tasks), 'No tasks yet.');

        return $html;
    }

    public function renderHealth(): string
    {
        if (! is_user_logged_in()) {
            return '<p>LIFE OS health data is private.</p>';
        }

        $summary = $this->healthImportService->summaryForDate(get_current_user_id(), gmdate('Y-m-d'));
        $html = $this->styles();
        $html .= $this->featureNav('health');
        $html .= '<section class="life-os-panel"><h2>Health</h2><p>Imported health metrics appear here once your bridge or XML import is connected.</p></section>';
        $html .= '<section class="life-os-grid life-os-grid-4">';
        $html .= $this->statCard('Steps Today', (string) ((int) $summary['steps']), 'Current daily total');
        $html .= $this->statCard('Sleep Hours', (string) $summary['sleep_hours'], 'Today\'s imported sleep');
        $html .= $this->statCard('Average Heart Rate', $summary['average_heart_rate'] !== null ? (string) $summary['average_heart_rate'] : 'n/a', 'Average imported pulse');
        $html .= $this->statCard('Latest Weight', $summary['latest_weight'] !== null ? (string) $summary['latest_weight'] : 'n/a', 'Most recent imported weight');
        $html .= '</section>';

        return $html;
    }

    public function renderFinance(): string
    {
        if (! is_user_logged_in()) {
            return '<p>LIFE OS finance data is private.</p>';
        }

        $html = $this->styles();
        $html .= $this->featureNav('finance');
        $finance = $this->financeProvider->dashboardData(get_current_user_id());
        $status = (array) ($finance['status'] ?? []);
        $transactions = (array) ($finance['transactions'] ?? []);
        $accounts = (array) ($finance['accounts'] ?? []);
        $categories = (array) ($finance['category_totals'] ?? []);
        $budgetProjection = (array) ($finance['budget_projection'] ?? []);
        $recurring = (array) ($finance['recurring'] ?? []);
        $subscriptions = (array) ($finance['subscriptions'] ?? []);
        $timeline = (array) ($finance['timeline'] ?? []);

        if (! ($status['connected'] ?? false) && $transactions === []) {
            $message = current_user_can('manage_life_os')
                ? 'No finance connection is active yet. Open the Finance Connections admin screen to connect SimpleFIN and start archiving daily account history.'
                : 'No finance connection is active yet.';

            return $html . '<section class="life-os-panel"><h2>Finance</h2><p>' . esc_html($message) . '</p></section>';
        }

        $html .= '<section class="life-os-panel"><h2>Finance</h2><p>SimpleFIN acts as a daily read-only feed. LIFE OS keeps the long-term ledger, balance history, recurring-spend hints, and timeline context locally even after transactions age out of the provider window.</p></section>';
        $html .= '<section class="life-os-grid life-os-grid-4">';
        $html .= $this->statCard('Accounts', (string) count($accounts), 'Archived normalized finance accounts');
        $html .= $this->statCard('Stored Txns', (string) (int) (($status['counts']['transactions'] ?? 0)), 'Locally retained transactions');
        $html .= $this->statCard('Last Sync', ! empty($status['last_success_at']) ? (string) $status['last_success_at'] : 'n/a', 'Most recent successful import');
        $html .= $this->statCard('Raw Logs', (string) (int) (($status['counts']['raw_logs'] ?? 0)), 'Append-only provider payload archive');
        $html .= '</section>';

        $html .= $this->tableWrap(['Institution', 'Account', 'Balance', 'Available', 'As Of'], array_map(fn (array $account): array => [
            esc_html((string) ($account['institution_name'] ?? '')),
            esc_html((string) ($account['account_name'] ?? '')),
            esc_html((string) ($account['current_balance'] ?? '')),
            esc_html((string) ($account['available_balance'] ?? '')),
            esc_html((string) ($account['balance_date'] ?? '')),
        ], array_slice($accounts, 0, 10)), 'No connected finance accounts yet.');

        $html .= $this->tableWrap(['Date', 'Name', 'Category', 'Amount'], array_map(fn (array $transaction): array => [
            esc_html((string) $transaction['date_posted']),
            esc_html((string) $transaction['name']),
            esc_html(ucfirst(str_replace('_', ' ', (string) ($transaction['category'] ?? 'uncategorized')))),
            '$' . esc_html(number_format((float) $transaction['amount'], 2)),
        ], $transactions), 'No transactions yet.');

        $html .= '<section class="life-os-grid life-os-grid-2">';
        $html .= $this->cardList('Category Spend (30d)', array_map(static fn (array $row): string => '<strong>' . esc_html(ucfirst(str_replace('_', ' ', (string) $row['category']))) . '</strong><br><small>$' . esc_html(number_format((float) $row['actual_30d'], 2)) . '</small>', $categories), 'Categories will appear after transactions are archived.');
        $html .= $this->cardList('Receipt Matching', [
            'Receipt uploads stay local to LIFE OS and are meant to attach to archived transactions rather than provider windows.',
            'Transaction precision remains date-based so receipts and manual notes can still match after provider lookback expires.',
        ], 'Receipt matching is not configured yet.');
        $html .= '</section>';

        $html .= $this->tableWrap(['Merchant', 'Amount', 'Occurrences', 'Latest'], array_map(fn (array $row): array => [
            esc_html((string) $row['merchant']),
            '$' . esc_html(number_format((float) $row['amount'], 2)),
            esc_html((string) $row['count']),
            esc_html((string) $row['latest_date']),
        ], $recurring), 'No recurring-spend patterns detected yet.');

        $html .= $this->tableWrap(['Subscription', 'Amount', 'Latest Date'], array_map(fn (array $row): array => [
            esc_html((string) $row['merchant']),
            '$' . esc_html(number_format((float) $row['amount'], 2)),
            esc_html((string) $row['date_posted']),
        ], $subscriptions), 'No subscription candidates detected yet.');

        $html .= $this->tableWrap(['Category', 'Actual 30d', 'Projected Monthly'], array_map(fn (array $row): array => [
            esc_html(ucfirst(str_replace('_', ' ', (string) $row['category']))),
            '$' . esc_html(number_format((float) $row['actual_30d'], 2)),
            '$' . esc_html(number_format((float) $row['projected_monthly'], 2)),
        ], $budgetProjection), 'Budget projections will appear after enough transactions are archived.');

        $html .= $this->tableWrap(['When', 'Title', 'Summary'], array_map(fn (array $item): array => [
            esc_html($this->timelineTime((string) ($item['start_at'] ?: $item['occurred_at'] ?: $item['created_at']))),
            esc_html((string) $item['title']),
            esc_html((string) ($item['summary'] ?? '')),
        ], $timeline), 'No finance timeline entries yet.');

        return $html;
    }

    public function renderMood(): string
    {
        if (! is_user_logged_in()) {
            return '<p>LIFE OS mood data is private.</p>';
        }

        $entries = $this->moodService->listEntries(get_current_user_id(), 20);
        $html = $this->styles();
        $html .= $this->featureNav('mood');
        $html .= $this->renderNotices();
        $html .= '<section class="life-os-panel"><h2>Mood</h2><p>Log mood context manually from the site and preserve it in the timeline.</p></section>';
        $html .= '<section class="life-os-panel"><h3>Quick Log Mood</h3>';
        $html .= '<form class="life-os-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        $html .= wp_nonce_field('life_os_front_mood_create', '_wpnonce', true, false);
        $html .= '<input type="hidden" name="action" value="life_os_front_mood_create" />';
        $html .= '<label>Category<select name="category"><option value="great">Great</option><option value="good">Good</option><option value="neutral" selected>Neutral</option><option value="low">Low</option><option value="overwhelmed">Overwhelmed</option></select></label>';
        $html .= '<label>Happened At<input type="datetime-local" name="happened_at" /></label>';
        $html .= '<label>Note<textarea name="note" rows="3"></textarea></label>';
        $html .= '<button type="submit">Save Mood Entry</button>';
        $html .= '</form></section>';
        $html .= $this->tableWrap(['When', 'Category', 'Note'], array_map(fn (array $entry): array => [
            esc_html(get_date_from_gmt((string) $entry['happened_at'], 'Y-m-d g:i A')),
            esc_html(ucfirst((string) $entry['category'])),
            esc_html((string) ($entry['note'] ?? '')),
        ], $entries), 'No mood entries yet.');

        return $html;
    }

    public function renderTimeline(): string
    {
        if (! is_user_logged_in()) {
            return '<p>LIFE OS timeline is private.</p>';
        }

        $userId = get_current_user_id();
        $atInput = isset($_GET['life_os_at']) ? sanitize_text_field(wp_unslash((string) $_GET['life_os_at'])) : '';
        $radius = isset($_GET['life_os_radius']) ? max(15, (int) $_GET['life_os_radius']) : 60;
        $items = [];
        $mode = 'recent';

        if ($atInput !== '') {
            $atIso = $this->normalizeLocalInputToIso($atInput);
            if ($atIso !== null) {
                $items = $this->timelineRepository->moment($userId, gmdate('Y-m-d H:i:s', strtotime($atIso)), $radius * 60);
                $mode = 'moment';
            }
        }

        if ($items === []) {
            $items = $this->timelineRepository->recent($userId, 20);
        }

        $html = $this->styles();
        $html .= $this->featureNav('timeline');
        $html .= '<section class="life-os-panel"><h2>Timeline</h2><p>Ask what was true around a moment in time and review the latest cross-module activity.</p></section>';
        $html .= '<section class="life-os-panel"><h3>Moment Lookup</h3><form class="life-os-form life-os-form-inline" method="get">';
        $html .= '<label>At<input type="datetime-local" name="life_os_at" value="' . esc_attr($atInput) . '" /></label>';
        $html .= '<label>Radius Minutes<input type="number" min="15" step="15" name="life_os_radius" value="' . esc_attr((string) $radius) . '" /></label>';
        $html .= '<button type="submit">Lookup</button>';
        $html .= '</form></section>';
        $html .= '<section class="life-os-panel"><h3>' . esc_html($mode === 'moment' ? 'Moment Results' : 'Recent Timeline') . '</h3></section>';
        $html .= $this->tableWrap(['When', 'Domain', 'Title', 'Summary'], array_map(fn (array $item): array => [
            esc_html($this->timelineTime((string) ($item['occurred_at'] ?: $item['start_at'] ?: $item['created_at']))),
            esc_html((string) $item['domain']),
            esc_html((string) $item['title']),
            esc_html((string) ($item['summary'] ?? '')),
        ], $items), 'No timeline items yet.');

        return $html;
    }

    public function handleTaskCreate(): void
    {
        if (! is_user_logged_in()) {
            wp_die('You must be logged in to create a task.', 403);
        }

        check_admin_referer('life_os_front_task_create');

        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash((string) $_POST['title'])) : '';
        if ($title === '') {
            $this->redirectBack('task_error', 'Task title is required.');
        }

        $dueAt = isset($_POST['due_at']) ? sanitize_text_field(wp_unslash((string) $_POST['due_at'])) : '';
        $decayHours = isset($_POST['decay_after_hours']) ? (int) $_POST['decay_after_hours'] : null;

        $this->taskService->createTask(get_current_user_id(), [
            'title' => $title,
            'due_at' => $this->normalizeLocalInputToIso($dueAt),
            'decay_after_hours' => $decayHours,
        ], 'site_form');

        $this->redirectBack('task_created');
    }

    public function handleMoodCreate(): void
    {
        if (! is_user_logged_in()) {
            wp_die('You must be logged in to create a mood entry.', 403);
        }

        check_admin_referer('life_os_front_mood_create');

        $category = isset($_POST['category']) ? sanitize_key(wp_unslash((string) $_POST['category'])) : 'neutral';
        $note = isset($_POST['note']) ? sanitize_textarea_field(wp_unslash((string) $_POST['note'])) : '';
        $happenedAt = isset($_POST['happened_at']) ? sanitize_text_field(wp_unslash((string) $_POST['happened_at'])) : '';

        $this->moodService->createEntry(get_current_user_id(), [
            'category' => $category,
            'note' => $note,
            'happened_at' => $this->normalizeLocalInputToIso($happenedAt) ?: gmdate('c'),
        ], 'site_form');

        $this->redirectBack('mood_created');
    }

    private function featureNav(string $active): string
    {
        $links = [];
        foreach ($this->pageProvisioner->pages() as $key => $page) {
            if (! $page['exists'] || $page['url'] === '') {
                continue;
            }

            $class = $key === $active ? 'life-os-nav-link is-active' : 'life-os-nav-link';
            $links[] = '<a class="' . esc_attr($class) . '" href="' . esc_url((string) $page['url']) . '">' . esc_html((string) $page['title']) . '</a>';
        }

        return '<nav class="life-os-nav">' . implode('', $links) . '</nav>';
    }

    private function renderNotices(): string
    {
        $notice = isset($_GET['life_os_notice']) ? sanitize_text_field(wp_unslash((string) $_GET['life_os_notice'])) : '';
        if ($notice === '') {
            return '';
        }

        $message = match ($notice) {
            'task_created' => 'Task created successfully.',
            'mood_created' => 'Mood entry saved successfully.',
            'task_error' => isset($_GET['life_os_message']) ? sanitize_text_field(wp_unslash((string) $_GET['life_os_message'])) : 'Something went wrong.',
            default => '',
        };

        if ($message === '') {
            return '';
        }

        $class = $notice === 'task_error' ? 'life-os-alert is-error' : 'life-os-alert is-success';

        return '<div class="' . esc_attr($class) . '">' . esc_html($message) . '</div>';
    }

    private function styles(): string
    {
        static $printed = false;
        if ($printed) {
            return '';
        }

        $printed = true;

        return '<style>
            .life-os-nav{display:flex;flex-wrap:wrap;gap:10px;margin:0 0 24px}
            .life-os-nav-link{padding:10px 14px;border:1px solid #d1d9e0;border-radius:999px;text-decoration:none;background:#fff;color:#16324f;font-weight:600}
            .life-os-nav-link.is-active{background:#16324f;color:#fff;border-color:#16324f}
            .life-os-panel{background:#fff;border:1px solid #d6dde5;border-radius:18px;padding:20px;margin:0 0 20px;box-shadow:0 12px 24px rgba(15,23,42,.05)}
            .life-os-grid{display:grid;gap:18px;margin:0 0 20px}
            .life-os-grid-2{grid-template-columns:repeat(auto-fit,minmax(280px,1fr))}
            .life-os-grid-4{grid-template-columns:repeat(auto-fit,minmax(180px,1fr))}
            .life-os-stat{background:linear-gradient(180deg,#fdfefe,#f3f8fb);border:1px solid #d6dde5;border-radius:18px;padding:18px}
            .life-os-stat strong{display:block;font-size:1.9rem;line-height:1.1;color:#16324f}
            .life-os-stat span{display:block;font-size:.9rem;color:#516170;margin-top:8px}
            .life-os-card-list{list-style:none;padding:0;margin:0}
            .life-os-card-list li{padding:10px 0;border-bottom:1px solid #e8edf2}
            .life-os-card-list li:last-child{border-bottom:none}
            .life-os-form{display:grid;gap:12px}
            .life-os-form-inline{grid-template-columns:repeat(auto-fit,minmax(180px,1fr));align-items:end}
            .life-os-form label{display:grid;gap:6px;font-weight:600;color:#183b56}
            .life-os-form input,.life-os-form select,.life-os-form textarea{padding:10px 12px;border:1px solid #c7d2dc;border-radius:12px;background:#fcfdfe}
            .life-os-form button{padding:11px 16px;border:none;border-radius:12px;background:#1d5b79;color:#fff;font-weight:700;cursor:pointer}
            .life-os-table{width:100%;border-collapse:collapse}
            .life-os-table th,.life-os-table td{padding:12px;border-bottom:1px solid #e8edf2;text-align:left;vertical-align:top}
            .life-os-alert{padding:12px 14px;border-radius:14px;margin:0 0 18px;font-weight:600}
            .life-os-alert.is-success{background:#ecfdf3;color:#146c43;border:1px solid #b7ebc6}
            .life-os-alert.is-error{background:#fff1f2;color:#a61b29;border:1px solid #fecdd3}
        </style>';
    }

    private function statCard(string $label, string $value, string $caption): string
    {
        return '<article class="life-os-stat"><div>' . esc_html($label) . '</div><strong>' . esc_html($value) . '</strong><span>' . esc_html($caption) . '</span></article>';
    }

    private function cardList(string $title, array $items, string $empty): string
    {
        $html = '<section class="life-os-panel"><h3>' . esc_html($title) . '</h3>';
        if ($items === []) {
            $html .= '<p>' . esc_html($empty) . '</p></section>';
            return $html;
        }

        $html .= '<ul class="life-os-card-list">';
        foreach ($items as $item) {
            $html .= '<li>' . $item . '</li>';
        }
        $html .= '</ul></section>';

        return $html;
    }

    private function tableWrap(array $headers, array $rows, string $empty): string
    {
        $html = '<section class="life-os-panel">';
        if ($rows === []) {
            $html .= '<p>' . esc_html($empty) . '</p></section>';
            return $html;
        }

        $html .= '<table class="life-os-table"><thead><tr>';
        foreach ($headers as $header) {
            $html .= '<th>' . esc_html($header) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $column) {
                $html .= '<td>' . $column . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></section>';

        return $html;
    }

    private function formatTaskLine(array $task): string
    {
        $suffix = ! empty($task['due_at']) ? ' due ' . get_date_from_gmt((string) $task['due_at'], 'Y-m-d g:i A') : ' no due date';

        return '<strong>' . esc_html((string) $task['title']) . '</strong><br><small>' . esc_html((string) $task['status'] . $suffix) . '</small>';
    }

    private function formatMoodLine(array $entry): string
    {
        $note = ! empty($entry['note']) ? '<br><small>' . esc_html((string) $entry['note']) . '</small>' : '';

        return '<strong>' . esc_html(ucfirst((string) $entry['category'])) . '</strong><br><small>' . esc_html(get_date_from_gmt((string) $entry['happened_at'], 'Y-m-d g:i A')) . '</small>' . $note;
    }

    private function formatFinanceLine(array $transaction): string
    {
        return '<strong>' . esc_html((string) $transaction['name']) . '</strong><br><small>' . esc_html((string) $transaction['date_posted']) . ' $' . esc_html(number_format((float) $transaction['amount'], 2)) . '</small>';
    }

    private function formatTimelineLine(array $item): string
    {
        $time = (string) ($item['occurred_at'] ?: $item['start_at'] ?: $item['created_at']);

        return '<strong>' . esc_html((string) $item['title']) . '</strong><br><small>' . esc_html((string) $item['domain']) . ' at ' . esc_html($this->timelineTime($time)) . '</small>';
    }

    private function timelineTime(string $gmtTime): string
    {
        return get_date_from_gmt($gmtTime, 'Y-m-d g:i A');
    }

    private function normalizeLocalInputToIso(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value, wp_timezone());
        if (! $date instanceof DateTimeImmutable) {
            $unixTime = strtotime($value);
            return $unixTime === false ? null : gmdate('c', $unixTime);
        }

        return $date->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM);
    }

    private function redirectBack(string $notice, string $message = ''): never
    {
        $redirect = wp_get_referer() ?: home_url('/');
        $args = ['life_os_notice' => $notice];

        if ($message !== '') {
            $args['life_os_message'] = $message;
        }

        wp_safe_redirect(add_query_arg($args, $redirect));
        exit;
    }
}
