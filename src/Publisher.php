<?php
declare(strict_types=1);

namespace ModernMSPP;

if (! defined('ABSPATH')) {
    exit;
}

final class Publisher
{
    public const ACTION              = 'modern_missed_scheduled_posts_publisher';
    public const OPTION_LAST_RUN     = 'modern_mspp_last_run';
    public const BATCH_LIMIT         = 20;
    public const FALLBACK_MULTIPLIER = 1.1;
    public const DEFAULT_FREQUENCY   = 900;

    /**
     * The inline fallback source, read once per request.
     *
     * Kept in memory so should_inject_fallback() and enqueue_fallback() do not
     * read the asset twice on the same page.
     */
    private ?string $fallback_script_cache = null;

    public function register(): void
    {
        add_action('send_headers', [$this, 'on_send_headers']);
        add_action('shutdown',     [$this, 'loopback']);
        add_action('wp_ajax_nopriv_' . self::ACTION, [$this, 'handle_ajax']);
        add_action('wp_ajax_'        . self::ACTION, [$this, 'handle_ajax']);
    }

    public function frequency(): int
    {
        /**
         * Filter how often (in seconds) we attempt to publish missed posts.
         *
         * @param int $frequency Default 900 (15 min).
         */
        return max(
            60,
            (int) apply_filters('modern_missed_scheduled_posts_publisher_frequency', self::DEFAULT_FREQUENCY)
        );
    }

    public function last_run(): int
    {
        return (int) get_option(self::OPTION_LAST_RUN, 0);
    }

    public function recently_ran(): bool
    {
        return $this->last_run() >= (time() - $this->frequency());
    }

    /**
     * Ask the cache layer not to cache the page while we still need to
     * inject our fallback script. HTML page requests only.
     */
    public function on_send_headers(): void
    {
        if (! $this->should_inject_fallback()) {
            return;
        }

        add_action('wp_enqueue_scripts', [$this, 'enqueue_fallback']);
        nocache_headers();
    }

    /**
     * Embed a tiny fetch()-based fallback for when the loopback fails
     * (hosts often block loopback requests).
     *
     * The script is inlined into the page: an empty handle plus
     * wp_add_inline_script() means no separate request and no <script src>.
     * assets/js/fallback.js stays in the repository as the source.
     */
    public function enqueue_fallback(): void
    {
        if (! $this->should_inject_fallback()) {
            return;
        }

        $script = $this->fallback_script();
        if ('' === $script) {
            return;
        }

        // Once we know real visitor traffic hits the server, the loopback is unnecessary.
        remove_action('shutdown', [$this, 'loopback']);

        wp_register_script(self::ACTION, false, [], MODERN_MSPP_VERSION, true);
        wp_enqueue_script(self::ACTION);

        wp_localize_script(self::ACTION, 'modernMsppFallback', [
            'url'  => add_query_arg('action', self::ACTION, admin_url('admin-ajax.php')),
            'body' => self::ACTION . '_nonce=' . Nonce::create(self::ACTION),
        ]);

        wp_add_inline_script(self::ACTION, $script);
    }

    /**
     * Contents of assets/js/fallback.js, or '' when it cannot be read.
     *
     * The file is the source of truth but is never served as a separate asset:
     * it is embedded into the page. A missing file disables only the browser
     * fallback; the shutdown loopback stays registered.
     */
    private function fallback_script(): string
    {
        if (null !== $this->fallback_script_cache) {
            return $this->fallback_script_cache;
        }

        $file = MODERN_MSPP_DIR . 'assets/js/fallback.js';

        if (! is_readable($file)) {
            $this->fallback_script_cache = '';
            return $this->fallback_script_cache;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin asset, never a user path.
        $contents = file_get_contents($file);
        $this->fallback_script_cache = is_string($contents) ? $contents : '';

        return $this->fallback_script_cache;
    }

    /**
     * Non-blocking loopback request fired on shutdown.
     */
    public function loopback(): void
    {
        if ($this->recently_ran()) {
            return;
        }

        wp_remote_post(
            add_query_arg('action', self::ACTION, admin_url('admin-ajax.php')),
            [
                'timeout'   => 0.01,
                'blocking'  => false,
                /** This filter is documented in wp-includes/class-wp-http-streams.php */
                'sslverify' => apply_filters('https_local_ssl_verify', false),
                'body'      => [
                    self::ACTION . '_nonce' => Nonce::create(self::ACTION),
                ],
            ]
        );
    }

    /**
     * AJAX handler for both authenticated and anonymous callers.
     *
     * Always responds with success so the plugin is safe behind full-page
     * caching and no error output ever leaks to the visitor's browser.
     */
    public function handle_ajax(): void
    {
        $raw = $_POST[self::ACTION . '_nonce'] ?? '';
        if (! is_string($raw) || ! Nonce::verify(self::ACTION, sanitize_text_field(wp_unslash($raw)))) {
            wp_send_json_success();
        }

        if ($this->recently_ran() || ! $this->claim_run()) {
            wp_send_json_success();
        }

        $this->publish_missed_posts();

        wp_send_json_success();
    }

    /**
     * Publish up to BATCH_LIMIT posts that missed their schedule.
     *
     * The caller must own the run (claim_run()): the timestamp is written
     * when the run is claimed, so publishing can never bypass the throttle.
     */
    public function publish_missed_posts(): void
    {
        global $wpdb;

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_date <= %s AND post_status = 'future'
                 ORDER BY post_date ASC
                 LIMIT %d",
                current_time('mysql', 0),
                self::BATCH_LIMIT
            )
        );

        if (empty($ids)) {
            return;
        }

        foreach ($ids as $id) {
            wp_publish_post((int) $id);
        }

        // If we hit the batch limit, reset the timer after the batch is
        // published so the next request continues the backlog immediately.
        // Resetting before the loop would let a second request claim the
        // run and select the same not-yet-published rows again.
        if (count($ids) >= self::BATCH_LIMIT) {
            update_option(self::OPTION_LAST_RUN, 0);
        }
    }

    /**
     * Whether this request can render the browser fallback at all.
     *
     * send_headers() runs for every front-end request, but the fallback is
     * only useful on a normal public content view that calls wp_head(): not in
     * the admin, feeds, robots.txt, trackback, sitemap, REST/JSON, search,
     * 404, previews or embeds. Everything else keeps the shutdown loopback and
     * is never touched by this script.
     */
    private function should_inject_fallback(): bool
    {
        if (! $this->needs_fallback()) {
            return false;
        }

        if (is_admin() || is_feed() || is_robots() || is_trackback()) {
            return false;
        }

        // is_sitemap() exists since WP 7.1; on 7.0 the sitemap request is
        // identified by its query var.
        $is_sitemap = function_exists('is_sitemap')
            ? is_sitemap()
            : (bool) get_query_var('sitemap');

        if ($is_sitemap) {
            return false;
        }

        if ((defined('REST_REQUEST') && REST_REQUEST)
            || (function_exists('wp_is_json_request') && wp_is_json_request())) {
            return false;
        }

        if (is_preview() || is_customize_preview()) {
            return false;
        }

        if (function_exists('is_embed') && is_embed()) {
            return false;
        }

        // Normal content views only: front page, blog index, singular and
        // archives. Search, 404, favicons and other utility pages keep the
        // loopback trigger but never receive the inline script.
        if (! (is_front_page() || is_home() || is_singular() || is_archive())) {
            return false;
        }

        // A missing source file disables only the browser fallback.
        return '' !== $this->fallback_script();
    }

    /**
     * True while no run happened for longer than one fallback window.
     *
     * The window is slightly longer than the throttle so a healthy loopback
     * keeps the pages cacheable: by the time the script could be useful,
     * a run has already updated the timestamp.
     */
    private function needs_fallback(): bool
    {
        return $this->last_run() < (time() - (self::FALLBACK_MULTIPLIER * $this->frequency()));
    }

    /**
     * Atomically claims the current run.
     *
     * The previous read-then-write sequence let two concurrent requests
     * (loopback plus browser fallback, or two visitors) both pass the
     * throttle and publish the same batch twice, firing transition hooks
     * twice. A single conditional statement makes the claim exclusive: only
     * the request that changes the row may publish.
     *
     * The timestamp is deliberately written before the posts are selected:
     * a fatal in the middle of a run costs one cycle, it never creates a
     * publish loop.
     *
     * One statement both creates the option on the first run and performs
     * the compare-and-swap. MySQL reports 1 affected row for the insert,
     * 2 for a real update and 0 when the stored timestamp is still inside
     * the throttle, so "0" means another request owns the run.
     */
    private function claim_run(): bool
    {
        global $wpdb;

        $now = time();

        $claimed = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
                 VALUES (%s, %s, %s)
                 ON DUPLICATE KEY UPDATE option_value =
                     IF(CAST(option_value AS UNSIGNED) < %d, %s, option_value)",
                self::OPTION_LAST_RUN,
                (string) $now,
                'yes',
                $now - $this->frequency(),
                (string) $now
            )
        );

        if ($claimed < 1) {
            return false;
        }

        // The statement bypasses the options API: drop the cached value, the
        // autoloaded "alloptions" map and the "option does not exist" marker
        // that get_option() may have set earlier in this request.
        wp_cache_delete(self::OPTION_LAST_RUN, 'options');
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');

        return true;
    }
}
