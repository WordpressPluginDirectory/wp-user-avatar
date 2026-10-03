<?php

namespace ProfilePress\Core\Classes;

use WP_Error;

/**
 * Authorise login and redirect to the appropriate page
 *
 * currently used only by the tabbed widget.
 */
class LoginAuth
{
    private static $redirect, $secure_cookie, $user, $username, $password, $login_form_id;

    /** @var null|\WP_Error set when wp_signon() fails */
    private static $login_failed_error;

    /**
     * Authenticate login
     *
     * @param string $username
     * @param string $password
     * @param string $remember_login
     * @param null|int $login_form_id
     * @param string $redirect
     *
     * @return mixed|string|void|WP_Error|\WP_User
     */
    public static function login_auth($username, $password, $remember_login = 'true', $login_form_id = 0, $redirect = '')
    {
        do_action('ppress_before_login_validation', $username, $password, $login_form_id);

        /* start filter Hook */
        $login_errors = new WP_Error();

        // call validate reg from function
        $login_form_errors = apply_filters('ppress_login_validation', $login_errors, $login_form_id);

        if (is_wp_error($login_form_errors) && $login_form_errors->get_error_code() != '') {
            return $login_form_errors;
        }

        /* End Filter Hook */

        $creds                  = array();
        $creds['user_login']    = $username;
        $creds['user_password'] = $password;

        if (empty($remember_login) || $remember_login == 'true') {
            $creds['remember'] = true;
        }

        $secure_cookie = '';
        // If the user wants ssl but the session is not ssl, force a secure cookie.
        if ( ! force_ssl_admin()) {
            if ($user = get_user_by('login', $username)) {
                if (get_user_option('use_ssl', $user->ID)) {
                    $secure_cookie = true;
                    force_ssl_admin(true);
                }
            }
        }

        if (defined('FORCE_SSL_ADMIN') && FORCE_SSL_ADMIN === true) {
            $secure_cookie = true;
        }

        add_filter('wp_redirect', [__CLASS__, 'wp_redirect_intercept'], 999999999, 2);

        // Don't call remove_all_actions('wp_login_failed') here. It was used to stop callbacks that redirect after a
        // failed login from being caught by wp_redirect_intercept() and treated as a successful login, but it also
        // removed brute-force protection plugins (Limit Login Attempts etc.), which record failures on that hook.
        // With them gone, ProfilePress logins allowed unlimited password guessing. record_login_failure() runs first
        // instead, so wp_redirect_intercept() can tell a failed login from a successful one.
        self::$login_failed_error = null;
        add_action('wp_login_failed', [__CLASS__, 'record_login_failure'], -999999999, 2);

        self::$redirect      = $redirect;
        self::$secure_cookie = $secure_cookie;
        self::$username      = $username;
        self::$password      = $password;
        self::$login_form_id = $login_form_id;

        $user = wp_signon($creds, $secure_cookie);

        if (is_wp_error($user) && ($user->get_error_code())) {
            return $user;
        }

        self::$user = $user;

        $login_redirection = self::after_do_login();

        // if ajax, return the url to redirect to
        if (wp_doing_ajax()) return $login_redirection;

        nocache_headers();

        wp_safe_redirect($login_redirection);
        exit;
    }

    public static function after_do_login()
    {
        $redirect      = self::$redirect;
        $secure_cookie = self::$secure_cookie;
        $user          = self::$user;
        $username      = self::$username;
        $password      = self::$password;
        $login_form_id = self::$login_form_id;

        if ( ! self::$user) {
            $user = get_user_by('login', $username);
            if ( ! $user) {
                $user = get_user_by('email', $username);
            }
        }

        do_action('ppress_before_login_redirect', $username, $password, $login_form_id);

        // culled from wp-login.php file.
        if ( ! empty($redirect)) {

            $previous_page = ppress_var($_POST, 'login_referrer_page', '');

            if ($redirect == 'previous_page' && ! empty($previous_page)) {
                $redirect = esc_url_raw($previous_page);
            }

            // Redirect to https if user wants ssl
            if ($secure_cookie && false !== strpos($redirect, 'wp-admin')) {
                $redirect = preg_replace('|^http://|', 'https://', $redirect);
            }

        } else {
            $redirect = ppress_login_redirect();
        }

        $requested_redirect_to = isset($_REQUEST['redirect_to']) ? wp_validate_redirect($_REQUEST['redirect_to']) : '';

        $login_redirection = apply_filters('login_redirect', $redirect, $requested_redirect_to, $user);

        /** Setup a custom location of the builder */
        $login_redirection = wp_validate_redirect(
            apply_filters('ppress_login_redirect', $login_redirection, $login_form_id, $user)
        );

        remove_filter('wp_redirect', [__CLASS__, 'wp_redirect_intercept'], 999999999);

        return $login_redirection;
    }

    /**
     * Runs before any other wp_login_failed callback so wp_redirect_intercept() knows the login failed.
     *
     * @param string $username
     * @param null|\WP_Error $error
     */
    public static function record_login_failure($username, $error = null)
    {
        self::$login_failed_error = is_wp_error($error) ? $error : new \WP_Error('authentication_failed', esc_html__('Invalid username or password.', 'wp-user-avatar'));
    }

    /**
     * Hooked on wp_redirect during wp_signon(). Plugins that redirect on wp_login (2FA, login redirect plugins
     * etc.) are sent to ProfilePress's own login redirect instead, so AJAX logins get a JSON response.
     *
     * A redirect from a wp_login_failed callback is left alone for normal requests, the same as on wp-login.php.
     * For AJAX, the failure is returned as an error, never as a successful login.
     *
     * @param string $location
     * @param int $status
     *
     * @return string
     */
    public static function wp_redirect_intercept($location = '', $status = 302)
    {
        // a wp_login_failed callback is redirecting after a failed login. Don't treat it as a successful login.
        if (is_wp_error(self::$login_failed_error)) {

            if (wp_doing_ajax()) {
                wp_send_json([
                    'success' => false,
                    'code'    => self::$login_failed_error->get_error_code(),
                    'message' => '<div class="' . esc_attr(apply_filters('ppress_login_error_css_class', 'profilepress-login-status', self::$login_form_id)) . '">' . self::$login_failed_error->get_error_message() . '</div>'
                ]);
            }

            return $location;
        }

        $login_redirection = self::after_do_login();

        // if ajax, return the url to redirect to
        if (wp_doing_ajax()) {
            wp_send_json(['success' => true, 'redirect' => $login_redirection]);
        }

        nocache_headers();

        wp_safe_redirect($login_redirection);
        exit;
    }
}
