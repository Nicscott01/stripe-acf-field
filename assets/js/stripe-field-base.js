/**
 * Base Stripe ACF Field JavaScript
 * 
 * This provides common functionality for all Stripe field types.
 * Each field type extends this base class with object-specific configurations.
 */

(function($) {
    'use strict';

    if (typeof acf !== 'undefined' && typeof acf.registerFieldType === 'function') {
        ['stripe_customer', 'stripe_subscription', 'stripe_product'].forEach(function(typeKey) {
            /*if (acf.getFieldType && acf.getFieldType(typeKey)) {
                return;
            }*/

            var SelectModel = (acf.models && acf.models.Select) ? acf.models.Select : acf.Field;

            var BasicStripeField = SelectModel.extend({
                type: typeKey,
                /*supports: $.extend({}, (SelectModel.prototype && SelectModel.prototype.supports) || {}, {
                    conditionalLogic: true
                }),*/
                supports: {
                    conditionalLogic: true
                },
                $input: function() {
                    return this.$el.find('select');
                },
                getValue: function() {
                    var $input = this.$input();
                    return $input.length ? $input.val() : null;
                },
                setValue: function(val) {
                    var $input = this.$input();
                    if (!$input.length) {
                        return;
                    }
                    if ($input.val() !== val) {
                        $input.val(val).trigger('change');
                    }
                },
                initialize: function() {
                // Fire change to initialize conditional logic
                this.$input().trigger('change');
            }
            });



            acf.registerFieldType(BasicStripeField);
        });
    }

    /**
     * Base Stripe Field Class
     * 
     * @param {string} objectType - The Stripe object type (customer, subscription, etc.)
     * @param {Object} config - Configuration object for this field type
     */
    const registeredFieldTypes = {};

    class ACFStripeFieldBase {
        constructor(objectType, config = {}) {
            this.objectType = objectType;
            this.config = $.extend({
                ajaxAction: `acf_stripe_search_${objectType}s`,
                fieldSelector: `.acf-stripe-${objectType.replace('_', '-')}-select`,
                fieldTypeSelector: `[data-type="stripe_${objectType}"]`,
                noticeSelector: '.acf-stripe-notice',
                loadingText: 'Loading…',
                errorText: 'Failed to load items',
                noResultsText: 'No items found',
                optionDataKey: `${objectType}-data`,
                loadedDataFlag: `${objectType}s-loaded`,
                hiddenFieldSuffix: '_data',
                globalConfigGetter: () => ({}),
                ajaxDataBuilder: (search, instance) => ({
                    action: instance.config.ajaxAction,
                    nonce: instance.getNonce(),
                    search: search || '',
                    page: 1
                }),
                extractItemsFromResponse: (response) => {
                    if (response && response.success && response.data) {
                        if (Array.isArray(response.data.items)) {
                            return response.data.items;
                        }
                        if (Array.isArray(response.data.data)) {
                            return response.data.data;
                        }
                        if (Array.isArray(response.data)) {
                            return response.data;
                        }
                    }
                    return [];
                },
                extractItemData: (item) => ({ id: item.id || '', label: item.text || item.id || '' }),
                formatResult: null,
                formatSelection: null,
                handleInitialValue: null,
                handleSelectionChange: null
            }, config);

            this.init();
            this.registerFieldType();
        }

        /**
         * Initialize the field handlers
         */
        init() {
            if (typeof acf === 'undefined') {
                console.error('ACF is not available');
                return;
            }

            const fieldTypeName = `stripe_${this.objectType}`;
            console.log(`Setting up ACF actions for ${fieldTypeName}`);

            // Initialize fields when ACF loads them
            if (acf.addAction) {
                // Modern ACF Pro 6.x
                acf.addAction(`ready_field/type=${fieldTypeName}`, (field) => {
                    this.initField(field);
                });
                acf.addAction(`append_field/type=${fieldTypeName}`, (field) => {
                    this.initField(field);
                });
            }
            
            // Fallback for older ACF versions
            if (acf.add_action) {
                acf.add_action(`ready_field/type=${fieldTypeName}`, (field) => {
                    this.initField(field);
                });
                acf.add_action(`append_field/type=${fieldTypeName}`, (field) => {
                    this.initField(field);
                });
            }

            // Initialize any existing fields on document ready
            $(document).ready(() => {
                $(this.config.fieldTypeSelector).each((index, element) => {
                    this.initField({ $el: $(element) });
                });
            });

            // Monitor for dynamically added fields
            $(document).on('acf/setup_fields', (e, postbox) => {
                $(postbox).find(this.config.fieldTypeSelector).each((index, element) => {
                    this.initField({ $el: $(element) });
                });
            });
        }

        /**
         * Register the field type with ACF so conditional logic and other features work.
         */
        registerFieldType() {
            if (typeof acf === 'undefined') {
                return;
            }

            const typeKey = `stripe_${this.objectType}`;

            if (registeredFieldTypes[typeKey]) {
                return;
            }

            const selector = this.config.fieldSelector;
            const SelectModel = (acf.models && acf.models.Select) ? acf.models.Select : acf.Field;

            const FieldModel = SelectModel.extend({
                type: typeKey,
                supports: $.extend({}, SelectModel.prototype.supports || {}, {
                    conditionalLogic: true
                }),
                events: $.extend({}, SelectModel.prototype.events || {}, {
                    'change select': 'onChange'
                }),
                $input: function() {
                    return this.$el.find(selector);
                },
                getValue: function() {
                    const $input = this.$input();
                    if (!$input.length) {
                        return null;
                    }
                    return $input.val();
                },
                setValue: function(val) {
                    const $input = this.$input();
                    if (!$input.length) {
                        return;
                    }

                    if ($input.val() === val) {
                        return;
                    }

                    $input.val(val).trigger('change');
                },
                onChange: function() {
                    if (typeof SelectModel.prototype.onChange === 'function') {
                        SelectModel.prototype.onChange.apply(this, arguments);
                    }
                    this.trigger('change');
                }
            });

            if (typeof acf.registerFieldType === 'function') {
                acf.registerFieldType(FieldModel);
            } else if (acf.fields) {
                acf.fields[typeKey] = FieldModel;
            }

            registeredFieldTypes[typeKey] = {
                field: FieldModel
            };
        }

        getObjectDisplayName() {
            if (this.config.objectDisplayName) {
                return this.config.objectDisplayName;
            }

            const type = this.objectType.replace('_', ' ');
            return type.charAt(0).toUpperCase() + type.slice(1);
        }

        /**
         * Initialize a single field instance
         * 
         * @param {Object} field - ACF field object or jQuery element
         */
        initField(field) {
            console.log(`initField called for ${this.objectType} with:`, field);
            
            // Handle different ways the field might be passed
            let $field, $select;
            
            if (!field) {
                console.error(`ACF Stripe ${this.objectType} Field: field parameter is undefined`);
                return;
            }
            
            // Determine field element
            if (field.$el && field.$el.length) {
                $field = field.$el;
            } else if (field.jquery) {
                $field = field;
            } else if (field.field && field.field.jquery) {
                $field = field.field;
            } else {
                console.warn(`ACF Stripe ${this.objectType} Field: Unexpected field format, trying fallback`);
                $field = $(this.config.fieldTypeSelector);
            }
            
            if (!$field || !$field.length) {
                console.warn(`ACF Stripe ${this.objectType} Field: Could not find field element`);
                return;
            }
            
            $select = $field.find(this.config.fieldSelector);
            console.log( "$select", $select)
            if (!$select.length) {
                console.warn(`ACF Stripe ${this.objectType} Field: Could not find select element`);
                return;
            }
            
            // Check if already initialized to prevent multiple initializations
            if ($select.data(`stripe-${this.objectType}-initialized`)) {
                console.log(`ACF Stripe ${this.objectType} Field: Already initialized, skipping`);
                return;
            }
            
            // Mark as initialized
            $select.data(`stripe-${this.objectType}-initialized`, true);

            // Check if Stripe is connected
            if (!this.isConnected()) {
                $select.prop('disabled', true);
                $field.find(this.config.noticeSelector).show();
                return;
            }

            $select.prop('disabled', false);
            $field.find(this.config.noticeSelector).hide();

            // Initialize Select2
            this.initializeSelect2($field, $select);
        }

        /**
         * Initialize Select2 for the field
         * 
         * @param {jQuery} $field - Field container
         * @param {jQuery} $select - Select element
         */
        initializeSelect2($field, $select) {
            // Destroy existing Select2 if it exists
            if ($select.hasClass('select2-hidden-accessible')) {
                $select.select2('destroy');
            }

            const allowClear = $select.data('allow-clear') === 1 || $select.data('allow-clear') === '1';
            const placeholder = $select.data('placeholder') || this.getPlaceholder();

            console.log(`Initializing Select2 for ${this.objectType} with manual loading`);
            
            const select2Config = {
                width: '100%',
                allowClear: allowClear,
                placeholder: placeholder,
                templateResult: (item) => this.getFormatResult()(item),
                templateSelection: (item) => this.getFormatSelection()(item),
                escapeMarkup: function(markup) {
                    return markup;
                }
            };

            // Initialize Select2
            $select.select2(select2Config);

            // Load items when the dropdown opens
            $select.on('select2:open', () => {
                this.loadItemsOnOpen($select);
            });

            // Handle selection changes
            $select.on('change', () => {
                this.handleSelectionChange($field, $select);
            });

            // Ensure the clear control resets the field state.
            $select.on('select2:clear', () => {
                $select.val('').trigger('change');
            });

            // Handle initial value if present
            this.handleInitialValue($select);
        }

        /**
         * Load items when dropdown opens
         * 
         * @param {jQuery} $select - Select element
         */
        loadItemsOnOpen($select) {
            const dataKey = this.config.loadedDataFlag;
            
            if ($select.data(dataKey)) {
                return; // Already loaded
            }
            
            const $dropdown = $select.data('select2').$dropdown;
            console.log(`Loading ${this.objectType}s...`);
            $dropdown.find('.select2-results__options').html(`<li class="select2-results__option">${this.config.loadingText}</li>`);
            
            $.ajax({
                url: this.getAjaxUrl(),
                type: 'POST',
                data: this.buildAjaxData('', $select),
                success: (response) => {
                    console.log(`${this.objectType}s loaded successfully:`, response);
                    
                    if (this.isValidResponse(response)) {
                        this.populateOptions($select, response);
                        $select.data(dataKey, true);
                    } else {
                        console.error('Invalid response:', response);
                        this.showError($dropdown);
                    }
                },
                error: (jqXHR, textStatus, errorThrown) => {
                    console.error(`Failed to load ${this.objectType}s:`, textStatus, errorThrown);
                    this.showError($dropdown);
                }
            });
        }

        /**
         * Populate select options from AJAX response
         * 
         * @param {jQuery} $select - Select element
         * @param {Object} response - AJAX response
         */
        populateOptions($select, response) {
            const selectedValue = $select.val();
            const allowClear = $select.data('allow-clear') === 1 || $select.data('allow-clear') === '1';
            
            // Clear existing options except selected one
            $select.empty();
            
            if (allowClear) {
                $select.append('<option value=""></option>');
            }
            
            // Get items from response
            const items = this.getItemsFromResponse(response);
            
            // Add options
            items.forEach((item) => {
                const data = this.getOptionData(item);
                if (!data || !data.id) {
                    return;
                }
                const selected = data.id === selectedValue ? ' selected' : '';
                const label = data.label || data.text || data.id;
                const $option = $(`<option value="${data.id}"${selected}>${label}</option>`);
                
                // Store item data in the option for later use
                $option.data(this.config.optionDataKey, data);
                
                $select.append($option);
            });
            
            $select.trigger('change.select2');
        }

        /**
         * Handle selection changes
         * 
         * @param {jQuery} $field - Field container
         * @param {jQuery} $select - Select element
         */
        handleSelectionChange($field, $select) {
            if (typeof this.config.handleSelectionChange === 'function') {
                this.config.handleSelectionChange($field, $select, this);
                return;
            }

            const selectedValue = $select.val();
            let hiddenDataField = $field.find(`input[name="${$select.attr('name')}${this.config.hiddenFieldSuffix}"]`);
            
            if (selectedValue && selectedValue !== '') {
                const selectedOption = $select.find('option:selected');
                const itemData = selectedOption.data(this.config.optionDataKey);
                
                if (itemData) {
                    // Create or update hidden field with item data
                    if (hiddenDataField.length === 0) {
                        hiddenDataField = $(`<input type="hidden" name="${$select.attr('name')}${this.config.hiddenFieldSuffix}" />`);
                        $field.append(hiddenDataField);
                    }
                    
                    hiddenDataField.val(JSON.stringify(itemData));
                } else {
                    if (hiddenDataField.length > 0) {
                        hiddenDataField.remove();
                    }
                }
            } else if (hiddenDataField.length > 0) {
                hiddenDataField.remove();
            }
        }

        /**
         * Handle initial value display
         * 
         * @param {jQuery} $select - Select element
         */
        handleInitialValue($select) {
            if (typeof this.config.handleInitialValue === 'function') {
                this.config.handleInitialValue($select, this);
                return;
            }

            const initialValue = $select.val();
            if (!initialValue || initialValue === '') {
                return;
            }

            const displayText = $select.data('selected-text');
            if (displayText && displayText !== initialValue) {
                const $option = $select.find(`option[value="${initialValue}"]`);
                if ($option.length) {
                    $option.text(displayText);
                } else {
                    $select.append(`<option value="${initialValue}" selected="selected">${displayText}</option>`);
                }
            }
        }

        // Abstract methods that should be overridden by subclasses

        /**
         * Format result for dropdown display
         * 
         * @param {Object} item - Item data
         * @return {jQuery|string} Formatted result
         */
        formatResult(item) {
            if (!item || item.loading) {
                return item && item.text ? item.text : '';
            }
            
            // Default simple text display - override in subclasses for custom formatting
            return $('<span>').text(item.text || item.id);
        }

        /**
         * Format selection for display in field
         * 
         * @param {Object} item - Selected item data
         * @return {string} Formatted selection
         */
        formatSelection(item) {
            if (!item) {
                return '';
            }
            return item.text || item.id || '';
        }

        /**
         * Extract items array from AJAX response
         * 
         * @param {Object} response - AJAX response
         * @return {Array} Items array
         */
        getItemsFromResponse(response) {
            return this.config.extractItemsFromResponse(response, this);
        }

        getOptionData(item) {
            return this.config.extractItemData(item, this);
        }

        /**
         * Check if AJAX response is valid
         * 
         * @param {Object} response - AJAX response
         * @return {boolean} True if valid
         */
        isValidResponse(response) {
            return response && response.success;
        }

        /**
         * Show error in dropdown
         * 
         * @param {jQuery} $dropdown - Dropdown element
         */
        showError($dropdown) {
            $dropdown.find('.select2-results__options').html(`<li class="select2-results__option">${this.config.errorText}</li>`);
        }

        // Utility methods

        /**
         * Get default placeholder text
         * 
         * @return {string} Placeholder text
         */
        getDefaultPlaceholder() {
            return `Select a Stripe ${this.objectType}`;
        }

        /**
         * Check if Stripe is connected
         * 
         * @return {boolean} True if connected
         */
        isConnected() {
            const cfg = this.getGlobalConfig();
            if (!cfg) {
                return false;
            }
            if (typeof cfg.isConnected !== 'undefined') {
                return !!cfg.isConnected;
            }
            if (typeof cfg.is_connected !== 'undefined') {
                return !!cfg.is_connected;
            }
            return false;
        }

        /**
         * Get AJAX URL
         * 
         * @return {string} AJAX URL
         */
        getAjaxUrl() {
            const cfg = this.getGlobalConfig();
            if (cfg && cfg.ajaxUrl) {
                return cfg.ajaxUrl;
            }
            if (cfg && cfg.ajax_url) {
                return cfg.ajax_url;
            }
            return window.ajaxurl;
        }

        /**
         * Get AJAX nonce
         * 
         * @return {string} Nonce
         */
        getNonce() {
            const cfg = this.getGlobalConfig();
            if (cfg && cfg.nonce) {
                return cfg.nonce;
            }
            return '';
        }

        getPlaceholder() {
            const strings = this.getStrings();
            if (strings && strings.placeholder) {
                return strings.placeholder;
            }
            return this.getDefaultPlaceholder();
        }

        getStrings() {
            const cfg = this.getGlobalConfig();
            return (cfg && cfg.strings) || {};
        }

        getFormatResult() {
            if (typeof this.config.formatResult === 'function') {
                return this.config.formatResult;
            }
            return (item) => this.formatResult(item);
        }

        getFormatSelection() {
            if (typeof this.config.formatSelection === 'function') {
                return this.config.formatSelection;
            }
            return (item) => this.formatSelection(item);
        }

        getGlobalConfig() {
            try {
                return this.config.globalConfigGetter(this) || {};
            } catch (e) {
                return {};
            }
        }

        buildAjaxData(search, $select) {
            return this.config.ajaxDataBuilder(search, this, $select) || {};
        }
    }

    // Export to global scope
    window.ACFStripeFieldBase = ACFStripeFieldBase;

})(jQuery);
