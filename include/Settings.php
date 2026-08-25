<?php

namespace NextJsRevalidate;

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
 * @property string $fse_endpoint_path       The FSE revalidate route, or '' for the default.
 * @property string $secret                  The shared secret every request carries.
 * @property array  $allow_revalidate_all    Post types offering "revalidate all", keyed by name.
 * @property array  $revalidate_on_menu_save Post types revalidated on a menu update, keyed by name.
 * @property array  $debug                   Debug switches, keyed by name.
 */
class Settings extends Base implements Hookable {

	const PAGE_NAME = 'nextjs-revalidate-settings';

	const SETTINGS_GROUP = 'nextjs-revalidate-settings';

	const SETTINGS_DOMAIN_NAME = 'nextjs_revalidate-domain';
	const SETTINGS_ENDPOINT_PATH_NAME = 'nextjs_revalidate-endpoint_path';
	const SETTINGS_FSE_ENDPOINT_PATH_NAME = 'nextjs_revalidate-fse_endpoint_path';
	const SETTINGS_SECRET_NAME = 'nextjs_revalidate-secret';
	const SETTINGS_ALLOW_REVALIDATE_ALL_NAME = 'nextjs_revalidate-allow_revalidate_all';
	const SETTINGS_REVALIDATE_ON_MENU_SAVE = 'nextjs_revalidate-revalidate-on-menu-save';
	const SETTINGS_DEBUG = 'nextjs_revalidate-debug';

	/**
	 * The settings this plugin reads, declared once.
	 *
	 * Keyed by the name the rest of the plugin reads, each entry pairs the
	 * option the setting is stored under with the empty value a read yields on
	 * a site holding no row for it — of the setting's own type, never false, so
	 * a read is always safe to iterate or compare.
	 *
	 * Authoritative for reads, registration, seeding and teardown alike, so a
	 * setting cannot be added to one of them and forgotten in another.
	 */
	private const OPTIONS = [
		'domain'                  => [ 'name' => self::SETTINGS_DOMAIN_NAME,                   'empty' => ''  ],
		'endpoint_path'           => [ 'name' => self::SETTINGS_ENDPOINT_PATH_NAME,            'empty' => ''  ],
		'fse_endpoint_path'       => [ 'name' => self::SETTINGS_FSE_ENDPOINT_PATH_NAME,        'empty' => ''  ],
		'secret'                  => [ 'name' => self::SETTINGS_SECRET_NAME,                   'empty' => ''  ],
		'allow_revalidate_all'    => [ 'name' => self::SETTINGS_ALLOW_REVALIDATE_ALL_NAME,     'empty' => []  ],
		'revalidate_on_menu_save' => [ 'name' => self::SETTINGS_REVALIDATE_ON_MENU_SAVE,       'empty' => []  ],
		'debug'                   => [ 'name' => self::SETTINGS_DEBUG,                         'empty' => []  ],
	];

	/**
	 * The path each endpoint is reached at on a Next.js app that has not been
	 * told otherwise.
	 *
	 * A default rather than a seeded value: an empty path field means "whatever
	 * this release ships", so an app that renames its route later is a one-field
	 * edit, and a standard install never has to look at these at all.
	 */
	const DEFAULT_ENDPOINT_PATH = '/api/revalidate';
	const DEFAULT_FSE_ENDPOINT_PATH = '/api/revalidate-fse';

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

		add_action( 'admin_notices', [$this, 'unconfigured_notice'] );
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

		return $value === false ? $empty : $value;
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
			[ 'id' => 'on_menu_save',   'title' => __('On menu update', 'nextjs-revalidate')  ],
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
		foreach ( self::OPTIONS as $setting ) {
			register_setting( self::SETTINGS_GROUP, $setting['name'] );
		}


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

		// The paths are optional, and the placeholder is how an operator knows
		// it: a field left empty is the default shown in it, not a blank.
		$path_field = function ( $option_name, $value, $default, $help ) {
			printf(
				'<input type="text" id="%1$s" name="%1$s" value="%2$s" placeholder="%3$s" class="regular-text code" /><p class="description">%4$s</p>',
				$option_name,
				esc_attr( $value ),
				esc_attr( $default ),
				esc_html( $help )
			);
		};

		add_settings_field(
			'nextjs_path',
			__('Revalidate path', 'nextjs-revalidate'),
			function ($args) use ( $path_field ) {
				$path_field(
					self::SETTINGS_ENDPOINT_PATH_NAME,
					$this->endpoint_path,
					self::DEFAULT_ENDPOINT_PATH,
					__('Optional. The route that revalidates a single path on the front-end. Leave empty for the default.', 'nextjs-revalidate')
				);
			},
			self::PAGE_NAME,
			'nextjs-revalidate-section'
		);

		add_settings_field(
			'nextjs_fse_path',
			__('FSE revalidate path', 'nextjs-revalidate'),
			function ($args) use ( $path_field ) {
				$path_field(
					self::SETTINGS_FSE_ENDPOINT_PATH_NAME,
					$this->fse_endpoint_path,
					self::DEFAULT_FSE_ENDPOINT_PATH,
					__('Optional. The route that invalidates the front-end’s FSE template snapshot. Leave empty for the default.', 'nextjs-revalidate')
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

		$post_types = get_post_types([ 'public' => true ]);
		foreach ($post_types as $post_type) {
			if ( $post_type === 'attachment' ) continue; // skip attachments

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


		// On menu save section settings
		add_settings_section(
			'nextjs-revalidate-section-revalidate-on-menu-save',
			__('On menu update options', 'nextjs-revalidate'),
			function() {
				printf( '<p>%s</p>', __('Define which post type will be revalidated when updating a menu.', 'nextjs-revalidate') );
			},
			self::PAGE_NAME,
			[
				'before_section' => '<section aria-hidden="true" id="tab-panel--on_menu_save" role="tabpanel" tabindex="-1" aria-labelledby="tab-on_menu_save">',
				'after_section'  => '</section>',
			]
		);

		foreach ($post_types as $post_type) {
			if ( $post_type === 'attachment' ) continue; // skip attachments

			$post_type_object = get_post_type_object( $post_type );
			$id = "revalidate-on-menu-save-$post_type";
			add_settings_field(
				$id,
				$post_type_object->labels->name,
				'Kuuak\WordPressSettingFields\Fields::switch',
				self::PAGE_NAME,
				'nextjs-revalidate-section-revalidate-on-menu-save',
				[
					'label_for' => $id,
					'id'        => $id,
					'name'      => self::SETTINGS_REVALIDATE_ON_MENU_SAVE."[$post_type]",
					'checked'   => $this->revalidate_on_menu_save[$post_type] ?? false,
				]
			);
		}
		$id = "revalidate-on-menu-save-all";
		add_settings_field(
			$id,
			__('All post types', 'nextjs-revalidate'),
			'Kuuak\WordPressSettingFields\Fields::switch',
			self::PAGE_NAME,
			'nextjs-revalidate-section-revalidate-on-menu-save',
			[
				'label_for' => $id,
				'id'        => $id,
				'name'      => self::SETTINGS_REVALIDATE_ON_MENU_SAVE.'[all]',
				'checked'   => $this->revalidate_on_menu_save['all'] ?? false,
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

		$upload_dir = wp_upload_dir();
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
					trailingslashit($upload_dir['basedir']) . Logger::FILENAME
				),
			]
		);
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

		// The URL the settings above were split out of, on a site upgraded
		// before it was ever visited in the admin: the migration that consumes
		// it may not have run, and it is this site's data either way.
		delete_option( self::LEGACY_URL_OPTION_NAME );

		// The migration ledger goes with the data it describes: left behind, a
		// later reinstall would read it, believe this site's options already
		// have the running code's shape, and skip migrations that must run.
		delete_option( self::DB_VERSION_OPTION_NAME );
	}

	/**
	 * Register every setting of the site currently being served,
	 * holding its empty value until an operator supplies one.
	 *
	 * @return void
	 */
	public function define_settings() {
		foreach ( self::OPTIONS as $setting ) {
			add_option( $setting['name'], $setting['empty'] );
		}
	}

	/**
	 * The URL a revalidation is sent to.
	 *
	 * @return string Empty on a site holding no domain.
	 */
	public function revalidate_endpoint_url() {
		return $this->endpoint_url( $this->endpoint_path, self::DEFAULT_ENDPOINT_PATH );
	}

	/**
	 * The URL an FSE snapshot invalidation is sent to.
	 *
	 * @return string Empty on a site holding no domain.
	 */
	public function fse_endpoint_url() {
		return $this->endpoint_url( $this->fse_endpoint_path, self::DEFAULT_FSE_ENDPOINT_PATH );
	}

	/**
	 * Compose one endpoint from the site's domain and a path.
	 *
	 * The two halves are stored separately because the front-end serves several
	 * endpoints on one app, and deriving the second by string surgery on the
	 * first breaks the moment a route is named anything but the default.
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
	 * Both halves are trimmed first. Neither field is sanitised on save, and a
	 * domain pasted in with a trailing space composes a URL `wp_remote_get()`
	 * rejects — a revalidation that fails for a reason nothing on screen names.
	 * Trimming here rather than on save also covers the rows already stored.
	 *
	 * @param string $path    The path the operator supplied, possibly empty.
	 * @param string $default The path to use when they supplied none.
	 *
	 * @return string
	 */
	private function endpoint_url( $path, $default ) {
		$domain = untrailingslashit( trim( (string) $this->domain ) );
		if ( empty($domain) ) return '';

		$path = untrailingslashit( trim( (string) $path ) );
		if ( empty($path) ) $path = $default;

		return $domain . '/' . ltrim( $path, '/' );
	}

	/**
	 * The settings a revalidation cannot be delivered without,
	 * which the site has no value for.
	 *
	 * The paths are deliberately not among them: each falls back to a default,
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
	 * Tell whoever is looking at the admin that this site revalidates nothing.
	 *
	 * Not dismissible on purpose: an unconfigured site accepts edits and looks
	 * like it works, and a notice that can be dismissed for good recreates
	 * exactly the silence this is here to break.
	 */
	public function unconfigured_notice() {
		if ( $this->is_configured() ) return;

		$can_configure = current_user_can( 'manage_options' );

		// Only bother people whose work is being silently dropped,
		// or who can do something about it.
		if ( !$can_configure && !current_user_can( 'edit_posts' ) ) return;

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
	 * Migrate this site's options to the data shape the running code expects.
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

		// Stamp the ledger, so none of the above is eligible to run again.
		// A site whose data was migrated by newer code than is running now
		// keeps its higher version: a downgrade must not make migrations it
		// has already been through eligible again.
		$stamp = version_compare( $db_version, NJR_VERSION, '>' ) ? $db_version : NJR_VERSION;
		if ( $stamp !== $stored ) update_option( self::DB_VERSION_OPTION_NAME, $stamp );
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
