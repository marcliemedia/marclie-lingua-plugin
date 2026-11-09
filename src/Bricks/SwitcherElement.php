<?php
/**
 * Bricks element `mlg-switch` ("Language switcher"): the plugin's language dropdown (Switcher) in a Bricks
 * layout. Loaded by Bricks only (Switcher::registerBricksElement(), `init` priority 11), never by the plugin's
 * autoloader, because it extends \Bricks\Element.
 *
 * Controls: button text (language code or name), globe icon on/off, menu alignment (left or right edge of the
 * button). The element's root is the switcher wrapper (`mlg-switch` classes, `translate="no"`,
 * `data-mlg-switch`), so Bricks classes, ids and custom attributes land on it. In the builder the links point to
 * the home page in each language (static preview; the menu opens on the frontend).
 */

namespace Marclie\Lingua\Bricks;

use Marclie\Lingua\Switcher;

defined( 'ABSPATH' ) || exit;

final class SwitcherElement extends \Bricks\Element {

	public $category = 'general';

	public $name = 'mlg-switch';

	public $icon = 'ti-world';

	public function get_label() {
		return esc_html__( 'Language switcher', 'marclie-lingua' );
	}

	public function get_keywords() {
		return array( 'language', 'lang', 'switcher', 'dropdown', 'translation' );
	}

	public function set_controls() {
		$this->controls['display'] = array(
			'tab'         => 'content',
			'label'       => esc_html__( 'Button text', 'marclie-lingua' ),
			'type'        => 'select',
			'options'     => array(
				'code' => esc_html__( 'Language code', 'marclie-lingua' ),
				'name' => esc_html__( 'Language name', 'marclie-lingua' ),
			),
			'inline'      => true,
			'placeholder' => esc_html__( 'Language code', 'marclie-lingua' ),
			'default'     => 'code',
		);

		$this->controls['showIcon'] = array(
			'tab'     => 'content',
			'label'   => esc_html__( 'Show globe icon', 'marclie-lingua' ),
			'type'    => 'checkbox',
			'default' => true,
		);

		$this->controls['align'] = array(
			'tab'         => 'content',
			'label'       => esc_html__( 'Menu alignment', 'marclie-lingua' ),
			'type'        => 'select',
			'options'     => array(
				'left'  => esc_html__( 'Left', 'marclie-lingua' ),
				'right' => esc_html__( 'Right', 'marclie-lingua' ),
			),
			'inline'      => true,
			'placeholder' => esc_html__( 'Left', 'marclie-lingua' ),
			'default'     => 'left',
		);
	}

	public function render() {
		$args = array(
			'display' => 'name' === ( $this->settings['display'] ?? 'code' ) ? 'name' : 'code',
			'icon'    => ! empty( $this->settings['showIcon'] ),
			'align'   => 'right' === ( $this->settings['align'] ?? 'left' ) ? 'right' : 'left',
		);
		if ( ! $this->is_frontend ) {
			$args['base'] = home_url( '/' );
		}

		$html = Switcher::inner( $args );
		if ( '' === $html ) {
			if ( ! $this->is_frontend ) {
				$this->render_element_placeholder( array( 'title' => esc_html__( 'Add a target language in Marclie Lingua settings to show the switcher.', 'marclie-lingua' ) ) );
			}
			return;
		}

		$this->set_attribute( '_root', 'class', Switcher::classes( $args ) );
		$this->set_attribute( '_root', 'translate', 'no' );
		$this->set_attribute( '_root', 'data-mlg-switch', '' );

		echo "<div {$this->render_attributes( '_root' )}>{$html}</div>"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bricks attributes + markup escaped by Switcher.
	}
}
