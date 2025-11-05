(function($) {
    'use strict';

    class QoinAdminSettings {
        constructor() {
            this.init();
        }

        init() {
            this.initEyeToggle();
            this.initTabFunctionality();
        }

        initEyeToggle() {
            // For each sensitive input
            $('input.qp-sensitive-field').each(function() {
                const $input = $(this);

                // Skip if readonly
                if ($input.is('[readonly]')) return;

                // Wrap for positioning
                if (!$input.parent().hasClass('qp-password-wrapper')) {
                    $input.wrap('<div class="qp-password-wrapper" style="position: relative; display: inline-block; width: 100%;"></div>');
                }

                // Add eye icon
                if (!$input.siblings('.qp-eye-toggle').length) {
                    const $toggle = $('<span class="qp-eye-toggle dashicons dashicons-visibility" title="Show password"></span>');
                    $input.after($toggle);
                }

                const $toggle = $input.siblings('.qp-eye-toggle');

                // Adjust padding for icon space
                $input.css({
                    'padding-right': '32px',
                    'box-sizing': 'border-box'
                });

                // Select all text on focus for easy copy-paste
                $input.on('focus', function() {
                    $(this).select();
                    // Show eye icon when focused
                    $toggle.addClass('qp-visible');
                });

                // Toggle visibility
                $toggle.on('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();

                    const isPassword = $input.attr('type') === 'password';
                    $input.attr('type', isPassword ? 'text' : 'password');

                    // Toggle icon state
                    if (isPassword) {
                        $(this)
                            .removeClass('dashicons-visibility')
                            .addClass('dashicons-hidden')
                            .attr('title', 'Hide password');
                    } else {
                        $(this)
                            .removeClass('dashicons-hidden')
                            .addClass('dashicons-visibility')
                            .attr('title', 'Show password');
                    }

                    // Keep eye visible after toggle
                    $toggle.addClass('qp-visible');
                });

                // Show eye icon on any typing interaction
                $input.on('input keydown keyup paste cut', function() {
                    $toggle.addClass('qp-visible');
                });

                // Hide eye icon when input loses focus
                $input.on('blur', function() {
                    $toggle.removeClass('qp-visible');
                });

                // Hide eye icons for all fields when form is submitted
                $('#mainform').on('submit', function() {
                    $('.qp-eye-toggle').removeClass('qp-visible');
                });

                // Ensure hidden initially
                $toggle.removeClass('qp-visible');
            });
        }

        initTabFunctionality() {
            // Create tabs HTML
            const tabsHTML = `
                <div class="qp-credentials-tabs">
                    <nav class="qp-tabs-nav">
                        <button type="button" class="qp-tab-button active" data-tab="live">Live Credentials</button>
                        <button type="button" class="qp-tab-button" data-tab="staging">Staging Credentials</button>
                    </nav>
                </div>
            `;
            
            // Insert tabs after the credentials title
            $('.qp-credentials-tabs-container').after(tabsHTML);
            
            // Initial tab setup - show Staging tab if Test Mode is enabled, otherwise Live
            const initialTab = $('#woocommerce_quantumepay_testmode').is(':checked') ? 'staging' : 'live';
            this.switchTab(initialTab);
            
            // Tab button click handler - only switches tabs, doesn't affect test mode
            $('.qp-tab-button').on('click', (e) => {
                const tab = $(e.currentTarget).data('tab');
                this.switchTab(tab);
            });
            
            // Remove the test mode checkbox change handler that was switching tabs
            // The test mode checkbox now only controls the actual gateway mode
            // and doesn't affect which tab is visible for editing credentials
        }

        switchTab(tab) {
            // Update button states
            $('.qp-tab-button').removeClass('active');
            $(`.qp-tab-button[data-tab="${tab}"]`).addClass('active');
            
            // Hide all credential-related elements first
            
            // 1. Hide section titles (h3 elements)
            $('h3.qp-credentials-section').hide();
            
            // 2. Hide section descriptions (p elements that follow the h3)
            $('h3.qp-credentials-section').next('p').hide();
            
            // 3. Hide credential fields (table rows)
            $('.qp-credentials-field').closest('tr').hide();
            
            // Show only the active tab's elements
            
            // 1. Show active tab's section title (h3)
            $(`h3.qp-${tab}-credentials`).show();
            
            // 2. Show active tab's section description (p that follows the h3)
            $(`h3.qp-${tab}-credentials`).next('p').show();
            
            // 3. Show active tab's credential fields (table rows)
            $(`.qp-${tab}-credentials.qp-credentials-field`).closest('tr').show();
            
            // REMOVED: No longer updating test mode checkbox based on tab selection
            // The test mode checkbox remains independent
        }
    }

    // Initialize when document is ready
    $(document).ready(() => {
        new QoinAdminSettings();
    });

})(jQuery);