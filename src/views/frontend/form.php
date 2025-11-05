<?php
echo '<fieldset id="wc-' . esc_attr( $this->id ) . '-cc-form" class="wc-credit-card-form wc-payment-form" style="background:transparent;">';

do_action( 'woocommerce_credit_card_form_start', $this->id );

echo '<div class="form-row form-row-wide"><label>Card Number <span class="required">*</span></label>
    <input id="qp_ccNo" name="qp_ccNo" type="text" pattern="[0-9]*" autocomplete="off">
    </div>
    <div class="form-row form-row-first">
        <label>Expiry Date <span class="required">*</span></label>
        <input id="qp_expdate" name="qp_expdate" type="text" autocomplete="off" placeholder="MM / YY">
    </div>
    <div class="form-row form-row-last">
        <label>Card Code (CVC) <span class="required">*</span></label>
        <input id="qp_cvv" name="qp_cvv" type="text" pattern="[0-9]*" autocomplete="off" placeholder="CVC">
    </div>
    <div class="clear"></div>';

do_action( 'woocommerce_credit_card_form_end', $this->id );

echo '<div class="clear"></div></fieldset>';