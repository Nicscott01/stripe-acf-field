(function($) {
    'use strict';

    if (typeof ACFStripeFieldBase === 'undefined') {
        console.error('ACFStripeFieldBase is required but not available.');
        return;
    }

    const OPTION_DATA_KEY = 'product-data';
    const PRODUCT_CONFIG = typeof acfStripeProductField !== 'undefined' ? acfStripeProductField : {};

    function resolveProductData(item) {
        if (!item) {
            return {};
        }

        if (item[OPTION_DATA_KEY]) {
            return item[OPTION_DATA_KEY];
        }

        if (item.productData) {
            return item.productData;
        }

        if (item.element) {
            const data = $(item.element).data(OPTION_DATA_KEY);
            if (data) {
                return data;
            }
        }

        const result = {
            id: item.id || '',
            label: item.text || item.id || '',
            text: item.text || item.id || '',
            name: item.name || '',
            description: item.description || '',
            active: item.active !== undefined ? !!item.active : true,
            price_amount: item.price_amount || '',
            price_currency: item.price_currency || '',
            price_interval: item.price_interval || ''
        };

        return result;
    }

    function buildProductLabel(data) {
        if (data.label) {
            return data.label;
        }

        const parts = [];
        if (data.name) {
            parts.push(data.name);
        }

        if (data.price_currency && data.price_amount !== '') {
            const currency = String(data.price_currency).toUpperCase();
            const amount = Number(data.price_amount);
            const formattedAmount = !isNaN(amount) ? amount.toFixed(2) : data.price_amount;
            const interval = data.price_interval ? '/' + data.price_interval : '';
            parts.push(currency + formattedAmount + interval);
        }

        if (!parts.length && data.id) {
            parts.push(data.id);
        }

        let label = parts.join(' – ');

        if (data.active === false) {
            const inactiveText = PRODUCT_CONFIG.strings && PRODUCT_CONFIG.strings.inactive ? PRODUCT_CONFIG.strings.inactive : 'inactive';
            label += label ? ' ' : '';
            label += `(${inactiveText})`;
        }

        return label;
    }

    class ACFStripeProductField extends ACFStripeFieldBase {
        constructor() {
            super('product', {
                noticeSelector: '.acf-stripe-product-notice',
                optionDataKey: OPTION_DATA_KEY,
                loadedDataFlag: 'products-loaded'
            });
        }

        getGlobalConfig() {
            return PRODUCT_CONFIG || {};
        }

        buildAjaxData(search) {
            const cfg = this.getGlobalConfig();
            return {
                action: 'acf_stripe_search_products',
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
            const data = resolveProductData(item);
            return {
                id: data.id || '',
                label: buildProductLabel(data),
                text: data.text || buildProductLabel(data),
                name: data.name || '',
                description: data.description || '',
                active: data.active !== undefined ? !!data.active : true,
                price_amount: data.price_amount || '',
                price_currency: data.price_currency || '',
                price_interval: data.price_interval || ''
            };
        }

        formatResult(item) {
            if (!item || item.loading) {
                return item && item.text ? item.text : '';
            }

            const data = resolveProductData(item);
            const label = buildProductLabel(data);
            const $container = $('<div class="acf-stripe-product-option"></div>');

            if (label) {
                $('<div class="product-name"></div>').text(label).appendTo($container);
            }

            if (data.description) {
                $('<div class="product-description"></div>').text(data.description).appendTo($container);
            }

            if (data.id) {
                $('<div class="product-id"></div>').text(data.id).appendTo($container);
            }

            return $container;
        }

        formatSelection(item) {
            if (!item) {
                return '';
            }

            const data = resolveProductData(item);
            return buildProductLabel(data);
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
                    text: displayText && displayText !== initialValue ? displayText : initialValue
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

            $option.text(buildProductLabel(data));
            $option.data(this.config.optionDataKey, data);
            $select.val(data.id);
            return $option;
        }
    }

    new ACFStripeProductField();

    if (window.console && console.log) {
        console.log('ACF Stripe Product Field JavaScript loaded');
    }
})(jQuery);
