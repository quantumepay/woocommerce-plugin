<?php
if (!defined('ABSPATH')) {
    exit;
}

get_header();
wp_enqueue_style('quantumepay-style', WC_QUANTUMEPAY_PLUGIN_URL . '/assets/css/quantumepay.css', [], WC_QUANTUMEPAY_DEBUG_MODE == 'development' ? time() : WC_QUANTUMEPAY_VERSION);
?>

<div class="email-page">
    <div class="quantumepay-card">
        <h2 class="text-2xl font-bold mb-6">Pay for Order #<?php echo esc_html($order_id); ?></h2>

        <div class="mb-6">
            <p>Total Amount: <strong>$<?php echo esc_html($form_data['item_cost']); ?></strong></p>
            <p>Order Description: <strong><?php echo esc_html($form_data['item_description']); ?></strong></p>
            <p>Quantity: <strong><?php echo esc_html($form_data['item_qty']); ?></strong></p>
        </div>

        <div class="mb-6">
            <h3 class="text-lg font-medium mb-3">Order Items</h3>
            <ul>
                <?php foreach ($order->get_items() as $item) {
                    $product = $item->get_product();
                ?>
                    <li>
                        <?php echo esc_html($product->get_name()); ?> × <?php echo esc_html($item->get_quantity()); ?>
                        (<?php echo wc_price($item->get_total()); ?>)
                    </li>
                <?php } ?>
            </ul>
        </div>

        <form id="payment-form" method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="qp_process_payment">
            <input type="hidden" name="order_id" value="<?php echo esc_attr($order_id); ?>">
            <input type="hidden" name="qp_slug" value="<?php echo esc_attr($slug); ?>">

            <!-- Card Number -->
            <div class="quantumepay-form-group">
                <input type="text" name="card_number" id="card_number" class="quantumepay-form-control" placeholder=" " required maxlength="19">
                <label for="card_number" class="quantumepay-form-label">Card Number</label>
                <div id="card_number_error" class="error hidden"></div>
            </div>

            <!-- Expiry + CVC -->
            <div class="quantumepay-form-row">
                <div class="quantumepay-form-group quantumepay-half-width">
                    <input type="text" name="card_expiry" id="card_expiry" class="quantumepay-form-control" placeholder=" " required maxlength="5">
                    <label for="card_expiry" class="quantumepay-form-label">Expiry (MM/YY)</label>
                    <div id="card_expiry_error" class="error hidden"></div>
                </div>
                <div class="quantumepay-form-group quantumepay-half-width">
                    <input type="text" name="card_cvc" id="card_cvc" class="quantumepay-form-control" placeholder=" " required maxlength="4">
                    <label for="card_cvc" class="quantumepay-form-label">CVC</label>
                    <div id="card_cvc_error" class="error hidden"></div>
                </div>
            </div>

            <!-- Submit Button -->
            <button type="submit" class="quantumepay-btn">
                Pay Now
            </button>
        </form>
    </div>
</div>


<?php get_footer(); // ✅ WordPress footer 
?>
<script>
    document.addEventListener("DOMContentLoaded", function() {
        const cardNumberInput = document.getElementById("card_number");
        const expiryInput = document.getElementById("card_expiry");
        const cvcInput = document.getElementById("card_cvc");

        // ✅ Format card number (1234 5678 9012 3456)
        cardNumberInput.addEventListener("input", function(e) {
            let value = e.target.value.replace(/\D/g, ""); // sirf digits
            if (value.length > 16) value = value.slice(0, 16); // max 16
            let formatted = value.replace(/(\d{4})(?=\d)/g, "$1 "); // har 4 digit ke baad space
            e.target.value = formatted;
        });

        // ✅ Format expiry (MM/YY)
        expiryInput.addEventListener("input", function(e) {
            let value = e.target.value.replace(/\D/g, ""); // sirf digits
            if (value.length > 4) value = value.slice(0, 4);
            if (value.length >= 3) {
                value = value.replace(/(\d{2})(\d{1,2})/, "$1/$2");
            }
            e.target.value = value;
        });

        // ✅ CVC only digits (3 or 4)
        cvcInput.addEventListener("input", function(e) {
            let value = e.target.value.replace(/\D/g, "");
            if (value.length > 4) value = value.slice(0, 4);
            e.target.value = value;
        });
    });
</script>