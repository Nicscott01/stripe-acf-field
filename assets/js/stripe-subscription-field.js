(function($) {
    'use strict';

    var config = typeof acfStripeSubscriptionField !== 'undefined' ? acfStripeSubscriptionField : null;

    function debugLog() {
        if (config && config.debug && window.console && console.log) {
            console.log.apply(console, arguments);
        }
    }

    function resolveField(field) {
        if (!field) {
            return null;
        }

        if (field.$el && field.$el.length) {
            return field.$el;
        }

        if (field.jquery) {
            return field;
        }

        if (field.field && field.field.jquery) {
            return field.field;
        }

        return $('.acf-field[data-type="stripe_subscription"]');
    }

    function findSelect($field) {
        if (!$field) {
            return null;
        }
        var $select = $field.find('select.acf-stripe-subscription-select');
        return $select.length ? $select : null;
    }

    function normalizeData(raw) {
        var data = raw || {};
        var normalized = {
            id: data.id || '',
            label: data.label || data.text || data.plan || data.name || data.email || '',
            plan: data.plan || '',
            status: data.status || '',
            customer_id: data.customer_id || data.customer || '',
            customer_name: data.customer_name || data.name || '',
            customer_email: data.customer_email || data.email || ''
        };

        normalized.name = normalized.customer_name;
        normalized.email = normalized.customer_email;

        if (!normalized.label && normalized.id) {
            normalized.label = normalized.id;
        }

        return normalized;
    }

    function ensureHiddenInput($field, $select, data) {
        if (!$field || !$select) {
            return;
        }

        var hiddenName = $select.attr('name') + '_data';
        var $hidden = $field.find('input[name="' + hiddenName + '"]');

        if (!data || !data.id) {
            if ($hidden.length) {
                $hidden.remove();
            }
            return;
        }

        if (!$hidden.length) {
            $hidden = $('<input type="hidden" />').attr('name', hiddenName).appendTo($field);
        }

        $hidden.val(JSON.stringify(data));
    }

    function formatResult(subscription) {
        if (!subscription || subscription.loading) {
            return subscription && subscription.text ? subscription.text : '';
        }

        var normalized = normalizeData(subscription);
        var labelText = normalized.label || subscription.text || subscription.id || '';
        var $container = $('<div class="acf-stripe-subscription-option"></div>');

        if (labelText) {
            $('<div class="subscription-label"></div>').text(labelText).appendTo($container);
        }

        if (normalized.plan && labelText.indexOf(normalized.plan) === -1) {
            $('<div class="subscription-plan"></div>').text(normalized.plan).appendTo($container);
        }

        if (normalized.status && labelText.indexOf(normalized.status) === -1) {
            $('<div class="subscription-status"></div>').text(normalized.status).appendTo($container);
        }

        if (normalized.customer_name || normalized.customer_email) {
            var customerLine = normalized.customer_name;
            if (normalized.customer_email) {
                customerLine = customerLine ? customerLine + ' (' + normalized.customer_email + ')' : normalized.customer_email;
            }
            $('<div class="subscription-customer"></div>').text(customerLine).appendTo($container);
        } else if (normalized.customer_id) {
            $('<div class="subscription-customer"></div>').text(normalized.customer_id).appendTo($container);
        }

        if (normalized.id && labelText.indexOf(normalized.id) === -1) {
            $('<div class="subscription-id"></div>').text(normalized.id).appendTo($container);
        }

        return $container;
    }

    function formatSelection(subscription) {
        if (!subscription) {
            return '';
        }

        var normalized = normalizeData(subscription);
        return normalized.label || normalized.id || '';
    }

    function hydrateInitialOption($field, $select) {
        var initialValue = $select.val();
        if (!initialValue) {
            ensureHiddenInput($field, $select, null);
            return;
        }

        var $option = $select.find('option[value="' + initialValue + '"]');
        var hiddenName = $select.attr('name') + '_data';
        var $hidden = $field.find('input[name="' + hiddenName + '"]');
        var cachedData = null;

        if ($hidden.length) {
            try {
                cachedData = JSON.parse($hidden.val());
            } catch (e) {
                cachedData = null;
            }
        }

        if (cachedData && cachedData.id === initialValue) {
            var normalized = normalizeData(cachedData);
            if (!$option.length) {
                $option = $('<option value="' + normalized.id + '" selected="selected">' + normalized.label + '</option>');
                $select.append($option);
            } else {
                $option.text(normalized.label).attr('selected', 'selected');
            }
            $option.data('subscription-data', normalized);
            ensureHiddenInput($field, $select, normalized);
            return;
        }

        var serverText = $select.data('selected-text');
        if (!$option.length && serverText && serverText !== initialValue) {
            $option = $('<option value="' + initialValue + '" selected="selected">' + serverText + '</option>');
            $select.append($option);
        } else if ($option.length && serverText) {
            $option.text(serverText).attr('selected', 'selected');
        }

        if ($option.length) {
            var fallbackData = normalizeData({
                id: initialValue,
                label: serverText || $option.text() || initialValue
            });
            $option.data('subscription-data', fallbackData);
            ensureHiddenInput($field, $select, fallbackData);
        } else {
            ensureHiddenInput($field, $select, null);
        }
    }

    function populateSelect($field, $select, items) {
        var allowClear = $select.data('allow-clear') === 1 || $select.data('allow-clear') === '1';
        var selectedValue = $select.val();
        var $existingSelected = $select.find('option:selected');
        var existingData = null;

        if ($existingSelected.length) {
            existingData = $existingSelected.data('subscription-data') || normalizeData({
                id: $existingSelected.val(),
                label: $existingSelected.text()
            });
        } else if (selectedValue) {
            existingData = normalizeData({ id: selectedValue, label: selectedValue });
        }

        $select.empty();

        if (allowClear) {
            $select.append('<option value=""></option>');
        }

        if (Array.isArray(items)) {
            items.forEach(function(item) {
                if (!item || !item.id) {
                    return;
                }
                var normalized = normalizeData(item);
                var isSelected = normalized.id === selectedValue;
                var $option = $('<option value="' + normalized.id + '"' + (isSelected ? ' selected="selected"' : '') + '>' + normalized.label + '</option>');
                $option.data('subscription-data', normalized);
                $select.append($option);
            });
        }

        if (selectedValue && !$select.find('option[value="' + selectedValue + '"]').length && existingData && existingData.id) {
            var $fallbackOption = $('<option value="' + existingData.id + '" selected="selected">' + existingData.label + '</option>');
            $fallbackOption.data('subscription-data', existingData);
            $select.append($fallbackOption);
        }

        if (selectedValue) {
            $select.val(selectedValue);
        }

        var $selectedOption = $select.find('option:selected');
        if ($selectedOption.length) {
            ensureHiddenInput($field, $select, $selectedOption.data('subscription-data'));
        }
    }

    function showDropdownMessage($dropdown, message) {
        if (!$dropdown) {
            return;
        }
        $dropdown.find('.select2-results__options').html('<li class="select2-results__option" role="option">' + message + '</li>');
    }

    function loadSubscriptions($field, $select) {
        if (!$select || !config || !config.ajaxUrl || $select.data('subscriptions-loading') || $select.data('subscriptions-loaded')) {
            return;
        }

        var select2Instance = $select.data('select2');
        var $dropdown = select2Instance ? select2Instance.$dropdown : null;
        var loadingMessage = config.strings ? config.strings.loading || 'Loading subscriptions...' : 'Loading subscriptions...';

        $select.data('subscriptions-loading', true);
        showDropdownMessage($dropdown, loadingMessage);

        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'acf_stripe_search_subscriptions',
                nonce: config.nonce,
                search: '',
                page: 1
            }
        }).done(function(response) {
            if (response && response.success && response.data && Array.isArray(response.data.items)) {
                populateSelect($field, $select, response.data.items);
                $select.data('subscriptions-loaded', true);
                debugLog('ACF Stripe Subscription: loaded', response.data.items);
            } else {
                var invalidMessage = config && config.strings ? config.strings.error : 'Unable to load subscriptions.';
                showDropdownMessage($dropdown, invalidMessage);
                debugLog('ACF Stripe Subscription: invalid response', response);
            }
        }).fail(function(jqXHR, textStatus, errorThrown) {
            var errorMessage = config && config.strings ? config.strings.error : 'Unable to load subscriptions.';
            showDropdownMessage($dropdown, errorMessage);
            debugLog('ACF Stripe Subscription: request failed', textStatus, errorThrown);
        }).always(function() {
            $select.removeData('subscriptions-loading');
        });
    }

    function initField(field) {
        var $field = resolveField(field);
        if (!$field || !$field.length) {
            return;
        }

        var $select = findSelect($field);
        if (!$select || !$select.length) {
            return;
        }

        if ($select.data('stripe-subscription-initialized')) {
            return;
        }

        $select.data('stripe-subscription-initialized', true);

        if (!config || !config.isConnected) {
            $select.prop('disabled', true);
            $field.find('.acf-stripe-subscription-notice').show();
            hydrateInitialOption($field, $select);
            return;
        }

        $select.prop('disabled', false);
        $field.find('.acf-stripe-subscription-notice').hide();

        if ($select.hasClass('select2-hidden-accessible')) {
            $select.select2('destroy');
        }

        hydrateInitialOption($field, $select);

        var allowClear = $select.data('allow-clear') === 1 || $select.data('allow-clear') === '1';
        var placeholder = $select.data('placeholder') || (config.strings ? config.strings.placeholder : 'Select a Stripe subscription');

        $select.select2({
            width: '100%',
            allowClear: allowClear,
            placeholder: placeholder,
            templateResult: formatResult,
            templateSelection: formatSelection,
            escapeMarkup: function(markup) {
                return markup;
            }
        });

        $select.on('select2:open', function() {
            loadSubscriptions($field, $select);
        });

        $select.on('change', function() {
            var $selectedOption = $select.find('option:selected');
            var data = $selectedOption.length ? $selectedOption.data('subscription-data') : null;
            ensureHiddenInput($field, $select, data ? normalizeData(data) : null);
        });

        $select.on('select2:clear', function() {
            $select.val('').trigger('change');
        });

        debugLog('ACF Stripe Subscription: initialized field', $field);
    }

    function registerHandlers() {
        if (typeof acf !== 'undefined') {
            if (acf.addAction) {
                acf.addAction('ready_field/type=stripe_subscription', initField);
                acf.addAction('append_field/type=stripe_subscription', initField);
            }

            if (acf.add_action) {
                acf.add_action('ready_field/type=stripe_subscription', initField);
                acf.add_action('append_field/type=stripe_subscription', initField);
            }

            $(document).on('acf/setup_fields', function(event, context) {
                $(context).find('.acf-field[data-type="stripe_subscription"]').each(function() {
                    initField({ $el: $(this) });
                });
            });
        }

        $(document).ready(function() {
            $('.acf-field[data-type="stripe_subscription"]').each(function() {
                initField({ $el: $(this) });
            });
        });
    }

    registerHandlers();

    debugLog('ACF Stripe Subscription Field JavaScript loaded', config);
})(jQuery);
