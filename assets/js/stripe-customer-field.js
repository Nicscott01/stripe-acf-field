(function($) {
    'use strict';

    if (typeof ACFStripeFieldBase === 'undefined') {
        console.error('ACFStripeFieldBase is required but not available.');
        return;
    }

    const OPTION_DATA_KEY = 'customer-data';

    function resolveCustomerData(item) {
        if (!item) {
            return {};
        }

        if (item[OPTION_DATA_KEY]) {
            return item[OPTION_DATA_KEY];
        }

        if (item.customerData) {
            return item.customerData;
        }

        if (item.element) {
            const data = $(item.element).data(OPTION_DATA_KEY);
            if (data) {
                return data;
            }
        }

        return {
            id: item.id || '',
            label: item.text || item.id || '',
            text: item.text || item.id || '',
            name: item.name || '',
            email: item.email || ''
        };
    }

    function buildCustomerLabel(data) {
        if (data.label) {
            return data.label;
        }

        if (data.name && data.email) {
            return `${data.name} (${data.email})`;
        }

        if (data.name) {
            return data.name;
        }

        if (data.email) {
            return data.email;
        }

        return data.id || '';
    }

    class ACFStripeCustomerField extends ACFStripeFieldBase {
        constructor() {
            super('customer', {
                noticeSelector: '.acf-stripe-customer-notice',
                optionDataKey: OPTION_DATA_KEY,
                loadedDataFlag: 'customers-loaded'
            });
        }

        getGlobalConfig() {
            return window.acfStripeCustomerField || {};
        }

        buildAjaxData(search) {
            const cfg = this.getGlobalConfig();
            return {
                action: 'acf_stripe_search_customers',
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
            const data = resolveCustomerData(item);
            return {
                id: data.id || '',
                label: buildCustomerLabel(data),
                text: data.text || buildCustomerLabel(data),
                name: data.name || '',
                email: data.email || ''
            };
        }

        formatResult(item) {
            if (!item || item.loading) {
                return item && item.text ? item.text : '';
            }

            const data = resolveCustomerData(item);
            const label = buildCustomerLabel(data);
            const $container = $('<div class="acf-stripe-customer-option"></div>');

            if (data.name || label) {
                $('<div class="customer-name"></div>').text(data.name || label).appendTo($container);
            }

            if (data.email) {
                $('<div class="customer-email"></div>').text(data.email).appendTo($container);
            }

            if (data.id) {
                $('<div class="customer-id"></div>').text(data.id).appendTo($container);
            }

            return $container;
        }

        formatSelection(item) {
            if (!item) {
                return '';
            }

            const data = resolveCustomerData(item);
            const label = buildCustomerLabel(data);

            if (data.name && data.email) {
                return `${data.name} (${data.email})`;
            }

            if (data.name) {
                return data.name;
            }

            if (data.email) {
                return data.email;
            }

            if (label) {
                return label;
            }

            return data.id || '';
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

            let optionData = null;
            if (cachedData && cachedData.id === initialValue) {
                optionData = this.getOptionData(cachedData);
            } else {
                optionData = {
                    id: initialValue,
                    label: displayText && displayText !== initialValue ? displayText : (cachedData && cachedData.label) || initialValue,
                    text: displayText && displayText !== initialValue ? displayText : (cachedData && cachedData.label) || initialValue,
                    name: cachedData && cachedData.name ? cachedData.name : '',
                    email: cachedData && cachedData.email ? cachedData.email : ''
                };
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

            $option.text(buildCustomerLabel(data));
            $option.data(this.config.optionDataKey, data);
            $select.val(data.id);
            return $option;
        }
    }

    new ACFStripeCustomerField();

    console.log('ACF Stripe Customer Field JavaScript loaded');
})(jQuery);
