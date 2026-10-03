<?php

namespace ProfilePress\Core\Admin\SettingsPages\Membership\CustomersPage;

use ProfilePress\Core\Admin\SettingsPages\AbstractSettingsPage;
use ProfilePress\Core\Classes\RegistrationAuth;
use ProfilePress\Core\Admin\SettingsPages\Membership\ContextualStateChangeHelper;
use ProfilePress\Core\Membership\CheckoutFields;
use ProfilePress\Core\Membership\Models\Customer\CustomerEntity;
use ProfilePress\Core\Membership\Models\Customer\CustomerFactory;
use ProfilePress\Core\Membership\Models\Order\OrderStatus;
use ProfilePress\Core\Membership\PaymentMethods\PaymentMethods;
use ProfilePress\Core\Membership\Repositories\PlanRepository;
use ProfilePress\Core\Membership\Services\Calculator;
use ProfilePress\Custom_Settings_Page_Api;
use ProfilePressVendor\Carbon\CarbonImmutable;

class SettingsPage extends AbstractSettingsPage
{
    public $error_bucket;

    /** @var CustomerWPListTable */
    private $customerListTable;

    function __construct()
    {
        add_action('ppress_register_menu_page', [$this, 'register_cpf_settings_page']);
        add_action('ppress_admin_settings_page_customers', [$this, 'settings_page_function']);

        add_action('wp_ajax_ppress_mb_search_wp_users', [$this, 'search_wp_users']);

        if (ppressGET_var('page') == PPRESS_MEMBERSHIP_CUSTOMERS_SETTINGS_SLUG) {

            add_filter('set-screen-option', [__CLASS__, 'set_screen'], 10, 3);
            add_filter('set_screen_option_rules_per_page', [__CLASS__, 'set_screen'], 10, 3);

            add_action('admin_init', function () {
                $this->save_customer();
                $this->add_customer();
            });
        }
    }

    public function default_header_menu()
    {
        return 'customers';
    }

    public function register_cpf_settings_page()
    {
        global $ppress_customer_page;

        $hook = $ppress_customer_page = add_submenu_page(
                PPRESS_DASHBOARD_SETTINGS_SLUG,
                $this->admin_page_title() . ' - ProfilePress',
                esc_html__('Customers', 'wp-user-avatar'),
                'manage_options',
                PPRESS_MEMBERSHIP_CUSTOMERS_SETTINGS_SLUG,
                [$this, 'admin_page_callback']
        );

        add_action("load-$hook", [$this, 'add_options']);

        do_action('ppress_membership_customers_settings_page_register', $hook);
    }

    /**
     * @return void
     * @throws \Exception
     */
    public function save_customer()
    {
        if ( ! isset($_POST['ppress_save_customer'])) return;

        ppress_verify_nonce();

        if ( ! current_user_can('manage_options')) return;

        $user_id = absint($_POST['customer_wp_user']);

        $customer_id            = absint($_GET['id']);
        $customer               = CustomerFactory::fromId($customer_id);
        $customer->user_id      = $user_id;
        $customer->private_note = sanitize_textarea_field(stripslashes($_POST['private_note']));
        $customer->save();

        $billing_fields = array_keys(CheckoutFields::billing_fields());

        foreach ($_POST as $key => $value) {
            if (in_array($key, $billing_fields)) {
                update_user_meta($user_id, $key, sanitize_textarea_field($value));
            }
        }

        do_action('ppress_customer_updated', $customer_id);

        wp_safe_redirect(add_query_arg([
                'ppress_customer_action' => 'view',
                'id'                     => $customer_id,
                'saved'                  => 'true'
        ], PPRESS_MEMBERSHIP_CUSTOMERS_SETTINGS_PAGE));
        exit;
    }

    /**
     * @return void|string
     * @throws \Exception
     */
    public function add_customer()
    {
        if ( ! isset($_POST['save_ppress_customers'])) return;

        if ( ! current_user_can('create_users')) {
            wp_die(__('You do not have permission to perform this action.', 'wp-user-avatar'), __('Error', 'wp-user-avatar'), array('response' => 403));
        }

        check_admin_referer('wp-csa-nonce', 'wp_csa_nonce');

        $type = ! empty($_POST['user_account_type']) ? $_POST['user_account_type'] : 'new';

        $customer_data = ppressPOST_var('ppress_customers', [], true);

        $customer_email   = ppress_var($customer_data, 'email', '');
        $customer_user_id = ppress_var($customer_data, 'search_user', '');

        if ($type == 'new' && empty($customer_email)) {
            wp_die(__('Please enter a valid customer email.', 'wp-user-avatar'), __('Error', 'wp-user-avatar'), array('response' => 400));
        }

        if ('new' == $type) {

            $user_login = ! empty($customer_data['username']) ? $customer_data['username'] : $customer_email;

            $existing_user = get_user_by('login', $user_login);

            if ($existing_user && $existing_user->exists()) {
                wp_die(sprintf(__('A user account already exists with the login %s.', 'wp-user-avatar'), esc_html($user_login)), __('Error', 'wp-user-avatar'), array('response' => 500));
            }

            $user_args = array(
                    'user_login' => sanitize_text_field($user_login),
                    'user_email' => sanitize_text_field($customer_email),
                    'user_pass'  => ! empty($_POST['pass1']) ? $_POST['pass1'] : wp_generate_password(24),
                    'first_name' => ! empty($customer_data['first_name']) ? sanitize_text_field($customer_data['first_name']) : '',
                    'last_name'  => ! empty($customer_data['last_name']) ? sanitize_text_field($customer_data['last_name']) : ''
            );

            $user_args['display_name'] = trim($user_args['first_name'] . ' ' . $user_args['last_name']);

            if (empty($user_args['display_name'])) {
                $user_args['display_name'] = $user_args['user_login'];
            }

            $user_id = wp_insert_user($user_args);

            if (empty($user_id)) {
                wp_die(__('Error creating customer account.', 'wp-user-avatar'), __('Error', 'wp-user-avatar'), array('response' => 500));
            }

            // Send welcome email to the user
            RegistrationAuth::send_welcome_email($user_id, $user_args['user_pass']);

            $user = get_userdata($user_id);

        } else {

            $user = get_user_by('id', $customer_user_id);

            if ( ! is_a($user, 'WP_User')) {
                wp_die(sprintf(__('Unable to locate existing account with the email %s.', 'wp-user-avatar'), esc_html($customer_email)), __('Error', 'wp-user-avatar'), array('response' => 500));
            }
        }

        $customer = CustomerFactory::fromUserId($user->ID);

        if ($customer->exists()) {
            wp_die(sprintf(__('A customer with the ID %d already exists with this account.', 'wp-user-avatar'), $customer->get_id()), __('Error', 'wp-user-avatar'), array('response' => 500));
        }

        $new_customer          = new CustomerEntity();
        $new_customer->user_id = $user->ID;
        $customer_id           = $new_customer->save();

        if (empty($customer_id)) {
            wp_die(__('Error creating customer record.', 'wp-user-avatar'), __('Error', 'wp-user-avatar'), array('response' => 500));
        }

        if (ppress_var($customer_data, 'billing_address') == 'true') {
            foreach (array_keys(CheckoutFields::billing_fields()) as $billing_key) {
                if (isset($_POST[$billing_key])) {
                    update_user_meta($user->ID, $billing_key, sanitize_textarea_field($_POST[$billing_key]));
                }
            }
        }

        do_action('ppress_customer_updated', $customer_id);

        if (ppress_var($customer_data, 'create_order') == 'true') {
            $this->create_customer_order($customer_id, $customer_data);
        }

        wp_safe_redirect(esc_url_raw(CustomerWPListTable::view_customer_url($customer_id)));
        exit;
    }

    /**
     * Create a membership order for the customer.
     *
     * @param int $customer_id
     * @param array $customer_data
     *
     * @return void
     */
    protected function create_customer_order($customer_id, $customer_data)
    {
        $plan_id = absint(ppress_var($customer_data, 'order_plan'));

        if (empty($plan_id)) {
            wp_die(__('No plan was selected. Please try again.', 'wp-user-avatar'), __('Error', 'wp-user-avatar'), array('response' => 400));
        }

        $date_created = current_time('mysql');

        if ( ! empty($customer_data['order_date'])) {
            $date_created = CarbonImmutable::parse(sanitize_text_field($customer_data['order_date']), wp_timezone())
                                           ->setTimeFromTimeString(CarbonImmutable::now(wp_timezone())->toTimeString())
                                           ->utc()->toDateTimeString();
        }

        $amount = ppress_sanitize_amount(ppress_var($customer_data, 'order_amount', ''));

        if (empty($amount) || Calculator::init($amount)->isNegativeOrZero()) {
            $amount = ppress_get_plan($plan_id)->get_price();
        }

        $response = ppress_subscribe_user_to_plan(
            $plan_id,
            $customer_id,
            [
                'amount'         => $amount,
                'order_status'   => ppress_var($customer_data, 'order_status', OrderStatus::COMPLETED),
                'payment_method' => ppress_var($customer_data, 'order_payment_method', ''),
                'transaction_id' => ppress_var($customer_data, 'order_transaction_id', ''),
                'date_created'   => $date_created
            ],
            ppress_var($customer_data, 'order_send_receipt') == 'true'
        );

        if (is_wp_error($response)) {
            wp_die($response->get_error_message(), __('Error', 'wp-user-avatar'), array('response' => 400));
        }
    }

    public function search_wp_users()
    {
        if ( ! current_user_can('manage_options')) return;

        check_ajax_referer('ppress-admin-nonce', 'nonce');

        $search = sanitize_text_field($_GET['search']);

        $results['results'] = [];

        $users = get_users([
                'search'         => '*' . $search . '*',
                'search_columns' => ['user_email', 'user_login', 'user_nicename', 'display_name'],
                'fields'         => ['ID', 'user_email', 'user_login'],
                'number'         => 1000
        ]);

        if (is_array($users) && ! empty($users)) {

            foreach ($users as $user) {

                $results['results'][$user->ID] = array(
                        'id'   => $user->ID,
                        'text' => sprintf('%s (%s)', $user->user_login, $user->user_email),
                );
            }
        }

        $results['results'] = array_values($results['results']);

        wp_send_json($results, 200);
    }

    public function admin_page_title()
    {
        $title = esc_html__('Customers', 'wp-user-avatar');

        if (ppressGET_var('ppress_customer_action') == 'new') {
            $title = esc_html__('Add New Customer', 'wp-user-avatar');
        }

        if (ppressGET_var('ppress_customer_action') == 'view') {
            $title = esc_html__('Customer Details', 'wp-user-avatar');
        }

        return $title;
    }

    public static function set_screen($status, $option, $value)
    {
        return $value;
    }

    public function add_options()
    {
        $args = [
                'label'   => esc_html__('Customers', 'wp-user-avatar'),
                'default' => 10,
                'option'  => 'customers_per_page'
        ];

        add_screen_option('per_page', $args);

        $this->customerListTable = new CustomerWPListTable();
    }

    public function admin_notices()
    {
        if ( ! isset($_GET['saved']) && ! isset($this->error_bucket)) return;

        $status  = 'updated';
        $message = '';

        if ( ! empty($this->error_bucket)) {
            $message = $this->error_bucket;
            $status  = 'error';
        }

        if (isset($_GET['saved'])) {
            $message = esc_html__('Changes saved.', 'wp-user-avatar');
        }

        printf('<div id="message" class="%s notice is-dismissible"><p>%s</strong></p></div>', $status, $message);
    }

    public function settings_page_function()
    {
        add_action('admin_footer', [$this, 'js_script']);
        add_filter('wp_cspa_main_content_area', [$this, 'admin_settings_page_callback'], 10, 2);
        add_action('wp_cspa_before_closing_header', [$this, 'add_new_button']);

        add_action('wp_cspa_form_tag', function ($option_name) {
            if ($option_name == 'ppress_customers') {
                printf(' action="%s"', ppress_get_current_url_query_string());
            }
        });

        $instance = Custom_Settings_Page_Api::instance();
        if ( ! isset($_GET['ppress_customer_action'])) {
            $instance->form_method('get');
            $instance->remove_nonce_field();
        }

        if (ppressGET_var('ppress_customer_action') == 'new') {

            $form_fields = [
                            'account_type' => [
                                    'label' => __('User Account', 'wp-user-avatar'),
                                    'type'  => 'custom_field_block',
                                    'data'  => self::user_account_type_settings()
                            ],
                            'search_user'  => [
                                    'label'      => __('Search User', 'wp-user-avatar'),
                                    'type'       => 'select',
                                    'options'    => [],
                                    'attributes' => ['class' => 'ppress-select2-field customer_wp_user']
                            ],
                            'first_name'   => [
                                    'label' => __('First Name', 'wp-user-avatar'),
                                    'type'  => 'text'
                            ],
                            'last_name'    => [
                                    'label' => __('Last Name', 'wp-user-avatar'),
                                    'type'  => 'text'
                            ],
                            'email'        => [
                                    'label' => __('Email Address', 'wp-user-avatar'),
                                    'type'  => 'text'
                            ],
                            'username'     => [
                                    'label' => __('Username', 'wp-user-avatar'),
                                    'type'  => 'text'
                            ],
                            'password'     => [
                                    'label' => __('Password', 'wp-user-avatar'),
                                    'type'  => 'custom_field_block',
                                    'data'  => self::password_field()
                            ],
                            'billing_address'         => [
                                    'label'          => __('Billing Address', 'wp-user-avatar'),
                                    'checkbox_label' => __('Add a billing address for this customer.', 'wp-user-avatar'),
                                    'type'           => 'checkbox'
                            ]
            ];

            $form_fields = array_merge($form_fields, self::billing_field_blocks());

            $form_fields = array_merge($form_fields, [
                            'create_order'           => [
                                    'label'          => __('Create Order', 'wp-user-avatar'),
                                    'checkbox_label' => __('Create a membership order for this customer.', 'wp-user-avatar'),
                                    'type'           => 'checkbox'
                            ],
                            'order_plan'             => [
                                    'label'   => __('Membership Plan', 'wp-user-avatar'),
                                    'type'    => 'select',
                                    'options' => array_reduce(PlanRepository::init()->retrieveAll(), function ($carry, $item) {
                                        $carry[$item->id] = $item->name;

                                        return $carry;
                                    }, ['' => '&mdash;&mdash;&mdash;&mdash;'])
                            ],
                            'order_amount'           => [
                                    'label'       => __('Amount', 'wp-user-avatar'),
                                    'type'        => 'text',
                                    'placeholder' => ppress_display_amount('0.00'),
                                    'description' => esc_html__('Enter the total order amount, or leave blank to use the price of the selected plan.', 'wp-user-avatar')
                            ],
                            'order_status'           => [
                                    'label'   => __('Order Status', 'wp-user-avatar'),
                                    'type'    => 'select',
                                    'options' => OrderStatus::get_all()
                            ],
                            'order_payment_method'   => [
                                    'label'   => __('Payment Method', 'wp-user-avatar'),
                                    'type'    => 'select',
                                    'options' => array_reduce(PaymentMethods::get_instance()->get_all(), function ($carry, $item) {
                                        $carry[$item->id] = $item->method_title;

                                        return $carry;
                                    }, ['' => '&mdash;&mdash;&mdash;&mdash;'])
                            ],
                            'order_transaction_id'   => [
                                    'label'       => __('Transaction ID', 'wp-user-avatar'),
                                    'type'        => 'text',
                                    'description' => esc_html__('Enter the transaction ID, if any.', 'wp-user-avatar')
                            ],
                            'order_date'             => [
                                    'label'       => __('Date', 'wp-user-avatar'),
                                    'type'        => 'text',
                                    'class'       => 'ppress_datepicker',
                                    'description' => esc_html__("Enter the purchase date, or leave blank for today's date.", 'wp-user-avatar')
                            ],
                            'order_send_receipt'     => [
                                    'label'          => __('Send Receipt', 'wp-user-avatar'),
                                    'checkbox_label' => __('Check to send the order receipt to the customer.', 'wp-user-avatar'),
                                    'type'           => 'checkbox',
                                    'default_value'  => 'true'
                            ]
            ]);

            ContextualStateChangeHelper::init();

            $instance->main_content([
                    apply_filters('ppress_admin_new_customer_form_fields', $form_fields)
            ]);

            $instance->remove_white_design();

            $instance->sidebar(self::sidebar_args());
        }

        $instance->add_view_classes('ppview');
        $instance->option_name('ppress_customers');
        $instance->page_header($this->admin_page_title());
        $instance->build(ppressGET_var('ppress_customer_action') != 'new');
    }


    protected static function user_account_type_settings()
    {
        $html = sprintf(
                '<label><input checked class="user-account-type" type="radio" name="user_account_type" value="new">%s</label>&nbsp;&nbsp;',
                __('New Account', 'wp-user-avatar')
        );

        $html .= sprintf(
                '<label><input class="user-account-type" type="radio" name="user_account_type" value="existing">%s</label>&nbsp;&nbsp;',
                __('Existing Account', 'wp-user-avatar')
        );

        return $html;
    }

    protected static function password_field()
    {
        ob_start();
        ?>
        <div id="password" class="user-pass1-wrap">
            <input class="hidden" value=" "/><!-- #24364 workaround -->
            <button type="button" class="button wp-generate-pw hide-if-no-js" aria-expanded="false"><?php _e('Show password', 'wp-user-avatar'); ?></button>
            <div class="wp-pwd hide-if-js">
			<span class="password-input-wrapper">
			    <input style="width:25em!important;" type="password" name="pass1" id="pass1" class="regular-text" value="" autocomplete="new-password" data-pw="<?php echo esc_attr(wp_generate_password(24)); ?>"/>
			</span>
                <button type="button" class="button wp-hide-pw hide-if-no-js" data-toggle="0" aria-label="<?php esc_attr_e('Hide password', 'wp-user-avatar'); ?>">
                    <span class="dashicons dashicons-hidden" aria-hidden="true"></span>
                    <span class="text"><?php _e('Hide', 'wp-user-avatar'); ?></span>
                </button>
                <button type="button" class="button wp-cancel-pw hide-if-no-js" data-toggle="0">
                    <span class="dashicons dashicons-no" aria-hidden="true"></span>
                    <span class="text"><?php _e('Cancel', 'wp-user-avatar'); ?></span>
                </button>
                <div style="display:none" id="pass-strength-result" aria-live="polite"></div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Billing field blocks for the new customer form.
     *
     * @return array
     */
    protected static function billing_field_blocks()
    {
        $billing_fields = CheckoutFields::billing_fields();
        $standard       = CheckoutFields::standard_billing_fields();

        $blocks = [];

        foreach ($billing_fields as $field_id => $field) {

            $label = ppress_var($field, 'label', ppress_var($standard, $field_id, ['label' => $field_id])['label']);

            $blocks['billing_field_' . $field_id] = [
                    'label' => $label,
                    'type'  => 'custom_field_block',
                    'data'  => do_shortcode(sprintf('[edit-profile-cpf id="%1$s" key="%1$s" value="" class="regular-text widefat"]', esc_attr($field_id)))
            ];
        }

        return $blocks;
    }

    public function add_new_button()
    {
        if ( ! isset($_GET['ppress_customer_action'])) {
            $url = esc_url_raw(add_query_arg('ppress_customer_action', 'new', PPRESS_MEMBERSHIP_CUSTOMERS_SETTINGS_PAGE));
            echo "<a class=\"add-new-h2\" href=\"$url\">" . esc_html__('Add New', 'wp-user-avatar') . '</a>';
        }
    }

    public function admin_settings_page_callback($content)
    {
        if (ppressGET_var('ppress_customer_action') == 'view') {
            $this->admin_notices();
            ContextualStateChangeHelper::init();
            require_once dirname(dirname(__FILE__)) . '/views/customers/view-customer.php';

            return;
        }

        if (ppressGET_var('ppress_customer_action') == 'new') return $content;

        $this->customerListTable->prepare_items();

        echo '<input type="hidden" name="page" value="' . PPRESS_MEMBERSHIP_CUSTOMERS_SETTINGS_SLUG . '" />';
        echo '<input type="hidden" name="view" value="customers" />';
        if (isset($_GET['status'])) {
            echo '<input type="hidden" name="status" value="' . esc_attr($_GET['status']) . '" />';
        }
        $this->customerListTable->views();
        $this->customerListTable->filter_bar();
        $this->customerListTable->display();

        do_action('ppress_customer_wp_list_table_bottom');
    }

    public function js_script()
    {
        ?>
        <script type="text/javascript">
            jQuery(function ($) {
                $("input.user-account-type").on("change", function () {
                    if ($("input[name=\'user_account_type\']:checked").val() === "new") {
                        $("#search_user_row").hide();
                        $("#first_name_row").show();
                        $("#last_name_row").show();
                        $("#email_row").show();
                        $("#username_row").show();
                        $("#password_row").show();
                    } else {
                        $("#search_user_row").show();
                        $("#first_name_row").hide();
                        $("#last_name_row").hide();
                        $("#email_row").hide();
                        $("#username_row").hide();
                        $("#password_row").hide();
                    }
                }).trigger('change');

                var orderRows = "#order_plan_row, #order_amount_row, #order_status_row, #order_payment_method_row, #order_transaction_id_row, #order_date_row, #order_send_receipt_row";
                $("#create_order").on("change", function () {
                    $(this).is(":checked") ? $(orderRows).show() : $(orderRows).hide();
                }).trigger('change');

                var billingRows = $("tr[id^='billing_field_']");
                $("#billing_address").on("change", function () {
                    $(this).is(":checked") ? billingRows.show() : billingRows.hide();
                }).trigger('change');
            });
        </script>
        <?php
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
