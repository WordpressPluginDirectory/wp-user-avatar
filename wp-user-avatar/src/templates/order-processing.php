<?php /** @global $order_success_page */ ?>
<div id="ppress-payment-processing">
    <p><?php echo apply_filters('ppress_payment_processing_message', sprintf(__('Your order is processing. This page will reload automatically in 8 seconds. If it does not, click <a href="%s">here</a>.', 'wp-user-avatar'), esc_url($order_success_page)), $order_success_page); ?>
        <script type="text/javascript">setTimeout(function () {
                window.location = '<?php echo $order_success_page; ?>';
            }, 8000);
        </script>
</div>

<?php do_action('ppress_payment_processing'); ?>
