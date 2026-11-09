<?php
/**
 * Admin › Translations › Settings: source (default) language, translated languages and their order, how blog posts
 * are translated, indexing of translated pages, Marclie Booking integration — one form in sections, each with a
 * short explanation. Changing the source language deletes every stored text (they are written in the old
 * language), so it needs an explicit confirmation.
 *
 * Also registers the Texts entry of the menu (priority 11); Posts (PostEditor, 12) comes between it and Settings
 * (13).
 */

namespace Marclie\Lingua\Admin;

use Marclie\Lingua\Coverage;
use Marclie\Lingua\Integrations\Booking;
use Marclie\Lingua\Languages;
use Marclie\Lingua\Settings;
use Marclie\Lingua\Store;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {

	public const SLUG = 'marclie-lingua-settings';

	private const GROUP = 'mlg_settings_group';

	public static function hooks(): void {
		add_action( 'admin_menu', array( self::class, 'textsMenu' ), 11 );
		add_action( 'admin_menu', array( self::class, 'menu' ), 13 );
		add_action( 'admin_init', array( self::class, 'register' ) );
	}

	public static function textsMenu(): void {
		add_submenu_page( Page::SLUG, __( 'Translations', 'marclie-lingua' ), __( 'Texts', 'marclie-lingua' ), Page::capability(), Page::SLUG );
	}

	public static function menu(): void {
		$hook = (string) add_submenu_page( Page::SLUG, __( 'Translation settings', 'marclie-lingua' ), __( 'Settings', 'marclie-lingua' ), Page::capability(), self::SLUG, array( self::class, 'render' ) );
		add_action(
			'load-' . $hook,
			static function (): void {
				add_action( 'admin_enqueue_scripts', array( Page::class, 'style' ) );
			}
		);
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
		$position = static fn( string $code ): int => '' === (string) ( $order[ $code ] ?? '' ) ? 99 : (int) $order[ $code ];
		usort( $targets, static fn( string $a, string $b ): int => ( $position( $a ) <=> $position( $b ) ) ?: strcmp( $a, $b ) );
		$input['targets'] = $targets;

		$source = sanitize_key( (string) ( $input['source'] ?? $current['source'] ) );
		if ( $source !== $current['source'] && Store::count( 'all', $source ) > 0 ) {
			if ( empty( $input['reset'] ) ) {
				add_settings_error( Settings::OPTION, 'mlg-source', __( 'The source language was not changed: confirm that every stored text and translation may be deleted.', 'marclie-lingua' ) );
				$input['source'] = $current['source'];
			} else {
				Store::truncate();
				Coverage::reset();
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
		<div class="wrap mlg mlg-settings">
			<div class="mlg-head">
				<div class="mlg-head__text">
					<h1 class="mlg-head__title"><?php esc_html_e( 'Translation settings', 'marclie-lingua' ); ?></h1>
				</div>
			</div>
			<hr class="wp-header-end">
			<?php
			settings_errors( Settings::OPTION );
			settings_errors( 'general' ); // "Settings saved." (options.php files it under `general`)
			?>
			<form method="post" action="options.php" class="mlg-settings__form">
				<?php settings_fields( self::GROUP ); ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>[_form]" value="1">

				<section class="mlg-section" id="mlg-section-languages" aria-labelledby="mlg-section-languages-title">
					<div class="mlg-section__intro">
						<h2 class="mlg-section__title" id="mlg-section-languages-title"><?php esc_html_e( 'Languages', 'marclie-lingua' ); ?></h2>
						<p><?php esc_html_e( 'The language your content is written in, and the languages visitors can switch to.', 'marclie-lingua' ); ?></p>
					</div>
					<div class="mlg-section__fields">
						<div class="mlg-setting">
							<label class="mlg-setting__label" for="mlg-source"><?php esc_html_e( 'Source language', 'marclie-lingua' ); ?></label>
							<select id="mlg-source" name="<?php echo esc_attr( $name ); ?>[source]" aria-describedby="mlg-source-help">
								<?php foreach ( Languages::all() as $lang ) : ?>
									<option value="<?php echo esc_attr( $lang->code ); ?>" <?php selected( $lang->code, $settings['source'] ); ?>><?php echo esc_html( $lang->name . ' (' . $lang->code . ')' ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description" id="mlg-source-help"><?php esc_html_e( 'The language the content is written in. It is the default language: its pages keep their URLs without a language prefix.', 'marclie-lingua' ); ?></p>
							<?php if ( $hasTexts ) : ?>
								<label class="mlg-setting__danger"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[reset]" value="1"> <?php esc_html_e( 'Changing it deletes every stored text and translation (they belong to the current source language).', 'marclie-lingua' ); ?></label>
							<?php endif; ?>
						</div>
						<fieldset class="mlg-setting">
							<legend class="mlg-setting__label"><?php esc_html_e( 'Translated languages', 'marclie-lingua' ); ?></legend>
							<p class="description"><?php esc_html_e( 'Each language gets its URLs under its prefix. The number sets its position in the language switcher.', 'marclie-lingua' ); ?></p>
							<div class="mlg-langs">
								<?php
								// chosen languages first, in switcher order
								$all = Languages::all();
								usort( $all, static fn( $a, $b ): int => ( $position[ $a->code ] ?? 999 ) <=> ( $position[ $b->code ] ?? 999 ) );
								foreach ( $all as $lang ) :
									if ( $lang->code === $settings['source'] ) {
										continue;
									}
									$on = isset( $position[ $lang->code ] );
									?>
									<span class="mlg-langs__item">
										<label class="mlg-langs__name"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[targets][]" value="<?php echo esc_attr( $lang->code ); ?>" <?php checked( $on ); ?>> <?php echo esc_html( $lang->name ); ?> <code>/<?php echo esc_html( $lang->code ); ?>/</code></label>
										<label class="screen-reader-text" for="mlg-order-<?php echo esc_attr( $lang->code ); ?>"><?php esc_html_e( 'Position in the language switcher', 'marclie-lingua' ); ?></label>
										<input type="number" min="1" max="99" class="small-text" id="mlg-order-<?php echo esc_attr( $lang->code ); ?>" name="<?php echo esc_attr( $name ); ?>[order][<?php echo esc_attr( $lang->code ); ?>]" value="<?php echo esc_attr( (string) ( $on ? $position[ $lang->code ] + 1 : '' ) ); ?>">
									</span>
								<?php endforeach; ?>
							</div>
						</fieldset>
					</div>
				</section>

				<section class="mlg-section" id="mlg-section-blog" aria-labelledby="mlg-section-blog-title">
					<div class="mlg-section__intro">
						<h2 class="mlg-section__title" id="mlg-section-blog-title"><?php esc_html_e( 'Blog posts', 'marclie-lingua' ); ?></h2>
						<p><?php esc_html_e( 'How blog posts get their translations. Pages, menus and buttons are always translated under Texts.', 'marclie-lingua' ); ?></p>
					</div>
					<div class="mlg-section__fields">
						<fieldset class="mlg-setting">
							<legend class="screen-reader-text"><?php esc_html_e( 'Blog posts', 'marclie-lingua' ); ?></legend>
							<?php
							$blog = array(
								'document' => array( __( 'Translate whole posts', 'marclie-lingua' ), __( 'Translations › Posts: title, content, excerpt, URL and SEO per language; translated posts can be found in search engines.', 'marclie-lingua' ) ),
								'strings'  => array( __( 'Translate text by text', 'marclie-lingua' ), __( 'Like the other pages, under Translations › Texts.', 'marclie-lingua' ) ),
								'browser'  => array( __( 'Translate in the visitor’s browser', 'marclie-lingua' ), __( 'Parts marked data-mlg-skip; nothing to translate here, but translated posts are not indexed.', 'marclie-lingua' ) ),
							);
							foreach ( $blog as $mode => $text ) :
								?>
								<label class="mlg-choice">
									<input type="radio" name="<?php echo esc_attr( $name ); ?>[blog]" value="<?php echo esc_attr( $mode ); ?>" <?php checked( $settings['blog'], $mode ); ?>>
									<span class="mlg-choice__text"><span class="mlg-choice__title"><?php echo esc_html( $text[0] ); ?></span><span class="mlg-choice__desc"><?php echo esc_html( $text[1] ); ?></span></span>
								</label>
							<?php endforeach; ?>
						</fieldset>
					</div>
				</section>

				<section class="mlg-section" id="mlg-section-seo" aria-labelledby="mlg-section-seo-title">
					<div class="mlg-section__intro">
						<h2 class="mlg-section__title" id="mlg-section-seo-title"><?php esc_html_e( 'Search engines', 'marclie-lingua' ); ?></h2>
						<p><?php esc_html_e( 'Whether Google and others may list the translated pages.', 'marclie-lingua' ); ?></p>
					</div>
					<div class="mlg-section__fields">
						<div class="mlg-setting">
							<label class="mlg-check"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[index]" value="1" <?php checked( $settings['index'] ); ?> aria-describedby="mlg-index-help"> <?php esc_html_e( 'Let search engines index the translated pages (hreflang links, own canonical URL, language sitemaps)', 'marclie-lingua' ); ?></label>
							<p class="description" id="mlg-index-help"><?php esc_html_e( 'When off, translated pages are "noindex, follow". When on, a translated page is still "noindex, follow" while it shows texts that are not translated yet (a page in two languages is a poor search result); it is indexed once every text on it is translated.', 'marclie-lingua' ); ?></p>
						</div>
						<div class="mlg-setting">
							<label class="mlg-setting__label" for="mlg-xdefault"><?php esc_html_e( 'Version for searchers whose language the site does not have (x-default):', 'marclie-lingua' ); ?></label>
							<select id="mlg-xdefault" name="<?php echo esc_attr( $name ); ?>[xdefault]">
								<?php foreach ( Settings::languages() as $lang ) : ?>
									<option value="<?php echo esc_attr( $lang->code ); ?>" <?php selected( $lang->code, $settings['xdefault'] ); ?>><?php echo esc_html( $lang->name ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>
				</section>

				<section class="mlg-section" id="mlg-section-booking" aria-labelledby="mlg-section-booking-title">
					<div class="mlg-section__intro">
						<h2 class="mlg-section__title" id="mlg-section-booking-title"><?php esc_html_e( 'Marclie Booking', 'marclie-lingua' ); ?></h2>
						<p><?php echo esc_html( Booking::statusText() ); ?></p>
					</div>
					<div class="mlg-section__fields">
						<fieldset class="mlg-setting">
							<legend class="screen-reader-text"><?php esc_html_e( 'Marclie Booking integration', 'marclie-lingua' ); ?></legend>
							<?php
							$modes = array(
								'auto' => __( 'Automatic: on when Marclie Booking is active and supports it', 'marclie-lingua' ),
								'on'   => __( 'On', 'marclie-lingua' ),
								'off'  => __( 'Off: booking widgets are translated like any other text', 'marclie-lingua' ),
							);
							foreach ( $modes as $mode => $label ) :
								?>
								<label class="mlg-check"><input type="radio" name="<?php echo esc_attr( $name ); ?>[booking]" value="<?php echo esc_attr( $mode ); ?>" <?php checked( $settings['booking'], $mode ); ?>> <?php echo esc_html( $label ); ?></label>
							<?php endforeach; ?>
						</fieldset>
					</div>
				</section>

				<div class="mlg-savebar">
					<?php submit_button( null, 'primary', 'submit', false ); ?>
				</div>
			</form>
		</div>
		<?php
	}
}
