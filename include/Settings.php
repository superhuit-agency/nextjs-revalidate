<?php

namespace NextJsRevalidate;

use NextJsRevalidate;

class Settings {

	const PAGE_NAME = 'nextjs-revalidate-settings';

	const SETTINGS_GROUP = 'nextjs-revalidate-settings';

	const SETTINGS_URL_NAME = 'nextjs_revalidate-url';
	const SETTINGS_SECRET_NAME = 'nextjs_revalidate-secret';
	const SETTINGS_ALLOW_REVALIDATE_ALL_NAME = 'nextjs_revalidate-allow_revalidate_all';

	/**
	 * @var array
	 */
	private $settings = [];

	/**
	 * Settings constructor.
	 */
	function __construct() {
		add_action( 'admin_menu', [$this, 'add_page'] );
		add_action( 'admin_init', [$this, 'register_fields'] );
		add_action( 'admin_init', [$this, 'stop_revalidate_all'] );
		add_action( 'admin_init', [$this, 'stop_purge_all'] );
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
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<form method="post" action="options.php">
				<?php
					// This prints out all hidden setting fields
					settings_fields( self::SETTINGS_GROUP );
					do_settings_sections( self::PAGE_NAME );
					submit_button();

					$njr = NextJsRevalidate::init();
					if ( $njr->RevalidateAll->is_revalidating_all() ) {
						submit_button( "Stop / Reset Purge All", 'secondary', 'revalidate_all_stop', false );
					}

					$revalidate_queue = (new Revalidate())->get_queue();
					?>
					<hr />
					<h2>Purge queue</h2>
					<details>
						<summary><?php printf("%d URLs waiting to be purged", count($revalidate_queue)); ?></summary>
						<ul>
							<?php foreach ($revalidate_queue as $url): ?>
								<li><?php echo $url; ?></li>
							<?php endforeach; ?>
						</ul>
					</details>
			</form>
		</div>
		<?php
	}

	/**
	 * Register and add settings
	 */
	public function register_fields() {
		register_setting( self::SETTINGS_GROUP, self::SETTINGS_URL_NAME );
		add_settings_section(
			'nextjs-revalidate-section',
			__('Next.js API config', 'nextjs-revalidate'),
			null,
			self::PAGE_NAME
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


		add_settings_section(
			'nextjs-revalidate-section-allow_revalidate_all',
			__('Allow purge all options', 'nextjs-revalidate'),
			function() {
				printf( '<p>%s</p>', __('Define which post type has the option to have all posts purged in the admin bar.', 'nextjs-revalidate') );
			},
			self::PAGE_NAME
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
	}

	public function __get( $name ) {
		$setting_name = null;
		switch ($name) {
			case 'url':
				$setting_name = self::SETTINGS_URL_NAME;
				break;
			case 'secret':
				$setting_name = self::SETTINGS_SECRET_NAME;
				break;
			case 'allow_revalidate_all':
				$setting_name = self::SETTINGS_ALLOW_REVALIDATE_ALL_NAME;
				break;
		}

		return empty($setting_name)
			? null
			: get_option($setting_name);
	}

	public static function delete_settings() {
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

	public function stop_revalidate_all() {
		if ( !(isset($_POST['option_page']) && $_POST['option_page'] === 'nextjs-revalidate-settings') ) return;
		if ( !isset($_POST['revalidate_all_stop']) ) return;

		$njr = NextJsRevalidate::init();
		$njr->RevalidateAll->stop_revalidate_all();
	}
	}
}
