jQuery(function ($) {
    var checkout_form = $('form.woocommerce-checkout');
    var cardNumber = '';
    var cardExpiry = '';
    var cardCvc = '';
    var isCardNumberValid = false;
    var isCardExpiryValid = false;
    var isCardCvcValid = false;

    // Check if bypass mode is enabled (passed from PHP)
    var isBypass = window.quantumEPayData ? window.quantumEPayData.is_bypass : false;

    // Validation and formatting functions
    const formatCardNumber = (value) => {
        const v = value.replace(/\s+/g, '').replace(/[^0-9]/gi, '');
        const matches = v.match(/\d{4,16}/g);
        const match = (matches && matches[0]) || '';
        const parts = [];
        for (let i = 0, len = match.length; i < len; i += 4) {
            parts.push(match.substring(i, i + 4));
        }
        return parts.length ? parts.join(' ') : value;
    };

    const formatCardExpiry = (value) => {
        let v = value.replace(/\D/g, '');
        if (v.length > 2) {
            v = v.substring(0, 2) + '/' + v.substring(2, 4);
        }
        return v.substring(0, 5);
    };

    const validateCardNumber = (value) => {
        const cleaned = value.replace(/\s/g, '');
        return cleaned.length >= 15 && cleaned.length <= 19;
    };

    const validateCardExpiry = (value) => {
        return /^(0[1-9]|1[0-2])\/?([0-9]{2})$/.test(value);
    };

    const validateCardCvc = (value) => {
        return value.length >= 3 && value.length <= 4;
    };

    // Event listeners for real-time updates (only if not bypass mode)
    if (!isBypass) {
        checkout_form.on('input', '#quantumepay_card_number', function () {
            cardNumber = formatCardNumber($(this).val());
            $(this).val(cardNumber);
            isCardNumberValid = validateCardNumber(cardNumber);
            updateFormValidation();
        });

        checkout_form.on('input', '#quantumepay_card_expiry', function () {
            cardExpiry = formatCardExpiry($(this).val());
            $(this).val(cardExpiry);
            isCardExpiryValid = validateCardExpiry(cardExpiry);
            updateFormValidation();
        });

        checkout_form.on('input', '#quantumepay_card_cvc', function () {
            cardCvc = $(this).val().replace(/\D/g, '').substring(0, 4);
            $(this).val(cardCvc);
            isCardCvcValid = validateCardCvc(cardCvc);
            updateFormValidation();
        });

        // Update form validation state and enable/disable submit
        const updateFormValidation = () => {
            const isValid = isCardNumberValid && isCardExpiryValid && isCardCvcValid;
            checkout_form.find('#place_order').prop('disabled', !isValid);
        };
    } else {
        // Bypass mode: Enable place order button by default
        checkout_form.find('#place_order').prop('disabled', false);
    }

    // Token request on form submission
    checkout_form.on('checkout_place_order', function () {
        if (isBypass) {
            return true;
        }

        if (!isCardNumberValid || !isCardExpiryValid || !isCardCvcValid) {
            alert('Please correct the card details.');
            return false;
        }

        // Placeholder for payment gateway token request
        var tokenRequest = function () {
            var data = {
                card_number: cardNumber.replace(/\s/g, ''),
                card_expiry: cardExpiry.replace(/\D/g, ''),
                card_cvc: cardCvc
            };
            // Simulate success for now
            successCallback(data);
            return false;
        };

        return tokenRequest();
    });

    var successCallback = function (data) {
        checkout_form.submit();
    };

    var errorCallback = function (data) {
        console.log(data);
        alert('Payment processing failed. Please try again.');
    };
});