<?php

namespace ProfilePress\Core\Membership\PaymentMethods\Stripe\WebhookHandlers;

use ProfilePress\Core\Membership\Models\Subscription\SubscriptionFactory;
use ProfilePress\Core\Membership\PaymentMethods\WebhookHandlerInterface;
use ProfilePress\Core\Membership\Repositories\OrderRepository;

class ChargeRefunded implements WebhookHandlerInterface
{
    public function handle($event_data)
    {
        // This is an uncaptured PaymentIntent, not a true refund.
        if ( ! $event_data['captured']) return;

        $orders = OrderRepository::init()->retrieveBy(['transaction_id' => $event_data['payment_intent']]);

        if (empty($orders)) return;

        $order = $orders[0];

        // If this was completely refunded, set the status to refunded.
        if ($event_data['refunded'] === true) {
            $order->refund_order();
            $subscription = SubscriptionFactory::fromId($order->subscription_id);

            if ( ! $subscription->is_recurring()) {
                $subscription->cancel(); // cancelled one-time/lifetime sub are considered inactive if cancelled
            }

            $last_order = $subscription->get_last_order();
            if ($last_order && $order->get_id() == $last_order->get_id()) $subscription->expire();

        } else {
            // If this was partially refunded, don't change the status.
            $order->add_note(sprintf(__('Payment %s partially refunded in Stripe.', 'wp-user-avatar'), $event_data['payment_intent']));
        }
    }
}
