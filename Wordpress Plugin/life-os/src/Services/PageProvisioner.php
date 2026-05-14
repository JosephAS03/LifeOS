<?php

declare(strict_types=1);

namespace LifeOS\Services;

use LifeOS\Support\FeatureRegistry;
use LifeOS\Support\Options;

final class PageProvisioner
{
    public const OPTION_NAME = 'life_os_feature_pages';
    public const REGISTRY_HASH_OPTION = 'life_os_feature_registry_hash';
    private const META_KEY = '_life_os_feature_key';
    private const MANAGED_META_KEY = '_life_os_managed_page';

    public function __construct(
        private readonly Options $options,
        private readonly FeatureRegistry $featureRegistry,
        private readonly AuditLogService $auditLogService
    ) {
    }

    public function boot(): void
    {
        add_action('admin_init', [$this, 'maybeSync']);
        add_action('admin_post_life_os_sync_feature_pages', [$this, 'handleManualSync']);
    }

    public function maybeSync(): void
    {
        if (! current_user_can('manage_life_os')) {
            return;
        }

        $settings = $this->options->all();
        if ($settings['auto_create_feature_pages'] !== '1' && $settings['auto_retire_feature_pages'] !== '1') {
            return;
        }

        $storedHash = (string) get_option(self::REGISTRY_HASH_OPTION, '');
        $currentHash = $this->featureRegistry->hash();

        if ($storedHash === $currentHash && $this->allCurrentPagesExist()) {
            return;
        }

        $this->sync();
    }

    public function sync(bool $force = false): array
    {
        $settings = $this->options->all();
        $createEnabled = $force || $settings['auto_create_feature_pages'] === '1';
        $retireEnabled = $settings['auto_retire_feature_pages'] === '1';
        $tracked = get_option(self::OPTION_NAME, []);
        $tracked = is_array($tracked) ? $tracked : [];
        $currentFeatures = $this->featureRegistry->all();
        $created = 0;
        $updated = 0;
        $retired = 0;
        $resolved = [];

        foreach ($currentFeatures as $key => $feature) {
            $pageId = isset($tracked[$key]['id']) ? (int) $tracked[$key]['id'] : 0;
            $page = $pageId > 0 ? get_post($pageId) : null;

            if (! $page instanceof \WP_Post) {
                $page = $this->findExistingPage($feature['slug'], $key);
            }

            if (! $page instanceof \WP_Post && ! $createEnabled) {
                continue;
            }

            if (! $page instanceof \WP_Post) {
                $pageId = wp_insert_post([
                    'post_type' => 'page',
                    'post_status' => 'private',
                    'post_title' => $feature['title'],
                    'post_name' => $feature['slug'],
                    'post_content' => $this->pageContent($feature),
                ]);

                if (! is_wp_error($pageId) && $pageId > 0) {
                    update_post_meta($pageId, self::META_KEY, $key);
                    update_post_meta($pageId, self::MANAGED_META_KEY, '1');
                    $resolved[$key] = [
                        'id' => $pageId,
                        'slug' => $feature['slug'],
                    ];
                    $created++;
                }

                continue;
            }

            if ($page->post_status === 'trash' && $createEnabled) {
                wp_update_post([
                    'ID' => $page->ID,
                    'post_status' => 'private',
                ]);
                $updated++;
            }

            update_post_meta($page->ID, self::META_KEY, $key);
            update_post_meta($page->ID, self::MANAGED_META_KEY, '1');

            $resolved[$key] = [
                'id' => (int) $page->ID,
                'slug' => $feature['slug'],
            ];
        }

        if ($retireEnabled) {
            foreach ($tracked as $key => $pageData) {
                if (isset($currentFeatures[$key])) {
                    continue;
                }

                $pageId = isset($pageData['id']) ? (int) $pageData['id'] : 0;
                if ($pageId <= 0) {
                    continue;
                }

                if ((string) get_post_meta($pageId, self::MANAGED_META_KEY, true) !== '1') {
                    continue;
                }

                if (get_post_status($pageId) && get_post_status($pageId) !== 'trash') {
                    wp_trash_post($pageId);
                    $retired++;
                }
            }
        } else {
            $resolved = array_merge($tracked, $resolved);
        }

        update_option(self::OPTION_NAME, $resolved);
        update_option(self::REGISTRY_HASH_OPTION, $this->featureRegistry->hash());

        $summary = [
            'created' => $created,
            'updated' => $updated,
            'retired' => $retired,
            'tracked_pages' => count($resolved),
        ];

        $this->auditLogService->record('feature_pages_synced', 'page', null, $summary);

        return $summary;
    }

    public function pages(): array
    {
        $tracked = get_option(self::OPTION_NAME, []);
        $tracked = is_array($tracked) ? $tracked : [];
        $pages = [];

        foreach ($this->featureRegistry->all() as $key => $feature) {
            $pageId = isset($tracked[$key]['id']) ? (int) $tracked[$key]['id'] : 0;
            $post = $pageId > 0 ? get_post($pageId) : $this->findExistingPage($feature['slug'], $key);
            $pages[$key] = array_merge($feature, [
                'id' => $post instanceof \WP_Post ? (int) $post->ID : 0,
                'exists' => $post instanceof \WP_Post,
                'status' => $post instanceof \WP_Post ? (string) $post->post_status : 'missing',
                'url' => $post instanceof \WP_Post ? get_permalink($post) : '',
                'edit_url' => $post instanceof \WP_Post ? get_edit_post_link($post->ID, '') : '',
            ]);
        }

        return $pages;
    }

    public function handleManualSync(): void
    {
        if (! current_user_can('manage_life_os')) {
            wp_die('You do not have permission to sync LIFE OS feature pages.', 403);
        }

        check_admin_referer('life_os_sync_feature_pages');
        $summary = $this->sync(true);

        $url = add_query_arg([
            'page' => 'life-os',
            'life_os_notice' => 'feature_pages_synced',
            'created' => $summary['created'],
            'updated' => $summary['updated'],
            'retired' => $summary['retired'],
        ], admin_url('admin.php'));

        wp_safe_redirect($url);
        exit;
    }

    private function allCurrentPagesExist(): bool
    {
        foreach ($this->pages() as $page) {
            if (! $page['exists']) {
                return false;
            }
        }

        return true;
    }

    private function findExistingPage(string $slug, string $featureKey): ?\WP_Post
    {
        $page = get_page_by_path($slug, OBJECT, 'page');
        if ($page instanceof \WP_Post) {
            return $page;
        }

        $posts = get_posts([
            'post_type' => 'page',
            'post_status' => ['publish', 'private', 'draft', 'trash'],
            'meta_key' => self::META_KEY,
            'meta_value' => $featureKey,
            'numberposts' => 1,
        ]);

        return $posts !== [] && $posts[0] instanceof \WP_Post ? $posts[0] : null;
    }

    private function pageContent(array $feature): string
    {
        return sprintf(
            "This page is managed by the LIFE OS feature registry.\n\n%s\n\n%s",
            $feature['description'],
            $feature['shortcode']
        );
    }
}
