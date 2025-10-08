/**
 * Base Stripe ACF Field JavaScript
 * 
 * This provides common functionality for all Stripe field types.
 * Each field type extends this base class with object-specific configurations.
 */

(function($) {
    'use strict';

    /**
     * Base Stripe Field Class
     * 
     * @param {string} objectType - The Stripe object type (customer, subscription, etc.)
     * @param {Object} config - Configuration object for this field type
     */
    class ACFStripeFieldBase {
        constructor(objectType, config = {}) {
            this.objectType = objectType;
            this.config = $.extend({
                ajaxAction: `acf_stripe_search_${objectType}s`,
                fieldSelector: `.acf-stripe-${objectType.replace('_', '-')}-select`,
                fieldTypeSelector: `[data-type="stripe_${objectType}"]`,
                loadingText: 'Loading...',
                errorText: 'Failed to load items',
                noResultsText: 'No items found'
            }, config);
            
            console.log(`Creating ACF Stripe ${objectType} field handler`);
            console.log(`Field selector: ${this.config.fieldSelector}`);
            console.log(`AJAX action: ${this.config.ajaxAction}`);
            
            this.init();
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
                $field.find('.acf-stripe-notice').show();
                return;
            }

            $select.prop('disabled', false);
            $field.find('.acf-stripe-notice').hide();

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
            const placeholder = $select.data('placeholder') || this.getDefaultPlaceholder();

            console.log(`Initializing Select2 for ${this.objectType} with manual loading`);
            
            const select2Config = {
                width: '100%',
                allowClear: allowClear,
                placeholder: placeholder,
                templateResult: (item) => this.formatResult(item),
                templateSelection: (item) => this.formatSelection(item),
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

            // Handle initial value if present
            this.handleInitialValue($select);
        }

        /**
         * Load items when dropdown opens
         * 
         * @param {jQuery} $select - Select element
         */
        loadItemsOnOpen($select) {
            const dataKey = `${this.objectType}s-loaded`;
            
            if ($select.data(dataKey)) {
                return; // Already loaded
            }
            
            const $dropdown = $select.data('select2').$dropdown;
            console.log(`Loading ${this.objectType}s...`);
            $dropdown.find('.select2-results__options').html(`<li class="select2-results__option">${this.config.loadingText}</li>`);
            
            $.ajax({
                url: this.getAjaxUrl(),
                type: 'POST',
                data: {
                    action: this.config.ajaxAction,
                    nonce: this.getNonce(),
                    search: '',
                    page: 1
                },
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
            const items = this.extractItemsFromResponse(response);
            
            // Add options
            items.forEach((item) => {
                const selected = item.id === selectedValue ? ' selected' : '';
                const $option = $(`<option value="${item.id}"${selected}>${item.text}</option>`);
                
                // Store item data in the option for later use
                $option.data(`${this.objectType}-data`, this.extractItemData(item));
                
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
            const selectedValue = $select.val();
            const hiddenDataField = $field.find(`input[name="${$select.attr('name')}_data"]`);
            
            if (selectedValue && selectedValue !== '') {
                const selectedOption = $select.find('option:selected');
                const itemData = selectedOption.data(`${this.objectType}-data`);
                
                if (itemData) {
                    // Create or update hidden field with item data
                    if (hiddenDataField.length === 0) {
                        const $hiddenField = $(`<input type="hidden" name="${$select.attr('name')}_data" />`);
                        $field.append($hiddenField);
                        hiddenDataField = $hiddenField;
                    }
                    
                    hiddenDataField.val(JSON.stringify(itemData));
                    console.log(`Updated hidden field with ${this.objectType} data:`, itemData);
                } else {
                    console.warn(`No ${this.objectType} data available for selected option`);
                }
            } else {
                // Clear hidden field if no selection
                if (hiddenDataField.length > 0) {
                    hiddenDataField.remove();
                }
            }
        }

        /**
         * Handle initial value display
         * 
         * @param {jQuery} $select - Select element
         */
        handleInitialValue($select) {
            const initialValue = $select.val();
            
            if (!initialValue || initialValue === '') {
                return;
            }

            // Handle case where initialValue might be JSON object instead of ID
            let actualValue = initialValue;
            if (typeof initialValue === 'object') {
                console.warn(`Initial value is an object, extracting ${this.objectType} ID:`, initialValue);
                if (initialValue.id) {
                    actualValue = initialValue.id;
                    $select.val(actualValue);
                }
            }
            
            const displayText = $select.data('selected-text');
            console.log(`Field has initial value:`, actualValue, 'with text:', displayText);
            
            if (displayText && displayText !== actualValue) {
                // Update option with display text
                const $option = $select.find(`option[value="${actualValue}"]`);
                if ($option.length) {
                    $option.text(displayText);
                } else {
                    $select.append(`<option value="${actualValue}" selected="selected">${displayText}</option>`);
                }
                console.log('Used server-provided display text, no API call needed');
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
        extractItemsFromResponse(response) {
            // Default expects response.data.items (customer format)
            // Override in subclasses if different format is used
            if (response.success && response.data) {
                return response.data.items || response.data || [];
            }
            return [];
        }

        /**
         * Extract item data for storage in option
         * 
         * @param {Object} item - Item from response
         * @return {Object} Data to store
         */
        extractItemData(item) {
            // Default extracts id, name, email - override in subclasses
            return {
                id: item.id,
                name: item.name || '',
                email: item.email || ''
            };
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
            return window.acfStripeField && window.acfStripeField.is_connected;
        }

        /**
         * Get AJAX URL
         * 
         * @return {string} AJAX URL
         */
        getAjaxUrl() {
            return (window.acfStripeField && window.acfStripeField.ajax_url) || window.ajaxurl;
        }

        /**
         * Get AJAX nonce
         * 
         * @return {string} Nonce
         */
        getNonce() {
            return (window.acfStripeField && window.acfStripeField.nonce) || '';
        }
    }

    // Export to global scope
    window.ACFStripeFieldBase = ACFStripeFieldBase;

})(jQuery);