<?php

namespace NextJsRevalidate;

class RevalidateItem {

	/**
	 * @var int
	 */
	public $id;

	/**
	 * @var string
	 */
	public $permalink;

	/**
	 * @var int
	 */
	public $priority;

	/**
	 * Constructor
	 *
	 * @param RevalidateItem|object $item
	 */
	public function __construct( $item ) {
		$this->id        = $item->id;
		$this->permalink = $item->permalink;
		$this->priority  = $item->priority;
	}
}
