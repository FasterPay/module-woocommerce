(function($) {
    // here $ would be point to jQuery object
    $(document).ready(function() {
        if ($('#fasterpay_payment_form').length) {
            $('#fasterpay_submit').hide();
            $('#fasterpay_payment_form').submit();
        }
    });
})(jQuery);