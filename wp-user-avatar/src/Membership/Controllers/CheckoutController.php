<?php

namespace ProfilePress\Core\Membership\Controllers;

use ProfilePress\Core\Classes\LoginAuth;
use ProfilePress\Core\Membership\CheckoutFields;
use ProfilePress\Core\Membership\Models\Coupon\CouponFactory;
use ProfilePress\Core\Membership\Models\Customer\CustomerFactory;
use ProfilePress\Core\Membership\Models\Group\GroupFactory;
use ProfilePress\Core\Membership\Models\Order\OrderEntity;
use ProfilePress\Core\Membership\Models\Order\OrderFactory;
use ProfilePress\Core\Membership\Models\Order\OrderType;
use ProfilePress\Core\Membership\Models\Plan\PlanEntity;
use ProfilePress\Core\Membership\Models\Subscription\SubscriptionFactory;
use ProfilePress\Core\Membership\PaymentMethods\PaymentMethods;
use ProfilePress\Core\Membership\PaymentMethods\StoreGateway;
use ProfilePress\Core\Membership\Repositories\OrderRepository;
use ProfilePress\Core\Membership\Repositories\SubscriptionRepository;
use ProfilePress\Core\Membership\Services\CouponService;
use ProfilePress\Core\Membership\Services\EUVATChecker\EuVatApi;
use ProfilePress\Core\Membership\Services\OrderService;
use ProfilePress\Core\Membership\Services\TaxService;
use ProfilePress\Libsodium\LoginGuard;

class CheckoutController extends BaseController
{
    use CheckoutTrait;

    public function __construct()
    {
        add_action('wp_ajax_nopriv_ppress_process_checkout_login', [$this, 'process_checkout_login']);
        add_action('wp_ajax_ppress_checkout_check_email', [$this, 'check_email_exists']);
        add_action('wp_ajax_nopriv_ppress_checkout_check_email', [$this, 'check_email_exists']);

        add_action('wp_ajax_ppress_process_checkout', [$this, 'process_checkout']);
        add_action('wp_ajax_nopriv_ppress_process_checkout', [$this, 'process_checkout']);

        add_action('wp_ajax_ppress_checkout_apply_discount', [$this, 'apply_discount']);
        add_action('wp_ajax_nopriv_ppress_checkout_apply_discount', [$this, 'apply_discount']);

        add_action('wp_ajax_ppress_checkout_remove_discount', [$this, 'remove_discount']);
        add_action('wp_ajax_nopriv_ppress_checkout_remove_discount', [$this, 'remove_discount']);

        add_action('wp_ajax_ppress_update_order_review', [$this, 'update_order_review']);
        add_action('wp_ajax_nopriv_ppress_update_order_review', [$this, 'update_order_review']);

        add_action('wp_ajax_ppress_contextual_state_field', [$this, 'contextual_state_field']);
        add_action('wp_ajax_nopriv_ppress_contextual_state_field', [$this, 'contextual_state_field']);

        add_action('wp', [$this, 'validate_checkout_coupon']);
        add_action('wp', [$this, 'redirect_to_referrer_after_checkout']);
    }

    public function contextual_state_field()
    {
        check_ajax_referer('ppress_process_checkout', 'csrf');

        $country   = ppressPOST_var('country', '');
        $nameAttr  = ppressPOST_var('name', '');
        $idAttr    = ppressPOST_var('id', '');
        $classAttr = ppressPOST_var('class', '');

        $states = ! empty($country) ? ppress_array_of_world_states(sanitize_text_field($country)) : [];

        ob_start();

        if ( ! empty($states)) {

            printf(
                '<select name="%s" id="%s" class="%s" autocomplete="address-level1" required="required">',
                esc_attr($nameAttr),
                esc_attr($idAttr),
                esc_attr($classAttr)
            );
            echo '<option value="">&mdash;&mdash;&mdash;</option>';
            foreach ($states as $id => $label) {
                printf('<option value="%s">%s</option>', $id, $label);
            }
            echo '</select>';

        } else {

            printf(
                '<input name="%s" type="text" id="%s" class="%s" autocomplete="address-level1" required="required">',
                esc_attr($nameAttr),
                esc_attr($idAttr),
                esc_attr($classAttr)
            );
        }

        wp_send_json_success(ob_get_clean());
    }

    public function validate_checkout_coupon()
    {
        if ( ! ppress_is_checkout()) return;

        $plan_id = (int)ppressGET_var('plan', 0);

        $coupon = ppress_session()->get(CheckoutSessionData::COUPON_CODE);

        if (isset($coupon['coupon_code'])) {

            $coupon = CouponFactory::fromCode($coupon['coupon_code']);

            $order_type = CheckoutSessionData::get_order_type($plan_id);
            if ( ! $order_type) $order_type = OrderType::NEW_ORDER;

            if ( ! $coupon->is_valid($plan_id, $order_type)) {
                ppress_session()->set(CheckoutSessionData::COUPON_CODE, null);
            }
        }
    }

    public function process_checkout_login()
    {
        $nonce_check = check_ajax_referer('ppress_process_checkout', 'ppress_checkout_nonce', false);

        if (false === $nonce_check) {
            wp_send_json_error(
                $this->alert_message(
                    esc_html__('Error processing login. Nonce failed', 'wp-user-avatar')
                )
            );
        }

        $response = LoginAuth::login_auth(
            trim($_POST['ppmb_user_login']),
            $_POST['ppmb_user_pass'],
            true
        );

        if (is_wp_error($response)) {
            wp_send_json_error($this->alert_message($response->get_error_message()));
        }

        wp_send_json_success();
    }

    public function check_email_exists()
    {
        if (is_user_logged_in()) {
            wp_send_json_error([
                'message' => esc_html__('User is already logged in.', 'wp-user-avatar')
            ]);
        }

        $nonce_check = check_ajax_referer('ppress_process_checkout', 'csrf', false);
        if (false === $nonce_check) {
            $nonce_check = check_ajax_referer('ppress_process_checkout', 'ppress_checkout_nonce', false);
        }
        if (false === $nonce_check) {
            $nonce_check = check_ajax_referer('ppress-frontend-nonce', 'csrf', false);
        }

        if (false === $nonce_check) {
            wp_send_json_error([
                'message' => esc_html__('Security check failed.', 'wp-user-avatar')
            ]);
        }

        $email = sanitize_email(ppressPOST_var('email', ''));

        if (empty($email) || ! is_email($email)) {
            wp_send_json_error([
                'message' => esc_html__('Please enter a valid email address.', 'wp-user-avatar')
            ]);
        }

        $user_exists = (bool) email_exists($email);

        if ($user_exists) {
            $login_link = sprintf(
                '<a href="#" class="ppress-checkout-inline-login-link">%s</a>',
                esc_html__('log in', 'wp-user-avatar')
            );

            $message = sprintf(
                /* translators: %s: Login link */
                esc_html__('An account already exists with this email address. Please %s to continue.', 'wp-user-avatar'),
                $login_link
            );

            $message = apply_filters('ppress_checkout_email_exists_message', $message, $email);

            wp_send_json_success([
                'exists'  => true,
                'message' => $message
            ]);
        }

        wp_send_json_success([
            'exists' => false
        ]);
    }

    public function apply_discount()
    {
        try {

            $nonce_check = check_ajax_referer('ppress_process_checkout', 'ppress_checkout_nonce', false);

            if (false === $nonce_check) {

                throw new \Exception(
                    esc_html__('Error applying coupon code. Nonce failed', 'wp-user-avatar')
                );
            }

            if (empty($_POST['coupon_code'])) {

                throw new \Exception(
                    esc_html__('Please enter a coupon code.', 'wp-user-avatar')
                );
            }

            if (empty($_POST['plan_id'])) {

                throw new \Exception(
                    esc_html__('Please enter a plan ID.', 'wp-user-avatar')
                );
            }

            $plan_id     = absint($_POST['plan_id']);
            $coupon_code = sanitize_text_field($_POST['coupon_code']);

            $coupon = CouponFactory::fromCode($coupon_code);

            if ( ! $coupon->exists()) {

                throw new \Exception(
                    sprintf(esc_html__('Coupon code "%s" not found.', 'wp-user-avatar'), $coupon_code)
                );
            }

            $order_type = CheckoutSessionData::get_order_type($plan_id);

            if ( ! $order_type) $order_type = OrderType::NEW_ORDER;

            if ( ! $coupon->is_valid($plan_id, $order_type)) {

                throw new \Exception(
                    esc_html__('Sorry, this coupon is not valid.', 'wp-user-avatar')
                );
            }

            ppress_session()->set(CheckoutSessionData::COUPON_CODE, [
                'plan_id'     => $plan_id,
                'coupon_code' => $coupon->code,
            ]);

            wp_send_json_success();

        } catch (\Exception $e) {

            wp_send_json_error(
                $this->alert_message($e->getMessage())
            );
        }
    }

    public function remove_discount()
    {
        try {

            check_ajax_referer('ppress_process_checkout', 'ppress_checkout_nonce');

            if (empty($_POST['plan_id'])) {

                throw new \Exception(
                    esc_html__('Please enter a plan ID.', 'wp-user-avatar')
                );
            }

            $plan_id = absint($_POST['plan_id']);

            $session_coupon = ppress_session()->get(CheckoutSessionData::COUPON_CODE);

            if (isset($session_coupon['plan_id'], $session_coupon['coupon_code']) && $plan_id == $session_coupon['plan_id']) {
                ppress_session()->set(CheckoutSessionData::COUPON_CODE, null);
            }

            wp_send_json_success();

        } catch (\Exception $e) {

            wp_send_json_error(
                $this->alert_message($e->getMessage())
            );
        }
    }

    /**
     * @throws \Exception
     */
    public function process_checkout()
    {
        $order_id = 0;

        try {

            $nonce_check = check_ajax_referer('ppress_process_checkout', 'ppress_checkout_nonce', false);

            if (false === $nonce_check) {
                throw new \Exception(esc_html__('Error processing checkout. Nonce failed', 'wp-user-avatar'));
            }

            $GLOBALS['ppress_checkout_post_data'] = $_POST;

            $_POST = $this->cleanup_posted_data($_POST);

            if ( ! isset($_POST['_ppress_timestamp']) || intval($_POST['_ppress_timestamp']) > (time() - 2)) {
                throw new \Exception('spam');
            }

            if ( ! isset($_POST['_ppress_honeypot']) || ! empty($_POST['_ppress_honeypot'])) {
                throw new \Exception('spam');
            }

            $plan_id = (int)$_POST['plan_id'];

            $change_plan_sub_id = (int)$_POST['change_plan_sub_id'];

            // plan lookups absint() the ID, so a negative ID would otherwise resolve to a plan without passing the active check below.
            if ($plan_id < 1 || $change_plan_sub_id < 0) {
                throw new \Exception(
                    esc_html__('Invalid membership plan.', 'wp-user-avatar')
                );
            }

            if (empty($change_plan_sub_id) && ! ppress_get_plan($plan_id)->is_active()) {
                throw new \Exception(
                    esc_html__('Invalid membership plan.', 'wp-user-avatar')
                );
            }

            $checkout_errors = apply_filters('ppress_checkout_validation', new \WP_Error(), $plan_id, $_POST);

            if (is_wp_error($checkout_errors) && $checkout_errors->get_error_code() != '') {
                throw new \Exception($checkout_errors->get_error_message());
            }

            if ( ! empty(ppress_settings_by_key('terms_page_id')) && empty($_POST['ppress-terms'])) {
                throw new \Exception(
                    esc_html__('Please read and accept the terms and conditions to proceed with your order.', 'wp-user-avatar')
                );
            }

            $changePlanSub = SubscriptionFactory::fromId($change_plan_sub_id);

            if ( ! empty($change_plan_sub_id)) {

                if ( ! $changePlanSub->exists()) {
                    throw new \Exception(esc_html__('Invalid subscription ID provided for plan change.', 'wp-user-avatar'));
                }

                if ( ! is_user_logged_in()) {
                    throw new \Exception(esc_html__('You are not allowed to switch from this plan.', 'wp-user-avatar'));
                }

                $currentCustomer = CustomerFactory::fromUserId(get_current_user_id());

                if ( ! $currentCustomer->exists() || (int)$currentCustomer->id !== $changePlanSub->get_customer_id()) {
                    throw new \Exception(esc_html__('You are not allowed to switch from this plan.', 'wp-user-avatar'));
                }

                if ( ! $changePlanSub->can_switch_to_plan($plan_id)) {
                    throw new \Exception(esc_html__('You are not allowed to switch from this plan.', 'wp-user-avatar'));
                }
            }

            $coupon_code = CheckoutSessionData::get_coupon_code($plan_id);

            $cart_vars = OrderService::init()->checkout_order_calculation([
                'plan_id'            => $plan_id,
                'coupon_code'        => $coupon_code,
                'tax_rate'           => $this->get_submitted_checkout_tax_rate($plan_id),
                'change_plan_sub_id' => $change_plan_sub_id
            ]);

            if ( ! empty($coupon_code) && empty($cart_vars->coupon_code)) {
                throw new \Exception(
                    esc_html__('Sorry, this coupon is not valid.', 'wp-user-avatar')
                );
            }

            $is_free_checkout = OrderService::init()->is_free_checkout($cart_vars);

            $payment_method = PaymentMethods::get_instance()->get_by_id(ppressPOST_var('ppress_payment_method', ''));

            if ($is_free_checkout === false) {

                if (
                    empty($_POST['ppress_payment_method']) ||
                    ! $payment_method ||
                    ! $payment_method->is_enabled() ||
                    $payment_method->is_backend_only()
                ) {
                    throw new \Exception(
                        esc_html__('No payment method selected. Please try again.', 'wp-user-avatar')
                    );
                }
            }

            if ($is_free_checkout) {
                add_filter('ppress_checkout_billing_validation', '__return_false');
            } else {

                $validation_response = $payment_method->validate_fields();

                if (is_wp_error($validation_response)) {
                    throw new \Exception($validation_response->get_error_message());
                }
            }

            $customer_id = $this->register_update_user();

            if (is_wp_error($customer_id)) {
                throw new \Exception(json_encode($customer_id->get_error_messages()));
            }

            if (
                $changePlanSub->exists() &&
                $customer_id !== $changePlanSub->get_customer_id()) {
                throw new \Exception(
                    esc_html__('You are not allowed to switch from this plan.', 'wp-user-avatar')
                );
            }

            $order_id = $this->create_order($customer_id, $cart_vars);

            if (is_wp_error($order_id)) {
                throw new \Exception($order_id->get_error_message());
            }

            $subscription_id = $this->create_subscription($customer_id, $cart_vars);

            if (is_wp_error($subscription_id)) {
                throw new \Exception($subscription_id->get_error_message());
            }

            do_action('ppress_process_checkout_after_order_subscription_creation', $order_id, $subscription_id);

            SubscriptionRepository::init()->updateColumn($subscription_id, 'parent_order_id', $order_id);
            OrderRepository::init()->updateColumn($order_id, 'subscription_id', $subscription_id);

            if ( ! $payment_method || ! $payment_method->get_id()) {
                $payment_method = StoreGateway::get_instance();
            }

            $this->save_eu_vat_details($payment_method->id, $order_id);

            if ($changePlanSub->exists() && $changePlanSub->get_customer_id() == $customer_id) {
                SubscriptionFactory::fromId($subscription_id)->update_meta('_upgraded_from_sub_id', $changePlanSub->get_id());
            }

            if ( ! empty($cart_vars->coupon_code)) {

                $coupon = CouponFactory::fromCode($cart_vars->coupon_code);

                if ($coupon->exists() && absint($coupon->get_usage_limit()) > 0) {

                    $order_type = CheckoutSessionData::get_order_type($plan_id);
                    if ( ! $order_type) $order_type = OrderType::NEW_ORDER;

                    if (false === CouponService::init()->check_and_hold_coupon(
                        $coupon,
                        $order_id,
                        $plan_id,
                        $order_type
                    )) {
                        ppress_session()->set(CheckoutSessionData::COUPON_CODE, null);
                        throw new \Exception(
                            esc_html__('Sorry, this coupon is not valid.', 'wp-user-avatar')
                        );
                    }
                }
            }

            if ($is_free_checkout) {
                OrderFactory::fromId($order_id)->complete_order();
                SubscriptionFactory::fromId($subscription_id)->activate_subscription();

                $process_payment = (new CheckoutResponse())->set_is_success(true);

            } else {

                /** @var CheckoutResponse $process_payment */
                $process_payment = $payment_method->process_payment(
                    $order_id,
                    $subscription_id,
                    $customer_id
                );
            }

            $order = OrderFactory::fromId($order_id);

            $is_checkout_autologin = ppress_settings_by_key('enable_checkout_autologin') == 'true';

            if (apply_filters('ppress_autologin_after_checkout', $is_checkout_autologin, $order, $subscription_id)) {

                if (!is_user_logged_in()) {
                    $user_id = CustomerFactory::fromId($customer_id)->get_user_id();
                    if ($user_id > 0) {
                        $can_login = class_exists(LoginGuard::class)
                            ? LoginGuard::can_user_login($user_id, 'checkout_autologin')
                            : true;
                        if (!is_wp_error($can_login)) {
                            wp_set_current_user($user_id);
                            wp_set_auth_cookie($user_id, true);
                        }
                    }
                }
            }

            wp_send_json([
                'success'           => $process_payment->is_success,
                'redirect_url'      => $process_payment->redirect_url,
                'gateway_response'  => $process_payment->gateway_response,
                'error_message'     => $this->alert_message($process_payment->error_message),
                'order_success_url' => ppress_get_success_url($order->order_key, $order->payment_method),
            ]);

        } catch (\Exception $e) {

            if ( ! empty($order_id)) {
                CouponService::init()->release_coupon_hold($order_id);
            }

            $error_message = ppress_is_json($e->getMessage()) ? json_decode($e->getMessage(), true) : $e->getMessage();

            ppress_log_error($error_message);

            wp_send_json_error(
                $this->alert_message($error_message)
            );
        }
    }

    /**
     * @param $country_code
     * @param $country_state_code
     * @param $vat_number
     * @param PlanEntity $planObj
     *
     * @return float|int|string
     * @throws \Exception
     */
    private function get_checkout_tax_rate($country_code, $country_state_code, $vat_number, $planObj)
    {
        if ( ! TaxService::init()->is_tax_enabled()) return 0;

        if (TaxService::init()->calculate_tax_based_on_setting() == 'base') {
            $base_country = ppress_business_country();
            if ( ! empty($base_country)) {
                $country_code       = $base_country;
                $country_state_code = ppress_business_state();
            }
        }

        $tax_rate = TaxService::init()->get_country_tax_rate($country_code, $country_state_code);

        if (TaxService::init()->is_eu_vat_enabled() && TaxService::init()->is_eu_countries($country_code)) {

            $business_country          = ppress_business_country();
            $same_country_rule_setting = TaxService::init()->eu_vat_same_country_rule_setting();

            if ($business_country == $country_code && $same_country_rule_setting == 'charge_always') {
                return $tax_rate;
            }

            if ($business_country == $country_code && $same_country_rule_setting == 'no_charge') {
                return 0;
            }

            if (empty($vat_number)) return $tax_rate;

            // already validated earlier in this checkout session for the same plan, VAT number and country.
            $cached_vat_details = CheckoutSessionData::get_eu_vat_number_details($planObj->id, $vat_number);
            if (is_array($cached_vat_details) && ppress_var($cached_vat_details, 'country_code') == $country_code && ppress_var($cached_vat_details, 'reverse_charged') === true) {
                return 0;
            }

            $session_data = [
                'plan_id'      => $planObj->id,
                'vat_number'   => $vat_number,
                'country_code' => $country_code
            ];

            if (TaxService::init()->is_vat_number_validation_active()) {

                $response = EuVatApi::check_vat($vat_number, $country_code);

                if ( ! $response->is_valid()) {
                    throw new \Exception($response->get_error_message(), $response->error);
                }

                $session_data['company_name']    = $response->name;
                $session_data['company_address'] = $response->address;
                $session_data['is_valid']        = $response->is_valid();
            }

            $session_data['reverse_charged'] = true;

            ppress_session()->set(CheckoutSessionData::EU_VAT_NUMBER, $session_data);

            $tax_rate = 0;
        }

        return $tax_rate;
    }

    /**
     * Tax rate for the order being placed, computed from the submitted billing details rather than
     * trusting the rate stored in session by update_order_review.
     *
     * @param int $plan_id
     *
     * @return float|int|string
     * @throws \Exception
     */
    private function get_submitted_checkout_tax_rate($plan_id)
    {
        if ( ! TaxService::init()->is_tax_enabled()) return 0;

        $payment_method_id = sanitize_key(ppressPOST_var('ppress_payment_method', ''));

        $country_code       = sanitize_text_field(ppressPOST_var($payment_method_id . '_' . CheckoutFields::BILLING_COUNTRY, '', true));
        $country_state_code = sanitize_text_field(ppressPOST_var($payment_method_id . '_' . CheckoutFields::BILLING_STATE, '', true));
        $vat_number         = sanitize_text_field(ppressPOST_var($payment_method_id . '_' . CheckoutFields::VAT_NUMBER, '', true));

        $tax_rate = $this->get_checkout_tax_rate($country_code, $country_state_code, $vat_number, ppress_get_plan($plan_id));

        ppress_session()->set(CheckoutSessionData::TAX_RATE, [
            'plan_id'  => $plan_id,
            'tax_rate' => $tax_rate,
            'country'  => $country_code,
            'state'    => $country_state_code
        ]);

        return $tax_rate;
    }

    public function update_order_review()
    {
        check_ajax_referer('ppress_process_checkout', 'csrf');

        try {

            if (empty($_POST['plan_id'])) {
                throw new \Exception(esc_html__('Please enter a plan ID.', 'wp-user-avatar'));
            }

            global $cart_vars;

            parse_str($_POST['post_data'], $post_data);

            $GLOBALS['ppress_checkout_post_data'] = $post_data;

            $planObj = ppress_get_plan(absint($_POST['plan_id']));

            $groupObj = GroupFactory::fromId(absint(ppress_var($post_data, 'group_id', 0)));

            $changePlanSubId = false;

            // if group selector input is changed/ticked/checked/toggled
            if (ppressPOST_var('isChangePlanUpdate') == 'true') {

                $changePlanSubId = absint(ppress_var($post_data, 'change_plan_sub_id', 0));

                $selectedGroupPlanId = absint($post_data['group_selector']);

                if ($selectedGroupPlanId > 0) $planObj = ppress_get_plan($selectedGroupPlanId);
            }

            $country_code       = sanitize_text_field(ppressPOST_var('country', '', true));
            $country_state_code = sanitize_text_field(ppressPOST_var('state', '', true));
            $vat_number         = sanitize_text_field(ppressPOST_var('vat_number', '', true));

            $tax_rate = $this->get_checkout_tax_rate($country_code, $country_state_code, $vat_number, $planObj);

            ppress_session()->set(CheckoutSessionData::TAX_RATE, [
                'plan_id'  => $planObj->id,
                'tax_rate' => $tax_rate,
                'country'  => $country_code,
                'state'    => $country_state_code
            ]);

            $cart_vars = OrderService::init()->checkout_order_calculation([
                'plan_id'            => $planObj->id,
                'coupon_code'        => CheckoutSessionData::get_coupon_code($planObj->id),
                'tax_rate'           => CheckoutSessionData::get_tax_rate($planObj->id),
                'change_plan_sub_id' => $changePlanSubId
            ]);

            if (ppressPOST_var('isChangePlanUpdate') == 'true') {

                ob_start();
                echo '<div class="ppress-checkout__form">';
                ppress_render_view('checkout/form-checkout', [
                    'groupObj'        => $groupObj,
                    'planObj'         => $planObj,
                    'changePlanSubId' => $changePlanSubId
                ]);
                echo '</div>';

                $fragments = ['.ppress-checkout__form' => ob_get_clean()];

            } else {

                ob_start();
                ppress_render_view(
                    'checkout/form-checkout-sidebar', [
                        'plan'                   => $planObj,
                        'cart_vars'              => $cart_vars,
                        'isChangePlanIdSelected' => false
                    ]
                );
                $checkout_sidebar_html = ob_get_clean();

                ob_start();
                ppress_render_view('checkout/form-payment-methods', [
                    'plan'      => $planObj,
                    'cart_vars' => $cart_vars
                ]);
                $checkout_payment_methods_html = ob_get_clean();

                ob_start();
                ppress_render_view('checkout/form-checkout-submit-btn', [
                    'order_total' => $cart_vars->total,
                    'plan'        => $planObj
                ]);
                $checkout_submit_btn = ob_get_clean();

                $fragments = [
                    '.ppress-checkout_order_summary-wrap'   => $checkout_sidebar_html,
                    '.ppress-checkout_payment_methods-wrap' => $checkout_payment_methods_html,
                    '.ppress-checkout-submit'               => $checkout_submit_btn
                ];
            }

            do_action('ppress_update_order_review_actions', $post_data, $planObj, $cart_vars);

            wp_send_json_success(
                apply_filters('ppress_update_order_review_response', [
                    'fragments' => apply_filters('ppress_update_order_review_fragments', $fragments)
                ], $cart_vars, $planObj)
            );

        } catch (\Exception $e) {

            wp_send_json_error(
                $this->alert_message($e->getMessage())
            );
        }
    }

    public function redirect_to_referrer_after_checkout()
    {
        if (ppress_is_redirect_to_referrer_after_checkout()) {

            $referrer = ppress_session()->get('ppress_checkout_referrer');

            if ( ! empty($referrer) && ppress_is_success_page()) {
                wp_safe_redirect($referrer);
                exit;
            }
        }
    }
}