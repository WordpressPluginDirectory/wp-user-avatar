<?php

namespace ProfilePress\Core\Classes;

class LoginActivity
{
    const ACTIVITY_META_KEY = 'ppress_login_activity';

    const DEVICES_META_KEY = 'ppress_known_login_devices';

    const DEVICE_COOKIE = 'ppress_device_id';

    /**
     * Whether the login that happened in this request came from a new device, keyed by user ID.
     *
     * @var bool[]
     */
    protected $new_device_logins = [];

    public function __construct()
    {
        // runs before LoginNotificationEmail so it knows if the login is from a new device.
        add_action('wp_login', [$this, 'record_login'], 10, 2);

        add_filter('wp_privacy_personal_data_exporters', [$this, 'register_exporter']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'register_eraser']);
    }

    /**
     * @param string $user_login
     * @param \WP_User $wp_user
     */
    public function record_login($user_login, $wp_user = null)
    {
        if ( ! $wp_user instanceof \WP_User) {
            $wp_user = get_user_by('login', $user_login);
        }

        if ( ! $wp_user instanceof \WP_User) return;

        if ( ! apply_filters('ppress_login_activity_tracking_enabled', true, $wp_user)) return;

        $user_id     = $wp_user->ID;
        $ip_address  = ppress_get_ip_address();
        $user_agent  = self::current_user_agent();
        $device_hash = wp_hash($this->get_device_id());
        $fingerprint = wp_hash($ip_address . '|' . $user_agent);

        $devices = self::get_known_devices($user_id);

        // A login from the same IP address and browser counts as a known device even when the device cookie was cleared.
        $is_known_device = isset($devices[$device_hash]) || in_array($fingerprint, wp_list_pluck($devices, 'fingerprint'), true);

        // The first login we record establishes the user's first known device, so it is never "new".
        $this->new_device_logins[$user_id] = ! empty($devices) && ! $is_known_device;

        $devices[$device_hash] = ['fingerprint' => $fingerprint, 'last_seen' => time()];

        uasort($devices, function ($a, $b) {
            return $b['last_seen'] <=> $a['last_seen'];
        });

        $max_devices = absint(apply_filters('ppress_login_activity_max_known_devices', 20));

        update_user_meta($user_id, self::DEVICES_META_KEY, array_slice($devices, 0, $max_devices, true));

        $activity = self::get_login_activity($user_id);

        array_unshift($activity, [
            'time'       => time(),
            'ip'         => $ip_address,
            'user_agent' => $user_agent,
            'new_device' => $this->new_device_logins[$user_id]
        ]);

        $max_entries = absint(apply_filters('ppress_login_activity_max_entries', 10));

        update_user_meta($user_id, self::ACTIVITY_META_KEY, array_slice($activity, 0, $max_entries));
    }

    /**
     * Whether the login recorded in this request for the user came from a device they have not used before.
     *
     * @param int $user_id
     *
     * @return bool|null null if no login was recorded for the user in this request.
     */
    public function is_new_device_login($user_id)
    {
        return $this->new_device_logins[$user_id] ?? null;
    }

    /**
     * Most recent logins first.
     *
     * @param int $user_id
     *
     * @return array[] each with time, ip, user_agent and new_device keys.
     */
    public static function get_login_activity($user_id)
    {
        $activity = get_user_meta($user_id, self::ACTIVITY_META_KEY, true);

        return is_array($activity) ? $activity : [];
    }

    protected static function get_known_devices($user_id)
    {
        $devices = get_user_meta($user_id, self::DEVICES_META_KEY, true);

        return is_array($devices) ? $devices : [];
    }

    protected static function current_user_agent()
    {
        return substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255);
    }

    /**
     * Random ID stored in a long-lived cookie that identifies this browser across logins.
     *
     * @return string
     */
    protected function get_device_id()
    {
        $device_id = isset($_COOKIE[self::DEVICE_COOKIE]) ? sanitize_text_field(wp_unslash($_COOKIE[self::DEVICE_COOKIE])) : '';

        if (preg_match('/^[a-f0-9]{32}$/', $device_id)) return $device_id;

        $device_id = bin2hex(random_bytes(16));

        if ( ! headers_sent()) {
            setcookie(self::DEVICE_COOKIE, $device_id, time() + 2 * YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
        }

        $_COOKIE[self::DEVICE_COOKIE] = $device_id;

        return $device_id;
    }

    public function register_exporter($exporters)
    {
        $exporters['profilepress-login-activity'] = [
            'exporter_friendly_name' => esc_html__('Login Activity', 'wp-user-avatar'),
            'callback'               => [$this, 'export_data']
        ];

        return $exporters;
    }

    public function export_data($email_address)
    {
        $user = get_user_by('email', $email_address);

        $data_to_export = [];

        if ($user) {

            foreach (self::get_login_activity($user->ID) as $index => $login) {

                $data_to_export[] = [
                    'group_id'    => 'profilepress-login-activity',
                    'group_label' => esc_html__('Login Activity', 'wp-user-avatar'),
                    'item_id'     => "profilepress-login-activity-{$user->ID}-{$index}",
                    'data'        => [
                        ['name' => esc_html__('Date', 'wp-user-avatar'), 'value' => wp_date(get_option('date_format') . ' ' . get_option('time_format'), $login['time'])],
                        ['name' => esc_html__('IP Address', 'wp-user-avatar'), 'value' => $login['ip']],
                        ['name' => esc_html__('Browser', 'wp-user-avatar'), 'value' => $login['user_agent']],
                    ]
                ];
            }
        }

        return ['data' => $data_to_export, 'done' => true];
    }

    public function register_eraser($erasers)
    {
        $erasers['profilepress-login-activity'] = [
            'eraser_friendly_name' => esc_html__('Login Activity', 'wp-user-avatar'),
            'callback'             => [$this, 'erase_data']
        ];

        return $erasers;
    }

    public function erase_data($email_address)
    {
        $user = get_user_by('email', $email_address);

        $items_removed = false;

        if ($user) {
            $items_removed = delete_user_meta($user->ID, self::ACTIVITY_META_KEY);
            $items_removed = delete_user_meta($user->ID, self::DEVICES_META_KEY) || $items_removed;
        }

        return [
            'items_removed'  => $items_removed,
            'items_retained' => false,
            'messages'       => [],
            'done'           => true,
        ];
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
