<?php

namespace NextJsRevalidate\Integrations;

use NextJsRevalidate\Abstracts\Base;
use NextJsRevalidate\Logger;

// Exit if accessed directly.
defined( 'ABSPATH' ) or die( 'Cheatin&#8217; uh?' );

/**
 * The Redirection integration: a redirect changes, its source path revalidates.
 *
 * A headless front-end resolves a redirect *inside* the cached page of the
 * source path — it asks WordPress "is there a redirect for this path?" while
 * rendering, and caches the answer with the page. So creating, editing,
 * deleting, enabling or disabling a redirect leaves that path answering as it
 * did before, until its cache entry expires on its own. Revalidating the source
 * path is what makes the front-end ask again.
 *
 * Supported, never required: when the Redirection plugin is not there, this
 * registers no hooks at all and the plugin behaves exactly as it did before.
 *
 * See `docs/adr/0006-redirect-changes-revalidate-the-source-path.md`.
 *
 * @property-read \NextJsRevalidate\RevalidateQueue $queue The revalidation
 *                queue of the site being served, reached through `Base`.
 */
class Redirection extends Base {

	/**
	 * Register the integration's hooks.
	 *
	 * Whether Redirection is there cannot be asked while the plugins are still
	 * being loaded — nothing decides which of the two loads first — so the
	 * question is deferred to `plugins_loaded`, by which time every plugin has
	 * declared itself. Registration assumes no admin context: redirects are
	 * saved through Redirection's own REST routes, where the admin side hooks
	 * never fire.
	 *
	 * @return void
	 */
	public function register_hooks() {

		if ( did_action( 'plugins_loaded' ) ) {
			$this->register_redirect_hooks();
			return;
		}

		add_action( 'plugins_loaded', [$this, 'register_redirect_hooks'] );
	}

	/**
	 * Listen to Redirection's own actions, if Redirection is what this site runs.
	 *
	 * Its post slug monitor needs nothing of its own: it creates its redirects
	 * through the same code path, which fires the same action.
	 *
	 * @return void
	 */
	public function register_redirect_hooks() {

		if ( ! $this->is_redirection_active() ) return;

		add_action( 'redirection_redirect_updated', [$this, 'on_redirect_updated'], 10, 2 );
		add_action( 'redirection_redirect_deleted', [$this, 'on_redirect_deleted'] );
		add_action( 'redirection_redirect_enabled', [$this, 'on_redirect_enabled'] );
		add_action( 'redirection_redirect_disabled', [$this, 'on_redirect_disabled'] );
	}

	/**
	 * Whether the Redirection plugin is running on this site.
	 *
	 * A runtime check and nothing more: this plugin declares no dependency on
	 * Redirection, and an absent integration is inert rather than fatal.
	 *
	 * @return bool
	 */
	private function is_redirection_active() {
		return defined( 'REDIRECTION_VERSION' );
	}

	/**
	 * A redirect was created or updated.
	 *
	 * The second argument is the redirect as it now is, always. The first is
	 * the redirect's id when it comes from Redirection's create call site, and
	 * — on versions before 5.9.0 — the *previous* state of the redirect when it
	 * comes from its update one. That is upstream's API rather than something
	 * to normalise away, so the argument is read for whatever it turns out to
	 * be.
	 *
	 * When the previous state is there it matters: a changed source leaves two
	 * paths stale — the one that should stop redirecting, and the one that
	 * should start — and the previous state is the only thing that knows the
	 * first of them.
	 *
	 * @param mixed $previous The redirect's id, or its state before the update.
	 * @param mixed $redirect The redirect, as it is now.
	 *
	 * @return void
	 */
	public function on_redirect_updated( $previous = null, $redirect = null ) {

		if ( $this->is_redirect( $previous ) ) $this->revalidate_source_of( $previous );

		$this->revalidate_source_of( $redirect );
	}

	/**
	 * A redirect was deleted. The front-end still redirects that path until it
	 * is asked to rebuild it.
	 *
	 * @param mixed $redirect The redirect that was deleted.
	 * @return void
	 */
	public function on_redirect_deleted( $redirect = null ) {
		$this->revalidate_source_of( $redirect );
	}

	/**
	 * A redirect was enabled. Only its id travels with the action, so the
	 * redirect is loaded to find its source.
	 *
	 * @param mixed $id The redirect's id.
	 * @return void
	 */
	public function on_redirect_enabled( $id = null ) {
		$this->revalidate_source_of( $this->get_redirect( $id ) );
	}

	/**
	 * A redirect was disabled.
	 *
	 * By the time this fires the redirect is already stored as disabled, so
	 * asking whether it is enabled would answer "no" for every one of them —
	 * that it stopped being enabled is precisely the change the front-end has
	 * not heard about yet. Only the source is asked about here.
	 *
	 * @param mixed $id The redirect's id.
	 * @return void
	 */
	public function on_redirect_disabled( $id = null ) {
		$this->revalidate_source_of( $this->get_redirect( $id ), false );
	}

	/**
	 * Enqueue a revalidation of the redirect's source path.
	 *
	 * @param mixed $redirect        The redirect whose source path is stale.
	 * @param bool  $require_enabled Optional. Whether the redirect has to be
	 *                               enabled to be a candidate. Default true.
	 *
	 * @return bool Whether a revalidation was enqueued.
	 */
	private function revalidate_source_of( $redirect, $require_enabled = true ) {

		if ( ! $this->is_redirect( $redirect ) ) return false;

		$source = $redirect->get_url();
		$id     = $redirect->get_id();

		// A regular expression source matches an unbounded set of paths, so
		// there is no single path to rebuild. It is not refused, it was never a
		// candidate — and it never escalates to a revalidate all, which would
		// turn a per rule checkbox into a site wide stampede.
		if ( $redirect->is_regex() ) {
			$this->log_skip( $id, $source, 'its source is a regular expression, which names no single path' );
			return false;
		}

		// A disabled redirect resolves to nothing, so the answer the front-end
		// holds for its source path is already the right one.
		if ( $require_enabled && ! $redirect->is_enabled() ) {
			$this->log_skip( $id, $source, 'it is disabled, so the front-end resolves nothing for it' );
			return false;
		}

		$path = $this->source_path( $source );
		if ( '' === $path ) {
			$this->log_skip( $id, $source, 'it names no path of this site to rebuild' );
			return false;
		}

		/**
		 * Filters whether the given redirect source path is revalidated.
		 *
		 * The escape hatch for a site whose front-end resolves redirects some
		 * other way: return false and the path is left alone.
		 *
		 * @param bool   $should_revalidate Whether to revalidate the source path.
		 * @param string $path              The source path, normalised.
		 * @param object $redirect          The redirect the path is the source of.
		 */
		if ( ! apply_filters( 'nextjs_revalidate_should_revalidate_redirect', true, $path, $redirect ) ) {
			$this->log_skip( $id, $source, 'a filter declined the revalidation of ' . $path );
			return false;
		}

		// A bulk operation fires these actions once per redirect, and several
		// redirects can share one source, so the same path arrives here many
		// times over. It costs one queue entry however often it does: the queue
		// enqueues a permalink it already holds exactly once. Remembering the
		// paths here instead would be a second answer to the same question, and
		// one that outlives the entry it describes — a path enqueued, drained,
		// and made stale again within one long running process would be skipped
		// on the strength of the revalidation that already happened.
		//
		// No `is_configured()` guard either: the queue refuses an unconfigured
		// site at the door and logs the permalink it refused.
		$is_added = $this->queue->add_item( $this->permalink_of( $path ) );

		return ( $is_added && !is_wp_error($is_added) );
	}

	/**
	 * The path a redirect source names, in the form the queue already holds
	 * post permalinks in.
	 *
	 * A source can be stored as a bare path, with a query string, or as the
	 * full url someone pasted; all three name one path, and the query string
	 * and the domain are dropped to find it. The site's trailing slash
	 * convention is applied last because the front-end keys on the exact path
	 * string while Redirection matches either form — an unnormalised path would
	 * enqueue a revalidation that silently matches nothing, and under ADR 0004
	 * nothing retries it.
	 *
	 * @param mixed $source The redirect's source, as Redirection stored it.
	 * @return string The path, or an empty string when the source names none.
	 */
	private function source_path( $source ) {

		if ( ! is_string($source) ) return '';

		$source = trim( $source );
		if ( '' === $source ) return '';

		$path = wp_parse_url( $source, PHP_URL_PATH );
		if ( ! is_string($path) || '' === $path ) return '';

		$path = user_trailingslashit( '/' . ltrim( $path, '/' ) );

		// The bare site root is not a source path this integration acts on.
		return '/' === $path ? '' : $path;
	}

	/**
	 * The permalink the queue holds for a path of this site.
	 *
	 * @param string $path The path to revalidate.
	 * @return string
	 */
	private function permalink_of( $path ) {
		return home_url( $path );
	}

	/**
	 * The redirect of the given id, when there is one.
	 *
	 * @param mixed $id The redirect's id.
	 * @return object|null
	 */
	private function get_redirect( $id ) {

		// Asking for the class is also what loads it: Redirection resolves its
		// own class names through an autoloader, and is midway through a
		// rename that keeps `Red_Item` reachable as an alias.
		if ( ! class_exists( 'Red_Item' ) ) return null;

		$redirect = call_user_func( ['Red_Item', 'get_by_id'], intval( $id ) );

		return $this->is_redirect( $redirect ) ? $redirect : null;
	}

	/**
	 * Whether the given value is a redirect this integration can read.
	 *
	 * Asked of the object rather than of its class on purpose. Redirection is
	 * moving its classes into a namespace, keeping the old names as aliases,
	 * and what this integration depends on is the handful of methods below
	 * rather than where the class ended up.
	 *
	 * @param mixed $value
	 * @return bool
	 */
	private function is_redirect( $value ) {

		if ( ! is_object($value) ) return false;

		foreach ( ['get_id', 'get_url', 'is_regex', 'is_enabled'] as $method ) {
			if ( ! method_exists( $value, $method ) ) return false;
		}

		return true;
	}

	/**
	 * Record a redirect whose source path was not revalidated, and why.
	 *
	 * The only place a skip is reported: the redirect is saved through
	 * Redirection's own REST routes from an admin that never reloads the page,
	 * so an admin notice would surface on some later, unrelated screen.
	 *
	 * @param int    $id     The redirect's id.
	 * @param mixed  $source The redirect's source.
	 * @param string $why    Why nothing was enqueued for it.
	 *
	 * @return void
	 */
	private function log_skip( $id, $source, $why ) {
		Logger::log(
			sprintf(
				'↪️ Redirect #%d not revalidated (source: %s) — %s',
				$id,
				is_string($source) ? $source : gettype($source),
				$why
			),
			__FILE__
		);
	}
}
