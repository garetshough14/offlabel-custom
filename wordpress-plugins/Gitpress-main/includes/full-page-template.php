<?php
/** GitPress canvas: loaded after membership and canonical redirects complete. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

DGS_Page_Shortcode_Manager::maybe_render_full_page_canvas();
