<?php
/**
 * Elementor Site Settings (the active kit): global colors, global fonts and the site-wide kit settings.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Design;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The kit is a post (option elementor_active_kit) whose `_elementor_page_settings` hold system_colors / custom_colors,
 * system_typography / custom_typography and the theme-style settings (body, links, buttons, headings, layout). ONE line
 * per plan: before {kit, exists, raw (JSON of the settings) | null, hash}, after {raw, hash, ops}. Approve writes only while
 * the settings are the previewed ones, then clears Elementor's generated CSS (it is rebuilt on the next view); Undo
 * restores the exact previous settings.
 */
final class ElementorKit extends Planner {

	public const OPERATION = 'design_elementor_kit';
	public const OBJECT    = 'elementor_kit';
	public const FIELD     = 'design:elementor_kit';
	public const META      = '_elementor_page_settings';
	public const MAX_OPS   = 30;
	public const SYSTEM    = array( 'primary', 'secondary', 'text', 'accent' );

	/**
	 * Kit settings that may be set: key → kind (color | size | dimensions | font | choice).
	 */
	public const KEYS = array(
		'body_color'                    => 'color',
		'body_background_color'         => 'color',
		'link_normal_color'             => 'color',
		'link_hover_color'              => 'color',
		'h1_color'                      => 'color',
		'h2_color'                      => 'color',
		'h3_color'                      => 'color',
		'h4_color'                      => 'color',
		'h5_color'                      => 'color',
		'h6_color'                      => 'color',
		'button_text_color'             => 'color',
		'button_background_color'       => 'color',
		'button_hover_text_color'       => 'color',
		'button_hover_background_color' => 'color',
		'button_border_color'           => 'color',
		'button_border_radius'          => 'dimensions',
		'button_padding'                => 'dimensions',
		'container_width'               => 'size',
		'space_between_widgets'         => 'size',
		'body_typography_font_family'   => 'font',
		'h1_typography_font_family'     => 'font',
		'h2_typography_font_family'     => 'font',
		'button_typography_font_family' => 'font',
	);

	/**
	 * Object type.
	 */
	public static function object(): string {
		return self::OBJECT;
	}

	/**
	 * The active kit id (0 = none).
	 */
	public static function kit_id(): int {
		$id   = (int) get_option( 'elementor_active_kit', 0 );
		$post = $id > 0 ? get_post( $id ) : null;
		return $post && 'elementor_library' === $post->post_type ? $id : 0;
	}

	/**
	 * Settings of the kit (array).
	 *
	 * @param int $kit Kit.
	 * @return array<string,mixed>
	 */
	public static function settings( int $kit ): array {
		$value = get_post_meta( $kit, self::META, true );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Elementor's default system colors / fonts (a kit whose Site Settings were never saved has none stored).
	 */
	private const DEFAULTS = array(
		'system_colors'     => array(
			array(
				'_id'   => 'primary',
				'title' => 'Primary',
				'color' => '#6EC1E4',
			),
			array(
				'_id'   => 'secondary',
				'title' => 'Secondary',
				'color' => '#54595F',
			),
			array(
				'_id'   => 'text',
				'title' => 'Text',
				'color' => '#7A7A7A',
			),
			array(
				'_id'   => 'accent',
				'title' => 'Accent',
				'color' => '#61CE70',
			),
		),
		'system_typography' => array(
			array(
				'_id'                    => 'primary',
				'title'                  => 'Primary',
				'typography_typography'  => 'custom',
				'typography_font_family' => 'Roboto',
				'typography_font_weight' => '600',
			),
			array(
				'_id'                    => 'secondary',
				'title'                  => 'Secondary',
				'typography_typography'  => 'custom',
				'typography_font_family' => 'Roboto Slab',
				'typography_font_weight' => '400',
			),
			array(
				'_id'                    => 'text',
				'title'                  => 'Text',
				'typography_typography'  => 'custom',
				'typography_font_family' => 'Roboto',
				'typography_font_weight' => '400',
			),
			array(
				'_id'                    => 'accent',
				'title'                  => 'Accent',
				'typography_typography'  => 'custom',
				'typography_font_family' => 'Roboto',
				'typography_font_weight' => '500',
			),
		),
	);

	/**
	 * A global list of the kit with Elementor's defaults when none is stored (system lists only).
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @param string              $key      system_colors | system_typography | custom_….
	 * @return array<int,mixed>
	 */
	public static function global_list( array $settings, string $key ): array {
		if ( ! empty( $settings[ $key ] ) && is_array( $settings[ $key ] ) ) {
			return array_values( $settings[ $key ] );
		}
		if ( ! isset( self::DEFAULTS[ $key ] ) ) {
			return array();
		}
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->kits_manager ) ) {
			try {
				$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit_for_frontend();
				$own = $kit ? $kit->get_settings( $key ) : null;
				if ( is_array( $own ) && $own ) {
					return array_values( $own );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
		return self::DEFAULTS[ $key ];
	}

	/**
	 * The settings as stored text for comparisons (null = no meta row).
	 *
	 * @param int $kit Kit.
	 */
	public static function snapshot( int $kit ): ?string {
		return metadata_exists( 'post', $kit, self::META ) ? (string) wp_json_encode( self::settings( $kit ) ) : null;
	}

	/**
	 * Plans kit changes.
	 *
	 * @param array<string,mixed> $input           operations[].
	 * @param int                 $user_id         Owner.
	 * @param int                 $conversation_id Conversation.
	 * @return array<string,mixed>|WP_Error
	 */
	public function plan( array $input, int $user_id, int $conversation_id = 0 ) {
		$kit = self::kit_id();
		if ( $kit < 1 ) {
			return new WP_Error( 'addsynapse_assistant_design_elementor_kit', 'This site has no Elementor Site Settings (Elementor is not set up): change the theme\'s settings instead (describe_site_design).' );
		}
		if ( ! current_user_can( 'edit_theme_options' ) || ! current_user_can( 'edit_post', $kit ) ) {
			return new WP_Error( 'addsynapse_assistant_design_elementor_kit', 'Changing Elementor\'s Site Settings needs the «edit theme options» permission (an administrator).' );
		}
		$ops = array_values( array_filter( (array) ( $input['operations'] ?? array() ), 'is_array' ) );
		if ( ! $ops || count( $ops ) > self::MAX_OPS ) {
			return new WP_Error( 'addsynapse_assistant_design_elementor_kit', sprintf( 'Give 1 to %d operations.', self::MAX_OPS ) );
		}
		$before   = self::snapshot( $kit );
		$settings = self::settings( $kit );
		$start    = $settings;
		$lines    = array();
		foreach ( $ops as $i => $op ) {
			try {
				$lines[] = self::run_op( $settings, $op );
			} catch ( \InvalidArgumentException $e ) {
				return new WP_Error( 'addsynapse_assistant_design_elementor_kit', sprintf( 'Operation %1$d (%2$s): %3$s Nothing was planned.', $i + 1, (string) ( $op['op'] ?? '?' ), $e->getMessage() ) );
			}
		}
		if ( $settings === $start ) {
			return $this->store( array(), self::OPERATION, 'Elementor Site Settings: nothing would change.', array(), $user_id, $conversation_id );
		}
		$after = (string) wp_json_encode( $settings );
		return $this->store(
			array(
				array(
					'object_type' => self::OBJECT,
					'object_id'   => $kit,
					'parent_id'   => 0,
					'label'       => 'Elementor Site Settings',
					'sku'         => '',
					'field'       => self::FIELD,
					'before'      => array(
						'kit'    => $kit,
						'exists' => null !== $before,
						'raw'    => $before,
						'hash'   => hash( 'sha256', (string) $before ),
					),
					'after'       => array(
						'raw'  => $after,
						'hash' => hash( 'sha256', $after ),
						'ops'  => $lines,
					),
				),
			),
			self::OPERATION,
			sprintf( 'Elementor Site Settings: %d change(s) for the whole site.', count( $lines ) ),
			array( 'kit' => $kit ),
			$user_id,
			$conversation_id,
			array( 'changes' => $lines )
		);
	}

	/**
	 * One operation on the kit settings.
	 *
	 * @param array<string,mixed> $settings Settings (by reference).
	 * @param array<string,mixed> $op       Operation.
	 * @return array{label:string,field:string,before:string,after:string}
	 * @throws \InvalidArgumentException When not allowed.
	 */
	private static function run_op( array &$settings, array $op ): array {
		$kind = (string) ( $op['op'] ?? '' );
		switch ( $kind ) {
			case 'set_color':
				$id    = self::id( (string) ( $op['id'] ?? '' ) );
				$color = BlockAttrs::css_color( (string) ( $op['color'] ?? '' ) );
				if ( 0 === strpos( $color, 'var' ) ) {
					throw new \InvalidArgumentException( 'A global color is a #hex, rgb() or hsl() value.' );
				}
				$title = isset( $op['title'] ) ? sanitize_text_field( (string) $op['title'] ) : '';
				return self::upsert( $settings, in_array( $id, self::SYSTEM, true ) ? 'system_colors' : 'custom_colors', $id, $title, array( 'color' => $color ), 'Global color' );
			case 'set_font':
				$id     = self::id( (string) ( $op['id'] ?? '' ) );
				$family = trim( (string) ( $op['family'] ?? '' ) );
				if ( ! preg_match( '/^[A-Za-z0-9 \-]{2,60}$/', $family ) ) {
					throw new \InvalidArgumentException( 'family is a font name (e.g. "Roboto", "Playfair Display"), no URL.' );
				}
				$fields = array(
					'typography_typography'  => 'custom',
					'typography_font_family' => $family,
				);
				if ( isset( $op['weight'] ) ) {
					$weight = (string) $op['weight'];
					if ( ! preg_match( '/^([1-9]00|normal|bold)$/', $weight ) ) {
						throw new \InvalidArgumentException( 'weight is 100–900, normal or bold.' );
					}
					$fields['typography_font_weight'] = $weight;
				}
				if ( isset( $op['size'] ) ) {
					$fields['typography_font_size'] = self::size( $op['size'] );
				}
				$title = isset( $op['title'] ) ? sanitize_text_field( (string) $op['title'] ) : '';
				return self::upsert( $settings, in_array( $id, self::SYSTEM, true ) ? 'system_typography' : 'custom_typography', $id, $title, $fields, 'Global font' );
			case 'set':
				$key = (string) ( $op['key'] ?? '' );
				if ( ! isset( self::KEYS[ $key ] ) ) {
					throw new \InvalidArgumentException( sprintf( 'The kit setting "%1$s" cannot be changed here (allowed: %2$s).', esc_html( $key ), esc_html( implode( ', ', array_keys( self::KEYS ) ) ) ) );
				}
				$old   = $settings[ $key ] ?? null;
				$value = self::value( self::KEYS[ $key ], $op['value'] ?? null );
				if ( null === $value ) {
					unset( $settings[ $key ] );
				} else {
					$settings[ $key ] = $value;
				}
				if ( 'body_background_color' === $key && null !== $value ) {
					$settings['body_background_background'] = 'classic';
				}
				if ( 'font' === self::KEYS[ $key ] && null !== $value ) {
					$settings[ str_replace( '_font_family', '_typography', $key ) ] = 'custom';
				}
				return array(
					'label'  => ucfirst( str_replace( '_', ' ', $key ) ),
					'field'  => 'settings',
					'before' => self::text( $old ),
					'after'  => self::text( $value ),
				);
		}//end switch
		throw new \InvalidArgumentException( 'Unknown op: use set_color, set_font or set.' );
	}

	/**
	 * A global id.
	 *
	 * @param string $id Id.
	 * @throws \InvalidArgumentException When invalid.
	 */
	private static function id( string $id ): string {
		if ( ! preg_match( '/^[A-Za-z0-9_-]{1,20}$/', $id ) ) {
			throw new \InvalidArgumentException( 'id is primary, secondary, text, accent or a custom global id (from describe_site_design).' );
		}
		return $id;
	}

	/**
	 * Updates (or adds) an entry of a global list.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @param string              $key      List key.
	 * @param string              $id       Id.
	 * @param string              $title    Title (new entries).
	 * @param array<string,mixed> $fields   Fields.
	 * @param string              $what     Card label.
	 * @return array{label:string,field:string,before:string,after:string}
	 */
	private static function upsert( array &$settings, string $key, string $id, string $title, array $fields, string $what ): array {
		$items = self::global_list( $settings, $key );
		foreach ( $items as $i => $item ) {
			if ( is_array( $item ) && ( $item['_id'] ?? '' ) === $id ) {
				$old              = array_intersect_key( $item, $fields );
				$items[ $i ]      = array_merge( $item, $fields, '' !== $title ? array( 'title' => $title ) : array() );
				$settings[ $key ] = $items;
				return array(
					'label'  => sprintf( '%1$s "%2$s"', $what, (string) ( $item['title'] ?? $id ) ),
					'field'  => 'settings',
					'before' => self::text( $old ),
					'after'  => self::text( $fields ),
				);
			}
		}
		$items[]          = array_merge(
			array(
				'_id'   => $id,
				'title' => '' !== $title ? $title : ucfirst( $id ),
			),
			$fields
		);
		$settings[ $key ] = $items;
		return array(
			'label'  => sprintf( 'New %1$s "%2$s"', strtolower( $what ), '' !== $title ? $title : $id ),
			'field'  => 'settings',
			'before' => '',
			'after'  => self::text( $fields ),
		);
	}

	/**
	 * A checked value of a kind (null removes).
	 *
	 * @param string $kind  Kind.
	 * @param mixed  $value Value.
	 * @return mixed
	 * @throws \InvalidArgumentException When invalid.
	 */
	private static function value( string $kind, $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}
		switch ( $kind ) {
			case 'color':
				$color = BlockAttrs::css_color( (string) $value );
				if ( 0 === strpos( $color, 'var' ) ) {
					throw new \InvalidArgumentException( 'Use a #hex, rgb() or hsl() color.' );
				}
				return $color;
			case 'size':
				return self::size( $value );
			case 'dimensions':
				$size = self::size( is_array( $value ) ? ( $value['top'] ?? '' ) : $value );
				return array(
					'unit'     => $size['unit'],
					'top'      => (string) $size['size'],
					'right'    => (string) $size['size'],
					'bottom'   => (string) $size['size'],
					'left'     => (string) $size['size'],
					'isLinked' => true,
				);
			case 'font':
				if ( ! preg_match( '/^[A-Za-z0-9 \-]{2,60}$/', (string) $value ) ) {
					throw new \InvalidArgumentException( 'A font is a font name, no URL.' );
				}
				return (string) $value;
		}//end switch
		throw new \InvalidArgumentException( 'Unknown setting kind.' );
	}

	/**
	 * Elementor size {unit, size, sizes} from 16, "16px", "1.2em", {unit,size}.
	 *
	 * @param mixed $value Value.
	 * @return array{unit:string,size:float|int,sizes:array<int,mixed>}
	 * @throws \InvalidArgumentException When invalid.
	 */
	private static function size( $value ): array {
		if ( is_array( $value ) ) {
			$value = (string) ( $value['size'] ?? '' ) . (string) ( $value['unit'] ?? 'px' );
		}
		if ( ! preg_match( '/^\s*(\d+(?:\.\d+)?)\s*(px|em|rem|%|vw|vh)?\s*$/', (string) $value, $m ) ) {
			throw new \InvalidArgumentException( 'A size is a number with px, em, rem, %, vw or vh.' );
		}
		return array(
			'unit'  => isset( $m[2] ) && '' !== $m[2] ? $m[2] : 'px',
			'size'  => 0 + $m[1],
			'sizes' => array(),
		);
	}

	/**
	 * Plain text of a value.
	 *
	 * @param mixed $value Value.
	 */
	private static function text( $value ): string {
		if ( null === $value || array() === $value ) {
			return '';
		}
		if ( is_array( $value ) ) {
			if ( isset( $value['size'], $value['unit'] ) && ! isset( $value['top'] ) ) {
				return $value['size'] . $value['unit'];
			}
			if ( isset( $value['top'], $value['unit'] ) ) {
				return $value['top'] . $value['unit'];
			}
			$parts = array();
			foreach ( $value as $k => $v ) {
				if ( 'typography_typography' === $k ) {
					continue;
				}
				$parts[] = str_replace( 'typography_', '', (string) $k ) . ': ' . self::text( $v );
			}
			return implode( '; ', $parts );
		}
		return (string) $value;
	}




	/**
	 * Drops Elementor's generated CSS (rebuilt on the next view).
	 */
	public static function clear_css(): void {
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			try {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
	}
}
