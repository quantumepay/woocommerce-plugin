const { registerPaymentMethod } = wc.wcBlocksRegistry;
const { createElement, useState, useEffect } = wp.element;
const { __ } = wp.i18n;
const { useSelect, useDispatch } = wp.data;

// 🔒 Lock icon for security message
const LockIcon = () =>
    createElement(
        'svg',
        { width: '16', height: '16', viewBox: '0 0 24 24', fill: 'currentColor' },
        createElement('path', {
            d: 'M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z',
        })
    );

const QuantumGatewayContent = (props) => {
    const { eventRegistration, emitResponse, is_bypass } = props || {};
    const checkout_type = window.quantumEPayData ? window.quantumEPayData.checkout_type : 'standard';

    const [cardNumber, setCardNumber] = useState('');
    const [cardExpiry, setCardExpiry] = useState('');
    const [cardCvc, setCardCvc] = useState('');

    const { __internalSetPaymentMethodData, __internalSetPaymentStatus } = useDispatch('wc/store/payment');
    const { paymentMethodData } = useSelect((select) => {
        const store = select('wc/store/payment');
        return {
            paymentMethodData: store.getPaymentMethodData(),
        };
    });

    // Validation states
    const [isCardNumberValid, setIsCardNumberValid] = useState(false);
    const [isCardExpiryValid, setIsCardExpiryValid] = useState(false);
    const [isCardCvcValid, setIsCardCvcValid] = useState(false);

    // 🧠 Auto setup for bypass
    useEffect(() => {
        if (is_bypass) {
            __internalSetPaymentMethodData({
                ...paymentMethodData,
                payment_method: 'quantumepay',
                'quantumepay-bypass': true,
            });
            __internalSetPaymentStatus({ isFinished: true, hasError: false });
        }
    }, [is_bypass, __internalSetPaymentMethodData, __internalSetPaymentStatus, paymentMethodData]);

    // Validate fields
    useEffect(() => {
        const cleanedNumber = cardNumber.replace(/\s/g, '');
        setIsCardNumberValid(cleanedNumber.length >= 15 && cleanedNumber.length <= 19);
    }, [cardNumber]);

    useEffect(() => {
        setIsCardExpiryValid(/^(0[1-9]|1[0-2])\/?([0-9]{2})$/.test(cardExpiry));
    }, [cardExpiry]);

    useEffect(() => {
        setIsCardCvcValid(cardCvc.length >= 3 && cardCvc.length <= 4);
    }, [cardCvc]);

    // 🧾 WooCommerce Blocks payment event handling
    useEffect(() => {
        const { onPaymentSetup } = eventRegistration;
        const unsubscribe = onPaymentSetup(() => {
            if (is_bypass) {
                return {
                    type: emitResponse.responseTypes.SUCCESS,
                    meta: {
                        paymentMethodData: {
                            payment_method: 'quantumepay',
                            'quantumepay-bypass': true,
                        },
                    },
                };
            }

            if (!isCardNumberValid || !isCardExpiryValid || !isCardCvcValid) {
                return {
                    type: emitResponse.responseTypes.ERROR,
                    message: __('Please correct the card details.', 'quantum-gateway'),
                };
            }

            return {
                type: emitResponse.responseTypes.SUCCESS,
                meta: {
                    paymentMethodData: {
                        'quantumepay-card-number': cardNumber.replace(/\s/g, ''),
                        'quantumepay-card-expiry': cardExpiry.replace(/\s/g, ''),
                        'quantumepay-card-cvc': cardCvc,
                    },
                },
            };
        });
        return unsubscribe;
    }, [
        cardNumber,
        cardExpiry,
        cardCvc,
        isCardNumberValid,
        isCardExpiryValid,
        isCardCvcValid,
        eventRegistration,
        emitResponse,
        is_bypass,
    ]);

    // 🧰 Helpers
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

    const renderInputField = ({ name, value, onChange, label, valid }) => {
        return createElement('div', { className: 'quantumepay-form-input-container' },
            createElement('input', {
                type: 'text',
                className: 'quantumepay-form-control',
                name,
                placeholder: ' ',
                value,
                onChange,
                inputMode: 'numeric',
                'aria-label': label,
            }),
            createElement('label', { className: 'quantumepay-form-label' }, label),
            valid && createElement('span', { className: 'quantumepay-valid-icon' })
        );
    };

    const renderInputFields = () => {
        return createElement('div', null,
            createElement('div', { className: 'quantumepay-form-group' },
                renderInputField({
                    name: 'quantumepay-card-number',
                    value: cardNumber,
                    onChange: (e) => setCardNumber(formatCardNumber(e.target.value)),
                    label: __('Card Number', 'quantum-gateway'),
                    valid: isCardNumberValid,
                })
            ),
            createElement('div', { className: 'quantumepay-form-row' },
                createElement('div', { className: 'quantumepay-form-group quantumepay-half-width' },
                    renderInputField({
                        name: 'quantumepay-card-expiry',
                        value: cardExpiry,
                        onChange: (e) => {
                            let value = e.target.value.replace(/\D/g, '');
                            if (value.length > 2) value = value.substring(0, 2) + '/' + value.substring(2, 4);
                            setCardExpiry(value.substring(0, 5));
                        },
                        label: __('MM / YY', 'quantum-gateway'),
                        valid: isCardExpiryValid,
                    })
                ),
                createElement('div', { className: 'quantumepay-form-group quantumepay-half-width' },
                    renderInputField({
                        name: 'quantumepay-card-cvc',
                        value: cardCvc,
                        onChange: (e) => setCardCvc(e.target.value.replace(/\D/g, '').substring(0, 4)),
                        label: __('CVC', 'quantum-gateway'),
                        valid: isCardCvcValid,
                    })
                )
            ),
            createElement('div', { className: 'quantumepay-form-group quantumepay-security-message' },
                LockIcon(),
                __('Your payment information is secured with 256-bit SSL encryption.', 'quantum-gateway')
            )
        );
    };

    // 🪧 Notices for checkout type
    const renderNotice = () => {
        if (checkout_type === 'bypass') {
            return createElement(
                'div',
                { className: 'woocommerce-info quantumepay-bypass-notice' },
                __('No payment is required for this order. Click "Place Order" to complete.', 'quantum-gateway')
            );
        }

        if (checkout_type === 'pre_authorize') {
            return createElement(
                'div',
                { className: 'woocommerce-info quantumepay-preauth-notice' },
                createElement('strong', null, __('Pre-Authorization Notice', 'quantum-gateway')),
                createElement('p', null,
                    __('This transaction is a pre-authorization hold on your card. The actual charge will be processed upon order fulfillment or admin approval.', 'quantum-gateway')
                )
            );
        }

        return null;
    };

    return createElement(
        'div',
        { className: 'quantumepay-card-form' },
        renderNotice(),
        checkout_type === 'bypass' ? null : renderInputFields()
    );
};

// 🧱 Edit component (shown in editor)
const QuantumGatewayEdit = (props) => {
    const { is_bypass } = props || {};
    const checkout_type = window.quantumEPayData ? window.quantumEPayData.checkout_type : 'standard';

    if (is_bypass) {
        return createElement('div', { className: 'quantumepay-edit-view' },
            createElement('p', null, __('No payment required in bypass mode', 'quantum-gateway'))
        );
    }
    if (checkout_type === 'pre_authorize') {
        return createElement('div', { className: 'quantumepay-edit-view' },
            createElement('p', null, __('Quantum ePay (Pre-Authorization)', 'quantum-gateway')),
            createElement('p', null, __('Customer will enter card details for pre-authorization during checkout.', 'quantum-gateway'))
        );
    }
    return createElement('div', { className: 'quantumepay-edit-view' },
        createElement('p', null, __('Quantum ePay payment method', 'quantum-gateway')),
        createElement('p', null, __('Customer will enter their card details during checkout', 'quantum-gateway'))
    );
};

// 🌍 Determine checkout type
const checkout_type = window.quantumEPayData ? window.quantumEPayData.checkout_type : 'standard';
const is_bypass = checkout_type === 'bypass';

// 🧩 Register the payment method
const QuantumOptions = {
    name: 'quantumepay',
    label: createElement('span', null,
        createElement('span', { className: 'quantumepay-payment-method-label' },
            is_bypass
                ? __('No Payment Required', 'quantum-gateway')
                : checkout_type === 'pre_authorize'
                ? __('Quantum ePay (Pre-Authorization)', 'quantum-gateway')
                : __('Quantum ePay', 'quantum-gateway')
        )
    ),
    content: createElement(QuantumGatewayContent, { is_bypass }),
    edit: createElement(QuantumGatewayEdit, { is_bypass }),
    canMakePayment: () => true,
    ariaLabel: is_bypass
        ? __('No Payment Required', 'quantum-gateway')
        : checkout_type === 'pre_authorize'
        ? __('Quantum ePay (Pre-Authorization)', 'quantum-gateway')
        : __('Quantum ePay', 'quantum-gateway'),
    supports: {
        features: window.quantumEPayData ? window.quantumEPayData.supports : ['products'],
    },
    placeOrderButtonLabel: is_bypass
        ? __('Place Order', 'quantum-gateway')
        : checkout_type === 'pre_authorize'
        ? __('Authorize Payment', 'quantum-gateway')
        : __('Pay Securely', 'quantum-gateway'),
};

registerPaymentMethod(QuantumOptions);
