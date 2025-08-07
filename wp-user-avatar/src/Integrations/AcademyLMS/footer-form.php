<?php

if ( ! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
global $authordata;
$course_id  = get_the_ID();
$card_style = \Academy\Helper::get_settings('course_card_style');

$plan = ppress_get_plan($course_id);
?>

<?php if ('layout_two' === $card_style) :
    Academy\Helper::get_template(
        'loop/author.php'
    );
endif; ?>

<form class="ppress-academy-wrap academy-widget-enroll__enroll-form" method="get" action="<?php echo esc_url($plan->get_checkout_url()); ?>">
    <input type="hidden" name="plan" value="<?php esc_attr_e($plan->get_id()); ?>">
    <button type="submit" class="academy-btn academy-btn--bg-purple">
        <?php esc_html_e('Subscribe Now', 'profilepress-pro'); ?>
    </button>
</form>
