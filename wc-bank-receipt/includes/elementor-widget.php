<?php
/**
 * Elementor widget: a thin adapter over the shared receipt-panel renderer.
 *
 * This file is loaded **only** from
 * `WC_Bank_Receipt_Pro::register_elementor_widget()`, which Elementor itself calls
 * on `elementor/widgets/register` — so it is never parsed on a site where Elementor
 * is absent, inactive or older than the widget API. The guard below repeats that
 * check for the case where the file is included by something else.
 *
 * The widget deliberately contains no business logic:
 *   - it does not read an order, it asks the plugin for the order context;
 *   - it does not authorize anything, the shared context does (capability, ownership
 *     or that order's key), so a widget saved with a fixed order id can never hand
 *     another visitor somebody else's receipt;
 *   - it does not upload or validate files, the single POST path does;
 *   - it does not style or script anything, the enqueued `wcr-frontend` assets do.
 *
 * APPEARANCE
 * ----------
 * The style controls below do **not** write CSS and do not introduce a second way of
 * styling the panel: they produce a small map of design tokens
 * (`--wcrf-*` → value) for *this widget instance only*, which the shared renderer
 * validates and prints on the panel's own root element. That is what makes the
 * priority explicit and testable:
 *
 *     widget controls  >  shortcode `style=`  >  global appearance  >  theme/defaults
 *
 * Consequences of that design: one widget's colours can never leak into another
 * widget or into the theme, an element instance stays valid even if a token or a
 * preset is removed later (unknown values are dropped), and Elementor's Global
 * Colors / Global Fonts are supported by emitting their published CSS variables with
 * the plugin's own value as a fallback, so a missing kit value degrades to a correct
 * panel instead of an unstyled one. Everything Elementor is optional: without it the
 * plugin's own appearance screen is sufficient for every one of these settings.
 *
 * @package WC_Bank_Receipt
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
	return;
}

if ( class_exists( 'WCR_Elementor_Receipt_Panel' ) ) {
	return;
}

/**
 * Receipt panel widget.
 */
class WCR_Elementor_Receipt_Panel extends \Elementor\Widget_Base {

	/**
	 * Unique widget name (prefixed, so it cannot collide with another add-on).
	 *
	 * @return string
	 */
	public function get_name() {
		return 'wc_bank_receipt_panel';
	}

	/**
	 * @return string
	 */
	public function get_title() {
		return __( 'پنل فیش بانکی', 'wc-bank-receipt' );
	}

	/**
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-woocommerce';
	}

	/**
	 * The WooCommerce category exists in Elementor; fall back to `general` when a
	 * site has categories registered differently.
	 *
	 * @return array
	 */
	public function get_categories() {
		return array( 'woocommerce-elements', 'general' );
	}

	/**
	 * @return array
	 */
	public function get_keywords() {
		return array( 'receipt', 'bank', 'bacs', 'فیش', 'کارت به کارت' );
	}

	/**
	 * Controls: which order to describe, and an optional per-instance appearance.
	 *
	 * Every style control is “empty means automatic”: an untouched widget follows the
	 * plugin's «ظاهر و استایل» screen, and only the controls that were actually set
	 * override it. That is why the defaults are empty rather than a copied value.
	 */
	protected function register_controls() {
		if ( ! class_exists( 'WCR_Design_Tokens' ) ) {
			return; // the appearance layer is absent: this widget would have no style controls to offer.
		}

		$this->start_controls_section(
			'wcr_panel_section',
			array( 'label' => __( 'فیش بانکی', 'wc-bank-receipt' ) )
		);

		$this->add_control(
			'order_id',
			array(
				'label'       => __( 'شناسه سفارش (اختیاری)', 'wc-bank-receipt' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'min'         => 0,
				'description' => __( 'خالی بگذارید تا سفارش همین صفحه (صفحهٔ تشکر از سفارش یا جزئیات سفارش مشتری) نمایش داده شود. دسترسی به سفارش همیشه سمت سرور بررسی می‌شود، بنابراین این ویجت هرگز اطلاعات سفارش دیگران را نشان نمی‌دهد.', 'wc-bank-receipt' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'wcr_panel_style_section',
			array(
				'label' => __( 'ظاهر (اختصاصی همین ویجت)', 'wc-bank-receipt' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$presets = array( '' => __( 'از تنظیمات عمومی افزونه', 'wc-bank-receipt' ) );
		foreach ( WCR_Design_Tokens::presets() as $key => $preset ) {
			$presets[ $key ] = $preset['label'];
		}
		$this->add_control(
			'style_preset',
			array(
				'label'       => __( 'پیش‌تنظیم ظاهری', 'wc-bank-receipt' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'options'     => $presets,
				'default'     => '',
				'description' => __( 'یک ظاهر آماده برای همین ویجت. خالی بگذارید تا ظاهر عمومی افزونه اعمال شود.', 'wc-bank-receipt' ),
			)
		);

		$this->add_control(
			'style_primary',
			array(
				'label'       => __( 'رنگ اصلی', 'wc-bank-receipt' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'default'     => '',
				'description' => __( 'رنگ تأکیدی، دکمهٔ ارسال و کارت بانکی همین پنل. خالی = رنگ تنظیمات عمومی افزونه.', 'wc-bank-receipt' ),
			)
		);

		$this->add_control(
			'style_text',
			array(
				'label'   => __( 'رنگ متن', 'wc-bank-receipt' ),
				'type'    => \Elementor\Controls_Manager::COLOR,
				'default' => '',
			)
		);

		$this->add_control(
			'style_surface',
			array(
				'label'   => __( 'پس‌زمینهٔ جعبه‌ها', 'wc-bank-receipt' ),
				'type'    => \Elementor\Controls_Manager::COLOR,
				'default' => '',
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'        => 'style_typography',
				'label'       => __( 'تایپوگرافی', 'wc-bank-receipt' ),
				'description' => __( 'فونت انتخابی (از جمله فونت‌های سراسری المنتور) و اندازهٔ متن فقط برای همین پنل اعمال می‌شود.', 'wc-bank-receipt' ),
			)
		);

		$this->add_control(
			'style_radius',
			array(
				'label'      => __( 'گردی گوشه‌ها', 'wc-bank-receipt' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 40, 'step' => 1 ) ),
				'default'    => array( 'size' => '' ),
			)
		);

		$this->add_control(
			'style_content_width',
			array(
				'label'      => __( 'حداکثر عرض پنل', 'wc-bank-receipt' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 320, 'max' => 1200, 'step' => 10 ) ),
				'default'    => array( 'size' => '' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Turn the widget settings into the shared renderer's per-instance configuration.
	 *
	 * Nothing here is trusted: the token layer validates every value again (an unknown
	 * token, a colour that is not a colour or a size out of range is dropped), and a
	 * preset is only used when it exists. A widget whose settings were edited outside
	 * the editor therefore still renders the global appearance instead of raw CSS.
	 *
	 * @param array $settings Widget settings.
	 * @return array { preset: string, tokens: array }
	 */
	private function instance_config( $settings ) {
		if ( ! class_exists( 'WCR_Design_Tokens' ) ) {
			return array( 'preset' => '', 'tokens' => array() );
		}

		$presets = WCR_Design_Tokens::presets();
		$preset  = isset( $settings['style_preset'] ) ? (string) $settings['style_preset'] : '';
		if ( ! isset( $presets[ $preset ] ) ) {
			$preset = '';
		}

		$tokens = array();

		/*
		 * One colour picker drives the whole accent family, so a single choice stays
		 * coherent: base accent, hover shade, the submit button and the bank-card
		 * gradient. A plain colour is shaded into the derived values; a Global Color
		 * reference (which is not a colour we can compute from) is used as-is for all
		 * of them, i.e. as a flat accent instead of a gradient.
		 */
		$primary = $this->color_expression( $settings, 'style_primary', '--wcrf-pri' );
		if ( '' !== $primary ) {
			$hex = preg_match( '/^#[0-9a-f]{6}$/i', $primary ) ? $primary : '';
			$tokens['--wcrf-pri']       = $primary;
			$tokens['--wcrf-pri-h']     = $hex ? self::shade_hex( $hex, 12 ) : $primary;
			$tokens['--wcrf-btn-bg']    = $primary;
			$tokens['--wcrf-btn-bg-2']  = $hex ? self::shade_hex( $hex, 12 ) : $primary;
			$tokens['--wcrf-btn-bg-h']  = $hex ? self::shade_hex( $hex, -18 ) : $primary;
			$tokens['--wcrf-card-bg']   = $primary;
			$tokens['--wcrf-card-bg-2'] = $hex ? self::shade_hex( $hex, 22 ) : $primary;
		}

		$text = $this->color_expression( $settings, 'style_text', '--wcrf-text' );
		if ( '' !== $text ) {
			$tokens['--wcrf-text'] = $text;
		}

		$surface = $this->color_expression( $settings, 'style_surface', '--wcrf-surface' );
		if ( '' !== $surface ) {
			$tokens['--wcrf-surface'] = $surface;
		}

		$typography = isset( $settings['style_typography'] ) && is_array( $settings['style_typography'] )
			? $settings['style_typography']
			: array();

		if ( ! empty( $typography['font_family'] ) && is_scalar( $typography['font_family'] ) ) {
			$family = trim( (string) $typography['font_family'] );
			if ( preg_match( '/^globals\/typography\?id=([a-z0-9_-]+)$/i', $family, $m ) ) {
				/* A Global Font: Elementor publishes this variable for the active kit.
				 * The fallback keeps the panel readable when the kit value is absent. */
				$tokens['--wcrf-font-family'] = 'var(--e-global-typography-' . $m[1] . '-font-family, inherit)';
			} elseif ( preg_match( '/^[A-Za-z0-9 ,._\-"\']+$/', $family ) ) {
				$tokens['--wcrf-font-family'] = $family;
			}
		}

		if ( ! empty( $typography['font_size']['size'] ) && is_scalar( $typography['font_size']['size'] ) ) {
			$unit = isset( $typography['font_size']['unit'] ) ? (string) $typography['font_size']['unit'] : 'px';
			if ( in_array( $unit, array( 'px', 'em', 'rem' ), true ) ) {
				$tokens['--wcrf-font-size'] = self::trim_float( $typography['font_size']['size'] ) . $unit;
			}
		}

		$radius = $this->slider_size( $settings, 'style_radius' );
		if ( '' !== $radius ) {
			foreach ( array( '--wcrf-radius', '--wcrf-radius-card', '--wcrf-radius-button', '--wcrf-radius-field' ) as $token ) {
				$tokens[ $token ] = $radius . 'px';
			}
		}

		$width = $this->slider_size( $settings, 'style_content_width' );
		if ( '' !== $width ) {
			$tokens['--wcrf-content-width'] = $width . 'px';
		}

		return array( 'preset' => $preset, 'tokens' => $tokens );
	}

	/**
	 * A colour control's value as a CSS expression, or an empty string for “automatic”.
	 *
	 * @param array  $settings Widget settings.
	 * @param string $control  Control name.
	 * @param string $token    Token the value will be written to (for the fallback).
	 * @return string
	 */
	private function color_expression( $settings, $control, $token ) {
		if ( empty( $settings[ $control ] ) || ! is_scalar( $settings[ $control ] ) ) {
			return '';
		}
		$value = trim( (string) $settings[ $control ] );
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '/^globals\/colors\?id=([a-z0-9_-]+)$/i', $value, $m ) ) {
			return 'var(--e-global-color-' . $m[1] . ', ' . WCR_Design_Tokens::css_default( $token ) . ')';
		}
		return $value;
	}

	/**
	 * A slider control's size, or an empty string when the control was never touched.
	 *
	 * @param array  $settings
	 * @param string $control
	 * @return string
	 */
	private function slider_size( $settings, $control ) {
		if ( empty( $settings[ $control ]['size'] ) || ! is_scalar( $settings[ $control ]['size'] ) ) {
			return '';
		}
		$size = trim( (string) $settings[ $control ]['size'] );
		if ( '' === $size || ! is_numeric( $size ) ) {
			return '';
		}
		return self::trim_float( $size );
	}

	/**
	 * `16.00` → `16`, `1.50` → `1.5`.
	 *
	 * @param string|int|float $number
	 * @return string
	 */
	private static function trim_float( $number ) {
		$out = number_format( (float) $number, 2, '.', '' );
		$out = rtrim( rtrim( $out, '0' ), '.' );
		return ( '' === $out ) ? '0' : $out;
	}

	/**
	 * Lighten (positive) or darken (negative) a six-digit hex colour.
	 *
	 * Used only to derive the hover shade and the gradient end from the single colour
	 * the administrator picked, so one choice stays coherent. It never touches a colour
	 * the site already uses.
	 *
	 * @param string $hex
	 * @param int    $percent
	 * @return string
	 */
	private static function shade_hex( $hex, $percent ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return '#' . $hex;
		}
		$out = '#';
		for ( $i = 0; $i < 3; $i++ ) {
			$part = hexdec( substr( $hex, $i * 2, 2 ) );
			$part = (int) round( $part + ( $percent / 100 ) * 255 );
			$part = max( 0, min( 255, $part ) );
			$out .= str_pad( dechex( $part ), 2, '0', STR_PAD_LEFT );
		}
		return $out;
	}

	/**
	 * Render through the shared renderer.
	 */
	protected function render() {
		WC_Bank_Receipt_Pro::enqueue_frontend_assets();

		$plugin = function_exists( 'wc_bank_receipt_pro' ) ? wc_bank_receipt_pro() : null;
		if ( ! $plugin ) {
			return;
		}

		/*
		 * Elementor's settings stack expects the element data it normally passes to the
		 * constructor. A manually built instance (a broken editor payload, another plugin
		 * instantiating the widget) would otherwise throw *and* print a PHP warning inside
		 * Elementor's own code, so the read happens only when the instance carries element
		 * data at all: `get_id()` answers `null` without side effects for a bare instance,
		 * while every settings accessor below it warns first and then throws. On a failure
		 * the widget falls back to the page's order context and, when there is none, to the
		 * safe placeholder.
		 */
		$settings = array();
		$order_id = 0;
		if ( $this->get_id() ) {
			try {
				$settings = $this->get_settings_for_display();
				$order_id = isset( $settings['order_id'] ) ? absint( $settings['order_id'] ) : 0;
			} catch ( \Throwable $e ) {
				$settings = array();
				$order_id = 0;
			}
		}

		$instance = $this->instance_config( $settings );
		$style    = class_exists( 'WCR_Design_Tokens' ) ? WCR_Design_Tokens::style_attribute( $instance ) : '';

		$order = $plugin->resolve_context_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			// Editor preview, or a page that is not about an order: show a safe
			// placeholder, styled with this instance's own look so the editor shows
			// what the widget was configured to look like. Never another customer's
			// data, never a stray bank card.
			echo '<div class="wcrf-panel" dir="rtl"' . $style . '><div class="wcrf-notice">' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attribute escaped by the token layer.
				. esc_html__( 'این ویجت پنل فیش بانکی را نمایش می‌دهد. آن را در صفحهٔ تشکر از سفارش یا صفحهٔ جزئیات سفارش مشتری قرار دهید، یا شناسهٔ سفارش را وارد کنید.', 'wc-bank-receipt' )
				. '</div></div>';
			return;
		}

		// The automatic hook already printed this order's panel in this request (the
		// widget sits on the thank-you/view-order page): one page, one panel.
		if ( $plugin->auto_panel_printed( $order->get_id() ) ) {
			return;
		}

		echo $plugin->receipt_panel_html( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped by the renderer.
			$order,
			array(
				'source' => 'elementor',
				'style'  => $instance['preset'],
				'tokens' => $instance['tokens'],
			)
		);
	}
}
