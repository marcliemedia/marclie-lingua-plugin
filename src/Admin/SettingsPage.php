<?php
/**
 * Admin › Translations › Settings: source (default) language, translated languages and their order, Marclie
 * Booking integration, indexing of translated pages. Changing the source language deletes every stored text
 * (they are written in the old language), so it needs an explicit confirmation.
 */

namespace Marclie\Lingua\Admin;

use Marclie\Lingua\Integrations\Booking;
use Marclie\Lingua\Languages;
use Marclie\Lingua\Settings;
use Marclie\Lingua\Store;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {

	public const SLUG = 'marclie-lingua-settings';

	private const GROUP = 'mlg_settings_group';

	public static function hooks(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 11 );
		add_action( 'admin_init', array( self::class, 'register' ) );
	}

	public static function menu(): void {
		add_submenu_page( Page::SLUG, __( 'Translations', 'marclie-lingua' ), __( 'Texts', 'marclie-lingua' ), Page::capability(), Page::SLUG );
		add_submenu_page( Page::SLUG, __( 'Translation settings', 'marclie-lingua' ), __( 'Settings', 'marclie-lingua' ), Page::capability(), self::SLUG, array( self::class, 'render' ) );
	}

	public static function register(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
			)
		);
		add_filter( 'option_page_capability_' . self::GROUP, array( Page::class, 'capability' ) );
	}

	/**
	 * @param mixed $input
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		if ( empty( $input['_form'] ) ) {
			return Settings::sanitize( $input ); // saved by code (install, migration)
		}
		$current = Settings::get();
		$order   = (array) ( $input['order'] ?? array() );
		$targets = array_map( 'strval', (array) ( $input['targets'] ?? array() ) );
		usort( $targets, static fn( string $a, string $b ): int => ( (int) ( $order[ $a ] ?? 99 ) <=> (int) ( $order[ $b ] ?? 99 ) ) ?: strcmp( $a, $b ) );
		$input['targets'] = $targets;

		$source = sanitize_key( (string) ( $input['source'] ?? $current['source'] ) );
		if ( $source !== $current['source'] && Store::count( 'all', $source ) > 0 ) {
			if ( empty( $input['reset'] ) ) {
				add_settings_error( Settings::OPTION, 'mlg-source', __( 'The source language was not changed: confirm that every stored text and translation may be deleted.', 'marclie-lingua' ) );
				$input['source'] = $current['source'];
			} else {
				Store::truncate();
				add_settings_error( Settings::OPTION, 'mlg-reset', __( 'Source language changed; every stored text was deleted. Scan the site to collect the texts again.', 'marclie-lingua' ), 'updated' );
			}
		}

		return Settings::sanitize( $input );
	}

	public static function render(): void {
		$settings = Settings::get();
		$name     = Settings::OPTION;
		$hasTexts = Store::count( 'all', $settings['source'] ) > 0;
		$position = array_flip( $settings['targets'] );
		?>
		<div class="wrap mlg">
			<h1><?php esc_html_e( 'Translation settings', 'marclie-lingua' ); ?></h1>
			<?php settings_errors( Settings::OPTION ); ?>
			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>[_form]" value="1">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="mlg-source"><?php esc_html_e( 'Source language', 'marclie-lingua' ); ?></label></th>
						<td>
							<select id="mlg-source" name="<?php echo esc_attr( $name ); ?>[source]">
								<?php foreach ( Languages::all() as $lang ) : ?>
									<option value="<?php echo esc_attr( $lang->code ); ?>" <?php selected( $lang->code, $settings['source'] ); ?>><?php echo esc_html( $lang->name . ' (' . $lang->code . ')' ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'The language the content is written in. It is the default language: its pages keep their URLs without a language prefix.', 'marclie-lingua' ); ?></p>
							<?php if ( $hasTexts ) : ?>
								<p><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[reset]" value="1"> <?php esc_html_e( 'Changing it deletes every stored text and translation (they belong to the current source language).', 'marclie-lingua' ); ?></label></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Translated languages', 'marclie-lingua' ); ?></th>
						<td>
							<fieldset class="mlg-langs">
								<legend class="screen-reader-text"><?php esc_html_e( 'Translated languages', 'marclie-lingua' ); ?></legend>
								<?php foreach ( Languages::all() as $lang ) : ?>
									<?php
									if ( $lang->code === $settings['source'] ) {
										continue;
									}
									$on = isset( $position[ $lang->code ] );
									?>
									<span class="mlg-langs__item">
										<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[targets][]" value="<?php echo esc_attr( $lang->code ); ?>" <?php checked( $on ); ?>> <?php echo esc_html( $lang->name ); ?> <code>/<?php echo esc_html( $lang->code ); ?>/</code></label>
										<label class="screen-reader-text" for="mlg-order-<?php echo esc_attr( $lang->code ); ?>"><?php esc_html_e( 'Position in the language switcher', 'marclie-lingua' ); ?></label>
										<input type="number" min="1" max="99" class="small-text" id="mlg-order-<?php echo esc_attr( $lang->code ); ?>" name="<?php echo esc_attr( $name ); ?>[order][<?php echo esc_attr( $lang->code ); ?>]" value="<?php echo esc_attr( (string) ( $on ? $position[ $lang->code ] + 1 : '' ) ); ?>">
									</span>
								<?php endforeach; ?>
							</fieldset>
							<p class="description"><?php esc_html_e( 'Each language gets its URLs under its prefix. The number sets its position in the language switcher.', 'marclie-lingua' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Marclie Booking', 'marclie-lingua' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Marclie Booking integration', 'marclie-lingua' ); ?></legend>
								<?php
								$modes = array(
									'auto' => __( 'Automatic: on when Marclie Booking is active and supports it', 'marclie-lingua' ),
									'on'   => __( 'On', 'marclie-lingua' ),
									'off'  => __( 'Off: booking widgets are translated like any other text', 'marclie-lingua' ),
								);
								foreach ( $modes as $mode => $label ) :
									?>
									<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[booking]" value="<?php echo esc_attr( $mode ); ?>" <?php checked( $settings['booking'], $mode ); ?>> <?php echo esc_html( $label ); ?></label><br>
								<?php endforeach; ?>
							</fieldset>
							<p class="description"><?php echo esc_html( Booking::statusText() ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Search engines', 'marclie-lingua' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[index]" value="1" <?php checked( $settings['index'] ); ?>> <?php esc_html_e( 'Let search engines index the translated pages (hreflang links, own canonical URL, language sitemaps)', 'marclie-lingua' ); ?></label>
							<p class="description"><?php esc_html_e( 'When off, translated pages are "noindex, follow".', 'marclie-lingua' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
