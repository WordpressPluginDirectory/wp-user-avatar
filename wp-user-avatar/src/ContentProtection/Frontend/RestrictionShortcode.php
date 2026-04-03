<?php

namespace ProfilePress\Core\ContentProtection\Frontend;

use ProfilePress\Core\Membership\Models\Customer\CustomerFactory;

class RestrictionShortcode
{
    public function __construct()
    {
        add_shortcode('pp-restrict-content', [$this, 'shortcode_handler']);
    }

    public function shortcode_handler($atts, $content = null)
    {
        $atts = shortcode_atts(array(
            'roles'  => '',
            'users'  => '',
            'plans'  => '',
            'status' => '',
            'action' => 'show' // value can be "show" or "hide"
        ), $atts);

        if ($atts['action'] == 'hide') {
            return $this->rule_matches($atts['roles'], $atts['users'], $atts['plans'], $atts['status']) ? '' : \do_shortcode($content);
        } else {
            return $this->rule_matches($atts['roles'], $atts['users'], $atts['plans'], $atts['status']) ? \do_shortcode($content) : '';
        }
    }

    public function rule_matches($roles = '', $user_ids = '', $plans = '', $sub_status = false)
    {
        if (is_user_logged_in()) {

            $current_user_id = get_current_user_id();

            if ( ! empty($plans)) {

                $plans = array_map('absint', explode(',', $plans));

                $customer = CustomerFactory::fromUserId($current_user_id);

                foreach ($plans as $plan_id) {

                    if ( ! empty($sub_status)) {

                        $latestOnly = apply_filters('ppress_restriction_shortcode_rule_latest_only', false);

                        if ($latestOnly) {
                            $subs = $customer->get_subscriptions([], ['number' => 1]);
                            if ( ! empty($subs) && $subs[0]->status == $sub_status) return true;
                        } else {
                            if ($customer->has_any_status_subscription($plan_id, [$sub_status])) return true;
                        }
                    } else {
                        if ($customer->has_active_subscription($plan_id)) return true;
                    }
                }
            }

            if ( ! empty($roles)) {

                $roles = array_map('sanitize_text_field', explode(',', $roles));

                $user_roles = wp_get_current_user()->roles;

                if ( ! empty(array_intersect($roles, $user_roles))) return true;
            }

            if ( ! empty($user_ids)) {

                $user_ids = array_map('sanitize_text_field', explode(',', $user_ids));

                foreach ($user_ids as $user_id) {

                    if (is_numeric($user_id) && $current_user_id == absint($user_id)) {
                        return true;
                    } else {
                        $user = get_user_by('login', $user_id);
                        if ( ! $user) {
                            $user = get_user_by('email', $user_id);
                        }

                        if ($user instanceof \WP_User && $current_user_id == absint($user->ID)) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    public static function get_instance()
    {
        static $instance = null;

        if (is_null($instance)) {
            $instance = new self();
        }

        return $instance;
    }
}