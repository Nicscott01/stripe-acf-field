(function($) {
    'use strict';

    if (typeof ACFStripeFieldBase === 'undefined') {
        console.error('ACFStripeFieldBase is required but not available.');
        return;
    }

    const OPTION_DATA_KEY = 'subscription-data';

    function resolveCustomerDisplay(data) {
        if (data.customer_name && data.customer_email) {
            return `${data.customer_name} (${data.customer_email})`;
        }
        if (data.customer_name) {
            return data.customer_name;
        }
        if (data.customer_email) {
            return data.customer_email;
        }
        if (data.customer_id) {
            return data.customer_id;
        }
        return '';
    }

    function buildSubscriptionLabel(data) {
        if (data.label) {
            return data.label;
        }

        const customerDisplay = resolveCustomerDisplay(data);
        let label = '';

        if (data.plan && customerDisplay) {
            label = `${data.plan} – ${customerDisplay}`;
        } else if (data.plan) {
            label = data.plan;
        } else if (customerDisplay) {
            label = customerDisplay;
        }

        const metaParts = [];
        if (data.id) {
            metaParts.push(data.id);
        }
        if (data.status) {
            metaParts.push(data.status);
        }

        if (!label) {
            label = data.id || 'Stripe subscription';
        }

        if (metaParts.length) {
            label += ` [${metaParts.join(' | ')}]`;
        }

        return label;
    }

    function resolveSubscriptionData(item) {
        if (!item) {
            return {};
        }

        if (item[OPTION_DATA_KEY]) {
            return item[OPTION_DATA_KEY];
        }

        if (item.subscriptionData) {
            return item.subscriptionData;
        }

        if (item.element) {
            const data = $(item.element).data(OPTION_DATA_KEY);
            if (data) {
                return data;
            }
        }

        const plan = item.plan || (item.items && item.items[0] && item.items[0].plan) || '';
        const status = item.status || '';
        const customer_name = item.customer_name || item.name || '';
        const customer_email = item.customer_email || item.email || '';
        const customer_id = item.customer_id || item.customer || '';

        return {
            id: item.id || '',
            label: item.text || '',
            text: item.text || '',
            plan: plan,
            status: status,
            customer_id: customer_id,
            customer_name: customer_name,
            customer_email: customer_email,
            name: customer_name,
            email: customer_email
        };
    }

    class ACFStripeSubscriptionField extends ACFStripeFieldBase {
        constructor() {
            super('subscription', {
                noticeSelector: '.acf-stripe-subscription-notice',
                optionDataKey: OPTION_DATA_KEY,
                loadedDataFlag: 'subscriptions-loaded'
            });
        }

        getGlobalConfig() {
            return window.acfStripeSubscriptionField || {};
        }

        buildAjaxData(search) {
            const cfg = this.getGlobalConfig();
            return {
                action: 'acf_stripe_search_subscriptions',
                nonce: cfg.nonce || '',
                search: search || '',
                page: 1
            };
        }

        getItemsFromResponse(response) {
            if (response && response.success && response.data && Array.isArray(response.data.items)) {
                return response.data.items;
            }
            return [];
        }

        getOptionData(item) {
            const raw = resolveSubscriptionData(item);
            const data = {
                id: raw.id || '',
                plan: raw.plan || '',
                status: raw.status || '',
                customer_id: raw.customer_id || '',
                customer_name: raw.customer_name || raw.name || '',
                customer_email: raw.customer_email || raw.email || ''
            };

            data.label = buildSubscriptionLabel(Object.assign({}, raw, data));
            data.text = raw.text || data.label;
            data.name = data.customer_name;
            data.email = data.customer_email;

            return data;
        }

        formatResult(item) {
            if (!item || item.loading) {
                return item && item.text ? item.text : '';
            }

            const data = resolveSubscriptionData(item);
            const label = buildSubscriptionLabel(data);
            const $container = $('<div class="acf-stripe-subscription-option"></div>');

            if (label) {
                $('<div class="subscription-label"></div>').text(label).appendTo($container);
            }

            if (data.plan && label.indexOf(data.plan) === -1) {
                $('<div class="subscription-plan"></div>').text(data.plan).appendTo($container);
            }

            if (data.status && label.indexOf(data.status) === -1) {
                $('<div class="subscription-status"></div>').text(data.status).appendTo($container);
            }

            const customerDisplay = resolveCustomerDisplay(data);
            if (customerDisplay) {
                $('<div class="subscription-customer"></div>').text(customerDisplay).appendTo($container);
            }

            if (data.id && label.indexOf(data.id) === -1) {
                $('<div class="subscription-id"></div>').text(data.id).appendTo($container);
            }

            return $container;
        }

        formatSelection(item) {
            if (!item) {
                return '';
            }

            const data = resolveSubscriptionData(item);
            return buildSubscriptionLabel(data);
        }

        handleInitialValue($select) {
            let initialValue = $select.val();
            const $field = $select.closest(this.config.fieldTypeSelector);

            if (!initialValue) {
                this.handleSelectionChange($field, $select);
                return;
            }

            if (typeof initialValue === 'object' && initialValue.id) {
                initialValue = initialValue.id;
                $select.val(initialValue);
            }

            const hiddenFieldName = `${$select.attr('name')}${this.config.hiddenFieldSuffix}`;
            const $hiddenField = $field.find(`input[name="${hiddenFieldName}"]`);
            const displayText = $select.data('selected-text');

            let cachedData = null;
            if ($hiddenField.length) {
                try {
                    cachedData = JSON.parse($hiddenField.val());
                } catch (error) {
                    cachedData = null;
                }
            }

            let optionData;
            if (cachedData && cachedData.id === initialValue) {
                optionData = this.getOptionData(cachedData);
            } else {
                optionData = this.getOptionData({
                    id: initialValue,
                    text: displayText && displayText !== initialValue ? displayText : initialValue,
                    plan: cachedData && cachedData.plan ? cachedData.plan : '',
                    status: cachedData && cachedData.status ? cachedData.status : '',
                    customer_id: cachedData && cachedData.customer_id ? cachedData.customer_id : '',
                    customer_name: cachedData && cachedData.customer_name ? cachedData.customer_name : '',
                    customer_email: cachedData && cachedData.customer_email ? cachedData.customer_email : ''
                });
            }

            const $option = this.ensureOption($select, optionData);
            $option.data(this.config.optionDataKey, optionData);
            this.handleSelectionChange($field, $select);
        }

        ensureOption($select, data) {
            let $option = $select.find(`option[value="${data.id}"]`);
            if (!$option.length) {
                $option = $(`<option value="${data.id}" selected="selected"></option>`);
                $select.append($option);
            }

            $option.text(buildSubscriptionLabel(data));
            $option.data(this.config.optionDataKey, data);
            $select.val(data.id);
            return $option;
        }
    }

    new ACFStripeSubscriptionField();

    if (window.console && console.log) {
        console.log('ACF Stripe Subscription Field JavaScript loaded');
    }
})(jQuery);
