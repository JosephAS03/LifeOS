<?php

declare(strict_types=1);

namespace LifeOS\Support;

final class FeatureRegistry
{
    public function all(): array
    {
        return [
            'dashboard' => [
                'key' => 'dashboard',
                'title' => 'LIFE OS Dashboard',
                'slug' => 'life-os-dashboard',
                'shortcode' => '[life_os_dashboard]',
                'description' => 'Central landing page for recent context, quick stats, and module navigation.',
            ],
            'tasks' => [
                'key' => 'tasks',
                'title' => 'LIFE OS Tasks',
                'slug' => 'life-os-tasks',
                'shortcode' => '[life_os_tasks]',
                'description' => 'Pending tasks, quick add, and task completion overview.',
            ],
            'health' => [
                'key' => 'health',
                'title' => 'LIFE OS Health',
                'slug' => 'life-os-health',
                'shortcode' => '[life_os_health]',
                'description' => 'Today\'s health summary and latest imported metrics.',
            ],
            'finance' => [
                'key' => 'finance',
                'title' => 'LIFE OS Finance',
                'slug' => 'life-os-finance',
                'shortcode' => '[life_os_finance]',
                'description' => 'Archived SimpleFIN accounts, local ledger history, and finance insights.',
            ],
            'mood' => [
                'key' => 'mood',
                'title' => 'LIFE OS Mood',
                'slug' => 'life-os-mood',
                'shortcode' => '[life_os_mood]',
                'description' => 'Log moods manually from the site and review recent entries.',
            ],
            'timeline' => [
                'key' => 'timeline',
                'title' => 'LIFE OS Timeline',
                'slug' => 'life-os-timeline',
                'shortcode' => '[life_os_timeline]',
                'description' => 'Look up moment context and review recent timeline activity.',
            ],
        ];
    }

    public function get(string $key): ?array
    {
        $all = $this->all();

        return $all[$key] ?? null;
    }

    public function hash(): string
    {
        return md5(wp_json_encode($this->all()));
    }
}
