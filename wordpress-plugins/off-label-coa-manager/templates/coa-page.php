<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<main class="olr-coa-detail" data-olr-coa-viewer data-pdf-url="<?php echo esc_url( $pdf_url ); ?>">
	<section class="olr-coa-detail__hero">
		<div class="olr-coa-detail__identity">
			<p class="olr-coa-detail__crumb"><a href="<?php echo esc_url( home_url( '/coas/' ) ); ?>">The receipts</a><span>/</span><?php echo esc_html( $product->get_name() ); ?></p>
			<h1><?php echo esc_html( $product->get_name() ); ?></h1>
			<h2>Third-party testing documentation</h2>
			<i aria-hidden="true"></i>
			<p>Independent testing documentation is available in the original report below.</p>
			<div class="olr-coa-detail__actions"><a class="is-primary" href="<?php echo esc_url( $pdf_url ); ?>" target="_blank" rel="noopener">View original report <span>→</span></a><a href="<?php echo esc_url( $pdf_url ); ?>" download>Download COA <span aria-hidden="true">⇩</span></a></div>
		</div>
	</section>

	<section class="olr-pdf-viewer" aria-label="Certificate of Analysis viewer">
		<div class="olr-pdf-viewer__toolbar">
			<span class="olr-pdf-viewer__filename"><?php echo esc_html( basename( (string) get_attached_file( $pdf_id ) ) ); ?></span>
			<div class="olr-pdf-viewer__controls">
				<button type="button" data-pdf-prev aria-label="Previous page">‹</button><label><span class="screen-reader-text">Current page</span><input data-pdf-page type="number" min="1" value="1"></label><span>/ <b data-pdf-pages>1</b></span>
				<button type="button" data-pdf-zoom-out aria-label="Zoom out">−</button><output data-pdf-zoom>100%</output><button type="button" data-pdf-zoom-in aria-label="Zoom in">+</button>
				<button type="button" data-pdf-rotate aria-label="Rotate report">↻</button><button type="button" data-pdf-fullscreen aria-label="View report fullscreen">⛶</button>
			</div>
			<div class="olr-pdf-viewer__utilities"><a href="<?php echo esc_url( $pdf_url ); ?>" download aria-label="Download report">⇩</a><button type="button" data-pdf-print aria-label="Print report">▣</button></div>
		</div>
		<div class="olr-pdf-viewer__stage" data-pdf-stage><canvas data-pdf-canvas></canvas><p data-pdf-status>Loading report…</p></div>
		<noscript><p class="olr-pdf-viewer__fallback">The interactive viewer requires JavaScript. <a href="<?php echo esc_url( $pdf_url ); ?>">Open the PDF directly.</a></p></noscript>
	</section>

	<section class="olr-coa-detail__information">
		<article><h2>What you’re looking at</h2><i aria-hidden="true"></i><p>This Certificate of Analysis (COA) is the original third-party report for this research product. Sample identifiers, laboratory information, analytical methods, and results are contained in the PDF.</p><p>Use the viewer above or open the original report to review the complete laboratory record.</p></article>
		<article><h2>Previous testing</h2><i aria-hidden="true"></i><?php if ( $reports ) : ?><div class="olr-coa-detail__history"><?php foreach ( $reports as $report ) : $history_pdf_id = absint( get_post_meta( $report->ID, '_olr_pdf_id', true ) ); ?><a href="<?php echo esc_url( wp_get_attachment_url( $history_pdf_id ) ); ?>" target="_blank" rel="noopener"><span><strong><?php echo esc_html( $this->display_date( get_post_meta( $report->ID, '_olr_test_date', true ) ) ); ?></strong><small>Original laboratory report</small></span><b>View report&nbsp; →</b></a><?php endforeach; ?></div><?php else : ?><p>No previous published testing is available for this product.</p><?php endif; ?><a class="olr-coa-detail__history-all" href="<?php echo esc_url( home_url( '/coas/' ) ); ?>">View all testing history&nbsp; →</a></article>
	</section>

	<section class="olr-coa-detail__banner"><div><h2>The data is the point.</h2><p>Every batch is tested by independent laboratories.<br>We publish the results so you can verify.</p></div><img src="https://cdn.jsdelivr.net/gh/garetshough14/offlabel-custom@main/images/editorial/newsletter-molecule-black.png" alt="" loading="lazy"></section>
	<nav class="olr-coa-detail__navigation" aria-label="Receipt navigation"><a href="<?php echo esc_url( home_url( '/coas/' ) ); ?>">←&nbsp; Back to the receipts</a><a href="<?php echo esc_url( function_exists( 'olr_get_research_product_url' ) ? olr_get_research_product_url( $product ) : home_url( '/catalog/' . $product->get_slug() . '/' ) ); ?>">View <?php echo esc_html( $product->get_name() ); ?> product&nbsp; →</a></nav>
</main>
