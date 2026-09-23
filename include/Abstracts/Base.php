<?php

namespace NextJsRevalidate\Abstracts;

use NextJsRevalidate;

/**
 * Simplified access to the objects the composition root shares between classes.
 *
 * `__get()` below forwards exactly five names to `NextJsRevalidate::init()`, so
 * a subclass reads a collaborator as `$this->queue` without being handed one.
 * Nothing here declares that surface: PHPStan sees a class with no such
 * property, and every read of one is an undefined property until the subclass
 * *says which of the five it uses*, in an `@property` docblock of its own.
 *
 * That is deliberate rather than an oversight. The list a subclass declares is
 * its collaborators written down — `RevalidateAll` reaches the queue, the
 * settings and `Revalidate`; `Cron\ScheduledPurges` reaches the queue and
 * nothing else — and declaring them here instead would hand all five to all of
 * them and say nothing about any one.
 */
abstract class Base {

	/**
	 * Simplify access to main instance properties
	 * which are shared between classes
	 */
	function __get( $name ) {

		if ( property_exists( $this, $name ) ) return $this->$name;

		$njr = NextJsRevalidate::init();

		if      ( $name === 'queue' )         return $njr->queue;
		else if ( $name === 'settings' )      return $njr->settings;
		else if ( $name === 'revalidate' )    return $njr->revalidate;
		else if ( $name === 'revalidateAll' ) return $njr->revalidateAll;
		else if ( $name === 'restApi' )       return $njr->restApi;

		return $this->$name ?? null;
	}
}
