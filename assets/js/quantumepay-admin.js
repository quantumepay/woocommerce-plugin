jQuery(function ($) {
    const $mainForm = $('#mainform');
    const $nativeTable = $('.woocommerce table.form-table').first();
    if (!$mainForm.length || !$nativeTable.length || $('.qep-settings-app').length) {
        return;
    }

    let qepSettingsDirty = false;
    window.onbeforeunload = null;
    $(window).off('beforeunload');
    window.addEventListener('beforeunload', function (event) {
        if (!qepSettingsDirty) {
            return;
        }
        event.preventDefault();
        event.returnValue = '';
    });

    function getField(fieldName) {
        return $mainForm.find('[id$="_' + fieldName + '"], ' + '[name$="_' + fieldName + '"], ' + '[name$="[' + fieldName + ']"]').first();
    }

    function getRow(fieldName) {
        const $field = getField(fieldName);
        if (!$field.length) {
            return $();
        }
        return $field.closest('tr');
    }

    function createTable() {
        return $('<table class="form-table qep-settings-table" role="presentation">' + '<tbody></tbody>' + '</table>');
    }

    function moveFields(fields, $table) {
        fields.forEach(function (fieldName) {
            const $row = getRow(fieldName);
            if ($row.length) {
                $table.find('tbody').append($row);
            }
        });

    }

    [
        'test_terminal_key',
        'test_client_id',
        'test_client_secret'
    ].forEach(function (fieldName) {
        const $row = getRow(fieldName);
        if ($row.length) {
            $row.attr('data-qep-hidden-test-field', fieldName);
        }
    });

    const $app = $('<div class="qep-settings-app"></div>');
    const pluginVersion = typeof qepAdminSettings !== 'undefined' &&
            qepAdminSettings.version
            ? qepAdminSettings.version
            : '';
    const $sidebar = $(
        '<aside class="qep-settings-sidebar">' +
        '<div class="qep-settings-nav">' +
        '<button type="button" class="qep-settings-nav__item is-active" data-qep-tab="gateway">' +
        '<span class="dashicons dashicons-admin-network"></span>' +
        '<span>Gateway Setup</span>' +
        '</button>' +
        '<button type="button" class="qep-settings-nav__item" data-qep-tab="checkout">' +
        '<span class="dashicons dashicons-cart"></span>' +
        '<span>Checkout Settings</span>' +
        '</button>' +
        '<button type="button" class="qep-settings-nav__item" data-qep-tab="support">' +
        '<span class="dashicons dashicons-sos"></span>' +
        '<span>Support &amp; Help</span>' +
        '</button>' +
        '</div>' +
        (pluginVersion ? '<div class="qep-settings-version">' + '<span>Quantum ePay</span>' + '<strong>v' + pluginVersion + '</strong>' + '</div>' : '') +
        '</aside>'
    );
    const $content = $('<main class="qep-settings-content"></main>');
    const $gatewayPanel = $(
        '<section class="qep-settings-panel is-active" data-qep-panel="gateway">' +
        '<div class="qep-panel-header">' +
        '<h2>Gateway Setup</h2>' +
        '<p>' +
        'Configure your Quantum ePay connection and choose whether the gateway operates in Live or Test Mode.' +
        '</p>' +
        '</div>' +
        '</section>'
    );
    const $gatewayControls = $('<div class="qep-gateway-controls"></div>');
    const $enableCard = $('<div class="qep-card qep-control-card"></div>');
    const $enableTable = createTable();
    moveFields(['enabled'], $enableTable);
    $enableCard.append($enableTable);
    const $modeCard = $('<div class="qep-card qep-control-card"></div>');
    const $modeTable = createTable();
    moveFields(['testmode'], $modeTable);
    $modeCard.append($modeTable);
    $gatewayControls.append($enableCard, $modeCard);
    $gatewayPanel.append($gatewayControls);
    const $liveCard = $(
        '<div class="qep-card qep-credential-card qep-credential-card--live">' +
        '<div class="qep-credential-header">' +
        '<div class="qep-credential-heading">' +
        '<div class="qep-credential-icon qep-credential-icon--live">' +
        '<span class="dashicons dashicons-shield"></span>' +
        '</div>' +
        '<div>' +
        '<h3>Live API Credentials</h3>' +
        '<p>Used when Test Mode is disabled.</p>' +
        '</div>' +
        '</div>' +
        '<span class="qep-mode-badge qep-mode-badge--live">' +
        'LIVE' +
        '</span>' +
        '</div>' +
        '</div>'
    );
    const $liveTable = createTable();
    moveFields([ 'terminal_key', 'client_id', 'client_secret' ], $liveTable);
    $liveCard.append($liveTable);
    $liveCard.append(
        '<div class="qep-info-box qep-info-box--live">' +
        '<span class="dashicons dashicons-lock"></span>' +
        '<span>' +
        'Live credentials are used only when Test Mode is disabled.' +
        '</span>' +
        '</div>'
    );
    $gatewayPanel.append($liveCard);
    const $gatewaySaveBar = $(
        '<div class="qep-save-bar">' +
        '<span class="qep-save-status" data-qep-status="gateway"></span>' +
        '<button type="button" class="button button-primary qep-save-button" data-qep-save="gateway">' +
        'Save Changes' +
        '</button>' +
        '</div>'
    );
    $gatewayPanel.append($gatewaySaveBar);
    const $checkoutPanel = $(
        '<section class="qep-settings-panel" data-qep-panel="checkout">' +
        '<div class="qep-panel-header">' +
        '<h2>Checkout Settings</h2>' +
        '<p>' +
        'Control how Quantum ePay appears to customers and manage payment timeout notifications.' +
        '</p>' +
        '</div>' +
        '</section>'
    );
    const $checkoutCard = $('<div class="qep-card qep-checkout-card"></div>');
    const serviceFeeFields = [
        'service_fee_mode',
        'service_fee_label',
        'service_fee_type',
        'service_fee_amount',
        'service_fee_taxable'
    ];
    const $checkoutTable = createTable();
    moveFields([ 'title', 'description', 'timeout_notification_recipients' ], $checkoutTable);
    moveFields(serviceFeeFields, $checkoutTable);
    $checkoutCard.append($checkoutTable);
    $checkoutPanel.append($checkoutCard);
    const $checkoutSaveBar = $(
        '<div class="qep-save-bar">' +
        '<span class="qep-save-status" data-qep-status="checkout"></span>' +
        '<button type="button" class="button button-primary qep-save-button" data-qep-save="checkout">' +
        'Save Changes' +
        '</button>' +
        '</div>'
    );
    $checkoutPanel.append($checkoutSaveBar);
    const $supportPanel = $(
        '<section class="qep-settings-panel" data-qep-panel="support">' +
        '<div class="qep-panel-header">' +
        '<h2>Support &amp; Help</h2>' +
        '<p>' +
        'Need help configuring Quantum ePay? Contact our support team.' +
        '</p>' +
        '</div>' +
        '</section>'
    );
    const $supportIntro = $(
        '<div class="qep-card qep-support-intro">' +
        '<div class="qep-support-intro__inner">' +
        '<div class="qep-support-icon">' +
        '<span class="dashicons dashicons-sos"></span>' +
        '</div>' +
        '<div>' +
        '<h3>Quantum ePay Support</h3>' +
        '<p>' +
        'Our support team can assist with merchant configuration, API credentials, WooCommerce setup, and payment processing questions.' +
        '</p>' +
        '</div>' +
        '</div>' +
        '</div>'
    );
    const $supportGrid = $(
        '<div class="qep-support-grid">' +
        '<a class="qep-card qep-contact-card" href="mailto:support@quantumepay.com">' +
        '<div class="qep-contact-card__icon">' +
        '<span class="dashicons dashicons-email-alt"></span>' +
        '</div>' +
        '<div class="qep-contact-card__text">' +
        '<span class="qep-contact-card__label">Email Support</span>' +
        '<strong>support@quantumepay.com</strong>' +
        '</div>' +
        '<span class="dashicons dashicons-arrow-right-alt2 qep-contact-card__arrow"></span>' +
        '</a>' +
        '<a class="qep-card qep-contact-card" href="tel:+18888581678">' +
        '<div class="qep-contact-card__icon">' +
        '<span class="dashicons dashicons-phone"></span>' +
        '</div>' +
        '<div class="qep-contact-card__text">' +
        '<span class="qep-contact-card__label">Phone Support</span>' +
        '<strong>(888) 858-1678 ext. 100</strong>' +
        '</div>' +
        '<span class="dashicons dashicons-arrow-right-alt2 qep-contact-card__arrow"></span>' +
        '</a>' +
        '</div>'
    );
    $supportPanel.append($supportIntro, $supportGrid);
    $nativeTable.find('tbody > tr').each(function () {
        const $row = $(this);
        if ($row.is('[data-qep-hidden-test-field]')) {
            return;
        }
        $checkoutTable
            .find('tbody').append($row);
    });

    $content.append($gatewayPanel, $checkoutPanel, $supportPanel);
    $app.append($sidebar, $content);
    $nativeTable.before($app);
    $nativeTable.addClass('qep-native-settings-hidden');
    $('body').addClass('qep-settings-ready');

    function activateTab(tabName) {
        $('.qep-settings-nav__item').removeClass('is-active');
        $('.qep-settings-panel').removeClass('is-active');
        $('.qep-settings-nav__item[data-qep-tab="' + tabName + '"]').addClass('is-active');
        $('.qep-settings-panel[data-qep-panel="' + tabName + '"]').addClass('is-active');
        try {
            localStorage.setItem('qep_admin_active_tab', tabName);
        } catch (e) { }
    }

    $sidebar.on(
        'click',
        '.qep-settings-nav__item',
        function () {
            activateTab($(this).data('qep-tab'));
        }
    );
    try {
        const savedTab = localStorage.getItem('qep_admin_active_tab');
        if (savedTab && $('.qep-settings-nav__item[data-qep-tab="' + savedTab + '"]').length) {
            activateTab(savedTab);
        }
    } catch (e) { }
    [
        'client_id',
        'client_secret'
    ].forEach(function (fieldName) {
        const $input = getField(fieldName);
        if (!$input.length) {
            return;
        }
        if ($input.parent('.qep-password-wrap').length) {
            return;
        }
        $input.wrap('<div class="qep-password-wrap"></div>');
        const $button = $(
            '<button type="button" class="qep-password-toggle" aria-label="Show credential" aria-pressed="false" style="display:none;">' +
            '<span class="dashicons dashicons-visibility"></span>' +
            '</button>'
        );
        $input.after($button);

        function resetVisibility() {
            $input.attr('type', 'password');
            $button
                .attr('aria-label', 'Show credential').attr('aria-pressed', 'false');
            $button
                .find('.dashicons').removeClass('dashicons-hidden').addClass('dashicons-visibility');
        }
        $input.on(
            'input',
            function () {
                const hasNewValue = $.trim($(this).val()).length > 0;
                if (hasNewValue) {
                    $button.show();
                } else {
                    $button.hide();
                    resetVisibility();
                }
            }
        );
        $button.on(
            'click',
            function () {
                const isVisible = $input.attr('type') === 'text';
                $input.attr('type', isVisible ? 'password' : 'text');
                $(this).attr('aria-label', isVisible ? 'Show credential' : 'Hide credential').attr('aria-pressed', isVisible ? 'false' : 'true');
                $(this).find('.dashicons').toggleClass('dashicons-visibility', isVisible).toggleClass('dashicons-hidden', !isVisible);
            }
        );
    });

    $mainForm.on(
        'input change',
        '.qep-settings-app input, .qep-settings-app textarea, .qep-settings-app select',
        function () {
            qepSettingsDirty = true;
        }
    );
    const gatewayFields = [
        'enabled',
        'testmode',
        'terminal_key',
        'client_id',
        'client_secret'
    ];
    const checkoutFields = [
        'title',
        'description',
        'timeout_notification_recipients',
        ...serviceFeeFields
    ];

    function updateServiceFeeFields(animate) {
        const custom = getField('service_fee_mode').val() === 'custom';
        serviceFeeFields.slice(1).forEach(function (fieldName) {
            const $row = getRow(fieldName);
            $row.stop(true, true);
            if (animate) {
                custom ? $row.fadeIn(150) : $row.fadeOut(150);
            } else {
                $row.toggle(custom);
            }
        });

    }

    getField('service_fee_mode').on('change.qepServiceFeeSettings', function () {
        updateServiceFeeFields(true);
    });

    updateServiceFeeFields(false);

    function getFieldValue(fieldName) {
        const $field = getField(fieldName);
        if (!$field.length) {
            return '';
        }
        if ($field.is(':checkbox')) {
            return $field.is(':checked')
                ? 'yes'
                : 'no';
        }
        return $field.val();
    }

    function setSaveState(section, state, message) {
        const $button = $('[data-qep-save="' + section + '"]');
        const $status = $('[data-qep-status="' + section + '"]');
        $status.removeClass('is-visible is-success is-error');
        if (state === 'saving') {
            $button
                .addClass('is-saving').prop('disabled', true).text('Saving...');
            $status
                .addClass('is-visible').text('Saving changes...');
            return;
        }
        $button
            .removeClass('is-saving').prop('disabled', false).text('Save Changes');
        if (state === 'success') {
            $status
                .addClass('is-visible is-success').html('<span class="dashicons dashicons-yes-alt"></span>' + '<span>' + message + '</span>');
            return;
        }
        if (state === 'error') {
            $status
                .addClass('is-visible is-error').html('<span class="dashicons dashicons-warning"></span>' + '<span>' + message + '</span>');
        }
    }

    function saveSection(section, fields) {
        if (typeof qepAdminSettings === 'undefined') {
            setSaveState(section, 'error', 'AJAX configuration is unavailable.');
            return;
        }
        const settings = {};
        fields.forEach(function (fieldName) {
            settings[fieldName] = getFieldValue(fieldName);
        });

        setSaveState(section, 'saving');
        $.ajax({
            url: qepAdminSettings.ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'qep_save_gateway_settings',
                nonce: qepAdminSettings.nonce,
                settings: settings
            }
        }).done(function (response) {
                if (response && response.success) {
                    qepSettingsDirty = false;
                    window.onbeforeunload = null;
                    $(window).off('beforeunload');
                    [
                        'client_id',
                        'client_secret'
                    ].forEach(function (fieldName) {
                        const $field = getField(fieldName);
                        if (!$field.length) {
                            return;
                        }
                        if ($.trim($field.val()).length) {
                            $field
                                .val('').attr('placeholder', 'Saved — enter a new value to replace').attr('data-qep-saved', '1').attr('type', 'password');
                            $field
                                .siblings('.qep-password-toggle').hide().attr('aria-label', 'Show credential').attr('aria-pressed', 'false').find('.dashicons').removeClass('dashicons-hidden').addClass('dashicons-visibility');
                        }
                    });

                    const message = response.data &&
                            response.data.message
                            ? response.data.message
                            : 'Settings saved.';
                    setSaveState(section, 'success', message);
                    return;
                }
                const message = response &&
                        response.data &&
                        response.data.message
                        ? response.data.message
                        : 'Settings could not be saved.';
                setSaveState(section, 'error', message);
            }).fail(function (xhr) {
                let message = 'Settings could not be saved. Please try again.';
                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    message = xhr.responseJSON.data.message;
                }
                setSaveState(section, 'error', message);
            });

    }

    $gatewayPanel.on(
        'click',
        '[data-qep-save="gateway"]',
        function () {
            saveSection('gateway', gatewayFields);
        }
    );
    $checkoutPanel.on(
        'click',
        '[data-qep-save="checkout"]',
        function () {
            saveSection('checkout', checkoutFields);
        }
    );
});
