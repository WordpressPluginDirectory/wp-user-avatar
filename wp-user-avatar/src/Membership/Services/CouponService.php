<?php

namespace ProfilePress\Core\Membership\Services;

use ProfilePress\Core\Base;
use ProfilePress\Core\Membership\Models\Coupon\CouponEntity;
use ProfilePress\Core\Membership\Models\Coupon\CouponFactory;
use ProfilePress\Core\Membership\Models\Coupon\CouponUnit;
use ProfilePress\Core\Membership\Models\Order\OrderStatus;
use ProfilePress\Core\Membership\Models\Order\OrderType;
use ProfilePress\Core\Membership\Repositories\OrderRepository;

class CouponService
{
    const HOLD_META_PREFIX = '_ppress_coupon_held_';
    const HOLD_KEY_META = '_ppress_coupon_hold';

    public function get_coupon_percentage_fee($percentage, $subtotal)
    {
        return Calculator::init($percentage)->
        dividedBy('100')->
        multipliedBy($subtotal)->
        val();
    }

    /**
     * Checks whether a customer has used a particular discount code.
     *
     * This is used to prevent users from spamming discount codes.
     *
     * @param int $customer_id
     * @param string $code The discount code to check against the customer ID.
     *
     * @return bool
     */
    function customer_has_used_discount($customer_id, $code)
    {
        $result = OrderRepository::init()->retrieveBy([
            'coupon_code' => $code,
            'status'      => [OrderStatus::COMPLETED, OrderStatus::REFUNDED],
            'customer_id' => (int)$customer_id,
            'number'      => 1
        ]);

        return ! empty($result);
    }

    /**
     * Returns a formatted discount amount with a '%' sign appended (percentage-based) or with the
     * currency sign added to the amount (flat discount rate).
     *
     * @param string $amount Discount amount.
     * @param string $type Discount amount - either 'percentage' or 'flat'.
     *
     * @return string
     */
    function format_discount_display($amount, $type)
    {
        $discount = '';
        if ($type == CouponUnit::PERCENTAGE) {
            $discount = $amount . '%';
        } elseif ($type == CouponUnit::FLAT) {
            $discount = ppress_display_amount($amount);
        }

        return $discount;
    }

    public function validate_discount($code, $plan_id = 0, $order_type = OrderType::NEW_ORDER)
    {
        return CouponFactory::fromCode($code)->is_valid($plan_id, $order_type);
    }

    /**
     * Hold TTL in seconds. Defaults to the checkout session lifetime.
     *
     * @param CouponEntity|null $coupon
     *
     * @return int
     */
    public function get_hold_ttl($coupon = null)
    {
        $minutes = (int)apply_filters('ppress_coupon_hold_minutes', 30, $coupon);

        if ($minutes <= 0) {
            $minutes = 1;
        }

        return $minutes * MINUTE_IN_SECONDS;
    }

    /**
     * Count unexpired coupon holds on pending orders.
     *
     * @param string $coupon_code
     *
     * @return int
     */
    public function count_tentative_holds($coupon_code)
    {
        global $wpdb;

        $coupon_code = sanitize_text_field($coupon_code);

        if ($coupon_code === '') {
            return 0;
        }

        $meta_table  = Base::order_meta_db_table();
        $order_table = Base::orders_db_table();
        $prefix      = self::HOLD_META_PREFIX;

        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(m.meta_id)
                 FROM {$meta_table} m
                 INNER JOIN {$order_table} o ON o.id = m.ppress_order_id
                 WHERE m.meta_key LIKE %s
                   AND m.meta_key > %s
                   AND m.meta_value = %s
                   AND o.status = %s",
                $wpdb->esc_like($prefix) . '%',
                $prefix . time(),
                $coupon_code,
                OrderStatus::PENDING
            )
        );

        return absint($count);
    }

    /**
     * WooCommerce-style tentative hold for usage-limited coupons.
     *
     * Locks the coupon row, re-runs is_valid(), then writes an expiring hold key onto the order.
     *
     * @param CouponEntity|string $coupon
     * @param int $order_id
     * @param int $plan_id
     * @param string $order_type
     *
     * @return string|int|false Hold key, 0 when unlimited, false on failure.
     * @throws \Exception
     */
    public function check_and_hold_coupon($coupon, $order_id, $plan_id = 0, $order_type = OrderType::NEW_ORDER)
    {
        global $wpdb;

        if ( ! $coupon instanceof CouponEntity) {
            $coupon = CouponFactory::fromCode($coupon);
        }

        $order_id = absint($order_id);

        if ( ! $coupon->exists() || $order_id <= 0) {
            return false;
        }

        $coupons_table = Base::coupons_db_table();

        $wpdb->query('START TRANSACTION');

        try {
            $locked = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id FROM {$coupons_table} WHERE code = %s FOR UPDATE",
                    $coupon->code
                )
            );

            if (empty($locked)) {
                $wpdb->query('ROLLBACK');

                return false;
            }

            $coupon = CouponFactory::fromId($locked->id);

            if ( ! $coupon->exists() || ! $coupon->is_valid($plan_id, $order_type)) {
                $wpdb->query('ROLLBACK');

                return false;
            }

            if (absint($coupon->get_usage_limit()) === 0) {
                $wpdb->query('COMMIT');

                return 0;
            }

            $hold_key = self::HOLD_META_PREFIX . (time() + $this->get_hold_ttl($coupon)) . '_' . wp_generate_password(6, false);
            $inserted = OrderRepository::init()->add_meta_data($order_id, $hold_key, $coupon->code, true);

            if ( ! $inserted) {
                $wpdb->query('ROLLBACK');

                return false;
            }

            OrderRepository::init()->update_meta_data($order_id, self::HOLD_KEY_META, $hold_key);

            $wpdb->query('COMMIT');

            return $hold_key;
        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');

            throw $e;
        }
    }

    /**
     * Drop a tentative hold so the usage slot can be reused.
     *
     * @param int $order_id
     *
     * @return void
     */
    public function release_coupon_hold($order_id)
    {
        $order_id = absint($order_id);

        if ($order_id <= 0) {
            return;
        }

        $hold_key = OrderRepository::init()->get_meta_data($order_id, self::HOLD_KEY_META, true);

        if ( ! empty($hold_key)) {
            OrderRepository::init()->delete_meta_data($order_id, $hold_key);
            OrderRepository::init()->delete_meta_data($order_id, self::HOLD_KEY_META);
        }
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