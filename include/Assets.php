<?php

namespace NextJsRevalidate;

class Assets {

	const WEBPACK_PORT = 8000;

	private $assets;

	/**
	 * Hook into WP actions & filters
	 */
	function __construct() {
		add_action( 'init', [$this, 'register_assets'] );
		add_action( 'admin_enqueue_scripts',	[$this, 'enqueue_admin_assets'] );
		add_action( 'admin_enqueue_scripts',	[$this, 'enqueue_settings_assets'] );
	}

	function register_assets() {
		// vars
		$manifest = null;
		$assets_uri = '';
		$this->assets = [
			'admin'    => [],
			'settings' => [],
		];

		// In dev mode
		// -> try to load assets from webpack-dev-server
		if ( WP_DEBUG && $manifest = $this->get_remote_json(sprintf('%s://host.docker.internal:%d/manifest.json',  is_ssl() ? 'https' : 'http', self::WEBPACK_PORT)) ) {
			$assets_uri = sprintf( '%s:%d/', get_site_url(), self::WEBPACK_PORT );
		}

		// Not in dev mode
		// OR webpack-dev-server is not running
		// -> try to load from the filesystem  [/wp-content/themes/starterpack/static/manifest.json]
		elseif ( file_exists(NJR_PATH . '/dist/manifest.json') && $manifest = json_decode(file_get_contents(NJR_PATH . '/dist/manifest.json', true)) ) {
			$assets_uri = NJR_URI . 'dist/';
		}

		// Manifest not found…
		// -> bail
		else {
			return;
		}

		$this->assets['admin']['js']  = !empty($manifest->{'admin.js'})  ? $assets_uri . $manifest->{'admin.js'}  : null;
		// $this->assets['admin']['css']  = !empty($manifest->{'admin.css'})  ? $assets_uri . $manifest->{'admin.css'}  : null;

		$this->assets['settings']['css']  = !empty($manifest->{'settings.css'})  ? $assets_uri . $manifest->{'settings.css'}  : null;
	}

	function enqueue_admin_assets() {
		if ( !empty($this->assets['admin']['js']) ) {
			wp_register_script( 'njr-admin-script', $this->assets['admin']['js'], [], null, true );
			wp_localize_script( 'njr-admin-script', 'nextjs_revalidate', [
				'url'   => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'nextjs-revalidate-revalidate_all_progress' ),
			] );
			wp_enqueue_script( 'njr-admin-script', $this->assets['admin']['js'], [], null, true );
		}
		if ( !empty($this->assets['admin']['css']) ) wp_enqueue_style( 'njr-admin-styles', $this->assets['admin']['css'] );
	}

	function enqueue_settings_assets( $hook ) {
		if( 'settings_page_'.Settings::PAGE_NAME !== $hook ) return;

		if ( !empty($this->assets['settings']['css']) ) wp_enqueue_style( 'njr-settings-styles', $this->assets['settings']['css'] );
	}

	/**
	 * Retrieve.
	 */
	function get_remote_json($url) {
		$curl = curl_init($url);

		// Will return the response, if false it print the response
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

		// Debug options
		if (WP_DEBUG) {
			if ( is_ssl() ) {
				curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
				curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
			}
			// curl_setopt($curl, CURLOPT_VERBOSE, true); // to debug only
		}

		$content = curl_exec($curl);
		$code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close( $curl );

		return ($content === false || $code == 404)
			? false
			: json_decode($content);
	}
}



