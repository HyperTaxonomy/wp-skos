<?php
/**
 * Admin settings page.
 *
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_SKOS_Settings {

	const OPTION = 'wpskos_settings';

	/**
	 * @since 1.0.0
	 */
	public static function register(): void {
		add_action( 'admin_menu',  array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init',  array( __CLASS__, 'register_settings' ) );
	}

	/**
	 * @since 1.0.0
	 */
	public static function add_menu(): void {
		add_options_page(
			__( 'WP-SKOS', 'wp-skos' ),
			__( 'WP-SKOS', 'wp-skos' ),
			'manage_options',
			'wp-skos',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * @since 1.0.0
	 */
	public static function register_settings(): void {
		register_setting( 'wpskos', self::OPTION, array( __CLASS__, 'sanitize' ) );
		add_settings_section( 'wpskos_main', '', '__return_null', 'wp-skos' );

		add_settings_field(
			'enabled_taxonomies',
			__( 'Enabled taxonomies', 'wp-skos' ),
			array( __CLASS__, 'field_enabled_taxonomies' ),
			'wp-skos',
			'wpskos_main'
		);

		add_settings_field(
			'default_language',
			__( 'Default language tag', 'wp-skos' ),
			array( __CLASS__, 'field_default_language' ),
			'wp-skos',
			'wpskos_main'
		);
	}

	/**
	 * @since 1.0.0
	 */
	public static function field_enabled_taxonomies(): void {
		$enabled = self::get_enabled_taxonomies();
		$all     = get_taxonomies( array( 'public' => true, 'show_ui' => true ), 'objects' );
		foreach ( $all as $taxonomy ) {
			$checked = in_array( $taxonomy->name, $enabled, true );
			printf(
				'<label><input type="checkbox" name="%s[enabled_taxonomies][]" value="%s"%s> %s <code>(%s)</code></label><br>',
				esc_attr( self::OPTION ),
				esc_attr( $taxonomy->name ),
				checked( $checked, true, false ),
				esc_html( $taxonomy->label ),
				esc_html( $taxonomy->name )
			);
		}
		echo '<p class="description">' . esc_html__( 'Only enabled taxonomies are exposed via the Skosmos API and concept URIs.', 'wp-skos' ) . '</p>';
	}

	/**
	 * @since 1.0.0
	 */
	public static function field_default_language(): void {
		$lang = self::get_default_language();
		printf(
			'<input type="text" name="%s[default_language]" value="%s" size="6">',
			esc_attr( self::OPTION ),
			esc_attr( $lang )
		);
		echo '<p class="description">' . wp_kses(
			__( 'BCP 47 language tag applied to prefLabel when no multilingual plugin is active (e.g. <code>en</code>, <code>fr</code>).', 'wp-skos' ),
			array( 'code' => array() )
		) . '</p>';
	}

	/**
	 * @since 1.0.0
	 */
	public static function render_page(): void {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WP-SKOS Settings', 'wp-skos' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'wpskos' );
				do_settings_sections( 'wp-skos' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * @since 1.0.0
	 */
	public static function sanitize( $raw ): array {
		$out = array();

		$out['enabled_taxonomies'] = array();
		if ( ! empty( $raw['enabled_taxonomies'] ) && is_array( $raw['enabled_taxonomies'] ) ) {
			foreach ( $raw['enabled_taxonomies'] as $slug ) {
				$slug = sanitize_key( $slug );
				if ( taxonomy_exists( $slug ) ) {
					$out['enabled_taxonomies'][] = $slug;
				}
			}
		}

		$out['default_language'] = 'en';
		if ( ! empty( $raw['default_language'] ) ) {
			$out['default_language'] = sanitize_text_field( $raw['default_language'] );
		}

		return $out;
	}

	/**
	 * @since 1.0.0
	 */
	public static function get_enabled_taxonomies(): array {
		$opts = get_option( self::OPTION, array() );
		return (array) ( $opts['enabled_taxonomies'] ?? array() );
	}

	/**
	 * @since 1.0.0
	 */
	public static function get_default_language(): string {
		$opts = get_option( self::OPTION, array() );
		return (string) ( $opts['default_language'] ?? 'en' );
	}

	/**
	 * @since 1.0.0
	 */
	public static function is_taxonomy_enabled( string $taxonomy ): bool {
		return in_array( $taxonomy, self::get_enabled_taxonomies(), true );
	}
}
