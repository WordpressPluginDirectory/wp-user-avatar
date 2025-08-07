<?php

namespace ProfilePress\Core\Integrations\AcademyLMS;

use Academy\Helper;
use ProfilePress\Core\Classes\ExtensionManager as EM;
use ProfilePress\Core\Classes\FormRepository as FR;
use ProfilePress\Core\Membership\Models\Customer\CustomerFactory;
use ProfilePress\Core\Membership\Models\Order\OrderEntity;
use ProfilePress\Core\Membership\Models\Order\OrderFactory;
use ProfilePress\Core\Membership\Models\Subscription\SubscriptionBillingFrequency;
use ProfilePress\Core\Membership\Models\Subscription\SubscriptionEntity;
use ProfilePress\Core\Membership\Models\Subscription\SubscriptionFactory;
use ProfilePress\Core\Membership\Repositories\PlanRepository;

class Init
{
    public static $instance_flag = false;

    public function __construct()
    {
        add_action('plugins_loaded', function () {

            if (class_exists('\Academy')) {

                add_filter('ppress_settings_page_args', [$this, 'settings_page']);

                add_filter('ppress_admin_membership_plan_metabox_settings', [$this, 'plan_edit_screen']);

                add_action('ppress_order_completed', [$this, 'on_order_sub_success']);
                add_action('ppress_subscription_activated', [$this, 'on_order_sub_success']);
                add_action('ppress_subscription_enabled_trial', [$this, 'on_order_sub_success']);

                add_action('ppress_subscription_cancelled', [$this, 'on_subscription_cancelled']);
                add_action('ppress_subscription_expired', [$this, 'on_subscription_cancelled']);

                add_action('ppress_after_registration', [$this, 'after_user_registration'], 10, 4);

                add_filter('ppress_shortcode_builder_registration_meta', [$this, 'save_shortcode_builder_settings']);
                add_action('ppress_shortcode_builder_registration_screen_settings', [$this, 'shortcode_builder_settings']);
                add_filter('ppress_form_builder_meta_box_settings', [$this, 'dnd_builder_settings'], 99);

                add_filter('academy/templates/single_course/enroll_form', [$this, 'course_checkout_link'], 999, 2);
                add_filter('academy/single/enroll_content_args', [$this, 'enroll_content_args'], 999, 2);
                add_filter('academy/template/loop/price_args', [$this, 'price_args'], 999, 2);
                add_filter('academy/get_template', [$this, 'filter_course_loop_cta'], 999, 5);
                // this ensures course can be enrolled if a user has a profilepress subscription whose plan has the course
                add_filter('academy/before_enroll_course_type', [$this, 'modify_course_type'], 999, 2);
            }
        });
    }

    public function after_user_registration($form_id, $user_data, $user_id, $is_melange)
    {
        $global_academylms_courses = ppress_settings_by_key('alms_courses', []);

        if (is_array($global_academylms_courses)) {
            $global_academylms_courses = array_filter($global_academylms_courses);
            if ( ! empty($global_academylms_courses)) {
                foreach ($global_academylms_courses as $course_id) {
                    Helper::do_enroll($course_id, $user_id);
                }
            }
        }

        if ($is_melange === true || ! $form_id) return;

        $form_id = intval($form_id);

        if (FR::is_drag_drop($form_id, FR::REGISTRATION_TYPE)) {
            $academylms_courses = FR::get_dnd_metabox_setting('academylms_courses', $form_id, FR::REGISTRATION_TYPE);
        } else {
            $academylms_courses = FR::get_form_meta($form_id, FR::REGISTRATION_TYPE, 'academylms_courses');
        }

        if (is_array($academylms_courses)) {
            $academylms_courses = array_filter($academylms_courses);
            if ( ! empty($academylms_courses)) {
                foreach ($academylms_courses as $course_id) {
                    Helper::do_enroll($course_id, $user_id);
                }
            }
        }
    }

    /**
     * @param OrderEntity|SubscriptionEntity $order_or_sub
     *
     * @return void
     */
    public function on_order_sub_success($order_or_sub)
    {
        if ($order_or_sub instanceof SubscriptionEntity) {
            $order = OrderFactory::fromId($order_or_sub->get_parent_order_id());
        } else {
            $order = $order_or_sub;
        }

        $plan = $order->get_plan();

        $courses = $plan->get_plan_extras('academylms_courses');

        $user_id = $order->get_customer()->user_id;

        if (is_array($courses)) {
            $courses = array_filter($courses);
            if ( ! empty($courses)) {
                foreach ($courses as $course_id) {
                    if ( ! Helper::is_enrolled($course_id, $user_id)) {
                        Helper::do_enroll($course_id, $user_id);
                    }
                }
            }
        }
    }

    /**
     * @param SubscriptionEntity $subscription
     *
     * @return void
     */
    public function on_subscription_cancelled($subscription)
    {
        // doing SubscriptionFactory call because we want to re-fetch from DB.
        if (SubscriptionFactory::fromId($subscription->id)->is_active()) return;

        $plan = $subscription->get_plan();

        $user_id = $subscription->get_customer()->user_id;

        $courses = $plan->get_plan_extras('academylms_courses');

        if (is_array($courses)) {
            $courses = array_filter($courses);
            if ( ! empty($courses)) {
                foreach ($courses as $course_id) {
                    if (class_exists('\AcademyProEnrollment\Helper') && method_exists('\AcademyProEnrollment\Helper', 'cancel_enroll')) {
                        \AcademyProEnrollment\Helper::cancel_enroll($course_id, $user_id);
                    }
                }
            }
        }
    }

    private function get_courses($key_value = false)
    {
        $args = array(
            'post_type'   => 'academy_courses',
            'post_status' => 'publish',
            'numberposts' => -1,
        );

        $courses = get_posts($args);

        if ($key_value === false) return $courses;

        $options = array();

        foreach ($courses as $course) {
            $options[$course->ID] = $course->post_title;
        }

        return $options;
    }

    /**
     * @param array $args
     *
     * @return array
     */
    public function settings_page($args)
    {
        $args['pp_academylms_settings'] = [
            'tab_title'     => esc_html__('Academy LMS', 'wp-user-avatar'),
            'section_title' => esc_html__('Academy LMS Settings', 'wp-user-avatar'),
            'dashicon'      => 'dashicons-welcome-learn-more',
            'alms_courses'  => array(
                'type'        => 'select2',
                'options'     => $this->get_courses(true),
                'label'       => __('Courses', 'wp-user-avatar'),
                'description' => esc_attr__('Select the Academy LMS courses to enroll users in after user registration.', 'wp-user-avatar'),
            ),
        ];

        return $args;
    }

    public function plan_edit_screen($settings)
    {
        $settings['academylms'] = [
            'tab_title' => esc_html__('Academy LMS', 'wp-user-avatar'),
            [
                'id'          => 'academylms_courses',
                'type'        => 'select2',
                'options'     => $this->get_courses(true),
                'label'       => esc_html__('Select Courses', 'wp-user-avatar'),
                'description' => esc_html__('Select the Academy LMS courses to enroll users in that subscribe to this plan.', 'wp-user-avatar'),
                'priority'    => 5
            ]
        ];

        return $settings;
    }

    public function save_shortcode_builder_settings($settings)
    {
        $settings['academylms_courses'] = array_map('intval', $_POST['rfb_academylms_courses']);

        return $settings;
    }

    public function dnd_builder_settings($meta_box_settings)
    {
        $meta_box_settings['academylms'] = [
            'tab_title' => esc_html__('Academy LMS', 'wp-user-avatar'),
            [
                'id'          => 'academylms_courses',
                'type'        => 'select2',
                'options'     => $this->get_courses(true),
                'label'       => esc_html__('Select Courses', 'wp-user-avatar'),
                'description' => esc_attr__('Select the Academy LMS courses to enroll users in after registration through this form.', 'wp-user-avatar'),
                'priority'    => 5
            ]
        ];

        return $meta_box_settings;
    }

    public function shortcode_builder_settings($form_id)
    {
        $saved_courses = FR::get_form_meta($form_id, FR::REGISTRATION_TYPE, 'academylms_courses');
        if (empty($saved_courses)) $saved_courses = [];
        ?>
        <style>.select2-container {
                width: 100% !important;
            }</style>
        <h4 class="ppSCB-tab-content-header"><?= esc_html__('Academy LMS', 'wp-user-avatar') ?></h4>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="academylms_courses"><?php esc_attr_e('Courses', 'wp-user-avatar'); ?></label>
                </th>
                <td>
                    <select class="ppselect2" name="rfb_academylms_courses[]" id="academylms_courses" multiple>
                        <?php foreach ($this->get_courses() as $course) : ?>
                            <option value="<?= esc_attr($course->ID) ?>"<?= in_array($course->ID, $saved_courses) ? ' selected' : ''; ?>><?= esc_attr($course->post_title) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php esc_attr_e('Select the Academy LMS courses to enroll users in after registration through this form', 'wp-user-avatar'); ?></p>
                </td>
            </tr>
        </table>
        <div class="ppSCB-clear-both"></div>
        <?php
    }

    public function course_checkout_link($output, $course_id)
    {
        $get_purchase_plan = $this->is_purchase_button_display($course_id);

        if ($get_purchase_plan) {
            $plan = ppress_get_plan($get_purchase_plan);
            ob_start();
            ?>
            <form class="ppress-academy-wrap academy-widget-enroll__enroll-form" method="get" action="<?php echo esc_url($plan->get_checkout_url()); ?>">
                <input type="hidden" name="plan" value="<?php esc_attr_e($get_purchase_plan); ?>">
                <button type="submit" class="academy-btn academy-btn--bg-purple">
                    <?php esc_html_e('Subscribe Now', 'profilepress-pro'); ?>
                </button>
            </form>
            <?php
            $output = ob_get_clean();
        }

        return $output;
    }

    public function enroll_content_args($args, $course_id)
    {
        $get_purchase_plan = $this->is_purchase_button_display($course_id);

        if ($get_purchase_plan) {

            $plan              = ppress_get_plan($get_purchase_plan);
            $billing_frequency = $plan->is_recurring() ? ' ' . SubscriptionBillingFrequency::get_label($plan->billing_frequency) : '';
            $args['price']     = sprintf('<span class="ppress-academy-price-wrap">%s %s</span>', ppress_display_amount($plan->get_price()), $billing_frequency);
        }

        return $args;
    }

    public function price_args($args, $course_id)
    {
        $get_purchase_plan = $this->is_purchase_button_display($course_id);

        if ($get_purchase_plan) {

            $plan              = ppress_get_plan($get_purchase_plan);
            $billing_frequency = $plan->is_recurring() ? ' ' . SubscriptionBillingFrequency::get_label($plan->billing_frequency) : '';
            $args['price']     = sprintf('<span class="ppress-academy-price-wrap">%s %s</span>', ppress_display_amount($plan->get_price()), $billing_frequency);
        }

        return $args;
    }

    public function filter_course_loop_cta($template, $template_name, $args, $template_path, $default_path)
    {
        if (
            $template_name == 'loop/footer-form.php' &&
            ppress_var($args, 'course_type') == 'paid'
        ) {
            $display_plan = $this->is_purchase_button_display(get_the_ID());
            if ( ! empty($display_plan)) {
                $template = dirname(__FILE__) . '/footer-form.php';
            }
        }

        return $template;
    }

    public function modify_course_type($course_type, $course_id)
    {
        if ('paid' === $course_type && $this->is_customer_plans_has_course_access($course_id)) {
            $course_type = 'free';
        }

        return $course_type;
    }

    private function is_purchase_button_display($course_id)
    {
        if (Helper::get_course_type($course_id) == 'paid') {

            $plan_ids = $this->get_course_plan_ids($course_id);

            if ( ! empty($plan_ids)) {

                $customer = CustomerFactory::fromUserId(get_current_user_id());

                if ( ! $customer->exists() || ! $this->is_customer_plans_has_course_access($course_id)) {

                    return $plan_ids[0];
                }
            }
        }

        return false;
    }

    /**
     * Check if any of the customer active subscriptions can grant course access.
     *
     * @param $course_id
     *
     * @return bool
     */
    private function is_customer_plans_has_course_access($course_id)
    {
        $customer = CustomerFactory::fromUserId(get_current_user_id());

        $plan_ids = $this->get_course_plan_ids($course_id);

        $plan_checks = array_map(function ($plan_id) use ($customer) {

            if ( ! $customer->exists()) return false;

            return $customer->has_active_subscription($plan_id);

        }, $plan_ids);

        return in_array(true, $plan_checks, true);
    }

    private function get_course_plan_ids($course_id)
    {
        $ids = [];

        $plans = PlanRepository::init()->retrieveAll();

        $course_id = (int)$course_id;

        foreach ($plans as $plan) {

            if ($plan->is_active()) {

                $courses = $plan->get_plan_extras('academylms_courses');

                $courses = is_array($courses) ? array_map('absint', $plan->get_plan_extras('academylms_courses')) : [];

                if (in_array($course_id, $courses, true)) {
                    $ids[] = $plan->get_id();
                }
            }
        }

        return ! empty($ids) ? $ids : false;
    }

    /**
     * @return self|void
     */
    public static function get_instance()
    {
        self::$instance_flag = true;

        if ( ! defined('ProfilePress\Core\Classes\ExtensionManager::ACADEMYLMS')) return;

        if ( ! EM::is_enabled(EM::ACADEMYLMS)) return;

        static $instance = null;

        if (is_null($instance)) {
            $instance = new self();
        }

        return $instance;
    }
}
