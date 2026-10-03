<?php

namespace ProfilePress\Core\Membership\Emails;

use ProfilePress\Core\Membership\Models\Customer\CustomerFactory;
use ProfilePress\Core\Membership\Models\Subscription\SubscriptionStatus;
use ProfilePress\Core\Membership\Repositories\SubscriptionRepository;
use ProfilePressVendor\Carbon\CarbonImmutable;

class SubscriptionTrialEndingReminder extends AbstractMembershipEmail
{
    const ID = 'subscription_trial_ending_reminder';

    public function __construct()
    {
        add_action('ppress_daily_recurring_job', [$this, 'dispatch_email']);
    }

    public static function is_enabled()
    {
        return ppress_get_setting(self::ID . '_email_enabled', 'on') === 'on';
    }

    /**
     * @return void
     */
    public function dispatch_email()
    {
        if ( ! self::is_enabled()) return;

        $reminder_days = (int)apply_filters('ppress_' . self::ID . '_reminder_days',
            ppress_get_setting(self::ID . '_reminder_days', '3', true)
        );

        $subDate = CarbonImmutable::now(wp_timezone())->addDays($reminder_days);

        // a trialling subscription's expiration date is when its trial ends. Subscriptions without a payment profile
        // won't be charged when the trial ends, so they get the Upcoming Expiration Reminder instead.
        $subscriptions = SubscriptionRepository::init()->retrieveBy([
            'status'      => [SubscriptionStatus::TRIALLING],
            'number'      => 0,
            'date_column' => 'expiration_date',
            'start_date'  => $subDate->startOfDay()->utc()->toDateTimeString(),
            'end_date'    => $subDate->endOfDay()->utc()->toDateTimeString(),
            'profile_id'  => 'NOT_EMPTY'
        ]);

        if ( ! is_array($subscriptions) || empty($subscriptions)) return;

        foreach ($subscriptions as $subscription) {

            $placeholders_values = $this->get_subscription_placeholders_values($subscription);

            $subject = apply_filters('ppress_' . self::ID . '_email_subject', $this->parse_placeholders(
                ppress_get_setting(self::ID . '_email_subject', esc_html__('Your free trial is ending soon.', 'wp-user-avatar'), true),
                $placeholders_values,
                $subscription
            ), $subscription);

            $message = apply_filters('ppress_' . self::ID . '_email_content', $this->parse_placeholders(
                ppress_get_setting(self::ID . '_email_content', $this->get_subscription_trial_ending_reminder_content(), true),
                $placeholders_values,
                $subscription
            ), $subscription);

            $recipient = apply_filters('ppress_' . self::ID . '_recipient', CustomerFactory::fromId($subscription->customer_id)->get_email(), $subscription);

            ppress_send_email($recipient, $subject, $message);
        }
    }
}
