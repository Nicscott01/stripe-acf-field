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
        $value       = isset($field['value']) ? $field['value'] : '';
        $value       = is_array($value) ? '' : $value;
        $placeholder = !empty($field['placeholder']) ? $field['placeholder'] : __('Select a Stripe customer', 'acf-stripe-customer-field');
        $allow_null  = !empty($field['allow_null']);
        $connected   = $this->plugin->is_connected();
        $selected_label = '';

        if ($value && $connected) {
            if (method_exists($this->plugin, 'fetch_customer')) {
                $customer = $this->plugin->fetch_customer($value);
            } else {
                $customer = new WP_Error('no_method', __('Method fetch_customer does not exist', 'acf-stripe-customer-field'));
            }
            if (!is_wp_error($customer)) {
                $selected_label = $this->plugin->format_customer_label($customer);
            }
        }

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

        if ($value) {
            printf('<option value="%1$s" selected="selected">%2$s</option>', esc_attr($value), esc_html($selected_label ? $selected_label : $value));
        }

        echo '</select>';
        
        // Add debug information when WP_DEBUG is enabled
        if (defined('WP_DEBUG') && WP_DEBUG) {
            printf(
                '<!-- ACF Stripe Customer Field Debug: Connected=%s, Value=%s, Label=%s -->',
                $connected ? 'true' : 'false',
                esc_attr($value),
                esc_attr($selected_label)
            );
        }

        echo '</div>';
    }        /**
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

            if ('object' !== $return_format) {
                return $value;
            }

            if (!$this->plugin->is_connected()) {
                return null;
            }

            $customer = $this->plugin->fetch_customer($value);
            if (is_wp_error($customer)) {
                return null;
            }

            return $customer;
        }
    }
}
