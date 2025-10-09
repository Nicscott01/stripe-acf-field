<?php
/**
 * Plugin Name: ACF Stripe Field
 * Description: Adds a Stripe fields to Advanced Custom Fields.
 * Version: 1.0.0
 * Author: Nic Scott
 * Text Domain: acf-stripe-field
 */

if (!defined('ABSPATH')) {
    exit;
}

$acf_stripe_autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($acf_stripe_autoload)) {
    require_once $acf_stripe_autoload;
}

if (!class_exists('ACF_Stripe_Customer_Field_Plugin')) {
    class ACF_Stripe_Customer_Field_Plugin
    {
        const OPTION_PREFIX = 'acf_stripe_';
        protected $path;
        protected $url;
        protected $stripe_clients = [];

        public function __construct()
        {
            $this->path = plugin_dir_path(__FILE__);
            $this->url  = plugin_dir_url(__FILE__);

            add_action('admin_menu', [$this, 'register_settings_page']);
            add_action('admin_init', [$this, 'register_settings']);
            add_action('acf/include_field_types', [$this, 'include_field'], 10, 1);
            add_action('acf/register_fields', [$this, 'include_field'], 10, 1);
            add_action('acf/input/admin_enqueue_scripts', [$this, 'enqueue_field_assets']);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_field_assets']);
            add_action('wp_enqueue_scripts', [$this, 'enqueue_field_assets_frontend']);
            add_action('wp_ajax_acf_stripe_search_customers', [$this, 'ajax_search_customers']);
            add_action('wp_ajax_nopriv_acf_stripe_search_customers', [$this, 'ajax_search_customers']);
            add_action('wp_ajax_acf_stripe_search_subscriptions', [$this, 'ajax_search_subscriptions']);
            add_action('wp_ajax_nopriv_acf_stripe_search_subscriptions', [$this, 'ajax_search_subscriptions']);

            // Debug: Log plugin initialization
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ACF Stripe Customer Field Plugin initialized');
                $secret_key = $this->get_secret_key();
                error_log('ACF Stripe: API key configured: ' . (!empty($secret_key) ? 'YES (length: ' . strlen($secret_key) . ')' : 'NO'));
            }
        }

        public function include_field($version = false)
        {
            if (!class_exists('acf_field')) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('ACF Stripe Customer Field: acf_field class not found');
                }
                return;
            }
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ACF Stripe Customer Field: Registering field type');
            }
            require_once $this->path . 'includes/class-acf-field-stripe-base.php';
            require_once $this->path . 'includes/class-acf-field-stripe-customer.php';
            require_once $this->path . 'includes/class-acf-field-stripe-subscription.php';
            new ACF_Field_Stripe_Customer($this);
            new ACF_Field_Stripe_Subscription($this);
        }

        public function enqueue_field_assets()
        {
            wp_enqueue_script('select2');
            wp_enqueue_style('select2');
            
            wp_register_style('acf-stripe-customer-field', $this->url . 'assets/css/stripe-customer-field.css', [], '1.0.0');
            wp_enqueue_style('acf-stripe-customer-field');

            wp_register_script('acf-stripe-field-base', $this->url . 'assets/js/stripe-field-base.js', ['jquery', 'acf-input', 'select2'], '1.0.1', true);
            wp_enqueue_script('acf-stripe-field-base');

            wp_register_script('acf-stripe-customer-field', $this->url . 'assets/js/stripe-customer-field.js', ['jquery', 'acf-input', 'select2', 'acf-stripe-field-base'], '1.0.1', true);
            wp_localize_script('acf-stripe-customer-field', 'acfStripeCustomerField', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('acf_stripe_customer_search'),
                'isConnected' => $this->is_connected(),
                'debug' => defined('WP_DEBUG') && WP_DEBUG,
                'strings' => [
                    'placeholder' => __('Select a Stripe customer', 'acf-stripe-customer-field'),
                    'noConnection' => __('Connect your Stripe account to search for customers.', 'acf-stripe-customer-field'),
                    'noResults' => __('No customers found.', 'acf-stripe-customer-field'),
                    'error' => __('Unable to load customers.', 'acf-stripe-customer-field'),
                ],
            ]);
            wp_enqueue_script('acf-stripe-customer-field');

            wp_register_script('acf-stripe-subscription-field', $this->url . 'assets/js/stripe-subscription-field.js', ['jquery', 'acf-input', 'select2', 'acf-stripe-field-base'], '1.0.1', true);
            wp_localize_script('acf-stripe-subscription-field', 'acfStripeSubscriptionField', [
                'ajaxUrl'    => admin_url('admin-ajax.php'),
                'nonce'      => wp_create_nonce('acf_stripe_subscription_search'),
                'isConnected'=> $this->is_connected(),
                'debug'      => defined('WP_DEBUG') && WP_DEBUG,
                'strings'    => [
                    'placeholder'  => __('Select a Stripe subscription', 'acf-stripe-subscription-field'),
                    'noConnection' => __('Connect your Stripe account to search for subscriptions.', 'acf-stripe-subscription-field'),
                    'noResults'    => __('No subscriptions found.', 'acf-stripe-subscription-field'),
                    'error'        => __('Unable to load subscriptions.', 'acf-stripe-subscription-field'),
                ],
            ]);
            wp_enqueue_script('acf-stripe-subscription-field');
        }

        public function enqueue_field_assets_frontend()
        {
            if (function_exists('acf_form_head') || isset($_GET['acf_form'])) {
                $this->enqueue_field_assets();
            }
        }

        public function ajax_search_customers()
        {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ACF Stripe Customer AJAX request started');
                error_log('Request data: ' . print_r($_REQUEST, true));
            }

            if (!wp_verify_nonce($_REQUEST['nonce'], 'acf_stripe_customer_search')) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('ACF Stripe Customer AJAX: Nonce verification failed');
                }
                wp_send_json_error(['message' => __('Security check failed.', 'acf-stripe-customer-field')], 403);
            }

            if (!current_user_can('edit_posts')) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('ACF Stripe Customer AJAX: Permission check failed');
                }
                wp_send_json_error(['message' => __('You do not have permission to perform this request.', 'acf-stripe-customer-field')], 403);
            }

            $secret_key = $this->get_secret_key();
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf('ACF Stripe Customer AJAX request — secret length: %d, connected: %s', strlen($secret_key), $this->is_connected() ? 'yes' : 'no'));
            }

            if ('' === $secret_key) {
                wp_send_json_error(['message' => __('Stripe secret key is missing.', 'acf-stripe-customer-field')], 400);
            }

            $request = wp_unslash($_REQUEST);
            $search = isset($request['search']) ? sanitize_text_field($request['search']) : '';

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ACF Stripe Customer AJAX: Searching for: ' . $search);
            }

            // If searching for a specific customer ID, fetch that customer directly
            if (!empty($search) && preg_match('/^cus_[a-zA-Z0-9]+$/', $search)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('ACF Stripe Customer AJAX: Fetching specific customer: ' . $search);
                }
                
                $customer = $this->fetch_customer($search, $secret_key);
                if (is_wp_error($customer)) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('ACF Stripe Customer AJAX: Customer fetch error: ' . $customer->get_error_message());
                    }
                    wp_send_json_error(['message' => $customer->get_error_message()], 500);
                }
                
                // Format single customer as items array
                $items = [[
                    'id' => $customer['id'],
                    'text' => $this->format_customer_label($customer),
                    'email' => isset($customer['email']) ? $customer['email'] : '',
                    'name' => isset($customer['name']) ? $customer['name'] : '',
                ]];
                
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('ACF Stripe Customer AJAX: Returning single customer: ' . $customer['id']);
                }
                
                wp_send_json_success(['items' => $items, 'more' => false]);
            }

            $customers = $this->fetch_customers($search, $secret_key);
            if (is_wp_error($customers)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('ACF Stripe Customer AJAX: Stripe API error: ' . $customers->get_error_message());
                }
                wp_send_json_error(['message' => $customers->get_error_message()], 500);
            }

            $items = [];
            if (!empty($customers['data']) && is_array($customers['data'])) {
                foreach ($customers['data'] as $customer) {
                    $items[] = [
                        'id' => $customer['id'],
                        'text' => $this->format_customer_label($customer),
                        'email' => isset($customer['email']) ? $customer['email'] : '',
                        'name' => isset($customer['name']) ? $customer['name'] : '',
                    ];
                }
            }

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ACF Stripe Customer AJAX: Returning ' . count($items) . ' customers');
            }

            wp_send_json_success(['items' => $items, 'more' => false]);
        }

        public function fetch_customers($search = '', $secret_key = null)
        {
            $client = $this->get_stripe_client($secret_key);
            if (is_wp_error($client)) {
                return $client;
            }

            $search_value = '';
            if (!empty($search)) {
                $search_value = $this->prepare_search_query($search);
            }

            try {
                if (!empty($search_value)) {
                    $result = $client->customers->search([
                        'query' => $search_value,
                        'limit' => 20,
                    ]);
                } else {
                    $result = $client->customers->all([
                        'limit' => 20,
                    ]);
                }

                return $this->convert_stripe_collection($result);
            } catch (\Stripe\Exception\ApiErrorException $exception) {
                return new WP_Error('acf_stripe_api_error', $exception->getMessage());
            }
        }

        /**
         * Fetch a single customer from Stripe.
         *
         * @param string $customer_id Customer ID.
         * @param string $secret_key Stripe secret key.
         * @return array|WP_Error
         */
        public function fetch_customer($customer_id, $secret_key = null)
        {
            $client = $this->get_stripe_client($secret_key);
            if (is_wp_error($client)) {
                return $client;
            }

            $customer_id = trim($customer_id);
            if ('' === $customer_id) {
                return new WP_Error('acf_stripe_missing_customer', __('Customer ID is required.', 'acf-stripe-customer-field'));
            }

            try {
                $customer = $client->customers->retrieve($customer_id, []);
                return $this->convert_stripe_object($customer);
            } catch (\Stripe\Exception\ApiErrorException $exception) {
                return new WP_Error('acf_stripe_api_error', $exception->getMessage());
            }
        }

        protected function prepare_search_query($search)
        {
            $term = trim($search);
            if ('' === $term) {
                return '';
            }
            
            // If searching for a specific customer ID (starts with cus_), search by ID
            if (preg_match('/^cus_[a-zA-Z0-9]+$/', $term)) {
                return sprintf("id:'%s'", $term);
            }
            
            // Otherwise search by name/email
            $term = preg_replace('/[^a-zA-Z0-9@._\\-\\s]/', '', $term);
            $term = substr($term, 0, 50);
            $term = trim($term);
            if ('' === $term) {
                return '';
            }
            return sprintf("name:'%1\$s*' OR email:'%1\$s*'", $term);
        }

        public function format_customer_label($customer)
        {
            $name = isset($customer['name']) ? $customer['name'] : '';
            $email = isset($customer['email']) ? $customer['email'] : '';

            if ($name && $email) {
                return sprintf('%1$s (%2$s)', $name, $email);
            }
            if ($name) {
                return $name;
            }
            if ($email) {
                return $email;
            }
            return isset($customer['id']) ? $customer['id'] : __('Unknown customer', 'acf-stripe-customer-field');
        }

        protected function get_stripe_client($secret_key = null)
        {
            $secret_key = null === $secret_key ? $this->get_secret_key() : $secret_key;

            if (empty($secret_key)) {
                return new WP_Error('acf_stripe_missing_token', __('Stripe secret key is missing.', 'acf-stripe-field'));
            }

            $cache_key = md5($secret_key);

            if (isset($this->stripe_clients[$cache_key])) {
                return $this->stripe_clients[$cache_key];
            }

            $client_args = [
                'api_key'        => $secret_key,
                'stripe_version' => '2022-11-15',
            ];

            $client_args = apply_filters('acf_stripe_field/stripe_client_args', $client_args, $secret_key);

            try {
                $client = new \Stripe\StripeClient($client_args);
            } catch (\Throwable $exception) {
                return new WP_Error('acf_stripe_client_error', $exception->getMessage());
            }

            $client = apply_filters('acf_stripe_field/stripe_client', $client, $secret_key, $client_args);

            $this->stripe_clients[$cache_key] = $client;

            return $client;
        }

        protected function convert_stripe_object($object)
        {
            if (is_array($object)) {
                return $object;
            }

            if ($object instanceof \JsonSerializable) {
                $decoded = json_decode(json_encode($object), true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }

            return [];
        }

        protected function convert_stripe_collection($collection)
        {
            $items = [];

            if (is_object($collection) && isset($collection->data) && is_array($collection->data)) {
                foreach ($collection->data as $item) {
                    $items[] = $this->convert_stripe_object($item);
                }
            }

            return [
                'data'     => $items,
                'has_more' => (bool) (is_object($collection) && isset($collection->has_more) ? $collection->has_more : false),
            ];
        }

        public function is_connected()
        {
            return !empty($this->get_secret_key());
        }

        protected function get_secret_key()
        {
            $secret_key = $this->get_option('secret_key');
            if (empty($secret_key) && defined('ACF_STRIPE_CUSTOMER_SECRET_KEY')) {
                $secret_key = (string) constant('ACF_STRIPE_CUSTOMER_SECRET_KEY');
            }
            return apply_filters('acf_stripe_customer_field/secret_key', $secret_key);
        }

        public function get_option($key)
        {
            return get_option(self::OPTION_PREFIX . $key);
        }

        public function update_option($key, $value)
        {
            update_option(self::OPTION_PREFIX . $key, $value);
        }

        public function register_settings()
        {
            register_setting('acf_stripe_settings', self::OPTION_PREFIX . 'secret_key', [
                'type' => 'string',
                'sanitize_callback' => [$this, 'sanitize_text'],
            ]);
        }

        public function sanitize_text($value)
        {
            return sanitize_text_field($value);
        }

        public function register_settings_page()
        {
            $capability = $this->get_admin_capability();

            if ($this->is_acf_admin_available()) {
                add_submenu_page(
                    'edit.php?post_type=acf-field-group',
                    __('Stripe Customers', 'acf-stripe-customer-field'),
                    __('Stripe Customers', 'acf-stripe-customer-field'),
                    $capability,
                    'acf-stripe-customer-field',
                    [$this, 'render_settings_page']
                );
                return;
            }

            add_options_page(
                __('ACF Stripe Customer', 'acf-stripe-customer-field'),
                __('ACF Stripe Customer', 'acf-stripe-customer-field'),
                $capability,
                'acf-stripe-customer-field',
                [$this, 'render_settings_page']
            );
        }

        public function render_settings_page()
        {
            if (!current_user_can($this->get_admin_capability())) {
                return;
            }

            $secret_key = $this->get_secret_key();
            $is_connected = !empty($secret_key);
            ?>
            <div class="wrap">
                <h1><?php esc_html_e('Stripe Customers', 'acf-stripe-customer-field'); ?></h1>
                <?php settings_errors('acf_stripe_settings'); ?>

                <h2><?php esc_html_e('Connection Status', 'acf-stripe-customer-field'); ?></h2>
                <?php if ($is_connected) : ?>
                    <p><?php esc_html_e('Stripe requests will use the secret key saved below.', 'acf-stripe-customer-field'); ?></p>
                <?php else : ?>
                    <p><?php esc_html_e('Enter your Stripe secret key to allow the field to load customers.', 'acf-stripe-customer-field'); ?></p>
                <?php endif; ?>

                <h2><?php esc_html_e('Stripe API Credentials', 'acf-stripe-customer-field'); ?></h2>
                <p class="description"><?php esc_html_e('Paste a restricted or full-access secret key that can read customers.', 'acf-stripe-customer-field'); ?></p>
                <form method="post" action="options.php">
                    <?php settings_fields('acf_stripe_settings'); ?>
                    <table class="form-table" role="presentation">
                        <tbody>
                        <tr>
                            <th scope="row">
                                <label for="acf-stripe-secret-key"><?php esc_html_e('Stripe Secret Key', 'acf-stripe-customer-field'); ?></label>
                            </th>
                            <td>
                                <input name="<?php echo esc_attr(self::OPTION_PREFIX . 'secret_key'); ?>" id="acf-stripe-secret-key" type="password" class="regular-text" value="<?php echo esc_attr($secret_key); ?>" autocomplete="off" />
                                <p class="description"><?php esc_html_e('Example: sk_live_...', 'acf-stripe-customer-field'); ?></p>
                            </td>
                        </tr>
                        </tbody>
                    </table>
                    <?php submit_button(); ?>
                </form>
            </div>
            <?php
        }

        public function get_settings_menu_hint()
        {
            if ($this->is_acf_admin_available()) {
                return __('Custom Fields → Stripe Customers', 'acf-stripe-customer-field');
            }

            return __('Settings → ACF Stripe Customer', 'acf-stripe-customer-field');
        }

        protected function get_admin_capability()
        {
            if (function_exists('acf_get_setting')) {
                $capability = acf_get_setting('capability');
                if (!empty($capability)) {
                    return $capability;
                }
            }
            return 'manage_options';
        }

        protected function is_acf_admin_available()
        {
            return post_type_exists('acf-field-group');
        }



        public function ajax_search_subscriptions()
        {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ACF Stripe Subscription AJAX request started');
                error_log('Request data: ' . print_r($_REQUEST, true));
            }

            if (!wp_verify_nonce($_REQUEST['nonce'], 'acf_stripe_subscription_search')) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('ACF Stripe Subscription AJAX: Nonce verification failed');
                }
                wp_send_json_error(['message' => __('Security check failed.', 'acf-stripe-subscription-field')], 403);
            }

            if (!current_user_can('edit_posts')) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('ACF Stripe Subscription AJAX: Permission check failed');
                }
                wp_send_json_error(['message' => __('You do not have permission to perform this request.', 'acf-stripe-subscription-field')], 403);
            }

            $secret_key = $this->get_secret_key();
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf('ACF Stripe Subscription AJAX request — secret length: %d, connected: %s', strlen($secret_key), $this->is_connected() ? 'yes' : 'no'));
            }
            if ('' === $secret_key) {
                wp_send_json_error(['message' => __('Stripe secret key is missing.', 'acf-stripe-subscription-field')], 400);
            }

            $request = wp_unslash($_REQUEST);
            $search  = isset($request['search']) ? sanitize_text_field($request['search']) : '';

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ACF Stripe Subscription AJAX: Searching for: ' . $search);
            }

            // If searching for a specific subscription ID, fetch that subscription directly.
            if (!empty($search) && preg_match('/^sub_[a-zA-Z0-9]+$/', $search)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('ACF Stripe Subscription AJAX: Fetching specific subscription: ' . $search);
                }
                $subscription = $this->fetch_subscription($search, $secret_key);
                if (is_wp_error($subscription)) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('ACF Stripe Subscription AJAX: Subscription fetch error: ' . $subscription->get_error_message());
                    }
                    wp_send_json_error(['message' => $subscription->get_error_message()], 500);
                }
                $items = [
                    $this->prepare_subscription_item($subscription),
                ];
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('ACF Stripe Subscription AJAX: Returning single subscription: ' . $subscription['id']);
                }
                wp_send_json_success(['items' => $items, 'more' => false]);
            }

            $subscriptions = $this->fetch_subscriptions($search, $secret_key);
            if (is_wp_error($subscriptions)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('ACF Stripe Subscription AJAX: Stripe API error: ' . $subscriptions->get_error_message());
                }
                wp_send_json_error(['message' => $subscriptions->get_error_message()], 500);
            }

            $items = [];
            if (!empty($subscriptions['data']) && is_array($subscriptions['data'])) {
                foreach ($subscriptions['data'] as $sub) {
                    $items[] = $this->prepare_subscription_item($sub);
                }
            }

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ACF Stripe Subscription AJAX: Returning ' . count($items) . ' subscriptions');
            }

            wp_send_json_success(['items' => $items, 'more' => false]);
        }

        public function fetch_subscriptions($search = '', $secret_key = null)
        {
            $client = $this->get_stripe_client($secret_key);
            if (is_wp_error($client)) {
                return $client;
            }

            try {
                $result = $client->subscriptions->all([
                    'limit'  => 20,
                    'expand' => ['data.customer', 'data.items.data.plan', 'data.items.data.price'],
                ]);

                return $this->convert_stripe_collection($result);
            } catch (\Stripe\Exception\ApiErrorException $exception) {
                return new WP_Error('acf_stripe_api_error', $exception->getMessage());
            }
        }

        public function fetch_subscription($subscription_id, $secret_key = null)
        {
            $client = $this->get_stripe_client($secret_key);
            if (is_wp_error($client)) {
                return $client;
            }

            $subscription_id = trim($subscription_id);
            if ('' === $subscription_id) {
                return new WP_Error('acf_stripe_missing_subscription', __('Subscription ID is required.', 'acf-stripe-subscription-field'));
            }

            try {
                $subscription = $client->subscriptions->retrieve($subscription_id, [
                    'expand' => ['customer', 'items.data.plan', 'items.data.price'],
                ]);

                return $this->convert_stripe_object($subscription);
            } catch (\Stripe\Exception\ApiErrorException $exception) {
                return new WP_Error('acf_stripe_api_error', $exception->getMessage());
            }
        }

        public function format_subscription_label($subscription)
        {
            $plan   = $this->extract_subscription_plan_name($subscription);
            $status = isset($subscription['status']) ? $subscription['status'] : '';
            $id     = isset($subscription['id']) ? $subscription['id'] : '';
            list($customer_id, $customer_name, $customer_email) = $this->extract_subscription_customer_details($subscription);

            $customer_display = $this->format_subscription_customer_display($customer_name, $customer_email, $customer_id);

            return $this->build_subscription_label($plan, $customer_display, $status, $id);
        }

        protected function extract_subscription_plan_name($subscription)
        {
            if (!is_array($subscription)) {
                return '';
            }

            if (!empty($subscription['plan']['nickname'])) {
                return $subscription['plan']['nickname'];
            }

            if (!empty($subscription['plan']['id'])) {
                return $subscription['plan']['id'];
            }

            if (!empty($subscription['items']['data'][0]['plan']['nickname'])) {
                return $subscription['items']['data'][0]['plan']['nickname'];
            }

            if (!empty($subscription['items']['data'][0]['plan']['id'])) {
                return $subscription['items']['data'][0]['plan']['id'];
            }

            if (!empty($subscription['items']['data'][0]['price']['nickname'])) {
                return $subscription['items']['data'][0]['price']['nickname'];
            }

            if (!empty($subscription['items']['data'][0]['price']['id'])) {
                return $subscription['items']['data'][0]['price']['id'];
            }

            return '';
        }

        protected function extract_subscription_customer_details($subscription)
        {
            $customer_id    = '';
            $customer_name  = '';
            $customer_email = '';

            if (isset($subscription['customer'])) {
                if (is_array($subscription['customer'])) {
                    $customer_id    = isset($subscription['customer']['id']) ? $subscription['customer']['id'] : '';
                    $customer_name  = isset($subscription['customer']['name']) ? $subscription['customer']['name'] : '';
                    $customer_email = isset($subscription['customer']['email']) ? $subscription['customer']['email'] : '';
                } elseif (!empty($subscription['customer'])) {
                    $customer_id = (string) $subscription['customer'];
                }
            }

            return [$customer_id, $customer_name, $customer_email];
        }

        protected function prepare_subscription_item($subscription)
        {
            $plan  = $this->extract_subscription_plan_name($subscription);
            $status = isset($subscription['status']) ? $subscription['status'] : '';
            list($customer_id, $customer_name, $customer_email) = $this->extract_subscription_customer_details($subscription);
            $customer_display = $this->format_subscription_customer_display($customer_name, $customer_email, $customer_id);
            $id = isset($subscription['id']) ? $subscription['id'] : '';
            $label = $this->build_subscription_label($plan, $customer_display, $status, $id);

            return [
                'id'             => isset($subscription['id']) ? $subscription['id'] : '',
                'text'           => $label,
                'label'          => $label,
                'plan'           => $plan,
                'status'         => $status,
                'customer_id'    => $customer_id,
                'customer_name'  => $customer_name,
                'customer_email' => $customer_email,
                'name'           => $customer_name,
                'email'          => $customer_email,
                'customer_display' => $customer_display,
            ];
        }

        public function format_subscription_customer_display($customer_name, $customer_email, $customer_id)
        {
            $customer_name  = trim((string) $customer_name);
            $customer_email = trim((string) $customer_email);
            $customer_id    = trim((string) $customer_id);

            if ($customer_name && $customer_email) {
                return sprintf('%1$s (%2$s)', $customer_name, $customer_email);
            }

            if ($customer_name) {
                return $customer_name;
            }

            if ($customer_email) {
                return $customer_email;
            }

            return $customer_id;
        }


        public function build_plan_label($plan_id)
        {
            $secret_key = $this->get_secret_key();
            if (empty($secret_key)) {
            return new WP_Error('missing_secret_key', __('Stripe secret key is missing.', 'acf-stripe-subscription-field'));
            }

            $client = $this->get_stripe_client($secret_key);
            if (is_wp_error($client)) {
            return $client;
            }

            try {
            $plan = $client->plans->retrieve($plan_id, []);
            } catch (\Stripe\Exception\ApiErrorException $exception) {
            return new WP_Error('invalid_plan_response', $exception->getMessage());
            }

            if (empty($plan->product)) {
            return new WP_Error('missing_product_id', __('Product id is missing from the plan object.', 'acf-stripe-subscription-field'));
            }

            try {
            $product = $client->products->retrieve($plan->product, []);
            } catch (\Stripe\Exception\ApiErrorException $exception) {
            return new WP_Error('invalid_product_response', $exception->getMessage());
            }

            $product_name = !empty($product->name) ? $product->name : __('Unknown product', 'acf-stripe-subscription-field');
            $amount       = isset($plan->amount) ? $plan->amount : 0;
            $currency     = isset($plan->currency) ? strtoupper($plan->currency) : '';
            $interval     = isset($plan->interval) ? $plan->interval : '';

            // Format the amount (Stripe amounts are in cents).
            $price = number_format($amount / 100, 2);

            // Build and return the label: {product_name} {currency}{price}/{interval}
            return sprintf('%s %s%s/%s', $product_name, $currency, $price, $interval);
        }





        public function build_subscription_label($plan, $customer_display, $status, $id)
        {
            $plan             = trim((string) $plan);
            $customer_display = trim((string) $customer_display);
            $status           = trim((string) $status);
            $id               = trim((string) $id);

            $label = '';

            if ($plan && $customer_display) {
                $label = sprintf('%1$s – %2$s', $this->build_plan_label($plan), $customer_display);
            } elseif ($plan) {
                $label = $this->build_plan_label($plan);
            } elseif ($customer_display) {
                $label = $customer_display;
            }

            if ('' === $label) {
                $label = __('Stripe subscription', 'acf-stripe-subscription-field');
            }
            /*
            $meta_parts = [];
            if ($id) {
                $meta_parts[] = $id;
            }
            if ($status) {
                $meta_parts[] = $status;
            }

            if (!empty($meta_parts)) {
                $label .= ' [' . implode(' | ', $meta_parts) . ']';
            }*/
            return $label;
        }
    }
}

// Bootstrap the plugin.
new ACF_Stripe_Customer_Field_Plugin();
