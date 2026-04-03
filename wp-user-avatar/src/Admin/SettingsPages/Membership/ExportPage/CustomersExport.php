<?php

namespace ProfilePress\Core\Admin\SettingsPages\Membership\ExportPage;

use ProfilePress\Core\Base;
use ProfilePress\Core\Membership\Models\Order\OrderStatus;

class CustomersExport extends AbstractExport
{
    protected function headers()
    {
        return [
            __('Customer ID', 'wp-user-avatar'),
            __('User ID', 'wp-user-avatar'),
            __('Email', 'wp-user-avatar'),
            __('Name', 'wp-user-avatar'),
            __('Private Note', 'wp-user-avatar'),
            __('Total Spend', 'wp-user-avatar'),
            __('Purchase Count', 'wp-user-avatar'),
            __('Date Created', 'wp-user-avatar'),
            __('Order IDs', 'wp-user-avatar'),
            __('Billing Street Address', 'wp-user-avatar'),
            __('Billing City', 'wp-user-avatar'),
            __('Billing Country', 'wp-user-avatar'),
            __('Billing State', 'wp-user-avatar'),
            __('Billing Zip / Postal Code', 'wp-user-avatar'),
            __('Billing Phone', 'wp-user-avatar')
        ];
    }

    public function get_data($page = 1, $limit = 9999)
    {
        global $wpdb;
        $plan_id            = $this->form['plan_id'] ?? '';
        $orders_table       = Base::orders_db_table();
        $customers_table    = Base::customers_db_table();
        $wp_user_table      = $wpdb->users;
        $wp_user_meta_table = $wpdb->usermeta;

        $replacements = [OrderStatus::COMPLETED];

        $sql = "
        SELECT
            pc.id,
            pc.user_id,
            wpu.user_email,
            wpu.display_name,
            pc.private_note,
            pc.total_spend,
            pc.purchase_count,
            pc.date_created,
            GROUP_CONCAT(DISTINCT po.id) AS order_ids,
            um_address.meta_value AS street_address,
            um_city.meta_value AS city,
            um_country.meta_value AS country,
            um_state.meta_value AS state,
            um_postcode.meta_value AS postcode,
            um_phone.meta_value AS phone
        FROM
            $customers_table AS pc
            INNER JOIN $wp_user_table AS wpu ON pc.user_id = wpu.ID
            INNER JOIN $orders_table AS ppo ON ppo.customer_id = pc.id
            LEFT JOIN $orders_table AS po ON po.customer_id = pc.id AND po.status = %s
            LEFT JOIN $wp_user_meta_table AS um_address ON pc.user_id = um_address.user_id AND um_address.meta_key = 'ppress_billing_address'
            LEFT JOIN $wp_user_meta_table AS um_city ON pc.user_id = um_city.user_id AND um_city.meta_key = 'ppress_billing_city'
            LEFT JOIN $wp_user_meta_table AS um_country ON pc.user_id = um_country.user_id AND um_country.meta_key = 'ppress_billing_country'
            LEFT JOIN $wp_user_meta_table AS um_state ON pc.user_id = um_state.user_id AND um_state.meta_key = 'ppress_billing_state'
            LEFT JOIN $wp_user_meta_table AS um_postcode ON pc.user_id = um_postcode.user_id AND um_postcode.meta_key = 'ppress_billing_postcode'
            LEFT JOIN $wp_user_meta_table AS um_phone ON pc.user_id = um_phone.user_id AND um_phone.meta_key = 'ppress_phone'
    ";

        if ( ! empty($plan_id)) {
            $replacements[] = intval($plan_id);
            $sql            .= " AND ppo.plan_id = %d";
        }

        $sql .= " GROUP BY pc.id";

        $page   = max(1, intval($page));
        $offset = ($page - 1) * intval($limit);

        if ($limit > 0) {
            $sql            .= " LIMIT %d";
            $replacements[] = $limit;
        }

        if ($offset > 0) {
            $sql            .= " OFFSET %d";
            $replacements[] = $offset;
        }

        return $wpdb->get_results($wpdb->prepare($sql, $replacements), ARRAY_A);
    }
}