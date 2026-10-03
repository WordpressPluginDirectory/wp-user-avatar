<?php

namespace ProfilePress\Core\Classes;

class LoginNotificationEmail
{
    const EMAIL_KEY = 'login_notification';

    public function __construct()
    {
        add_action('wp_login', [$this, 'send_login_notification'], 99, 2);
    }

    /**
     * @param string $user_login
     * @param \WP_User $wp_user
     */
    public function send_login_notification($user_login, $wp_user = null)
    {
        if ( ! $wp_user instanceof \WP_User) {
            $wp_user = get_user_by('login', $user_login);
        }

        if ( ! $wp_user instanceof \WP_User || empty($wp_user->user_email)) return;

        $is_enabled = ppress_get_setting(self::EMAIL_KEY . '_email_enabled', 'off') == 'on';

        if ( ! apply_filters('ppress_login_notification_email_enabled', $is_enabled, $wp_user)) return;

        $send_for = ppress_get_setting(self::EMAIL_KEY . '_send_for', 'new_device', true);

        // null means login activity tracking is off for this user, so we can't tell and send anyway.
        if ($send_for == 'new_device' && LoginActivity::get_instance()->is_new_device_login($wp_user->ID) === false) return;

        $subject = $this->parse_placeholders(
            ppress_get_setting(self::EMAIL_KEY . '_email_subject', ppress_login_notification_subject_default(), true),
            $wp_user
        );

        $message = $this->parse_placeholders(
            apply_filters(
                'ppress_login_notification_raw_content',
                ppress_get_setting(self::EMAIL_KEY . '_email_content', ppress_login_notification_content_default(), true),
                $wp_user
            ),
            $wp_user
        );

        ppress_send_email($wp_user->user_email, $subject, $message);
    }

    /**
     * @param string $content
     * @param \WP_User $wp_user
     *
     * @return string
     */
    public function parse_placeholders($content, $wp_user)
    {
        $timestamp  = time();
        $user_agent = sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? ''));

        $search = apply_filters('ppress_login_notification_placeholder_search', [
            '{{username}}',
            '{{userid}}',
            '{{email}}',
            '{{display_name}}',
            '{{first_name}}',
            '{{last_name}}',
            '{{site_title}}',
            '{{login_date}}',
            '{{login_time}}',
            '{{device}}',
            '{{ip_address}}',
            '{{user_agent}}',
            '{{password_reset_link}}',
            '{{login_link}}'
        ]);

        $replace = apply_filters('ppress_login_notification_placeholder_replace', [
            $wp_user->user_login,
            $wp_user->ID,
            $wp_user->user_email,
            $wp_user->display_name,
            $wp_user->first_name,
            $wp_user->last_name,
            ppress_site_title(),
            wp_date(get_option('date_format'), $timestamp),
            wp_date(get_option('time_format') . ' T', $timestamp),
            esc_html(ppress_user_agent_label($user_agent)),
            esc_html(ppress_get_ip_address()),
            esc_html($user_agent),
            ppress_password_reset_url(),
            ppress_login_url()
        ], $wp_user);

        return ppress_custom_profile_field_search_replace(
            str_replace($search, $replace, $content),
            $wp_user
        );
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
