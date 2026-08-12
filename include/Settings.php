<?php

namespace NextJsRevalidate;

use NextJsRevalidate\Abstracts\Base;

class Settings extends Base {

	const PAGE_NAME = 'nextjs-revalidate-settings';

	const SETTINGS_GROUP = 'nextjs-revalidate-settings';

	const SETTINGS_URL_NAME = 'nextjs_revalidate-url';
	const SETTINGS_SECRET_NAME = 'nextjs_revalidate-secret';
	const SETTINGS_ALLOW_REVALIDATE_ALL_NAME = 'nextjs_revalidate-allow_revalidate_all';
	const SETTINGS_REVALIDATE_ON_MENU_SAVE = 'nextjs_revalidate-revalidate-on-menu-save';
	const SETTINGS_DEBUG = 'nextjs_revalidate-debug';

	/**
	 * The migration ledger: the per-site record of the DB version, i.e. the
	 * version of the plugin whose data shape this site's options match.
	 * Not a setting — nothing an operator supplies is kept here.
	 */
	const DB_VERSION_OPTION_NAME = 'nextjs_revalidate-db_version';

	/**
	 * Fingerprints used to backfill the ledger on sites which predate it.
	 *
	 * Each entry maps a DB version to the legacy options a site still holding
	 * any of them stopped at. Ordered oldest first: a site left behind by
	 * several releases holds several of these, and the oldest one wins.
	 */
	const DB_VERSION_FINGERPRINTS = [
		// Options renamed by 1.5.0, when purge became revalidate.
		'1.4.0' => [ 'nextjs_revalidate-allow_purge_all', 'nextjs-revalidate-purge_all' ],
		// Options dropped by 1.6.0, when the queue moved to its own table.
		'1.5.0' => [ 'nextjs-revalidate-queue', 'nextjs-revalidate-revalidate_all' ],
	];

	/**
	 * Settings constructor.
	 */
	function __construct() {
		add_action( 'admin_menu', [$this, 'add_page'] );
		add_action( 'admin_init', [$this, 'register_fields'] );

		add_action( 'admin_init', [$this, 'migrate_db'] );
	}

	public function __get( $name ) {

		$opt_name = null;
		if      ( $name === 'url'                     ) $opt_name = self::SETTINGS_URL_NAME;
		else if ( $name === 'secret'                  ) $opt_name = self::SETTINGS_SECRET_NAME;
		else if ( $name === 'allow_revalidate_all'    ) $opt_name = self::SETTINGS_ALLOW_REVALIDATE_ALL_NAME;
		else if ( $name === 'revalidate_on_menu_save' ) $opt_name = self::SETTINGS_REVALIDATE_ON_MENU_SAVE;
		else if ( $name === 'debug'                   ) $opt_name = self::SETTINGS_DEBUG;

		$value = null;
		if ( !empty($opt_name) ) $value = get_option($opt_name);
		else                     $value = Parent::__get( $name );

		return $value;
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
		</div>
		<?php
	}

	/**
	 * Register and add settings
	 */
	public function register_fields() {
		register_setting( self::SETTINGS_GROUP, self::SETTINGS_URL_NAME );


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
			'nextjs_url',
			__('Revalidate url', 'nextjs-revalidate'),
			function ($args) {
				printf(
					'<input type="url" id="%1$s" name="%1$s" value="%2$s" placeholder="%3$s" class="regular-text code" />',
					self::SETTINGS_URL_NAME,
					$this->url,
					'https://example.com/api/revalidate'
			);
			},
			self::PAGE_NAME,
			'nextjs-revalidate-section'
		);

		register_setting( self::SETTINGS_GROUP, self::SETTINGS_SECRET_NAME );
		add_settings_field(
			'revalidate-secret',
			__('Revalidate Secret', 'nextjs-revalidate'),
			function ($args) {
				printf(
					'<input type="password" id="%1$s" name="%1$s" value="%2$s" class="regular-text code" />',
					self::SETTINGS_SECRET_NAME,
					$this->secret
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
		register_setting( self::SETTINGS_GROUP, self::SETTINGS_ALLOW_REVALIDATE_ALL_NAME );
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

		register_setting( self::SETTINGS_GROUP, self::SETTINGS_REVALIDATE_ON_MENU_SAVE );
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
		register_setting( self::SETTINGS_GROUP, self::SETTINGS_DEBUG );

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
					__('Logs will be saved to file lacated in <code>%s</code>', 'nextjs-revalidate'),
					trailingslashit($upload_dir['basedir']) . Logger::FILENAME
				),
			]
		);
	}

	public static function delete_settings() {
		// The migration ledger goes with the data it describes: left behind, a
		// later reinstall would trust it and skip migrations that must run.
		delete_option( self::DB_VERSION_OPTION_NAME );

		return
			delete_option( self::SETTINGS_URL_NAME ) &&
			delete_option( self::SETTINGS_SECRET_NAME );
	}

	public function define_settings() {
		add_option( self::SETTINGS_URL_NAME );
		add_option( self::SETTINGS_SECRET_NAME );
		add_option( self::SETTINGS_ALLOW_REVALIDATE_ALL_NAME, [] );
	}

	/**
	 * Returns if the plugin is correctly configured.
	 *
	 * @return boolean
	 */
	public function is_configured() {
		$url = $this->url;
		$secret = $this->secret;
		return !(empty($url) || empty($secret));

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

		// Stamp the ledger, so none of the above is eligible to run again.
		// A site whose data was migrated by newer code than is running now
		// keeps its higher version: a downgrade must not make migrations it
		// has already been through eligible again.
		$stamp = version_compare( $db_version, NJR_VERSION, '>' ) ? $db_version : NJR_VERSION;
		if ( $stamp !== $stored ) update_option( self::DB_VERSION_OPTION_NAME, $stamp );
	}

	/**
	 * Infer the DB version of a site which predates the migration ledger,
	 * from the legacy options it still holds.
	 *
	 * A site holding none of them has data of the shape the running code
	 * expects — either a fresh install, or one already carried past every
	 * migration by the version comparison this replaces.
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
