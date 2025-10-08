<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('ACF_Field_Stripe_Base') && class_exists('acf_field')) {
    /**
     * Base class for all Stripe field types.
     * 
     * This abstract class provides common functionality for Stripe object fields
     * like customers, subscriptions, products, etc.
     */
    abstract class ACF_Field_Stripe_Base extends acf_field
    {
        /**
         * Parent plugin instance.
         *
         * @var ACF_Stripe_Customer_Field_Plugin
         */
        protected $plugin;

        /**
         * The Stripe object type (customer, subscription, product, etc.)
         *
         * @var string
         */
        protected $stripe_object_type;

        /**
         * Constructor.
         *
         * @param ACF_Stripe_Customer_Field_Plugin $plugin Plugin instance.
         */
        public function __construct($plugin)
        {
            $this->plugin = $plugin;
            
            // Set common properties
            $this->category = 'relational';
            $this->defaults = [
                'return_format' => 'id',
                'placeholder'   => sprintf(__('Select a Stripe %s', 'acf-stripe-field'), $this->get_object_display_name()),
                'allow_null'    => 0,
            ];

            parent::__construct();
        }

        /**
         * Get the display name for this Stripe object type.
         * 
         * @return string
         */
        abstract protected function get_object_display_name();

        /**
         * Get the Stripe object type identifier.
         * 
         * @return string
         */
        abstract protected function get_stripe_object_type();

        /**
         * Get Stripe objects from the API.
         * 
         * @param string $search Search term.
         * @param array  $filters Additional filters.
         * @return array|WP_Error
         */
        abstract protected function get_stripe_objects($search = '', $filters = []);

        /**
         * Fetch a single Stripe object by ID.
         * 
         * @param string $object_id Object ID.
         * @return array|WP_Error
         */
        abstract protected function fetch_stripe_object($object_id);

        /**
         * Format object label for display.
         * 
         * @param array $object Stripe object data.
         * @return string
         */
        abstract protected function format_object_label($object);

        /**
         * Get the fields to display in the object label.
         * 
         * @return array Array of field names to use for display.
         */
        abstract protected function get_display_fields();

        /**
         * Render field settings that appear when editing the field.
         *
         * @param array $field Field settings.
         * @return void
         */
        public function render_field_settings($field)
        {
            $object_name = $this->get_object_display_name();

            acf_render_field_setting($field, [
                'label'        => __('Placeholder Text', 'acf-stripe-field'),
                'instructions' => sprintf(__('Text shown when no %s is selected.', 'acf-stripe-field'), strtolower($object_name)),
                'type'         => 'text',
                'name'         => 'placeholder',
            ]);

            acf_render_field_setting($field, [
                'label'        => __('Allow Null', 'acf-stripe-field'),
                'instructions' => __('Allow the field to be cleared.', 'acf-stripe-field'),
                'type'         => 'true_false',
                'name'         => 'allow_null',
                'ui'           => 1,
            ]);

            acf_render_field_setting($field, [
                'label'        => __('Return Format', 'acf-stripe-field'),
                'instructions' => __('Specify the returned value when using the field.', 'acf-stripe-field'),
                'type'         => 'radio',
                'name'         => 'return_format',
                'layout'       => 'horizontal',
                'choices'      => [
                    'id'     => sprintf(__('%s ID', 'acf-stripe-field'), $object_name),
                    'object' => sprintf(__('Stripe %s object', 'acf-stripe-field'), $object_name),
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
                error_log("ACF Stripe {$this->get_stripe_object_type()} render_field - Raw field value: " . print_r($raw_value, true));
            }
            
            $object_data = $this->parse_object_value($raw_value);
            $object_id = $object_data['id'];
            $selected_label = $object_data['label'];
            
            $object_name = $this->get_object_display_name();
            $placeholder = !empty($field['placeholder']) ? $field['placeholder'] : sprintf(__('Select a Stripe %s', 'acf-stripe-field'), strtolower($object_name));
            $allow_null  = !empty($field['allow_null']);
            $connected   = $this->plugin->is_connected();

            $select_attributes = [
                'class'                 => 'acf-stripe-' . str_replace('_', '-', $this->get_stripe_object_type()) . '-select',
                'name'                  => $field['name'],
                'data-placeholder'      => $placeholder,
                'data-allow-clear'      => $allow_null ? '1' : '0',
                'data-selected-text'    => $selected_label,
                'data-stripe-object-type' => $this->get_stripe_object_type(),
            ];

            if (!$connected) {
                $select_attributes['disabled'] = 'disabled';
            }

            echo '<div class="acf-stripe-' . esc_attr(str_replace('_', '-', $this->get_stripe_object_type())) . '-field">';

            if (!$connected) {
                $hint = $this->plugin->get_settings_menu_hint();
                printf(
                    '<p class="description acf-stripe-notice" style="color: #d63638;">%s</p>',
                    esc_html(sprintf(__('Connect your Stripe account from %s to load %s.', 'acf-stripe-field'), $hint, strtolower($object_name) . 's'))
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

            if ($object_id) {
                printf('<option value="%1$s" selected="selected">%2$s</option>', esc_attr($object_id), esc_html($selected_label ? $selected_label : $object_id));
            }

            echo '</select>';
            
            // Store object data in hidden field for JavaScript access
            $display_fields = $this->get_display_fields();
            $has_display_data = false;
            foreach ($display_fields as $field_name) {
                if (!empty($object_data[$field_name])) {
                    $has_display_data = true;
                    break;
                }
            }

            if ($has_display_data) {
                $hidden_data = ['id' => $object_data['id']];
                foreach ($display_fields as $field_name) {
                    $hidden_data[$field_name] = $object_data[$field_name] ?? '';
                }
                
                printf(
                    '<input type="hidden" name="%s_data" value="%s" />',
                    esc_attr($field['name']),
                    esc_attr(json_encode($hidden_data))
                );
            }
            
            // Add debug information when WP_DEBUG is enabled
            if (defined('WP_DEBUG') && WP_DEBUG) {
                printf(
                    '<!-- ACF Stripe %s Field Debug: Connected=%s, ID=%s, Label=%s, Data=%s -->',
                    esc_attr($object_name),
                    $connected ? 'true' : 'false',
                    esc_attr($object_id),
                    esc_attr($selected_label),
                    esc_attr(json_encode($object_data))
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
                error_log("ACF Stripe {$this->get_stripe_object_type()} load_value - Raw from DB: " . print_r($value, true));
            }

            // If the value is an array (our new format), extract just the ID for the form field
            if (is_array($value) && isset($value['id'])) {
                return $value['id'];
            }

            // If it's already a string (legacy format), return as-is
            return $value;
        }

        /**
         * Parse object value from database.
         *
         * @param mixed $value Raw value from database.
         * @return array Object data array.
         */
        protected function parse_object_value($value)
        {
            $display_fields = $this->get_display_fields();
            $default = ['id' => '', 'label' => ''];
            
            // Initialize display fields
            foreach ($display_fields as $field_name) {
                $default[$field_name] = '';
            }

            if (empty($value)) {
                return $default;
            }

            // If it's already an array (new format), use it
            if (is_array($value) && isset($value['id'])) {
                $object_data = array_merge($default, $value);
                $object_data['label'] = $this->format_object_label($object_data);
                return $object_data;
            }

            // If it's a JSON string, decode it first
            if (is_string($value) && !empty($value)) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && isset($decoded['id'])) {
                    // It's a JSON string with object data
                    $object_data = array_merge($default, $decoded);
                    $object_data['label'] = $this->format_object_label($object_data);
                    return $object_data;
                }
                
                // If it's not JSON or doesn't decode properly, treat as object ID
                return array_merge($default, [
                    'id' => $value,
                    'label' => $value // Use ID as fallback label
                ]);
            }

            return $default;
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

            // If it's just an object ID string, we need to enrich it
            if (is_string($value) && $this->is_valid_object_id($value)) {
                // Check if we have additional data from the hidden field
                $data_field_name = $field['name'] . '_data';
                if (isset($_POST[$data_field_name])) {
                    $posted_data = json_decode(stripslashes($_POST[$data_field_name]), true);
                    if (is_array($posted_data) && $posted_data['id'] === $value) {
                        $display_fields = $this->get_display_fields();
                        $enriched_data = ['id' => $value];
                        foreach ($display_fields as $field_name) {
                            $enriched_data[$field_name] = $posted_data[$field_name] ?? '';
                        }
                        return $enriched_data;
                    }
                }

                // Fallback: try to fetch object data from Stripe
                if ($this->plugin->is_connected()) {
                    $object = $this->fetch_stripe_object($value);
                    if (!is_wp_error($object)) {
                        $display_fields = $this->get_display_fields();
                        $enriched_data = ['id' => $object['id']];
                        foreach ($display_fields as $field_name) {
                            $enriched_data[$field_name] = $object[$field_name] ?? '';
                        }
                        return $enriched_data;
                    }
                }

                // Last resort: store just the ID with empty display fields
                $display_fields = $this->get_display_fields();
                $minimal_data = ['id' => $value];
                foreach ($display_fields as $field_name) {
                    $minimal_data[$field_name] = '';
                }
                return $minimal_data;
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
            $object_data = $this->parse_object_value($value);

            if ('object' === $return_format) {
                $display_fields = $this->get_display_fields();
                $has_cached_data = false;
                
                // Check if we have cached display data
                foreach ($display_fields as $field_name) {
                    if (!empty($object_data[$field_name])) {
                        $has_cached_data = true;
                        break;
                    }
                }

                if ($has_cached_data) {
                    // We have cached data, return it as object-like array
                    $result = ['id' => $object_data['id']];
                    foreach ($display_fields as $field_name) {
                        $result[$field_name] = $object_data[$field_name];
                    }
                    return $result;
                }

                // Try to fetch from Stripe API as fallback
                if ($this->plugin->is_connected() && !empty($object_data['id'])) {
                    $object = $this->fetch_stripe_object($object_data['id']);
                    if (!is_wp_error($object)) {
                        return $object;
                    }
                }

                return null;
            }

            // Default: return just the object ID
            return $object_data['id'];
        }

        /**
         * Check if a string is a valid Stripe object ID for this object type.
         * 
         * @param string $id The ID to check.
         * @return bool
         */
        protected function is_valid_object_id($id)
        {
            $object_type = $this->get_stripe_object_type();
            $prefix_map = [
                'customer'     => 'cus_',
                'subscription' => 'sub_',
                'product'      => 'prod_',
                'price'        => 'price_',
                'invoice'      => 'in_',
            ];

            $prefix = $prefix_map[$object_type] ?? '';
            if (empty($prefix)) {
                return false;
            }

            return preg_match('/^' . preg_quote($prefix, '/') . '[a-zA-Z0-9]+$/', $id);
        }
    }
}