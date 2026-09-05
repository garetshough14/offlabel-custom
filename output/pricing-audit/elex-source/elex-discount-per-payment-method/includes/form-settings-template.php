<?php

/**
 *
 * Form Settings.
 *
 * @package Elex Discount Per Payment Mehtod
 */

// To check whether accessed directly.
if (!defined('ABSPATH')) {
	exit;
}
$nonce = wp_create_nonce('form_data');
?>
<tr valign="top">
	<td class="forminp" colspan="2" style="padding-left:0px">
		<div class="elex-discount-payment-method-wrapper">
			<table class="fields_adjustment widefat" id="elex_discount_per_payment_method_options">
				<input type="hidden" name="nounce_verify" value="<?php echo esc_attr($nonce); ?>">
				<thead>
					<tr>
						<th class="table_header">&nbsp;</th>
						<th class="table_header">
							<?php esc_html_e('Payment Method', 'elex_wfp_discount_per_payment_method'); ?>
						</th>
						<th class="table_header">
							<?php esc_html_e('Discount Type', 'elex_wfp_discount_per_payment_method'); ?>
						</th>
						<th class="table_header">
							<?php esc_html_e('Percentage / Fixed Amount', 'elex_wfp_discount_per_payment_method'); ?>
						</th>
						<th><?php esc_html_e('Discount Label', 'elex_wfp_discount_per_payment_method'); ?></th>
						<th class="table_header"><?php esc_html_e('Enable', 'elex_wfp_discount_per_payment_method'); ?>
						</th>
						<th class="table_header_remove">
							<?php esc_html_e('Remove', 'elex_wfp_discount_per_payment_method'); ?>
						</th>
					</tr>
				</thead>
				<tbody id='elex_raq_table_body'>
					<?php

					$default_form_fields = array();

					$form_adjustment_fields = !empty(get_option('elex_discount_per_payment_method_options')) ? get_option('elex_discount_per_payment_method_options') : $default_form_fields;

					if (!empty($form_adjustment_fields)) {

						foreach ($form_adjustment_fields as $key => $value) {

							?>
							<tr id='<?php echo esc_attr($value['id']); ?>'
								class='<?php echo esc_attr( isset($value['type']) ? $value['type'] : '' ); ?>'>
								<td class='table_col_1'></td>
								<td class="table_col_2">

									<?php if (!empty($value['id'])) : ?>
										<input type="hidden"
											name="elex_discount_per_payment_method_options[<?php echo esc_attr($key); ?>][id]"
											value="<?php echo esc_attr($value['id']); ?>" />

										<input type="hidden"
											name="elex_discount_per_payment_method_options[<?php echo esc_attr($key); ?>][type]"
											value="<?php echo esc_attr($value['type']); ?>" />

										<span class="bold_span">
											<?php echo esc_html($value['type']); ?>
										</span>

									<?php else : ?>
										<select class="payment-method-select"
											name="elex_discount_per_payment_method_options[<?php echo esc_attr($key); ?>][id]">
											<option value="">
												<?php esc_html_e('Select payment method', 'elex_wfp_discount_per_payment_method'); ?>
											</option>
											<?php foreach (WC()->payment_gateways->get_available_payment_gateways() as $gateway_id => $gateway) : ?>
												<option value="<?php echo esc_attr($gateway_id); ?>">
													<?php echo esc_html($gateway->get_title()); ?>
												</option>
											<?php endforeach; ?>
										</select>
									<?php endif; ?>

								</td>
								<td class="table_col_3">
									<select
										name="elex_discount_per_payment_method_options[<?php echo esc_attr($key); ?>][discount_type]"
										required>
										<option value="percentage" <?php selected( isset($value['discount_type']) ? $value['discount_type'] : 'percentage', 'percentage' ); ?>>
											<?php esc_html_e('Percentage', 'elex_wfp_discount_per_payment_method'); ?>
										</option>
										<option value="fixed" <?php selected( isset($value['discount_type']) ? $value['discount_type'] : 'percentage', 'fixed' ); ?>>
											<?php esc_html_e('Fixed Amount', 'elex_wfp_discount_per_payment_method'); ?>
										</option>
									</select>
								</td>

								<td class="table_col_3">
									<input type="number" class="order discount-value" min="0" step="1" inputmode="numeric"
										pattern="[0-9]*"
										name="elex_discount_per_payment_method_options[<?php echo esc_attr($key); ?>][value]"
										value="<?php echo esc_attr($value['value']); ?>" required>
								</td>
								<td class="table_col_3">
									<?php 
									$global_label = get_option('elex_wfp_discount_per_payment_method_label');
									$display_label = isset($value['row_label']) ? $value['row_label'] : $global_label;
									?>
									<input type="text" class="order"
										name="elex_discount_per_payment_method_options[<?php echo esc_attr($key); ?>][row_label]"
										value="<?php echo esc_attr( $display_label ); ?>"
										placeholder="<?php esc_attr_e('Discount label', 'elex_wfp_discount_per_payment_method'); ?>">
								</td>

								<?php if ('yes' === $value['checkbox_value']) { ?>

									<td class="table_col_4">
										<label
											title="<?php esc_html_e('Click here to disable the discount.', 'elex_wfp_discount_per_payment_method'); ?>"
											class="switch">
											<input type="hidden" class="order"
												name="elex_discount_per_payment_method_options[<?php echo esc_attr($key); ?>][checkbox_value]"
												value="yes" required />
											<input type="checkbox" class="checkbox_click"
												name="elex_discount_per_payment_method_options[<?php echo esc_attr($key); ?>][enabled]"
												checked>
											<span class="slider round"></span>
										</label>
									</td>

								<?php } else { ?>

									<td class="table_col_4">
										<label
											title="<?php esc_html_e('Click here to enable the discount.', 'elex_wfp_discount_per_payment_method'); ?>"
											class="switch">
											<input type="hidden" class="order"
												name="elex_discount_per_payment_method_options[<?php echo esc_attr($key); ?>][checkbox_value]"
												value="no" required />
											<input type="checkbox" class="checkbox_click"
												name="elex_discount_per_payment_method_options[<?php echo esc_attr($key); ?>][enabled]">
											<span class="slider round"></span>
										</label>
									</td>

								<?php } ?>

								<td class="remove_icon_discount">
									<button type="button" class="remove-row" aria-label="<?php esc_attr_e('Remove discount row', 'elex_wfp_discount_per_payment_method'); ?>"><span class="dashicons dashicons-minus" aria-hidden="true"></span></button>
								</td>
							</tr>
								<?php

						}
					} else {

						?>

						<tr id='nodiscount' class='nodiscount'>
							<td colspan="7" class='nodiscount_cell'>
								<span
									id='no_discount_available'><?php esc_html_e('No payment method discounts have been specified. Select a payment method from the drop-down to set up a discount.', 'elex_wfp_discount_per_payment_method'); ?></span>
							</td>
						</tr>

						<?php
					}

					?>

				</tbody>
				<tfoot>
					<tr class="table_input_action_row">
						<td colspan="7" class="col_btn">
							<button type="button" class="btn_raw add-row-btn" id="elex_raq_add_field_discount">
								<span class="dashicons dashicons-plus" aria-hidden="true"></span>
								<?php esc_html_e('Add new discount', 'elex_wfp_discount_per_payment_method'); ?>
							</button>
						</td>
					</tr>
				</tfoot>

			</table>
		</div><!-- /.elex-discount-payment-method-wrapper -->
	</td>
</tr>
