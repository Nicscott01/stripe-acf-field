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

        // Check if Stripe is connected
        if (typeof acfStripeCustomerField === 'undefined' || !acfStripeCustomerField.isConnected) {
            $select.prop('disabled', true);
            $field.find('.acf-stripe-customer-notice').show();
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
                            $select.append('<option value="' + customer.id + '"' + selected + '>' + customer.text + '</option>');
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

        // Handle initial value if already selected
        var initialValue = $select.val();
        if (initialValue && initialValue !== '') {
            var displayText = $select.data('selected-text');
            console.log('Field has initial value:', initialValue, 'with text:', displayText);
            
            // If we have display text, create the option
            if (displayText) {
                // Make sure the option exists with the correct text
                if (!$select.find('option[value="' + initialValue + '"]').length) {
                    $select.append('<option value="' + initialValue + '" selected="selected">' + displayText + '</option>');
                } else {
                    // Update existing option text
                    $select.find('option[value="' + initialValue + '"]').text(displayText).attr('selected', 'selected');
                }
            } else if (initialValue !== '') {
                // We have a value but no display text - fetch customer details
                console.log('Fetching customer details for initial value:', initialValue);
                
                $.ajax({
                    url: acfStripeCustomerField.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'acf_stripe_search_customers',
                        nonce: acfStripeCustomerField.nonce,
                        search: initialValue, // Search for the specific customer ID
                        page: 1
                    },
                    success: function(response) {
                        if (response.success && response.data && response.data.items) {
                            var customer = response.data.items.find(function(c) { return c.id === initialValue; });
                            if (customer) {
                                console.log('Found customer for initial value:', customer);
                                $select.find('option[value="' + initialValue + '"]').text(customer.text);
                                $select.trigger('change.select2');
                            } else {
                                console.warn('Customer not found in search results');
                                // Keep the customer ID as display text for now
                                $select.find('option[value="' + initialValue + '"]').text(initialValue);
                            }
                        }
                    },
                    error: function() {
                        console.warn('Could not fetch customer details for initial value');
                        // Keep the customer ID as display text
                        $select.find('option[value="' + initialValue + '"]').text(initialValue);
                    }
                });
            }
        }
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