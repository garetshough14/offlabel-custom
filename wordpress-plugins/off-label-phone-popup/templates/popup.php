<?php defined( 'ABSPATH' ) || exit; ?>
<dialog class="olr-pp" id="olr-pp-dialog" aria-labelledby="olr-pp-title" aria-describedby="olr-pp-description" <?php echo $preview ? 'data-preview="true"' : ''; ?>>
	<div class="olr-pp__content">
		<button type="button" class="olr-pp__close" data-pp-close aria-label="Close phone signup"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18" stroke="currentColor" stroke-width="1.5"/></svg></button>
		<p class="olr-pp__eyebrow">OFF LABEL RESEARCH <span aria-hidden="true">/</span> YOUR ACCOUNT</p>
		<div class="olr-pp__mark" aria-hidden="true"><svg width="27" height="34" viewBox="0 0 27 34" fill="none"><rect x="5" y="2" width="17" height="30" rx="3" stroke="currentColor" stroke-width="1.5"/><path d="M10 6h7M12 28h3" stroke="currentColor" stroke-width="1.5"/></svg></div>
		<h2 id="olr-pp-title"><?php echo esc_html( $settings['title'] ); ?></h2>
		<p id="olr-pp-description"><?php echo esc_html( $settings['body'] ); ?></p>
		<form id="olr-pp-form" novalidate>
			<label class="olr-pp__label" for="olr-pp-phone">Mobile number</label>
			<input id="olr-pp-phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" maxlength="40" required placeholder="+1 (415) 555-0123" aria-describedby="olr-pp-phone-help olr-pp-error">
			<p class="olr-pp__help" id="olr-pp-phone-help"><?php echo $settings['local_numbers'] ? 'US / Canada: 10 digits. Elsewhere: include + and your country code.' : 'Include + and your country code.'; ?></p>
			<div class="olr-pp__consent" <?php echo empty( $settings['sms'] ) ? 'hidden' : ''; ?>>
				<label for="olr-pp-consent"><input type="checkbox" id="olr-pp-consent" name="consent" value="1" <?php echo ! empty( $settings['sms'] ) ? 'required' : ''; ?> aria-describedby="olr-pp-error"><span><?php echo esc_html( $settings['consent'] ); ?></span></label>
				<p class="olr-pp__legal"><a data-pp-terms href="<?php echo esc_url( $settings['terms_url'] ); ?>" target="_blank" rel="noopener">SMS terms</a><span aria-hidden="true"> · </span><a data-pp-privacy href="<?php echo esc_url( $settings['privacy_url'] ); ?>" target="_blank" rel="noopener">Privacy policy</a> <span>(opens a new tab)</span></p>
			</div>
			<p id="olr-pp-error" class="olr-pp__error" role="alert"></p>
			<button class="olr-pp__submit" type="submit"><?php echo esc_html( $settings['button'] ); ?></button>
			<button class="olr-pp__later" type="button" data-pp-close>Not now</button>
		</form>
		<div class="olr-pp__success" id="olr-pp-success" hidden>
			<p class="olr-pp__success-label">ALL SET</p>
			<p id="olr-pp-result" role="status" tabindex="-1"></p>
			<button class="olr-pp__submit" type="button" data-pp-close>Done</button>
		</div>
		<p class="olr-pp__footer"><?php echo $preview ? 'PREVIEW · No account changes or texts' : 'Saved to your existing Off Label account.'; ?></p>
	</div>
</dialog>
