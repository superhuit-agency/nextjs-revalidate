<?php

namespace NextJsRevalidate;

// Exit if accessed directly.
defined( 'ABSPATH' ) or die( 'Cheatin&#8217; uh?' );

/**
 * A **change**: something that happened to one WordPress subject, described as
 * WordPress sees it and never as the front-end caches it.
 *
 * A change is a plain array — exactly the object it travels as in the request's
 * `changes`, with its `subject` and that subject's fields, as ADR 0033
 * tabulates them:
 *
 * | Subject     | Fields                                                  |
 * | ----------- | ------------------------------------------------------- |
 * | `post`      | `id`, `type`, `before: { uri } \| null`, `after: { uri } \| null` |
 * | `redirect`  | `uri`                                                   |
 * | `path`      | `uri`                                                   |
 * | `menu`      | `id`, `locations`                                       |
 * | `templates` | none                                                    |
 * | `all`       | none, or `type` and `taxonomies`                        |
 *
 * An array rather than an object on purpose: the `nextjs_revalidate_change`
 * filter hands every change to site code, and what site code alters is the
 * wire shape it will read in its own front-end route — not a class of this
 * plugin's it would have to learn first.
 *
 * This class only builds changes and answers questions about them. Holding them
 * is `PendingChanges`' job, and producing them is the job of whatever saw the
 * WordPress event.
 *
 * See `docs/adr/0033-the-plugin-reports-changes-not-tags.md`.
 */
final class Change {

	const POST      = 'post';
	const REDIRECT  = 'redirect';
	const PATH      = 'path';
	const MENU      = 'menu';
	const TEMPLATES = 'templates';
	const ALL       = 'all';

	/**
	 * A post, as the front-end sees it before and after.
	 *
	 * `null` on a side means the post is not on the front-end on that side:
	 * `before` for a publish, `after` for a post that left the front-end. A
	 * change with both sides `null` is not a change at all — see `is_void()`.
	 *
	 * @param int         $id         The post ID.
	 * @param string      $type       The post type.
	 * @param string|null $before_uri The path the post had before, from the domain root, or null.
	 * @param string|null $after_uri  The path the post has after, from the domain root, or null.
	 * @return array
	 */
	public static function post( int $id, string $type, ?string $before_uri, ?string $after_uri ): array {
		return [
			'subject' => self::POST,
			'id'      => $id,
			'type'    => $type,
			'before'  => ( null === $before_uri ) ? null : [ 'uri' => $before_uri ],
			'after'   => ( null === $after_uri )  ? null : [ 'uri' => $after_uri ],
		];
	}

	/**
	 * A redirect's source path — one change per path a redirect change affects.
	 *
	 * @param string $uri The source path, from the domain root.
	 * @return array
	 */
	public static function redirect( string $uri ): array {
		return [ 'subject' => self::REDIRECT, 'uri' => $uri ];
	}

	/**
	 * A path somebody named, without saying what is held there.
	 *
	 * @param string $uri The path, from the domain root.
	 * @return array
	 */
	public static function path( string $uri ): array {
		return [ 'subject' => self::PATH, 'uri' => $uri ];
	}

	/**
	 * The `uri` a URL or a path names: its path from the domain root.
	 *
	 * What the public API, the inbound REST routes and a scheduled purge are
	 * handed is whatever their caller had to hand — a permalink as often as a
	 * path — and both name one `uri`. The scheme, the host and the port are
	 * dropped, and with them the query string and the fragment, which are not
	 * part of a path; a path keeps its trailing slash, or its lack of one,
	 * exactly as it was given, because the front-end keys on the exact string.
	 *
	 * A URL is reduced rather than resolved against this site: its path is
	 * already from the domain root, directory and all, which is what `uri` is.
	 * A path is taken as from the domain root too, and given the leading slash
	 * it may have been sent without.
	 *
	 * @param mixed $url A URL, or a path.
	 * @return string|null The `uri`, or null when there is none to read: not a
	 *                     string, empty, or a URL too malformed to parse.
	 */
	public static function uri_of( $url ): ?string {

		if ( ! is_string( $url ) ) return null;

		$url = trim( $url );
		if ( '' === $url ) return null;

		$path = wp_parse_url( $url, PHP_URL_PATH );

		// `false` is a URL `parse_url()` could not read at all, which names
		// nothing; `null` is one that parsed and carries no path — a bare
		// domain, whose path is the root.
		if ( false === $path ) return null;

		return '/' . ltrim( (string) $path, '/' );
	}

	/**
	 * A menu, and the locations it is assigned to.
	 *
	 * @param int      $id        The menu's term ID.
	 * @param string[] $locations The theme locations it is assigned to.
	 * @return array
	 */
	public static function menu( int $id, array $locations ): array {
		return [ 'subject' => self::MENU, 'id' => $id, 'locations' => array_values( array_map( 'strval', $locations ) ) ];
	}

	/**
	 * The templates — the whole FSE snapshot, never naming which template.
	 *
	 * @return array
	 */
	public static function templates(): array {
		return [ 'subject' => self::TEMPLATES ];
	}

	/**
	 * Revalidate all, of the whole site or of one post type and the
	 * revalidatable taxonomies registered for it.
	 *
	 * @param string|null $type       The post type, or null for the whole site.
	 * @param string[]    $taxonomies The revalidatable taxonomies of that type.
	 * @return array
	 */
	public static function all( ?string $type = null, array $taxonomies = [] ): array {
		if ( null === $type ) return [ 'subject' => self::ALL ];

		return [ 'subject' => self::ALL, 'type' => $type, 'taxonomies' => array_values( array_map( 'strval', $taxonomies ) ) ];
	}

	/**
	 * Whether a value is a change at all: an array naming a subject.
	 *
	 * Deliberately no stricter than that. A front-end ignores a subject or a
	 * field it does not recognise (ADR 0033, rule 1), so a change the filter
	 * reshaped into something this plugin never produces is still one to send.
	 *
	 * @param mixed $value
	 * @return bool
	 */
	public static function is_change( $value ): bool {
		return is_array( $value )
			&& isset( $value['subject'] )
			&& is_string( $value['subject'] )
			&& '' !== $value['subject'];
	}

	/**
	 * Which change a change is, for merging: two changes with the same identity
	 * are one change in the pending changes.
	 *
	 * A post is identified by its ID, because a post has a before and an
	 * after, and two changes to it merge (`merge()`). Every other subject has
	 * no sides to keep, so its identity is the whole change: two identical
	 * changes collapse into one, and two that differ in anything are both kept.
	 *
	 * @param array $change
	 * @return string
	 */
	public static function identity( array $change ): string {

		if ( self::POST === $change['subject'] && isset( $change['id'] ) && is_scalar( $change['id'] ) ) {
			return self::POST . ':' . (string) $change['id'];
		}

		return (string) $change['subject'] . ':' . serialize( self::normalised( $change ) );
	}

	/**
	 * Two changes of the same identity, as one: the state before the first and
	 * the state after the last.
	 *
	 * Everything but `before` comes from the later change, which describes the
	 * subject as it now is. For a subject without sides, the two changes are
	 * identical and the later one is as good as the earlier.
	 *
	 * @param array $earlier The change already held.
	 * @param array $later   The change just produced.
	 * @return array
	 */
	public static function merge( array $earlier, array $later ): array {

		if ( self::POST !== $later['subject'] ) return $later;

		$merged = $later;
		$merged['before'] = array_key_exists( 'before', $earlier ) ? $earlier['before'] : null;

		return $merged;
	}

	/**
	 * Whether a change says nothing happened on the front-end.
	 *
	 * Only a post can: one that was not on the front-end before and is not
	 * after — a draft published and trashed in the same request, once merged —
	 * has no page to tell anybody about.
	 *
	 * @param array $change
	 * @return bool
	 */
	public static function is_void( array $change ): bool {
		return self::POST === $change['subject']
			&& array_key_exists( 'before', $change ) && null === $change['before']
			&& array_key_exists( 'after', $change )  && null === $change['after'];
	}

	/**
	 * A change with its keys in one order at every depth, so two changes that
	 * say the same thing have the same identity whichever order a filter built
	 * them in.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	private static function normalised( $value ) {
		if ( ! is_array( $value ) ) return $value;

		foreach ( $value as $key => $item ) $value[ $key ] = self::normalised( $item );

		// A list keeps its order — `locations` and `taxonomies` are lists — and
		// only a map is sorted.
		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) ksort( $value );

		return $value;
	}
}
