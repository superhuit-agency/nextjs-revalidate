<?php

namespace NextJsRevalidate;

use NextJsRevalidate\Abstracts\Base;
use NextJsRevalidate\Interfaces\Hookable;
use WP_Term;

// Exit if accessed directly.
defined( 'ABSPATH' ) or die( 'Cheatin&#8217; uh?' );

/**
 * A term's own lifecycle — created, edited, deleted — as a producer of `term`
 * changes.
 *
 * Each reports the term as the front-end sees it before and after: its slug
 * and the URI of its archive, `null` on the side where it is not there. Only
 * the terms of a **revalidatable taxonomy** are candidates; the others produce
 * nothing, as a post that is not revalidatable produces nothing.
 *
 * Three rules make a term's change more than the term:
 *
 *  - **Dependent terms.** A child term's archive URI is its parent's plus its
 *    own slug, so changing a term's slug or parent, or deleting it (WordPress
 *    then moves its children up a level), moves every descendant without
 *    editing any of them. Each one whose URI moved is reported as a `term`
 *    change of its own — the dependent post rule of ADR 0038, applied to terms.
 *  - **The default term.** Deleting a term moves the posts it held alone into
 *    the taxonomy's default term — *Uncategorized*. The default term's archive
 *    gained them, and nothing that shows the deleted term reaches it, so it is
 *    reported once, with equal sides.
 *  - **Never the members.** A term's change does not report the posts in it.
 *    Whatever shows a term is the front-end's to tag with it, so a rename or a
 *    delete is one change however many posts the term holds; and the per-post
 *    writes `wp_delete_term()` makes on its way are a consequence of the delete
 *    rather than changes of their own — see `is_deleting()`.
 *
 * See `docs/adr/0040-a-term-reports-itself-and-a-post-the-terms-it-is-in.md`.
 *
 * @property PendingChanges $pendingChanges The pending changes, from the composition root.
 * @property Revalidate     $revalidate     The post producer, which holds the taxonomy gate.
 */
class Terms extends Base implements Hookable {

	/**
	 * The terms whose edit is under way, as each stood before it: taxonomy =>
	 * term ID => its side, and the sides of the dependent terms the edit may
	 * move.
	 *
	 * Read on `edit_terms`, which `wp_update_term()` fires before it writes
	 * either of the term's rows — `edit_term` comes after both — and let go by
	 * that term's own `edited_term`, which reports it.
	 *
	 * @var array<string, array<int, array{before: array|null, dependents: array<int, array|null>}>>
	 */
	private array $edits = [];

	/**
	 * The terms whose delete is under way, as each stood before it: taxonomy =>
	 * term ID => its side, the sides of its descendants, and the terms the
	 * delete has moved posts into.
	 *
	 * Read on `pre_delete_term`, while the term's link can still be built and
	 * before its children are moved up a level, and let go by its own
	 * `delete_term`, which reports it.
	 *
	 * @var array<string, array<int, array{before: array|null, dependents: array<int, array|null>, defaults: array<int, int>}>>
	 */
	private array $deletes = [];

	public function register_hooks(): void {
		add_action( 'created_term',    [$this, 'on_term_created'], 10, 3 );

		add_action( 'edit_terms',      [$this, 'on_term_edit'], 10, 3 );
		add_action( 'edited_term',     [$this, 'on_term_edited'], 10, 3 );

		add_action( 'pre_delete_term', [$this, 'on_term_delete'], 10, 2 );
		add_action( 'set_object_terms', [$this, 'on_object_terms_set'], 10, 6 );
		add_action( 'delete_term',     [$this, 'on_term_deleted'], 10, 3 );
	}

	/**
	 * Whether a term of the given taxonomy is being deleted: the terms
	 * WordPress writes on posts now are the delete's, and not changes of
	 * their own.
	 *
	 * @param string $taxonomy
	 * @return bool
	 */
	public function is_deleting( $taxonomy ) {
		return ! empty( $this->deletes[ (string) $taxonomy ] );
	}

	/**
	 * A term was created: reported with no `before`.
	 *
	 * @param int    $term_id  The term ID.
	 * @param int    $tt_id    The term taxonomy ID.
	 * @param string $taxonomy The taxonomy.
	 * @return void
	 */
	public function on_term_created( $term_id, $tt_id, $taxonomy ) {
		if ( ! $this->is_candidate( $taxonomy ) ) return;

		$this->report( (int) $term_id, (string) $taxonomy, null, $this->side_of( $term_id, $taxonomy ) );
	}

	/**
	 * A term is about to be edited: read where it stands, and where each of its
	 * descendants stands when the edit may move them — see `$edits`.
	 *
	 * `wp_insert_term()` fires this hook too, for a term whose taxonomy row is
	 * not written yet. That term is not in the taxonomy, and its creation is
	 * reported on its own.
	 *
	 * @param int    $term_id  The term ID.
	 * @param string $taxonomy The taxonomy.
	 * @param mixed  $args     The term's fields as they are about to be written.
	 * @return void
	 */
	public function on_term_edit( $term_id, $taxonomy, $args = [] ) {
		if ( ! $this->is_candidate( $taxonomy ) ) return;

		$term = get_term( (int) $term_id, (string) $taxonomy );
		if ( ! $term instanceof WP_Term ) return;

		// A term edited again while its edit is under way — a plugin writing it
		// from its own hooks — keeps what was read before the first write.
		if ( isset( $this->edits[ $term->taxonomy ][ $term->term_id ] ) ) return;

		$this->edits[ $term->taxonomy ][ $term->term_id ] = [
			'before'     => $this->side_of( $term ),
			'dependents' => self::edit_moves( $term, is_array( $args ) ? $args : [] ) ? $this->sides_of_descendants( $term ) : [],
		];
	}

	/**
	 * A term was edited: report it from where it stood to where it is, then
	 * each of its descendants the edit moved.
	 *
	 * An edit that moves nothing has two equal sides, and is reported all the
	 * same: the name or the description the front-end shows has changed.
	 *
	 * @param int    $term_id  The term ID.
	 * @param int    $tt_id    The term taxonomy ID.
	 * @param string $taxonomy The taxonomy.
	 * @return void
	 */
	public function on_term_edited( $term_id, $tt_id, $taxonomy ) {
		$term_id  = (int) $term_id;
		$taxonomy = (string) $taxonomy;

		$edit = $this->edits[ $taxonomy ][ $term_id ] ?? null;
		unset( $this->edits[ $taxonomy ][ $term_id ] );
		if ( empty( $this->edits[ $taxonomy ] ) ) unset( $this->edits[ $taxonomy ] );

		if ( ! $this->is_candidate( $taxonomy ) ) return;

		$after = $this->side_of( $term_id, $taxonomy );

		// Nothing was read before the edit — the edit hook was skipped, or the
		// term was split from a shared one on the way — so it is reported as it
		// stands.
		$this->report( $term_id, $taxonomy, is_null( $edit ) ? $after : $edit['before'], $after );

		if ( ! is_null( $edit ) ) $this->report_dependents( $taxonomy, $edit['dependents'] );
	}

	/**
	 * A term is about to be deleted: read where it and each of its descendants
	 * stand — see `$deletes`.
	 *
	 * @param int    $term_id  The term ID.
	 * @param string $taxonomy The taxonomy.
	 * @return void
	 */
	public function on_term_delete( $term_id, $taxonomy ) {
		if ( ! $this->is_candidate( $taxonomy ) ) return;

		$term = get_term( (int) $term_id, (string) $taxonomy );
		if ( ! $term instanceof WP_Term ) return;

		$this->deletes[ $term->taxonomy ][ $term->term_id ] = [
			'before'     => $this->side_of( $term ),
			'dependents' => $this->sides_of_descendants( $term ),
			'defaults'   => [],
		];
	}

	/**
	 * A post's terms were set. While a term of that taxonomy is being deleted,
	 * a term the write added is the default term the delete moved the post
	 * into: `wp_delete_term()` adds no other.
	 *
	 * @param int    $object_id  The object whose terms were set.
	 * @param mixed  $terms      The terms, as they were passed.
	 * @param mixed  $tt_ids     The term taxonomy IDs the object now holds of the taxonomy.
	 * @param string $taxonomy   The taxonomy.
	 * @param bool   $append     Whether the terms were added to the old ones.
	 * @param mixed  $old_tt_ids The term taxonomy IDs it held before.
	 * @return void
	 */
	public function on_object_terms_set( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
		$taxonomy = (string) $taxonomy;
		if ( empty( $this->deletes[ $taxonomy ] ) ) return;

		$added = array_diff( array_map( 'intval', (array) $tt_ids ), array_map( 'intval', (array) $old_tt_ids ) );
		if ( empty( $added ) ) return;

		// The delete under way is the innermost one: the last read.
		end( $this->deletes[ $taxonomy ] );
		$deleting = key( $this->deletes[ $taxonomy ] );

		foreach ( $added as $tt_id ) {
			$term = get_term_by( 'term_taxonomy_id', $tt_id, $taxonomy );
			if ( $term instanceof WP_Term ) $this->deletes[ $taxonomy ][ $deleting ]['defaults'][ $term->term_id ] = $term->term_id;
		}
	}

	/**
	 * A term was deleted: report it with no `after`, then each descendant the
	 * delete moved up a level, then the default term it moved posts into.
	 *
	 * @param int    $term_id  The term ID.
	 * @param int    $tt_id    The term taxonomy ID.
	 * @param string $taxonomy The taxonomy.
	 * @return void
	 */
	public function on_term_deleted( $term_id, $tt_id, $taxonomy ) {
		$term_id  = (int) $term_id;
		$taxonomy = (string) $taxonomy;

		$delete = $this->deletes[ $taxonomy ][ $term_id ] ?? null;
		unset( $this->deletes[ $taxonomy ][ $term_id ] );
		if ( empty( $this->deletes[ $taxonomy ] ) ) unset( $this->deletes[ $taxonomy ] );

		// Nothing was read before the delete, so there is nothing to say what
		// was there: a term of a taxonomy that is not revalidatable.
		if ( is_null( $delete ) || ! $this->is_candidate( $taxonomy ) ) return;

		$this->report( $term_id, $taxonomy, $delete['before'], null );

		$this->report_dependents( $taxonomy, $delete['dependents'] );

		foreach ( $delete['defaults'] as $default_id ) {
			$side = $this->side_of( $default_id, $taxonomy );
			$this->report( $default_id, $taxonomy, $side, $side );
		}
	}

	/**
	 * Report each dependent term whose URI moved, from the side it had to the
	 * side it has.
	 *
	 * @param string                  $taxonomy   The taxonomy.
	 * @param array<int, array|null> $dependents Term ID => its side before.
	 * @return void
	 */
	private function report_dependents( $taxonomy, array $dependents ) {
		foreach ( $dependents as $dependent_id => $before ) {
			$after = $this->side_of( $dependent_id, $taxonomy );

			// Named, and not moved: this edit changed nothing about its archive.
			if ( ( $before['uri'] ?? null ) === ( $after['uri'] ?? null ) ) continue;

			$this->report( (int) $dependent_id, $taxonomy, $before, $after );
		}
	}

	/**
	 * Hand a term change to the pending changes, unless it says nothing.
	 *
	 * @param int        $term_id
	 * @param string     $taxonomy
	 * @param array|null $before
	 * @param array|null $after
	 * @return void
	 */
	private function report( $term_id, $taxonomy, ?array $before, ?array $after ) {
		if ( is_null( $before ) && is_null( $after ) ) return;

		$this->pendingChanges->report( Change::term( (int) $term_id, (string) $taxonomy, $before, $after ) );
	}

	/**
	 * Whether a taxonomy's terms are candidates for a change at all: it is a
	 * **revalidatable taxonomy**, the site's filter included.
	 *
	 * @param mixed $taxonomy
	 * @return bool
	 */
	private function is_candidate( $taxonomy ) {
		return is_string( $taxonomy ) && '' !== $taxonomy && $this->revalidate->should_revalidate_taxonomy( $taxonomy );
	}

	/**
	 * Whether an edit may move a term's archive, and with it its descendants':
	 * the taxonomy is hierarchical, and the edit writes another slug or another
	 * parent.
	 *
	 * @param WP_Term $term The term, as it is before the edit.
	 * @param array   $args The term's fields as they are about to be written.
	 * @return bool
	 */
	private static function edit_moves( WP_Term $term, array $args ) {
		if ( ! is_taxonomy_hierarchical( $term->taxonomy ) ) return false;

		// An empty slug is written as the name, sanitised — `wp_update_term()`'s
		// own rule.
		$slug = empty( $args['slug'] ) ? sanitize_title( (string) ( $args['name'] ?? $term->name ) ) : (string) $args['slug'];

		$parent = isset( $args['parent'] ) ? (int) $args['parent'] : (int) $term->parent;

		return $slug !== $term->slug || $parent !== (int) $term->parent;
	}

	/**
	 * The side of each descendant of a term, parents before their children.
	 *
	 * @param WP_Term $term
	 * @return array<int, array|null> Term ID => its side.
	 */
	private function sides_of_descendants( WP_Term $term ) {
		$sides = [];
		foreach ( $this->descendants( $term ) as $descendant_id ) $sides[ $descendant_id ] = $this->side_of( $descendant_id, $term->taxonomy );

		return $sides;
	}

	/**
	 * Every descendant of a term, parents before their children.
	 *
	 * Read in one query of the taxonomy rather than a query per level, and
	 * straight from the table so that no query filter — a multilingual plugin's
	 * language — narrows it, as `Revalidate::descendants()` reads a post's.
	 *
	 * @param WP_Term $term
	 * @return int[]
	 */
	private function descendants( WP_Term $term ) {
		global $wpdb;

		if ( ! is_taxonomy_hierarchical( $term->taxonomy ) ) return [];

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT term_id, parent FROM $wpdb->term_taxonomy WHERE taxonomy = %s AND parent <> 0 ORDER BY term_id",
			$term->taxonomy
		) );

		$children = [];
		foreach ( (array) $rows as $row ) $children[ (int) $row->parent ][] = (int) $row->term_id;

		$descendants = [];
		$stack       = array_reverse( $children[ $term->term_id ] ?? [] );

		while ( ! empty( $stack ) ) {
			$id = array_pop( $stack );

			// A loop in the tree, which a direct write can make, would
			// otherwise never end.
			if ( isset( $descendants[ $id ] ) || $term->term_id === $id ) continue;

			$descendants[ $id ] = $id;

			foreach ( array_reverse( $children[ $id ] ?? [] ) as $child_id ) $stack[] = $child_id;
		}

		return array_values( $descendants );
	}

	/**
	 * A term as the front-end sees it: its slug and the URI of its archive, or
	 * null when it has none — it does not exist, or WordPress builds no link
	 * for it.
	 *
	 * @param WP_Term|int $term     The term, or its ID.
	 * @param string      $taxonomy The taxonomy, for a term given by its ID.
	 * @return array|null As `Change::term_side()` builds it.
	 */
	private function side_of( $term, $taxonomy = '' ) {
		if ( ! $term instanceof WP_Term ) $term = get_term( (int) $term, (string) $taxonomy );
		if ( ! $term instanceof WP_Term ) return null;

		$link = get_term_link( $term );
		if ( is_wp_error( $link ) ) return null;

		$uri = Change::uri_of( $link );

		return is_null( $uri ) ? null : Change::term_side( $term->slug, $uri );
	}
}
