<?php

namespace ProfilePress\Core\Membership\Services;

use ProfilePress\Core\Classes\PROFILEPRESS_sql;
use ProfilePress\Core\Membership\Emails\SubscriptionCancelledNotification;
use ProfilePress\Core\Membership\Emails\SubscriptionExpiredNotification;
use ProfilePress\Core\Membership\Models\Order\OrderEntity;
use ProfilePress\Core\Membership\Models\Order\OrderType;
use ProfilePress\Core\Membership\Models\Subscription\SubscriptionBillingFrequency;
use ProfilePress\Core\Membership\Models\Subscription\SubscriptionEntity;
use ProfilePress\Core\Membership\Models\Subscription\SubscriptionFactory;
use ProfilePress\Core\Membership\Repositories\SubscriptionRepository;
use ProfilePress\Core\ShortcodeParser\MyAccount\MyAccountTag;
use ProfilePressVendor\Carbon\CarbonImmutable;

class SubscriptionService
{
    public function __construct()
    {
        add_action('ppress_order_completed', [$this, 'process_plan_change_completion']);
        add_action('ppress_subscription_activated', [$this, 'process_plan_change_completion']);
        add_action('ppress_subscription_enabled_trial', [$this, 'process_plan_change_completion']);
    }

    public function get_plan_expiration_datetime($plan_id)
    {
        $expiration_datetime = '';

        $plan = ppress_get_plan($plan_id);

        if ($plan->is_recurring()) {

            $carbon = CarbonImmutable::now(wp_timezone());

            if ($plan->has_free_trial()) {

                $duration = explode('_', $plan->get_free_trial());
                $period   = $carbon->add($duration[0], $duration[1]);

            } else {

                $period = $plan->billing_frequency;

                switch ($period) {
                    case SubscriptionBillingFrequency::DAILY :
                        $period = $carbon->addDay();
                        break;
                    case SubscriptionBillingFrequency::WEEKLY :
                        $period = $carbon->addWeek();
                        break;
                    case SubscriptionBillingFrequency::QUARTERLY :
                        $period = $carbon->addMonths(3);
                        break;
                    case SubscriptionBillingFrequency::EVERY_6_MONTHS :
                        $period = $carbon->addMonths(6);
                        break;
                    case SubscriptionBillingFrequency::YEARLY :
                        $period = $carbon->addYear();
                        break;
                    default:
                        $period = $carbon->addMonth();
                        break;
                }
            }

            $expiration_datetime = $period->toDateTimeString();
        }

        return apply_filters('ppress_plan_expiration_datetime', $expiration_datetime, $plan_id, $plan);
    }

    /**
     * Keep the previous membership expiration on downgrades.
     *
     * A downgrade is billed as a new order, but the customer already paid through
     * the current period, so the replacement subscription must not start a fresh term.
     *
     * @param SubscriptionEntity $fromSub
     * @param string $fallback_expiration
     *
     * @return string
     */
    public function get_downgrade_expiration_datetime($fromSub, $fallback_expiration = '')
    {
        if ( ! $fromSub instanceof SubscriptionEntity || ! $fromSub->exists() || $fromSub->is_lifetime()) {
            return $fallback_expiration;
        }

        $previous_expiration = $fromSub->expiration_date;

        if (empty($previous_expiration) || ppress_strtotime_utc($previous_expiration) <= time()) {
            return $fallback_expiration;
        }

        return apply_filters('ppress_downgrade_expiration_datetime', $previous_expiration, $fromSub, $fallback_expiration);
    }

    /**
     * Whether the first gateway charge should wait until the previous membership expiration.
     *
     * Only $0 auto-renew downgrades are delayed. A paid-in-full downgrade still needs its first invoice.
     *
     * @param OrderEntity $order
     * @param SubscriptionEntity $subscription
     *
     * @return bool
     */
    public function should_delay_downgrade_billing($order, $subscription)
    {
        if ( ! $order instanceof OrderEntity || $order->order_type !== OrderType::DOWNGRADE) {
            return false;
        }

        if (Calculator::init($order->total)->isGreaterThanZero()) {
            return false;
        }

        if ( ! $subscription instanceof SubscriptionEntity || $subscription->is_lifetime()) {
            return false;
        }

        $expiration_ts = ppress_strtotime_utc($subscription->expiration_date);

        return $expiration_ts > time();
    }

    /**
     * Whole days until a delayed downgrade should first bill.
     *
     * @param SubscriptionEntity $subscription
     *
     * @return int
     */
    public function get_downgrade_delay_days($subscription)
    {
        if ( ! $subscription instanceof SubscriptionEntity) {
            return 0;
        }

        $expiration_ts = ppress_strtotime_utc($subscription->expiration_date);

        if ($expiration_ts <= time()) {
            return 0;
        }

        return max(1, (int)ceil(($expiration_ts - time()) / DAY_IN_SECONDS));
    }

    /**
     * @param $sub_id
     *
     * @return false|int
     */
    public function delete_subscription($sub_id)
    {
        $sub = SubscriptionFactory::fromId($sub_id);

        if ($sub->exists()) {
            $sub->cancel(true);
        }

        $sub->remove_plan_role_from_customer();

        $result = SubscriptionRepository::init()->delete($sub_id);

        if ($result) {
            PROFILEPRESS_sql::delete_meta_data_by_flag($sub->get_meta_flag_id());
        }

        do_action('ppress_subscription_deleted', $sub_id, $sub);

        return $result;
    }

    /**
     * Complete the plan change by cancelling and expiring the old subscription.
     *
     * Only runs once successful payment or activation of the replacement subscription occurs,
     * ensuring the customer does not lose access if checkout is abandoned or payment fails.
     *
     * @param OrderEntity|SubscriptionEntity $order_or_sub
     *
     * @return void
     */
    public function process_plan_change_completion($order_or_sub)
    {
        if ($order_or_sub instanceof OrderEntity) {
            $subscription = $order_or_sub->get_subscription();
        } elseif ($order_or_sub instanceof SubscriptionEntity) {
            $subscription = $order_or_sub;
        } else {
            return;
        }

        if ( ! $subscription instanceof SubscriptionEntity || ! $subscription->exists()) {
            return;
        }

        $old_sub_id = absint($subscription->get_meta('_upgraded_from_sub_id'));

        if ($old_sub_id <= 0) {
            return;
        }

        if ($subscription->get_meta('_plan_change_processed') === 'true') {
            return;
        }

        $old_sub = SubscriptionFactory::fromId($old_sub_id);

        if ( ! $old_sub->exists()) {
            return;
        }

        $subscription->update_meta('_plan_change_processed', 'true');

        // Do not send subscription cancelled or expired emails on plan changes
        remove_action('ppress_subscription_cancelled', [SubscriptionCancelledNotification::init(), 'dispatch_email'], 10);
        remove_action('ppress_subscription_expired', [SubscriptionExpiredNotification::init(), 'dispatch_email']);

        $old_sub->cancel(true);
        $old_sub->expire();

        add_action('ppress_subscription_cancelled', [SubscriptionCancelledNotification::init(), 'dispatch_email'], 10, 2);
        add_action('ppress_subscription_expired', [SubscriptionExpiredNotification::init(), 'dispatch_email']);

        // Ensure customer has the new plan role even if old plan had the same role
        $subscription->add_plan_role_to_customer();

        $old_sub->update_meta('_upgraded_to_sub_id', $subscription->get_id());
    }

    public function frontend_view_sub_url($subscription_id)
    {
        return add_query_arg(['sub_id' => $subscription_id], MyAccountTag::get_endpoint_url('list-subscriptions'));
    }

    public function admin_view_sub_url($subscription_id)
    {
        return add_query_arg(
            ['ppress_subscription_action' => 'edit', 'id' => $subscription_id],
            PPRESS_MEMBERSHIP_SUBSCRIPTIONS_SETTINGS_PAGE
        );
    }

    /**
     * @return self
     */
    public static function init()
    {
        static $instance = null;

        if (is_null($instance)) {
            $instance = new self();
        }

        return $instance;
    }
}
