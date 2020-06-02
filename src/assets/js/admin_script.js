(function ($) {
    $(function () {

        var conditionField = $('#woocommerce_fasterpay_allow_pingback_url');
        var customPingbackUrlField = $('#woocommerce_fasterpay_pingback_url').parents('tr');

        if (conditionField.length > 0 && customPingbackUrlField.length > 0) {

            if (conditionField.prop('checked') == false) {

                customPingbackUrlField.hide();

            }
            
            conditionField.on('change', function () {
                
                if ($(this).prop('checked') == false) {

                    customPingbackUrlField.fadeOut();

                } else {

                    customPingbackUrlField.fadeIn();

                }
                
            });
        }
    });
})(jQuery);