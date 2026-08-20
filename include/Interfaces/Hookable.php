<?php

namespace NextJsRevalidate\Interfaces;

/**
 * A class that registers WordPress hooks.
 *
 * Constructing a Hookable has no effect on global state: `register_hooks()` is
 * the only thing that does, it is called once, and it is called by the
 * composition root — `NextJsRevalidate::__construct()` — rather than by the
 * class itself. So a Hookable is always safe to construct for a single method
 * call, which is what `NextJsRevalidate::uninstall()` used to have to pay four
 * hook registrations for.
 *
 * Every class the composition root constructs implements this. The interface
 * asserts that one thing and forces nothing else: `Assets` and `I18n` do not
 * extend `Abstracts\Base` and gain none of its coupling by declaring this.
 *
 * See `docs/adr/0003-explicit-hook-registration.md`.
 */
interface Hookable {

	/**
	 * Attach this class's callbacks to WordPress actions and filters.
	 *
	 * Called once, by the composition root, in construction order — the order
	 * is load-bearing, because WordPress runs same-hook, same-priority
	 * callbacks in registration order.
	 *
	 * @return void
	 */
	public function register_hooks(): void;
}
