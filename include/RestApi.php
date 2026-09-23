<?php

namespace NextJsRevalidate;

use NextJsRevalidate\Abstracts\Base;
use NextJsRevalidate\Interfaces\Hookable;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * @property RevalidateQueue $queue
 * @property Settings        $settings
 */
class RestApi extends Base implements Hookable {

	const NAMESPACE = 'nextjs-revalidate/v1';

	/**
	 * What an item that was not accepted contributes to its request's status.
	 *
	 * The kinds have to be kept apart, because they send whoever is holding the
	 * response to different places. An item this route could not read is the
	 * caller's to fix; an insert that did not happen is this site's; and a
	 * **refusal** is neither — nothing is wrong with the request and nothing
	 * broke, the site simply has no revalidate domain or secret and can deliver
	 * nothing until an operator supplies them (ADR 0015).
	 */
	private const ITEM_BAD_REQUEST = 400;
	private const ITEM_FAILED      = 500;
	private const ITEM_REFUSED     = 503;

	/**
	 * The status a request answers when *none* of its items was accepted, in the
	 * order the kinds outrank one another.
	 *
	 * A refusal comes first because it describes the site rather than the item:
	 * an unconfigured site refuses everything it is sent, so wherever a refusal
	 * is among the outcomes it is the truth about the whole request. Both 5xx
	 * kinds outrank 400, because a caller told its request was bad goes looking
	 * at the request — and a request that was fine is the one place that answer
	 * must never send it.
	 */
	private const WHOLLY_FAILED_STATUSES = [ self::ITEM_REFUSED, self::ITEM_FAILED, self::ITEM_BAD_REQUEST ];

	public function register_hooks(): void {
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
						'default'           => RevalidateQueue::DEFAULT_PRIORITY,
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
		// REST dispatch has already applied the route's `default` and `absint`, so
		// read it as given: a truthiness check would turn an explicit `0` into 10.
		$priority = absint($request->get_param('priority'));
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
				'priority' => isset($it['priority']) ? absint($it['priority']) : RevalidateQueue::DEFAULT_PRIORITY,
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
		// The status of each item that was not accepted, in the order they were
		// sent. A count of failures decided the status until #118, and a count
		// cannot say what a wholly-failed request should answer with.
		$failed = [];

		foreach ($items as $it) {
			if (empty($it['path'])) {
				$results[] = [
					'path'    => $it['path'],
					'success' => false,
					'message' => 'Missing path',
				];
				$failed[] = self::ITEM_BAD_REQUEST;
				continue;
			}

			try {
				// An item is accepted when the queue holds it afterwards, and
				// failed otherwise (ADR 0010). `add_item()` answers four
				// different things, and three of them are not a `WP_Error`: `1`
				// for a row it inserted, `true` for a permalink already waiting
				// — both honest acceptances — and a bare `false` when its own
				// insert failed. Reading everything that is not a `WP_Error` as
				// an acceptance reported that failed insert as `success: true`
				// with a 200, and a caller reaching these routes has no other
				// feedback channel to find out otherwise (#93).
				$accepted = $this->queue->add_item($it['path'], $it['priority']);
				if (is_wp_error($accepted)) {
					$results[] = [
						'path'    => $it['path'],
						'success' => false,
						'message' => $accepted->get_error_message(),
					];
					// The only `WP_Error` the queue produces is the refusal of
					// an unconfigured site. Anything else arriving here is this
					// site failing rather than declining, and is answered as
					// such rather than as a configuration an operator could go
					// and fix.
					$failed[] = 'not_configured' === $accepted->get_error_code()
						? self::ITEM_REFUSED
						: self::ITEM_FAILED;
				} elseif (!$accepted) {
					$results[] = [
						'path'    => $it['path'],
						'success' => false,
						// A fixed message rather than `$wpdb->last_error`: the
						// insert failed, and the database's own words for why
						// are not something to hand back over a route anyone
						// holding the secret can call. The `WP_Error` above
						// carries its own because the queue wrote that one for
						// a caller to read.
						'message' => 'Could not be added to the revalidation queue.',
					];
					$failed[] = self::ITEM_FAILED;
				} else {
					$results[] = [
						'path'    => $it['path'],
						'success' => true,
						// The queue's raw answer, left as it is: `1` and `true`
						// both mean the queue holds the permalink, so `success`
						// above is the whole of what this route promises, and
						// the field can no longer be the `false` that was the
						// only trace of a failed insert.
						'data'    => $accepted,
					];
				}
			} catch (\Exception $e) {
				$results[] = [
					'path'    => $it['path'],
					'success' => false,
					'message' => $e->getMessage(),
				];
				$failed[] = self::ITEM_FAILED;
			}
		}

		return new WP_REST_Response([
			'success' => empty($failed),
			'results' => $results,
		], $this->status_of_request($results, $failed));
	}

	/**
	 * The status one request answers with.
	 *
	 * Three answers, and which one applies is decided by the outcomes rather
	 * than by the route that produced them:
	 *
	 * - **200** — every item was accepted.
	 * - **207 Multi-Status** — some were and some were not. One code cannot
	 *   describe that body, which is the whole of what 207 is for
	 *   (RFC 4918 §13), and the per-item `success` fields are where the caller
	 *   reads the rest.
	 * - **4xx or 5xx** — nothing was accepted, and the status names why.
	 *
	 * That last case used to answer 207 as well, and 207 is a **success**
	 * class: `res.ok` is true for it, `WP_REST_Response::is_error()` is false,
	 * and the raise-on-error helper of most HTTP clients stays quiet. A deploy
	 * hook or a CI job doing the ordinary thing — issue the request, check the
	 * status, trust it — was told everything was fine while nothing had been
	 * queued, and these callers have no other feedback channel to learn
	 * otherwise: they cannot see the queue, the log or the drain (#118, #93).
	 *
	 * The single route sends one item, so it can never reach the 207 branch:
	 * one outcome is the request's outcome, and there is nothing for a
	 * multi-status to disambiguate.
	 *
	 * @param array $results One entry per item processed.
	 * @param int[] $failed  The status of each item that was not accepted.
	 *
	 * @return int
	 */
	private function status_of_request(array $results, array $failed) {
		if (empty($failed)) return 200;

		if (count($failed) < count($results)) return 207;

		foreach (self::WHOLLY_FAILED_STATUSES as $status) {
			if (in_array($status, $failed, true)) return $status;
		}

		// Unreachable: every failed item contributes one of the statuses above.
		// Kept so this answers an int whatever a later kind of failure forgets.
		return self::ITEM_FAILED;
	}
}
