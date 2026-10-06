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
 * | `term`      | `id`, `taxonomy`, `before: { slug, uri } \| null`, `after: { slug, uri } \| null` |
 * | `redirect`  | `uri`                                                   |
 * | `path`      | `uri`                                                   |
 * | `menu`      | `id`, `locations`                                       |
 * | `templates` | none                                                    |
 * | `settings`  | none                                                    |
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
 * See `docs/adr/0033-the-plugin-reports-changes-not-tags.md`, and
 * `docs/adr/0040-a-term-reports-itself-and-a-post-the-terms-it-is-in.md` for
 * the `term` subject.
 */
final class Change {

	const POST      = 'post';
	const TERM      = 'term';
	const REDIRECT  = 'redirect';
	const PATH      = 'path';
	const MENU      = 'menu';
	const TEMPLATES = 'templates';
	const SETTINGS  = 'settings';
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
	 * A term, as the front-end sees it before and after.
	 *
	 * `null` on a side means the term is not there on that side: `before` for
	 * a term just created, `after` for one deleted. An edit that moves nothing
	 * — a name, a description — has two equal sides. See `term_side()` for
	 * what a side holds, and `is_void()` for a change with neither.
	 *
	 * @param int        $id       The term ID.
	 * @param string     $taxonomy The taxonomy.
	 * @param array|null $before   The term before, as `term_side()` builds it, or null.
	 * @param array|null $after    The term after, as `term_side()` builds it, or null.
	 * @return array
	 */
	public static function term( int $id, string $taxonomy, ?array $before, ?array $after ): array {
		return [
			'subject'  => self::TERM,
			'id'       => $id,
			'taxonomy' => $taxonomy,
			'before'   => $before,
			'after'    => $after,
		];
	}

	/**
	 * One side of a term change: its slug, and the URI of its archive.
	 *
	 * The slug is carried because a common tag scheme is
	 * `term:{taxonomy}:{slug}`: after a slug change, the front-end's entries
	 * are still tagged with the old one, which only `before.slug` reaches.
	 *
	 * @param string $slug The term's slug.
	 * @param string $uri  The path of its archive, from the domain root.
	 * @return array
	 */
	public static function term_side( string $slug, string $uri ): array {
		return [ 'slug' => $slug, 'uri' => $uri ];
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
	 * The site settings — whichever of them was saved, never naming which.
	 *
	 * See `docs/adr/0037-a-settings-change-reports-what-every-page-renders.md`.
	 *
	 * @return array
	 */
	public static function settings(): array {
		return [ 'subject' => self::SETTINGS ];
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
	 * A post is identified by its ID, and a term by its taxonomy and its ID,
	 * because each has a before and an after, and two changes to it merge
	 * (`merge()`). The site settings are
	 * identified by their subject alone: every site setting a request saves is
	 * the same change, even once a filter has reshaped some of them — the later
	 * one is kept. Every other subject has no sides to keep, so its identity is
	 * the whole change: two identical changes collapse into one, and two that
	 * differ in anything are both kept.
	 *
	 * @param array $change
	 * @return string
	 */
	public static function identity( array $change ): string {

		if ( self::POST === $change['subject'] && isset( $change['id'] ) && is_scalar( $change['id'] ) ) {
			return self::POST . ':' . (string) $change['id'];
		}

		if ( self::TERM === $change['subject'] && isset( $change['id'], $change['taxonomy'] ) && is_scalar( $change['id'] ) && is_scalar( $change['taxonomy'] ) ) {
			return self::TERM . ':' . (string) $change['taxonomy'] . ':' . (string) $change['id'];
		}

		if ( self::SETTINGS === $change['subject'] ) return self::SETTINGS;

		return (string) $change['subject'] . ':' . serialize( self::normalised( $change ) );
	}

	/**
	 * Two changes of the same identity, as one: the state before the first and
	 * the state after the last.
	 *
	 * Everything but `before` comes from the later change, which describes the
	 * subject as it now is — for a post and for a term, the two subjects with
	 * sides. For a subject without sides, the two changes are identical and
	 * the later one is as good as the earlier.
	 *
	 * @param array $earlier The change already held.
	 * @param array $later   The change just produced.
	 * @return array
	 */
	public static function merge( array $earlier, array $later ): array {

		if ( ! self::has_sides( $later ) ) return $later;

		$merged = $later;
		$merged['before'] = array_key_exists( 'before', $earlier ) ? $earlier['before'] : null;

		return $merged;
	}

	/**
	 * Whether a change says nothing happened on the front-end.
	 *
	 * Only a subject with sides can: a post that was not on the front-end
	 * before and is not after — a draft published and trashed in the same
	 * request, once merged — has no page to tell anybody about, and a term
	 * created and deleted in the same request has no archive.
	 *
	 * @param array $change
	 * @return bool
	 */
	public static function is_void( array $change ): bool {
		return self::has_sides( $change )
			&& array_key_exists( 'before', $change ) && null === $change['before']
			&& array_key_exists( 'after', $change )  && null === $change['after'];
	}

	/**
	 * Whether a change is of a subject with a `before` and an `after`.
	 *
	 * @param array $change
	 * @return bool
	 */
	private static function has_sides( array $change ): bool {
		return in_array( $change['subject'] ?? null, [ self::POST, self::TERM ], true );
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
