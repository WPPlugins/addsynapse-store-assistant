/**
 * Formatting helpers for ability results (user locale from WP).
 */
import { __ } from '@wordpress/i18n';

const settings = window.addsynapseAssistant || {};
const locale = settings.locale || undefined;

/**
 * Money with the store currency ("" → "—").
 *
 * @param {string|number} value    Amount.
 * @param {string}        currency ISO code.
 * @return {string} Formatted.
 */
export function money( value, currency ) {
	if ( value === '' || value === null || value === undefined ) {
		return '—';
	}
	const n = Number( value );
	if ( Number.isNaN( n ) ) {
		return String( value );
	}
	try {
		return new Intl.NumberFormat( locale, {
			style: currency ? 'currency' : 'decimal',
			currency: currency || undefined,
			minimumFractionDigits: 2,
			maximumFractionDigits: 2,
		} ).format( n );
	} catch ( e ) {
		return `${ n.toFixed( 2 ) } ${ currency || '' }`.trim();
	}
}

/**
 * Integer with grouping.
 *
 * @param {number} value Number.
 * @return {string} Formatted.
 */
export function number( value ) {
	try {
		return new Intl.NumberFormat( locale ).format( Number( value ) || 0 );
	} catch ( e ) {
		return String( value );
	}
}

/**
 * Date (and time) from ISO / Y-m-d.
 *
 * @param {string}  value    Date.
 * @param {boolean} withTime Include time.
 * @return {string} Formatted.
 */
export function date( value, withTime = false ) {
	if ( ! value ) {
		return '—';
	}
	const d = new Date(
		/^\d{4}-\d{2}-\d{2}$/.test( value ) ? `${ value }T00:00:00` : value
	);
	if ( Number.isNaN( d.getTime() ) ) {
		return value;
	}
	try {
		return new Intl.DateTimeFormat(
			locale,
			withTime
				? {
						day: 'numeric',
						month: 'short',
						year: 'numeric',
						hour: '2-digit',
						minute: '2-digit',
				  }
				: { day: 'numeric', month: 'short', year: 'numeric' }
		).format( d );
	} catch ( e ) {
		return d.toLocaleString();
	}
}

/**
 * A front-end link of this site (same origin as the home URL), or ''.
 *
 * @param {string} url URL from a tool result.
 * @return {string} Safe URL or ''.
 */
export function siteLink( url ) {
	const home = settings.homeUrl || '';
	if ( typeof url !== 'string' || ! home ) {
		return '';
	}
	try {
		const a = new URL( url );
		const b = new URL( home );
		return a.origin === b.origin &&
			( a.protocol === 'https:' || a.protocol === 'http:' )
			? url
			: '';
	} catch ( e ) {
		return '';
	}
}

/**
 * Only wp-admin links of this site are rendered as links.
 *
 * @param {string} url URL from a tool result.
 * @return {string} Safe URL or ''.
 */
export function adminLink( url ) {
	const base = settings.adminUrl || '';
	if ( typeof url !== 'string' || ! base || ! url.startsWith( base ) ) {
		return '';
	}
	return url;
}

/**
 * Link that opens a product / order in the store's admin. A direct admin link of this site (wp-admin) when the result
 * has one; otherwise — addsynapse portal, stores that keep their admin URL out of tool results (PrestaShop) — the
 * portal's «open» route (`settings.openUrl`, a single-use link the shop verifies before redirecting). '' = no link.
 *
 * @param {string} url  URL from a tool result.
 * @param {string} kind product | order.
 * @param {number} id   Object id (combination ids included; the store maps them).
 * @return {string} Safe URL or ''.
 */
export function objectLink( url, kind = '', id = 0 ) {
	const direct = adminLink( url );
	if ( direct ) {
		return direct;
	}
	const open = settings.openUrl || '';
	const n = Number( id );
	if (
		! open ||
		! [ 'product', 'order' ].includes( kind ) ||
		! Number.isInteger( n ) ||
		n < 1
	) {
		return '';
	}
	// openUrl = …/open/product/1 → same route with this kind + id.
	return open.replace(
		/\/open\/product\/1(?=$|\?)/,
		`/open/${ kind }/${ n }`
	);
}

/**
 * Platform of the store (commerce contract v1): 'woocommerce' (wp-admin, default) or e.g. 'prestashop' (addsynapse
 * portal). Only labels change — the data shape is the same canonical one.
 */
export const platform = settings.platform || 'woocommerce';

/**
 * Column / field label of the product code: «SKU» (WooCommerce) or «Reference» (PrestaShop).
 *
 * @return {string} Label.
 */
export const skuLabel = () =>
	platform === 'prestashop'
		? __( 'Reference', 'addsynapse-store-assistant' )
		: 'SKU';

/**
 * Translated field name of the product code (change set rows, variation tables): «SKU» / «Reference» (PrestaShop).
 *
 * @return {string} Label.
 */
export const skuName = () =>
	platform === 'prestashop'
		? __( 'Reference', 'addsynapse-store-assistant' )
		: __( 'SKU', 'addsynapse-store-assistant' );

/**
 * Tooltip of an «edit» link: WooCommerce (wp-admin) or the store's own admin (PrestaShop back office).
 *
 * @return {string} Label.
 */
export const editLabel = () =>
	platform === 'woocommerce'
		? __( 'Edit in WooCommerce (new tab)', 'addsynapse-store-assistant' )
		: __(
				'Open in the shop admin (new tab)',
				'addsynapse-store-assistant'
		  );

/**
 * «Variations» / «Combinations» (PrestaShop).
 *
 * @return {string} Label.
 */
export const variationsLabel = () =>
	platform === 'prestashop'
		? __( 'Combinations', 'addsynapse-store-assistant' )
		: __( 'Variations', 'addsynapse-store-assistant' );

/**
 * «Variation» / «Combination» (PrestaShop).
 *
 * @return {string} Label.
 */
export const variationLabel = () =>
	platform === 'prestashop'
		? __( 'Combination', 'addsynapse-store-assistant' )
		: __( 'Variation', 'addsynapse-store-assistant' );

export const stockLabel = ( status ) =>
	( {
		instock: __( 'In stock', 'addsynapse-store-assistant' ),
		outofstock: __( 'Out of stock', 'addsynapse-store-assistant' ),
		onbackorder: __( 'On backorder', 'addsynapse-store-assistant' ),
	} )[ status ] ||
	status ||
	'—';

export const typeLabel = ( type ) =>
	( {
		simple: __( 'Simple', 'addsynapse-store-assistant' ),
		variable: __( 'Variable', 'addsynapse-store-assistant' ),
		grouped: __( 'Grouped', 'addsynapse-store-assistant' ),
		external: __( 'External', 'addsynapse-store-assistant' ),
		variation: variationLabel(),
		// PrestaShop product types (get_product `product_type`).
		standard: __( 'Standard', 'addsynapse-store-assistant' ),
		combinations: __( 'With combinations', 'addsynapse-store-assistant' ),
		pack: __( 'Pack', 'addsynapse-store-assistant' ),
		virtual: __( 'Virtual', 'addsynapse-store-assistant' ),
	} )[ type ] ||
	type ||
	'—';

export const productStatusLabel = ( status ) =>
	( {
		publish: __( 'Published', 'addsynapse-store-assistant' ),
		draft: __( 'Draft', 'addsynapse-store-assistant' ),
		pending: __( 'Pending review', 'addsynapse-store-assistant' ),
		private: __( 'Private', 'addsynapse-store-assistant' ),
	} )[ status ] ||
	status ||
	'—';

export const periodLabel = ( period ) =>
	( {
		today: __( 'Today', 'addsynapse-store-assistant' ),
		'7d': __( 'Last 7 days', 'addsynapse-store-assistant' ),
		'30d': __( 'Last 30 days', 'addsynapse-store-assistant' ),
		month: __( 'This month', 'addsynapse-store-assistant' ),
		custom: __( 'Custom range', 'addsynapse-store-assistant' ),
	} )[ period ] ||
	period ||
	'';

/**
 * Store wall-clock time "2027-01-01T00:00" (computed by the server in the store timezone)
 * → localized text. Formatted as UTC so the browser's timezone never shifts it.
 *
 * @param {string} local Local store time.
 * @return {string} Formatted.
 */
export function storeLocal( local ) {
	const d = new Date( `${ local }:00Z` );
	if ( Number.isNaN( d.getTime() ) ) {
		return local;
	}
	return new Intl.DateTimeFormat( locale, {
		day: 'numeric',
		month: 'short',
		year: 'numeric',
		hour: '2-digit',
		minute: '2-digit',
		hourCycle: 'h23',
		timeZone: 'UTC',
	} ).format( d );
}

/**
 * Unix timestamp → date and time in the STORE timezone (sale dates are store-time days).
 * The server sends ready texts for the card (`*_text`); this is the fallback. WordPress may
 * store an offset ("+02:00") instead of a city: Intl does not accept that everywhere, so the
 * offset is applied by hand and the result formatted as UTC — never the browser's timezone.
 *
 * @param {number} ts Timestamp (seconds).
 * @return {string} Formatted date.
 */
export function storeDate( ts ) {
	const options = {
		day: 'numeric',
		month: 'short',
		year: 'numeric',
		hour: '2-digit',
		minute: '2-digit',
		hourCycle: 'h23',
	};
	const tz = settings.timezone || '';
	const offset = /^([+-])(\d{2}):(\d{2})$/.exec( tz );
	let d = new Date( Number( ts ) * 1000 );
	let timeZone = tz || 'UTC';
	if ( offset ) {
		const minutes =
			( Number( offset[ 2 ] ) * 60 + Number( offset[ 3 ] ) ) *
			( offset[ 1 ] === '-' ? -1 : 1 );
		d = new Date( d.getTime() + minutes * 60000 );
		timeZone = 'UTC';
	}
	try {
		return new Intl.DateTimeFormat( locale, {
			...options,
			timeZone,
		} ).format( d );
	} catch ( e ) {
		return new Intl.DateTimeFormat( locale, {
			...options,
			timeZone: 'UTC',
		} ).format( d );
	}
}
