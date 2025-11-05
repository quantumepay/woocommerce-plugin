<?php

/**
 * Quantum Payment Request Email Template
 *
 * @var WC_Order $order
 * @var string   $payment_url
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div style="max-width:600px;margin:0 auto;background:#ffffff;
            border-radius:8px;padding:30px;
            box-shadow:0 4px 10px rgba(0,0,0,0.08);">

    <!-- Greeting -->
    <p style="font-size:16px;margin:0 0 20px;">
        <?php printf(__('Hi %s,', 'qoin-payment-gateway'), esc_html($order->get_billing_first_name())); ?>
    </p>

    <p style="margin:0 0 20px;font-size:14px;color:#555;">
        <?php printf(
            __('We kindly request you to complete the payment for your order <strong>#%s</strong>.', 'qoin-payment-gateway'),
            $order->get_id()
        ); ?>
    </p>

    <!-- Order Details -->
    <h2 style="margin:20px 0 10px;font-size:18px;color:#333;border-bottom:1px solid #eee;padding-bottom:8px;">
        <?php _e('Order Summary', 'qoin-payment-gateway'); ?>
    </h2>
    <table style="width:100%;border-collapse:collapse;margin-bottom:20px;">
        <thead>
            <tr>
                <th align="left" style="border-bottom:1px solid #eee;padding:8px;"><?php _e('Product', 'qoin-payment-gateway'); ?></th>
                <th align="center" style="border-bottom:1px solid #eee;padding:8px;"><?php _e('Quantity', 'qoin-payment-gateway'); ?></th>
                <th align="right" style="border-bottom:1px solid #eee;padding:8px;"><?php _e('Total', 'qoin-payment-gateway'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($order->get_items() as $item) :
                $product = $item->get_product();
            ?>
                <tr>
                    <td style="padding:8px;border-bottom:1px solid #f5f5f5;">
                        <?php echo esc_html($product->get_name()); ?>
                    </td>
                    <td align="center" style="padding:8px;border-bottom:1px solid #f5f5f5;">
                        <?php echo esc_html($item->get_quantity()); ?>
                    </td>
                    <td align="right" style="padding:8px;border-bottom:1px solid #f5f5f5;">
                        <?php echo wp_kses_post($order->get_formatted_line_subtotal($item)); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Billing & Shipping -->
    <h2 style="margin:20px 0 10px;font-size:18px;color:#333;"><?php _e('Billing Address', 'qoin-payment-gateway'); ?></h2>
    <p style="margin:0 0 20px;font-size:14px;color:#555;">
        <?php echo wp_kses_post($order->get_formatted_billing_address()); ?><br>
        <?php echo esc_html($order->get_billing_phone()); ?><br>
        <?php echo esc_html($order->get_billing_email()); ?>
    </p>

    <?php if ($order->get_formatted_shipping_address()) : ?>
        <h2 style="margin:20px 0 10px;font-size:18px;color:#333;"><?php _e('Shipping Address', 'qoin-payment-gateway'); ?></h2>
        <p style="margin:0 0 20px;font-size:14px;color:#555;">
            <?php echo wp_kses_post($order->get_formatted_shipping_address()); ?>
        </p>
    <?php endif; ?>

    <!-- Order Total -->
    <h2 style="margin:20px 0 10px;font-size:18px;color:#333;">
        <?php _e('Order Total', 'qoin-payment-gateway'); ?>:
        <?php echo wp_kses_post($order->get_formatted_order_total()); ?>
    </h2>

    <!-- Pay Now Button -->
    <div style="text-align:center;margin:30px 0;">
        <a href="<?php echo esc_url($payment_url); ?>"
            style="background:#96588a;color:#ffffff;
                  padding:14px 32px;border-radius:5px;
                  text-decoration:none;font-weight:600;
                  display:inline-block;font-size:16px;">
            <?php _e('Pay Now', 'qoin-payment-gateway'); ?>
        </a>
    </div>

    <p style="font-size:14px;color:#777;margin-top:20px;">
        <?php _e("If you have any questions, simply reply to this email and we'll be happy to help.", 'qoin-payment-gateway'); ?>
    </p>
</div>