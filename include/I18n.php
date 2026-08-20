<?php

namespace NextJsRevalidate;

use NextJsRevalidate\Interfaces\Hookable;

class I18n implements Hookable {

	public function register_hooks(): void {
		add_action( 'init', [$this, 'load_plugin_textdomain'] );
		add_action( 'switch_locale', [$this, 'load_plugin_textdomain' ] );
	}

	function load_plugin_textdomain() {
		load_plugin_textdomain( 'nextjs-revalidate', false, 'nextjs-revalidate/languages' );
	}
}
