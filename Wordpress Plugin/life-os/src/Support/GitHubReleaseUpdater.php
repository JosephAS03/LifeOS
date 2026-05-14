<?php

declare(strict_types=1);

namespace LifeOS\Support;

final class GitHubReleaseUpdater
{
    private const CACHE_KEY = 'life_os_github_latest_release';
    private const CACHE_TTL = 30 * MINUTE_IN_SECONDS;

    public function boot(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'injectUpdate']);
        add_filter('plugins_api', [$this, 'pluginInformation'], 20, 3);
        add_filter('plugin_row_meta', [$this, 'pluginRowMeta'], 10, 4);
        add_action('upgrader_process_complete', [$this, 'clearCacheAfterUpgrade'], 10, 2);
    }

    public function injectUpdate(mixed $transient): mixed
    {
        if (! is_object($transient) || ! isset($transient->checked) || ! is_array($transient->checked)) {
            return $transient;
        }

        $pluginFile = $this->pluginFile();
        $release = $this->latestRelease();

        if ($release === null) {
            return $transient;
        }

        if (version_compare($release['version'], LIFE_OS_VERSION, '>')) {
            $transient->response[$pluginFile] = (object) [
                'id' => LIFE_OS_GITHUB_REPO_URL,
                'slug' => $this->pluginSlug(),
                'plugin' => $pluginFile,
                'new_version' => $release['version'],
                'url' => $release['html_url'],
                'package' => $release['package'],
                'icons' => [],
                'banners' => [],
                'banners_rtl' => [],
                'tested' => '',
                'requires' => '',
                'requires_php' => '8.1',
                'compatibility' => new \stdClass(),
            ];

            unset($transient->no_update[$pluginFile]);

            return $transient;
        }

        $transient->no_update[$pluginFile] = (object) [
            'id' => LIFE_OS_GITHUB_REPO_URL,
            'slug' => $this->pluginSlug(),
            'plugin' => $pluginFile,
            'new_version' => LIFE_OS_VERSION,
            'url' => LIFE_OS_GITHUB_REPO_URL,
            'package' => '',
            'icons' => [],
            'banners' => [],
            'banners_rtl' => [],
            'tested' => '',
            'requires' => '',
            'requires_php' => '8.1',
            'compatibility' => new \stdClass(),
        ];

        unset($transient->response[$pluginFile]);

        return $transient;
    }

    public function pluginInformation(mixed $result, string $action, object $args): mixed
    {
        if ($action !== 'plugin_information' || ! isset($args->slug) || (string) $args->slug !== $this->pluginSlug()) {
            return $result;
        }

        $release = $this->latestRelease();
        if ($release === null) {
            return $result;
        }

        return (object) [
            'name' => 'LIFE OS',
            'slug' => $this->pluginSlug(),
            'version' => $release['version'],
            'author' => '<a href="' . esc_url(LIFE_OS_GITHUB_REPO_URL) . '">OpenAI Codex</a>',
            'author_profile' => esc_url(LIFE_OS_GITHUB_REPO_URL),
            'homepage' => esc_url(LIFE_OS_GITHUB_REPO_URL),
            'download_link' => $release['package'],
            'trunk' => $release['package'],
            'last_updated' => $release['published_at'],
            'external' => true,
            'sections' => [
                'description' => wp_kses_post(
                    '<p>LIFE OS is a private, timeline-first personal dashboard built around a WordPress plugin plus a companion Discord bot.</p>'
                    . '<p>Source and releases are published at <a href="' . esc_url(LIFE_OS_GITHUB_REPO_URL) . '">' . esc_html(LIFE_OS_GITHUB_REPO_URL) . '</a>.</p>'
                ),
                'installation' => wp_kses_post(
                    '<p>Updates for this plugin are served from GitHub Releases.</p>'
                    . '<p>Each release should include a plugin ZIP asset built from <code>Wordpress Plugin/life-os</code> with <code>life-os/</code> as the root folder.</p>'
                ),
                'changelog' => $this->renderChangelog($release),
            ],
        ];
    }

    public function pluginRowMeta(array $links, string $pluginFile, array $pluginData, string $status): array
    {
        if ($pluginFile !== $this->pluginFile()) {
            return $links;
        }

        $links[] = '<a href="' . esc_url(LIFE_OS_GITHUB_REPO_URL) . '" target="_blank" rel="noreferrer noopener">GitHub</a>';
        $links[] = '<a href="' . esc_url(LIFE_OS_GITHUB_RELEASES_URL) . '" target="_blank" rel="noreferrer noopener">Releases</a>';

        return $links;
    }

    public function clearCacheAfterUpgrade(\WP_Upgrader $upgrader, array $hookExtra): void
    {
        if (($hookExtra['type'] ?? '') !== 'plugin' || empty($hookExtra['plugins']) || ! is_array($hookExtra['plugins'])) {
            return;
        }

        if (! in_array($this->pluginFile(), $hookExtra['plugins'], true)) {
            return;
        }

        delete_site_transient(self::CACHE_KEY);
    }

    private function latestRelease(): ?array
    {
        $cached = get_site_transient(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $response = wp_remote_get(LIFE_OS_GITHUB_LATEST_RELEASE_API, [
            'timeout' => 15,
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'LIFE-OS-Plugin-Updater',
                'X-GitHub-Api-Version' => '2022-11-28',
            ],
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $payload = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($status >= 400 || ! is_array($payload)) {
            return null;
        }

        $version = $this->normalizeVersion((string) ($payload['tag_name'] ?? ''));
        $package = $this->selectPackage($payload);

        if ($version === '' || $package === '') {
            return null;
        }

        $release = [
            'version' => $version,
            'package' => $package,
            'html_url' => (string) ($payload['html_url'] ?? LIFE_OS_GITHUB_RELEASES_URL),
            'published_at' => (string) ($payload['published_at'] ?? ''),
            'body' => (string) ($payload['body'] ?? ''),
            'name' => (string) ($payload['name'] ?? ''),
        ];

        set_site_transient(self::CACHE_KEY, $release, self::CACHE_TTL);

        return $release;
    }

    private function selectPackage(array $payload): string
    {
        $assets = $payload['assets'] ?? [];
        if (! is_array($assets)) {
            return '';
        }

        $preferredNames = ['life-os.zip', 'wordpress-plugin-life-os.zip'];

        foreach ($preferredNames as $preferredName) {
            foreach ($assets as $asset) {
                if (! is_array($asset)) {
                    continue;
                }

                if (strtolower((string) ($asset['name'] ?? '')) !== $preferredName) {
                    continue;
                }

                return (string) ($asset['browser_download_url'] ?? '');
            }
        }

        foreach ($assets as $asset) {
            if (! is_array($asset)) {
                continue;
            }

            $name = strtolower((string) ($asset['name'] ?? ''));
            if (! str_ends_with($name, '.zip')) {
                continue;
            }

            return (string) ($asset['browser_download_url'] ?? '');
        }

        return '';
    }

    private function normalizeVersion(string $tag): string
    {
        $tag = trim($tag);
        if ($tag === '') {
            return '';
        }

        return ltrim($tag, "vV");
    }

    private function renderChangelog(array $release): string
    {
        $title = trim((string) ($release['name'] ?? ''));
        $body = trim((string) ($release['body'] ?? ''));

        $html = '';
        if ($title !== '') {
            $html .= '<p><strong>' . esc_html($title) . '</strong></p>';
        }

        if ($body === '') {
            $html .= '<p>See the latest release notes on <a href="' . esc_url($release['html_url'] ?? LIFE_OS_GITHUB_RELEASES_URL) . '">GitHub Releases</a>.</p>';
            return $html;
        }

        $paragraphs = preg_split("/\r\n\r\n|\n\n|\r\r/", $body) ?: [];
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            $html .= '<p>' . nl2br(esc_html($paragraph)) . '</p>';
        }

        return $html;
    }

    private function pluginFile(): string
    {
        return plugin_basename(LIFE_OS_FILE);
    }

    private function pluginSlug(): string
    {
        return dirname($this->pluginFile());
    }
}
