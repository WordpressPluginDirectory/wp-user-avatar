<?php

use ProfilePress\Core\Classes\LoginActivity;
use ProfilePress\Core\ShortcodeParser\MyAccount\MyAccountTag;

if ( ! defined('ABSPATH')) {
    exit;
}

if ( ! is_user_logged_in()) return;

$login_activity = LoginActivity::get_login_activity(get_current_user_id());

$date_time_format = get_option('date_format') . ' ' . get_option('time_format');

echo '<div class="profilepress-myaccount-orders-subs">';
printf('<h2>%s</h2>', esc_html__('Login Activity', 'wp-user-avatar'));

if (empty($login_activity)) {
    printf('<p class="profilepress-myaccount-alert pp-alert-danger">%s</p>', esc_html__('No login activity recorded yet.', 'wp-user-avatar'));
} else {

    echo '<p>';
    esc_html_e('These are the most recent logins to your account.', 'wp-user-avatar');

    if (isset(MyAccountTag::myaccount_tabs()['change-password'])) {
        echo ' ';
        printf(
            esc_html__("If you don't recognise one, %schange your password%s.", 'wp-user-avatar'),
            '<a href="' . esc_url(MyAccountTag::get_endpoint_url('change-password')) . '">', '</a>'
        );
    }

    echo '</p>';
    ?>
    <div class="profilepress-myaccount-sub-order-details-table-wrap">
        <table class="ppress-details-table">
            <thead>
            <tr>
                <th><?php esc_html_e('Date', 'wp-user-avatar') ?></th>
                <th><?php esc_html_e('Device', 'wp-user-avatar') ?></th>
                <th><?php esc_html_e('IP Address', 'wp-user-avatar') ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($login_activity as $login) : ?>
                <tr>
                    <td><?php echo esc_html(wp_date($date_time_format, $login['time'])) ?></td>
                    <td>
                        <?php echo esc_html(ppress_user_agent_label($login['user_agent'])) ?>
                        <?php if ( ! empty($login['new_device'])) : ?>
                            <em>(<?php esc_html_e('new device', 'wp-user-avatar') ?>)</em>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($login['ip']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}
echo '</div>';
