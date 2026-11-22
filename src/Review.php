<?php
declare(strict_types=1);

namespace ModernMSPP;

if (! defined('ABSPATH')) {
    exit;
}

final class Review
{
    private const OPTION_REMOVED = 'modern_mspp_review_removed';
    private const OPTION_DELAYED = 'modern_mspp_review_delayed';
    private const NONCE_ACTION   = 'modern_mspp_dismiss_review';
    private const AJAX_ACTION    = 'modern_mspp_dismiss_review';
    private const SCRIPT_HANDLE  = 'modern-mspp-review';

    public function register(): void
    {
        add_action('admin_notices',         [$this, 'render']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'dismiss']);
    }

    public function enqueue(): void
    {
        if (! $this->should_display()) {
            return;
        }

        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            MODERN_MSPP_URL . 'assets/js/review.js',
            ['jquery'],
            MODERN_MSPP_VERSION,
            true
        );

        wp_localize_script(self::SCRIPT_HANDLE, 'modernMsppReview', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action'  => self::AJAX_ACTION,
            'nonce'   => wp_create_nonce(self::NONCE_ACTION),
        ]);
    }

    public function dismiss(): void
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }

        $nonce = $_POST['nonce'] ?? '';
        if (! is_string($nonce) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($nonce)), self::NONCE_ACTION)) {
            wp_send_json_error(['message' => 'invalid nonce'], 403);
        }

        $type = $_POST['type'] ?? '';
        if (! is_string($type)) {
            wp_send_json_error(['message' => 'invalid type'], 400);
        }
        $type = sanitize_key(wp_unslash($type));

        switch ($type) {
            case 'remove':
                update_option(self::OPTION_REMOVED, true, false);
                wp_send_json_success(['status' => 'removed']);
                // no break — wp_send_json_* terminates.

            case 'delay':
                update_option(self::OPTION_DELAYED, ['until' => time() + WEEK_IN_SECONDS], false);
                wp_send_json_success(['status' => 'delayed']);
                // no break.

            default:
                wp_send_json_error(['message' => 'invalid type'], 400);
        }
    }

    public function render(): void
    {
        if (! $this->should_display()) {
            return;
        }

        $review_url = 'https://wordpress.org/support/plugin/modern-missed-scheduled-posts-publisher/reviews/';
        $review_5   = $review_url . '?filter=5';
        ?>
        <div class="notice notice-info is-dismissible" id="modern-mspp-review-notice">
            <p>
                <?php esc_html_e(
                    'Понравился плагин? Поставьте оценку — это помогает другим сайтам узнать о нём.',
                    'modern-missed-scheduled-posts-publisher'
                ); ?>
            </p>
            <p>
                <a class="modern-mspp-action" data-action="remove" data-open="1"
                   href="<?php echo esc_url($review_5); ?>" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e('Хорошо, ставлю оценку', 'modern-missed-scheduled-posts-publisher'); ?>
                </a> |
                <a class="modern-mspp-action" data-action="delay" href="#">
                    <?php esc_html_e('Не сейчас, может позже', 'modern-missed-scheduled-posts-publisher'); ?>
                </a> |
                <a class="modern-mspp-action" data-action="remove" href="#">
                    <?php esc_html_e('Я уже поставил', 'modern-missed-scheduled-posts-publisher'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    private function should_display(): bool
    {
        if (! current_user_can('manage_options')) {
            return false;
        }

        if (get_option(self::OPTION_REMOVED, false)) {
            return false;
        }

        $delayed = get_option(self::OPTION_DELAYED, []);
        if (is_array($delayed) && ! empty($delayed['until']) && time() < (int) $delayed['until']) {
            return false;
        }

        return true;
    }
}