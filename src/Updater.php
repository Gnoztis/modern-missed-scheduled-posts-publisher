<?php
declare(strict_types=1);

namespace ModernMSPP;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Plugin updates through the native WordPress mechanism; source — GitHub
 * Releases and tags of REPO.
 *
 * The candidate is the highest version of both sources, not "release, else
 * tag": a repository can keep an old release and a newer tag, and a
 * release-first order would never offer the new version.
 *
 * The download package must come from GitHub over https; the update entry is
 * keyed by the real plugin_basename() and compared with the version in the
 * header on disk, so a renamed folder and the request that installs an
 * update cannot produce a phantom "update available" entry.
 */
final class Updater
{
    public const REPO = 'Gnoztis/modern-missed-scheduled-posts-publisher';
    public const SLUG = 'modern-missed-scheduled-posts-publisher';

    private const TRANSIENT       = 'modern_mspp_github_release';
    private const ERROR_TRANSIENT = 'modern_mspp_github_release_error';
    private const CACHE_TTL       = 6 * \HOUR_IN_SECONDS;
    private const ERROR_TTL       = 15 * \MINUTE_IN_SECONDS;
    private const FORCE_CHECK     = 'modern-mspp-force-check';
    private const FORCE_NONCE     = 'modern_mspp_force_check';

    /** @var array{requires:string,requires_php:string,tested:string}|null */
    private static ?array $header = null;

    public function register_hooks(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'inject_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
        add_filter('upgrader_source_selection', [$this, 'rename_source'], 10, 4);
        add_action('upgrader_process_complete', [$this, 'after_upgrade'], 10, 2);
        add_action('load-plugins.php', [$this, 'maybe_force_check']);
        add_filter('plugin_action_links_' . self::basename(), [$this, 'action_links']);
    }

    /**
     * Basename of the installed main plugin file, computed at runtime.
     *
     * WordPress keys update_plugins->response by this value. A hard-coded
     * "slug/slug.php" breaks when the folder is renamed (a GitHub zipball
     * unpacks into Gnoztis-...-<sha>): the admin badge counts the entry while
     * get_plugin_updates() cannot list it, because no installed file matches
     * the key.
     */
    public static function basename(): string
    {
        $basename = plugin_basename(MODERN_MSPP_FILE);

        if ($basename === '') {
            // The main file is outside the plugin directory: fall back to the
            // documented layout so the filter still has a key.
            $basename = self::SLUG . '/' . wp_basename(MODERN_MSPP_FILE);
        }

        return $basename;
    }

    /**
     * Adds our entry to the update_plugins transient when GitHub has a
     * version newer than both the running constant and the file on disk.
     *
     * Inside the request that replaces the files, core rewrites this
     * transient while MODERN_MSPP_VERSION still holds the version loaded at
     * the start of the request. Comparing only with the constant would
     * re-inject the installed version right after a successful update, and
     * that phantom entry would live up to 12 hours.
     */
    public function inject_update(mixed $transient): mixed
    {
        if (! is_object($transient)) {
            return $transient;
        }
        if (! isset($transient->response) || ! is_array($transient->response)) {
            $transient->response = [];
        }

        $basename  = self::basename();
        $installed = $this->installed_version();

        // Runs before the early returns: a dangling entry from a renamed
        // folder or an earlier key must disappear even when GitHub is
        // unreachable right now.
        $this->purge_stale_entries($transient, $basename);
        $this->purge_installed_entry($transient, $basename, $installed);

        $release = $this->get_release();
        if ($release === null) {
            return $transient;
        }

        if (version_compare($release['version'], MODERN_MSPP_VERSION, '<=')
            || version_compare($release['version'], $installed, '<=')) {
            return $transient;
        }

        $transient->response[$basename] = (object) [
            'id'            => $basename,
            'slug'          => self::SLUG,
            'plugin'        => $basename,
            'new_version'   => $release['version'],
            'url'           => 'https://github.com/' . self::REPO,
            'package'       => $release['package'],
            'tested'        => $release['tested'],
            'requires'      => $release['requires'],
            'requires_php'  => $release['requires_php'],
            'icons'         => [],
            'banners'       => [],
            'compatibility' => new \stdClass(),
        ];

        if (isset($transient->no_update) && is_array($transient->no_update)) {
            unset($transient->no_update[$basename]);
        }

        return $transient;
    }

    /**
     * Version of the main plugin file as it is on disk.
     *
     * Deliberately not cached in a static: the point is to see the files
     * after Plugin_Upgrader replaced them inside the same request.
     */
    private function installed_version(): string
    {
        $data = function_exists('get_file_data')
            ? get_file_data(MODERN_MSPP_FILE, ['Version' => 'Version'], 'plugin')
            : [];

        $version = isset($data['Version']) ? trim((string) $data['Version']) : '';

        return $version !== '' ? $version : MODERN_MSPP_VERSION;
    }

    /**
     * Removes our entry when it offers what is already on disk.
     *
     * Core compares the entry with the version from get_plugins() and shows
     * the row as an update until the transient is rebuilt, so the phantom
     * entry must not stay in response.
     */
    private function purge_installed_entry(mixed $transient, string $basename, string $installed): void
    {
        if (! isset($transient->response[$basename]) || ! is_object($transient->response[$basename])) {
            return;
        }

        $entry   = $transient->response[$basename];
        $offered = isset($entry->new_version) ? trim((string) $entry->new_version) : '';

        if ($offered === ''
            || version_compare($offered, $installed, '<=')
            || version_compare($offered, MODERN_MSPP_VERSION, '<=')) {
            unset($transient->response[$basename]);
        }
    }

    /**
     * Drops entries that point at our main file under a foreign key.
     *
     * Such a key does not match any installed plugin file, so the admin badge
     * counts an update that the update list can never show.
     */
    private function purge_stale_entries(mixed $transient, string $basename): void
    {
        $main = wp_basename(MODERN_MSPP_FILE);

        foreach (['response', 'no_update'] as $bucket) {
            if (! isset($transient->$bucket) || ! is_array($transient->$bucket)) {
                continue;
            }

            foreach ($transient->$bucket as $key => $entry) {
                if ((string) $key === $basename || ! is_object($entry)) {
                    continue;
                }
                if (empty($entry->plugin) || wp_basename((string) $entry->plugin) !== $main) {
                    continue;
                }

                unset($transient->$bucket[$key]);
            }
        }
    }

    /**
     * Data for the "View details" modal.
     */
    public function plugin_info(mixed $result, string $action = '', mixed $args = null): mixed
    {
        if ($action !== 'plugin_information' || ! is_object($args) || empty($args->slug)) {
            return $result;
        }
        if (self::SLUG !== (string) $args->slug) {
            return $result;
        }

        $release = $this->get_release();
        if ($release === null) {
            return $result;
        }

        $header = $this->header_data();

        return (object) [
            'name'          => 'Modern Missed Scheduled Posts Publisher',
            'slug'          => self::SLUG,
            'version'       => $release['version'],
            'author'        => '<a href="https://github.com/Gnoztis">Gnostis</a>',
            'homepage'      => 'https://github.com/' . self::REPO,
            'requires'      => $header['requires'],
            'tested'        => $this->tested_version($header['tested']),
            'requires_php'  => $header['requires_php'],
            'download_link' => $release['package'],
            'last_updated'  => $release['published_at'],
            'sections'      => [
                'description' => __(
                    'Публикует просроченные запланированные записи, когда WP-Cron не сработал.',
                    'modern-missed-scheduled-posts-publisher'
                ),
                'changelog'   => $release['body'] !== ''
                    ? wpautop(wp_kses_post($release['body']))
                    : __('Описание изменений в релизе не заполнено.', 'modern-missed-scheduled-posts-publisher'),
            ],
        ];
    }

    /**
     * GitHub archives unpack into Gnoztis-modern-...-<sha>; rename the folder
     * to the one the site actually uses, otherwise WordPress updates the
     * wrong directory or leaves a second, inactive copy.
     */
    public function rename_source(mixed $source, mixed $remote_source, mixed $upgrader = null, mixed $hook_extra = []): mixed
    {
        if (! is_string($source) || ! is_string($remote_source)) {
            return $source;
        }
        if (! is_array($hook_extra) || empty($hook_extra['plugin'])) {
            return $source;
        }
        if (self::basename() !== (string) $hook_extra['plugin']) {
            return $source;
        }
        if (! is_dir($source)) {
            return $source;
        }
        if (! is_file(trailingslashit($source) . wp_basename(MODERN_MSPP_FILE))) {
            return $source;
        }

        $folder = dirname(self::basename());
        if ($folder === '.' || $folder === '') {
            return $source;
        }

        $desired = trailingslashit($remote_source) . $folder;
        if (untrailingslashit($source) === untrailingslashit($desired)) {
            return $source;
        }

        global $wp_filesystem;
        if (! $wp_filesystem) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        if (! $wp_filesystem) {
            return $source;
        }

        if ($wp_filesystem->move($source, $desired, true)) {
            return trailingslashit($desired);
        }

        return $source;
    }

    /**
     * Reset the update-check cache after our upgrade.
     */
    public function after_upgrade(mixed $upgrader = null, mixed $hook_extra = []): void
    {
        if (is_array($hook_extra) && ! empty($hook_extra['plugins']) && is_array($hook_extra['plugins'])) {
            if (! in_array(self::basename(), $hook_extra['plugins'], true)) {
                return;
            }
        }

        $this->flush();
    }

    /**
     * "Check for updates" link in the plugin row.
     *
     * @param string[] $links
     * @return string[]
     */
    public function action_links($links): array
    {
        if (! current_user_can('update_plugins')) {
            return (array) $links;
        }

        $url = wp_nonce_url(
            add_query_arg(self::FORCE_CHECK, '1', admin_url('plugins.php')),
            self::FORCE_NONCE
        );

        $links = (array) $links;
        $links['modern_mspp_check'] = '<a href="' . esc_url($url) . '">'
            . esc_html__('Проверить обновления', 'modern-missed-scheduled-posts-publisher')
            . '</a>';

        return $links;
    }

    /**
     * Handles the "Check for updates" click on plugins.php.
     */
    public function maybe_force_check(): void
    {
        if (! isset($_GET[self::FORCE_CHECK])) {
            return;
        }
        if (! current_user_can('update_plugins')) {
            return;
        }
        check_admin_referer(self::FORCE_NONCE);

        $this->flush();

        require_once ABSPATH . 'wp-admin/includes/update.php';
        if (function_exists('wp_update_plugins')) {
            wp_update_plugins();
        }

        wp_safe_redirect(admin_url('plugins.php'));
        exit;
    }

    /**
     * Drops every cached value of this class.
     */
    private function flush(): void
    {
        delete_transient(self::TRANSIENT);
        delete_transient(self::ERROR_TRANSIENT);
        delete_site_transient('update_plugins');

        self::$header = null;
    }

    /**
     * Latest version data (6 hour cache, 15 minute error cache).
     *
     * @return array{version:string,package:string,body:string,published_at:string,tested:string,requires:string,requires_php:string}|null
     */
    private function get_release(): ?array
    {
        $cached = get_transient(self::TRANSIENT);
        if (is_array($cached) && isset($cached['version'], $cached['package'])) {
            return $cached;
        }

        if (get_transient(self::ERROR_TRANSIENT)) {
            return null;
        }

        $release = $this->fetch_release();

        if ($release === null) {
            set_transient(self::ERROR_TRANSIENT, 1, self::ERROR_TTL);
            return null;
        }

        set_transient(self::TRANSIENT, $release, self::CACHE_TTL);

        return $release;
    }

    /**
     * Highest version of both sources; the release wins a tie (it carries the
     * changelog).
     *
     * @return array{version:string,package:string,body:string,published_at:string,tested:string,requires:string,requires_php:string}|null
     */
    private function fetch_release(): ?array
    {
        $release = $this->fetch_latest_release();
        $tag     = $this->fetch_latest_tag();

        if ($release === null && $tag === null) {
            return null;
        }
        if ($release === null) {
            return $tag;
        }
        if ($tag === null) {
            return $release;
        }

        return version_compare($tag['version'], $release['version'], '>') ? $tag : $release;
    }

    /**
     * @return array{version:string,package:string,body:string,published_at:string,tested:string,requires:string,requires_php:string}|null
     */
    private function fetch_latest_release(): ?array
    {
        $body = $this->api_get(
            sprintf('https://api.github.com/repos/%s/releases/latest', self::REPO)
        );
        if ($body === null) {
            return null;
        }

        $version = $this->normalize_version(isset($body['tag_name']) ? (string) $body['tag_name'] : '');
        if ($version === '') {
            return null;
        }

        $package = $this->find_package($body);
        if ($package === '') {
            return null;
        }

        return $this->build_release(
            $version,
            $package,
            isset($body['body']) ? (string) $body['body'] : '',
            isset($body['published_at']) ? (string) $body['published_at'] : ''
        );
    }

    /**
     * Highest semver tag. A tag without a GitHub Release is a normal case.
     *
     * @return array{version:string,package:string,body:string,published_at:string,tested:string,requires:string,requires_php:string}|null
     */
    private function fetch_latest_tag(): ?array
    {
        $tags = $this->api_get(
            sprintf('https://api.github.com/repos/%s/tags?per_page=30', self::REPO)
        );

        if (! is_array($tags)) {
            return null;
        }

        $best_version = '';
        $best_tag     = '';

        foreach ($tags as $tag) {
            if (! is_array($tag) || empty($tag['name'])) {
                continue;
            }
            $version = $this->normalize_version((string) $tag['name']);
            if ($version === '') {
                continue;
            }
            if ($best_version === '' || version_compare($version, $best_version, '>')) {
                $best_version = $version;
                $best_tag     = (string) $tag['name'];
            }
        }

        if ($best_version === '' || $best_tag === '') {
            return null;
        }

        $package = $this->safe_package_url(
            sprintf(
                'https://api.github.com/repos/%s/zipball/%s',
                self::REPO,
                rawurlencode($best_tag)
            )
        );

        if ($package === '') {
            return null;
        }

        return $this->build_release($best_version, $package, '', '');
    }

    /**
     * @return array{version:string,package:string,body:string,published_at:string,tested:string,requires:string,requires_php:string}
     */
    private function build_release(string $version, string $package, string $body, string $published_at): array
    {
        $header = $this->header_data();

        return [
            'version'      => $version,
            'package'      => $package,
            'body'         => $body,
            'published_at' => $published_at,
            'tested'       => $this->tested_version($header['tested']),
            'requires'     => $header['requires'],
            'requires_php' => $header['requires_php'],
        ];
    }

    /**
     * "Tested up to" turned into the value WordPress compares with.
     *
     * The header holds a branch claim ("7.1"), while the update screen
     * compares it with the full running version ("7.1.5"). api.wordpress.org
     * resolves this by serving the patch release of the declared branch; the
     * same expansion is done here so a bare "7.1" does not render
     * "Compatibility with WordPress 7.1.5: Not tested".
     */
    private function tested_version(string $tested): string
    {
        $tested = trim($tested);
        if ($tested === '') {
            return '';
        }

        $running = function_exists('wp_get_wp_version') ? (string) wp_get_wp_version() : '';
        if ($running === '' && isset($GLOBALS['wp_version'])) {
            $running = (string) $GLOBALS['wp_version'];
        }
        $running = trim((string) preg_replace('/-.*/', '', $running));
        if ($running === '') {
            return $tested;
        }

        $tested_parts  = explode('.', $tested);
        $running_parts = explode('.', $running);

        if (count($tested_parts) >= count($running_parts)) {
            return $tested;
        }

        if (array_slice($running_parts, 0, count($tested_parts)) === $tested_parts) {
            return $running;
        }

        return $tested;
    }

    /**
     * GET to the GitHub API. The URL is built from the repository constant.
     *
     * @return array<string,mixed>|null
     */
    private function api_get(string $url): ?array
    {
        $args = [
            'timeout'     => 5,
            'redirection' => 2,
            'headers'     => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'Modern-Missed-Scheduled-Posts-Publisher/' . MODERN_MSPP_VERSION,
            ],
        ];

        $response = wp_remote_get($url, $args); // phpcs:ignore -- URL built from the repository constant; the package URL is validated separately.
        if (is_wp_error($response)) {
            return null;
        }
        if ((int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        return is_array($body) ? $body : null;
    }

    /**
     * Archive URL: the first valid release zip asset, then the GitHub
     * zipball.
     *
     * @param array<string,mixed> $body
     */
    private function find_package(array $body): string
    {
        if (! empty($body['assets']) && is_array($body['assets'])) {
            foreach ($body['assets'] as $asset) {
                if (! is_array($asset) || empty($asset['browser_download_url'])) {
                    continue;
                }
                $name = isset($asset['name']) ? (string) $asset['name'] : '';
                if (! preg_match('/\.zip$/i', $name)) {
                    continue;
                }
                $url = $this->safe_package_url((string) $asset['browser_download_url']);
                if ($url !== '') {
                    return $url;
                }
            }
        }

        if (! empty($body['zipball_url'])) {
            return $this->safe_package_url((string) $body['zipball_url']);
        }

        return '';
    }

    /**
     * https only, GitHub hosts only: the package must not come from a field
     * a third-party service could tamper with.
     */
    private function safe_package_url(string $url): string
    {
        $url = esc_url_raw(trim($url));
        if ($url === '') {
            return '';
        }

        $scheme = wp_parse_url($url, PHP_URL_SCHEME);
        $host   = wp_parse_url($url, PHP_URL_HOST);

        if (strtolower((string) $scheme) !== 'https') {
            return '';
        }

        $allowed = ['api.github.com', 'github.com', 'codeload.github.com', 'objects.githubusercontent.com'];
        if (! in_array(strtolower((string) $host), $allowed, true)) {
            return '';
        }

        return $url;
    }

    /**
     * Strict semver: v1.2.3 and 1.2.3 are equivalent; prereleases and
     * four-part versions are rejected.
     */
    private function normalize_version(string $tag): string
    {
        $tag = trim($tag);
        if (! preg_match('/^v?(\d+\.\d+(?:\.\d+)?)$/', $tag, $matches)) {
            return '';
        }

        return $matches[1];
    }

    /**
     * Version headers of the plugin file.
     *
     * `Tested up to` and `Requires at least` are different things: the first
     * is the newest WordPress the plugin was checked against, the second the
     * oldest it runs on.
     *
     * @return array{requires:string,requires_php:string,tested:string}
     */
    private function header_data(): array
    {
        if (self::$header === null) {
            $data = function_exists('get_file_data')
                ? get_file_data(
                    MODERN_MSPP_FILE,
                    [
                        'Requires at least' => 'Requires at least',
                        'Requires PHP'      => 'Requires PHP',
                        'Tested up to'      => 'Tested up to',
                    ],
                    'plugin'
                )
                : [];

            self::$header = [
                'requires'     => isset($data['Requires at least']) ? (string) $data['Requires at least'] : '',
                'requires_php' => isset($data['Requires PHP']) ? (string) $data['Requires PHP'] : '',
                'tested'       => isset($data['Tested up to']) ? (string) $data['Tested up to'] : '',
            ];
        }

        return self::$header;
    }
}
