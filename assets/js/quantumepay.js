var successCallback = function(data) {
    var checkout_form = $('form.woocommerce-checkout');
};

var errorCallback = function(data) {
    console.log(data);
};

var tokenRequest = function() {
    return false;
};

jQuery(function($) {
    var checkout_form = $('form.woocommerce-checkout');
});

(function() {
    'use strict';

    function formatExpiry(value) {
        const compact = String(value).replace(/\s/g, '');
        const complete = compact.match(/^(\d{1,2})\/(\d{4})$/);

        if (complete) {
            return complete[1].padStart(2, '0') + ' / ' + complete[2].slice(-2);
        }

        const digits = compact.replace(/\D/g, '');

        if (/^\d{6}$/.test(digits)) {
            return digits.slice(0, 2) + ' / ' + digits.slice(-2);
        }

        const short = digits.slice(0, 4);

        return short.length > 2
            ? short.slice(0, 2) + ' / ' + short.slice(2)
            : short;
    }

    const selector = '#qp_expdate, input[name="quantumepay-card-expiry"]';

    document.addEventListener('paste', function(event) {
        if (!event.target.matches(selector) || !event.clipboardData) return;

        const text = event.clipboardData.getData('text').trim();

        if (!/^(?:\d{1,2}\s*\/\s*\d{2}(?:\d{2})?|\d{4}|\d{6})$/.test(text)) return;

        event.preventDefault();
        event.target.value = formatExpiry(text);
        event.target.dispatchEvent(new Event('input', { bubbles: true }));
        event.target.dispatchEvent(new Event('change', { bubbles: true }));
    }, true);

    document.addEventListener('input', function(event) {
        if (!event.target.matches(selector)) return;
        event.target.value = formatExpiry(event.target.value);
    }, true);
})();