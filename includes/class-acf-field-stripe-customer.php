<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('ACF_Field_Stripe_Customer') && class_exists('acf_field')) {
    class ACF_Field_Stripe_Customer extends acf_field
    {
        /**
         * Parent plugin instance.
         *
         * @var ACF_Stripe_Customer_Field_Plugin
         */
        protected $plugin;

        /**
         * Constructor.
         *
         * @param ACF_Stripe_Customer_Field_Plugin $plugin Plugin instance.
         */
        public function __construct($plugin)
        {
            $this->plugin = $plugin;

            $this->name     = 'stripe_customer';
            $this->label    = __('Stripe Customer', 'acf-stripe-customer-field');
            $this->category = 'relational';
            $this->defaults = [
                'return_format' => 'id',
                'placeholder'   => __('Select a Stripe customer', 'acf-stripe-customer-field'),
                'allow_null'    => 0,
            ];

            parent::__construct();
        }

        /**
         * Render field settings that appear when editing the field.
         *
         * @param array $field Field settings.
         * @return void
         */
        public function render_field_settings($field)
        {
            acf_render_field_setting($field, [
                'label'        => __('Placeholder Text', 'acf-stripe-customer-field'),
                'instructions' => __('Text shown when no customer is selected.', 'acf-stripe-customer-field'),
                'type'         => 'text',
                'name'         => 'placeholder',
            ]);

            acf_render_field_setting($field, [
                'label'        => __('Allow Null', 'acf-stripe-customer-field'),
                'instructions' => __('Allow the field to be cleared.', 'acf-stripe-customer-field'),
                'type'         => 'true_false',
                'name'         => 'allow_null',
                'ui'           => 1,
            ]);

            acf_render_field_setting($field, [
                'label'        => __('Return Format', 'acf-stripe-customer-field'),
                'instructions' => __('Specify the returned value when using the field.', 'acf-stripe-customer-field'),
                'type'         => 'radio',
                'name'         => 'return_format',
                'layout'       => 'horizontal',
                'choices'      => [
                    'id'     => __('Customer ID', 'acf-stripe-customer-field'),
                    'object' => __('Stripe Customer object', 'acf-stripe-customer-field'),
                ],
            ]);
        }

    /**
     * Render the field input in the editor.
     *
     * @param array $field Field data.
     * @return void
     */
    public function render_field($field)
    {
        $raw_value = isset($field['value']) ? $field['value'] : '';
        
        // Debug logging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('ACF Stripe Customer render_field - Raw field value: ' . print_r($raw_value, true));
            error_log('ACF Stripe Customer render_field - Field structure: ' . print_r($field, true));
        }
        
        $customer_data = $this->parse_customer_value($raw_value);
        $customer_id = $customer_data['id'];
        $selected_label = $customer_data['label'];
        
        // Debug logging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('ACF Stripe Customer render_field - Parsed data: ' . print_r($customer_data, true));
            error_log('ACF Stripe Customer render_field - Selected label: ' . $selected_label);
        }
        
        $placeholder = !empty($field['placeholder']) ? $field['placeholder'] : __('Select a Stripe customer', 'acf-stripe-customer-field');
        $allow_null  = !empty($field['allow_null']);
        $connected   = $this->plugin->is_connected();

        $select_attributes = [
            'class'            => 'acf-stripe-customer-select',
            'name'             => $field['name'],
            'data-placeholder' => $placeholder,
            'data-allow-clear' => $allow_null ? '1' : '0',
            'data-selected-text' => $selected_label,
        ];

        if (!$connected) {
            $select_attributes['disabled'] = 'disabled';
        }

        echo '<div class="acf-stripe-customer-field">';

        if (!$connected) {
            $hint = $this->plugin->get_settings_menu_hint();
            printf(
                '<p class="description acf-stripe-customer-notice" style="color: #d63638;">%s</p>',
                esc_html(sprintf(__('Connect your Stripe account from %s to load customers.', 'acf-stripe-customer-field'), $hint))
            );
        }

        echo '<select';
        foreach ($select_attributes as $attribute => $attr_value) {
            printf(' %s="%s"', esc_attr($attribute), esc_attr($attr_value));
        }
        echo '>';

        if ($allow_null) {
            echo '<option value=""></option>';
        }

        if ($customer_id) {
            printf('<option value="%1$s" selected="selected">%2$s</option>', esc_attr($customer_id), esc_html($selected_label ? $selected_label : $customer_id));
        }

        echo '</select>';

        // Store customer data in hidden field for JavaScript access
        if ($customer_data['name'] || $customer_data['email']) {
            printf(
                '<input type="hidden" name="%s_data" value="%s" />',
                esc_attr($field['name']),
                esc_attr(json_encode([
                    'id' => $customer_data['id'],
                    'name' => $customer_data['name'],
                    'email' => $customer_data['email']
                ]))
            );
        }
        
        // Add debug information when WP_DEBUG is enabled
        if (defined('WP_DEBUG') && WP_DEBUG) {
            printf(
                '<!-- ACF Stripe Customer Field Debug: Connected=%s, ID=%s, Label=%s, Data=%s -->',
                $connected ? 'true' : 'false',
                esc_attr($customer_id),
                esc_attr($selected_label),
                esc_attr(json_encode($customer_data))
            );
        }

        echo '</div>';
    }

        /**
         * Load value from database for display in the field.
         *
         * @param mixed $value The raw value from database.
         * @param int   $post_id Post ID.
         * @param array $field Field array.
         * @return mixed
         */
        public function load_value($value, $post_id, $field)
        {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ACF Stripe Customer load_value - Raw from DB: ' . print_r($value, true));
            }

            // If the value is an array (our new format), extract just the ID for the form field
            if (is_array($value) && isset($value['id'])) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('ACF Stripe Customer load_value - Returning ID: ' . $value['id']);
                }
                return $value['id'];
            }

            // If it's already a string (legacy format), return as-is
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ACF Stripe Customer load_value - Returning as-is: ' . print_r($value, true));
            }
            
            return $value;
        }

        /**
         * Parse customer value from database.
         *
         * @param mixed $value Raw value from database.
         * @return array Customer data array.
         */
        protected function parse_customer_value($value)
        {
            $default = [
                'id' => '',
                'name' => '',
                'email' => '',
                'label' => ''
            ];

            if (empty($value)) {
                return $default;
            }

            // If it's already an array (new format), use it
            if (is_array($value) && isset($value['id'])) {
                $customer_data = array_merge($default, $value);
                $customer_data['label'] = $this->format_customer_label_from_data($customer_data);
                return $customer_data;
            }

            // If it's a JSON string, decode it first
            if (is_string($value) && !empty($value)) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && isset($decoded['id'])) {
                    // It's a JSON string with customer data
                    $customer_data = array_merge($default, $decoded);
                    $customer_data['label'] = $this->format_customer_label_from_data($customer_data);
                    return $customer_data;
                }
                
                // If it's not JSON or doesn't decode properly, treat as customer ID
                return [
                    'id' => $value,
                    'name' => '',
                    'email' => '',
                    'label' => $value // Use ID as fallback label
                ];
            }

            return $default;
        }

        /**
         * Format customer label from stored data.
         *
         * @param array $customer_data Customer data array.
         * @return string Formatted label.
         */
        protected function format_customer_label_from_data($customer_data)
        {
            $name = isset($customer_data['name']) ? $customer_data['name'] : '';
            $email = isset($customer_data['email']) ? $customer_data['email'] : '';

            if ($name && $email) {
                return sprintf('%1$s (%2$s)', $name, $email);
            }

            if ($name) {
                return $name;
            }

            if ($email) {
                return $email;
            }

            return isset($customer_data['id']) ? $customer_data['id'] : __('Unknown customer', 'acf-stripe-customer-field');
        }

        /**
         * Process value before saving to database.
         *
         * @param mixed $value The field value.
         * @param int   $post_id Post ID.
         * @param array $field Field array.
         * @return mixed
         */
        public function update_value($value, $post_id, $field)
        {
            // If no value, return empty
            if (empty($value)) {
                return '';
            }

            // If it's already our expected format, return as-is
            if (is_array($value) && isset($value['id'])) {
                return $value;
            }

            // If it's just a customer ID string, we need to enrich it
            // This happens when the form submits just the customer ID
            if (is_string($value) && preg_match('/^cus_[a-zA-Z0-9]+$/', $value)) {
                // Check if we have additional data from the hidden field
                $data_field_name = $field['name'] . '_data';
                if (isset($_POST[$data_field_name])) {
                    $posted_data = json_decode(stripslashes($_POST[$data_field_name]), true);
                    if (is_array($posted_data) && $posted_data['id'] === $value) {
                        return [
                            'id' => $value,
                            'name' => $posted_data['name'] ?? '',
                            'email' => $posted_data['email'] ?? ''
                        ];
                    }
                }

                // Fallback: try to fetch customer data from Stripe
                if ($this->plugin->is_connected()) {
                    $customer = $this->plugin->fetch_customer($value);
                    if (!is_wp_error($customer)) {
                        return [
                            'id' => $customer['id'],
                            'name' => $customer['name'] ?? '',
                            'email' => $customer['email'] ?? ''
                        ];
                    }
                }

                // Last resort: store just the ID
                return [
                    'id' => $value,
                    'name' => '',
                    'email' => ''
                ];
            }

            return $value;
        }

        /**
         * Format the value when loaded from the database.
         *
         * @param mixed  $value   The raw value.
         * @param int    $post_id Post ID.
         * @param array  $field   Field settings.
         * @return mixed
         */
        public function format_value($value, $post_id, $field)
        {
            if (empty($value)) {
                return $value;
            }

            $return_format = isset($field['return_format']) ? $field['return_format'] : 'id';
            $customer_data = $this->parse_customer_value($value);

            if ('object' === $return_format) {
                // For object format, try to return enriched data or fetch from API
                if (!empty($customer_data['name']) || !empty($customer_data['email'])) {
                    // We have cached data, return it as object-like array
                    return [
                        'id' => $customer_data['id'],
                        'name' => $customer_data['name'],
                        'email' => $customer_data['email']
                    ];
                }

                // Try to fetch from Stripe API as fallback
                if ($this->plugin->is_connected() && !empty($customer_data['id'])) {
                    $customer = $this->plugin->fetch_customer($customer_data['id']);
                    if (!is_wp_error($customer)) {
                        return $customer;
                    }
                }

                return null;
            }

            // Default: return just the customer ID
            return $customer_data['id'];
        }
    }
}
