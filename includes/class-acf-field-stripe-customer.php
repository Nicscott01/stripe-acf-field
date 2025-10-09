<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('ACF_Field_Stripe_Customer') && class_exists('acf_field')) {
    class ACF_Field_Stripe_Customer extends ACF_Field_Stripe_Base
    {
        /**
         * Constructor.
         *
         * @param ACF_Stripe_Customer_Field_Plugin $plugin Plugin instance.
         */
        public function __construct($plugin)
        {
            $this->plugin = $plugin;
            $this->stripe_object_type = 'customer';

            $this->name  = 'stripe_customer';
            $this->label = __('Stripe Customer', 'acf-stripe-customer-field');

            parent::__construct($plugin);

            // Ensure placeholder text uses customer-specific translation domain.
            $this->defaults['placeholder'] = __('Select a Stripe customer', 'acf-stripe-customer-field');
        }

        /**
         * {@inheritdoc}
         */
        protected function get_object_display_name()
        {
            return __('Customer', 'acf-stripe-customer-field');
        }

        /**
         * {@inheritdoc}
         */
        protected function get_stripe_object_type()
        {
            return 'customer';
        }

        /**
         * {@inheritdoc}
         */
        protected function get_stripe_objects($search = '', $filters = [])
        {
            $response = $this->plugin->fetch_customers($search);
            return is_wp_error($response) ? $response : ($response['data'] ?? []);
        }

        /**
         * {@inheritdoc}
         */
        protected function fetch_stripe_object($object_id)
        {
            $customer = $this->plugin->fetch_customer($object_id);
            if (is_wp_error($customer)) {
                return $customer;
            }

            return $this->normalize_customer_data($customer);
        }

        /**
         * {@inheritdoc}
         */
        protected function format_object_label($object)
        {
            return $this->plugin->format_customer_label($object);
        }

        /**
         * {@inheritdoc}
         */
        protected function get_display_fields()
        {
            return ['name', 'email', 'label'];
        }

        /**
         * {@inheritdoc}
         */
        public function update_value($value, $post_id, $field)
        {
            $updated = parent::update_value($value, $post_id, $field);

            if (is_array($updated) && isset($updated['id'])) {
                return $this->normalize_customer_data($updated);
            }

            return $updated;
        }

        /**
         * {@inheritdoc}
         */
        public function format_value($value, $post_id, $field)
        {
            $formatted = parent::format_value($value, $post_id, $field);

            if (isset($field['return_format']) && 'object' === $field['return_format']) {
                if (is_array($formatted)) {
                    return $this->normalize_customer_data($formatted);
                }

                if (is_string($formatted) && $this->is_valid_object_id($formatted)) {
                    $customer = $this->fetch_stripe_object($formatted);
                    if (!is_wp_error($customer) && is_array($customer)) {
                        return $this->normalize_customer_data($customer);
                    }
                }
            }

            return $formatted;
        }

        /**
         * Override to handle legacy storage formats.
         *
         * {@inheritdoc}
         */
        protected function parse_object_value($value)
        {
            $parsed = parent::parse_object_value($value);
            if (!empty($parsed['id'])) {
                $parsed['label'] = $this->format_object_label($parsed);
            }
            return $parsed;
        }

        /**
         * Normalise customer data to the storage structure used by the base class.
         *
         * @param array $data Stripe customer data.
         * @return array
         */
        protected function normalize_customer_data($data)
        {
            $normalized = [
                'id'    => isset($data['id']) ? $data['id'] : '',
                'name'  => isset($data['name']) ? $data['name'] : '',
                'email' => isset($data['email']) ? $data['email'] : '',
            ];

            $normalized['label'] = $this->format_object_label($normalized);

            return $normalized;
        }
    }
}
