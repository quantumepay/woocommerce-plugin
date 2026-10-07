(function () {
    'use strict';
    if (!window.wc || !window.wp || !wc.wcBlocksRegistry || !wc.wcSettings) return;
    const { createElement: el, useEffect, useRef, useState } = wp.element;
    const settings = wc.wcSettings.getSetting('quantumepay_data', {});
    const decode = wp.htmlEntities.decodeEntities;
    const title = decode(settings.title || 'Credit card');
    const isEditor = document.body.classList.contains('wp-admin');
    let lastQueuedMethod = null;
    let lastAppliedMethod = null;
    let syncFailed = false;
    let feeQueue = Promise.resolve(true);

    function activeMethod() {
        const store = wp.data.select('wc/store/payment');
        return store && typeof store.getActivePaymentMethod === 'function' ? store.getActivePaymentMethod() : '';
    }

    function syncPaymentMethod(method, retry) {
        if (isEditor || !settings.custom_service_fee) return Promise.resolve(true);
        if (typeof method !== 'string' || !method) return Promise.resolve(false);
        if (method === lastQueuedMethod && !retry) return feeQueue;
        lastQueuedMethod = method;
        feeQueue = feeQueue.then(async function () {
            if (method !== lastQueuedMethod) return true;
            try {
                await wc.blocksCheckout.extensionCartUpdate({ namespace: 'quantumepay-payment-method', data: { payment_method: method } });
                lastAppliedMethod = method;
                syncFailed = false;
                return true;
            } catch (error) {
                syncFailed = true;
                if (wc.wcBlocksData && typeof wc.wcBlocksData.processErrorResponse === 'function') wc.wcBlocksData.processErrorResponse(error);
                return false;
            }
        });
        return feeQueue;
    }

    async function ensureFeeReady() {
        if (!settings.custom_service_fee || isEditor) return true;
        const cartStore = wp.data.select('wc/store/cart');
        if (cartStore && typeof cartStore.getCartData === 'function' && cartStore.getCartData().needs_payment === false) return true;
        const method = activeMethod();
        const ready = await syncPaymentMethod(method, syncFailed);
        return ready && !syncFailed && lastAppliedMethod === activeMethod();
    }

    if (!isEditor && settings.custom_service_fee) {
        wp.data.subscribe(function () {
            const method = activeMethod();
            if (method && method !== lastQueuedMethod) syncPaymentMethod(method, false);
        });
        const method = activeMethod();
        if (method) syncPaymentMethod(method, false);
        if (wc.blocksCheckoutEvents && typeof wc.blocksCheckoutEvents.onCheckoutValidation === 'function') {
            wc.blocksCheckoutEvents.onCheckoutValidation(async function () {
                return await ensureFeeReady() ? true : { errorMessage: 'Your checkout total could not be updated. Refresh the page before placing your order.' };
            });
        }
    }

    function validateCard(card) {
        const number = card.number.replace(/[\s-]/g, '');
        if (!/^\d{12,19}$/.test(number)) return 'Please enter a valid card number.';
        const match = card.expiry.replace(/\s/g, '').match(/^(\d{1,2})\/(\d{2}|\d{4})$/);
        if (!match) return 'Please enter your card expiry as MM / YY.';
        const month = Number(match[1]);
        const year = Number(match[2]) + (match[2].length === 2 ? 2000 : 0);
        const now = new Date();
        if (month < 1 || month > 12 || year < now.getFullYear() || (year === now.getFullYear() && month < now.getMonth() + 1)) return 'Please enter a current card expiry date.';
        if (!/^\d{3,4}$/.test(card.cvc)) return 'Please enter a valid card security code.';
        return '';
    }

    function CardForm(props) {
        const card = useRef({ number: '', expiry: '', cvc: '' });
        const [fields, setFields] = useState(card.current);
        const [error, setError] = useState('');
        const { eventRegistration, emitResponse } = props;

        useEffect(function () {
            if (isEditor || !eventRegistration || !emitResponse) return;
            const unsubscribe = eventRegistration.onPaymentSetup(async function () {
                if (props.activePaymentMethod !== 'quantumepay') return true;
                const message = validateCard(card.current);
                setError(message);
                if (message) return { type: emitResponse.responseTypes.ERROR, message: message, messageContext: emitResponse.noticeContexts.PAYMENTS };
                if (!await ensureFeeReady()) return { type: emitResponse.responseTypes.ERROR, message: 'Your checkout total could not be updated. Refresh the page before placing your order.', messageContext: emitResponse.noticeContexts.PAYMENTS };
                return { type: emitResponse.responseTypes.SUCCESS, meta: { paymentMethodData: {
                    'quantumepay-card-number': card.current.number.replace(/[\s-]/g, ''),
                    'quantumepay-card-expiry': card.current.expiry,
                    'quantumepay-card-cvc': card.current.cvc
                } } };
            });
            return unsubscribe;
        }, [eventRegistration, emitResponse, props.activePaymentMethod]);

        useEffect(function () {
            return function () { card.current = { number: '', expiry: '', cvc: '' }; };
        }, []);

        function update(field, value) {
            const limits = { number: 25, expiry: 9, cvc: 4 };
            const cleaned = value.replace(field === 'expiry' ? /[^\d\s/]/g : /[^\d\s-]/g, '').slice(0, limits[field]);
            card.current = Object.assign({}, card.current, { [field]: cleaned });
            setFields(card.current);
            if (error) setError('');
        }

        function field(key, label, placeholder, autocomplete, type) {
            return el('label', { style: { display: 'block', flex: '1 1 140px', fontSize: '14px', color: '#253247' } },
                el('span', { style: { display: 'block', marginBottom: '6px', fontWeight: 500 } }, label),
                el('input', { type: type || 'text', inputMode: key === 'expiry' ? 'text' : 'numeric', autoComplete: autocomplete,
                    name: 'quantumepay-card-' + (key === 'number' ? 'number' : key === 'expiry' ? 'expiry' : 'cvc'),
                    value: fields[key], placeholder: placeholder, disabled: isEditor, required: true,
                    'aria-invalid': error ? 'true' : undefined, 'aria-describedby': error ? 'qep-block-card-error' : undefined,
                    onChange: function (event) { update(key, event.target.value); },
                    style: { boxSizing: 'border-box', width: '100%', minHeight: '44px', padding: '10px 12px', border: '1px solid #cdd5df', borderRadius: '6px', background: '#fff', color: '#253247', fontSize: '16px' } }));
        }

        return el('div', { className: 'qep-block-card-form' },
            settings.description ? el('p', { style: { margin: '0 0 16px', color: '#596577', fontSize: '14px', lineHeight: 1.5 } }, decode(settings.description)) : null,
            field('number', 'Card number', 'Card number', 'cc-number'),
            el('div', { style: { display: 'flex', flexWrap: 'wrap', gap: '14px', marginTop: '14px' } },
                field('expiry', 'Expiry date', 'MM / YY', 'cc-exp'), field('cvc', 'Security code', 'CVC', 'cc-csc', 'password')),
            error ? el('p', { id: 'qep-block-card-error', role: 'alert', style: { margin: '12px 0 0', color: '#a54545', fontSize: '14px' } }, error) : null);
    }

    function Label(props) {
        return el(props.components.PaymentMethodLabel, { text: title });
    }

    wc.wcBlocksRegistry.registerPaymentMethod({ name: 'quantumepay', paymentMethodId: 'quantumepay',
        label: el(Label), content: el(CardForm), edit: el(CardForm), ariaLabel: title,
        canMakePayment: function () { return true; }, supports: { features: settings.supports || ['products'] } });
})();
