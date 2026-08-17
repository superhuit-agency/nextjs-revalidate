<?php

namespace NextJsRevalidate;

use NextJsRevalidate\Abstracts\Base;
use NextJsRevalidate\Interfaces\Hookable;

class Settings extends Base implements Hookable {

	const PAGE_NAME = 'nextjs-revalidate-settings';

	const SETTINGS_GROUP = 'nextjs-revalidate-settings';

	const SETTINGS_URL_NAME = 'nextjs_revalidate-url';
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
		'url'                     => [ 'name' => self::SETTINGS_URL_NAME,                  'empty' => ''  ],
		'secret'                  => [ 'name' => self::SETTINGS_SECRET_NAME,               'empty' => ''  ],
		'allow_revalidate_all'    => [ 'name' => self::SETTINGS_ALLOW_REVALIDATE_ALL_NAME, 'empty' => []  ],
		'revalidate_on_menu_save' => [ 'name' => self::SETTINGS_REVALIDATE_ON_MENU_SAVE,   'empty' => []  ],
		'debug'                   => [ 'name' => self::SETTINGS_DEBUG,                     'empty' => []  ],
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
					__('Logs will be saved to file lacated in <code>%s</code>', 'nextjs-revalidate'),
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
	 * The settings a revalidation cannot be delivered without,
	 * which the site has no value for.
	 *
	 * @return string[] Any of 'url' and 'secret'. Empty on a configured site.
	 */
	public function missing_settings() {
		$missing = [];

		$url = $this->url;
		if ( empty($url) ) $missing[] = 'url';

		$secret = $this->secret;
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
		if ( count($missing) > 1 )                     $what = __( 'its revalidate URL and secret are missing', 'nextjs-revalidate' );
		else if ( in_array('url', $missing, true) )    $what = __( 'its revalidate URL is missing', 'nextjs-revalidate' );
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
	 * Migrate the database options
	 * according to the version of the plugin
	 */
	public function migrate_db() {

		$plugin_data = get_plugin_data( __FILE__ );
		$version = intval(str_replace('.', '', $plugin_data['Version']));

		if ( $version < 150 ) {
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
		else if ( $version < 160 ) {
			delete_option('nextjs-revalidate-queue');
			delete_option('nextjs-revalidate-revalidate_all');
		}
	}
}
