<?php

namespace NextJsRevalidate;

use NextJsRevalidate\Abstracts\Base;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class RestApi extends Base {

	const NAMESPACE = 'nextjs-revalidate/v1';

	public function __construct() {
		add_action('rest_api_init', [$this, 'register_routes']);
	}

	public function register_routes() {
		// Single-item route
		register_rest_route(
			self::NAMESPACE,
			'/revalidate',
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [$this, 'handle_revalidate'],
				'permission_callback' => [$this, 'check_permission'],
				'args'                => [
					'secret' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'path' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'priority' => [
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'default'           => 10,
					],
				],
			]
		);

		// Batch route
		register_rest_route(
			self::NAMESPACE,
			'/revalidate/batch',
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [$this, 'handle_revalidate_batch'],
				'permission_callback' => [$this, 'check_permission'],
				'args'                => [
					'secret' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'items' => [
						'required'          => true,
						'type'              => 'array',
						'sanitize_callback' => null,
					],
				],
			]
		);
	}

	public function check_permission(WP_REST_Request $request) {
		$secret = $request->get_param('secret');

		$expected_secret = $this->settings->secret;

		if (!$expected_secret) {
			return new \WP_Error(
				'missing_secret',
				'Missing secret',
				['status' => 500]
			);
		}

		return hash_equals($expected_secret, $secret);
	}

	public function handle_revalidate(WP_REST_Request $request) {
		// Single-item handler: build the single-item array and delegate
		$path = $request->get_param('path');
		$priority = $request->get_param('priority') ? absint($request->get_param('priority')) : 10;
		if (empty($path)) {
			return new WP_REST_Response([
				'success' => false,
				'message' => 'Missing "path" parameter for single revalidate endpoint.'
			], 400);
		}

		$items = [[
			'path'     => sanitize_text_field($path),
			'priority' => $priority,
		]];

		return $this->process_items($items);
	}

	/**
	 * Handler for batch revalidate route. Expects an 'items' array in the request body.
	 */
	public function handle_revalidate_batch(WP_REST_Request $request) {
		$body_items = $request->get_param('items');
		if (!$body_items || !is_array($body_items)) {
			return new WP_REST_Response([
				'success' => false,
				'message' => 'Missing or invalid "items" array in request body.'
			], 400);
		}

		$items = [];
		foreach ($body_items as $it) {
			if (is_object($it)) {
				$it = (array) $it;
			}
			if (!is_array($it)) {
				continue;
			}
			$items[] = [
				'path'     => isset($it['path']) ? sanitize_text_field($it['path']) : null,
				'priority' => isset($it['priority']) ? absint($it['priority']) : 10,
			];
		}

		if (empty($items)) {
			return new WP_REST_Response([
				'success' => false,
				'message' => 'No valid items found in request.'
			], 400);
		}

		return $this->process_items($items);
	}

	/**
	 * Shared processing for items array. Returns WP_REST_Response.
	 * Each item: ['path' => string, 'priority' => int]
	 */
	private function process_items(array $items) {
		$results = [];
		$had_error = false;

		foreach ($items as $it) {
			if (empty($it['path'])) {
				$results[] = [
					'path'    => $it['path'],
					'success' => false,
					'message' => 'Missing path',
				];
				$had_error = true;
				continue;
			}

			try {
				$res = $this->queue->add_item($it['path'], $it['priority']);
				if (is_wp_error($res)) {
					$results[] = [
						'path'    => $it['path'],
						'success' => false,
						'message' => $res->get_error_message(),
					];
					$had_error = true;
				} else {
					$results[] = [
						'path'    => $it['path'],
						'success' => true,
						'data'    => $res,
					];
				}
			} catch (\Exception $e) {
				$results[] = [
					'path'    => $it['path'],
					'success' => false,
					'message' => $e->getMessage(),
				];
				$had_error = true;
			}
		}

		$status = $had_error ? 207 : 200; // 207 Multi-Status when some items failed
		return new WP_REST_Response([
			'success' => !$had_error,
			'results' => $results,
		], $status);
	}
}
