<?php

namespace NextJsRevalidate\Traits;

/**
 * For an integration: ask whether the plugin it integrates with is there only
 * once every plugin has loaded — nothing decides which of two plugins loads
 * first, so the answer is not known before `plugins_loaded`.
 */
trait WhenPluginsLoaded {

	/**
	 * Call `$register` once every plugin has loaded: now, when that has
	 * already happened, or on `plugins_loaded`.
	 *
	 * @param callable $register
	 * @return void
	 */
	private function when_plugins_loaded( callable $register ) {

		if ( did_action( 'plugins_loaded' ) ) {
			$register();
			return;
		}

		add_action( 'plugins_loaded', $register );
	}
}
