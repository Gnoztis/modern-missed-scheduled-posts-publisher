(function ($) {
    'use strict';

    var config = window.modernMsppReview;
    if (!config || !config.ajaxUrl || !config.nonce) {
        return;
    }

    function dismiss(type) {
        $.post(config.ajaxUrl, {
            action: config.action,
            nonce: config.nonce,
            type: type
        });
    }

    $(document).on('click', '.modern-mspp-action', function (event) {
        event.preventDefault();
        var $link  = $(this);
        var action = $link.data('action');
        var open   = $link.data('open');
        var href   = this.href;

        $('#modern-mspp-review-notice').fadeOut();

        if (action) {
            dismiss(action);
        }

        if (open && href && href !== '#') {
            window.open(href, '_blank', 'noopener,noreferrer');
        }
    });

    $(document).on('click', '#modern-mspp-review-notice .notice-dismiss', function () {
        dismiss('delay');
    });
}(jQuery));