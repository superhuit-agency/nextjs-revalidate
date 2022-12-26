<?php

namespace NextJsRevalidate;

class I18n {
	function __construct() {
		add_action( 'init', [$this, 'load_plugin_textdomain'] );
		add_action( 'switch_locale', [$this, 'load_plugin_textdomain' ] );
	}

	function load_plugin_textdomain() {
		load_plugin_textdomain( 'nextjs-revalidate', false, 'nextjs-revalidate/languages' );
	}
}
