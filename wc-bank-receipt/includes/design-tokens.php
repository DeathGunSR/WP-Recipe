<?php
/**
 * WC Bank Receipt — appearance layer: design tokens, presets and validation.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * The receipt panel is one component with one renderer. Its look must be
 * configurable without a second renderer, a second stylesheet or a page of
 * hand-written CSS, so *every* adjustable visual property is expressed as a CSS
 * custom property (`--wcrf-*`) and this class is the only place that knows:
 *
 *   - which tokens exist, their type, their range and their default value
 *     (`schema()`);
 *   - what a preset means (`presets()`);
 *   - how a stored setting is validated (`sanitize()`);
 *   - how theme, global settings, presets and per-instance overrides combine
 *     (`resolve()`);
 *   - how a resolved set becomes a CSS declaration list (`declarations()`).
 *
 * The stylesheet (`assets/css/frontend.css`) declares the very same defaults on
 * the panel root, so an untouched install emits **no** inline declaration at all
 * and the panel looks exactly like the stylesheet says. A checked-in acceptance
 * test (`STYLE-11`) compares the two lists, so they cannot drift apart.
 *
 * PRIORITY ORDER (documented in docs/appearance.md, implemented in resolve())
 *
 *   1. per-instance overrides (Elementor widget controls)            — highest
 *   2. per-instance options (`[wc_bank_receipt style="minimal"]`)
 *   3. global appearance settings (this plugin's settings screen)
 *   4. theme-inherited values and the plugin's own fallbacks         — lowest
 *
 * ISOLATION
 * ---------
 * Nothing here can restyle anything outside the component: the declarations are
 * printed as a `style` attribute **on the panel root element only**, custom
 * properties inherit down that subtree, and no rule outside `.wcrf-panel` reads
 * them. No `!important`, no generated selectors, no `<style>` block, no global
 * scope. Values are never concatenated from raw input: each one is validated
 * against its own type (plus a final character-level guard in
 * `value_is_safe()`), and the result is escaped with `esc_attr()` at print time.
 *
 * @package WC_Bank_Receipt
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'WCR_Design_Tokens' ) ) {
	return;
}

/**
 * Appearance settings, design tokens and presets.
 */
final class WCR_Design_Tokens {

	/** The single option this layer owns (an array, saved as one unit). */
	const OPTION = 'wc_receipt_appearance';

	/** Its own options group: saving appearance can never write another setting. */
	const GROUP = 'wc_receipt_appearance_group';

	/** The three design modes. */
	const MODES = array( 'inherit', 'preset', 'custom' );

	/** The spacing choices; `custom` means “use the stored padding/gap tokens”. */
	const DENSITIES = array( 'comfortable', 'compact', 'custom' );

	/** Theme-palette links an administrator may opt into. */
	const LINKS = array( 'accent', 'text', 'surface' );

	/** @var array|null Token schema, built once per request. */
	private static $schema = null;

	/* =====================================================================
	 * Schema
	 * ===================================================================== */

	/**
	 * The design-token schema: the single source of truth for names, types,
	 * defaults, ranges and labels.
	 *
	 * Type meanings:
	 *   color  — `#rgb` / `#rrggbb` (or, for the theme link layer, a whitelisted
	 *            `var( --wp--preset--color--…, <fallback> )` expression);
	 *   length — a number with a `px` unit, clamped to [min,max];
	 *   ratio  — a unitless number (line-height), clamped to [min,max];
	 *   weight — a font weight from a whitelist;
	 *   enum   — a named choice; the *key* is stored, the CSS value comes from
	 *            the option map. This is what keeps arbitrary CSS out of the
	 *            settings: a shadow or a font stack is never admin-supplied text.
	 *
	 * The order of this array is the order of the emitted declarations, so the
	 * markup is deterministic and comparable between requests and themes.
	 *
	 * @return array
	 */
	public static function schema() {
		if ( null !== self::$schema ) {
			return self::$schema;
		}			self::$schema = array(

			/* --------------------------------------------------- lightbox colours
			 * The full-screen receipt viewer is part of the component the customer and
			 * the manager both use, so its three colours are design tokens like any
			 * other — the docs promise they are editable, and the preview offers them
			 * under “نمایش تمام‌صفحهٔ رسید”. Their defaults are the values the shared
			 * stylesheet falls back to, so an untouched installation looks identical. */
			'--wcrf-lb-backdrop' => array(
				'type'    => 'color',
				'group'   => 'color',
				'default' => '#090e1a',
				'label'   => 'نمایش تمام‌صفحه — پس‌زمینه',
				'hint'    => 'رنگ پردهٔ پشت تصویر رسید.',
			),
			'--wcrf-lb-btn-bg' => array(
				'type'    => 'color',
				'group'   => 'color',
				'default' => '#2a3550',
				'label'   => 'نمایش تمام‌صفحه — دکمهٔ بستن',
				'hint'    => 'پس‌زمینهٔ دکمهٔ بستن روی پرده.',
			),
			'--wcrf-lb-btn-fg' => array(
				'type'    => 'color',
				'group'   => 'color',
				'default' => '#ffffff',
				'label'   => 'نمایش تمام‌صفحه — رنگ نشانهٔ بستن',
				'hint'    => 'رنگ ✕ دکمهٔ بستن.',
			),

			/* --------------------------------------------------------- colours */
			'--wcrf-pri' => array(
				'type'    => 'color',
				'group'   => 'color',
				'default' => '#1565c0',
				'label'   => 'رنگ اصلی',
				'hint'    => 'رنگ تأکیدی افزونه: مقدار شمارش معکوس، نوار پیشرفت، حلقهٔ فوکوس.',
			),
			'--wcrf-pri-h' => array(
				'type'    => 'color',
				'group'   => 'color',
				'default' => '#1e88e5',
				'label'   => 'رنگ اصلی (هاور)',
				'hint'    => 'حالت اشاره‌گر/فوکوس روی اجزای تأکیدی.',
			),
			'--wcrf-text' => array(
				'type'    => 'color',
				'group'   => 'color',
				'default' => 'CanvasText',
				'label'   => 'رنگ متن اصلی',
				'hint'    => 'پیش‌فرض از رنگ متن سیستم/قالب پیروی می‌کند (CanvasText).',
			),
			'--wcrf-muted' => array(
				'type'    => 'color',
				'group'   => 'color',
				'default' => 'GrayText',
				'label'   => 'رنگ متن ثانویه',
				'hint'    => 'برای راهنماها و توضیح‌های کم‌اهمیت.',
			),
			'--wcrf-surface' => array(
				'type'    => 'color',
				'group'   => 'color',
				'default' => 'Canvas',
				'label'   => 'پس‌زمینهٔ جعبه‌ها و فرم',
				'hint'    => 'جعبهٔ شمارش معکوس، کادر وضعیت و ناحیهٔ بارگذاری.',
			),
			'--wcrf-border' => array(
				'type'    => 'color',
				'group'   => 'color',
				'default' => 'GrayText',
				'label'   => 'رنگ حاشیه',
				'hint'    => '',
			),
			'--wcrf-card-bg' => array(
				'type'    => 'color',
				'group'   => 'color',
				'default' => '#1565c0',
				'label'   => 'کارت بانکی — رنگ شروع',
				'hint'    => 'کارت بانکی یک گرادیان ملایم از این رنگ تا «رنگ پایان» است.',
			),
			'--wcrf-card-bg-2' => array(
				'type'    => 'color',
				'group'   => 'color',
				'default' => '#42a5f5',
				'label'   => 'کارت بانکی — رنگ پایان',
				'hint'    => '',
			),
			'--wcrf-card-text' => array(
				'type'    => 'color',
				'group'   => 'color',
				'default' => '#ffffff',
				'label'   => 'کارت بانکی — رنگ متن',
				'hint'    => 'اگر رنگ کارت را روشن می‌کنید، این مقدار را تیره بگذارید.',
			),
			'--wcrf-btn-bg' => array(
				'type'    => 'color',
				'group'   => 'color',
				'default' => '#1565c0',
				'label'   => 'دکمهٔ اصلی — رنگ شروع',
				'hint'    => 'دکمهٔ «ارسال فیش واریزی».',
			),
			'--wcrf-btn-bg-2' => array(
				'type'    => 'color',
				'group'   => 'color',
				'default' => '#1e88e5',
				'label'   => 'دکمهٔ اصلی — رنگ پایان',
				'hint'    => '',
			),
			'--wcrf-btn-bg-h' => array(
				'type'    => 'color',
				'group'   => 'color',
				'default' => '#0d47a1',
				'label'   => 'دکمهٔ اصلی — رنگ هاور',
				'hint'    => '',
			),
			'--wcrf-btn-text' => array(
				'type'    => 'color',
				'group'   => 'color',
				'default' => '#ffffff',
				'label'   => 'دکمهٔ اصلی — رنگ متن',
				'hint'    => '',
			),
			'--wcrf-field-bg' => array(
				'type'    => 'color',
				'group'   => 'color',
				'default' => 'Canvas',
				'label'   => 'پس‌زمینهٔ ناحیهٔ بارگذاری',
				'hint'    => 'پیش‌فرض از پس‌زمینهٔ سیستم/قالب پیروی می‌کند (Canvas)؛ کادر خط‌چین ناحیه را از پس‌زمینهٔ صفحه جدا می‌کند.',
			),
			'--wcrf-field-focus-bg' => array(
				'type'    => 'color',
				'group'   => 'color',
				'default' => 'rgba(21, 101, 192, .12)',
				'label'   => 'پس‌زمینهٔ ناحیهٔ بارگذاری (فوکوس)',
				'hint'    => 'یک لایهٔ نیمه‌شفاف از رنگ تأکیدی؛ در حالت روشن و تیره خوانا می‌ماند.',
			),
			'--wcrf-ok' => array(
				'type'    => 'color',
				'group'   => 'color',
				'default' => '#2e7d32',
				'label'   => 'وضعیت موفق — متن',
				'hint'    => '',
			),
			'--wcrf-ok-bg' => array(
				'type'    => 'color',
				'group'   => 'color',
				'default' => '#f1f8e9',
				'label'   => 'وضعیت موفق — پس‌زمینه',
				'hint'    => '',
			),
			'--wcrf-warn' => array(
				'type'    => 'color',
				'group'   => 'color',
				'default' => '#e65100',
				'label'   => 'وضعیت هشدار — متن',
				'hint'    => '',
			),
			'--wcrf-warn-bg' => array(
				'type'    => 'color',
				'group'   => 'color',
				'default' => '#fff8e1',
				'label'   => 'وضعیت هشدار — پس‌زمینه',
				'hint'    => '',
			),
			'--wcrf-err' => array(
				'type'    => 'color',
				'group'   => 'color',
				'default' => '#c62828',
				'label'   => 'وضعیت خطا/رد — متن',
				'hint'    => '',
			),
			'--wcrf-err-bg' => array(
				'type'    => 'color',
				'group'   => 'color',
				'default' => '#fff8f8',
				'label'   => 'وضعیت خطا/رد — پس‌زمینه',
				'hint'    => '',
			),

			/* ------------------------------------------------------ typography */
			'--wcrf-font-family' => array(
				'type'    => 'enum',
				'group'   => 'typography',
				'default' => 'inherit',
				'label'   => 'فونت',
				'hint'    => 'هیچ فونت خارجی بارگذاری نمی‌شود؛ این گزینه فقط فونت‌های موجود را انتخاب می‌کند.',
				'options' => array(
					'inherit' => array( 'label' => 'ارث‌بری از قالب (پیشنهادی)', 'value' => 'inherit' ),
					'system'  => array( 'label' => 'فونت سیستمی', 'value' => '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif' ),
					'sans'    => array( 'label' => 'Arial / Helvetica', 'value' => 'Arial, Helvetica, sans-serif' ),
					'tahoma'  => array( 'label' => 'Tahoma', 'value' => 'Tahoma, "Segoe UI", sans-serif' ),
					'serif'   => array( 'label' => 'Serif', 'value' => 'Georgia, "Times New Roman", serif' ),
				),
			),
			'--wcrf-font-size' => array(
				'type'    => 'length',
				'group'   => 'typography',
				'default' => '1em',
				'label'   => 'اندازهٔ متن پایه',
				'hint'    => 'پیش‌فرض نسبی است (1em) تا با اندازهٔ متن قالب هماهنگ بماند.',
				'ranges'  => array( 'px' => array( 11, 22 ), 'em' => array( 0.7, 2 ), 'rem' => array( 0.7, 2 ) ),
				'step'    => 0.5,
			),
			'--wcrf-font-size-title' => array(
				'type'    => 'length',
				'group'   => 'typography',
				'default' => '1.07em',
				'label'   => 'اندازهٔ تیتر',
				'hint'    => 'تیتر کادر وضعیت؛ نسبی است تا با متن پایه بزرگ و کوچک شود.',
				'ranges'  => array( 'px' => array( 12, 30 ), 'em' => array( 0.8, 2.2 ), 'rem' => array( 0.8, 2.2 ) ),
			),
			'--wcrf-font-size-small' => array(
				'type'    => 'length',
				'group'   => 'typography',
				'default' => '0.93em',
				'label'   => 'اندازهٔ متن کمکی',
				'hint'    => 'راهنماها و توضیح‌های ریز.',
				'ranges'  => array( 'px' => array( 10, 20 ), 'em' => array( 0.7, 1.4 ), 'rem' => array( 0.7, 1.4 ) ),
				'step'    => 0.5,
			),
			'--wcrf-line-height' => array(
				'type'    => 'ratio',
				'group'   => 'typography',
				'default' => 'inherit',
				'label'   => 'ارتفاع خط',
				'hint'    => 'خالی بگذارید تا از قالب پیروی کند.',
				'min'     => 1.2,
				'max'     => 2.4,
				'step'    => 0.05,
			),
			'--wcrf-font-weight' => array(
				'type'    => 'enum',
				'group'   => 'typography',
				'default' => 'normal',
				'label'   => 'وزن متن',
				'hint'    => '',
				'options' => array(
					'normal' => array( 'label' => 'عادی', 'value' => '400' ),
					'medium' => array( 'label' => 'نیمه‌ضخیم', 'value' => '500' ),
					'semi'   => array( 'label' => 'نیمه‌پُر', 'value' => '600' ),
					'bold'   => array( 'label' => 'ضخیم', 'value' => '700' ),
					'heavy'  => array( 'label' => 'خیلی ضخیم', 'value' => '800' ),
				),
			),
			'--wcrf-font-weight-title' => array(
				'type'    => 'enum',
				'group'   => 'typography',
				'default' => 'bold',
				'label'   => 'وزن تیترها',
				'hint'    => '',
				'options' => array(
					'medium' => array( 'label' => 'نیمه‌ضخیم', 'value' => '500' ),
					'semi'   => array( 'label' => 'نیمه‌پُر', 'value' => '600' ),
					'bold'   => array( 'label' => 'ضخیم', 'value' => '700' ),
					'heavy'  => array( 'label' => 'خیلی ضخیم', 'value' => '800' ),
				),
			),

			/* -------------------------------------------------- shape & spacing */
			'--wcrf-radius' => array(
				'type'    => 'length',
				'group'   => 'shape',
				'default' => '12px',
				'label'   => 'گردی گوشهٔ جعبه‌ها',
				'hint'    => 'کادر وضعیت، جعبهٔ شمارش معکوس، ناحیهٔ بارگذاری، اعلان‌ها.',
				'min'     => 0,
				'max'     => 40,
				'step'    => 1,
			),
			'--wcrf-radius-card' => array(
				'type'    => 'length',
				'group'   => 'shape',
				'default' => '18px',
				'label'   => 'گردی گوشهٔ کارت بانکی',
				'hint'    => '',
				'min'     => 0,
				'max'     => 40,
				'step'    => 1,
			),
			'--wcrf-radius-button' => array(
				'type'    => 'length',
				'group'   => 'shape',
				'default' => '10px',
				'label'   => 'گردی گوشهٔ دکمه‌ها',
				'hint'    => '',
				'min'     => 0,
				'max'     => 40,
				'step'    => 1,
			),
			'--wcrf-radius-field' => array(
				'type'    => 'length',
				'group'   => 'shape',
				'default' => '8px',
				'label'   => 'گردی گوشهٔ فیلدها و پیش‌نمایش',
				'hint'    => 'صفحهٔ شمارهٔ کارت، برچسب نام فایل، تصویر پیش‌نمایش.',
				'min'     => 0,
				'max'     => 30,
				'step'    => 1,
			),
			'--wcrf-border-width' => array(
				'type'    => 'length',
				'group'   => 'shape',
				'default' => '1.5px',
				'label'   => 'ضخامت حاشیه',
				'hint'    => 'حاشیهٔ جعبه‌ها؛ نوار رنگی وضعیت و کادر خط‌چین از همین مقدار ساخته می‌شوند.',
				'min'     => 0,
				'max'     => 4,
				'step'    => 0.5,
			),
			'--wcrf-shadow' => array(
				'type'    => 'enum',
				'group'   => 'shape',
				'default' => 'medium',
				'label'   => 'سایه',
				'hint'    => 'سایهٔ کارت بانکی و بلندشدن دکمه در حالت هاور.',
				'options' => array(
					'none'   => array( 'label' => 'بدون سایه', 'value' => 'none' ),
					'soft'   => array( 'label' => 'ملایم', 'value' => '0 2px 10px rgba(15, 23, 42, .10)' ),
					'medium' => array( 'label' => 'متوسط', 'value' => '0 8px 32px rgba(21, 101, 192, .30)' ),
					'strong' => array( 'label' => 'قوی', 'value' => '0 14px 44px rgba(15, 23, 42, .28)' ),
				),
			),
			'--wcrf-pad' => array(
				'type'    => 'length',
				'group'   => 'shape',
				'default' => '20px',
				'label'   => 'فاصلهٔ داخلی اجزا',
				'hint'    => 'با انتخاب «چیدمان فشرده/راحت» خودکار پر می‌شود؛ در حالت «سفارشی» همین عدد استفاده می‌شود.',
				'min'     => 6,
				'max'     => 40,
				'step'    => 1,
			),
			'--wcrf-gap' => array(
				'type'    => 'length',
				'group'   => 'shape',
				'default' => '14px',
				'label'   => 'فاصلهٔ بین اجزا',
				'hint'    => '',
				'min'     => 4,
				'max'     => 32,
				'step'    => 1,
			),
			'--wcrf-content-width' => array(
				'type'    => 'length',
				'group'   => 'shape',
				'default' => '580px',
				'label'   => 'حداکثر عرض فرم',
				'hint'    => 'در ستون‌ها و موبایل، پنل هرگز از عرض ظرف خود پهن‌تر نمی‌شود.',
				'min'     => 320,
				'max'     => 1200,
				'step'    => 10,
			),
		);

		return self::$schema;
	}

	/**
	 * Human labels for the settings-page sections (keyed by `group`).
	 *
	 * @return array
	 */
	public static function groups() {
		return array(
			'color'      => 'رنگ‌ها',
			'typography' => 'تایپوگرافی',
			'shape'      => 'شکل و فاصله‌ها',
		);
	}

	/**
	 * The design modes, with the label the admin sees.
	 *
	 * @return array
	 */
	public static function modes() {
		return array(
			'inherit' => array(
				'label' => 'هماهنگ با قالب (Theme-aware)',
				'hint'  => 'پیش‌فرض. فونت، اندازهٔ متن و رنگ متن از قالب/سیستم پیروی می‌کنند و رنگ‌های افزونه پایه و ایمن می‌مانند.',
			),
			'preset'  => array(
				'label' => 'پیش‌تنظیم (Preset)',
				'hint'  => 'یکی از ظاهرهای آماده را انتخاب کنید؛ می‌توانید بعد از آن مقدارهای دلخواه را هم جایگزین کنید.',
			),
			'custom'  => array(
				'label' => 'سفارشی (Custom)',
				'hint'  => 'فقط مقدارهایی که خودتان وارد می‌کنید اعمال می‌شوند و بقیه از پیش‌فرض‌های افزونه می‌آیند.',
			),
		);
	}

	/**
	 * Visual presets. A preset is a *token set*, never markup or logic: choosing
	 * one swaps values, it does not produce a different form.
	 *
	 * Spacing (`--wcrf-pad`, `--wcrf-gap`) is deliberately owned by the density
	 * choice instead, so a preset and the compact/comfortable switch cannot fight
	 * over the same tokens.
	 *
	 * @return array
	 */
	public static function presets() {
		return array(
			'minimal' => array(
				'label'  => 'Minimal (کمینه)',
				'hint'   => 'مسطح، بدون سایه، گوشه‌های کم‌گرد و پالت خنثی.',
				'tokens' => array(
					'--wcrf-pri'          => '#111827',
					'--wcrf-pri-h'        => '#374151',
					'--wcrf-card-bg'      => '#f3f4f6',
					'--wcrf-card-bg-2'    => '#e5e7eb',
					'--wcrf-card-text'    => '#111827',
					'--wcrf-btn-bg'       => '#111827',
					'--wcrf-btn-bg-2'     => '#111827',
					'--wcrf-btn-bg-h'     => '#000000',
					'--wcrf-radius'       => '6px',
					'--wcrf-radius-card'  => '8px',
					'--wcrf-radius-button' => '6px',
					'--wcrf-radius-field' => '4px',
					'--wcrf-border-width' => '1px',
					'--wcrf-shadow'       => 'none',
					'--wcrf-font-family'  => 'system',
				),
			),
			'modern'  => array(
				'label'  => 'Modern (مدرن)',
				'hint'   => 'گوشه‌های گرد، سایهٔ ملایم و پالت بنفش–نیلی.',
				'tokens' => array(
					'--wcrf-pri'          => '#4f46e5',
					'--wcrf-pri-h'        => '#6366f1',
					'--wcrf-card-bg'      => '#4f46e5',
					'--wcrf-card-bg-2'    => '#7c3aed',
					'--wcrf-card-text'    => '#ffffff',
					'--wcrf-btn-bg'       => '#4f46e5',
					'--wcrf-btn-bg-2'     => '#6366f1',
					'--wcrf-btn-bg-h'     => '#4338ca',
					'--wcrf-radius'       => '16px',
					'--wcrf-radius-card'  => '22px',
					'--wcrf-radius-button' => '12px',
					'--wcrf-radius-field' => '10px',
					'--wcrf-border-width' => '1px',
					'--wcrf-shadow'       => 'soft',
					'--wcrf-font-family'  => 'system',
				),
			),
			'flat'    => array(
				'label'  => 'Flat / Professional (تخت و رسمی)',
				'hint'   => 'گوشه‌های تیز، حاشیهٔ پررنگ و یک رنگ تأکیدی سبز–آبی.',
				'tokens' => array(
					'--wcrf-pri'          => '#0f766e',
					'--wcrf-pri-h'        => '#0d9488',
					'--wcrf-card-bg'      => '#0f766e',
					'--wcrf-card-bg-2'    => '#0f766e',
					'--wcrf-card-text'    => '#ffffff',
					'--wcrf-btn-bg'       => '#0f766e',
					'--wcrf-btn-bg-2'     => '#0f766e',
					'--wcrf-btn-bg-h'     => '#115e59',
					'--wcrf-radius'       => '0px',
					'--wcrf-radius-card'  => '0px',
					'--wcrf-radius-button' => '0px',
					'--wcrf-radius-field' => '0px',
					'--wcrf-border-width' => '2px',
					'--wcrf-shadow'       => 'none',
					'--wcrf-font-family'  => 'sans',
				),
			),
		);
	}

	/**
	 * What “compact” / “comfortable” mean for the spacing tokens.
	 *
	 * @return array
	 */
	public static function densities() {
		return array(
			'comfortable' => array(
				'label'  => 'راحت (پیش‌فرض)',
				'hint'   => 'فاصلهٔ استاندارد برای خواندن آسان.',
				'tokens' => array(),
			),
			'compact'     => array(
				'label'  => 'فشرده',
				'hint'   => 'برای ستون‌های باریک، سایدبار و فرم‌های کوتاه.',
				'tokens' => array(
					'--wcrf-pad' => '12px',
					'--wcrf-gap' => '8px',
				),
			),
			'custom'      => array(
				'label' => 'سفارشی',
				'hint'  => 'فاصلهٔ داخلی و بین اجزا را خودتان تعیین کنید.',
			),
		);
	}

	/**
	 * The theme-palette links: each link maps a group of tokens to the theme.json /
	 * Global Styles custom property of a colour the administrator picked.
	 *
	 * The lookup is best-effort by design: `--wp--preset--color--{slug}` exists when
	 * the theme (or Global Styles) defines that palette colour — a classic theme
	 * without `theme.json` defines none, and every link therefore falls back to the
	 * plugin's own value inside the same `var()` expression. Nothing here writes to
	 * the theme, and no theme colour is ever *changed*.
	 *
	 * @return array
	 */
	public static function links() {
		return array(
			'accent'  => array(
				'label'  => 'رنگ تأکیدی از پالت قالب',
				'hint'   => 'رنگ شمارش معکوس، نوار پیشرفت، دکمهٔ اصلی و کارت بانکی از این رنگ قالب گرفته می‌شود؛ اگر قالب آن را تعریف نکرده باشد، رنگ پیش‌فرض افزونه استفاده می‌شود.',
				'tokens' => array( '--wcrf-pri', '--wcrf-pri-h', '--wcrf-btn-bg', '--wcrf-btn-bg-2', '--wcrf-btn-bg-h', '--wcrf-card-bg', '--wcrf-card-bg-2' ),
			),
			'text'    => array(
				'label'  => 'رنگ متن از پالت قالب',
				'hint'   => 'رنگ متن اصلی پنل از این رنگ قالب گرفته می‌شود.',
				'tokens' => array( '--wcrf-text' ),
			),
			'surface' => array(
				'label'  => 'رنگ پس‌زمینه از پالت قالب',
				'hint'   => 'پس‌زمینهٔ جعبه‌ها و ناحیهٔ بارگذاری از این رنگ قالب گرفته می‌شود.',
				'tokens' => array( '--wcrf-surface', '--wcrf-field-bg' ),
			),
		);
	}

	/* =====================================================================
	 * Reading, resolving, emitting
	 * ===================================================================== */

	/**
	 * The stored appearance settings, always in a complete, safe shape.
	 *
	 * @return array
	 */
	public static function get() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array(
			'mode'        => in_array( isset( $stored['mode'] ) ? $stored['mode'] : '', self::MODES, true ) ? $stored['mode'] : 'inherit',
			'preset'      => array_key_exists( (string) ( isset( $stored['preset'] ) ? $stored['preset'] : '' ), self::presets() ) ? $stored['preset'] : 'modern',
			'density'     => in_array( isset( $stored['density'] ) ? $stored['density'] : '', self::DENSITIES, true ) ? $stored['density'] : 'comfortable',
			'links'       => isset( $stored['links'] ) && is_array( $stored['links'] ) ? $stored['links'] : array(),
			'tokens'      => isset( $stored['tokens'] ) && is_array( $stored['tokens'] ) ? $stored['tokens'] : array(),
			/* Defaults to on: a shop owner who picks one accent colour expects the
			 * button and the card to follow it (see couple_accent()). */
			'couple'      => ( isset( $stored['couple'] ) && 'no' === $stored['couple'] ) ? 'no' : 'yes',
		);
	}

	/**
	 * The plugin's own defaults — the values the stylesheet declares, expressed as
	 * final CSS values (enum keys already resolved).
	 *
	 * @return array
	 */
	public static function defaults() {
		$out = array();
		foreach ( self::schema() as $name => $spec ) {
			$out[ $name ] = self::css_default( $name );
		}
		return $out;
	}

	/**
	 * The CSS value of a token's default.
	 *
	 * @param string $name
	 * @return string
	 */
	public static function css_default( $name ) {
		$schema = self::schema();
		if ( ! isset( $schema[ $name ] ) ) {
			return '';
		}
		$spec = $schema[ $name ];
		if ( 'enum' === $spec['type'] ) {
			return isset( $spec['options'][ $spec['default'] ] ) ? $spec['options'][ $spec['default'] ]['value'] : '';
		}
		return (string) $spec['default'];
	}

	/**
	 * Turn one named choice into its CSS value.
	 *
	 * @param string $name
	 * @param string $key
	 * @return string|null
	 */
	public static function enum_value( $name, $key ) {
		$schema = self::schema();
		if ( ! isset( $schema[ $name ] ) || 'enum' !== $schema[ $name ]['type'] ) {
			return null;
		}
		return isset( $schema[ $name ]['options'][ $key ] ) ? $schema[ $name ]['options'][ $key ]['value'] : null;
	}

	/**
	 * Apply a token map (preset, density, theme links, explicit values) onto a
	 * resolved set. Every value goes through `normalize()`, so a typo in a preset
	 * or a crafted instance override cannot reach the page.
	 *
	 * @param array $tokens Current resolved values.
	 * @param array $map    name => key (enum) or name => CSS value.
	 * @return array
	 */
	private static function apply( array $tokens, $map ) {
		if ( ! is_array( $map ) ) {
			return $tokens;
		}
		foreach ( $map as $name => $value ) {
			$normalized = self::normalize( $name, $value );
			if ( null === $normalized ) {
				continue;
			}
			$tokens[ $name ] = $normalized;
		}
		return $tokens;
	}

	/**
	 * Validate one token value against its schema entry.
	 *
	 * Returns the value in its *final CSS form* (enum key → CSS value, `12` → `12px`),
	 * or null when the name is unknown or the value is out of type/range.
	 *
	 * @param string $name
	 * @param mixed  $value
	 * @return string|null
	 */
	public static function normalize( $name, $value ) {
		$schema = self::schema();
		if ( ! isset( $schema[ $name ] ) ) {
			return null;
		}
		if ( ! is_scalar( $value ) ) {
			return null;
		}
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}
		$spec = $schema[ $name ];
		$type = $spec['type'];

		/* Named choice: only the key is accepted, the CSS value is ours. A CSS value
		 * is tolerated for a stored legacy value or an instance override, but it is
		 * always replaced by the option map's own value. */
		if ( 'enum' === $type ) {
			if ( isset( $spec['options'][ $value ] ) ) {
				return $spec['options'][ $value ]['value'];
			}
			foreach ( $spec['options'] as $option ) {
				if ( $option['value'] === $value ) {
					return $option['value'];
				}
			}
			return null;
		}

		/* The defaults of the theme-aware tokens (`CanvasText`, `inherit`, …) are
		 * keywords, not hex colours: accept them for the colour tokens too, but
		 * only as the exact schema default or as an explicit `inherit`. */
		if ( 'color' === $type ) {
			if ( 'inherit' === $value ) {
				return 'inherit';
			}
			if ( $value === self::css_default( $name ) && ! preg_match( '/^#/', (string) $spec['default'] ) ) {
				return $value;
			}
			if ( preg_match( '/^rgba\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*[\d.]+\s*\)$/', $value ) ) {
				return $value;
			}
			$hex = sanitize_hex_color( $value );
			return $hex ? strtolower( $hex ) : null;
		}

		if ( 'length' === $type ) {
			if ( ! preg_match( '/^(\d+(?:\.\d+)?)(px|em|rem)?$/', $value, $m ) ) {
				return null;
			}
			$unit   = ( isset( $m[2] ) && '' !== $m[2] ) ? $m[2] : 'px'; // a bare number is always a px length
			$ranges = self::ranges( $spec );
			if ( ! isset( $ranges[ $unit ] ) ) {
				return null; // e.g. `3em` for a token that only accepts px
			}
			$num = (float) $m[1];
			$min = (float) $ranges[ $unit ][0];
			$max = (float) $ranges[ $unit ][1];
			$num = round( min( $max, max( $min, $num ) ), 3 );
			return self::trim_number( $num ) . $unit;
		}

		if ( 'ratio' === $type ) {
			if ( ! preg_match( '/^\d+(?:\.\d+)?$/', $value ) ) {
				return null;
			}
			$num = (float) $value;
			$num = min( (float) $spec['max'], max( (float) $spec['min'], $num ) );
			return self::trim_number( round( $num, 2 ) );
		}

		return null;
	}

	/**
	 * The allowed (min,max) range of a length token per unit. A schema entry either
	 * declares `ranges` explicitly (the type-scale tokens accept px and em/rem) or
	 * the simpler `min`/`max` pair, which is a px-only range.
	 *
	 * @param array $spec
	 * @return array unit => array( min, max )
	 */
	private static function ranges( $spec ) {
		if ( ! empty( $spec['ranges'] ) && is_array( $spec['ranges'] ) ) {
			return $spec['ranges'];
		}
		return array( 'px' => array( isset( $spec['min'] ) ? $spec['min'] : 0, isset( $spec['max'] ) ? $spec['max'] : 2000 ) );
	}

	/**
	 * `12.00` → `12`, `1.50` → `1.5`.
	 *
	 * @param float $num
	 * @return string
	 */
	private static function trim_number( $num ) {
		$out = number_format( $num, 2, '.', '' );
		$out = rtrim( rtrim( $out, '0' ), '.' );
		return ( '' === $out ) ? '0' : $out;
	}

	/**
	 * Final character-level guard before a value is written into the style
	 * attribute. Deliberately narrow: letters, digits, space and the punctuation a
	 * CSS value legitimately needs (`,` `.` `#` `%` `(` `)` `-` `_` `"` `'`).
	 * A semicolon, brace, angle bracket, backslash, `!`, `url(...)`, `@import` or
	 * `javascript:` therefore can never survive — no rule can be closed and no new
	 * declaration injected.
	 *
	 * @param string $value
	 * @return bool
	 */
	public static function value_is_safe( $value ) {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 200 ) {
			return false;
		}
		if ( preg_match( '/[;{}<>\\\\!]/', $value ) ) {
			return false;
		}
		if ( preg_match( '/(url\s*\(|expression\s*\(|javascript:|@import|-moz-binding)/i', $value ) ) {
			return false;
		}
		return (bool) preg_match( '/^[A-Za-z0-9 ,.#%()_\-"\']+$/', $value );
	}

	/**
	 * THE RESOLVER — the priority order as code.
	 *
	 *   1. plugin defaults (level 4 base)
	 *   2. global appearance layer (level 3): the mode's value set, the theme-palette
	 *      links, then the administrator's explicit token values (each replacing the
	 *      previous one)
	 *   3. per-instance preset (level 2 — replaces the global look for that instance)
	 *   4. per-instance token overrides (level 1 — the strongest)
	 *   5. spacing: the density choice supplies `--wcrf-pad` / `--wcrf-gap` unless it
	 *      is `custom`
	 *
	 * @param array $instance {
	 *     Optional. Per-instance configuration.
	 *     @type string $preset A preset slug (shortcode `style=` / widget control).
	 *     @type array  $tokens name => CSS value (widget style controls).
	 * }
	 * @return array Token name => final CSS value.
	 */
	public static function resolve( $instance = array() ) {
		$settings = self::get();
		$mode     = $settings['mode'];

		$tokens = self::defaults();
		$layers = array();

		/* --- level 3: the global appearance layer --------------------------- */
		if ( 'preset' === $mode ) {
			$presets = self::presets();
			if ( isset( $presets[ $settings['preset'] ] ) ) {
				$layers['preset_global'] = $presets[ $settings['preset'] ]['tokens'];
			}
		}

		$links = array();
		foreach ( self::links() as $key => $link ) {
			$slug = isset( $settings['links'][ $key ] ) ? self::clean_slug( $settings['links'][ $key ] ) : '';
			if ( '' === $slug ) {
				continue;
			}
			foreach ( $link['tokens'] as $name ) {
				$links[ $name ] = self::theme_expression( $name, $slug );
			}
		}
		if ( $links ) {
			$layers['theme_links'] = $links;
		}
		if ( $settings['tokens'] ) {
			$layers['global'] = $settings['tokens'];
		}

		/* --- levels 2 and 1: this instance only ----------------------------- */
		$instance   = is_array( $instance ) ? $instance : array();
		$presets    = self::presets();
		$i_preset   = isset( $instance['preset'] ) ? (string) $instance['preset'] : '';
		$i_has      = ( '' !== $i_preset && isset( $presets[ $i_preset ] ) );
		if ( $i_has ) {
			/* An instance preset replaces the global look, so the global layer is
			 * dropped and the preset starts from the plugin defaults. */
			$tokens = self::defaults();
			$layers = array( 'preset_instance' => $presets[ $i_preset ]['tokens'] );
		}
		if ( ! empty( $instance['tokens'] ) && is_array( $instance['tokens'] ) ) {
			$layers['instance'] = $instance['tokens'];
		}

		/* --- spacing -------------------------------------------------------- */
		$densities = self::densities();
		if ( 'custom' !== $settings['density'] && isset( $densities[ $settings['density'] ] ) ) {
			$layers['density'] = $densities[ $settings['density'] ]['tokens'];
		}

		foreach ( $layers as $map ) {
			$tokens = self::apply( $tokens, $map );
		}

		/* --- accent coupling --------------------------------------------------
		 * A per-instance preset owns the whole look of that panel, so the global accent
		 * family is not re-applied on top of it. An unknown preset slug is not a preset
		 * (the layer above ignored it) and therefore does not disable the coupling. */
		if ( 'yes' === $settings['couple'] && ! $i_has ) {
			$tokens = self::couple_accent( $tokens, $settings['tokens'] );
		}

		/**
		 * Filters the resolved design tokens of one panel instance.
		 *
		 * Everything is already validated; a value added here is escaped with
		 * `esc_attr()` and goes through `value_is_safe()` once more at print time.
		 *
		 * @param array  $tokens   Token name => CSS value.
		 * @param array  $instance The per-instance configuration.
		 * @param string $mode     The global design mode.
		 */
		$tokens = apply_filters( 'wcr_panel_design_tokens', $tokens, $instance, $mode );

		return is_array( $tokens ) ? self::sanitize_values( $tokens ) : self::defaults();
	}

	/**
	 * Let one accent colour drive the whole accent family.
	 *
	 * A shop owner who changes “رنگ اصلی” reasonably expects the submit button and the
	 * bank card to follow, so the derived tokens (hover shade, button gradient, card
	 * gradient) are computed from that one colour — but only the tokens that are *still
	 * at the plugin default*: a value from a theme-palette link, a preset or an explicit
	 * field always wins, which keeps the documented priority intact.
	 *
	 * Only a real six-digit colour can be shaded; a `var()` expression (a theme palette
	 * link) is left alone, and the plugin's own defaults already form a coherent family.
	 *
	 * @param array $tokens   Resolved tokens.
	 * @param array $explicit Explicitly stored token values.
	 * @return array
	 */
	private static function couple_accent( array $tokens, $explicit ) {
		/* Coupling is about a colour the shop owner *chose*: with nothing stored there is
		 * nothing to couple, which is also why a plugin-default panel emits no styles. */
		if ( ! is_array( $explicit ) || ! isset( $explicit['--wcrf-pri'] ) ) {
			return $tokens;
		}

		$primary = (string) $tokens['--wcrf-pri'];
		if ( ! preg_match( '/^#[0-9a-f]{6}$/i', $primary ) ) {
			return $tokens;
		}

		/* percent: 0 = the colour itself, positive = lighter, negative = darker */
		$family = array(
			'--wcrf-pri-h'     => 12,
			'--wcrf-btn-bg'    => 0,
			'--wcrf-btn-bg-2'  => 12,
			'--wcrf-btn-bg-h'  => -18,
			'--wcrf-card-bg'   => 0,
			'--wcrf-card-bg-2' => 22,
		);

		foreach ( $family as $name => $shift ) {
			if ( is_array( $explicit ) && isset( $explicit[ $name ] ) ) {
				continue; // an explicit field always wins
			}
			if ( $tokens[ $name ] !== self::css_default( $name ) ) {
				continue; // a preset or a theme link already decided this token
			}
			$tokens[ $name ] = ( 0 === $shift ) ? $primary : self::shade_hex( $primary, $shift );
		}

		return $tokens;
	}

	/**
	 * Lighten (positive) or darken (negative) a six-digit hex colour by a percentage.
	 *
	 * Used only to derive the accent family from a colour the administrator picked.
	 *
	 * @param string $hex
	 * @param int    $percent
	 * @return string
	 */
	public static function shade_hex( $hex, $percent ) {
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
	 * The theme custom property expression for a token, with the plugin's own
	 * default as the same-expression fallback.
	 *
	 * @param string $name
	 * @param string $slug
	 * @return string
	 */
	public static function theme_expression( $name, $slug ) {
		/* The plugin's own value is the *fallback inside the same expression*, so a
		 * theme that does not define the preset still renders a correct panel. */
		$fallback = self::css_default( $name );

		/* A hover shade has no palette entry of its own: use the theme's secondary
		 * colour when it has one, else its primary, else the plugin default. */
		if ( '--wcrf-btn-bg-h' === $name ) {
			return 'var(--wp--preset--color--secondary, var(--wp--preset--color--' . $slug . ', ' . $fallback . '))';
		}
		return 'var(--wp--preset--color--' . $slug . ', ' . $fallback . ')';
	}

	/**
	 * Keep only known, safe values in a resolved token map (the final gate before
	 * the declarations are printed).
	 *
	 * @param array $tokens
	 * @return array
	 */
	private static function sanitize_values( array $tokens ) {
		$schema = self::schema();
		$out    = array();
		foreach ( $schema as $name => $spec ) {
			if ( ! isset( $tokens[ $name ] ) || ! is_scalar( $tokens[ $name ] ) ) {
				continue;
			}
			$value = (string) $tokens[ $name ];
			if ( '' === $value || ! self::value_is_safe( $value ) ) {
				continue;
			}
			$out[ $name ] = $value;
		}
		return $out;
	}

	/**
	 * The CSS declaration list of a resolved token map: only the values that
	 * differ from the stylesheet default are emitted, in schema order, so markup
	 * stays deterministic and an untouched install prints nothing at all.
	 *
	 * @param array $tokens
	 * @return string e.g. `--wcrf-pri:#123456;--wcrf-radius:8px`
	 */
	public static function declarations( array $tokens ) {
		$out = array();
		foreach ( self::schema() as $name => $spec ) {
			if ( ! isset( $tokens[ $name ] ) || ! is_scalar( $tokens[ $name ] ) ) {
				continue;
			}
			$value = (string) $tokens[ $name ];
			if ( '' === $value || ! self::value_is_safe( $value ) ) {
				continue;
			}
			if ( $value === self::css_default( $name ) ) {
				continue; // exactly what the stylesheet already says
			}
			$out[] = $name . ':' . $value;
		}
		return implode( ';', $out );
	}

	/**
	 * The ready-to-print `style` attribute for a panel root, or an empty string
	 * when the effective design is the stylesheet default.
	 *
	 * @param array $instance
	 * @return string
	 */
	public static function style_attribute( $instance = array() ) {
		$declarations = self::declarations( self::resolve( $instance ) );
		if ( '' === $declarations ) {
			return '';
		}
		return ' style="' . esc_attr( $declarations ) . '"';
	}

	/**
	 * The resolved tokens of one instance, for the value an admin sees as
	 * “automatic” (settings page preview and hints).
	 *
	 * @param array $instance
	 * @return array
	 */
	public static function preview_tokens( $instance = array() ) {
		return self::resolve( $instance );
	}

	/* =====================================================================
	 * Theme palette
	 * ===================================================================== */

	/**
	 * The colour slugs the current theme/Global Styles actually define
	 * (`wp_get_global_settings`, WordPress 5.9+).
	 *
	 * @return array slug => human name
	 */
	public static function theme_palette() {
		$out = array();
		if ( ! function_exists( 'wp_get_global_settings' ) ) {
			return $out;
		}
		$palette = wp_get_global_settings( array( 'color', 'palette' ) );
		if ( ! is_array( $palette ) ) {
			return $out;
		}
		foreach ( array( 'theme', 'default', 'custom' ) as $origin ) {
			if ( empty( $palette[ $origin ] ) || ! is_array( $palette[ $origin ] ) ) {
				continue;
			}
			foreach ( $palette[ $origin ] as $entry ) {
				if ( ! is_array( $entry ) || empty( $entry['slug'] ) ) {
					continue;
				}
				$slug = self::clean_slug( $entry['slug'] );
				if ( '' === $slug || isset( $out[ $slug ] ) ) {
					continue;
				}
				$out[ $slug ] = isset( $entry['name'] ) && '' !== $entry['name'] ? (string) $entry['name'] : $slug;
			}
		}
		return $out;
	}

	/**
	 * @param mixed $slug
	 * @return string
	 */
	public static function clean_slug( $slug ) {
		if ( ! is_scalar( $slug ) ) {
			return '';
		}
		$slug = preg_replace( '/[^a-z0-9_-]/i', '', (string) $slug );
		return is_string( $slug ) ? substr( $slug, 0, 40 ) : '';
	}

	/* =====================================================================
	 * Validation of the submitted form
	 * ===================================================================== */

	/**
	 * Sanitize callback for the appearance option.
	 *
	 * Every field is validated on the server: modes and densities against their
	 * list, colour slugs against `[a-z0-9_-]`, colours against `sanitize_hex_color`,
	 * lengths against their range, ratios against their range, enum tokens against
	 * their own option map. Anything else is dropped, so a stored value can never
	 * be raw CSS and the page can never carry a crafted declaration.
	 *
	 * An empty field means “automatic”: the token is not stored and the mode/theme
	 * layer decides. Nothing here touches orders, receipts, cards or files.
	 *
	 * @param mixed $input
	 * @return array
	 */
	public static function sanitize( $input ) {
		$clean = array(
			'mode'    => 'inherit',
			'preset'  => 'modern',
			'density' => 'comfortable',
			'links'   => array(),
			'tokens'  => array(),
			'couple'  => 'no',
		);

		if ( ! is_array( $input ) ) {
			return $clean;
		}

		if ( isset( $input['mode'] ) && in_array( $input['mode'], self::MODES, true ) ) {
			$clean['mode'] = $input['mode'];
		}
		if ( isset( $input['preset'] ) && array_key_exists( (string) $input['preset'], self::presets() ) ) {
			$clean['preset'] = (string) $input['preset'];
		}
		if ( isset( $input['density'] ) && in_array( $input['density'], self::DENSITIES, true ) ) {
			$clean['density'] = $input['density'];
		}
		/* An unchecked checkbox is absent from the POST, so this is a plain on/off. */
		$clean['couple'] = ( ! empty( $input['couple'] ) ) ? 'yes' : 'no';

		if ( isset( $input['links'] ) && is_array( $input['links'] ) ) {
			foreach ( self::LINKS as $key ) {
				if ( empty( $input['links'][ $key ] ) || ! is_scalar( $input['links'][ $key ] ) ) {
					continue;
				}
				$value = trim( (string) $input['links'][ $key ] );
				/* The field is a picker of the theme's own palette slugs: a value that
				 * is not already a plain slug (tampered form, stray path) is dropped
				 * instead of being silently repaired. */
				if ( ! preg_match( '/^[a-z0-9_-]{1,40}$/i', $value ) ) {
					continue;
				}
				$clean['links'][ $key ] = $value;
			}
		}

		if ( isset( $input['tokens'] ) && is_array( $input['tokens'] ) ) {
			$schema  = self::schema();
			$density = self::densities();
			$spacing = isset( $density[ $clean['density'] ]['tokens'] ) ? array_keys( $density[ $clean['density'] ]['tokens'] ) : array();

			foreach ( $input['tokens'] as $name => $value ) {
				/* An enum field that was never touched is “automatic”: the empty
				 * option carries an empty value. */
				/* The type-scale fields submit a number plus an explicit unit
				 * (`{num: 16, unit: px|em|rem}`); an empty unit means “automatic”. */
				if ( is_array( $value ) ) {
					$num  = isset( $value['num'] ) ? trim( (string) $value['num'] ) : '';
					$unit = isset( $value['unit'] ) ? trim( (string) $value['unit'] ) : '';
					if ( '' === $num || '' === $unit ) {
						continue;
					}
					$value = $num . $unit;
				}
				if ( '' === trim( (string) $value ) ) {
					continue;
				}
				if ( in_array( $name, $spacing, true ) ) {
					continue; // the density choice owns this token
				}
				/* A named choice is stored as its key, never as a CSS value: the value
				 * stays ours, so a stored setting cannot become raw CSS. */
				if ( isset( $schema[ $name ] ) && 'enum' === $schema[ $name ]['type'] ) {
					if ( isset( $schema[ $name ]['options'][ $value ] ) ) {
						$clean['tokens'][ $name ] = $value;
					}
					continue;
				}
				$normalized = self::normalize( $name, $value );
				if ( null !== $normalized ) {
					$clean['tokens'][ $name ] = $normalized;
				}
			}
		}

		return $clean;
	}
}
