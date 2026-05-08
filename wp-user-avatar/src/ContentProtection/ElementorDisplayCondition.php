<?php

namespace ProfilePress\Core\ContentProtection;

use Elementor\Controls_Manager;
use ElementorPro\Modules\DisplayConditions\Classes\Comparator_Provider as CP;
use ElementorPro\Modules\DisplayConditions\Classes\Comparators_Checker;
use ElementorPro\Modules\DisplayConditions\Conditions\Base\Condition_Base;
use ProfilePress\Core\Membership\Models\Customer\CustomerFactory;
use ProfilePress\Core\Membership\Repositories\PlanRepository;

if ( ! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class ElementorDisplayCondition extends Condition_Base
{

    public function get_name()
    {
        return 'profilepress_membership';
    }

    public function get_label()
    {
        return esc_html__('ProfilePress Membership', 'wp-user-avatar');
    }

    public function get_group()
    {
        return 'user';
    }

    public function check($args): bool
    {
        $user_id = get_current_user_id();

        if (empty($args['plans'])) {

            return Comparators_Checker::check_equality(
                $args['comparator'] == CP::COMPARATOR_IS_ONE_OF ? CP::COMPARATOR_IS : CP::COMPARATOR_IS_NOT,
                CustomerFactory::fromUserId($user_id)->has_active_subscription(),
                true
            );

        } else {

            $user_subscribed_plans = $user_id ? CustomerFactory::fromUserId($user_id)->get_active_subscriptions() : [];

            if ( ! empty($user_subscribed_plans)) {
                $user_subscribed_plans = array_map(function ($sub) {
                    return $sub->get_plan_id();

                }, $user_subscribed_plans);
            }

            return Comparators_Checker::check_array_contains($args['comparator'], $user_subscribed_plans, $args['plans']);
        }
    }

    public function get_options()
    {
        $comparators = CP::get_comparators(
            [
                CP::COMPARATOR_IS_ONE_OF,
                CP::COMPARATOR_IS_NONE_OF,
            ]
        );

        $this->add_control(
            'comparator',
            [
                'type'    => Controls_Manager::SELECT,
                'options' => $comparators,
                'default' => CP::COMPARATOR_IS_ONE_OF,
            ]
        );

        $this->add_control(
            'plans',
            [
                'type'     => Controls_Manager::SELECT2,
                'options'  => $this->get_plan_options(),
                'multiple' => true,
                'default'  => [],
            ]
        );
    }

    /**
     * @return array
     */
    private function get_plan_options()
    {
        $options = [];

        $plans = PlanRepository::init()->retrieveAll();

        foreach ($plans as $plan) {
            $options[$plan->get_id()] = $plan->get_name();
        }

        return $options;
    }
}