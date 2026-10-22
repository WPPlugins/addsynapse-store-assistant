<?php
/**
 * Theme settings: Customizer options of any theme (logo, colors, header / footer…), site title / tagline / icon,
 * Additional CSS in labelled blocks, widgets.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Design;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Lines (object `theme_setting`, all of one plan approved together):
 *  - `design:setting` one per Customizer setting: before {kind, where {type, base, keys, stylesheet, capability}, value},
 *    after {value}. Only that key path is written (never a stale copy of a whole option array such as astra-settings).
 *  - `design:css` the Additional CSS of the theme: before {kind, stylesheet, raw, hash}, after {raw, hash, ops}; the
 *    assistant's CSS lives in labelled blocks `/* addsynapse:<slug> <label> *\/ … /* /addsynapse:<slug> *\/`.
 *  - `design:widgets` the widget options: before {kind, options {sidebars_widgets, widget_block}}, after {options, ops}.
 * Approve writes a line only while the stored value is still `before` (else conflict); Undo only while it is `after`.
 * MISSING (Customizer::MISSING) marks a value that was not stored at all (Undo deletes it again).
 */
final class ThemeSettings extends Planner {

	public const OPERATION = 'design_theme_settings';
	public const OBJECT    = 'theme_setting';
	public const SETTING   = 'design:setting';
	public const CSS       = 'design:css';
	public const WIDGETS   = 'design:widgets';
	public const MAX_OPS   = 30;
	public const MAX_CSS   = 100000;

	/**
	 * Settings whose value is a media library image id.
	 */
	private const IMAGE_IDS = array( 'custom_logo', 'site_icon' );

	/**
	 * Object type.
	 */
	public static function object(): string {
		return self::OBJECT;
	}

	/**
	 * Plans theme setting changes.
	 *
	 * @param array<string,mixed> $input           {operations[]}.
	 * @param int                 $user_id         Owner.
	 * @param int                 $conversation_id Conversation.
	 * @return array<string,mixed>|WP_Error
	 * @throws \InvalidArgumentException Never outside (operations are caught and reported).
	 */
	public function plan( array $input, int $user_id, int $conversation_id = 0 ) {
		$ops = array_values( array_filter( (array) ( $input['operations'] ?? array() ), 'is_array' ) );
		if ( ! $ops || count( $ops ) > self::MAX_OPS ) {
			return new WP_Error( 'addsynapse_assistant_design_theme_ops', sprintf( 'Give 1 to %d operations.', self::MAX_OPS ) );
		}
		$settings = array();
		$css      = null;
		$widgets  = null;
		$external = array();
		foreach ( $ops as $i => $op ) {
			$kind = (string) ( $op['op'] ?? '' );
			try {
				if ( 'set' === $kind ) {
					$line                       = self::plan_setting( $op );
					$settings[ $line['label'] ] = $line;
				} elseif ( in_array( $kind, array( 'add_css', 'replace_css', 'remove_css' ), true ) ) {
					if ( ! current_user_can( 'edit_css' ) ) {
						throw new \InvalidArgumentException( 'Additional CSS needs the «edit CSS» permission (an administrator).' );
					}
					$css = $css ?? self::css_state();
					self::css_op( $css, $op );
				} elseif ( in_array( $kind, array( 'add_widget', 'update_widget', 'remove_widget', 'move_widget' ), true ) ) {
					if ( ! current_user_can( 'edit_theme_options' ) ) {
						throw new \InvalidArgumentException( 'Widgets need the «edit theme options» permission (an administrator).' );
					}
					$widgets  = $widgets ?? self::widget_state();
					$external = array_merge( $external, self::widget_op( $widgets, $op ) );
				} else {
					throw new \InvalidArgumentException( 'Unknown op: use set, add_css, replace_css, remove_css, add_widget, update_widget, remove_widget or move_widget.' );
				}
			} catch ( \InvalidArgumentException $e ) {
				return new WP_Error( 'addsynapse_assistant_design_theme_op', sprintf( 'Operation %1$d (%2$s): %3$s Nothing was planned.', $i + 1, $kind, $e->getMessage() ) );
			}//end try
		}//end foreach
		$items = array();
		foreach ( $settings as $line ) {
			if ( ! Customizer::same( $line['before']['value'], $line['after']['value'] ) ) {
				$items[] = $line;
			}
		}
		if ( null !== $css && $css['raw'] !== $css['new'] ) {
			if ( strlen( $css['new'] ) > self::MAX_CSS ) {
				return new WP_Error( 'addsynapse_assistant_design_theme_css', 'The Additional CSS would be too large.' );
			}
			$items[] = array(
				'object_type' => self::OBJECT,
				'object_id'   => 0,
				'parent_id'   => 0,
				'label'       => 'Additional CSS',
				'sku'         => '',
				'field'       => self::CSS,
				'before'      => array(
					'kind'       => 'css',
					'stylesheet' => get_stylesheet(),
					'raw'        => $css['raw'],
					'hash'       => hash( 'sha256', $css['raw'] ),
				),
				'after'       => array(
					'raw'  => $css['new'],
					'hash' => hash( 'sha256', $css['new'] ),
					'ops'  => $css['ops'],
				),
			);
		}//end if
		if ( null !== $widgets && ! Customizer::same( $widgets['before'], $widgets['now'] ) ) {
			$items[] = array(
				'object_type' => self::OBJECT,
				'object_id'   => 0,
				'parent_id'   => 0,
				'label'       => 'Widgets',
				'sku'         => '',
				'field'       => self::WIDGETS,
				'before'      => array(
					'kind'    => 'widgets',
					'options' => $widgets['before'],
				),
				'after'       => array(
					'options' => $widgets['now'],
					'ops'     => $widgets['ops'],
				),
			);
		}
		$changes = array();
		foreach ( $items as $item ) {
			foreach ( self::item_ops( $item ) as $op ) {
				$changes[] = $op;
			}
		}
		return $this->store(
			$items,
			self::OPERATION,
			sprintf( 'Theme settings: %d change(s).', count( $changes ) ),
			array( 'theme' => get_stylesheet() ),
			$user_id,
			$conversation_id,
			array(
				'changes'  => $changes,
				'external' => array_values( array_unique( $external ) ),
			)
		);
	}

	/**
	 * Line of one Customizer setting.
	 *
	 * @param array<string,mixed> $op {setting, value}.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException When not allowed.
	 */
	private static function plan_setting( array $op ): array {
		$id = trim( (string) ( $op['setting'] ?? '' ) );
		if ( '' === $id ) {
			throw new \InvalidArgumentException( 'Say which setting (its id from describe_site_design, section customizer).' );
		}
		$setting = Customizer::setting( $id );
		if ( null === $setting && null !== ( $raw = Customizer::theme_option( $id ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found, Squiz.PHP.DisallowMultipleAssignments.FoundInControlStructure
			return self::plan_theme_option( $id, $raw, $op );
		}
		if ( null === $setting ) {
			throw new \InvalidArgumentException( sprintf( 'The setting "%s" does not exist in this theme (or is changed with another tool): use an id from describe_site_design (customizer).', esc_html( $id ) ) );
		}
		if ( ! $setting->check_capabilities() ) {
			throw new \InvalidArgumentException( 'You are not allowed to change this setting.' );
		}
		if ( ! array_key_exists( 'value', $op ) ) {
			throw new \InvalidArgumentException( 'value is required.' );
		}
		$value   = $op['value'];
		$problem = BlockAttrs::value_problem( $value, 0, 'value' );
		if ( '' !== $problem ) {
			throw new \InvalidArgumentException( esc_html( $problem ) );
		}
		if ( in_array( $id, self::IMAGE_IDS, true ) || self::is_media_id( $id ) ) {
			$image = (int) $value;
			if ( 0 !== $image ) {
				$check = DesignText::image( $image );
				if ( is_wp_error( $check ) ) {
					throw new \InvalidArgumentException( esc_html( $check->get_error_message() ) );
				}
			}
			$value = $image;
		}
		$validity = $setting->validate( $value );
		if ( is_wp_error( $validity ) ) {
			throw new \InvalidArgumentException( esc_html( wp_strip_all_tags( $validity->get_error_message() ) ) );
		}
		$clean = $setting->sanitize( $value );
		if ( is_wp_error( $clean ) || ( null === $clean && null !== $value ) ) {
			throw new \InvalidArgumentException( sprintf( 'The value is not valid for "%s".', esc_html( $id ) ) );
		}
		$problem = BlockAttrs::value_problem( $clean, 0, 'value' );
		if ( '' !== $problem ) {
			throw new \InvalidArgumentException( esc_html( $problem ) );
		}
		$where = Customizer::storage( $setting );
		return array(
			'object_type' => self::OBJECT,
			'object_id'   => 0,
			'parent_id'   => 0,
			'label'       => Customizer::label( $id ),
			'sku'         => mb_substr( $id, 0, 100 ),
			'field'       => self::SETTING,
			'before'      => array(
				'kind'  => 'setting',
				'id'    => $id,
				'where' => $where,
				'image' => in_array( $id, self::IMAGE_IDS, true ) || self::is_media_id( $id ),
				'value' => Customizer::read( $where ),
			),
			'after'       => array(
				'value' => $clean,
			),
		);
	}

	/**
	 * Line of a raw key of a theme option array (Astra / GeneratePress settings not registered in this request): the new
	 * value must have the shape of the current one (same keys, same kinds, colors stay colors).
	 *
	 * @param string              $id  Id.
	 * @param array<string,mixed> $raw {where, current, shape}.
	 * @param array<string,mixed> $op  Operation.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException When not allowed.
	 */
	private static function plan_theme_option( string $id, array $raw, array $op ): array {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			throw new \InvalidArgumentException( 'You are not allowed to change this setting.' );
		}
		if ( ! array_key_exists( 'value', $op ) ) {
			throw new \InvalidArgumentException( 'value is required.' );
		}
		$value   = $op['value'];
		$problem = BlockAttrs::value_problem( $value, 0, 'value' );
		if ( '' === $problem ) {
			$problem = Customizer::shape_problem( $raw['shape'], $value );
		}
		if ( '' !== $problem ) {
			throw new \InvalidArgumentException( esc_html( $problem ) );
		}
		if ( is_array( $raw['shape'] ) ) {
			$value = array_replace_recursive( $raw['shape'], $value );
		}
		return array(
			'object_type' => self::OBJECT,
			'object_id'   => 0,
			'parent_id'   => 0,
			'label'       => 'Theme option ' . implode( ' › ', (array) $raw['where']['keys'] ),
			'sku'         => mb_substr( $id, 0, 100 ),
			'field'       => self::SETTING,
			'before'      => array(
				'kind'  => 'setting',
				'id'    => $id,
				'where' => $raw['where'],
				'image' => false,
				'value' => $raw['current'],
			),
			'after'       => array(
				'value' => $value,
			),
		);
	}

	/**
	 * Whether a setting's control stores a media library id (cropped image / media controls).
	 *
	 * @param string $id Setting.
	 */
	private static function is_media_id( string $id ): bool {
		$control = Customizer::control( $id );
		return $control instanceof \WP_Customize_Media_Control && ! $control instanceof \WP_Customize_Image_Control;
	}

	/**
	 * Additional CSS of the theme now + the planned copy.
	 *
	 * @return array{raw:string,new:string,ops:array<int,array<string,string>>}
	 */
	private static function css_state(): array {
		$post = wp_get_custom_css_post();
		$raw  = $post ? (string) $post->post_content : '';
		return array(
			'raw' => $raw,
			'new' => $raw,
			'ops' => array(),
		);
	}

	/**
	 * Labelled CSS blocks of the assistant in a stylesheet: slug → {label, css, start, end}.
	 *
	 * @param string $css CSS.
	 * @return array<string,array{label:string,css:string,start:int,end:int}>
	 */
	public static function css_blocks( string $css ): array {
		$out = array();
		if ( preg_match_all( '#/\* addsynapse:([a-z0-9-]{1,60}) (.*?) \*/\n(.*?)\n/\* /addsynapse:\1 \*/#s', $css, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m as $match ) {
				$out[ $match[1][0] ] = array(
					'label' => $match[2][0],
					'css'   => $match[3][0],
					'start' => (int) $match[0][1],
					'end'   => (int) $match[0][1] + strlen( $match[0][0] ),
				);
			}
		}
		return $out;
	}

	/**
	 * One CSS operation.
	 *
	 * @param array<string,mixed> $state State (by reference).
	 * @param array<string,mixed> $op    Operation.
	 * @throws \InvalidArgumentException When not allowed.
	 */
	private static function css_op( array &$state, array $op ): void {
		$kind  = (string) $op['op'];
		$label = trim( preg_replace( '/[^\p{L}\p{N} .,:()\/_-]/u', '', (string) ( $op['label'] ?? '' ) ) );
		$slug  = substr( sanitize_title( $label ), 0, 60 );
		if ( '' === $label || '' === $slug ) {
			throw new \InvalidArgumentException( 'Give the CSS a short label saying what it does (e.g. "Header phone number color").' );
		}
		$label  = mb_substr( $label, 0, 80 );
		$blocks = self::css_blocks( $state['new'] );
		$have   = $blocks[ $slug ] ?? null;
		if ( 'remove_css' === $kind ) {
			if ( null === $have ) {
				throw new \InvalidArgumentException( sprintf( 'There is no CSS labelled "%s" (describe_site_design, section css, lists them).', esc_html( $label ) ) );
			}
			$state['new']   = rtrim( substr( $state['new'], 0, $have['start'] ) ) . ( '' !== trim( substr( $state['new'], $have['end'] ) ) ? "\n\n" . ltrim( substr( $state['new'], $have['end'] ) ) : "\n" );
			$state['new']   = '' === trim( $state['new'] ) ? '' : ltrim( $state['new'] );
			$state['ops'][] = array(
				'label'  => 'CSS "' . $have['label'] . '"',
				'field'  => 'removed',
				'before' => $have['css'],
				'after'  => '',
			);
			return;
		}
		$css     = trim( str_replace( "\r\n", "\n", (string) ( $op['css'] ?? '' ) ) );
		$problem = '' === $css ? 'the CSS is empty.' : CssText::problem( $css );
		if ( '' !== $problem ) {
			throw new \InvalidArgumentException( esc_html( 'css: ' . $problem ) );
		}
		if ( false !== strpos( $css, '/* addsynapse:' ) || false !== strpos( $css, '/* /addsynapse:' ) ) {
			throw new \InvalidArgumentException( 'css must not contain the assistant\'s block markers.' );
		}
		$block = '/* addsynapse:' . $slug . ' ' . $label . " */\n" . $css . "\n/* /addsynapse:" . $slug . ' */';
		if ( 'add_css' === $kind ) {
			if ( null !== $have ) {
				throw new \InvalidArgumentException( sprintf( 'CSS labelled "%s" already exists: use replace_css.', esc_html( $label ) ) );
			}
			$state['new']   = '' === trim( $state['new'] ) ? $block . "\n" : rtrim( $state['new'] ) . "\n\n" . $block . "\n";
			$state['ops'][] = array(
				'label'  => 'CSS "' . $label . '"',
				'field'  => 'added',
				'before' => '',
				'after'  => $css,
			);
			return;
		}
		if ( null === $have ) {
			throw new \InvalidArgumentException( sprintf( 'There is no CSS labelled "%s": use add_css.', esc_html( $label ) ) );
		}
		$state['new']   = substr( $state['new'], 0, $have['start'] ) . $block . substr( $state['new'], $have['end'] );
		$state['ops'][] = array(
			'label'  => 'CSS "' . $label . '"',
			'field'  => 'replaced',
			'before' => $have['css'],
			'after'  => $css,
		);
	}

	/**
	 * Widget options now (+ the planned copy).
	 *
	 * @return array{before:array<string,mixed>,now:array<string,mixed>,ops:array<int,array<string,string>>}
	 */
	private static function widget_state(): array {
		$options = array(
			'sidebars_widgets' => get_option( 'sidebars_widgets', Customizer::MISSING ),
			'widget_block'     => get_option( 'widget_block', Customizer::MISSING ),
		);
		return array(
			'before' => $options,
			'now'    => $options,
			'ops'    => array(),
		);
	}

	/**
	 * Registered widget areas: id → name.
	 *
	 * @return array<string,string>
	 */
	public static function sidebars(): array {
		global $wp_registered_sidebars;
		$out = array();
		foreach ( (array) $wp_registered_sidebars as $id => $sidebar ) {
			$out[ (string) $id ] = html_entity_decode( (string) ( $sidebar['name'] ?? $id ), ENT_QUOTES, 'UTF-8' );
		}
		return $out;
	}

	/**
	 * One widget operation.
	 *
	 * @param array<string,mixed> $state State (by reference).
	 * @param array<string,mixed> $op    Operation.
	 * @return array<int,string> External links.
	 * @throws \InvalidArgumentException When not allowed.
	 */
	private static function widget_op( array &$state, array $op ): array {
		$kind     = (string) $op['op'];
		$areas    = self::sidebars();
		$sidebars = is_array( $state['now']['sidebars_widgets'] ) ? $state['now']['sidebars_widgets'] : array( 'array_version' => 3 );
		$blocks   = is_array( $state['now']['widget_block'] ) ? $state['now']['widget_block'] : array( '_multiwidget' => 1 );
		$external = array();
		$find     = static function ( string $widget ) use ( $sidebars ): string {
			foreach ( $sidebars as $area => $list ) {
				if ( is_array( $list ) && in_array( $widget, $list, true ) ) {
					return (string) $area;
				}
			}
			return '';
		};
		$area_of  = static function ( string $sidebar ) use ( $areas ): string {
			if ( ! isset( $areas[ $sidebar ] ) ) {
				throw new \InvalidArgumentException( sprintf( 'There is no widget area "%1$s" (available: %2$s).', esc_html( $sidebar ), esc_html( implode( ', ', array_keys( $areas ) ) ) ) );
			}
			return $sidebar;
		};
		$markup   = static function () use ( $op, &$external ): string {
			$given = array_filter(
				array(
					'blocks' => ! empty( $op['blocks'] ),
					'markup' => isset( $op['markup'] ) && '' !== $op['markup'],
					'text'   => isset( $op['text'] ) && '' !== $op['text'],
				)
			);
			if ( 1 !== count( $given ) ) {
				throw new \InvalidArgumentException( 'Give exactly one of blocks, markup or text for the widget.' );
			}
			$build = new BlockBuild();
			if ( ! empty( $op['blocks'] ) ) {
				$raw = $build->markup( array_values( (array) $op['blocks'] ) );
			} elseif ( isset( $op['text'] ) && '' !== $op['text'] ) {
				$raw = $build->markup(
					array(
						array(
							'name' => 'core/paragraph',
							'text' => (string) $op['text'],
						),
					)
				);
			} else {
				$raw = (string) $op['markup'];
			}
			$check    = BlockMarkup::check( $raw );
			$external = array_merge( $external, $build->external, $check['external'] );
			return $check['markup'];
		};
		$widget   = (string) ( $op['widget_id'] ?? '' );
		$position = isset( $op['position'] ) ? max( 1, (int) $op['position'] ) : 0;
		$insert   = static function ( array $list, string $id, int $position ): array {
			$list = array_values( array_diff( $list, array( $id ) ) );
			if ( $position < 1 || $position > count( $list ) ) {
				$list[] = $id;
			} else {
				array_splice( $list, $position - 1, 0, array( $id ) );
			}
			return $list;
		};
		switch ( $kind ) {
			case 'add_widget':
				$sidebar                = $area_of( (string) ( $op['sidebar'] ?? '' ) );
				$content                = $markup();
				$numbers                = array_filter( array_keys( $blocks ), 'is_int' );
				$number                 = $numbers ? max( $numbers ) + 1 : 2;
				$blocks[ $number ]      = array( 'content' => $content );
				$blocks['_multiwidget'] = 1;
				$sidebars[ $sidebar ]   = $insert( (array) ( $sidebars[ $sidebar ] ?? array() ), 'block-' . $number, $position );
				$state['ops'][]         = array(
					'label'  => 'Widget area "' . $areas[ $sidebar ] . '"',
					'field'  => 'added',
					'before' => '',
					'after'  => DesignText::excerpt( $content, 160 ),
				);
				break;
			case 'update_widget':
				if ( ! preg_match( '/^block-(\d+)$/', $widget, $m ) || ! isset( $blocks[ (int) $m[1] ] ) ) {
					throw new \InvalidArgumentException( 'Only block widgets (ids block-N from describe_site_design) can be rewritten; remove a legacy widget and add a block widget instead.' );
				}
				$old                   = (string) ( $blocks[ (int) $m[1] ]['content'] ?? '' );
				$content               = $markup();
				$blocks[ (int) $m[1] ] = array( 'content' => $content );
				$state['ops'][]        = array(
					'label'  => 'Widget ' . $widget,
					'field'  => 'replaced',
					'before' => DesignText::excerpt( $old, 160 ),
					'after'  => DesignText::excerpt( $content, 160 ),
				);
				break;
			case 'remove_widget':
				$from = $find( $widget );
				if ( '' === $from || 'wp_inactive_widgets' === $from ) {
					throw new \InvalidArgumentException( sprintf( 'Widget "%s" is not in a widget area.', esc_html( $widget ) ) );
				}
				$sidebars[ $from ] = array_values( array_diff( (array) $sidebars[ $from ], array( $widget ) ) );
				$text              = $widget;
				if ( preg_match( '/^block-(\d+)$/', $widget, $m ) ) {
					$text = DesignText::excerpt( (string) ( $blocks[ (int) $m[1] ]['content'] ?? '' ), 160 );
					unset( $blocks[ (int) $m[1] ] );
				} else {
					$sidebars['wp_inactive_widgets'] = array_values( array_merge( (array) ( $sidebars['wp_inactive_widgets'] ?? array() ), array( $widget ) ) );
				}
				$state['ops'][] = array(
					'label'  => 'Widget area "' . ( $areas[ $from ] ?? $from ) . '"',
					'field'  => 'removed',
					'before' => $text,
					'after'  => '',
				);
				break;
			case 'move_widget':
				$from = $find( $widget );
				if ( '' === $from ) {
					throw new \InvalidArgumentException( sprintf( 'Widget "%s" was not found.', esc_html( $widget ) ) );
				}
				$to                = $area_of( (string) ( $op['sidebar'] ?? $from ) );
				$sidebars[ $from ] = array_values( array_diff( (array) $sidebars[ $from ], array( $widget ) ) );
				$sidebars[ $to ]   = $insert( (array) ( $sidebars[ $to ] ?? array() ), $widget, $position );
				$state['ops'][]    = array(
					'label'  => 'Widget ' . $widget,
					'field'  => 'moved',
					'before' => $areas[ $from ] ?? $from,
					'after'  => ( $areas[ $to ] ?? $to ) . ( $position ? ' #' . $position : '' ),
				);
				break;
		}//end switch
		$state['now']['sidebars_widgets'] = $sidebars;
		$state['now']['widget_block']     = $blocks;
		return $external;
	}

	/**
	 * Card / result changes of a line.
	 *
	 * @param array<string,mixed> $item Line.
	 * @return array<int,array<string,string>>
	 */
	private static function item_ops( array $item ): array {
		if ( self::SETTING === ( $item['field'] ?? '' ) ) {
			$image = ! empty( $item['before']['image'] );
			return array(
				array(
					'label'  => (string) $item['label'],
					'field'  => 'settings',
					'before' => self::text( $item['before']['value'] ?? null, $image ),
					'after'  => self::text( $item['after']['value'] ?? null, $image ),
				),
			);
		}
		return (array) ( $item['after']['ops'] ?? array() );
	}

	/**
	 * Plain text of a value (images → file name).
	 *
	 * @param mixed $value Value.
	 * @param bool  $image Media id.
	 */
	public static function text( $value, bool $image = false ): string {
		if ( Customizer::MISSING === $value || null === $value || '' === $value ) {
			return '';
		}
		if ( $image && is_numeric( $value ) && (int) $value > 0 ) {
			$file = get_attached_file( (int) $value );
			return $file ? wp_basename( $file ) . ' (#' . (int) $value . ')' : '#' . (int) $value;
		}
		if ( is_bool( $value ) ) {
			return $value ? 'yes' : 'no';
		}
		$text = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) ) );
		return mb_strlen( $text ) > 300 ? mb_substr( $text, 0, 299 ) . '…' : $text;
	}




	/**
	 * Stores Additional CSS exactly (kses off: checked when planned).
	 *
	 * @param string $stylesheet Theme.
	 * @param string $css        CSS.
	 * @throws \RuntimeException When WordPress refuses or rewrites it.
	 */
	public static function write_css( string $stylesheet, string $css ): void {
		$kses = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $kses ) {
			kses_remove_filters();
		}
		try {
			$result = wp_update_custom_css_post( $css, array( 'stylesheet' => $stylesheet ) );
		} finally {
			if ( $kses ) {
				kses_init_filters();
			}
		}
		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( esc_html( $result->get_error_message() ) );
		}
		$post = wp_get_custom_css_post( $stylesheet );
		if ( ( $post ? (string) $post->post_content : '' ) !== $css ) {
			throw new \RuntimeException( 'WordPress changed the CSS while saving.' );
		}
	}
}
