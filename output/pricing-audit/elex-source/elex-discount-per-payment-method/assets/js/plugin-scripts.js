jQuery(document).ready(function () {

    /*Scripts for form-settings-temp */
    jQuery("#elex_raq_table_body").on('click', '.remove-row', function () {

        var tbody = jQuery('.fields_adjustment').find('tbody');
        var size = tbody.find('tr').length;
        var id = jQuery(this).closest("tr").attr("id");
        var value = jQuery(this).closest("tr").attr("class");
        var nonce = transalted_pmd.nonce;
        if (value === "nodiscount") {

            jQuery("#nodiscount").remove();

        } else if (size === 1) {

            if (id && transalted_pmd['payment_id'] && transalted_pmd['payment_id'][id]) {
                jQuery('select.payment-method-select').append('<option value="' + id + '">' + transalted_pmd['payment_id'][id] + '</option>');
            }
            jQuery(this).closest("tr").remove();

            var default_code = '<tr id="nodiscount" class="nodiscount"><td colspan="7" class="nodiscount_cell"><span id="no_discount_available">' + transalted_pmd['tooltip']['nodiscount'] + '</span></td></tr>';

            jQuery('#elex_raq_table_body').append(default_code);

        } else {

            if (id && transalted_pmd['payment_id'] && transalted_pmd['payment_id'][id]) {
                jQuery('select.payment-method-select').append('<option value="' + id + '">' + transalted_pmd['payment_id'][id] + '</option>');
            }
            jQuery(this).closest("tr").remove();

        }
    });

    jQuery("#elex_raq_table_body").on('click', '.checkbox_click', function () {

        var v = jQuery(this).closest("td").find('input').val();


        if (v == "yes") {
            jQuery(this).closest("td").find('input').val("no");
            jQuery(this).closest("td").find('label').attr('title', transalted_pmd['tooltip']['toggleon']);
        }


        if (v == "no") {
            jQuery(this).closest("td").find('input').val("yes");
            jQuery(this).closest("td").find('label').attr('title', transalted_pmd['tooltip']['toggleoff']);

        }


    });

    jQuery('#elex_raq_add_field_discount').click(function () {
        if (jQuery('#nodiscount').length) {
            jQuery('#nodiscount').remove();
        }

        var tbody = jQuery('.fields_adjustment').find('tbody');
        var size = tbody.find('tr').length;
        var usedMethods = [];
        // Collect from new row dropdowns (unsaved)
        jQuery('#elex_raq_table_body select.payment-method-select').each(function () {
            if (jQuery(this).val()) {
                usedMethods.push(jQuery(this).val());
            }
        });
        // Collect from saved rows (hidden inputs — shown as bold text)
        jQuery('#elex_raq_table_body input[name$="[id]"]').each(function () {
            if (jQuery(this).val()) {
                usedMethods.push(jQuery(this).val());
            }
        });
        var paymentOptions = '<option value="">Select payment method</option>';
        jQuery.each(transalted_pmd['payment_id'], function (key, value) {
            if (!usedMethods.includes(key)) {
                paymentOptions += '<option value="' + key + '">' + value + '</option>';
            }
        });
        if (paymentOptions === '<option value="">Select payment method</option>') {
            alert(transalted_pmd['tooltip']['allpaymentselected']);
            return false;
        }
        var code = '<tr>\
        <td class="table_col_1"></td>\
        <td class="table_col_2">\
            <select class="payment-method-select" required \
                name="elex_discount_per_payment_method_options[' + size + '][id]">\
                ' + paymentOptions + '\
            </select>\
            <input type="hidden" \
                name="elex_discount_per_payment_method_options[' + size + '][type]" value="" />\
        </td>\
        <td class="table_col_3">\
            <select name="elex_discount_per_payment_method_options[' + size + '][discount_type]">\
                <option value="percentage">Percentage</option>\
                <option value="fixed">Fixed Amount</option>\
            </select>\
        </td>\
        <td class="table_col_3">\
    <input type="number" \
        class="discount-value" \
        min="0" \
        step="1" \
        inputmode="numeric" \
        pattern="[0-9]*" \
        name="elex_discount_per_payment_method_options[' + size + '][value]" \
        value="0" />\
</td>\
        <td class="table_col_3">\
    <input type="text" \
        name="elex_discount_per_payment_method_options[' + size + '][row_label]" \
        placeholder="Discount label" \
        value="" />\
</td>\
        <td class="table_col_4">\
            <label class="switch">\
                <input type="hidden" \
                    name="elex_discount_per_payment_method_options[' + size + '][checkbox_value]" value="yes">\
                <input type="checkbox" checked class="checkbox_click">\
                <span class="slider round"></span>\
            </label>\
        </td>\
        <td class="remove_icon_discount"><button type="button" class="remove-row" aria-label="Remove discount row"><span class="dashicons dashicons-minus" aria-hidden="true"></span></button></td>\
    </tr>';

        jQuery('#elex_raq_table_body').append(code);

        return false;
    });
    jQuery('#elex_raq_table_body').on('change', '.payment-method-select', function () {

        var paymentId = jQuery(this).val();
        var paymentText = jQuery(this).find(':selected').text();
        var currentSelect = this;
        var row = jQuery(this).closest('tr');

        if (!paymentId) { return; }

        // Check saved rows (hidden inputs)
        var isDuplicate = false;
        jQuery('#elex_raq_table_body input[name$="[id]"]').each(function () {
            if (jQuery(this).val() === paymentId) {
                isDuplicate = true;
                return false;
            }
        });

        // Check other new row dropdowns
        if (!isDuplicate) {
            jQuery('#elex_raq_table_body select.payment-method-select').each(function () {
                if (this !== currentSelect && jQuery(this).val() === paymentId) {
                    isDuplicate = true;
                    return false;
                }
            });
        }

        if (isDuplicate) {
            alert('"' + paymentText + '" is already added. Please remove the existing rule first.');
            jQuery(this).val(''); // reset dropdown
            return;
        }

        row.attr('id', paymentId);
        row.find('input[name$="[id]"]').val(paymentId);
        row.find('input[name$="[type]"]').val(paymentText);

    });
    jQuery(document).on('keydown', '.discount-value', function (e) {
        if (
            e.key === 'e' ||
            e.key === 'E' ||
            e.key === '+' ||
            e.key === '-'
        ) {
            e.preventDefault();
        }
    });
    jQuery(document).on('input', '.discount-value', function () {

        var row = jQuery(this).closest('tr');
        var discountType = row.find('select[name$="[discount_type]"]').val();
        var value = parseInt(jQuery(this).val(), 10);

        if (isNaN(value)) {
            jQuery(this).val('');
            return;
        }

        if (discountType === 'percentage') {
            if (value < 0) jQuery(this).val(0);
            if (value > 100) jQuery(this).val(100);
        }

        if (discountType === 'fixed') {
            if (value < 0) jQuery(this).val(0);
        }
    });
    jQuery(document).on('change', 'select[name$="[discount_type]"]', function () {

        var row = jQuery(this).closest('tr');
        var input = row.find('.discount-value');

        if (jQuery(this).val() === 'percentage') {
            input.attr('max', 100);
        } else {
            input.removeAttr('max');
        }

        input.val(0);
    });
    /* Block save if required fields are empty */
    jQuery('form#mainform').on('submit', function (e) {
        var valid = true;
        jQuery('#elex_raq_table_body tr').not('#nodiscount').each(function () {
            var paymentSelect = jQuery(this).find('select.payment-method-select');
            var valueInput = jQuery(this).find('input.discount-value');

            if (paymentSelect.length && !paymentSelect.val()) {
                alert('Please select a payment method for all rows before saving.');
                paymentSelect.css('border-color', '#d63638');
                valid = false;
                return false;
            }
            if (valueInput.length && (valueInput.val() === '' || valueInput.val() === null)) {
                alert('Please enter a discount value for all rows before saving.');
                valueInput.css('border-color', '#d63638');
                valid = false;
                return false;
            }
        });
        if (!valid) { e.preventDefault(); return false; }
    });

    /* Clear red border on field focus */
    jQuery(document).on('focus', '.payment-method-select, .discount-value', function () {
        jQuery(this).css('border-color', '');
    });

    (function ($) {

        $('form.checkout').on('change', 'input[name^="payment_method"]', function () {

            $('body').trigger('update_checkout');

        });

    })(jQuery);


});

