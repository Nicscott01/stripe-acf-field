<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('ACF_Field_Stripe_Product') && class_exists('acf_field')) {
    class ACF_Field_Stripe_Product extends ACF_Field_Stripe_Base
    {
        /**
         * Constructor.
         *
         * @param ACF_Stripe_Customer_Field_Plugin $plugin Plugin instance.
         */
        public function __construct($plugin)
        {
            $this->plugin = $plugin;
            $this->stripe_object_type = 'product';

            $this->name  = 'stripe_product';
            $this->label = __('Stripe Product', 'acf-stripe-product-field');

            parent::__construct($plugin);

            $this->defaults['placeholder'] = __('Select a Stripe product', 'acf-stripe-product-field');
        }

        /**
         * {@inheritdoc}
         */
        protected function get_object_display_name()
        {
            return __('Product', 'acf-stripe-product-field');
        }

        /**
         * {@inheritdoc}
         */
        protected function get_stripe_object_type()
        {
            return 'product';
        }

        /**
         * {@inheritdoc}
         */
        protected function get_display_fields()
        {
            return [
                'label',
                'name',
                'description',
                'active',
                'price_amount',
                'price_currency',
                'price_interval',
            ];
        }

        /**
         * {@inheritdoc}
         */
        protected function get_stripe_objects($search = '', $filters = [])
        {
            $response = $this->plugin->fetch_products($search);
            if (is_wp_error($response)) {
                return $response;
            }

            $products = [];
            if (!empty($response['data']) && is_array($response['data'])) {
                foreach ($response['data'] as $product) {
                    $products[] = $this->normalize_product_array($product);
                }
            }

            return $products;
        }

        /**
         * {@inheritdoc}
         */
        protected function fetch_stripe_object($object_id)
        {
            $product = $this->plugin->fetch_product($object_id);
            if (is_wp_error($product)) {
                return $product;
            }

            return $this->normalize_product_array($product);
        }

        /**
         * {@inheritdoc}
         */
        protected function format_object_label($object)
        {
            return $this->plugin->format_product_label($object);
        }

        /**
         * {@inheritdoc}
         */
        public function update_value($value, $post_id, $field)
        {
            $updated = parent::update_value($value, $post_id, $field);

            if (is_array($updated) && isset($updated['id'])) {
                return $this->normalize_product_array($updated);
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
                    return $this->normalize_product_array($formatted);
                }

                if (is_string($formatted) && $this->is_valid_object_id($formatted)) {
                    $product = $this->fetch_stripe_object($formatted);
                    if (!is_wp_error($product) && is_array($product)) {
                        return $this->normalize_product_array($product);
                    }
                }

                return null;
            }

            return $formatted;
        }

        /**
         * Normalize stored product data.
         *
         * @param array $data Raw product data.
         * @return array
         */
        protected function normalize_product_array($data)
        {
            if (!is_array($data)) {
                return [
                    'id'             => '',
                    'label'          => '',
                    'name'           => '',
                    'description'    => '',
                    'active'         => false,
                    'price_amount'   => '',
                    'price_currency' => '',
                    'price_interval' => '',
                ];
            }

            $price_amount = isset($data['price_amount']) ? $data['price_amount'] : '';
            $price_currency = isset($data['price_currency']) ? $data['price_currency'] : '';
            $price_interval = isset($data['price_interval']) ? $data['price_interval'] : '';

            if ('' === $price_amount && isset($data['default_price'])) {
                $default_price = $data['default_price'];
                if (is_array($default_price)) {
                    if (isset($default_price['unit_amount'])) {
                        $price_amount = $default_price['unit_amount'] / 100;
                    }
                    if (isset($default_price['currency'])) {
                        $price_currency = $default_price['currency'];
                    }
                    if (isset($default_price['recurring']['interval'])) {
                        $price_interval = $default_price['recurring']['interval'];
                    }
                }
            }

            $normalized = [
                'id'             => isset($data['id']) ? $data['id'] : '',
                'name'           => isset($data['name']) ? $data['name'] : '',
                'description'    => isset($data['description']) ? $data['description'] : '',
                'active'         => isset($data['active']) ? (bool) $data['active'] : false,
                'price_amount'   => $price_amount,
                'price_currency' => $price_currency,
                'price_interval' => $price_interval,
            ];

            $normalized['label'] = $this->plugin->format_product_label($normalized);

            return $normalized;
        }
    }
}
