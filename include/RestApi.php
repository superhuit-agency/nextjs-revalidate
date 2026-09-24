<?php

namespace NextJsRevalidate;

use NextJsRevalidate\Abstracts\Base;
use NextJsRevalidate\Interfaces\Hookable;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * The two inbound routes: a deploy hook, a CI job or an external CMS naming
 * paths for this site to report.
 *
 * Each item becomes a **path** change in this request's pending changes, and
 * what a route answers is whether it was *accepted* there — never whether the
 * front-end took it, which happens after the response is sent (ADR 0010,
 * ADR 0034).
 *
 * Still `nextjs-revalidate/v1` in plugin 2.0: a REST namespace versions the REST
 * API rather than the plugin, and the request these routes take has not changed
 * — `priority` is still accepted, and ignored, since there is no queue left for
 * it to order (ADR 0035).
 *
 * @property PendingChanges $pendingChanges
 * @property Settings       $settings
 */
class RestApi extends Base implements Hookable {

	const NAMESPACE = 'nextjs-revalidate/v1';

	/**
	 * What an item that was not accepted contributes to its request's status.
	 *
	 * The kinds have to be kept apart, because they send whoever is holding the
	 * response to different places. An item this route could not read is the
	 * caller's to fix; one this site did not take — something threw, or the
	 * site's own `nextjs_revalidate_change` filter dropped it — is this site's;
	 * and a **refusal** is neither — nothing is wrong with the request and
	 * nothing broke, the site simply has no revalidate domain or secret and can
	 * deliver nothing until an operator supplies them (ADR 0015).
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
					// Accepted and ignored: there is no queue left for it to
					// order. Still declared, and still an integer, so a request
					// v1 accepted is accepted still, and one it rejected is
					// rejected still (ADR 0035).
					'priority' => [
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
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
		if (empty($path)) {
			return new WP_REST_Response([
				'success' => false,
				'message' => 'Missing "path" parameter for single revalidate endpoint.'
			], 400);
		}

		$items = [[
			'path' => sanitize_text_field($path),
		]];

		return $this->process_items($items);
	}

	/**
	 * Handler for batch revalidate route. Expects an 'items' array in the request body.
	 *
	 * An item's `priority` is read by nothing: it is accepted, as v1 accepted
	 * it, and ignored.
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
			// An entry that is not an object has no `path` to read, so it goes on
			// as an item with none and is reported like one. Skipping it left the
			// body a result short, and a batch that lost an item answering 200 as
			// though everything sent had been accepted (#118).
			if (!is_array($it)) {
				$it = [];
			}
			$items[] = [
				'path' => isset($it['path']) ? sanitize_text_field($it['path']) : null,
			];
		}

		return $this->process_items($items);
	}

	/**
	 * Report each item as a path change, and answer for all of them.
	 *
	 * Each item: ['path' => string|null], the path or URL the caller sent.
	 *
	 * @param array $items
	 * @return WP_REST_Response
	 */
	private function process_items(array $items) {
		$results = [];
		// The status of each item that was not accepted, in the order they were
		// sent. A count of failures decided the status until #118, and a count
		// cannot say what a wholly-failed request should answer with.
		$failed = [];

		foreach ($items as $it) {
			// What the caller sent, reduced to the path from the domain root —
			// a permalink and the path it names are the same item.
			$uri = Change::uri_of($it['path']);

			if (null === $uri) {
				$results[] = [
					'path'    => $it['path'],
					'success' => false,
					'message' => 'Missing path',
				];
				$failed[] = self::ITEM_BAD_REQUEST;
				continue;
			}

			try {
				// An item is accepted when the pending changes hold it
				// afterwards, and not otherwise (ADR 0010). `report()` answers
				// three things: `true` for a change it holds, the refusal of an
				// unconfigured site as a `WP_Error`, and a bare `false` when the
				// site's own `nextjs_revalidate_change` filter dropped it.
				$accepted = $this->pendingChanges->report(Change::path($uri));
				if (is_wp_error($accepted)) {
					$results[] = [
						'path'    => $it['path'],
						'success' => false,
						'message' => $accepted->get_error_message(),
					];
					// The only `WP_Error` the pending changes produce is the
					// refusal of an unconfigured site. Anything else arriving
					// here is this site failing rather than declining, and is
					// answered as such rather than as a configuration an
					// operator could go and fix.
					$failed[] = 'not_configured' === $accepted->get_error_code()
						? self::ITEM_REFUSED
						: self::ITEM_FAILED;
				} elseif (true !== $accepted) {
					$results[] = [
						'path'    => $it['path'],
						'success' => false,
						// The site decided, not the caller: nothing about the
						// request would make a second attempt any different,
						// and the filter is where an operator goes to find out
						// why.
						'message' => 'Dropped by this site\'s nextjs_revalidate_change filter.',
					];
					$failed[] = self::ITEM_FAILED;
				} else {
					$results[] = [
						'path'    => $it['path'],
						'success' => true,
						// Always `true` now: the queue's raw answer this field
						// used to carry — `1` or `true` — has no v2 counterpart,
						// and a caller of 1.x reading it for truthiness reads
						// the same thing it always did.
						'data'    => true,
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
	 * accepted, and these callers have no other feedback channel to learn
	 * otherwise: they cannot see the log or the delivery (#118, #93).
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
