<?php

namespace NextJsRevalidate\Abstracts;

use NextJsRevalidate;

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
