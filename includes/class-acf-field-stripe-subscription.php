<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('ACF_Field_Stripe_Subscription') && class_exists('acf_field')) {
    class ACF_Field_Stripe_Subscription extends ACF_Field_Stripe_Base
    {
        /**
         * Constructor.
         *
         * @param ACF_Stripe_Subscription_Field_Plugin $plugin Plugin instance.
         */
        public function __construct($plugin)
        {
            $this->plugin = $plugin;
            $this->stripe_object_type = 'subscription';

            $this->name  = 'stripe_subscription';
            $this->label = __('Stripe Subscription', 'acf-stripe-subscription-field');

            parent::__construct($plugin);

            $this->defaults['placeholder'] = __('Select a Stripe subscription', 'acf-stripe-subscription-field');
        }

        /**
         * {@inheritdoc}
         */
        protected function get_object_display_name()
        {
            return __('Subscription', 'acf-stripe-subscription-field');
        }

        /**
         * {@inheritdoc}
         */
        protected function get_stripe_object_type()
        {
            return 'subscription';
        }

        /**
         * {@inheritdoc}
         */
        protected function get_display_fields()
        {
            return [
                'label',
                'plan',
                'status',
                'customer_id',
                'customer_name',
                'customer_email',
                'name',
                'email',
            ];
        }

        /**
         * {@inheritdoc}
         */
        protected function get_stripe_objects($search = '', $filters = [])
        {
            $response = $this->plugin->fetch_subscriptions($search);
            if (is_wp_error($response)) {
                return $response;
            }

            $normalized = [];
            if (!empty($response['data']) && is_array($response['data'])) {
                foreach ($response['data'] as $subscription) {
                    $normalized[] = $this->map_subscription_response($subscription);
                }
            }

            return $normalized;
        }

        /**
         * {@inheritdoc}
         */
        protected function fetch_stripe_object($object_id)
        {
            $subscription = $this->plugin->fetch_subscription($object_id);
            if (is_wp_error($subscription)) {
                return $subscription;
            }

            return $this->map_subscription_response($subscription);
        }

        /**
         * {@inheritdoc}
         */
        protected function format_object_label($object)
        {
            return $this->build_label_from_data($object);
        }

        /**
         * Override parse logic to maintain backwards compatibility with stored data.
         *
         * {@inheritdoc}
         */
        protected function parse_object_value($value)
        {
            $parsed = $this->parse_subscription_value($value);
            $parsed['label'] = $this->build_label_from_data($parsed);
            return $parsed;
        }

        /**
         * Override update logic to normalise subscription data before saving.
         *
         * {@inheritdoc}
         */
        public function update_value($value, $post_id, $field)
        {
            $normalized = parent::update_value($value, $post_id, $field);

            if (is_array($normalized) && isset($normalized['id'])) {
                $normalized = $this->normalize_subscription_array($normalized);
            }

            return $normalized;
        }

        /**
         * Override format_value so object returns contain subscription fields.
         *
         * {@inheritdoc}
         */
        public function format_value($value, $post_id, $field)
        {
            $formatted = parent::format_value($value, $post_id, $field);

            if (isset($field['return_format']) && 'object' === $field['return_format']) {
                if (is_array($formatted)) {
                    return $this->normalize_subscription_array($formatted);
                }

                if (is_string($formatted) && $this->is_valid_object_id($formatted)) {
                    $subscription = $this->fetch_stripe_object($formatted);
                    if (!is_wp_error($subscription) && is_array($subscription)) {
                        return $this->normalize_subscription_array($subscription);
                    }
                }

                return null;
            }

            return $formatted;
        }

        /**
         * Parse subscription value from database (legacy compatibility).
         *
         * @param mixed $value Raw value from database.
         * @return array Normalised subscription data.
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

            if (is_array($value) && isset($value['id'])) {
                return $this->normalize_subscription_array(array_merge($default, $value));
            }

            if (is_string($value) && !empty($value)) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && isset($decoded['id'])) {
                    return $this->normalize_subscription_array(array_merge($default, $decoded));
                }

                return $this->normalize_subscription_array(array_merge($default, [
                    'id'    => $value,
                    'label' => $value
                ]));
            }

            return $default;
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

            $normalized['label'] = $this->build_label_from_data($normalized);

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
            } elseif (!empty($subscription['items']['data'][0]['price']['nickname'])) {
                $plan_name = $subscription['items']['data'][0]['price']['nickname'];
            } elseif (!empty($subscription['items']['data'][0]['price']['id'])) {
                $plan_name = $subscription['items']['data'][0]['price']['id'];
            }

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

            $normalized = [
                'id'             => isset($subscription['id']) ? $subscription['id'] : '',
                'plan'           => $plan_name,
                'status'         => isset($subscription['status']) ? $subscription['status'] : '',
                'customer_id'    => $customer_id,
                'customer_name'  => $customer_name,
                'customer_email' => $customer_email,
                'name'           => $customer_name,
                'email'          => $customer_email,
            ];

            $normalized['label'] = $this->build_label_from_data($normalized);

            return $normalized;
        }

        /**
         * Build a display label from normalised data.
         *
         * @param array $data Subscription data.
         * @return string
         */
        protected function build_label_from_data($data)
        {
            $plan = isset($data['plan']) ? $data['plan'] : '';
            $status = isset($data['status']) ? $data['status'] : '';
            $id = isset($data['id']) ? $data['id'] : '';

            $customer_display = $this->plugin->format_subscription_customer_display(
                isset($data['customer_name']) ? $data['customer_name'] : (isset($data['name']) ? $data['name'] : ''),
                isset($data['customer_email']) ? $data['customer_email'] : (isset($data['email']) ? $data['email'] : ''),
                isset($data['customer_id']) ? $data['customer_id'] : ''
            );

            return $this->plugin->build_subscription_label($plan, $customer_display, $status, $id);
        }

    }
}
