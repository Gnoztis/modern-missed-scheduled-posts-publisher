<?php
declare(strict_types=1);

namespace ModernMSPP;

defined('ABSPATH') || exit;

final class Publisher
{
    public const ACTION              = 'modern_missed_scheduled_posts_publisher';
    public const OPTION_LAST_RUN     = 'modern_mspp_last_run';
    public const BATCH_LIMIT         = 20;
    public const FALLBACK_MULTIPLIER = 1.1;
    public const DEFAULT_FREQUENCY   = 900;

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
     * inject our fallback script. Front-end only.
     */
    public function on_send_headers(): void
    {
        if (is_admin()) {
            return;
        }

        if ($this->last_run() >= (time() - (self::FALLBACK_MULTIPLIER * $this->frequency()))) {
            return;
        }

        add_action('wp_enqueue_scripts', [$this, 'enqueue_fallback']);
        nocache_headers();
    }

    /**
     * Inject a tiny fetch()-based fallback for when the loopback fails
     * (hosts often block loopback requests).
     */
    public function enqueue_fallback(): void
    {
        if ($this->last_run() >= (time() - (self::FALLBACK_MULTIPLIER * $this->frequency()))) {
            return;
        }

        // Once we know real visitor traffic hits the server, the loopback is unnecessary.
        remove_action('shutdown', [$this, 'loopback']);

        wp_enqueue_script(
            self::ACTION,
            MODERN_MSPP_URL . 'assets/js/fallback.js',
            [],
            MODERN_MSPP_VERSION,
            true
        );

        wp_localize_script(self::ACTION, 'modernMsppFallback', [
            'url'  => add_query_arg('action', self::ACTION, admin_url('admin-ajax.php')),
            'body' => self::ACTION . '_nonce=' . Nonce::create(self::ACTION),
        ]);
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

        if ($this->recently_ran()) {
            wp_send_json_success();
        }

        $this->publish_missed_posts();

        wp_send_json_success();
    }

    /**
     * Publish up to BATCH_LIMIT posts that missed their schedule.
     */
    public function publish_missed_posts(): void
    {
        global $wpdb;

        update_option(self::OPTION_LAST_RUN, time());

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

        // If we hit the batch limit, reset the timer so the next request
        // continues the backlog immediately instead of waiting a full cycle.
        if (count($ids) >= self::BATCH_LIMIT) {
            update_option(self::OPTION_LAST_RUN, 0);
        }

        foreach ($ids as $id) {
            wp_publish_post((int) $id);
        }
    }
}