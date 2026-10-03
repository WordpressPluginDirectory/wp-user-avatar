<?php

namespace ProfilePress\Core\Membership\Emails;

use ProfilePress\Core\Membership\Models\Customer\CustomerFactory;
use ProfilePress\Core\Membership\Models\Subscription\SubscriptionEntity;

class SubscriptionPaymentFailedNotification extends AbstractMembershipEmail
{
    const ID = 'subscription_payment_failed_notification';

    const DEDUPE_META_KEY = 'payment_failed_email_sent';

    public function __construct()
    {
        add_action('ppress_subscription_payment_failed', [$this, 'dispatch_email']);
    }

    /**
     * @param SubscriptionEntity $subscription
     *
     * @return void
     */
    public function dispatch_email($subscription)
    {
        if ( ! apply_filters('ppress_membership_subscription_payment_failed_email_enabled', true, $subscription)) return;

        if (ppress_get_setting(self::ID . '_email_enabled', 'on') !== 'on') return;

        // ensures gateway payment retries do not re-send the email within the same billing period.
        if ($subscription->get_meta(self::DEDUPE_META_KEY) === $subscription->expiration_date) return;

        $placeholders_values = $this->get_subscription_placeholders_values($subscription);

        $subject = apply_filters('ppress_' . self::ID . '_email_subject', $this->parse_placeholders(
            ppress_get_setting(self::ID . '_email_subject', esc_html__('Your subscription payment failed.', 'wp-user-avatar'), true),
            $placeholders_values,
            $subscription
        ), $subscription);

        $message = apply_filters('ppress_' . self::ID . '_email_content', $this->parse_placeholders(
            ppress_get_setting(self::ID . '_email_content', $this->get_subscription_payment_failed_content(), true),
            $placeholders_values,
            $subscription
        ), $subscription);

        $recipient = apply_filters('ppress_' . self::ID . '_recipient', CustomerFactory::fromId($subscription->customer_id)->get_email(), $subscription);

        if (ppress_send_email($recipient, $subject, $message)) {
            $subscription->update_meta(self::DEDUPE_META_KEY, $subscription->expiration_date);
        }
    }
}
