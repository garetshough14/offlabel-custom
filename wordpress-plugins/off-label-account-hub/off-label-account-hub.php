<?php
/**
 * Plugin Name: Off Label Account Hub
 * Description: Unified Ultimate Member, WooCommerce, and Ultimate Affiliate Pro account experience for Off Label Research.
 * Version: 1.1.2
 * Author: Off Label Research
 * Text Domain: off-label-account-hub
 * Requires Plugins: ultimate-member, woocommerce
 */

defined( 'ABSPATH' ) || exit;

final class OLR_Account_Hub {
	const VERSION                  = '1.1.2';
	const ACCOUNT_SLUG             = 'account';
	const AFFILIATE_SLUG           = 'affiliate';
	const GUIDELINES_SLUG          = 'affiliate-guidelines';
	const CDN_ASSET_BASE           = 'https://cdn.jsdelivr.net/gh/garetshough14/offlabel-custom@main/wordpress-plugins/off-label-account-hub/assets/';
	const OPTION_TERMS_URL         = 'olr_affiliate_terms_url';
	const OPTION_NOTIFICATION_EMAIL = 'olr_affiliate_notification_email';
	const META_STATUS              = '_olr_affiliate_application_status';
	const META_URL                 = '_olr_affiliate_application_url';
	const META_PLAN                = '_olr_affiliate_application_plan';
	const META_SUBMITTED           = '_olr_affiliate_application_submitted_at';
	const META_TERMS_URL           = '_olr_affiliate_terms_url_accepted';
	const META_TERMS_ACCEPTED      = '_olr_affiliate_terms_accepted_at';
	const META_APPROVED            = '_olr_affiliate_application_approved_at';
	const META_REJECTED            = '_olr_affiliate_application_rejected_at';

	private static $instance;
	private static $rendering_hub = false;
	private $late_styles_printed  = false;

	/**
	 * Return the single plugin instance.
	 *
	 * @return OLR_Account_Hub
	 */
	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'olr_account_hub', array( $this, 'shortcode' ) );
		add_shortcode( 'olr_affiliate_landing', array( $this, 'affiliate_landing_shortcode' ) );
		add_shortcode( 'olr_affiliate_guidelines', array( $this, 'affiliate_guidelines_shortcode' ) );
		add_filter( 'dgs_allowed_inner_shortcodes', array( $this, 'allow_gitpress_shortcodes' ) );
		add_filter( 'body_class', array( $this, 'body_classes' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'frontend_assets' ), 40 );
		add_action( 'init', array( $this, 'maybe_submit_frontend_application' ), 1 );
		add_action( 'template_redirect', array( $this, 'route_account_requests' ), 5 );
		add_filter( 'uap_filter_on_load_template', array( $this, 'uap_template_override' ), 100, 2 );

		add_filter( 'um_account_page_default_tabs_hook', array( $this, 'ultimate_member_tabs' ), 100 );
		add_filter( 'um_change_default_tab', array( $this, 'ultimate_member_current_tab' ), 100, 2 );
		foreach ( $this->custom_tab_slugs() as $tab_slug ) {
			add_action( 'um_account_tab__' . $tab_slug, array( $this, 'ultimate_member_tab_controller' ) );
			add_filter( 'um_account_content_hook_' . $tab_slug, array( $this, 'ultimate_member_tab_content' ) );
		}

		add_action( 'admin_post_olr_submit_affiliate_application', array( $this, 'submit_application' ) );
		add_action( 'admin_post_olr_affiliate_application_action', array( $this, 'application_admin_action' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ), 99 );
		add_action( 'admin_init', array( $this, 'protect_uap_affiliate_deletion' ), 1 );
		add_action( 'wp_ajax_uap_ajax_remove_one_affiliate', array( $this, 'protect_uap_affiliate_ajax_deletion' ), -100 );
		add_action( 'wp_ajax_uap_ajax_remove_many_affiliates', array( $this, 'protect_uap_affiliate_ajax_deletion' ), -100 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'dependency_notices' ) );
	}

	/**
	 * Create unpublished fallback pages for the two public affiliate surfaces.
	 * GitPress remains responsible for the final managed page bodies and chrome.
	 */
	public static function activate() {
		$pages = array(
			self::AFFILIATE_SLUG  => array(
				'title'   => __( 'Affiliate', 'off-label-account-hub' ),
				'content' => '[olr_affiliate_landing]',
			),
			self::GUIDELINES_SLUG => array(
				'title'   => __( 'Affiliate Guidelines', 'off-label-account-hub' ),
				'content' => '[olr_affiliate_guidelines]',
			),
		);

		foreach ( $pages as $slug => $page ) {
			if ( get_page_by_path( $slug ) ) {
				continue;
			}

			wp_insert_post(
				array(
					'post_title'     => $page['title'],
					'post_name'      => $slug,
					'post_content'   => $page['content'],
					'post_status'    => 'draft',
					'post_type'      => 'page',
					'comment_status' => 'closed',
				)
			);
		}
	}

	/**
	 * Custom Ultimate Member tab slugs owned by this plugin.
	 *
	 * @return string[]
	 */
	private function custom_tab_slugs() {
		return array(
			'overview',
			'orders',
			'affiliate',
			'performance',
			'commissions',
			'payouts',
			'creative',
			'guidelines',
			'olr_logout',
		);
	}

	/**
	 * Make nested account shortcodes available to GitPress managed pages.
	 *
	 * @param array $shortcodes Existing allowlist.
	 * @return array
	 */
	public function allow_gitpress_shortcodes( $shortcodes ) {
		$shortcodes = is_array( $shortcodes ) ? $shortcodes : array();
		$shortcodes = array_merge(
			$shortcodes,
			array( 'olr_account_hub', 'olr_affiliate_landing', 'olr_affiliate_guidelines', 'ultimatemember_account', 'uap-account-page' )
		);

		return array_values( array_unique( $shortcodes ) );
	}

	/**
	 * Determine whether the current request is the canonical account page.
	 *
	 * @return bool
	 */
	private function is_account_request() {
		if ( is_admin() ) {
			return false;
		}

		if ( function_exists( 'is_page' ) && is_page( self::ACCOUNT_SLUG ) ) {
			return true;
		}

		if ( function_exists( 'um_is_core_page' ) && um_is_core_page( 'account' ) ) {
			return true;
		}

		$queried_id = function_exists( 'get_queried_object_id' ) ? absint( get_queried_object_id() ) : 0;
		if ( $queried_id ) {
			$post = get_post( $queried_id );
			if ( $post && function_exists( 'has_shortcode' ) && has_shortcode( (string) $post->post_content, 'olr_account_hub' ) ) {
				return true;
			}

			$core_pages = get_option( 'um_core_pages', array() );
			if ( is_array( $core_pages ) && isset( $core_pages['account'] ) && $queried_id === absint( $core_pages['account'] ) ) {
				return true;
			}

			$um_options = get_option( 'um_options', array() );
			if ( is_array( $um_options ) && isset( $um_options['core_account'] ) && $queried_id === absint( $um_options['core_account'] ) ) {
				return true;
			}

			if ( function_exists( 'UM' ) ) {
				$ultimate_member = UM();
				if ( is_object( $ultimate_member ) && method_exists( $ultimate_member, 'options' ) ) {
					$options = $ultimate_member->options();
					if ( is_object( $options ) && method_exists( $options, 'get' ) && $queried_id === absint( $options->get( 'core_account' ) ) ) {
						return true;
					}
				}
			}
		}

		$request_uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$request_path = $request_uri ? (string) wp_parse_url( $request_uri, PHP_URL_PATH ) : '';
		$home_path    = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( $request_path ) {
			$request_path = '/' . trim( $request_path, '/' ) . '/';
			$home_path    = '/' . trim( $home_path, '/' ) . '/';
			if ( '/' !== $home_path && 0 === strpos( $request_path, $home_path ) ) {
				$request_path = '/' . ltrim( substr( $request_path, strlen( $home_path ) ), '/' );
			}

			if ( '/account/' === $request_path || 0 === strpos( $request_path, '/account/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determine whether this is one of the public affiliate content pages.
	 *
	 * @param string $slug Optional page slug to test.
	 * @return bool
	 */
	private function is_public_affiliate_request( $slug = '' ) {
		$slugs = $slug ? array( sanitize_key( $slug ) ) : array( self::AFFILIATE_SLUG, self::GUIDELINES_SLUG );
		if ( function_exists( 'is_page' ) && is_page( $slugs ) ) {
			return true;
		}

		$queried_id = function_exists( 'get_queried_object_id' ) ? absint( get_queried_object_id() ) : 0;
		if ( ! $queried_id ) {
			return false;
		}

		$post = get_post( $queried_id );
		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		$shortcodes = array(
			self::AFFILIATE_SLUG  => 'olr_affiliate_landing',
			self::GUIDELINES_SLUG => 'olr_affiliate_guidelines',
		);
		foreach ( $slugs as $candidate ) {
			if ( $candidate === $post->post_name || ( isset( $shortcodes[ $candidate ] ) && has_shortcode( (string) $post->post_content, $shortcodes[ $candidate ] ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve Ultimate Member's active tab from the GitPress account URL.
	 *
	 * Ultimate Member normally receives this value through a WordPress rewrite
	 * variable. A GitPress-managed core page can render the requested pretty URL
	 * without populating that variable, which otherwise makes every route fall
	 * back to the general account panel.
	 *
	 * @param string $current_tab Ultimate Member's current/default tab.
	 * @param array  $args        Account shortcode arguments.
	 * @return string
	 */
	public function ultimate_member_current_tab( $current_tab, $args = array() ) {
		if ( ! $this->is_account_request() ) {
			return $current_tab;
		}

		$requested_tab = $this->requested_account_tab();

		if ( 'account' === $requested_tab ) {
			$requested_tab = 'general';
		}

		if ( ! $requested_tab ) {
			return $current_tab;
		}

		$available_tabs = array();
		if ( function_exists( 'UM' ) && UM()->account() && is_array( UM()->account()->tabs ) ) {
			$available_tabs = array_keys( UM()->account()->tabs );
		}

		if ( $available_tabs && ! in_array( $requested_tab, $available_tabs, true ) ) {
			$current_tab = sanitize_key( (string) $current_tab );
			return in_array( $current_tab, $available_tabs, true ) ? $current_tab : 'general';
		}

		if ( ! $available_tabs ) {
			$fallback_tabs = array_merge(
				array( 'general', 'password', 'privacy', 'delete', 'notifications', 'terms_conditions' ),
				$this->custom_tab_slugs()
			);
			if ( ! in_array( $requested_tab, $fallback_tabs, true ) ) {
				return $current_tab;
			}
		}

		return $requested_tab;
	}

	/**
	 * Read the requested account tab from either query or pretty URL routing.
	 *
	 * @return string
	 */
	private function requested_account_tab() {
		if ( isset( $_GET['um_tab'] ) && is_scalar( $_GET['um_tab'] ) ) {
			return sanitize_key( wp_unslash( (string) $_GET['um_tab'] ) );
		}

		$request_uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '';
		$request_path = $request_uri ? (string) wp_parse_url( $request_uri, PHP_URL_PATH ) : '';
		if ( $request_path && preg_match( '#/account/([^/]+)/?#i', $request_path, $matches ) ) {
			return sanitize_key( rawurldecode( $matches[1] ) );
		}

		return '';
	}

	/**
	 * Add a page-level styling hook to recognized account requests.
	 *
	 * @param array $classes Existing body classes.
	 * @return array
	 */
	public function body_classes( $classes ) {
		$classes = is_array( $classes ) ? $classes : array();
		if ( $this->is_account_request() ) {
			$classes[] = 'olr-account-hub-page';
		}
		if ( $this->is_public_affiliate_request( self::AFFILIATE_SLUG ) ) {
			$classes[] = 'olr-affiliate-landing-page';
		}
		if ( $this->is_public_affiliate_request( self::GUIDELINES_SLUG ) ) {
			$classes[] = 'olr-affiliate-guidelines-page';
		}

		return array_values( array_unique( $classes ) );
	}

	/**
	 * Load frontend assets only on the account route.
	 */
	public function frontend_assets( $force = false ) {
		$is_account  = $this->is_account_request();
		$is_affiliate = $this->is_public_affiliate_request();
		if ( ! $force && ! $is_account && ! $is_affiliate ) {
			return;
		}

		/* GitPress loads the nested UAP shortcode after normal content discovery. */
		if ( $is_account ) {
			foreach ( array( 'uap_public_style', 'uap_templates' ) as $uap_style ) {
				if ( wp_style_is( $uap_style, 'registered' ) ) {
					wp_enqueue_style( $uap_style );
				}
			}
			foreach ( array( 'uap-public-functions' ) as $uap_script ) {
				if ( wp_script_is( $uap_script, 'registered' ) ) {
					wp_enqueue_script( $uap_script );
				}
			}
			if ( defined( 'UAP_URL' ) && defined( 'UAP_ASSET_VERSION' ) && ! wp_script_is( 'uap-account_page-functions', 'enqueued' ) ) {
				wp_enqueue_script(
					'uap-account_page-functions',
					UAP_URL . 'assets/js/account_page.js',
					array( 'jquery' ),
					UAP_ASSET_VERSION,
					array( 'in_footer' => true )
				);
			}
		}

		wp_enqueue_style(
			'olr-account-hub',
			$this->account_asset_url( 'account-hub.css' ),
			array(),
			self::VERSION
		);
		wp_enqueue_script(
			'olr-account-hub',
			$this->account_asset_url( 'account-hub.js' ),
			array(),
			self::VERSION,
			true
		);
		wp_localize_script(
			'olr-account-hub',
			'olrAccountHub',
			array(
				'logoutUrl'    => wp_logout_url( home_url( '/' ) ),
				'guidelinesUrl' => home_url( '/' . self::GUIDELINES_SLUG . '/' ),
				'menuLabel'    => __( 'Account menu', 'off-label-account-hub' ),
				'copyLabel'    => __( 'Copy', 'off-label-account-hub' ),
				'copied'       => __( 'Copied', 'off-label-account-hub' ),
			)
		);
	}

	/**
	 * Resolve a bundled account asset, falling back to the public repository CDN
	 * when a managed host installs only the main plugin file.
	 *
	 * @param string $filename File name inside the plugin assets directory.
	 * @return string
	 */
	private function account_asset_url( $filename ) {
		$filename = sanitize_file_name( $filename );
		$nested = plugin_dir_path( __FILE__ ) . 'assets/' . $filename;
		if ( is_readable( $nested ) ) {
			return plugins_url( 'assets/' . $filename, __FILE__ );
		}

		$root = plugin_dir_path( __FILE__ ) . $filename;
		if ( is_readable( $root ) ) {
			return plugins_url( $filename, __FILE__ );
		}

		return self::CDN_ASSET_BASE . rawurlencode( $filename );
	}

	/**
	 * Inline the scoped stylesheet when GitPress discovers the nested shortcode
	 * after wp_head has already printed. Normal account requests still use the
	 * enqueued stylesheet from the document head.
	 *
	 * @return string
	 */
	private function late_style_markup() {
		if ( $this->late_styles_printed || ! did_action( 'wp_head' ) || wp_style_is( 'olr-account-hub', 'done' ) ) {
			return '';
		}

		$stylesheet = plugin_dir_path( __FILE__ ) . 'assets/account-hub.css';
		if ( ! is_readable( $stylesheet ) ) {
			$stylesheet = plugin_dir_path( __FILE__ ) . 'account-hub.css';
		}
		if ( ! is_readable( $stylesheet ) ) {
			$this->late_styles_printed = true;
			return '<link rel="stylesheet" id="olr-account-hub-late-css" href="' . esc_url( $this->account_asset_url( 'account-hub.css' ) ) . '?ver=' . esc_attr( self::VERSION ) . '" media="all">';
		}

		$css = file_get_contents( $stylesheet );
		if ( false === $css ) {
			return '';
		}

		$this->late_styles_printed = true;
		$styles                    = wp_styles();
		if ( $styles && ! in_array( 'olr-account-hub', $styles->done, true ) ) {
			$styles->done[] = 'olr-account-hub';
		}

		return '<style id="olr-account-hub-late-css">' . $css . '</style>';
	}

	/**
	 * Render the canonical account shortcode.
	 *
	 * @return string
	 */
	public function shortcode() {
		/* The GitPress page body can reveal this shortcode after wp_head. */
		$this->frontend_assets( true );
		$late_styles = $this->late_style_markup();

		if ( ! is_user_logged_in() ) {
			$login_url = $this->ultimate_member_login_url();
			$output    = $this->notice_panel(
				__( 'ACCOUNT ACCESS', 'off-label-account-hub' ),
				__( 'Sign in to review your account.', 'off-label-account-hub' ),
				$login_url,
				__( 'SIGN IN', 'off-label-account-hub' )
			);
		} elseif ( ! shortcode_exists( 'ultimatemember_account' ) ) {
			$output = $this->notice_panel(
				__( 'ACCOUNT TEMPORARILY UNAVAILABLE', 'off-label-account-hub' ),
				__( 'The member account service is not active. Please contact support.', 'off-label-account-hub' )
			);
		} else {
			/*
			 * Set this before UM initializes its tab fields. Our inactive affiliate
			 * sections intentionally render placeholders, so they need the requested
			 * route before UM starts building and caching those tab bodies.
			 */
			if ( function_exists( 'UM' ) && UM()->account() ) {
				UM()->account()->current_tab = $this->ultimate_member_current_tab(
					UM()->account()->current_tab,
					array()
				);
			}

			self::$rendering_hub = true;
			$output              = do_shortcode( '[ultimatemember_account]' );
			self::$rendering_hub = false;

			$current_tab = '';
			if ( function_exists( 'UM' ) && UM()->account() ) {
				$current_tab = sanitize_key( (string) UM()->account()->current_tab );
			}
			if ( in_array( $current_tab, $this->custom_tab_slugs(), true ) ) {
				$output = $this->replace_um_outer_form_with_shell( $output );
			}
		}

		$brand = sprintf(
			'<a class="olr-account-brand" href="%1$s" aria-label="%2$s"><img src="%3$s" alt="%4$s" width="1199" height="169"></a>',
			esc_url( home_url( '/' ) ),
			esc_attr__( 'Off Label Research home', 'off-label-account-hub' ),
			esc_url( $this->account_asset_url( 'off-label-logo-cropped-black.webp' ) ),
			esc_attr__( 'Off Label Research', 'off-label-account-hub' )
		);

		return $late_styles . '<div class="olr-account-hub" data-olr-account-hub>' . $brand . $output . '</div>';
	}

	/**
	 * Render the public Affiliate Program landing page.
	 *
	 * @return string
	 */
	public function affiliate_landing_shortcode() {
		$this->frontend_assets( true );
		$late_styles = $this->late_style_markup();
		$policy      = $this->affiliate_program_policy();
		$ctas        = $this->affiliate_public_ctas();
		$guidelines  = home_url( '/' . self::GUIDELINES_SLUG . '/' );
		$terms       = trim( (string) get_option( self::OPTION_TERMS_URL, '' ) );
		$terms       = $terms ? $terms : $guidelines;
		$ruo         = home_url( '/research-use-policy/' );

		ob_start();
		?>
		<div class="olr-affiliate-public olr-affiliate-landing" data-olr-affiliate-public>
			<section class="olr-affiliate-landing__hero" aria-labelledby="olr-affiliate-landing-title">
				<div class="olr-affiliate-landing__hero-copy">
					<p class="olr-affiliate-public__eyebrow"><?php esc_html_e( 'Off Label affiliate program', 'off-label-account-hub' ); ?></p>
					<h1 id="olr-affiliate-landing-title"><?php esc_html_e( 'SHARE THE RESEARCH. EARN FOR LIFE.', 'off-label-account-hub' ); ?></h1>
					<p><?php echo esc_html( sprintf( __( 'Invite others to Off Label and earn %s commission on qualifying purchases from customers you refer.', 'off-label-account-hub' ), $policy['commission'] ) ); ?></p>
					<div class="olr-affiliate-public__actions">
						<a class="olr-affiliate-public__button is-primary" href="<?php echo esc_url( $ctas['primary']['url'] ); ?>"><?php echo esc_html( $ctas['primary']['label'] ); ?><span aria-hidden="true">&rarr;</span></a>
						<a class="olr-affiliate-public__button" href="<?php echo esc_url( $ctas['secondary']['url'] ); ?>"><?php echo esc_html( $ctas['secondary']['label'] ); ?><span aria-hidden="true">&rarr;</span></a>
					</div>
				</div>
				<div class="olr-affiliate-landing__benefits" aria-label="<?php esc_attr_e( 'Affiliate program benefits', 'off-label-account-hub' ); ?>">
					<div><?php echo $this->affiliate_icon( 'tag' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><strong><?php echo esc_html( $policy['customer_discount'] ); ?></strong><span><b><?php esc_html_e( 'For them.', 'off-label-account-hub' ); ?></b><?php echo esc_html( sprintf( __( 'New customers receive %s off their first qualifying order.', 'off-label-account-hub' ), $policy['customer_discount'] ) ); ?></span></div>
					<div><?php echo $this->affiliate_icon( 'commission' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><strong><?php echo esc_html( $policy['commission'] ); ?></strong><span><b><?php esc_html_e( 'For you.', 'off-label-account-hub' ); ?></b><?php echo esc_html( sprintf( __( 'Earn %s commission on qualifying purchases.', 'off-label-account-hub' ), $policy['commission'] ) ); ?></span></div>
					<div><?php echo $this->affiliate_icon( 'infinity' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><strong><?php esc_html_e( 'Lifetime', 'off-label-account-hub' ); ?></strong><span><b><?php esc_html_e( 'Keep earning.', 'off-label-account-hub' ); ?></b><?php esc_html_e( 'When your customers return and order again, you earn again.', 'off-label-account-hub' ); ?></span></div>
				</div>
			</section>

			<section class="olr-affiliate-landing__payout" aria-labelledby="olr-affiliate-payout-title">
				<h2 id="olr-affiliate-payout-title"><?php esc_html_e( 'GETTING PAID IS SIMPLE.', 'off-label-account-hub' ); ?></h2>
				<div class="olr-affiliate-landing__payout-grid">
					<div><?php echo $this->affiliate_icon( 'document' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><h3><?php esc_html_e( 'W-9 required', 'off-label-account-hub' ); ?></h3><p><?php esc_html_e( 'Complete your W-9 so we can properly report your earnings.', 'off-label-account-hub' ); ?></p></div>
					<div><?php echo $this->affiliate_icon( 'payment' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><h3><?php echo esc_html( $policy['payout_method'] . ' ' . __( 'only', 'off-label-account-hub' ) ); ?></h3><p><?php echo esc_html( sprintf( __( 'Payouts are made exclusively through %s. Add your details before payout.', 'off-label-account-hub' ), $policy['payout_method'] ) ); ?></p></div>
					<div><?php echo $this->affiliate_icon( 'calendar' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><h3><?php echo esc_html( $policy['payout_schedule'] . ' ' . __( 'payouts', 'off-label-account-hub' ) ); ?></h3><p><?php echo esc_html( sprintf( __( '%1$s hold period on new commissions. %2$s minimum payout.', 'off-label-account-hub' ), $policy['hold_period'], $policy['minimum_payout'] ) ); ?></p></div>
				</div>
				<p class="olr-affiliate-landing__payout-note"><?php esc_html_e( 'You can activate your affiliate account and start earning right away. W-9 and payout information must be completed before any commission can be paid.', 'off-label-account-hub' ); ?></p>
			</section>

			<section class="olr-affiliate-landing__process" aria-labelledby="olr-affiliate-process-title">
				<h2 id="olr-affiliate-process-title"><?php esc_html_e( 'HOW IT WORKS.', 'off-label-account-hub' ); ?></h2>
				<ol>
					<li><span>01</span><?php echo $this->affiliate_icon( 'link' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><h3><?php esc_html_e( 'Share your link or code', 'off-label-account-hub' ); ?></h3><p><?php esc_html_e( 'Send your unique link or code to friends, followers, and your network.', 'off-label-account-hub' ); ?></p></li>
					<li><span>02</span><?php echo $this->affiliate_icon( 'customer' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><h3><?php esc_html_e( 'They become new customers', 'off-label-account-hub' ); ?></h3><p><?php echo esc_html( sprintf( __( 'They receive %s off their first qualifying order.', 'off-label-account-hub' ), $policy['customer_discount'] ) ); ?></p></li>
					<li><span>03</span><?php echo $this->affiliate_icon( 'package' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><h3><?php esc_html_e( 'They place an order', 'off-label-account-hub' ); ?></h3><p><?php esc_html_e( 'We track the sale and attribute the customer to you for life.', 'off-label-account-hub' ); ?></p></li>
					<li><span>04</span><?php echo $this->affiliate_icon( 'commission' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><h3><?php echo esc_html( sprintf( __( 'You earn %s commission', 'off-label-account-hub' ), $policy['commission'] ) ); ?></h3><p><?php echo esc_html( sprintf( __( 'After the %s hold, commissions become available.', 'off-label-account-hub' ), $policy['hold_period'] ) ); ?></p></li>
					<li><span>05</span><?php echo $this->affiliate_icon( 'repeat' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><h3><?php esc_html_e( 'They return. You earn again', 'off-label-account-hub' ); ?></h3><p><?php esc_html_e( 'Every future qualifying order earns you commission for the life of the customer.', 'off-label-account-hub' ); ?></p></li>
				</ol>
				<a class="olr-affiliate-public__button is-primary" href="<?php echo esc_url( $ctas['primary']['url'] ); ?>"><?php esc_html_e( 'SEE AFFILIATE DASHBOARD PREVIEW', 'off-label-account-hub' ); ?><span aria-hidden="true">&rarr;</span></a>
			</section>

			<section class="olr-affiliate-landing__policies" aria-label="<?php esc_attr_e( 'Affiliate requirements', 'off-label-account-hub' ); ?>">
				<article><h2><?php esc_html_e( 'RESEARCH USE ONLY.', 'off-label-account-hub' ); ?></h2><p><?php esc_html_e( 'Off Label products are offered solely for legitimate laboratory, analytical, and research purposes and are not intended for human or animal consumption.', 'off-label-account-hub' ); ?></p><a href="<?php echo esc_url( $ruo ); ?>"><?php esc_html_e( 'RESEARCH USE ONLY PROTOCOL', 'off-label-account-hub' ); ?> &rarr;</a></article>
				<article><h2><?php esc_html_e( 'RESPONSIBLY REPRESENT OFF LABEL.', 'off-label-account-hub' ); ?></h2><p><?php esc_html_e( 'Affiliates must follow our Affiliate Guidelines and represent Off Label accurately and responsibly at all times.', 'off-label-account-hub' ); ?></p><a href="<?php echo esc_url( $guidelines ); ?>"><?php esc_html_e( 'VIEW AFFILIATE GUIDELINES', 'off-label-account-hub' ); ?> &rarr;</a></article>
				<article><h2><?php esc_html_e( 'YOU’RE COVERED.', 'off-label-account-hub' ); ?></h2><p><?php esc_html_e( 'The complete terms governing the program, commission rules, payout policies, and more.', 'off-label-account-hub' ); ?></p><a href="<?php echo esc_url( $terms ); ?>"><?php esc_html_e( 'VIEW AFFILIATE TERMS', 'off-label-account-hub' ); ?> &rarr;</a></article>
			</section>

			<section class="olr-affiliate-landing__final" aria-labelledby="olr-affiliate-final-title">
				<div><h2 id="olr-affiliate-final-title"><?php esc_html_e( 'READY TO START EARNING?', 'off-label-account-hub' ); ?></h2><p><?php esc_html_e( 'Activate your affiliate access in just a few clicks.', 'off-label-account-hub' ); ?></p></div>
				<a class="olr-affiliate-public__button" href="<?php echo esc_url( $ctas['primary']['url'] ); ?>"><?php echo esc_html( $ctas['primary']['label'] ); ?><span aria-hidden="true">&rarr;</span></a>
			</section>
		</div>
		<?php

		return $late_styles . ob_get_clean();
	}

	/**
	 * Render the complete public affiliate guidelines.
	 *
	 * @return string
	 */
	public function affiliate_guidelines_shortcode() {
		$this->frontend_assets( true );
		$late_styles = $this->late_style_markup();
		$policy      = $this->affiliate_program_policy();
		$terms       = trim( (string) get_option( self::OPTION_TERMS_URL, '' ) );
		$terms       = $terms ? $terms : home_url( '/affiliate-terms/' );
		$ruo         = home_url( '/research-use-policy/' );
		$creative    = $this->account_tab_url( 'creative' );

		$cards = array(
			array( '01', 'SHARE OFF LABEL', '<p>Share your link and code through the channels that work for you.</p><ul class="olr-guidelines-icon-list"><li><b>Text</b><span>Send your link directly to friends and contacts.</span></li><li><b>Email</b><span>Share your link and code through individual email.</span></li><li><b>Social</b><span>Share through your social media accounts and content.</span></li><li><b>Website / Blog</b><span>Use your link within appropriate original content.</span></li></ul><strong class="olr-guidelines-card__closing">Your link. Your code.<br>We track the rest.</strong>' ),
			array( '02', 'DISCLOSE YOUR RELATIONSHIP', '<p>Be clear that you may earn a commission.</p><blockquote>I may earn a commission from purchases made through my Off Label link.</blockquote><button class="olr-guidelines-copy" type="button" data-olr-copy data-copy-value="I may earn a commission from purchases made through my Off Label link.">Copy disclosure <span aria-hidden="true">&rarr;</span></button><p>Do not hide the disclosure or make it difficult for your audience to understand your relationship.</p>' ),
			array( '03', 'RESEARCH USE ONLY', '<div class="olr-guidelines-ruo"><small>Research use only.</small><strong>Share the research.<br>Not a protocol.</strong><p>All Off Label affiliate content must comply with the Off Label Research Use Only Protocol.</p><b>For research use only.<br>Not for human consumption.</b></div>', 'dark' ),
			array( '04', 'USE THE BRAND RESPONSIBLY', '<p>Represent Off Label accurately and responsibly.</p><h3>You may identify yourself as:</h3><ul class="is-allowed"><li>Off Label Affiliate</li></ul><h3>You may not represent yourself as:</h3><ul class="is-prohibited"><li>An Off Label employee</li><li>Off Label customer service</li><li>An authorized medical representative</li><li>An official Off Label social account</li><li>Off Label management</li><li>The company itself</li></ul><p>Do not create profiles, pages, or websites designed to make someone believe you are an official Off Label property.</p>' ),
			array( '05', 'BRAND ASSETS', '<p>Use Off Label-approved assets to keep your content on brand.</p><ul class="is-checklist"><li>Product photography</li><li>Campaign graphics</li><li>Logos approved for affiliate use</li><li>Promotional assets</li><li>Approved captions</li><li>RUO language</li><li>Affiliate disclosure language</li></ul><p>You may create original content. It must follow the Affiliate Guidelines, RUO Protocol, and Affiliate Terms.</p>' ),
			array( '06', 'COUPON CODE RULES', '<p>Your code is for your audience.</p><p>Do not submit your affiliate code to coupon sites, deal aggregators, coupon databases, browser extensions, or similar mass-distribution services unless Off Label provides written approval.</p><div class="olr-guidelines-symbol">◇</div><p>Affiliate commission is designed to reward genuine customer referrals.</p>' ),
			array( '07', 'NO SELF-REFERRALS', '<p>Affiliate commission is not earned on your own purchases.</p><p>Off Label may reverse commissions associated with:</p><ul><li>Self-referrals</li><li>Duplicate accounts</li><li>Artificial or fraudulent transactions</li><li>Other referral abuse</li></ul>' ),
			array( '08', 'YES, FRIENDS + FAMILY', '<p>You can refer people you know. Legitimate referrals to friends, family, and people in your network are allowed.</p><div class="olr-guidelines-symbol">◎</div><strong class="olr-guidelines-card__closing">They need to be genuine customers—not transactions created simply to generate commission.</strong>' ),
			array( '09', 'NO SPAM', '<p>Share. Do not spam.</p><p>Do not use:</p><ul class="is-prohibited"><li>Purchased lists</li><li>Unsolicited bulk email or SMS</li><li>Automated spam</li><li>Deceptive outreach</li><li>Misleading messages</li></ul>' ),
			array( '10', 'PAID ADVERTISING', '<p>Without written approval, do not bid on or purchase ads targeting Off Label, Off Label Research, our domain names, misspellings, or other branded terms.</p><div class="olr-guidelines-symbol">$</div><p>Do not run ads that reasonably appear to be official Off Label advertising.</p>' ),
			array( '11', 'ACCURATE PROMOTIONS', '<p>Share the offer that actually exists.</p><ul class="is-checklist"><li>Current customer discount</li><li>Eligible products</li><li>Promotion dates</li><li>Affiliate code</li><li>Off Label pricing</li><li>Shipping offers</li><li>Other promotional terms</li></ul><p>Do not advertise expired or nonexistent offers.</p>' ),
			array( '12', 'DISCOUNT STACKING', '<p>One promotion at a time. Affiliate discounts do not stack with other percentage promotions unless Off Label specifically states otherwise.</p><table><thead><tr><th>Example</th><th></th></tr></thead><tbody><tr><td>Affiliate offer</td><td>' . esc_html( $policy['customer_discount'] ) . '</td></tr><tr><td>Current Off Label promo</td><td>30%</td></tr><tr><td>Customer receives</td><td>30%</td></tr></tbody></table><small>The applicable offer is determined according to Off Label rules.</small>' ),
			array( '13', 'HOW YOU EARN', '<div class="olr-guidelines-rate"><strong>' . esc_html( $policy['commission'] ) . '</strong><p>You earn ' . esc_html( $policy['commission'] ) . ' commission on qualifying net merchandise revenue after applicable discounts.</p></div><table><tbody><tr><td>Retail merchandise</td><td>$200.00</td></tr><tr><td>Customer receives ' . esc_html( $policy['customer_discount'] ) . ' off</td><td>−$40.00</td></tr><tr><td>Net eligible purchase</td><td>$160.00</td></tr><tr><td>Your commission (' . esc_html( $policy['commission'] ) . ')</td><td>$16.00</td></tr></tbody></table><p>Excludes shipping, tax, refunds, canceled items, chargebacks, and other noncommissionable amounts defined in the Affiliate Terms.</p>' ),
			array( '14', 'LIFETIME COMMISSION', '<p>Refer once. Keep earning.</p><div class="olr-guidelines-lifetime"><span>First order<br><b>' . esc_html( $policy['commission'] ) . '</b></span><i>→</i><span>Repeat order<br><b>' . esc_html( $policy['commission'] ) . '</b></span><i>→</i><span>Repeat order<br><b>' . esc_html( $policy['commission'] ) . '</b></span><i>→</i><span>Repeat order<br><b>' . esc_html( $policy['commission'] ) . '</b></span></div><strong class="olr-guidelines-card__closing">They do not need to keep using your code. You referred them. We track the relationship.</strong>' ),
			array( '15', 'STAY UP TO DATE', '<p>Program offers, commission structures, eligible transactions, payout requirements, and rules may change.</p><dl class="olr-guidelines-statuses"><div><dt>Pending</dt><dd>Within the ' . esc_html( $policy['hold_period'] ) . ' hold period.</dd></div><div><dt>Available</dt><dd>Cleared and eligible for payout.</dd></div><div><dt>Paid</dt><dd>Included in a completed payout.</dd></div><div><dt>Reversed</dt><dd>Adjusted due to refund, cancellation, chargeback, or other permitted reason.</dd></div></dl>' ),
			array( '16', 'GETTING PAID', '<ul class="is-checklist"><li>W-9 required</li><li>' . esc_html( $policy['payout_method'] ) . ' only</li><li>' . esc_html( $policy['payout_schedule'] ) . ' payouts</li><li>' . esc_html( $policy['minimum_payout'] ) . ' minimum payout</li><li>' . esc_html( $policy['hold_period'] ) . ' commission hold</li></ul><p>Tax and payout details must be complete before commissions can be paid.</p>', 'dark' ),
			array( '17', 'REFUNDS + CANCELLATIONS', '<p>Commission follows the sale.</p><ul class="is-prohibited"><li>Canceled orders can be canceled.</li><li>Refunded commission can be reduced or reversed.</li><li>Partially refunded orders are adjusted.</li><li>Chargebacks reverse related commission.</li><li>Paid commission may be deducted from a future payout where permitted.</li></ul>', 'dark' ),
			array( '18', 'VIOLATIONS', '<p>We protect the program.</p><p>When guidelines are violated, Off Label may investigate, issue a warning, reverse unpaid commission, suspend affiliate access, or remove an affiliate from the program.</p><ul><li>Warning</li><li>Commission reversed</li><li>Account suspension</li><li>Affiliate removal</li></ul>', 'dark' ),
			array( '19', 'STAY UP TO DATE', '<p>Program offers, commission structures, eligible transactions, payout requirements, and rules may change.</p><p>Material updates to Guidelines may be communicated through the affiliate account or current program materials.</p>', 'dark' ),
		);

		ob_start();
		?>
		<div class="olr-affiliate-public olr-affiliate-guidelines" data-olr-affiliate-public>
			<section class="olr-affiliate-guidelines__hero" aria-labelledby="olr-affiliate-guidelines-title">
				<div><p class="olr-affiliate-public__eyebrow"><?php esc_html_e( 'Off Label affiliate program', 'off-label-account-hub' ); ?></p><h1 id="olr-affiliate-guidelines-title"><?php esc_html_e( 'AFFILIATE GUIDELINES.', 'off-label-account-hub' ); ?></h1><h2><?php esc_html_e( 'Share Off Label. Earn responsibly. Protect the brand.', 'off-label-account-hub' ); ?></h2><p><?php esc_html_e( 'These guidelines explain the standards every Off Label affiliate agrees to follow when promoting the brand and participating in the Affiliate Program.', 'off-label-account-hub' ); ?></p><div class="olr-affiliate-public__actions"><a class="olr-affiliate-public__button is-primary" href="<?php echo esc_url( $ruo ); ?>"><?php esc_html_e( 'RESEARCH USE ONLY PROTOCOL', 'off-label-account-hub' ); ?><span aria-hidden="true">&rarr;</span></a><a class="olr-affiliate-public__button" href="<?php echo esc_url( $terms ); ?>"><?php esc_html_e( 'AFFILIATE TERMS', 'off-label-account-hub' ); ?><span aria-hidden="true">&rarr;</span></a></div></div>
				<div class="olr-affiliate-guidelines__seal" aria-label="<?php esc_attr_e( 'Represent Off Label. Earn responsibly.', 'off-label-account-hub' ); ?>"><span>REP OFF LABEL</span><strong>OFF<br>LABEL</strong><span>EARN RESPONSIBLY</span></div>
			</section>
			<section class="olr-affiliate-guidelines__grid" aria-label="<?php esc_attr_e( 'Affiliate program guidelines', 'off-label-account-hub' ); ?>">
				<?php foreach ( $cards as $card ) : ?>
					<article class="olr-guidelines-card<?php echo ! empty( $card[3] ) ? ' olr-guidelines-card--' . esc_attr( sanitize_html_class( $card[3] ) ) : ''; ?>">
						<span class="olr-guidelines-card__number"><?php echo esc_html( $card[0] ); ?></span>
						<h2><?php echo esc_html( $card[1] ); ?></h2>
						<div><?php echo wp_kses_post( $card[2] ); ?></div>
						<?php if ( '03' === $card[0] ) : ?><a class="olr-guidelines-card__link" href="<?php echo esc_url( $ruo ); ?>"><?php esc_html_e( 'VIEW RUO PROTOCOL', 'off-label-account-hub' ); ?> &rarr;</a><?php endif; ?>
						<?php if ( '05' === $card[0] ) : ?><a class="olr-guidelines-card__link" href="<?php echo esc_url( $creative ); ?>"><?php esc_html_e( 'VIEW CREATIVE LIBRARY', 'off-label-account-hub' ); ?> &rarr;</a><?php endif; ?>
					</article>
				<?php endforeach; ?>
				<article class="olr-guidelines-card olr-guidelines-card--summary"><small><?php esc_html_e( 'The short version', 'off-label-account-hub' ); ?></small><h2><?php esc_html_e( 'SHARE HONESTLY. REPRESENT IT WELL. FOLLOW THE RESEARCH RULES. WE’LL TRACK THE REST.', 'off-label-account-hub' ); ?></h2><div class="olr-guidelines-summary-metrics"><span><b><?php echo esc_html( $policy['customer_discount'] ); ?></b> for them.</span><span><b><?php echo esc_html( $policy['commission'] ); ?></b> for you.</span><span><b><?php echo esc_html( $policy['payout_method'] ); ?></b> payouts.</span><span><b><?php esc_html_e( 'Lifetime', 'off-label-account-hub' ); ?></b> commission.</span></div><div class="olr-affiliate-public__actions"><a class="olr-affiliate-public__button" href="<?php echo esc_url( $ruo ); ?>"><?php esc_html_e( 'VIEW RUO PROTOCOL', 'off-label-account-hub' ); ?> &rarr;</a><a class="olr-affiliate-public__button" href="<?php echo esc_url( $terms ); ?>"><?php esc_html_e( 'AFFILIATE TERMS', 'off-label-account-hub' ); ?> &rarr;</a></div></article>
			</section>
		</div>
		<?php

		return $late_styles . ob_get_clean();
	}

	/**
	 * Program terms shown consistently across the public and account surfaces.
	 *
	 * @return array
	 */
	public static function affiliate_program_policy() {
		$policy = array(
			'customer_discount' => '20%',
			'commission'        => '10%',
			'payout_method'     => 'Zelle',
			'payout_schedule'   => __( 'Monthly', 'off-label-account-hub' ),
			'hold_period'       => __( '30-day', 'off-label-account-hub' ),
			'minimum_payout'    => '$50',
		);

		return (array) apply_filters( 'olr_affiliate_program_policy', $policy );
	}

	/**
	 * Return state-aware public Affiliate Program actions.
	 *
	 * @return array
	 */
	private function affiliate_public_ctas() {
		$application_url = $this->account_tab_url( 'affiliate' );
		$dashboard_url   = $this->account_tab_url( 'overview' );
		$guidelines_url  = home_url( '/' . self::GUIDELINES_SLUG . '/' );

		if ( ! is_user_logged_in() ) {
			return array(
				'primary'   => array( 'label' => __( 'ACTIVATE AFFILIATE ACCESS', 'off-label-account-hub' ), 'url' => $this->ultimate_member_register_url( $application_url ) ),
				'secondary' => array( 'label' => __( 'ALREADY ACTIVE? SIGN IN', 'off-label-account-hub' ), 'url' => $this->ultimate_member_login_url( $application_url ) ),
			);
		}

		if ( self::is_active_affiliate( get_current_user_id() ) ) {
			return array(
				'primary'   => array( 'label' => __( 'OPEN AFFILIATE DASHBOARD', 'off-label-account-hub' ), 'url' => $dashboard_url ),
				'secondary' => array( 'label' => __( 'VIEW GUIDELINES', 'off-label-account-hub' ), 'url' => $guidelines_url ),
			);
		}

		$status = $this->application_status( get_current_user_id() );
		return array(
			'primary'   => array( 'label' => $status ? __( 'VIEW APPLICATION STATUS', 'off-label-account-hub' ) : __( 'ACTIVATE AFFILIATE ACCESS', 'off-label-account-hub' ), 'url' => $application_url ),
			'secondary' => array( 'label' => __( 'VIEW GUIDELINES', 'off-label-account-hub' ), 'url' => $guidelines_url ),
		);
	}

	/**
	 * Small trusted line icons used by the public affiliate presentation.
	 *
	 * @param string $name Icon name.
	 * @return string
	 */
	private function affiliate_icon( $name ) {
		$icons = array(
			'tag'        => '<svg viewBox="0 0 32 32" aria-hidden="true"><path d="M4 5h11l13 13-10 10L5 15Z"/><circle cx="10" cy="10" r="2"/></svg>',
			'commission' => '<svg viewBox="0 0 32 32" aria-hidden="true"><circle cx="16" cy="16" r="12"/><path d="M19.5 11.5c-1-1.8-6-2-6 1.2 0 3.7 7 2.1 7 6 0 3.4-5.4 3.2-7 1.2M16 8.5v15"/></svg>',
			'infinity'   => '<svg viewBox="0 0 32 32" aria-hidden="true"><path d="M4 16c4-8 8-8 12 0s8 8 12 0c-4-8-8-8-12 0S8 24 4 16Z"/></svg>',
			'document'   => '<svg viewBox="0 0 32 32" aria-hidden="true"><path d="M8 3h11l6 6v20H8Z"/><path d="M19 3v7h6M12 16h9M12 21h9"/></svg>',
			'payment'    => '<svg viewBox="0 0 32 32" aria-hidden="true"><path d="M8 3h16v26H8Z"/><path d="M12 8h8M18.5 14c-1-1.6-5-1.8-5 .8 0 3 6 1.6 6 4.8 0 2.7-4.5 2.5-6 1M16 12v11"/></svg>',
			'calendar'   => '<svg viewBox="0 0 32 32" aria-hidden="true"><path d="M4 7h24v21H4ZM4 13h24M10 3v8M22 3v8"/></svg>',
			'link'       => '<svg viewBox="0 0 32 32" aria-hidden="true"><path d="m13 20-2 2a6 6 0 0 1-8-8l5-5a6 6 0 0 1 9 1M19 12l2-2a6 6 0 0 1 8 8l-5 5a6 6 0 0 1-9-1M10 22l12-12"/></svg>',
			'customer'   => '<svg viewBox="0 0 32 32" aria-hidden="true"><circle cx="14" cy="10" r="5"/><path d="M4 28v-4a10 10 0 0 1 20 0v4M25 8v8M21 12h8"/></svg>',
			'package'    => '<svg viewBox="0 0 32 32" aria-hidden="true"><path d="m5 9 11-6 11 6v14l-11 6-11-6ZM5 9l11 6 11-6M16 15v14"/></svg>',
			'repeat'     => '<svg viewBox="0 0 32 32" aria-hidden="true"><path d="M27 10A12 12 0 0 0 7 7L4 10M5 22a12 12 0 0 0 20 3l3-3M4 4v6h6M28 28v-6h-6"/></svg>',
		);

		return isset( $icons[ $name ] ) ? $icons[ $name ] : '';
	}

	/**
	 * Replace UM's outer account form on read-only/custom tabs.
	 *
	 * UAP owns the native forms inside its payout and marketing tools. HTML does
	 * not permit nested forms, so the shared UM wrapper becomes a layout-only div
	 * while one of our custom tabs is active. Native UM tabs retain their real
	 * form and normal save behavior.
	 *
	 * @param string $html Rendered Ultimate Member account HTML.
	 * @return string
	 */
	private function replace_um_outer_form_with_shell( $html ) {
		$html = (string) $html;
		$open = preg_replace( '/<form\b[^>]*>/i', '<div class="olr-um-account-form-shell">', $html, 1, $count );
		if ( ! $count || ! is_string( $open ) ) {
			return $html;
		}

		$closing_position = strripos( $open, '</form>' );
		if ( false === $closing_position ) {
			return $html;
		}

		return substr_replace( $open, '</div>', $closing_position, strlen( '</form>' ) );
	}

	/**
	 * Add the Off Label sections to Ultimate Member's native account navigation.
	 *
	 * @param array $tabs Ultimate Member tabs.
	 * @return array
	 */
	public function ultimate_member_tabs( $tabs ) {
		if ( ! is_user_logged_in() ) {
			return $tabs;
		}

		$tabs = is_array( $tabs ) ? $tabs : array();
		$tabs = $this->remove_custom_tabs( $tabs );

		$tabs[5]['overview'] = array(
			'icon'   => 'um-faicon-home',
			'title'  => __( 'Overview', 'off-label-account-hub' ),
			'custom' => true,
		);
		$tabs[10]['orders']   = array(
			'icon'   => 'um-faicon-file-text-o',
			'title'  => __( 'Orders', 'off-label-account-hub' ),
			'custom' => true,
		);

		if ( self::is_active_affiliate( get_current_user_id() ) ) {
			if ( $this->uap_group_enabled( array( 'reports', 'visits' ) ) ) {
				$tabs[20]['performance'] = array(
					'icon'   => 'um-faicon-line-chart',
					'title'  => __( 'Performance', 'off-label-account-hub' ),
					'custom' => true,
				);
			}
			if ( $this->uap_tab_enabled( 'referrals' ) ) {
				$tabs[30]['commissions'] = array(
					'icon'   => 'um-faicon-usd',
					'title'  => __( 'Commissions', 'off-label-account-hub' ),
					'custom' => true,
				);
			}
			if ( $this->uap_tab_enabled( 'payments' ) ) {
				$tabs[40]['payouts'] = array(
					'icon'   => 'um-faicon-credit-card',
					'title'  => __( 'Payouts', 'off-label-account-hub' ),
					'custom' => true,
				);
			}
			if ( $this->creative_uap_tab() ) {
				$tabs[50]['creative'] = array(
					'icon'   => 'um-faicon-picture-o',
					'title'  => __( 'Creative', 'off-label-account-hub' ),
					'custom' => true,
				);
			}
			$tabs[60]['guidelines'] = array(
				'icon'   => 'um-faicon-file-text',
				'title'  => __( 'Guidelines', 'off-label-account-hub' ),
				'custom' => true,
			);
		} else {
			$tabs[20]['affiliate'] = array(
				'icon'   => 'um-faicon-share-alt',
				'title'  => __( 'Affiliate Program', 'off-label-account-hub' ),
				'custom' => true,
			);
		}

		foreach ( $tabs as $group => $group_tabs ) {
			if ( isset( $group_tabs['general'] ) ) {
				$tabs[ $group ]['general']['title'] = __( 'Account', 'off-label-account-hub' );
				$tabs[ $group ]['general']['icon']  = 'um-faicon-user';
			}
		}

		$tabs[999]['olr_logout'] = array(
			'icon'   => 'um-faicon-sign-out',
			'title'  => __( 'Log Out', 'off-label-account-hub' ),
			'custom' => true,
		);

		/*
		 * Ultimate Member adds a save button to every tab unless explicitly
		 * disabled. Affiliate and read-only account sections own their actions,
		 * so those generated buttons are both misleading and visually disruptive.
		 */
		$owned_tabs = $this->custom_tab_slugs();
		foreach ( $tabs as $group => $group_tabs ) {
			if ( ! is_array( $group_tabs ) ) {
				continue;
			}
			foreach ( $owned_tabs as $owned_tab ) {
				if ( isset( $tabs[ $group ][ $owned_tab ] ) ) {
					$tabs[ $group ][ $owned_tab ]['show_button'] = false;
				}
			}
		}

		return $tabs;
	}

	/**
	 * Remove stale copies of our tabs before inserting them in a stable order.
	 *
	 * @param array $tabs Existing tab groups.
	 * @return array
	 */
	private function remove_custom_tabs( $tabs ) {
		$owned = $this->custom_tab_slugs();
		foreach ( $tabs as $group => $group_tabs ) {
			if ( ! is_array( $group_tabs ) ) {
				continue;
			}
			foreach ( $owned as $slug ) {
				unset( $tabs[ $group ][ $slug ] );
			}
			if ( empty( $tabs[ $group ] ) ) {
				unset( $tabs[ $group ] );
			}
		}

		return $tabs;
	}

	/**
	 * Let Ultimate Member request the filtered content for a custom tab.
	 *
	 * @param array $info Ultimate Member tab context.
	 */
	public function ultimate_member_tab_controller( $info ) {
		$tab = str_replace( 'um_account_tab__', '', current_filter() );
		if ( function_exists( 'UM' ) && UM()->account() ) {
			$output = UM()->account()->get_tab_output( $tab );
			if ( $output ) {
				echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- UM filters and our renderers escape at source.
			}
		}
	}

	/**
	 * Supply one custom Ultimate Member tab body.
	 *
	 * @param string $output Existing content.
	 * @return string
	 */
	public function ultimate_member_tab_content( $output ) {
		$tab = str_replace( 'um_account_content_hook_', '', current_filter() );
		$current_tab = '';
		if ( function_exists( 'UM' ) && UM()->account() ) {
			$current_tab = sanitize_key( (string) UM()->account()->current_tab );
		}

		/*
		 * UM renders every tab body into one outer form, even when a tab is hidden.
		 * Native affiliate sections can contain their own forms. Rendering all of
		 * them at once creates invalid nested markup and causes later account tabs
		 * to escape the branded content column. Only the requested custom tab needs
		 * its live UAP data and actions; inactive tabs retain a zero-size placeholder.
		 */
		if ( $current_tab && $tab !== $current_tab ) {
			return (string) $output . '<div class="olr-account-tab-placeholder" aria-hidden="true"></div>';
		}

		return (string) $output . $this->render_tab( $tab );
	}

	/**
	 * Render one account section.
	 *
	 * @param string $tab Account tab slug.
	 * @return string
	 */
	private function render_tab( $tab ) {
		$user_id = get_current_user_id();
		$active  = self::is_active_affiliate( $user_id );

		switch ( $tab ) {
			case 'overview':
				return $active ? $this->render_uap_section( 'overview' ) : $this->render_member_overview();
			case 'orders':
				return $this->render_orders();
			case 'affiliate':
				return $active ? $this->render_uap_section( 'overview' ) : $this->render_application();
			case 'performance':
				return $active ? $this->render_uap_section( 'reports' ) : $this->render_application();
			case 'commissions':
				return $active ? $this->render_uap_section( 'referrals' ) : $this->render_application();
			case 'payouts':
				return $active ? $this->render_uap_section( 'payments' ) : $this->render_application();
			case 'creative':
				$creative = $this->creative_uap_tab();
				return $active && $creative ? $this->render_uap_section( $creative ) : $this->empty_panel( __( 'Creative resources are not configured yet.', 'off-label-account-hub' ) );
			case 'guidelines':
				return $active
					? $this->notice_panel(
						__( 'AFFILIATE GUIDELINES.', 'off-label-account-hub' ),
						__( 'Review the complete disclosure, research-use, promotion, and payout standards.', 'off-label-account-hub' ),
						home_url( '/affiliate-guidelines/' ),
						__( 'VIEW GUIDELINES', 'off-label-account-hub' )
					)
					: $this->render_application();
			case 'olr_logout':
				return $this->notice_panel(
					__( 'LOG OUT', 'off-label-account-hub' ),
					__( 'End this account session on this device.', 'off-label-account-hub' ),
					wp_logout_url( home_url( '/' ) ),
					__( 'LOG OUT', 'off-label-account-hub' )
				);
		}

		return '';
	}

	/**
	 * Render a native UAP section inside the Ultimate Member account shell.
	 *
	 * @param string $default_subtab Default UAP section.
	 * @return string
	 */
	private function render_uap_section( $default_subtab ) {
		if ( ! shortcode_exists( 'uap-account-page' ) || ! self::is_active_affiliate( get_current_user_id() ) ) {
			return $this->empty_panel( __( 'Affiliate reporting is temporarily unavailable.', 'off-label-account-hub' ) );
		}

		$allowed_by_section = array(
			'overview'       => array( 'overview' ),
			'reports'        => array( 'reports', 'visits', 'campaign_reports', 'referrals_history' ),
			'referrals'      => array( 'referrals', 'referrals_history', 'source_details' ),
			'payments'       => array( 'payments', 'payments_settings' ),
			'affiliate_link' => array( 'affiliate_link', 'banners', 'campaigns', 'simple_links', 'landing_pages', 'coupons', 'product_links' ),
			'banners'        => array( 'banners', 'affiliate_link', 'campaigns', 'simple_links', 'landing_pages', 'coupons', 'product_links' ),
			'campaigns'      => array( 'campaigns', 'affiliate_link', 'banners', 'simple_links', 'landing_pages', 'coupons', 'product_links' ),
			'help'           => array( 'help' ),
		);
		$allowed            = isset( $allowed_by_section[ $default_subtab ] ) ? $allowed_by_section[ $default_subtab ] : array( $default_subtab );
		$requested          = isset( $_GET['uap_aff_subtab'] ) ? sanitize_key( wp_unslash( $_GET['uap_aff_subtab'] ) ) : '';
		$selected           = in_array( $requested, $allowed, true ) ? $requested : $default_subtab;
		$had_get            = array_key_exists( 'uap_aff_subtab', $_GET );
		$had_request        = array_key_exists( 'uap_aff_subtab', $_REQUEST );
		$old_get            = $had_get ? $_GET['uap_aff_subtab'] : null;
		$old_request        = $had_request ? $_REQUEST['uap_aff_subtab'] : null;

		$_GET['uap_aff_subtab']     = $selected;
		$_REQUEST['uap_aff_subtab'] = $selected;
		$was_rendering               = self::$rendering_hub;
		self::$rendering_hub         = true;
		$output                      = do_shortcode( '[uap-account-page]' );
		self::$rendering_hub         = $was_rendering;

		if ( $had_get ) {
			$_GET['uap_aff_subtab'] = $old_get;
		} else {
			unset( $_GET['uap_aff_subtab'] );
		}
		if ( $had_request ) {
			$_REQUEST['uap_aff_subtab'] = $old_request;
		} else {
			unset( $_REQUEST['uap_aff_subtab'] );
		}

		return $output ? $this->compliance_safe_html( $output ) : $this->empty_panel( __( 'No affiliate information is available for this section yet.', 'off-label-account-hub' ) );
	}

	/**
	 * Normalize vendor-authored account copy to the site's approved terminology.
	 *
	 * @param string $html Rendered affiliate HTML.
	 * @return string
	 */
	private function compliance_safe_html( $html ) {
		$singular = 'pep' . 'tide';
		$plural   = $singular . 's';

		return str_ireplace(
			array( $plural, $singular ),
			array( 'research compounds', 'research compound' ),
			(string) $html
		);
	}

	/**
	 * Scope UAP presentation overrides to the unified account only.
	 *
	 * @param string $current_path Current UAP template path.
	 * @param string $search_file  Template filename.
	 * @return string
	 */
	public function uap_template_override( $current_path, $search_file ) {
		if ( ! self::$rendering_hub && ! $this->is_account_request() ) {
			return $current_path;
		}

		$allowed = array(
			'account_page-header.php',
			'account_page-overview.php',
			'account_page-footer.php',
		);
		$file    = basename( (string) $search_file );
		if ( ! in_array( $file, $allowed, true ) ) {
			return $current_path;
		}

		$override = plugin_dir_path( __FILE__ ) . 'templates/uap/' . $file;
		if ( is_readable( $override ) ) {
			return $override;
		}

		$flat_overrides = array(
			'account_page-header.php'   => 'olr-uap-account-page-header.php',
			'account_page-overview.php' => 'olr-uap-account-page-overview.php',
			'account_page-footer.php'   => 'olr-uap-account-page-footer.php',
		);
		$flat_override  = plugin_dir_path( __FILE__ ) . $flat_overrides[ $file ];

		return is_readable( $flat_override ) ? $flat_override : $current_path;
	}

	/**
	 * Return true when a user has an approved UAP affiliate record.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return bool
	 */
	public static function is_active_affiliate( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return false;
		}

		$user = get_userdata( $user_id );
		if ( $user instanceof WP_User && in_array( 'pending_user', (array) $user->roles, true ) ) {
			return false;
		}

		global $indeed_db;
		if ( ! is_object( $indeed_db ) ) {
			return false;
		}
		if ( method_exists( $indeed_db, 'is_user_affiliate_by_uid' ) ) {
			return (bool) $indeed_db->is_user_affiliate_by_uid( $user_id );
		}
		if ( method_exists( $indeed_db, 'affiliate_get_id_by_uid' ) ) {
			return absint( $indeed_db->affiliate_get_id_by_uid( $user_id ) ) > 0;
		}

		return false;
	}

	/**
	 * Return the current user's UAP affiliate ID.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return int
	 */
	public static function affiliate_id( $user_id ) {
		global $indeed_db;
		return is_object( $indeed_db ) && method_exists( $indeed_db, 'affiliate_get_id_by_uid' )
			? absint( $indeed_db->affiliate_get_id_by_uid( absint( $user_id ) ) )
			: 0;
	}

	/**
	 * Build the native UAP referral URL for an affiliate.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string
	 */
	public static function affiliate_referral_url( $user_id ) {
		$affiliate_id = self::affiliate_id( $user_id );
		if ( ! $affiliate_id || ! function_exists( 'uap_create_affiliate_link' ) ) {
			return '';
		}

		global $indeed_db;
		$settings = is_object( $indeed_db ) && method_exists( $indeed_db, 'return_settings_from_wp_option' )
			? (array) $indeed_db->return_settings_from_wp_option( 'general-settings' )
			: array();
		$param    = ! empty( $settings['uap_referral_variable'] ) ? sanitize_key( $settings['uap_referral_variable'] ) : 'ref';
		$value    = $affiliate_id;
		if ( ! empty( $settings['uap_default_ref_format'] ) && 'username' === $settings['uap_default_ref_format'] ) {
			$user  = get_userdata( absint( $user_id ) );
			$value = $user instanceof WP_User ? rawurlencode( $user->user_login ) : $affiliate_id;
		}

		return uap_create_affiliate_link( home_url( '/' ), $param, $value );
	}

	/**
	 * Return the affiliate's native UAP rank.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array
	 */
	public static function affiliate_rank( $user_id ) {
		global $indeed_db;
		$affiliate_id = self::affiliate_id( $user_id );
		if ( ! $affiliate_id || ! is_object( $indeed_db ) || ! method_exists( $indeed_db, 'get_affiliate_rank' ) || ! method_exists( $indeed_db, 'get_rank' ) ) {
			return array();
		}

		$rank_id = absint( $indeed_db->get_affiliate_rank( $affiliate_id ) );
		$rank    = $rank_id ? $indeed_db->get_rank( $rank_id ) : array();
		return is_array( $rank ) ? $rank : array();
	}

	/**
	 * Read a small, optional recent-referral list through UAP's database adapter.
	 *
	 * @param int $user_id WordPress user ID.
	 * @param int $limit   Maximum rows.
	 * @return array
	 */
	public static function recent_referrals( $user_id, $limit = 5 ) {
		global $indeed_db;
		$affiliate_id = self::affiliate_id( $user_id );
		if ( ! $affiliate_id || ! is_object( $indeed_db ) || ! method_exists( $indeed_db, 'get_referrals' ) ) {
			return array();
		}

		$items = $indeed_db->get_referrals(
			max( 1, min( 250, absint( $limit ) ) ),
			0,
			false,
			'date',
			'DESC',
			array( 'r.affiliate_id=' . $affiliate_id )
		);

		return is_array( $items ) ? $items : array();
	}

	/**
	 * Build the dashboard view model from native UAP summaries and owned referral
	 * records. WooCommerce order metrics are exposed only when an actual order can
	 * be resolved through WooCommerce CRUD, keeping the implementation HPOS-safe.
	 *
	 * @param int   $user_id Current affiliate user ID.
	 * @param array $native  Native UAP overview payload.
	 * @return array
	 */
	public static function affiliate_dashboard_data( $user_id, $native = array() ) {
		$user_id     = absint( $user_id );
		$native      = is_array( $native ) ? $native : array();
		$stats       = isset( $native['stats'] ) && is_array( $native['stats'] ) ? $native['stats'] : array();
		$month_stats = isset( $native['referralsExtraStats'] ) && is_array( $native['referralsExtraStats'] ) ? $native['referralsExtraStats'] : array();
		$report      = isset( $native['referralsStats'] ) && is_array( $native['referralsStats'] ) ? $native['referralsStats'] : array();
		$currency    = isset( $stats['currency'] ) ? sanitize_text_field( (string) $stats['currency'] ) : ( function_exists( 'uapCurrency' ) ? uapCurrency() : 'USD' );
		$paid        = isset( $stats['paid_payments_value'] ) ? (float) $stats['paid_payments_value'] : 0.0;
		$available   = isset( $stats['unpaid_payments_value'] ) ? (float) $stats['unpaid_payments_value'] : 0.0;
		$rank        = self::affiliate_rank( $user_id );
		$rate        = '';
		if ( isset( $rank['amount_value'] ) ) {
			$rate = isset( $rank['amount_type'] ) && 'flat' === $rank['amount_type']
				? number_format_i18n( (float) $rank['amount_value'], 2 ) . ' ' . $currency
				: number_format_i18n( (float) $rank['amount_value'], 0 ) . '%';
		}

		$items             = self::recent_referrals( $user_id, 250 );
		$pending           = 0.0;
		$sales             = 0.0;
		$repeat_sales      = 0.0;
		$repeat_commission = 0.0;
		$order_count       = 0;
		$customers         = array();
		$resolved_orders   = array();

		foreach ( $items as $item ) {
			$status = isset( $item['status'] ) ? absint( $item['status'] ) : 0;
			$amount = isset( $item['amount'] ) ? (float) $item['amount'] : 0.0;
			if ( 1 === $status ) {
				$pending += $amount;
			}

			$order_id = 0;
			foreach ( array( 'reference', 'reference_id', 'order_id', 'source' ) as $reference_key ) {
				if ( empty( $item[ $reference_key ] ) || ! is_scalar( $item[ $reference_key ] ) ) {
					continue;
				}
				if ( preg_match( '/\d+/', (string) $item[ $reference_key ], $match ) ) {
					$order_id = absint( $match[0] );
					break;
				}
			}
			if ( ! $order_id || isset( $resolved_orders[ $order_id ] ) || ! function_exists( 'wc_get_order' ) ) {
				continue;
			}

			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order || in_array( $order->get_status(), array( 'cancelled', 'failed', 'trash' ), true ) ) {
				continue;
			}

			$resolved_orders[ $order_id ] = true;
			$net = max(
				0,
				(float) $order->get_total()
				- (float) $order->get_shipping_total()
				- (float) $order->get_total_tax()
				- (float) $order->get_total_refunded()
			);
			$customer_id  = absint( $order->get_customer_id() );
			$billing_email = strtolower( trim( (string) $order->get_billing_email() ) );
			$customer_key = $customer_id ? 'user:' . $customer_id : ( $billing_email ? 'email:' . hash( 'sha256', $billing_email ) : 'order:' . $order_id );

			if ( ! isset( $customers[ $customer_key ] ) ) {
				$customers[ $customer_key ] = array( 'orders' => 0, 'sales' => 0.0, 'commission' => 0.0 );
			}
			++$customers[ $customer_key ]['orders'];
			$customers[ $customer_key ]['sales']      += $net;
			$customers[ $customer_key ]['commission'] += $amount;
			$sales += $net;
			++$order_count;
		}

		$returning = 0;
		foreach ( $customers as $customer ) {
			if ( $customer['orders'] > 1 ) {
				++$returning;
				$repeat_sales      += (float) $customer['sales'];
				$repeat_commission += (float) $customer['commission'];
			}
		}

		$customer_count = count( $customers );
		$policy         = self::affiliate_program_policy();

		return array(
			'currency'              => $currency,
			'paid'                  => $paid,
			'available'             => $available,
			'pending'               => $pending,
			'earnings'              => $paid + $available + $pending,
			'referrals'             => isset( $stats['referrals'] ) ? absint( $stats['referrals'] ) : count( $items ),
			'payments'              => isset( $stats['payments'] ) ? absint( $stats['payments'] ) : 0,
			'clicks'                => isset( $month_stats['visits'] ) ? absint( $month_stats['visits'] ) : 0,
			'conversion'            => isset( $report['success_rate'] ) ? (float) $report['success_rate'] : 0.0,
			'rank'                  => $rank,
			'rate'                  => $rate ? $rate : $policy['commission'],
			'referral_url'          => self::affiliate_referral_url( $user_id ),
			'coupon_code'           => self::affiliate_coupon_code( $user_id ),
			'recent'                => array_slice( $items, 0, 5 ),
			'order_metrics_available' => $order_count > 0,
			'sales_generated'       => $sales,
			'order_count'           => $order_count,
			'customer_count'        => $customer_count,
			'returning_customers'   => $returning,
			'first_order_only'      => max( 0, $customer_count - $returning ),
			'repeat_rate'           => $customer_count ? ( $returning / $customer_count ) * 100 : 0,
			'repeat_sales'          => $repeat_sales,
			'repeat_commission'     => $repeat_commission,
			'average_order_value'   => $order_count ? $sales / $order_count : 0,
			'policy'                => $policy,
			'chart'                 => ! empty( $native['statsForLast30'] ) && is_array( $native['statsForLast30'] ) ? $native['statsForLast30'] : array(),
		);
	}

	/**
	 * Resolve a configured affiliate coupon without assuming one vendor method.
	 * Sites can supply an authoritative value through the public filter.
	 *
	 * @param int $user_id Affiliate user ID.
	 * @return string
	 */
	public static function affiliate_coupon_code( $user_id ) {
		$user_id = absint( $user_id );
		$code    = sanitize_text_field( (string) get_user_meta( $user_id, 'olr_affiliate_coupon_code', true ) );
		$code    = (string) apply_filters( 'olr_affiliate_dashboard_coupon_code', $code, $user_id, self::affiliate_id( $user_id ) );

		return strtoupper( trim( sanitize_text_field( $code ) ) );
	}

	/**
	 * Render a non-affiliate account overview.
	 *
	 * @return string
	 */
	private function render_member_overview() {
		$user       = wp_get_current_user();
		$first_name = $user->first_name ? $user->first_name : $user->display_name;
		$status     = $this->application_status( $user->ID );
		$order_count = function_exists( 'wc_get_customer_order_count' ) ? absint( wc_get_customer_order_count( $user->ID ) ) : 0;

		ob_start();
		?>
		<section class="olr-account-panel olr-account-overview" aria-labelledby="olr-account-overview-title">
			<div class="olr-account-panel__header">
				<div>
					<p class="olr-account-eyebrow"><?php esc_html_e( 'Off Label account', 'off-label-account-hub' ); ?></p>
					<h2 id="olr-account-overview-title"><?php echo esc_html( sprintf( __( 'WELCOME BACK, %s.', 'off-label-account-hub' ), strtoupper( $first_name ) ) ); ?></h2>
				</div>
				<span class="olr-account-status"><span aria-hidden="true"></span><?php esc_html_e( 'Active member', 'off-label-account-hub' ); ?></span>
			</div>
			<div class="olr-account-stat-grid olr-account-stat-grid--two">
				<a class="olr-account-stat" href="<?php echo esc_url( $this->account_tab_url( 'orders' ) ); ?>">
					<strong><?php echo esc_html( number_format_i18n( $order_count ) ); ?></strong>
					<span><?php esc_html_e( 'Orders', 'off-label-account-hub' ); ?></span>
				</a>
				<a class="olr-account-stat" href="<?php echo esc_url( $this->account_tab_url( 'affiliate' ) ); ?>">
					<strong><?php echo esc_html( $status ? strtoupper( $status ) : __( 'APPLY', 'off-label-account-hub' ) ); ?></strong>
					<span><?php esc_html_e( 'Affiliate program', 'off-label-account-hub' ); ?></span>
				</a>
			</div>
			<div class="olr-account-callout">
				<div>
					<p class="olr-account-eyebrow"><?php esc_html_e( 'Affiliate access', 'off-label-account-hub' ); ?></p>
					<h3><?php esc_html_e( 'SHARE THE RESEARCH.', 'off-label-account-hub' ); ?></h3>
					<p><?php esc_html_e( 'Apply from this account to receive a personal referral link and track eligible activity after approval.', 'off-label-account-hub' ); ?></p>
				</div>
				<a class="olr-account-button" href="<?php echo esc_url( $this->account_tab_url( 'affiliate' ) ); ?>"><?php esc_html_e( 'VIEW AFFILIATE PROGRAM', 'off-label-account-hub' ); ?><span aria-hidden="true">&rarr;</span></a>
			</div>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render WooCommerce order history or one owned order.
	 *
	 * @return string
	 */
	private function render_orders() {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return $this->empty_panel( __( 'Order history is temporarily unavailable.', 'off-label-account-hub' ) );
		}

		$user_id  = get_current_user_id();
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		if ( $order_id ) {
			return $this->render_order_details( $order_id, $user_id );
		}

		$page    = isset( $_GET['order_page'] ) ? max( 1, absint( $_GET['order_page'] ) ) : 1;
		$results = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'limit'       => 10,
				'page'        => $page,
				'paginate'    => true,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'return'      => 'objects',
			)
		);
		$orders  = isset( $results->orders ) && is_array( $results->orders ) ? $results->orders : array();
		$pages   = isset( $results->max_num_pages ) ? absint( $results->max_num_pages ) : 1;

		ob_start();
		?>
		<section class="olr-account-panel" aria-labelledby="olr-orders-title">
			<div class="olr-account-panel__header">
				<div><p class="olr-account-eyebrow"><?php esc_html_e( 'Purchase history', 'off-label-account-hub' ); ?></p><h2 id="olr-orders-title"><?php esc_html_e( 'YOUR ORDERS.', 'off-label-account-hub' ); ?></h2></div>
			</div>
			<?php if ( $orders ) : ?>
				<div class="olr-account-table-wrap">
					<table class="olr-account-table">
						<thead><tr><th><?php esc_html_e( 'Order', 'off-label-account-hub' ); ?></th><th><?php esc_html_e( 'Date', 'off-label-account-hub' ); ?></th><th><?php esc_html_e( 'Status', 'off-label-account-hub' ); ?></th><th><?php esc_html_e( 'Total', 'off-label-account-hub' ); ?></th><th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'off-label-account-hub' ); ?></span></th></tr></thead>
						<tbody>
						<?php foreach ( $orders as $order ) :
							$date    = $order->get_date_created();
							$view_url = add_query_arg( array( 'um_tab' => 'orders', 'order_id' => $order->get_id() ), $this->account_url() );
							?>
							<tr>
								<td data-label="<?php esc_attr_e( 'Order', 'off-label-account-hub' ); ?>"><a href="<?php echo esc_url( $view_url ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a></td>
				<td data-label="<?php esc_attr_e( 'Date', 'off-label-account-hub' ); ?>"><?php echo esc_html( $date ? $date->date_i18n( get_option( 'date_format' ) ) : '—' ); ?></td>
								<td data-label="<?php esc_attr_e( 'Status', 'off-label-account-hub' ); ?>"><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></td>
								<td data-label="<?php esc_attr_e( 'Total', 'off-label-account-hub' ); ?>"><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></td>
								<td><div class="olr-order-actions"><?php echo wp_kses_post( $this->render_order_actions( $order, $view_url ) ); ?></div></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php if ( $pages > 1 ) : ?>
					<nav class="olr-account-pagination" aria-label="<?php esc_attr_e( 'Order history pages', 'off-label-account-hub' ); ?>">
						<?php if ( $page > 1 ) : ?><a href="<?php echo esc_url( add_query_arg( array( 'um_tab' => 'orders', 'order_page' => $page - 1 ), $this->account_url() ) ); ?>">&larr; <?php esc_html_e( 'Previous', 'off-label-account-hub' ); ?></a><?php endif; ?>
						<span><?php echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'off-label-account-hub' ), $page, $pages ) ); ?></span>
						<?php if ( $page < $pages ) : ?><a href="<?php echo esc_url( add_query_arg( array( 'um_tab' => 'orders', 'order_page' => $page + 1 ), $this->account_url() ) ); ?>"><?php esc_html_e( 'Next', 'off-label-account-hub' ); ?> &rarr;</a><?php endif; ?>
					</nav>
				<?php endif; ?>
			<?php else : ?>
				<div class="olr-account-empty"><p class="olr-account-eyebrow"><?php esc_html_e( 'No orders yet', 'off-label-account-hub' ); ?></p><h3><?php esc_html_e( 'YOUR ORDER HISTORY WILL APPEAR HERE.', 'off-label-account-hub' ); ?></h3><a class="olr-account-button" href="<?php echo esc_url( home_url( '/shop/' ) ); ?>"><?php esc_html_e( 'EXPLORE RESEARCH', 'off-label-account-hub' ); ?><span aria-hidden="true">&rarr;</span></a></div>
			<?php endif; ?>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render one order after a strict ownership check.
	 *
	 * @param int $order_id Order ID.
	 * @param int $user_id  Current user ID.
	 * @return string
	 */
	private function render_order_details( $order_id, $user_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( ! $order || absint( $order->get_user_id() ) !== absint( $user_id ) ) {
			return $this->notice_panel(
				__( 'ORDER NOT AVAILABLE', 'off-label-account-hub' ),
				__( 'That order could not be found in this account.', 'off-label-account-hub' ),
				$this->account_tab_url( 'orders' ),
				__( 'BACK TO ORDERS', 'off-label-account-hub' )
			);
		}

		ob_start();
		?>
		<section class="olr-account-panel olr-account-order-detail" aria-labelledby="olr-order-detail-title">
			<a class="olr-account-back" href="<?php echo esc_url( $this->account_tab_url( 'orders' ) ); ?>">&larr; <?php esc_html_e( 'Back to orders', 'off-label-account-hub' ); ?></a>
			<div class="olr-account-panel__header"><div><p class="olr-account-eyebrow"><?php echo esc_html( sprintf( __( 'Order #%s', 'off-label-account-hub' ), $order->get_order_number() ) ); ?></p><h2 id="olr-order-detail-title"><?php esc_html_e( 'ORDER DETAILS.', 'off-label-account-hub' ); ?></h2></div><span class="olr-account-status"><span aria-hidden="true"></span><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></span></div>
			<div class="olr-account-native-order">
			<?php
			if ( function_exists( 'woocommerce_order_details_table' ) ) {
				woocommerce_order_details_table( $order_id );
			}
			?>
			</div>
			<div class="olr-order-actions olr-order-actions--detail"><?php echo wp_kses_post( $this->render_order_actions( $order, add_query_arg( array( 'um_tab' => 'orders', 'order_id' => $order_id ), $this->account_url() ) ) ); ?></div>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render WooCommerce's current actions while keeping View inside the hub.
	 *
	 * @param WC_Order $order    Order object.
	 * @param string   $view_url Hub-owned detail URL.
	 * @return string
	 */
	private function render_order_actions( $order, $view_url ) {
		if ( ! function_exists( 'wc_get_account_orders_actions' ) || ! $order instanceof WC_Order ) {
			return '';
		}

		$actions = wc_get_account_orders_actions( $order );
		if ( isset( $actions['view'] ) ) {
			$actions['view']['url'] = $view_url;
		}
		$output = '';
		foreach ( $actions as $key => $action ) {
			if ( empty( $action['url'] ) || empty( $action['name'] ) ) {
				continue;
			}
			$output .= '<a class="olr-account-text-link olr-order-action--' . esc_attr( sanitize_html_class( $key ) ) . '" href="' . esc_url( $action['url'] ) . '">' . esc_html( $action['name'] ) . ' <span aria-hidden="true">&rarr;</span></a>';
		}

		return $output;
	}

	/**
	 * Render application, pending, or rejected affiliate state.
	 *
	 * @return string
	 */
	private function render_application() {
		$user       = wp_get_current_user();
		$status     = $this->application_status( $user->ID );
		$terms_url  = trim( (string) get_option( self::OPTION_TERMS_URL, '' ) );
		$notice     = isset( $_GET['olr_notice'] ) ? sanitize_key( wp_unslash( $_GET['olr_notice'] ) ) : '';
		$ineligible = self::is_administrator_account( $user->ID );

		if ( in_array( 'pending_user', (array) $user->roles, true ) ) {
			$status = 'pending';
		}

		ob_start();
		?>
		<section class="olr-account-panel olr-affiliate-application" aria-labelledby="olr-affiliate-application-title">
			<div class="olr-account-panel__header">
				<div><p class="olr-account-eyebrow"><?php esc_html_e( 'Off Label affiliate', 'off-label-account-hub' ); ?></p><h2 id="olr-affiliate-application-title"><?php esc_html_e( 'AFFILIATE PROGRAM.', 'off-label-account-hub' ); ?></h2></div>
				<?php if ( $status ) : ?><span class="olr-account-status olr-account-status--<?php echo esc_attr( $status ); ?>"><span aria-hidden="true"></span><?php echo esc_html( ucfirst( $status ) ); ?></span><?php endif; ?>
			</div>

			<?php if ( 'application_submitted' === $notice ) : ?><div class="olr-account-notice" role="status"><?php esc_html_e( 'Your application was submitted for review.', 'off-label-account-hub' ); ?></div><?php endif; ?>
			<?php if ( 'application_invalid' === $notice ) : ?><div class="olr-account-notice olr-account-notice--error" role="alert"><?php esc_html_e( 'Enter a valid website or social URL, describe your plan, and accept the affiliate terms.', 'off-label-account-hub' ); ?></div><?php endif; ?>
			<?php if ( 'application_session_expired' === $notice ) : ?><div class="olr-account-notice olr-account-notice--error" role="alert"><?php esc_html_e( 'Your form session expired. Refresh the page and submit the application again.', 'off-label-account-hub' ); ?></div><?php endif; ?>
			<?php if ( 'application_ineligible' === $notice ) : ?><div class="olr-account-notice olr-account-notice--error" role="alert"><?php esc_html_e( 'Administrator accounts cannot enroll in the affiliate program. Use a standard member account.', 'off-label-account-hub' ); ?></div><?php endif; ?>

			<?php if ( 'pending' === $status ) : ?>
				<div class="olr-account-empty"><p class="olr-account-eyebrow"><?php esc_html_e( 'Application received', 'off-label-account-hub' ); ?></p><h3><?php esc_html_e( 'YOUR APPLICATION IS UNDER REVIEW.', 'off-label-account-hub' ); ?></h3><p><?php esc_html_e( 'You will receive an update after the affiliate team completes its review.', 'off-label-account-hub' ); ?></p></div>
			<?php elseif ( 'rejected' === $status ) : ?>
				<div class="olr-account-empty"><p class="olr-account-eyebrow"><?php esc_html_e( 'Application status', 'off-label-account-hub' ); ?></p><h3><?php esc_html_e( 'APPLICATION NOT APPROVED.', 'off-label-account-hub' ); ?></h3><p><?php esc_html_e( 'Contact affiliate support if your information has changed and you would like the application reset.', 'off-label-account-hub' ); ?></p></div>
			<?php elseif ( 'approved' === $status ) : ?>
				<div class="olr-account-empty"><p class="olr-account-eyebrow"><?php esc_html_e( 'Application approved', 'off-label-account-hub' ); ?></p><h3><?php esc_html_e( 'AFFILIATE ACCESS NEEDS REVIEW.', 'off-label-account-hub' ); ?></h3><p><?php esc_html_e( 'Your application is approved, but the affiliate record is not currently active. Contact affiliate support to restore access.', 'off-label-account-hub' ); ?></p></div>
			<?php elseif ( $ineligible ) : ?>
				<div class="olr-account-empty"><p class="olr-account-eyebrow"><?php esc_html_e( 'Account type', 'off-label-account-hub' ); ?></p><h3><?php esc_html_e( 'USE A MEMBER ACCOUNT TO APPLY.', 'off-label-account-hub' ); ?></h3><p><?php esc_html_e( 'Administrator accounts manage applications but cannot become affiliates. Sign in with a standard member account to test or submit an application.', 'off-label-account-hub' ); ?></p></div>
			<?php elseif ( ! $terms_url ) : ?>
				<div class="olr-account-notice olr-account-notice--error" role="alert"><?php esc_html_e( 'Applications will open after the affiliate terms are published.', 'off-label-account-hub' ); ?></div>
			<?php else : ?>
				<div class="olr-affiliate-application__intro"><h3><?php esc_html_e( 'APPLY TO WORK WITH OFF LABEL.', 'off-label-account-hub' ); ?></h3><p><?php esc_html_e( 'Tell us where you plan to share Off Label Research. Applications are reviewed before affiliate tools become available.', 'off-label-account-hub' ); ?></p></div>
				<form class="olr-account-form" method="post" action="<?php echo esc_url( $this->account_tab_url( 'affiliate' ) ); ?>" novalidate>
					<input type="hidden" name="olr_account_action" value="submit_affiliate_application">
					<?php wp_nonce_field( 'olr_submit_affiliate_application', 'olr_affiliate_application_nonce' ); ?>
					<div class="olr-account-form__grid">
						<p><label><?php esc_html_e( 'Name', 'off-label-account-hub' ); ?><input type="text" value="<?php echo esc_attr( $user->display_name ); ?>" readonly></label></p>
						<p><label><?php esc_html_e( 'Email', 'off-label-account-hub' ); ?><input type="email" value="<?php echo esc_attr( $user->user_email ); ?>" readonly></label></p>
					</div>
					<p><label for="olr-affiliate-url"><?php esc_html_e( 'Website or social URL', 'off-label-account-hub' ); ?> <span aria-hidden="true">*</span></label><input id="olr-affiliate-url" type="text" inputmode="url" autocomplete="url" name="olr_affiliate_url" value="<?php echo esc_attr( get_user_meta( $user->ID, self::META_URL, true ) ); ?>" aria-required="true"></p>
					<p><label for="olr-affiliate-plan"><?php esc_html_e( 'How do you plan to introduce Off Label Research to your audience?', 'off-label-account-hub' ); ?> <span aria-hidden="true">*</span></label><textarea id="olr-affiliate-plan" name="olr_affiliate_plan" maxlength="1200" rows="6" aria-required="true"><?php echo esc_textarea( get_user_meta( $user->ID, self::META_PLAN, true ) ); ?></textarea></p>
					<label class="olr-account-checkbox"><input type="checkbox" name="olr_affiliate_terms" value="1" aria-required="true"><span><?php echo wp_kses_post( sprintf( __( 'I have read and agree to the <a href="%s" target="_blank" rel="noopener">affiliate terms</a>.', 'off-label-account-hub' ), esc_url( $terms_url ) ) ); ?></span></label>
					<button class="olr-account-button" type="submit"><?php esc_html_e( 'SUBMIT APPLICATION', 'off-label-account-hub' ); ?><span aria-hidden="true">&rarr;</span></button>
				</form>
			<?php endif; ?>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * Save a member's affiliate application without changing their role.
	 */
	public function submit_application() {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( $this->ultimate_member_login_url() );
			exit;
		}

		$user_id = get_current_user_id();
		$nonce   = isset( $_POST['olr_affiliate_application_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['olr_affiliate_application_nonce'] ) ) : '';
		if ( self::is_administrator_account( $user_id ) ) {
			$this->redirect_to_application( 'application_ineligible' );
		}
		if ( ! wp_verify_nonce( $nonce, 'olr_submit_affiliate_application' ) ) {
			$this->redirect_to_application( 'application_session_expired' );
		}
		if ( self::is_active_affiliate( $user_id ) || $this->application_status( $user_id ) ) {
			$this->redirect_to_application( 'application_invalid' );
		}

		$terms_url = trim( (string) get_option( self::OPTION_TERMS_URL, '' ) );
		$raw_url   = isset( $_POST['olr_affiliate_url'] ) && is_scalar( $_POST['olr_affiliate_url'] ) ? trim( sanitize_text_field( wp_unslash( (string) $_POST['olr_affiliate_url'] ) ) ) : '';
		if ( $raw_url && ! preg_match( '#^https?://#i', $raw_url ) ) {
			$raw_url = 'https://' . ltrim( $raw_url, '/' );
		}
		$url       = esc_url_raw( $raw_url, array( 'http', 'https' ) );
		$plan      = isset( $_POST['olr_affiliate_plan'] ) && is_scalar( $_POST['olr_affiliate_plan'] ) ? trim( sanitize_textarea_field( wp_unslash( (string) $_POST['olr_affiliate_plan'] ) ) ) : '';
		$accepted  = ! empty( $_POST['olr_affiliate_terms'] );
		$length    = function_exists( 'mb_strlen' ) ? mb_strlen( $plan ) : strlen( $plan );

		if ( ! $terms_url || ! $accepted || ! $url || ! wp_http_validate_url( $url ) || $length < 1 || $length > 1200 ) {
			$this->redirect_to_application( 'application_invalid' );
		}

		$now = current_time( 'mysql' );
		update_user_meta( $user_id, self::META_STATUS, 'pending' );
		update_user_meta( $user_id, self::META_URL, $url );
		update_user_meta( $user_id, self::META_PLAN, $plan );
		update_user_meta( $user_id, self::META_SUBMITTED, $now );
		update_user_meta( $user_id, self::META_TERMS_URL, $terms_url );
		update_user_meta( $user_id, self::META_TERMS_ACCEPTED, $now );
		delete_user_meta( $user_id, self::META_REJECTED );
		do_action( 'olr_affiliate_application_submitted', $user_id );
		$this->send_application_email( $user_id, 'submitted' );
		$this->send_application_admin_email( $user_id );

		$this->redirect_to_application( 'application_submitted' );
	}

	/**
	 * Process the application form on the public account route.
	 *
	 * Ultimate Member can block non-administrators from wp-admin, including
	 * admin-post.php. Keeping this POST on /account/ avoids that redirect while
	 * retaining the same nonce, validation, and post-submit redirect.
	 */
	public function maybe_submit_frontend_application() {
		if ( 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : '' ) ) {
			return;
		}

		$action = isset( $_POST['olr_account_action'] ) && is_scalar( $_POST['olr_account_action'] )
			? sanitize_key( wp_unslash( (string) $_POST['olr_account_action'] ) )
			: '';
		if ( 'submit_affiliate_application' !== $action ) {
			return;
		}

		$this->submit_application();
	}

	/**
	 * Redirect to the affiliate application tab with a safe notice key.
	 *
	 * @param string $notice Notice key.
	 */
	private function redirect_to_application( $notice ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'um_tab'     => 'affiliate',
					'olr_notice' => sanitize_key( $notice ),
				),
				$this->account_url()
			)
		);
		exit;
	}

	/**
	 * Add the application screen beneath UAP when available.
	 */
	public function admin_menu() {
		$parent = defined( 'UAP_PATH' ) ? 'ultimate_affiliates_pro' : 'users.php';
		add_submenu_page(
			$parent,
			__( 'Affiliate Applications', 'off-label-account-hub' ),
			__( 'Affiliate Applications', 'off-label-account-hub' ),
			'manage_options',
			'olr-affiliate-applications',
			array( $this, 'admin_page' )
		);
	}

	/**
	 * Register the required terms URL setting.
	 */
	public function register_settings() {
		register_setting(
			'olr_account_hub',
			self::OPTION_TERMS_URL,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
				'default'           => '',
			)
		);
		register_setting(
			'olr_account_hub',
			self::OPTION_NOTIFICATION_EMAIL,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_email',
				'default'           => '',
			)
		);
	}

	/**
	 * Render the administrator application queue and terms setting.
	 */
	public function admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$error_code = isset( $_GET['olr_error'] ) && is_scalar( $_GET['olr_error'] )
			? sanitize_key( wp_unslash( (string) $_GET['olr_error'] ) )
			: '';
		$applicants = get_users(
			array(
				'meta_key' => self::META_STATUS,
				'orderby'  => 'registered',
				'order'    => 'DESC',
				'number'   => -1,
			)
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Affiliate Applications', 'off-label-account-hub' ); ?></h1>
			<?php if ( isset( $_GET['olr_updated'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Application updated.', 'off-label-account-hub' ); ?></p></div><?php endif; ?>
			<?php if ( $error_code ) : ?><div class="notice notice-error"><p><?php echo esc_html( $this->application_error_message( $error_code ) ); ?></p></div><?php endif; ?>

			<form method="post" action="options.php" style="max-width:900px;margin:24px 0 32px;padding:20px;background:#fff;border:1px solid #c3c4c7;">
				<?php settings_fields( 'olr_account_hub' ); ?>
				<h2 style="margin-top:0"><?php esc_html_e( 'Application settings', 'off-label-account-hub' ); ?></h2>
				<p><label for="olr-affiliate-terms-url"><strong><?php esc_html_e( 'Affiliate terms URL', 'off-label-account-hub' ); ?></strong></label></p>
				<input class="regular-text" id="olr-affiliate-terms-url" type="url" name="<?php echo esc_attr( self::OPTION_TERMS_URL ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_TERMS_URL, '' ) ); ?>" placeholder="https://offlabelresearch.com/affiliate-terms/">
				<p class="description"><?php esc_html_e( 'Applications remain closed until this points to published terms.', 'off-label-account-hub' ); ?></p>
				<p><label for="olr-affiliate-notification-email"><strong><?php esc_html_e( 'Application notification email', 'off-label-account-hub' ); ?></strong></label></p>
				<input class="regular-text" id="olr-affiliate-notification-email" type="email" name="<?php echo esc_attr( self::OPTION_NOTIFICATION_EMAIL ); ?>" value="<?php echo esc_attr( $this->application_notification_email() ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email', '' ) ); ?>">
				<p class="description"><?php esc_html_e( 'New application alerts are sent here. The WordPress administration email is used when this field is empty.', 'off-label-account-hub' ); ?></p>
				<?php submit_button( __( 'Save application settings', 'off-label-account-hub' ) ); ?>
			</form>

			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Applicant', 'off-label-account-hub' ); ?></th><th><?php esc_html_e( 'Status', 'off-label-account-hub' ); ?></th><th><?php esc_html_e( 'Website or social URL', 'off-label-account-hub' ); ?></th><th><?php esc_html_e( 'Promotion plan', 'off-label-account-hub' ); ?></th><th><?php esc_html_e( 'Submitted', 'off-label-account-hub' ); ?></th><th><?php esc_html_e( 'Actions', 'off-label-account-hub' ); ?></th></tr></thead>
				<tbody>
				<?php if ( ! $applicants ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'No applications have been submitted.', 'off-label-account-hub' ); ?></td></tr>
				<?php else : foreach ( $applicants as $applicant ) :
					$status = $this->application_status( $applicant->ID );
					?>
					<tr>
						<td><strong><?php echo esc_html( $applicant->display_name ); ?></strong><br><a href="mailto:<?php echo esc_attr( $applicant->user_email ); ?>"><?php echo esc_html( $applicant->user_email ); ?></a></td>
						<td><?php echo esc_html( ucfirst( $status ) ); ?></td>
						<td><a href="<?php echo esc_url( get_user_meta( $applicant->ID, self::META_URL, true ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( get_user_meta( $applicant->ID, self::META_URL, true ) ); ?></a></td>
						<td style="max-width:420px;white-space:pre-wrap"><?php echo esc_html( get_user_meta( $applicant->ID, self::META_PLAN, true ) ); ?></td>
						<td><?php echo esc_html( get_user_meta( $applicant->ID, self::META_SUBMITTED, true ) ); ?></td>
						<td><?php echo wp_kses_post( $this->admin_application_actions( $applicant->ID, $status ) ); ?></td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>

			<?php
			$legacy_pending = get_users(
				array(
					'role'   => 'pending_user',
					'number' => -1,
				)
			);
			if ( $legacy_pending ) :
				?>
				<h2 style="margin-top:32px"><?php esc_html_e( 'Legacy pending-role audit', 'off-label-account-hub' ); ?></h2>
				<p><?php esc_html_e( 'These users already have UAP’s legacy pending role. This plugin will not change that role automatically; review each user and restore the correct Ultimate Member role during staging.', 'off-label-account-hub' ); ?></p>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'User', 'off-label-account-hub' ); ?></th><th><?php esc_html_e( 'Email', 'off-label-account-hub' ); ?></th><th><?php esc_html_e( 'UAP affiliate ID', 'off-label-account-hub' ); ?></th><th><?php esc_html_e( 'Application status', 'off-label-account-hub' ); ?></th></tr></thead>
					<tbody><?php foreach ( $legacy_pending as $pending_user ) : ?><tr><td><?php echo esc_html( $pending_user->display_name ); ?></td><td><?php echo esc_html( $pending_user->user_email ); ?></td><td><?php echo esc_html( self::affiliate_id( $pending_user->ID ) ?: '—' ); ?></td><td><?php echo esc_html( $this->application_status( $pending_user->ID ) ?: __( 'Legacy pending', 'off-label-account-hub' ) ); ?></td></tr><?php endforeach; ?></tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Build nonce-protected admin decision links.
	 *
	 * @param int    $user_id User ID.
	 * @param string $status  Application status.
	 * @return string
	 */
	private function admin_application_actions( $user_id, $status ) {
		$links = array();
		if ( 'pending' === $status ) {
			if ( self::is_administrator_account( $user_id ) ) {
				$links[] = '<span title="' . esc_attr__( 'UAP does not create affiliate records for administrator accounts.', 'off-label-account-hub' ) . '">' . esc_html__( 'Approval unavailable', 'off-label-account-hub' ) . '</span>';
			} else {
				$links[] = $this->admin_action_link( $user_id, 'approve', __( 'Approve', 'off-label-account-hub' ) );
			}
			$links[] = $this->admin_action_link( $user_id, 'reject', __( 'Reject', 'off-label-account-hub' ) );
		}
		if ( 'pending' === $status || 'rejected' === $status ) {
			$links[] = $this->admin_action_link( $user_id, 'reset', __( 'Reset', 'off-label-account-hub' ) );
		}

		return implode( ' | ', $links );
	}

	/**
	 * Build one application decision URL.
	 *
	 * @param int    $user_id User ID.
	 * @param string $decision Decision.
	 * @param string $label Link label.
	 * @return string
	 */
	private function admin_action_link( $user_id, $decision, $label ) {
		$url = add_query_arg(
			array(
				'action'   => 'olr_affiliate_application_action',
				'user_id'  => absint( $user_id ),
				'decision' => sanitize_key( $decision ),
			),
			admin_url( 'admin-post.php' )
		);
		$url = wp_nonce_url( $url, 'olr_affiliate_application_' . $decision . '_' . absint( $user_id ) );

		return '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}

	/**
	 * Process an administrator's application decision.
	 */
	public function application_admin_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage affiliate applications.', 'off-label-account-hub' ) );
		}

		$user_id  = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		$decision = isset( $_GET['decision'] ) ? sanitize_key( wp_unslash( $_GET['decision'] ) ) : '';
		if ( ! $user_id || ! in_array( $decision, array( 'approve', 'reject', 'reset' ), true ) ) {
			wp_die( esc_html__( 'Invalid application action.', 'off-label-account-hub' ) );
		}
		check_admin_referer( 'olr_affiliate_application_' . $decision . '_' . $user_id );
		$current_status = $this->application_status( $user_id );
		if ( ( in_array( $decision, array( 'approve', 'reject' ), true ) && 'pending' !== $current_status ) || ( 'reset' === $decision && ! in_array( $current_status, array( 'pending', 'rejected' ), true ) ) ) {
			wp_die( esc_html__( 'This application is no longer in a state that allows that action.', 'off-label-account-hub' ) );
		}

		$redirect_args = array( 'page' => 'olr-affiliate-applications' );
		if ( 'approve' === $decision ) {
			$result = $this->approve_application( $user_id );
			if ( is_wp_error( $result ) ) {
				$redirect_args['olr_error'] = sanitize_key( $result->get_error_code() );
			} else {
				$redirect_args['olr_updated'] = 1;
			}
		} elseif ( 'reject' === $decision ) {
			update_user_meta( $user_id, self::META_STATUS, 'rejected' );
			update_user_meta( $user_id, self::META_REJECTED, current_time( 'mysql' ) );
			$redirect_args['olr_updated'] = 1;
		} else {
			$this->reset_application_state( $user_id );
			$redirect_args['olr_updated'] = 1;
		}

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Create the UAP affiliate record while preserving the existing WordPress role.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return true|WP_Error
	 */
	private function approve_application( $user_id ) {
		global $indeed_db;
		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User ) {
			return new WP_Error( 'user_missing' );
		}
		if ( self::is_administrator_account( $user_id ) ) {
			return new WP_Error( 'administrator_account' );
		}
		if ( ! self::uap_is_available() ) {
			return new WP_Error( 'uap_unavailable' );
		}

		if ( ! is_object( $indeed_db ) && class_exists( 'Uap_Database' ) ) {
			$indeed_db = new Uap_Database();
		}
		if ( ! is_object( $indeed_db ) || ! method_exists( $indeed_db, 'save_affiliate' ) ) {
			return new WP_Error( 'uap_unavailable' );
		}

		$affiliate_id = self::affiliate_id( $user_id );
		if ( ! $affiliate_id ) {
			$affiliate_id = absint( $indeed_db->save_affiliate( $user_id ) );
			if ( ! $affiliate_id ) {
				$affiliate_id = self::affiliate_id( $user_id );
			}
		}
		if ( ! $affiliate_id ) {
			return new WP_Error( 'record_creation_failed' );
		}

		$default_rank = absint( get_option( 'uap_register_new_user_rank' ) );
		if ( ! $default_rank && method_exists( $indeed_db, 'return_settings_from_wp_option' ) ) {
			$register_settings = (array) $indeed_db->return_settings_from_wp_option( 'register' );
			$default_rank      = ! empty( $register_settings['uap_register_new_user_rank'] ) ? absint( $register_settings['uap_register_new_user_rank'] ) : 0;
		}
		if ( $default_rank && method_exists( $indeed_db, 'update_affiliate_rank_by_uid' ) ) {
			$indeed_db->update_affiliate_rank_by_uid( $user_id, $default_rank );
		}

		update_user_meta( $user_id, self::META_STATUS, 'approved' );
		update_user_meta( $user_id, self::META_APPROVED, current_time( 'mysql' ) );
		delete_user_meta( $user_id, self::META_REJECTED );
		$notification_sent = false;
		if ( function_exists( 'uap_send_user_notifications' ) ) {
			$notification_sent = (bool) uap_send_user_notifications( $user_id, 'affiliate_account_approve', $default_rank );
		}
		if ( ! $notification_sent ) {
			$this->send_application_email( $user_id, 'approved' );
		}
		do_action( 'olr_affiliate_application_approved', $user_id, $affiliate_id );

		return true;
	}

	/**
	 * Send a transactional application-status email to the applicant.
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param string $event   submitted or approved.
	 * @return bool
	 */
	private function send_application_email( $user_id, $event ) {
		$user = get_userdata( absint( $user_id ) );
		if ( ! $user instanceof WP_User || ! is_email( $user->user_email ) ) {
			return false;
		}

		$site_name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$name      = $user->display_name ? $user->display_name : $user->user_login;
		if ( 'submitted' === $event ) {
			$subject = sprintf( __( '[%s] Affiliate application received', 'off-label-account-hub' ), $site_name );
			$message = sprintf(
				__( "Hello %1\$s,\n\nWe received your affiliate application for %2\$s. Its status is pending review.\n\nWe will email you again after the application is reviewed.", 'off-label-account-hub' ),
				$name,
				$site_name
			);
		} elseif ( 'approved' === $event ) {
			$subject = sprintf( __( '[%s] Affiliate application approved', 'off-label-account-hub' ), $site_name );
			$message = sprintf(
				__( "Hello %1\$s,\n\nYour affiliate application for %2\$s has been approved.\n\nSign in to your account to access the affiliate portal: %3\$s", 'off-label-account-hub' ),
				$name,
				$site_name,
				$this->account_url()
			);
		} else {
			return false;
		}

		return (bool) wp_mail( $user->user_email, $subject, $message );
	}

	/**
	 * Resolve the administrator address used for new-application alerts.
	 *
	 * @return string
	 */
	private function application_notification_email() {
		$email = sanitize_email( (string) get_option( self::OPTION_NOTIFICATION_EMAIL, '' ) );
		if ( ! is_email( $email ) ) {
			$email = sanitize_email( (string) get_option( 'admin_email', '' ) );
		}

		return is_email( $email ) ? $email : '';
	}

	/**
	 * Notify the affiliate manager when a new application is submitted.
	 *
	 * @param int $user_id Applicant WordPress user ID.
	 * @return bool
	 */
	private function send_application_admin_email( $user_id ) {
		$user = get_userdata( absint( $user_id ) );
		$to   = $this->application_notification_email();
		if ( ! $user instanceof WP_User || ! $to ) {
			return false;
		}

		$site_name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$subject   = sprintf( __( '[%s] New affiliate application', 'off-label-account-hub' ), $site_name );
		$message   = sprintf(
			__( "A new affiliate application is ready for review.\n\nApplicant: %1\$s\nEmail: %2\$s\nWebsite or social URL: %3\$s\nPromotion plan:\n%4\$s\n\nReview applications: %5\$s", 'off-label-account-hub' ),
			$user->display_name ? $user->display_name : $user->user_login,
			$user->user_email,
			(string) get_user_meta( $user->ID, self::META_URL, true ),
			(string) get_user_meta( $user->ID, self::META_PLAN, true ),
			admin_url( 'admin.php?page=olr-affiliate-applications' )
		);

		return (bool) wp_mail( $to, $subject, $message );
	}

	/**
	 * Clear application history so a member can submit a fresh application.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	private function reset_application_state( $user_id ) {
		foreach ( array( self::META_STATUS, self::META_URL, self::META_PLAN, self::META_SUBMITTED, self::META_TERMS_URL, self::META_TERMS_ACCEPTED, self::META_APPROVED, self::META_REJECTED ) as $meta_key ) {
			delete_user_meta( absint( $user_id ), $meta_key );
		}
	}

	/**
	 * Convert UAP dashboard deletions into affiliate-only removals.
	 *
	 * UAP 9.7.7's delete_affiliates() also deletes the underlying WordPress user.
	 * The account hub must preserve the member identity, UM profile, and store
	 * access, so valid UAP deletion requests are intercepted before page render.
	 */
	public function protect_uap_affiliate_deletion() {
		if ( ! current_user_can( 'manage_options' ) || 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : '' ) ) {
			return;
		}

		$page = isset( $_GET['page'] ) && is_scalar( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		$tab  = isset( $_GET['tab'] ) && is_scalar( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : '';
		if ( 'ultimate_affiliates_pro' !== $page || 'affiliates' !== $tab ) {
			return;
		}

		$nonce = isset( $_POST['uap_admin_list_affiliate_nonce'] ) && is_scalar( $_POST['uap_admin_list_affiliate_nonce'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['uap_admin_list_affiliate_nonce'] ) )
			: '';
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'uap_admin_list_affiliate_nonce' ) ) {
			return;
		}

		$affiliate_ids = array();
		if ( ! empty( $_POST['delete_affiliate'] ) && is_scalar( $_POST['delete_affiliate'] ) ) {
			$affiliate_ids[] = absint( $_POST['delete_affiliate'] );
		}
		$bulk_action = isset( $_POST['do_action'] ) && is_scalar( $_POST['do_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['do_action'] ) ) : '';
		if ( 'delete' === $bulk_action && ! empty( $_POST['affiliate_id_arr'] ) && is_array( $_POST['affiliate_id_arr'] ) ) {
			$affiliate_ids = array_merge( $affiliate_ids, array_map( 'absint', wp_unslash( $_POST['affiliate_id_arr'] ) ) );
		}
		$affiliate_ids = array_values( array_unique( array_filter( $affiliate_ids ) ) );
		if ( ! $affiliate_ids ) {
			return;
		}

		$removed = $this->remove_affiliate_access( $affiliate_ids );

		$redirect_args = array(
			'page' => 'ultimate_affiliates_pro',
			'tab'  => 'affiliates',
		);
		if ( $removed ) {
			$redirect_args['olr_affiliate_unlinked'] = $removed;
		} else {
			$redirect_args['olr_affiliate_unlink_error'] = 1;
		}

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Protect the current UAP AJAX datatable deletion actions as well as its
	 * older form-based affiliate screen.
	 */
	public function protect_uap_affiliate_ajax_deletion() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'error' );
		}

		$nonce = isset( $_POST['uap_admin_forms_nonce'] ) && is_scalar( $_POST['uap_admin_forms_nonce'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['uap_admin_forms_nonce'] ) )
			: '';
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'uap_admin_forms_nonce' ) ) {
			wp_die( 'error' );
		}

		$action        = isset( $_POST['action'] ) && is_scalar( $_POST['action'] ) ? sanitize_key( wp_unslash( (string) $_POST['action'] ) ) : '';
		$affiliate_ids = array();
		if ( 'uap_ajax_remove_one_affiliate' === $action && ! empty( $_POST['id'] ) && is_scalar( $_POST['id'] ) ) {
			$affiliate_ids[] = absint( $_POST['id'] );
		} elseif ( 'uap_ajax_remove_many_affiliates' === $action && ! empty( $_POST['ids'] ) && is_scalar( $_POST['ids'] ) ) {
			$affiliate_ids = array_map( 'absint', explode( ',', wp_unslash( (string) $_POST['ids'] ) ) );
		}

		$removed = $this->remove_affiliate_access( $affiliate_ids );
		wp_die( $removed ? 'success' : 'error' );
	}

	/**
	 * Remove UAP access and records without deleting the underlying member.
	 *
	 * @param int[] $affiliate_ids UAP affiliate IDs.
	 * @return int Number of safely unlinked affiliates.
	 */
	private function remove_affiliate_access( $affiliate_ids ) {
		global $indeed_db;

		$affiliate_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $affiliate_ids ) ) ) );
		if ( ! $affiliate_ids ) {
			return 0;
		}
		if ( ! is_object( $indeed_db ) && class_exists( 'Uap_Database' ) ) {
			$indeed_db = new Uap_Database();
		}
		if ( ! is_object( $indeed_db ) || ! method_exists( $indeed_db, 'get_uid_by_affiliate_id' ) ) {
			return 0;
		}

		$removed = 0;
		foreach ( $affiliate_ids as $affiliate_id ) {
			$user_id = absint( $indeed_db->get_uid_by_affiliate_id( $affiliate_id ) );
			if ( ! $user_id || ! get_userdata( $user_id ) ) {
				continue;
			}

			$did_remove = false;
			if ( method_exists( $indeed_db, 'remove_user_from_affiliate' ) ) {
				$did_remove = (bool) $indeed_db->remove_user_from_affiliate( $user_id );
			} elseif ( method_exists( $indeed_db, 'delete_affiliate_details' ) ) {
				$indeed_db->delete_affiliate_details( $affiliate_id );
				$did_remove = true;
			}

			if ( $did_remove ) {
				$this->reset_application_state( $user_id );
				++$removed;
			}
		}

		return $removed;
	}

	/**
	 * Explain a safe administrator-facing application failure.
	 *
	 * @param string $code Error code.
	 * @return string
	 */
	private function application_error_message( $code ) {
		$messages = array(
			'administrator_account' => __( 'Ultimate Affiliate Pro does not allow WordPress administrator accounts to become affiliates. Reset this application and test with a standard member account.', 'off-label-account-hub' ),
			'uap_unavailable'       => __( 'Ultimate Affiliate Pro is not available to create the affiliate record. Confirm the plugin is active and finish its setup.', 'off-label-account-hub' ),
			'user_missing'          => __( 'The applicant account no longer exists.', 'off-label-account-hub' ),
			'record_creation_failed' => __( 'Ultimate Affiliate Pro was detected, but it did not create the affiliate record. Review its database setup and logs, then try again.', 'off-label-account-hub' ),
		);

		return isset( $messages[ $code ] ) ? $messages[ $code ] : __( 'The affiliate record could not be created.', 'off-label-account-hub' );
	}

	/**
	 * Match UAP's native restriction against administrator affiliate records.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return bool
	 */
	private static function is_administrator_account( $user_id ) {
		$user = get_userdata( absint( $user_id ) );
		return $user instanceof WP_User && in_array( 'administrator', (array) $user->roles, true );
	}

	/**
	 * Detect UAP in both public and administrator requests.
	 *
	 * UAP does not register its public account shortcode on every administrator
	 * request, so shortcode_exists() alone produces a false missing-plugin notice.
	 *
	 * @return bool
	 */
	private static function uap_is_available() {
		global $indeed_db;
		return defined( 'UAP_PATH' )
			|| defined( 'UAP_NAME' )
			|| class_exists( 'Ultimate_Affiliate_Pro_Main' )
			|| class_exists( 'Uap_Database' )
			|| is_object( $indeed_db );
	}

	/**
	 * Return a normalized application status.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string
	 */
	private function application_status( $user_id ) {
		$status = sanitize_key( (string) get_user_meta( absint( $user_id ), self::META_STATUS, true ) );
		return in_array( $status, array( 'pending', 'approved', 'rejected' ), true ) ? $status : '';
	}

	/**
	 * Determine whether a native UAP account subtab is enabled.
	 *
	 * @param string $slug UAP tab slug.
	 * @return bool
	 */
	private function uap_tab_enabled( $slug ) {
		global $indeed_db;
		if ( ! is_object( $indeed_db ) || ! method_exists( $indeed_db, 'return_settings_from_wp_option' ) ) {
			return false;
		}

		$settings = (array) $indeed_db->return_settings_from_wp_option( 'account_page' );
		$raw      = isset( $settings['uap_ap_tabs'] ) ? (string) $settings['uap_ap_tabs'] : '';
		$tabs     = array_filter( array_map( 'sanitize_key', explode( ',', $raw ) ) );

		return in_array( sanitize_key( $slug ), $tabs, true );
	}

	/**
	 * Determine whether any tab in a native UAP group is enabled.
	 *
	 * @param string[] $tabs UAP tab slugs.
	 * @return bool
	 */
	private function uap_group_enabled( $tabs ) {
		foreach ( $tabs as $tab ) {
			if ( $this->uap_tab_enabled( $tab ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return the first enabled UAP creative section.
	 *
	 * @return string
	 */
	private function creative_uap_tab() {
		foreach ( array( 'affiliate_link', 'banners', 'campaigns', 'simple_links', 'landing_pages', 'coupons', 'product_links' ) as $tab ) {
			if ( $this->uap_tab_enabled( $tab ) ) {
				return $tab;
			}
		}

		return '';
	}

	/**
	 * Canonical account URL.
	 *
	 * @return string
	 */
	private function account_url() {
		$page = get_page_by_path( self::ACCOUNT_SLUG );
		return $page instanceof WP_Post ? get_permalink( $page ) : home_url( '/' . self::ACCOUNT_SLUG . '/' );
	}

	/**
	 * Canonical account tab URL.
	 *
	 * @param string $tab Ultimate Member tab slug.
	 * @return string
	 */
	private function account_tab_url( $tab ) {
		return add_query_arg( 'um_tab', sanitize_key( $tab ), $this->account_url() );
	}

	/**
	 * Resolve Ultimate Member's configured login page with a WordPress fallback.
	 *
	 * @return string
	 */
	private function ultimate_member_login_url( $redirect_url = '' ) {
		$redirect_url = $redirect_url ? esc_url_raw( $redirect_url ) : $this->account_url();
		$login_url = '';
		if ( function_exists( 'um_get_core_page' ) ) {
			$core_page = um_get_core_page( 'login' );
			if ( is_numeric( $core_page ) ) {
				$login_url = get_permalink( absint( $core_page ) );
			} elseif ( is_string( $core_page ) && wp_http_validate_url( $core_page ) ) {
				$login_url = $core_page;
			}
		}
		if ( ! $login_url ) {
			$core_pages = (array) get_option( 'um_core_pages', array() );
			if ( ! empty( $core_pages['login'] ) ) {
				$login_url = get_permalink( absint( $core_pages['login'] ) );
			}
		}
		if ( ! $login_url ) {
			$login_url = wp_login_url( $redirect_url );
		}

		return add_query_arg( 'redirect_to', $redirect_url, $login_url );
	}

	/**
	 * Resolve Ultimate Member's registration page with a safe account fallback.
	 *
	 * @param string $redirect_url Destination after registration.
	 * @return string
	 */
	private function ultimate_member_register_url( $redirect_url = '' ) {
		$redirect_url = $redirect_url ? esc_url_raw( $redirect_url ) : $this->account_url();
		$register_url = '';
		if ( function_exists( 'um_get_core_page' ) ) {
			$core_page = um_get_core_page( 'register' );
			if ( is_numeric( $core_page ) ) {
				$register_url = get_permalink( absint( $core_page ) );
			} elseif ( is_string( $core_page ) && wp_http_validate_url( $core_page ) ) {
				$register_url = $core_page;
			}
		}
		if ( ! $register_url ) {
			$core_pages = (array) get_option( 'um_core_pages', array() );
			if ( ! empty( $core_pages['register'] ) ) {
				$register_url = get_permalink( absint( $core_pages['register'] ) );
			}
		}
		if ( ! $register_url ) {
			return $this->ultimate_member_login_url( $redirect_url );
		}

		return add_query_arg( 'redirect_to', $redirect_url, $register_url );
	}

	/**
	 * Route logged-out and retired account surfaces safely.
	 */
	public function route_account_requests() {
		if ( is_admin() || wp_doing_ajax() || is_preview() ) {
			return;
		}

		if ( $this->is_account_request() && is_user_logged_in() && in_array( $this->requested_account_tab(), array( 'logout', 'olr_logout' ), true ) ) {
			wp_safe_redirect( wp_logout_url( home_url( '/' ) ) );
			exit;
		}

		if ( $this->is_account_request() && ! is_user_logged_in() ) {
			wp_safe_redirect( $this->ultimate_member_login_url() );
			exit;
		}

		global $wp;
		$request = isset( $wp->request ) ? trim( (string) $wp->request, '/' ) : '';
		if ( 'my-account' === $request ) {
			wp_safe_redirect( $this->account_url(), 301 );
			exit;
		}
		if ( preg_match( '#^my-account/orders(?:/(?:page/)?([0-9]+))?/?$#', $request, $matches ) ) {
			$args = array( 'um_tab' => 'orders' );
			if ( ! empty( $matches[1] ) ) {
				$args['order_page'] = absint( $matches[1] );
			}
			wp_safe_redirect( add_query_arg( $args, $this->account_url() ), 301 );
			exit;
		}
		if ( preg_match( '#^my-account/view-order/([0-9]+)/?$#', $request, $matches ) ) {
			wp_safe_redirect( add_query_arg( array( 'um_tab' => 'orders', 'order_id' => absint( $matches[1] ) ), $this->account_url() ), 301 );
			exit;
		}

		$uap_page_id = absint( get_option( 'uap_general_user_page' ) );
		if ( ! $uap_page_id ) {
			global $indeed_db;
			if ( is_object( $indeed_db ) && method_exists( $indeed_db, 'return_settings_from_wp_option' ) ) {
				$uap_pages   = (array) $indeed_db->return_settings_from_wp_option( 'general-default_pages' );
				$uap_page_id = ! empty( $uap_pages['uap_general_user_page'] ) ? absint( $uap_pages['uap_general_user_page'] ) : 0;
			}
		}
		$account     = get_page_by_path( self::ACCOUNT_SLUG );
		if ( $uap_page_id && ( ! $account || $uap_page_id !== absint( $account->ID ) ) && is_page( $uap_page_id ) ) {
			$tab = self::is_active_affiliate( get_current_user_id() ) ? 'overview' : 'affiliate';
			wp_safe_redirect( $this->account_tab_url( $tab ), 302 );
			exit;
		}
	}

	/**
	 * Report missing dependencies and unreviewed UAP versions to administrators.
	 */
	public function dependency_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$missing = array();
		if ( ! shortcode_exists( 'ultimatemember_account' ) ) {
			$missing[] = 'Ultimate Member';
		}
		if ( ! class_exists( 'WooCommerce' ) ) {
			$missing[] = 'WooCommerce';
		}
		if ( ! self::uap_is_available() ) {
			$missing[] = 'Ultimate Affiliate Pro';
		}
		if ( $missing ) {
			echo '<div class="notice notice-error"><p>' . esc_html( sprintf( __( 'Off Label Account Hub requires: %s.', 'off-label-account-hub' ), implode( ', ', $missing ) ) ) . '</p></div>';
		}

		if ( defined( 'UAP_PLUGIN_VER' ) && '9.7.7' !== (string) UAP_PLUGIN_VER ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( sprintf( __( 'Off Label Account Hub was verified with Ultimate Affiliate Pro 9.7.7. Installed version: %s. Review the affiliate templates on staging before launch.', 'off-label-account-hub' ), UAP_PLUGIN_VER ) ) . '</p></div>';
		}
		if ( ! get_option( self::OPTION_TERMS_URL ) ) {
			echo '<div class="notice notice-warning"><p>' . wp_kses_post( sprintf( __( 'Affiliate applications are closed until an <a href="%s">affiliate terms URL</a> is configured.', 'off-label-account-hub' ), esc_url( admin_url( 'admin.php?page=olr-affiliate-applications' ) ) ) ) . '</p></div>';
		}

		$page = isset( $_GET['page'] ) && is_scalar( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		$tab  = isset( $_GET['tab'] ) && is_scalar( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : '';
		if ( 'ultimate_affiliates_pro' === $page && 'affiliates' === $tab ) {
			if ( ! empty( $_GET['olr_affiliate_unlinked'] ) ) {
				$count = absint( $_GET['olr_affiliate_unlinked'] );
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( _n( 'Affiliate access was removed. The member account was preserved and can apply again.', 'Affiliate access was removed for %d users. Their member accounts were preserved and can apply again.', $count, 'off-label-account-hub' ), $count ) ) . '</p></div>';
			} elseif ( isset( $_GET['olr_affiliate_unlink_error'] ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Affiliate access could not be removed safely. No WordPress member account was deleted.', 'off-label-account-hub' ) . '</p></div>';
			}
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Account protection is active: Delete removes affiliate access and affiliate records, preserves the member account, and opens a fresh application. Use Reject in Affiliate Applications only when reapplication should remain locked.', 'off-label-account-hub' ) . '</p></div>';
		}
	}

	/**
	 * Branded reusable empty state.
	 *
	 * @param string $message Empty-state text.
	 * @return string
	 */
	private function empty_panel( $message ) {
		return $this->notice_panel( __( 'NOTHING TO SHOW YET', 'off-label-account-hub' ), $message );
	}

	/**
	 * Branded reusable notice panel.
	 *
	 * @param string $title  Heading.
	 * @param string $message Body.
	 * @param string $url Optional action URL.
	 * @param string $label Optional action label.
	 * @return string
	 */
	private function notice_panel( $title, $message, $url = '', $label = '' ) {
		ob_start();
		?>
		<section class="olr-account-panel"><div class="olr-account-empty"><p class="olr-account-eyebrow"><?php esc_html_e( 'Off Label account', 'off-label-account-hub' ); ?></p><h2><?php echo esc_html( $title ); ?></h2><p><?php echo esc_html( $message ); ?></p><?php if ( $url && $label ) : ?><a class="olr-account-button" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?><span aria-hidden="true">&rarr;</span></a><?php endif; ?></div></section>
		<?php
		return ob_get_clean();
	}
}

OLR_Account_Hub::instance();

if ( function_exists( 'register_activation_hook' ) ) {
	register_activation_hook( __FILE__, array( 'OLR_Account_Hub', 'activate' ) );
}
