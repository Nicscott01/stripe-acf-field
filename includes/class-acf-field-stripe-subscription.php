<?php
if (!defined('ABSPATH')) {
    exit;
}
if (!class_exists('ACF_Field_Stripe_Subscription') && class_exists('acf_field')) {
    class ACF_Field_Stripe_Subscription extends acf_field
    {
        /**
         * Parent plugin instance.
         *
         * @var ACF_Stripe_Subscription_Field_Plugin
         */
        protected $plugin;

        /**
         * Constructor.
         *
         * @param ACF_Stripe_Subscription_Field_Plugin $plugin Plugin instance.
         */
        public function __construct($plugin)
        {
            $this->plugin = $plugin;

            $this->name     = 'stripe_subscription';
            $this->label    = __('Stripe Subscription', 'acf-stripe-subscription-field');
            $this->category = 'relational';
            $this->defaults = [
                'return_format' => 'id',
                'placeholder'   => __('Select a Stripe subscription', 'acf-stripe-subscription-field'),
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
                'label'        => __('Placeholder Text', 'acf-stripe-subscription-field'),
                'instructions' => __('Text shown when no subscription is selected.', 'acf-stripe-subscription-field'),
                'type'         => 'text',
                'name'         => 'placeholder',
            ]);

            acf_render_field_setting($field, [
                'label'        => __('Allow Null', 'acf-stripe-subscription-field'),
                'instructions' => __('Allow the field to be cleared.', 'acf-stripe-subscription-field'),
                'type'         => 'true_false',
                'name'         => 'allow_null',
                'ui'           => 1,
            ]);

            acf_render_field_setting($field, [
                'label'        => __('Return Format', 'acf-stripe-subscription-field'),
                'instructions' => __('Specify the returned value when using the field.', 'acf-stripe-subscription-field'),
                'type'         => 'radio',
                'name'         => 'return_format',
                'layout'       => 'horizontal',
                'choices'      => [
                    'id'     => __('Subscription ID', 'acf-stripe-subscription-field'),
                    'object' => __('Stripe Subscription object', 'acf-stripe-subscription-field'),
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
                error_log('ACF Stripe Subscription render_field - Raw field value: ' . print_r($raw_value, true));
                error_log('ACF Stripe Subscription render_field - Field structure: ' . print_r($field, true));
            }

            $subscription_data = $this->parse_subscription_value($raw_value);
            $subscription_id   = $subscription_data['id'];
            $selected_label    = $subscription_data['label'];

            // Debug logging
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ACF Stripe Subscription render_field - Parsed data: ' . print_r($subscription_data, true));
                error_log('ACF Stripe Subscription render_field - Selected label: ' . $selected_label);
            }

            $placeholder = !empty($field['placeholder']) ? $field['placeholder'] : __('Select a Stripe subscription', 'acf-stripe-subscription-field');
            $allow_null  = !empty($field['allow_null']);
            $connected   = $this->plugin->is_connected();

            $select_attributes = [
                'class'              => 'acf-stripe-subscription-select',
                'name'               => $field['name'],
                'data-placeholder'   => $placeholder,
                'data-allow-clear'   => $allow_null ? '1' : '0',
                'data-selected-text' => $selected_label,
            ];

            if (!$connected) {
                $select_attributes['disabled'] = 'disabled';
            }

            echo '<div class="acf-stripe-subscription-field">';

            if (!$connected) {
                $hint = $this->plugin->get_settings_menu_hint();
                printf(
                    '<p class="description acf-stripe-subscription-notice" style="color: #d63638;">%s</p>',
                    esc_html(sprintf(__('Connect your Stripe account from %s to load subscriptions.', 'acf-stripe-subscription-field'), $hint))
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

            if ($subscription_id) {
                printf('<option value="%1$s" selected="selected">%2$s</option>', esc_attr($subscription_id), esc_html($selected_label ? $selected_label : $subscription_id));
            }

            echo '</select>';

            // Store subscription data in hidden field for JavaScript access
            if (!empty($subscription_data['id'])) {
                $hidden_data = [
                    'id'             => $subscription_data['id'],
                    'label'          => isset($subscription_data['label']) ? $subscription_data['label'] : '',
                    'plan'           => isset($subscription_data['plan']) ? $subscription_data['plan'] : '',
                    'status'         => isset($subscription_data['status']) ? $subscription_data['status'] : '',
                    'customer_id'    => isset($subscription_data['customer_id']) ? $subscription_data['customer_id'] : '',
                    'customer_name'  => isset($subscription_data['customer_name']) ? $subscription_data['customer_name'] : (isset($subscription_data['name']) ? $subscription_data['name'] : ''),
                    'customer_email' => isset($subscription_data['customer_email']) ? $subscription_data['customer_email'] : (isset($subscription_data['email']) ? $subscription_data['email'] : ''),
                    'name'           => isset($subscription_data['customer_name']) ? $subscription_data['customer_name'] : (isset($subscription_data['name']) ? $subscription_data['name'] : ''),
                    'email'          => isset($subscription_data['customer_email']) ? $subscription_data['customer_email'] : (isset($subscription_data['email']) ? $subscription_data['email'] : ''),
                ];

                printf(
                    '<input type="hidden" name="%s_data" value="%s" />',
                    esc_attr($field['name']),
                    esc_attr(json_encode($hidden_data))
                );
            }

            // Add debug information when WP_DEBUG is enabled
            if (defined('WP_DEBUG') && WP_DEBUG) {
                printf(
                    '<!-- ACF Stripe Subscription Field Debug: Connected=%s, ID=%s, Label=%s, Data=%s -->',
                    $connected ? 'true' : 'false',
                    esc_attr($subscription_id),
                    esc_attr($selected_label),
                    esc_attr(json_encode($subscription_data))
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
                error_log('ACF Stripe Subscription load_value - Raw from DB: ' . print_r($value, true));
            }

            // If the value is an array (our new format), extract just the ID for the form field
            if (is_array($value) && isset($value['id'])) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('ACF Stripe Subscription load_value - Returning ID: ' . $value['id']);
                }
                return $value['id'];
            }

            // If it's already a string (legacy format), return as-is
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ACF Stripe Subscription load_value - Returning as-is: ' . print_r($value, true));
            }

            return $value;
        }

        /**
         * Parse subscription value from database.
         *
         * @param mixed $value Raw value from database.
         * @return array Subscription data array.
         */
        protected function parse_subscription_value($value)
        {
            $default = [
                'id'             => '',
                'label'          => '',
                'plan'           => '',
                'status'         => '',
                'customer_id'    => '',
                'customer_name'  => '',
                'customer_email' => '',
                'name'           => '',
                'email'          => ''
            ];

            if (empty($value)) {
                return $default;
            }

            // If it's already an array (new format), use it
            if (is_array($value) && isset($value['id'])) {
                $subscription_data = array_merge($default, $value);
                $subscription_data = $this->normalize_subscription_array($subscription_data);
                if (empty($subscription_data['label'])) {
                    $subscription_data['label'] = $this->format_subscription_label_from_data($subscription_data);
                }
                return $subscription_data;
            }

            // If it's a JSON string, decode it first
            if (is_string($value) && !empty($value)) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && isset($decoded['id'])) {
                    // It's a JSON string with subscription data
                    $subscription_data = array_merge($default, $decoded);
                    $subscription_data = $this->normalize_subscription_array($subscription_data);
                    if (empty($subscription_data['label'])) {
                        $subscription_data['label'] = $this->format_subscription_label_from_data($subscription_data);
                    }
                    return $subscription_data;
                }

                // If it's not JSON or doesn't decode properly, treat as subscription ID
                return [
                    'id'             => $value,
                    'label'          => $value,
                    'plan'           => '',
                    'status'         => '',
                    'customer_id'    => '',
                    'customer_name'  => '',
                    'customer_email' => '',
                    'name'           => '',
                    'email'          => ''
                ];
            }

            return $default;
        }

        /**
         * Format subscription label from stored data.
         *
         * @param array $subscription_data Subscription data array.
         * @return string Formatted label.
         */
        protected function format_subscription_label_from_data($subscription_data)
        {
            if (!empty($subscription_data['label'])) {
                return $subscription_data['label'];
            }

            $plan   = isset($subscription_data['plan']) ? $subscription_data['plan'] : '';
            $status = isset($subscription_data['status']) ? $subscription_data['status'] : '';
            if ($plan && $status) {
                return sprintf('%1$s (%2$s)', $plan, $status);
            }

            if ($plan) {
                return $plan;
            }

            $name = isset($subscription_data['customer_name']) ? $subscription_data['customer_name'] : (isset($subscription_data['name']) ? $subscription_data['name'] : '');
            $email = isset($subscription_data['customer_email']) ? $subscription_data['customer_email'] : (isset($subscription_data['email']) ? $subscription_data['email'] : '');

            if ($name && $email) {
                return sprintf('%1$s (%2$s)', $name, $email);
            }

            if ($name) {
                return $name;
            }

            if ($email) {
                return $email;
            }

            if ($status) {
                return $status;
            }

            return isset($subscription_data['id']) ? $subscription_data['id'] : __('Unknown subscription', 'acf-stripe-subscription-field');
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

            // If it's already our expected format, normalise before saving
            if (is_array($value) && isset($value['id'])) {
                return $this->normalize_subscription_array($value);
            }

            // If it's just a subscription ID string, we need to enrich it
            // This happens when the form submits just the subscription ID
            if (is_string($value) && preg_match('/^sub_[a-zA-Z0-9]+$/', $value)) {
                // Check if we have additional data from the hidden field
                $data_field_name = $field['name'] . '_data';
                if (isset($_POST[$data_field_name])) {
                    $posted_data = json_decode(stripslashes($_POST[$data_field_name]), true);
                    if (is_array($posted_data)) {
                        $posted_data['id'] = $value;
                        return $this->normalize_subscription_array($posted_data);
                    }
                }

                // Fallback: try to fetch subscription data from Stripe
                if ($this->plugin->is_connected()) {
                    $subscription = $this->plugin->fetch_subscription($value);
                    if (!is_wp_error($subscription)) {
                        return $this->normalize_subscription_array($this->map_subscription_response($subscription));
                    }
                }

                // Last resort: store just the ID
                return $this->normalize_subscription_array(['id' => $value]);
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

            $return_format     = isset($field['return_format']) ? $field['return_format'] : 'id';
            $subscription_data = $this->parse_subscription_value($value);
            $subscription_data = $this->normalize_subscription_array($subscription_data);

            if ('object' === $return_format) {
                if ($this->plugin->is_connected() && !$this->has_subscription_details($subscription_data) && !empty($subscription_data['id'])) {
                    $subscription = $this->plugin->fetch_subscription($subscription_data['id']);
                    if (!is_wp_error($subscription)) {
                        $subscription_data = $this->normalize_subscription_array($this->map_subscription_response($subscription));
                    }
                }

                return $this->prepare_subscription_return_object($subscription_data);
            }

            // Default: return just the subscription ID
            return $subscription_data['id'];
        }

        /**
         * Normalise subscription data for storage and reuse.
         *
         * @param array $data Raw subscription data.
         * @return array
         */
        protected function normalize_subscription_array($data)
        {
            $defaults = [
                'id'             => '',
                'label'          => '',
                'plan'           => '',
                'status'         => '',
                'customer_id'    => '',
                'customer_name'  => '',
                'customer_email' => '',
                'name'           => '',
                'email'          => '',
            ];

            $normalized = array_merge($defaults, is_array($data) ? $data : []);

            if (empty($normalized['customer_name']) && !empty($normalized['name'])) {
                $normalized['customer_name'] = $normalized['name'];
            }

            if (empty($normalized['customer_email']) && !empty($normalized['email'])) {
                $normalized['customer_email'] = $normalized['email'];
            }

            if (empty($normalized['name']) && !empty($normalized['customer_name'])) {
                $normalized['name'] = $normalized['customer_name'];
            }

            if (empty($normalized['email']) && !empty($normalized['customer_email'])) {
                $normalized['email'] = $normalized['customer_email'];
            }

            if (empty($normalized['label'])) {
                $normalized['label'] = $this->format_subscription_label_from_data($normalized);
            }

            return $normalized;
        }

        /**
         * Extract subscription data from a Stripe API response.
         *
         * @param array $subscription Subscription response.
         * @return array
         */
        protected function map_subscription_response($subscription)
        {
            if (!is_array($subscription)) {
                return ['id' => is_string($subscription) ? $subscription : ''];
            }

            $plan_name = '';
            if (!empty($subscription['plan']['nickname'])) {
                $plan_name = $subscription['plan']['nickname'];
            } elseif (!empty($subscription['plan']['id'])) {
                $plan_name = $subscription['plan']['id'];
            } elseif (!empty($subscription['items']['data'][0]['plan']['nickname'])) {
                $plan_name = $subscription['items']['data'][0]['plan']['nickname'];
            } elseif (!empty($subscription['items']['data'][0]['plan']['id'])) {
                $plan_name = $subscription['items']['data'][0]['plan']['id'];
            }

            $customer_id    = '';
            $customer_name  = '';
            $customer_email = '';

            if (isset($subscription['customer'])) {
                if (is_array($subscription['customer'])) {
                    $customer_id    = isset($subscription['customer']['id']) ? $subscription['customer']['id'] : '';
                    $customer_name  = isset($subscription['customer']['name']) ? $subscription['customer']['name'] : '';
                    $customer_email = isset($subscription['customer']['email']) ? $subscription['customer']['email'] : '';
                } else {
                    $customer_id = (string) $subscription['customer'];
                }
            }

            return [
                'id'             => isset($subscription['id']) ? $subscription['id'] : '',
                'label'          => $this->plugin->format_subscription_label($subscription),
                'plan'           => $plan_name,
                'status'         => isset($subscription['status']) ? $subscription['status'] : '',
                'customer_id'    => $customer_id,
                'customer_name'  => $customer_name,
                'customer_email' => $customer_email,
                'name'           => $customer_name,
                'email'          => $customer_email,
            ];
        }

        /**
         * Determine if stored subscription data already has descriptive fields.
         *
         * @param array $subscription_data Normalised subscription data.
         * @return bool
         */
        protected function has_subscription_details($subscription_data)
        {
            $detail_keys = ['label', 'plan', 'status', 'customer_name', 'customer_email', 'name', 'email'];

            foreach ($detail_keys as $key) {
                if (!empty($subscription_data[$key]) && $subscription_data[$key] !== $subscription_data['id']) {
                    return true;
                }
            }

            return false;
        }

        /**
         * Prepare the value returned when return_format === object.
         *
         * @param array $subscription_data Normalised subscription data.
         * @return array
         */
        protected function prepare_subscription_return_object($subscription_data)
        {
            return [
                'id'             => $subscription_data['id'],
                'label'          => $subscription_data['label'],
                'plan'           => $subscription_data['plan'],
                'status'         => $subscription_data['status'],
                'customer_id'    => $subscription_data['customer_id'],
                'customer_name'  => $subscription_data['customer_name'],
                'customer_email' => $subscription_data['customer_email'],
                'name'           => $subscription_data['name'],
                'email'          => $subscription_data['email'],
            ];
        }
    }
}
