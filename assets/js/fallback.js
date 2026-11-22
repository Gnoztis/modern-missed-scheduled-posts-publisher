/**
 * Fallback publisher for Modern Missed Scheduled Posts Publisher.
 *
 * Fired from the browser when the server-side loopback request cannot reach
 * the site (blocked by firewall, Basic Auth, WAF, etc.). Sends the same
 * AJAX request the loopback would have sent.
 *
 * The config object (window.modernMsppFallback) is injected via
 * wp_localize_script() from Publisher::enqueue_fallback().
 */
(function (config) {
    'use strict';

    if (!config || !window.fetch || !config.url || !config.body) {
        return;
    }

    try {
        window.fetch(config.url, {
            method: 'POST',
            body: new URLSearchParams(config.body),
            credentials: 'same-origin',
            keepalive: true
        }).catch(function () {
            /* Silent — the shutdown loopback should have handled it first. */
        });
    } catch (e) {
        /* Silent — never break the page for a background task. */
    }
}(window.modernMsppFallback));