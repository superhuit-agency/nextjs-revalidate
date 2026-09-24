<?php

namespace NextJsRevalidate;

use NextJsRevalidate;
use NextJsRevalidate\Abstracts\Base;
use NextJsRevalidate\Interfaces\Hookable;

/**
 * Every setting is read as a property off this class, through `__get()`.
 * Declared here so static analysis can see the surface the options table below
 * defines at runtime, and so the pair cannot drift apart unnoticed: a setting
 * added to `OPTIONS` and forgotten here reads fine and analyses as undefined.
 *
 * @property string $domain                  The scheme, host and port of the front-end.
 * @property string $endpoint_path           The revalidate route, or '' for the default.
 * @property string $secret                  The shared secret every request carries, read trimmed.
 * @property array  $allow_revalidate_all    Post types offering "revalidate all", keyed by name.
 * @property array  $debug                   Debug switches, keyed by name.
 *
 * The plugin's own objects are reached through the same `__get()`, off the
 * base class rather than off the table below.
 *
 * @property RevalidateQueue $queue      The queue, read for the pending count this page shows, and
 *                                      asked to migrate its own table alongside the options.
 * @property Revalidate      $revalidate The gate, asked which post types this page offers switches for.
 */
class Settings extends Base implements Hookable {

	const PAGE_NAME = 'nextjs-revalidate-settings';

	const SETTINGS_GROUP = 'nextjs-revalidate-settings';

	const SETTINGS_DOMAIN_NAME = 'nextjs_revalidate-domain';
	const SETTINGS_ENDPOINT_PATH_NAME = 'nextjs_revalidate-endpoint_path';
	const SETTINGS_SECRET_NAME = 'nextjs_revalidate-secret';
	const SETTINGS_ALLOW_REVALIDATE_ALL_NAME = 'nextjs_revalidate-allow_revalidate_all';
	const SETTINGS_DEBUG = 'nextjs_revalidate-debug';

	/**
	 * The two settings v2 removed with the FSE snapshot's own endpoint (ADR 0034):
	 * its endpoint path, and the switch that gated it. No longer in the table
	 * below, so nothing reads, registers or seeds them; named only so that an
	 * uninstall takes a row a site still holds, and so the upgrade can delete it.
	 */
	const LEGACY_FSE_ENDPOINT_PATH_NAME = 'nextjs_revalidate-fse_endpoint_path';
	const LEGACY_REVALIDATE_ON_FSE_SAVE = 'nextjs_revalidate-revalidate-on-fse-save';

	/**
	 * The per-post-type "revalidate on menu save" switches, which v2 removed
	 * when a menu save became one `menu` change (ADR 0033): they existed only to
	 * bound the cost of a revalidate all per menu save. Named, like the two
	 * above, only so that an uninstall takes the row and the upgrade can delete it.
	 */
	const LEGACY_REVALIDATE_ON_MENU_SAVE = 'nextjs_revalidate-revalidate-on-menu-save';

	/**
	 * The settings this plugin reads, declared once.
	 *
	 * Keyed by the name the rest of the plugin reads, each entry pairs the
	 * option the setting is stored under with the empty value a read yields on
	 * a site holding no row for it — of the setting's own type, never false, so
	 * a read is always safe to iterate or compare — and with the callback every
	 * value is sanitised through before it is stored. A setting whose stored
	 * form has tightened since rows were first written also names the callback
	 * a read passes its value through, so an older row is used in the form a
	 * save would give it now.
	 *
	 * Authoritative for reads, registration, seeding and teardown alike, so a
	 * setting cannot be added to one of them and forgotten in another.
	 */
	private const OPTIONS = [
		'domain'                  => [ 'name' => self::SETTINGS_DOMAIN_NAME,               'empty' => '', 'sanitize' => [ self::class, 'sanitize_domain'        ] ],
		'endpoint_path'           => [ 'name' => self::SETTINGS_ENDPOINT_PATH_NAME,        'empty' => '', 'sanitize' => [ self::class, 'sanitize_path'          ] ],
		'secret'                  => [ 'name' => self::SETTINGS_SECRET_NAME,               'empty' => '', 'sanitize' => [ self::class, 'sanitize_secret'        ], 'read' => [ self::class, 'sanitize_secret' ] ],
		'allow_revalidate_all'    => [ 'name' => self::SETTINGS_ALLOW_REVALIDATE_ALL_NAME, 'empty' => [], 'sanitize' => [ self::class, 'sanitize_switch_set'    ] ],
		'debug'                   => [ 'name' => self::SETTINGS_DEBUG,                     'empty' => [], 'sanitize' => [ self::class, 'sanitize_switch_set'    ] ],
	];

	/**
	 * The path the endpoint is reached at on a Next.js app that has not been
	 * told otherwise.
	 *
	 * A default rather than a seeded value: an empty path field means "whatever
	 * this release ships", so an app that renames its route later is a one-field
	 * edit, and a standard install never has to look at it at all.
	 */
	const DEFAULT_ENDPOINT_PATH = '/api/revalidate';

	/**
	 * The single, fully-qualified revalidate URL this plugin stored until 1.7.0.
	 *
	 * Kept only so the migration that splits it into a domain and a path can
	 * name it, and so an uninstall takes it with the rest. Nothing reads it.
	 */
	const LEGACY_URL_OPTION_NAME = 'nextjs_revalidate-url';

	/**
	 * The migration ledger: the per-site record of the DB version, i.e. the
	 * version of the plugin whose data shape this site's options match.
	 *
	 * Deliberately outside the settings table above: nothing an operator
	 * supplies is kept here, and it is neither registered nor rendered.
	 */
	const DB_VERSION_OPTION_NAME = 'nextjs_revalidate-db_version';

	/**
	 * The swept version: the network-scoped record of the release every site of
	 * the network was last asked to migrate at.
	 *
	 * Stored through the site-option API rather than the per-site one, because
	 * it is the network's own state and not any one site's — the only piece of
	 * this plugin's state that is. It answers a different question from the
	 * ledger above: the ledger says which migrations a site has been through,
	 * this says only whether every site has been asked this release.
	 */
	const SWEPT_VERSION_OPTION_NAME = 'nextjs_revalidate-swept_version';

	/**
	 * Fingerprints used to backfill the ledger on sites which predate it.
	 *
	 * Each entry maps a DB version to the legacy options a site still holding
	 * any of them stopped at. Ordered oldest first: a site left behind by
	 * several releases holds several of these, and the oldest one wins.
	 */
	private const DB_VERSION_FINGERPRINTS = [
		// Options renamed by 1.5.0, when purge became revalidate.
		'1.4.0' => [ 'nextjs_revalidate-allow_purge_all', 'nextjs-revalidate-purge_all' ],
		// Options dropped by 1.6.0, when the queue moved to its own table.
		'1.5.0' => [ 'nextjs-revalidate-queue', 'nextjs-revalidate-revalidate_all' ],
	];

	public function register_hooks(): void {
		add_action( 'admin_menu', [$this, 'add_page'] );
		add_action( 'admin_init', [$this, 'register_fields'] );

		add_action( 'admin_init', [$this, 'migrate_db'] );
		add_action( 'admin_init', [$this, 'sweep_migrations'] );

		add_action( 'admin_notices', [$this, 'unconfigured_notice'] );

		// The declined sweep is the network's business, and a super admin
		// reads network notices in the network admin — where `admin_notices`
		// does not fire at all.
		add_action( 'admin_notices', [$this, 'sweep_declined_notice'] );
		add_action( 'network_admin_notices', [$this, 'sweep_declined_notice'] );
	}

	public function __get( $name ) {

		if ( !isset(self::OPTIONS[$name]) ) return parent::__get( $name );

		$empty = self::OPTIONS[$name]['empty'];
		$value = get_option( self::OPTIONS[$name]['name'], $empty );

		// A site can hold a row whose value does not match the setting's type:
		// a row stored as false, or a set-shaped setting saved by a form which
		// submitted none of its switches. For a read, such a row means exactly
		// what an absent one means.
		if ( is_array($empty) ) return is_array($value) ? $value : $empty;

		if ( $value === false ) return $empty;

		// Read the way it is now saved, so a row stored before saving tightened
		// it is used in its current form too — the secret, trimmed. Here rather
		// than at each use: the outbound URL of each endpoint, the inbound REST
		// check and the transport's redaction all read it, and all of them must
		// agree.
		if ( isset(self::OPTIONS[$name]['read']) ) return call_user_func( self::OPTIONS[$name]['read'], $value );

		return $value;
	}

	/**
	 * Whether a setting reads as set, for `isset()` and `empty()`.
	 *
	 * PHP routes both of those to `__isset()` rather than `__get()`, so without
	 * this every `empty( $this->some_setting )` answers *true* on a configured
	 * site — silently, and only for code written in the obvious way. The trap
	 * is what `missing_settings()` is dodging by reading each setting into a
	 * local first, and it has already cost one debugging session.
	 *
	 * A setting reads as set exactly when its value is not the empty value the
	 * setting's type falls back to, so `empty()` here agrees with `empty()` on
	 * the value `__get()` would have answered.
	 *
	 * @param string $name
	 * @return bool
	 */
	public function __isset( $name ) {

		if ( !isset(self::OPTIONS[$name]) ) return false;

		// `__get()` by name, not `$this->$name`: PHP routes that back here,
		// and its guard against re-entering a magic method already in progress
		// would answer for an undefined property instead of reading the option.
		$value = $this->__get( $name );

		return !empty( $value );
	}

	/**
	 * Add page
	 */
	public function add_page() {
		add_options_page(
			__( 'Next.js revalidate settings', 'nextjs-revalidate'),
			__( 'Next.js revalidate', 'nextjs-revalidate' ),
			'manage_options',
			self::PAGE_NAME,
			[$this, 'render_page']
		);
	}

	/**
	 * Render the page
	 */
	public function render_page() {

		$queue = $this->queue->get_queue();
		$nb_in_queue = count($queue);

		$sections = [
			[ 'id' => 'api',            'title' => __('Next.js API', 'nextjs-revalidate')     ],
			[ 'id' => 'allow_all_opts', 'title' => __('Allow purge all', 'nextjs-revalidate') ],
			[ 'id' => 'debug',          'title' => __('Debug', 'nextjs-revalidate')           ],
			[ 'id' => 'queue',          'title' => __('Queue', 'nextjs-revalidate') . sprintf('<span class="badge">%s</span>', $nb_in_queue) ],
			[ 'id' => 'probe',          'title' => __('Probe', 'nextjs-revalidate')          ],
		];
		?>
		<div class="wrap njr-settings">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<div class="njr-settings__tab-list" role="tablist" aria-label="<?php _e( 'NextJS-Revalidate settings tabs', 'nextjs-revalidate' ); ?>">
				<nav>
					<?php foreach($sections as $i => $section): ?>
						<button
							role="tab"
							type="button"
							class="njr-settings__tab"
							tabindex="<?php echo $i === 0 ? '0' : '-1' ?>"
							aria-selected="<?php echo $i === 0 ? 'true' : 'false' ?>"
							id="<?php printf('tab-%s', $section['id']) ?>"
							aria-controls="<?php printf('tab-panel--%s', $section['id']) ?>"
						>
							<?php echo $section['title'] ?>
						</button>
					<?php endforeach ?>
				</nav>
			</div>
			<form class="njr-settings__form" method="post" action="options.php">
				<?php
					// This prints out all hidden setting fields
					settings_fields( self::SETTINGS_GROUP );
					// Prints all registered section for this page
					do_settings_sections( self::PAGE_NAME );
					?>
					<section id="tab-panel--queue" role="tabpanel" tabindex="-1" aria-labelledby="tab-queue" aria-hidden="true">
						<h2><?php _e('Purge queue', 'nextjs-revalidate'); ?></h2>
						<p>
							<strong><?php printf( _n( '%d URL waiting to be purged', '%d URLs waiting to be purged', $nb_in_queue, 'nextjs-revalidate'), $nb_in_queue ); ?></strong>
							<?php if ( $nb_in_queue > 0 ) submit_button( "Reset queue (stop purging URLs in the queue)", 'secondary', 'revalidate_reset_queue', false ); ?>
						</p>
						<table>
							<thead>
								<th><?php _e('Id', 'nextjs-revalidate'); ?></th>
								<th><?php _e('Priority', 'nextjs-revalidate'); ?></th>
								<th><?php _e('URL', 'nextjs-revalidate'); ?></th>
							</thead>
							<tbody>
								<?php foreach ($queue as $item): ?>
								<tr>
									<td><?php echo $item->id; ?></td>
									<td><?php echo $item->priority; ?></td>
									<td><?php echo $item->permalink; ?></td>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</section>

					<?php submit_button(); ?>
			</form>
			<?php
				// Its own form, beside the settings one rather than inside it:
				// a probe answers about the *saved* settings, and a button
				// intercepted from the settings form’s own submit would have
				// to answer before that form was saved — silently dropping
				// whatever the operator had typed into it.
				Probe::render_panel();
			?>
		</div>
		<?php
	}

	/**
	 * Register and add settings
	 */
	public function register_fields() {
		$this->register_settings();

		// API section settings

		add_settings_section(
			'nextjs-revalidate-section',
			__('Next.js API config', 'nextjs-revalidate'),
			null,
			self::PAGE_NAME,
			[
				'before_section' => '<section aria-hidden="false" id="tab-panel--api" role="tabpanel" tabindex="-1" aria-labelledby="tab-api">',
				'after_section'  => '</section>',
			]
		);

		add_settings_field(
			'nextjs_domain',
			__('Revalidate domain', 'nextjs-revalidate'),
			function ($args) {
				printf(
					'<input type="url" id="%1$s" name="%1$s" value="%2$s" placeholder="%3$s" class="regular-text code" />',
					self::SETTINGS_DOMAIN_NAME,
					esc_attr( $this->domain ),
					'https://example.com'
				);
			},
			self::PAGE_NAME,
			'nextjs-revalidate-section'
		);

		// The path is optional, and the placeholder is how an operator knows
		// it: a field left empty is the default shown in it, not a blank.
		add_settings_field(
			'nextjs_path',
			__('Revalidate path', 'nextjs-revalidate'),
			function ($args) {
				printf(
					'<input type="text" id="%1$s" name="%1$s" value="%2$s" placeholder="%3$s" class="regular-text code" /><p class="description">%4$s</p>',
					self::SETTINGS_ENDPOINT_PATH_NAME,
					esc_attr( $this->endpoint_path ),
					esc_attr( self::DEFAULT_ENDPOINT_PATH ),
					esc_html__('Optional. The route every revalidation is sent to on the front-end. Leave empty for the default.', 'nextjs-revalidate')
				);
			},
			self::PAGE_NAME,
			'nextjs-revalidate-section'
		);

		add_settings_field(
			'revalidate-secret',
			__('Revalidate Secret', 'nextjs-revalidate'),
			function ($args) {
				printf(
					'<input type="password" id="%1$s" name="%1$s" value="%2$s" class="regular-text code" />',
					self::SETTINGS_SECRET_NAME,
					esc_attr( $this->secret )
				);
			},
			self::PAGE_NAME,
			'nextjs-revalidate-section'
		);


		// Revalidate All section settings
		add_settings_section(
			'nextjs-revalidate-section-allow_revalidate_all',
			__('Allow purge all options', 'nextjs-revalidate'),
			function() {
				printf( '<p>%s</p>', __('Define which post type has the option to have all posts purged in the admin bar.', 'nextjs-revalidate') );
			},
			self::PAGE_NAME,
			[
				'before_section' => '<section aria-hidden="true" id="tab-panel--allow_all_opts" role="tabpanel" tabindex="-1" aria-labelledby="tab-allow_all_opts">',
				'after_section'  => '</section>',
			]
		);

		// The post types this plugin offers its actions for, rather than the
		// `public` ones this list asked for until #53 — a switch offered for a
		// type the gate declines every post of is one an operator can turn on
		// to no effect. A type this no longer lists keeps whatever row it has
		// in the option until the next save of this page, which posts only the
		// switches it rendered. See `Revalidate::offered_post_types()`.
		$post_types = $this->revalidate->offered_post_types();
		foreach ($post_types as $post_type) {
			$post_type_object = get_post_type_object( $post_type );
			$id = "allow_revalidate_all-$post_type";
			add_settings_field(
				$id,
				$post_type_object->labels->name,
				'Kuuak\WordPressSettingFields\Fields::switch',
				self::PAGE_NAME,
				'nextjs-revalidate-section-allow_revalidate_all',
				[
					'label_for' => $id,
					'id'        => $id,
					'name'      => self::SETTINGS_ALLOW_REVALIDATE_ALL_NAME."[$post_type]",
					'checked'   => $this->allow_revalidate_all[$post_type] ?? false,
				]
			);
		}

		$id = "allow_revalidate_all-all";
		add_settings_field(
			$id,
			__('All post types', 'nextjs-revalidate'),
			'Kuuak\WordPressSettingFields\Fields::switch',
			self::PAGE_NAME,
			'nextjs-revalidate-section-allow_revalidate_all',
			[
				'label_for' => $id,
				'id'        => $id,
				'name'      => self::SETTINGS_ALLOW_REVALIDATE_ALL_NAME.'[all]',
				'checked'   => $this->allow_revalidate_all['all'] ?? false,
				'help'      => __('Warning: according to the number of post types & posts for each post type this action can be very slow.', 'nextjs-revalidate'),
			]
		);


		// Debug section settings
		add_settings_section(
			'nextjs-revalidate-section-debug',
			__('Debug options', 'nextjs-revalidate'),
			function() {
				printf( '<p>%s</p>', __('Some configuration for easier debug.', 'nextjs-revalidate') );
			},
			self::PAGE_NAME,
			[
				'before_section' => '<section aria-hidden="true" id="tab-panel--debug" role="tabpanel" tabindex="-1" aria-labelledby="tab-debug">',
				'after_section'  => '</section>',
			]
		);

		$id = "enable-logs";
		add_settings_field(
			$id,
			__('Enable logs', 'nextjs-revalidate'),
			'Kuuak\WordPressSettingFields\Fields::switch',
			self::PAGE_NAME,
			'nextjs-revalidate-section-debug',
			[
				'label_for' => $id,
				'id'        => $id,
				'name'      => self::SETTINGS_DEBUG.'[enable-logs]',
				'checked'   => $this->debug['enable-logs'] ?? false,
				'help'      => sprintf(
					__('Logs will be saved to file located in <code>%s</code>', 'nextjs-revalidate'),
					Logger::reported_location()
				),
			]
		);
	}

	/**
	 * Register every setting, with the callback it is sanitised through.
	 *
	 * WordPress attaches that callback to `sanitize_option_{$name}`, which
	 * `add_option()` and `update_option()` apply to every write and not only to
	 * a save of the settings screen. So it holds for the plugin's own writes as
	 * well — the seeding in `define_settings()` and the migrations in
	 * `migrate_db()`, which this runs before on `admin_init` — and each callback
	 * stores whatever those writes stored before it existed.
	 *
	 * Each callback also answers its own output unchanged, because WordPress can
	 * run one twice on a single save: `update_option()` on a site holding no row
	 * falls through to `add_option()`, which sanitises again (core #21989).
	 *
	 * @return void
	 */
	public function register_settings() {
		foreach ( self::OPTIONS as $setting ) {
			register_setting( self::SETTINGS_GROUP, $setting['name'], [ 'sanitize_callback' => $setting['sanitize'] ] );
		}
	}

	/**
	 * The revalidate domain, as it is stored.
	 *
	 * Trimmed, with any query or fragment dropped, and otherwise as typed: a
	 * port, a subdirectory and basic-auth credentials all belong to the domain
	 * (ADR 0017). A value that is not an `http` or `https` URL with a host is
	 * refused rather than stored, with an error on the settings screen, and the
	 * domain the site held before is kept — so a first bad entry leaves the site
	 * unconfigured, and the notice saying so, rather than configured with a
	 * domain every revalidation would fail against. `esc_url_raw()` is not the
	 * rule on purpose: it strips what it cannot use, and a domain stripped to
	 * `''` would unconfigure a site with nothing on screen to say why.
	 *
	 * @param mixed $value What was submitted.
	 * @return mixed What is stored.
	 */
	public static function sanitize_domain( $value ) {
		$domain = self::normalise_domain( $value );
		if ( $domain !== null ) return $domain;

		// One error however many times WordPress runs this on the save.
		if ( empty( get_settings_errors( self::SETTINGS_DOMAIN_NAME ) ) ) {
			add_settings_error(
				self::SETTINGS_DOMAIN_NAME,
				'invalid_domain',
				__( 'The revalidate domain was not saved: it must be a web address starting with http:// or https://, such as https://example.com.', 'nextjs-revalidate' )
			);
		}

		// The domain held before, unchanged — which is also what makes
		// `update_option()` skip the write.
		return (string) get_option( self::SETTINGS_DOMAIN_NAME, '' );
	}

	/**
	 * A revalidate domain in the form it is stored, or null when it is not one.
	 *
	 * The rule `sanitize_domain()` applies to a save, and `split_legacy_url()`
	 * to what it splits out of the legacy URL, so the migration can never write
	 * a domain the rule would refuse.
	 *
	 * @param mixed $value
	 * @return string|null `''` for a value holding nothing but whitespace.
	 */
	private static function normalise_domain( $value ) {

		// Something was submitted, and it is not a domain. `null` is not among
		// them: it is what `options.php` saves for a field the form left out.
		if ( is_array($value) || is_object($value) ) return null;

		$domain = self::strip_query_and_fragment( $value );
		if ( $domain === '' ) return '';

		$parts = wp_parse_url( $domain );
		if ( ! is_array($parts) || empty($parts['host']) ) return null;

		$scheme = strtolower( $parts['scheme'] ?? '' );
		if ( $scheme !== 'http' && $scheme !== 'https' ) return null;

		return $domain;
	}

	/**
	 * An endpoint path, as it is stored.
	 *
	 * Trimmed, with any query or fragment dropped — the shape ADR 0017's
	 * migration gives the path it splits off — and otherwise as typed. Slashes
	 * are left alone: composition already joins the halves with exactly one.
	 *
	 * @param mixed $value What was submitted.
	 * @return string What is stored.
	 */
	public static function sanitize_path( $value ) {
		return self::strip_query_and_fragment( $value );
	}

	/**
	 * The secret, as it is stored: trimmed, and nothing else.
	 *
	 * An opaque string whose character set the operator does not control, so
	 * nothing inside it is removed — `sanitize_text_field()` would strip tags,
	 * octets and line breaks out of a value that is only ever compared.
	 *
	 * @param mixed $value What was submitted.
	 * @return string What is stored.
	 */
	public static function sanitize_secret( $value ) {
		return is_scalar($value) ? trim( (string) $value ) : '';
	}

	/**
	 * A set of switches, as it is stored: a map from key to `'on'`.
	 *
	 * An entry holding anything but `'on'` is dropped, and so is anything that
	 * is not a map at all. The keys are not checked against the post types
	 * registered now, because a post type that registers later or only on some
	 * requests would lose its switch on every save made without it.
	 *
	 * @param mixed $value What was submitted.
	 * @return array What is stored.
	 */
	public static function sanitize_switch_set( $value ) {
		if ( ! is_array($value) ) return [];

		return array_filter( $value, function ( $state ) { return $state === 'on'; } );
	}

	/**
	 * A scalar value cut at its first `?` or `#`, then trimmed.
	 *
	 * A rule rather than ADR 0017's construction on purpose: a typed value has
	 * no parts to rebuild it from until it is known to be a URL, and a path
	 * never is one. Both characters end a URL's path, so nothing either can
	 * begin belongs to a domain or a path.
	 *
	 * @param mixed $value
	 * @return string `''` for anything that is not a scalar.
	 */
	private static function strip_query_and_fragment( $value ) {
		if ( ! is_scalar($value) ) return '';

		return trim( (string) preg_replace( '/[?#].*$/s', '', (string) $value ) );
	}

	/**
	 * Delete every setting of the site currently being served.
	 *
	 * @return void
	 */
	public static function delete_settings() {
		foreach ( self::OPTIONS as $setting ) {
			delete_option( $setting['name'] );
		}

		// The settings v2 removed, on a site whose upgrade has not yet
		// deleted them.
		delete_option( self::LEGACY_FSE_ENDPOINT_PATH_NAME );
		delete_option( self::LEGACY_REVALIDATE_ON_FSE_SAVE );
		delete_option( self::LEGACY_REVALIDATE_ON_MENU_SAVE );

		// The URL the settings above were split out of, on a site upgraded
		// before it was ever visited in the admin: the migration that consumes
		// it may not have run, and it is this site's data either way.
		delete_option( self::LEGACY_URL_OPTION_NAME );

		// The migration ledger goes with the data it describes: left behind, a
		// later reinstall would read it, believe this site's options already
		// have the running code's shape, and skip migrations that must run.
		delete_option( self::DB_VERSION_OPTION_NAME );

		// The log's filename suffix is internal state too, and goes with it.
		// The log itself is left where it is: it is the operator's evidence.
		delete_option( Logger::SUFFIX_OPTION_NAME );
	}

	/**
	 * Register every setting of the site currently being served,
	 * holding its empty value until an operator supplies one.
	 *
	 * Until v2 the FSE gate was the one exception, seeded `on` for a new
	 * install only. It went with the FSE snapshot's own endpoint (ADR 0034),
	 * and every setting now starts empty.
	 *
	 * @return void
	 */
	public function define_settings() {
		foreach ( self::OPTIONS as $setting ) {
			add_option( $setting['name'], $setting['empty'] );
		}
	}

	/**
	 * The **endpoint URL**: the site's domain and its endpoint path, composed at
	 * the moment a revalidation is sent. Every revalidation goes to it.
	 *
	 * The two halves are stored separately so that a route named anything but
	 * the default is one field to edit rather than something derived from the
	 * domain by string surgery (ADR 0017). There is one path from v2; v1 kept
	 * a second for the FSE snapshot.
	 *
	 * Exactly one slash joins them, whichever way the operator typed each half.
	 * A path holding nothing but slashes is a field left empty rather than a
	 * request to revalidate against the domain root, which no app serves.
	 *
	 * Answers the empty string on a site with no domain, rather than a bare
	 * path: nothing composes an endpoint without an `is_configured()` guard
	 * first, and this is what keeps a mistake there from becoming a request to
	 * a relative URL.
	 *
	 * Both halves are trimmed first. Both are trimmed on save as well, but a row
	 * stored before that was not, and a domain pasted in with a trailing space
	 * composes a URL the transport rejects — a revalidation that fails for a
	 * reason nothing on screen names. Trimming here covers those rows.
	 *
	 * @return string Empty on a site holding no domain.
	 */
	public function endpoint_url() {
		$domain = untrailingslashit( trim( (string) $this->domain ) );
		if ( empty($domain) ) return '';

		$path = untrailingslashit( trim( (string) $this->endpoint_path ) );
		if ( empty($path) ) $path = self::DEFAULT_ENDPOINT_PATH;

		return $domain . '/' . ltrim( $path, '/' );
	}

	/**
	 * The settings a revalidation cannot be delivered without,
	 * which the site has no value for.
	 *
	 * The path is deliberately not among them: it falls back to a default,
	 * so a standard install configures a domain and a secret and nothing else.
	 *
	 * A field holding nothing but whitespace is a field nobody filled in. It has
	 * to read as missing here, because `endpoint_url()` trims before composing
	 * and would answer nothing for it — a site reported as configured which
	 * cannot address its front-end is exactly the silence the notice exists for.
	 *
	 * @return string[] Any of 'domain' and 'secret'. Empty on a configured site.
	 */
	public function missing_settings() {
		$missing = [];

		$domain = trim( (string) $this->domain );
		if ( empty($domain) ) $missing[] = 'domain';

		$secret = trim( (string) $this->secret );
		if ( empty($secret) ) $missing[] = 'secret';

		return $missing;
	}

	/**
	 * Returns if the plugin is correctly configured.
	 * Half-configured is unconfigured.
	 *
	 * @return boolean
	 */
	public function is_configured() {
		return empty( $this->missing_settings() );
	}

	/**
	 * The refusal an unconfigured site answers every revalidation with.
	 *
	 * Declared once because it is raised from two places — `add_item()` refuses
	 * at enqueue time, `purge()` guards the delivery it is unreachable from —
	 * and a refusal that reads differently depending on which guard caught it
	 * is a refusal an operator has to learn twice. The code is the contract:
	 * `RestApi::process_items` reports it per item, and the drain branches on it
	 * to write ⛔ rather than ❌.
	 *
	 * @return \WP_Error Always `not_configured`.
	 */
	public function not_configured_error() {
		return new \WP_Error(
			'not_configured',
			__( 'Next.js revalidate is not configured for this site: the revalidate domain and secret are both required before anything can be revalidated.', 'nextjs-revalidate' )
		);
	}

	/**
	 * Whether this site's unconfigured notice speaks to whoever is reading.
	 *
	 * Decided once, here, and consulted by both notices that can carry it:
	 * `unconfigured_notice()` itself, and `FailureWindow::get_degraded_notice()`
	 * where it speaks in the unconfigured notice's place on a block editor
	 * screen. A site that asked for silence must not be told the same thing by
	 * the other notice instead.
	 *
	 * The filter is asked last, once the site and the reader have both
	 * qualified: a configured site, or a reader outside the audience, never
	 * reaches it. It is code rather than a setting on purpose — a stored "hide
	 * this" is the dismissible notice ADR 0015 rejected, under another name. It
	 * silences the notice only: the site still refuses every revalidation, and
	 * still logs each refusal.
	 *
	 * @return bool
	 */
	public function shows_unconfigured_notice() {
		if ( $this->is_configured() ) return false;

		// Only bother people whose work is being silently dropped,
		// or who can do something about it.
		if ( !current_user_can( 'manage_options' ) && !current_user_can( 'edit_posts' ) ) return false;

		return (bool) apply_filters( 'nextjs_revalidate_show_unconfigured_notice', true, $this->missing_settings() );
	}

	/**
	 * Tell whoever is looking at the admin that this site revalidates nothing.
	 *
	 * Not dismissible on purpose: an unconfigured site accepts edits and looks
	 * like it works, and a notice that can be dismissed for good recreates
	 * exactly the silence this is here to break.
	 */
	public function unconfigured_notice() {
		if ( !$this->shows_unconfigured_notice() ) return;

		$can_configure = current_user_can( 'manage_options' );

		$missing = $this->missing_settings();
		if ( count($missing) > 1 )                     $what = __( 'its revalidate domain and secret are missing', 'nextjs-revalidate' );
		else if ( in_array('domain', $missing, true) ) $what = __( 'its revalidate domain is missing', 'nextjs-revalidate' );
		else                                           $what = __( 'its secret is missing', 'nextjs-revalidate' );

		$message = esc_html(
			sprintf(
				/* translators: %s: which of the two required settings are missing. */
				__( 'Next.js revalidate is not configured for this site — %s. Content is still saved, but every revalidation is refused: the front-end is never asked to rebuild its pages.', 'nextjs-revalidate' ),
				$what
			)
		);

		$on_settings_page = ( isset($_GET['page']) && $_GET['page'] === self::PAGE_NAME );

		if ( $can_configure && !$on_settings_page ) {
			$message .= sprintf(
				' <a href="%s">%s</a>',
				esc_url( admin_url( 'options-general.php?page=' . self::PAGE_NAME ) ),
				esc_html__( 'Configure Next.js revalidate', 'nextjs-revalidate' )
			);
		}
		else if ( !$can_configure ) {
			$message .= ' ' . esc_html__( 'Please contact a site administrator.', 'nextjs-revalidate' );
		}

		printf(
			'<div class="notice notice-warning nextjs-revalidate-unconfigured__notice"><p>%s</p></div>',
			$message
		);
	}

	/**
	 * Tell the network admin that the migration sweep declined, and why.
	 *
	 * A sweep reaches every site or it does not start, so on a large network
	 * this one declines and nothing stamps the swept version — which is what
	 * makes this condition true, and keeps it true for as long as the network
	 * stays over core's threshold. Nothing here can know that the sites were
	 * migrated one admin visit at a time, so the notice does not stop of its
	 * own accord; saying nothing instead would leave a network running new code
	 * over old data with no sign of it anywhere, which is the silence this
	 * whole change is against.
	 *
	 * The condition is recomputed here rather than handed over by
	 * `sweep_migrations()`, so the notice states something true on its own
	 * terms instead of describing a flag an earlier hook happened to set.
	 */
	public function sweep_declined_notice() {

		// First, and in this order: `wp_is_large_network()` and
		// `get_blog_count()` live in `ms-functions.php`, which a single install
		// never loads. The multisite test is inside `network_sweep_is_due()`.
		if ( !$this->network_sweep_is_due() ) return;
		if ( !wp_is_large_network( 'sites' ) ) return;

		// Nobody but a super admin can act on this, and nobody but a super
		// admin can even see the sites it is about.
		if ( !current_user_can( 'manage_network' ) ) return;

		printf(
			'<div class="notice notice-warning nextjs-revalidate-sweep-declined__notice"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: number of sites on the network. */
					__( 'Next.js revalidate cannot migrate the %s sites of this network in a single request, and it does not migrate some of them and leave the rest running new code over old data. Open the admin of each site once instead — a site migrates itself the first time somebody does.', 'nextjs-revalidate' ),
					number_format_i18n( get_blog_count() )
				)
			)
		);
	}

	/**
	 * Migrate this site's data to the shape the running code expects — its
	 * options, and everything else this plugin keeps per site: the log file's
	 * location, and the queue table's own columns and keys.
	 *
	 * Each migration is gated on the site's DB version — read from the
	 * migration ledger, never from the plugin version, which is always the
	 * release being run and so never says anything about the stored data.
	 * The migrations are cumulative: a site left several releases behind runs
	 * each one it missed, in order, on the same request.
	 */
	public function migrate_db() {

		$stored     = get_option( self::DB_VERSION_OPTION_NAME );
		$db_version = ( is_string($stored) && $stored !== '' ) ? $stored : $this->backfill_db_version();

		// 1.5.0 — purge became revalidate: carry the options to their new names.
		if ( version_compare( $db_version, '1.5.0', '<' ) ) {
			$revalidate_all_opt = get_option('nextjs_revalidate-allow_purge_all');
			delete_option('nextjs_revalidate-allow_purge_all');

			if ( !empty($revalidate_all_opt) ) {
				update_option( self::SETTINGS_ALLOW_REVALIDATE_ALL_NAME, $revalidate_all_opt );
			}

			$revalidate_all_cron_opt = get_option('nextjs-revalidate-purge_all');
			delete_option('nextjs-revalidate-purge_all');

			if ( !empty($revalidate_all_cron_opt) ) {
				update_option( 'nextjs-revalidate-revalidate_all', $revalidate_all_cron_opt );
			}
		}

		// 1.6.0 — the queue moved out of the options and into its own table.
		if ( version_compare( $db_version, '1.6.0', '<' ) ) {
			delete_option('nextjs-revalidate-queue');
			delete_option('nextjs-revalidate-revalidate_all');
		}

		// 1.7.0 — the single revalidate URL split into a domain and a path per
		// endpoint. Guarded on the data rather than on the version, and not by
		// preference: every site predating the ledger is backfilled to the
		// release that introduces it, so a version gate on that same release
		// would be read after the site had already been stamped past it, and
		// would never fire for anybody. See `backfill_db_version()`.
		$this->split_legacy_url();

		// The log moved into a guarded directory of its own, under a per-site
		// name (ADR-0024). Guarded on the data for the same reason as above.
		Logger::migrate_legacy_log();

		// The queue's unique key moved off the `permalink` TEXT column and onto
		// a hash of it (ADR-0029), so the dedup the queue depends on exists on
		// standard MySQL and not only on MariaDB. Guarded on the data for the
		// same reason as the two above, and it is also where a site whose
		// `CREATE TABLE` MySQL refused gets a queue table at all — unless an
		// enqueue got there first, which runs the same migration when its write
		// fails on a table not yet in this shape.
		$this->queue->migrate_table();

		// Stamp the ledger, so none of the above is eligible to run again.
		// A site whose data was migrated by newer code than is running now
		// keeps its higher version: a downgrade must not make migrations it
		// has already been through eligible again.
		$stamp = version_compare( $db_version, NJR_VERSION, '>' ) ? $db_version : NJR_VERSION;
		if ( $stamp !== $stored ) update_option( self::DB_VERSION_OPTION_NAME, $stamp );
	}

	/**
	 * Ask every site of the network to migrate, once per release.
	 *
	 * `migrate_db()` above is hooked on `admin_init`, which fires per site: on
	 * a network a site therefore migrates only when a human opens *that site's*
	 * admin. A plugin update reaches every site's code at once and no site's
	 * data, and `register_activation_hook` does not fire on an update at all,
	 * so nothing else closes the gap. It is not dormant inertia either — cron
	 * on a site is triggered by *front-end* traffic, so a subsite with visitors
	 * and no admin visitors drains its queue and reads its revalidate domain
	 * and secret out of unmigrated options for as long as nobody logs in.
	 *
	 * The trigger is a version *comparison* and not an update *event*, on
	 * purpose: Composer, git and manual zip deploys all replace the plugin's
	 * files without WordPress's own updater ever running, and each of them has
	 * to sweep on the next admin request just as an update through the updater
	 * does.
	 *
	 * This decides only *when every site gets asked*. Which migrations then run
	 * on a given site is the site's own ledger's answer, unchanged — asking a
	 * site that is already up to date costs it one option read.
	 *
	 * Single-site installs never reach any of this: there, the per-site hook
	 * above already reaches the only site there is.
	 */
	public function sweep_migrations() {

		if ( !$this->network_sweep_is_due() ) return;

		// One sweep helper serves setup, teardown and migration alike — there
		// is no second blog-switching path here. On a large network it declines
		// rather than covering as many sites as one request has time for, and
		// leaves the swept version alone so that a network whose threshold is
		// later raised is swept on the next admin request. Until then,
		// `sweep_declined_notice()` says so.
		if ( !NextJsRevalidate::for_each_site( [$this, 'migrate_db'] ) ) return;

		// Stamped only now the sweep has been through every site. A sweep cut
		// short — a fatal on one site, a request nobody waited for — leaves the
		// record behind the running version, so the next admin request retries
		// it rather than skipping a network that was never finished.
		update_site_option( self::SWEPT_VERSION_OPTION_NAME, NJR_VERSION );
	}

	/**
	 * Whether this network still has to be swept for the running release.
	 *
	 * A live property, computed when it is asked for rather than a flag some
	 * earlier code path set, so the sweep and the notice cannot disagree.
	 *
	 * A network swept by *newer* code than is running keeps its higher record,
	 * for the reason the per-site ledger keeps its higher DB version: a
	 * downgrade must not make a sweep the network has already been through due
	 * again. Everything else — a record one release behind, a record left by a
	 * sweep that never finished, no record at all — is due.
	 *
	 * @return bool
	 */
	private function network_sweep_is_due() {

		if ( !is_multisite() ) return false;

		// Only a network-activated plugin has a network's worth of data to
		// migrate. Activated site by site instead, it is a per-site plugin that
		// happens to live on a network: each site reaches `migrate_db()` on its
		// own `admin_init` exactly as a single install does, and sweeping from
		// the one site that has it would write this plugin's rows into sites it
		// has never run on — a migration ledger among them, stamped for data the
		// site does not hold. The same test guards `setup_new_site()`, for the
		// same reason.
		if ( !NextJsRevalidate::is_network_active() ) return false;

		$swept = get_site_option( self::SWEPT_VERSION_OPTION_NAME );

		// Compared as versions, never as concatenated digits: the scheme this
		// plugin used before the ledger read 1.7.0 as 170 and 1.6.10 as 1610,
		// and so ranked the newer release as the older one.
		if ( !is_string($swept) || $swept === '' ) return true;

		return version_compare( $swept, NJR_VERSION, '<' );
	}

	/**
	 * Split the legacy revalidate URL into the domain and path it was always
	 * two halves of.
	 *
	 * Runs iff this site holds no domain and a non-empty legacy URL — a
	 * condition on the data itself, which makes it idempotent by construction:
	 * it runs exactly once per site, in whatever order it is reached, and it
	 * cannot be re-entered afterwards. That matters more here than it looks:
	 * `migrate_db()` runs on every `admin_init`, and an unguarded re-split
	 * would overwrite an operator's edits to either field on every page load.
	 *
	 * The path is preserved verbatim rather than assumed to be the default —
	 * it is whatever the operator's Next.js app routes, and nothing else in the
	 * system knows it. Only the scheme, credentials, host, port and path are
	 * carried over, so query args an operator pasted in with the URL are
	 * dropped by construction rather than stripped.
	 *
	 * @return void
	 */
	private function split_legacy_url() {

		if ( ! empty( $this->domain ) ) return;

		$legacy = get_option( self::LEGACY_URL_OPTION_NAME );
		if ( ! is_string($legacy) || $legacy === '' ) return;

		$parts = wp_parse_url( $legacy );

		// Too broken to parse. Left where it is on purpose: discarding an
		// operator's only record of their endpoint is the one outcome worse
		// than the unconfigured site they have until they retype it.
		if ( ! is_array($parts) || empty($parts['host']) ) return;

		$domain = ( empty($parts['scheme']) ? 'https' : $parts['scheme'] ) . '://';

		// Basic-auth credentials belong to the domain. A protected staging
		// front-end is exactly the kind of site that carries them, and dropping
		// them turns a working install into one that 401s silently.
		if ( ! empty($parts['user']) ) {
			$domain .= $parts['user'];
			if ( ! empty($parts['pass']) ) $domain .= ':' . $parts['pass'];
			$domain .= '@';
		}

		$domain .= $parts['host'];
		if ( ! empty($parts['port']) ) $domain .= ':' . $parts['port'];

		// Held to the rule a saved domain is held to, before anything is
		// written. The write goes through `sanitize_domain()`, and a domain it
		// refused would be dropped while the legacy URL was still deleted below,
		// leaving the site with neither. So a legacy URL whose scheme is not
		// `http` or `https` is left where it is, as an unparseable one is.
		$domain = self::normalise_domain( $domain );
		if ( empty($domain) ) return;

		// A trailing slash belongs to neither half — the composition puts
		// exactly one slash between them.
		$path = untrailingslashit( isset($parts['path']) ? $parts['path'] : '' );

		update_option( self::SETTINGS_DOMAIN_NAME, $domain );

		// A legacy URL that was a bare domain carries no path to preserve, and
		// writing an empty one would say the operator had cleared the field.
		if ( $path !== '' ) update_option( self::SETTINGS_ENDPOINT_PATH_NAME, $path );

		delete_option( self::LEGACY_URL_OPTION_NAME );
	}

	/**
	 * Infer the DB version of a site which predates the migration ledger,
	 * from the legacy options it still holds.
	 *
	 * A site holding none of them has data of the shape the running code
	 * expects — either a fresh install, or one already carried past every
	 * migration by the version comparison this replaces.
	 *
	 * That default is the limit of this inference, and it binds whoever adds
	 * the next migration: a backfilled site is stamped with the release it is
	 * running, so **a migration introduced by the same release that first runs
	 * this code cannot be gated on the version alone** — every existing site
	 * lands on that version before the gate is ever read, and skips it. Such a
	 * migration needs a guard on the data's own state, as the settings split of
	 * #29 has. From the release after, the ledger is authoritative and the
	 * version gate is enough.
	 *
	 * @return string A version string, comparable with `version_compare()`.
	 */
	private function backfill_db_version() {
		foreach ( self::DB_VERSION_FINGERPRINTS as $version => $legacy_options ) {
			foreach ( $legacy_options as $option_name ) {
				if ( self::option_exists( $option_name ) ) return $version;
			}
		}

		return NJR_VERSION;
	}

	/**
	 * Whether the site has a row for an option, regardless of its value.
	 *
	 * `get_option()` answers `false` both for an absent option and for one
	 * holding an empty value, and a legacy option left empty is still evidence
	 * of the release that wrote it.
	 *
	 * @param string $name
	 * @return bool
	 */
	private static function option_exists( $name ) {
		$absent = "\0njr-absent";
		return get_option( $name, $absent ) !== $absent;
	}
}
