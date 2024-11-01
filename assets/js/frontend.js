jQuery(document).ready(function ($) {
    let data = {
        action: smart_send_options.prefix + '_shipping_options',
        security: smart_send_options.security
    };
    $(document).on('change', '.smart_send_option', function () {
        let $wrap = $(this).closest('div');
        let $_self = $(this);
        $wrap.block({
            message: null,
            overlayCSS: {
                background: '#fff',
                opacity: 0.6
            }
        });
        data.option = $_self.attr('data-option');
        data.value = $_self.is(':checked') ? 'yes' : 'no';

        $.ajax({
            type: 'POST',
            url: smart_send_options.ajax_url,
            data: data,
            success: function (resp) {
                $('body.woocommerce-checkout').trigger('update_checkout');
                $('body.woocommerce-cart').trigger('wc_update_cart');
                $wrap.unblock();
            }
        });
    });
});