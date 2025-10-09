(function($){
    function formatResult(customer) {
        if (!customer || customer.loading) {
            return customer && customer.text ? customer.text : '';
        }

        var $container = $('<div class="acf-stripe-customer-option"></div>');

        if (customer.name) {
            $('<div class="customer-name"></div>').text(customer.name).appendTo($container);
        } else if (customer.text) {
            $('<div class="customer-name"></div>').text(customer.text).appendTo($container);
        }

        if (customer.email) {
            $('<div class="customer-email"></div>').text(customer.email).appendTo($container);
        }

        if (customer.id) {
            $('<div class="customer-id"></div>').text(customer.id).appendTo($container);
        }

        return $container;
    }

    function formatSelection(customer) {
        if (!customer) {
            return '';
        }

        if (customer.text) {
            return customer.text;
        }

        if (customer.name && customer.email) {
            return customer.name + ' (' + customer.email + ')';
        }

        return customer.name || customer.email || customer.id || '';
    }

    function initField(field) {
        console.log('initField called with:', field);
        
        // Handle different ways the field might be passed
        var $field, $select;
        
        if (!field) {
            console.error('ACF Stripe Customer Field: field parameter is undefined');
            return;
        }
        
        // Check if field has $el property (ACF 6.x format)
        if (field.$el && field.$el.length) {
            $field = field.$el;
        }
        // Check if field is already a jQuery object
        else if (field.jquery) {
            $field = field;
        }
        // Check if field has a field wrapper
        else if (field.field && field.field.jquery) {
            $field = field.field;
        }
        // Fallback: try to find field by type
        else {
            console.warn('ACF Stripe Customer Field: Unexpected field format, trying fallback');
            $field = $('.acf-field[data-type="stripe_customer"]');
        }
        
        if (!$field || !$field.length) {
            console.warn('ACF Stripe Customer Field: Could not find field element');
            return;
        }
        
        $select = $field.find('select.acf-stripe-customer-select');
        if (!$select.length) {
            console.warn('ACF Stripe Customer Field: Could not find select element');
            return;
        }
        
        // Check if already initialized to prevent multiple initializations
        if ($select.data('stripe-initialized')) {
            console.log('ACF Stripe Customer Field: Already initialized, skipping');
            return;
        }

        // Mark as initialized
        $select.data('stripe-initialized', true);

        var $clearButton = $field.find('.acf-stripe-select-clear');

        function updateClearButtonState() {
            if (!$clearButton.length) {
                return;
            }

            var hasValue = !!$select.val();
            var disabled = !hasValue || $select.is(':disabled');
            $clearButton.prop('disabled', disabled).attr('aria-disabled', disabled ? 'true' : 'false');
            if (!hasValue) {
                $clearButton.removeClass('is-active');
            } else {
                $clearButton.addClass('is-active');
            }
        }

        // Check if Stripe is connected
        if (typeof acfStripeCustomerField === 'undefined' || !acfStripeCustomerField.isConnected) {
            $select.prop('disabled', true);
            $field.find('.acf-stripe-customer-notice').show();
            updateClearButtonState();
            return;
        }

        $select.prop('disabled', false);
        $field.find('.acf-stripe-customer-notice').hide();

        // Destroy existing Select2 if it exists
        if ($select.hasClass('select2-hidden-accessible')) {
            $select.select2('destroy');
        }

        var allowClear = $select.data('allow-clear') === 1 || $select.data('allow-clear') === '1';
        var placeholder = $select.data('placeholder') || (acfStripeCustomerField.strings ? acfStripeCustomerField.strings.placeholder : 'Select a Stripe customer');

        // Endpoint test passed, proceeding with Select2 initialization
        
        // Use simple Select2 without AJAX, load customers on focus
        console.log('Initializing Select2 with manual customer loading');
        
        var select2Config = {
            width: '100%',
            allowClear: allowClear,
            placeholder: placeholder,
            templateResult: formatResult,
            templateSelection: formatSelection,
            escapeMarkup: function(markup) {
                return markup;
            }
        };

        // Initialize Select2
        $select.select2(select2Config);

        // Load customers when the dropdown opens
        $select.on('select2:open', function() {
            var $dropdown = $select.data('select2').$dropdown;
            
            if ($select.data('customers-loaded')) {
                return; // Already loaded
            }
            
            console.log('Loading customers...');
            $dropdown.find('.select2-results__options').html('<li class="select2-results__option">Loading customers...</li>');
            
            $.ajax({
                url: acfStripeCustomerField.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'acf_stripe_search_customers',
                    nonce: acfStripeCustomerField.nonce,
                    search: '',
                    page: 1
                },
                success: function(response) {
                    console.log('Customers loaded successfully:', response);
                    
                    if (response.success && response.data && response.data.items) {
                        // Clear existing options except selected one
                        var selectedValue = $select.val();
                        $select.empty();
                        
                        if (allowClear) {
                            $select.append('<option value=""></option>');
                        }
                        
                        // Add customer options
                        response.data.items.forEach(function(customer) {
                            var selected = customer.id === selectedValue ? ' selected' : '';
                            var $option = $('<option value="' + customer.id + '"' + selected + '>' + customer.text + '</option>');
                            
                            // Store customer data in the option for later use
                            $option.data('customer-data', {
                                id: customer.id,
                                name: customer.name || '',
                                email: customer.email || ''
                            });
                            
                            $select.append($option);
                        });
                        
                        $select.data('customers-loaded', true);
                        $select.trigger('change.select2');
                    } else {
                        console.error('Invalid response:', response);
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    console.error('Failed to load customers:', textStatus, errorThrown);
                    console.error('Response:', jqXHR.responseText);
                    $dropdown.find('.select2-results__options').html('<li class="select2-results__option">Failed to load customers</li>');
                }
            });
        });

        // Update hidden data field when selection changes
        $select.on('change', function() {
            var selectedValue = $(this).val();
            var hiddenDataField = $field.find('input[name="' + $(this).attr('name') + '_data"]');
            
            if (selectedValue && selectedValue !== '') {
                var selectedOption = $(this).find('option:selected');
                var customerData = selectedOption.data('customer-data');
                
                if (customerData) {
                    // Create or update hidden field with customer data
                    if (hiddenDataField.length === 0) {
                        hiddenDataField = $('<input type="hidden" name="' + $(this).attr('name') + '_data" />');
                        $field.append(hiddenDataField);
                    }
                    
                    hiddenDataField.val(JSON.stringify(customerData));
                    console.log('Updated hidden field with customer data:', customerData);
                } else {
                    console.warn('No customer data available for selected option');
                }
            } else {
                // Clear hidden field if no selection
                if (hiddenDataField.length > 0) {
                    hiddenDataField.remove();
                }
            }
        });

        // Ensure clearing through Select2's UI removes hidden data and stored value.
        $select.on('select2:clear', function() {
            $select.val('').trigger('change');
        });

        if ($clearButton.length) {
            $clearButton.on('click', function(event) {
                event.preventDefault();

                if ($select.is(':disabled') || $clearButton.is(':disabled')) {
                    return;
                }

                if ($select.data('select2')) {
                    $select.val(null).trigger('change');
                    $select.trigger('change.select2');
                } else {
                    $select.val('').trigger('change');
                }
            });
        }

        // Handle initial value if already selected
        var initialValue = $select.val();
        
        // Handle case where initialValue might be JSON object instead of customer ID
        if (initialValue && typeof initialValue === 'object') {
            console.warn('Initial value is an object, extracting customer ID:', initialValue);
            if (initialValue.id) {
                initialValue = initialValue.id;
                $select.val(initialValue); // Update the select with just the ID
            }
        }
        
        if (initialValue && initialValue !== '') {
            var displayText = $select.data('selected-text');
            console.log('Field has initial value:', initialValue, 'with text:', displayText);
            
            // Check if we have cached customer data in a hidden field
            var hiddenDataField = $field.find('input[name="' + $select.attr('name') + '_data"]');
            var cachedData = null;
            
            if (hiddenDataField.length) {
                try {
                    cachedData = JSON.parse(hiddenDataField.val());
                    console.log('Found cached customer data:', cachedData);
                } catch (e) {
                    console.warn('Could not parse cached customer data');
                }
            }
            
            // If we have cached data, use it
            if (cachedData && cachedData.id === initialValue) {
                var cachedDisplayText = '';
                if (cachedData.name && cachedData.email) {
                    cachedDisplayText = cachedData.name + ' (' + cachedData.email + ')';
                } else if (cachedData.name) {
                    cachedDisplayText = cachedData.name;
                } else if (cachedData.email) {
                    cachedDisplayText = cachedData.email;
                } else {
                    cachedDisplayText = cachedData.id;
                }
                
                // Update the option with cached data
                if (!$select.find('option[value="' + initialValue + '"]').length) {
                    $select.append('<option value="' + initialValue + '" selected="selected">' + cachedDisplayText + '</option>');
                } else {
                    $select.find('option[value="' + initialValue + '"]').text(cachedDisplayText).attr('selected', 'selected');
                }
                
                console.log('Used cached data for initial value, no API call needed');
            }
            // If we have display text from server, use it
            else if (displayText && displayText !== initialValue) {
                if (!$select.find('option[value="' + initialValue + '"]').length) {
                    $select.append('<option value="' + initialValue + '" selected="selected">' + displayText + '</option>');
                } else {
                    $select.find('option[value="' + initialValue + '"]').text(displayText).attr('selected', 'selected');
                }
                
                console.log('Used server-provided display text, no API call needed');
            }
            // Last resort: we have a value but no display text - this should rarely happen now
            else if (initialValue !== '') {
                console.warn('No cached data or display text available, using customer ID as fallback');
                $select.find('option[value="' + initialValue + '"]').text(initialValue);
            }
        }

        updateClearButtonState();

        $select.on('change', updateClearButtonState);
    }

    // Initialize field on ready
    if (typeof acf !== 'undefined') {
        // Modern ACF Pro 6.x
        if (acf.addAction) {
            acf.addAction('ready_field/type=stripe_customer', initField);
            acf.addAction('append_field/type=stripe_customer', initField);
        }
        
        // Fallback for older ACF versions
        if (acf.add_action) {
            acf.add_action('ready_field/type=stripe_customer', initField);
            acf.add_action('append_field/type=stripe_customer', initField);
        }
        
        // Additional fallback - initialize on document ready
        $(document).ready(function() {
            $('.acf-field[data-type="stripe_customer"]').each(function() {
                initField({ $el: $(this) });
            });
        });
        
        // Monitor for dynamically added fields
        $(document).on('acf/setup_fields', function(e, postbox) {
            $(postbox).find('.acf-field[data-type="stripe_customer"]').each(function() {
                initField({ $el: $(this) });
            });
        });
    } else {
        console.warn('ACF not found, trying manual initialization');
        $(document).ready(function() {
            $('.acf-field[data-type="stripe_customer"]').each(function() {
                initField({ $el: $(this) });
            });
        });
    }

    // Debug logging
    console.log('ACF Stripe Customer Field JavaScript loaded');
    console.log('acfStripeCustomerField config:', typeof acfStripeCustomerField !== 'undefined' ? acfStripeCustomerField : 'undefined');

})(jQuery);
