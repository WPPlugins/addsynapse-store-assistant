/**
 * Change set preview card: summary, before → after table, Approve / Reject, live progress,
 * result and Undo. The live status always comes from the REST API (survives reloads);
 * the tool result only provides the id and the plan-time summary.
 *
 * Everything shown from products is DATA, rendered as text nodes (never HTML).
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Spinner } from '@wordpress/components';
import {
	Icon,
	external,
	arrowUp,
	arrowDown,
	check,
	caution,
	undo,
	repeat,
} from '@wordpress/icons';
import {
	date,
	money,
	number,
	objectLink,
	skuLabel,
	skuName,
	stockLabel,
	storeDate,
	storeLocal,
} from '../lib/format';
import {
	changesetAction,
	errorMessage,
	getChangeset,
	getChangesetItems,
	isNetworkError,
} from '../lib/api';
import { RowCheck } from './ResultView';
import { SeoChangeValue, seoFieldLabel } from './SeoViews';

const PER_PAGE = 10;
const POLL_MS = 1500;

/**
 * Whether the server already did an action (its response may have been lost on the way back).
 */
const DONE_AFTER = {
	approve: ( s ) => s !== 'draft',
	reject: ( s ) => s !== 'draft',
	revert: ( s ) => s !== 'applied' && s !== 'partially_applied',
};

/**
 * Browser-level network failure (fetch TypeError, or apiFetch's fetch_error).
 *
 * @param {*} e Error.
 * @return {boolean} True when the request may not have reached the server.
 */
const lostConnection = ( e ) =>
	isNetworkError( e ) || ( !! e && e.code === 'fetch_error' );

/**
 * Tells the chat that a «Repeat» stored a new card in a conversation (it reloads the thread).
 *
 * @param {number} conversationId Conversation.
 */
const announceReplan = ( conversationId ) => {
	if ( conversationId ) {
		window.dispatchEvent(
			new window.CustomEvent( 'addsynapse-assistant:replanned', {
				detail: { conversationId },
			} )
		);
	}
};
const RUNNING = [ 'applying', 'reverting' ];
const PRICE_FIELDS = [ 'regular_price', 'sale_price' ];
const DATE_FIELDS = [ 'date_on_sale_from', 'date_on_sale_to' ];

export const statusLabel = ( status ) =>
	( {
		draft: __(
			'Preview — waiting for approval',
			'addsynapse-store-assistant'
		),
		applying: __( 'Applying…', 'addsynapse-store-assistant' ),
		applied: __( 'Applied', 'addsynapse-store-assistant' ),
		partially_applied: __(
			'Partially applied',
			'addsynapse-store-assistant'
		),
		rejected: __( 'Rejected', 'addsynapse-store-assistant' ),
		expired: __( 'Expired', 'addsynapse-store-assistant' ),
		reverting: __( 'Undoing…', 'addsynapse-store-assistant' ),
		reverted: __( 'Undone', 'addsynapse-store-assistant' ),
		failed: __( 'Failed', 'addsynapse-store-assistant' ),
	} )[ status ] || status;

const itemStatusLabel = ( status ) =>
	( {
		pending: __( 'Pending', 'addsynapse-store-assistant' ),
		applied: __( 'Applied', 'addsynapse-store-assistant' ),
		conflict: __(
			'Changed meanwhile — skipped',
			'addsynapse-store-assistant'
		),
		failed: __( 'Failed', 'addsynapse-store-assistant' ),
		reverted: __( 'Undone', 'addsynapse-store-assistant' ),
		revert_conflict: __(
			'Not undone (changed later)',
			'addsynapse-store-assistant'
		),
		excluded: __( 'Left out (not approved)', 'addsynapse-store-assistant' ),
	} )[ status ] || status;

const fieldLabel = ( field, change = {} ) => {
	if ( change.seo_part ) {
		return (
			<>
				{ seoFieldLabel( change.seo_part ) }
				{ change.seo_plugin && (
					<span className="aiops-r__sub">{ change.seo_plugin }</span>
				) }
			</>
		);
	}
	if ( /^terms:/.test( field ) ) {
		return change.taxonomy_label || field.replace( /^[a-z_]+:/, '' );
	}
	return baseFieldLabel( field );
};

const baseFieldLabel = ( field ) =>
	( {
		regular_price: __( 'Regular price', 'addsynapse-store-assistant' ),
		sale_price: __( 'Sale price', 'addsynapse-store-assistant' ),
		date_on_sale_from: __( 'Sale starts', 'addsynapse-store-assistant' ),
		date_on_sale_to: __( 'Sale ends', 'addsynapse-store-assistant' ),
		manage_stock: __( 'Track stock', 'addsynapse-store-assistant' ),
		stock_quantity: __( 'Stock', 'addsynapse-store-assistant' ),
		stock_status: __( 'Stock status', 'addsynapse-store-assistant' ),
		name: __( 'Name', 'addsynapse-store-assistant' ),
		status: __( 'Status', 'addsynapse-store-assistant' ),
		catalog_visibility: __(
			'Catalog visibility',
			'addsynapse-store-assistant'
		),
		featured: __( 'Featured', 'addsynapse-store-assistant' ),
		sku: skuName(),
		weight: __( 'Weight', 'addsynapse-store-assistant' ),
		length: __( 'Length', 'addsynapse-store-assistant' ),
		width: __( 'Width', 'addsynapse-store-assistant' ),
		height: __( 'Height', 'addsynapse-store-assistant' ),
		shipping_class_id: __( 'Shipping class', 'addsynapse-store-assistant' ),
		tax_status: __( 'Tax status', 'addsynapse-store-assistant' ),
		tax_class: __( 'Tax class', 'addsynapse-store-assistant' ),
		backorders: __( 'Backorders', 'addsynapse-store-assistant' ),
		low_stock_amount: __(
			'Low stock threshold',
			'addsynapse-store-assistant'
		),
		sold_individually: __(
			'Sold individually',
			'addsynapse-store-assistant'
		),
		purchase_note: __( 'Purchase note', 'addsynapse-store-assistant' ),
		menu_order: __( 'Menu order', 'addsynapse-store-assistant' ),
		image_id: __( 'Product image', 'addsynapse-store-assistant' ),
		gallery_image_ids: __(
			'Product gallery',
			'addsynapse-store-assistant'
		),
		virtual: __( 'Virtual', 'addsynapse-store-assistant' ),
	} )[ field ] || field;

const FIELD_NAMES = () => ( {
	name: __( 'name', 'addsynapse-store-assistant' ),
	short_description: __( 'short description', 'addsynapse-store-assistant' ),
	description: __( 'description', 'addsynapse-store-assistant' ),
	status: __( 'status', 'addsynapse-store-assistant' ),
	catalog_visibility: __(
		'catalog visibility',
		'addsynapse-store-assistant'
	),
	featured: __( 'featured', 'addsynapse-store-assistant' ),
	sku: __( 'SKU', 'addsynapse-store-assistant' ),
	weight: __( 'weight', 'addsynapse-store-assistant' ),
	length: __( 'length', 'addsynapse-store-assistant' ),
	width: __( 'width', 'addsynapse-store-assistant' ),
	height: __( 'height', 'addsynapse-store-assistant' ),
	shipping_class_id: __( 'shipping class', 'addsynapse-store-assistant' ),
	tax_status: __( 'tax status', 'addsynapse-store-assistant' ),
	tax_class: __( 'tax class', 'addsynapse-store-assistant' ),
	manage_stock: __( 'stock tracking', 'addsynapse-store-assistant' ),
	stock_quantity: __( 'stock', 'addsynapse-store-assistant' ),
	backorders: __( 'backorders', 'addsynapse-store-assistant' ),
	low_stock_amount: __( 'low stock threshold', 'addsynapse-store-assistant' ),
	sold_individually: __( 'sold individually', 'addsynapse-store-assistant' ),
	purchase_note: __( 'purchase note', 'addsynapse-store-assistant' ),
	menu_order: __( 'menu order', 'addsynapse-store-assistant' ),
	image_id: __( 'image', 'addsynapse-store-assistant' ),
	gallery_image_ids: __( 'gallery', 'addsynapse-store-assistant' ),
	virtual: __( 'virtual', 'addsynapse-store-assistant' ),
} );

/**
 * Stored value → text.
 *
 * @param {string} field    Field.
 * @param {*}      value    Value (prices as strings, dates as unix timestamps).
 * @param {string} currency Currency.
 * @return {string} Text.
 */
function valueText( field, value, currency ) {
	if ( value === null || value === undefined || value === '' ) {
		return '—';
	}
	if ( PRICE_FIELDS.includes( field ) ) {
		return money( value, currency );
	}
	if ( DATE_FIELDS.includes( field ) ) {
		return storeDate( value );
	}
	if ( Array.isArray( value ) ) {
		return value.join( ', ' );
	}
	if ( field === 'manage_stock' ) {
		return value
			? __( 'Yes', 'addsynapse-store-assistant' )
			: __( 'No', 'addsynapse-store-assistant' );
	}
	if ( field === 'stock_status' ) {
		return stockLabel( value );
	}
	if ( field === 'stock_quantity' ) {
		return number( value );
	}
	return String( value );
}

/**
 * Server reasons are written in English for the model; translate the known ones.
 *
 * @param {string} reason Reason.
 * @return {string} Text.
 */
export function reasonText( reason ) {
	if ( ! reason ) {
		return '';
	}
	const rules = [
		[
			/^Changed since the preview \(now: (.*)\)\.$/,
			( m ) =>
				sprintf(
					/* translators: %s: current value */ __(
						'Changed since the preview (now: %s).',
						'addsynapse-store-assistant'
					),
					m[ 1 ]
				),
		],
		[
			/^Skipped: another field of this product changed/,
			() =>
				__(
					'Skipped: another field of this product changed since the preview.',
					'addsynapse-store-assistant'
				),
		],
		[
			/^Changed after the update \(now: (.*)\); not restored\.$/,
			( m ) =>
				sprintf(
					/* translators: %s: current value */ __(
						'Changed after the update (now: %s); not restored.',
						'addsynapse-store-assistant'
					),
					m[ 1 ]
				),
		],
		[
			/^Not restored: another field/,
			() =>
				__(
					'Not restored: another field of this product changed after the update.',
					'addsynapse-store-assistant'
				),
		],
		[
			/^Product not found/,
			() => __( 'Product not found.', 'addsynapse-store-assistant' ),
		],
		[
			/^The SKU "(.*)" is already used by another product\.$/,
			( m ) =>
				sprintf(
					/* translators: %s: SKU */ __(
						'The SKU “%s” is already used by another product.',
						'addsynapse-store-assistant'
					),
					m[ 1 ]
				),
		],
		[
			/^Image (\d+) is not in the media library\.$/,
			( m ) =>
				sprintf(
					/* translators: %s: image id */ __(
						'Image %s is not in the media library.',
						'addsynapse-store-assistant'
					),
					m[ 1 ]
				),
		],
		[
			/^No product or variation has this SKU/,
			() =>
				__(
					'No product or variation has this SKU.',
					'addsynapse-store-assistant'
				),
		],
		[
			/^Grouped products have no price/,
			() =>
				__(
					'Grouped products have no price of their own.',
					'addsynapse-store-assistant'
				),
		],
		[
			/^It has no regular price to calculate from/,
			() =>
				__(
					'It has no regular price to calculate from.',
					'addsynapse-store-assistant'
				),
		],
		[
			/^It has no regular price, so a sale price/,
			() =>
				__(
					'It has no regular price, so a sale price cannot be set.',
					'addsynapse-store-assistant'
				),
		],
		[
			/^The new price would be (.*) \(zero or negative\)/,
			( m ) =>
				sprintf(
					/* translators: %s: price */ __(
						'The new price would be %s (zero or negative).',
						'addsynapse-store-assistant'
					),
					m[ 1 ]
				),
		],
		[
			/^The sale price (\S+) would not be lower than the regular price (\S+)\./,
			( m ) =>
				sprintf(
					/* translators: 1: sale price, 2: regular price */ __(
						'The sale price %1$s would not be lower than the regular price %2$s.',
						'addsynapse-store-assistant'
					),
					m[ 1 ],
					m[ 2 ]
				),
		],
		[
			/^The new regular price (\S+) would not be above the current sale price (\S+)\./,
			( m ) =>
				sprintf(
					/* translators: 1: new regular price, 2: sale price */ __(
						'The new regular price %1$s would not be above the current sale price %2$s.',
						'addsynapse-store-assistant'
					),
					m[ 1 ],
					m[ 2 ]
				),
		],
		[
			/^Stock is not tracked/,
			() =>
				__(
					'Stock is not tracked for it (manage stock is off).',
					'addsynapse-store-assistant'
				),
		],
		[
			/^Stock would become (-?\d+) and this product does not allow backorders/,
			( m ) =>
				sprintf(
					/* translators: %s: quantity */ __(
						'Stock would become %s and backorders are not allowed.',
						'addsynapse-store-assistant'
					),
					m[ 1 ]
				),
		],
		[
			/^This product type does not hold stock/,
			() =>
				__(
					'This product type does not hold stock.',
					'addsynapse-store-assistant'
				),
		],
		[
			/^Listed more than once/,
			() =>
				__(
					'Listed more than once with different quantities; kept the first.',
					'addsynapse-store-assistant'
				),
		],
		[
			/^WooCommerce refused the change: (.*)$/,
			( m ) =>
				sprintf(
					/* translators: %s: error */ __(
						'WooCommerce refused the change: %s',
						'addsynapse-store-assistant'
					),
					m[ 1 ]
				),
		],
		[
			/^WooCommerce stored (.*)\.$/,
			( m ) =>
				sprintf(
					/* translators: %s: value */ __(
						'WooCommerce stored %s.',
						'addsynapse-store-assistant'
					),
					m[ 1 ]
				),
		],
		[
			/^Not deleted: the term is still used by (\d+) products/,
			( m ) =>
				sprintf(
					/* translators: %s: number of products */ __(
						'Not deleted: the term is still used by %s products.',
						'addsynapse-store-assistant'
					),
					m[ 1 ]
				),
		],
		[
			/^The term already existed/,
			() =>
				__(
					'The term already existed; it was used and will not be deleted on undo.',
					'addsynapse-store-assistant'
				),
		],
		[
			/^The term existed before/,
			() =>
				__(
					'The term existed before; kept.',
					'addsynapse-store-assistant'
				),
		],
		[
			/^The new term could not be created/,
			() =>
				__(
					'The new term could not be created.',
					'addsynapse-store-assistant'
				),
		],
		[
			/^The taxonomy is no longer available/,
			() =>
				__(
					'The taxonomy is no longer available.',
					'addsynapse-store-assistant'
				),
		],
		[
			/^Not approved: left out by the manager/,
			() =>
				__(
					'Not approved: you left it out.',
					'addsynapse-store-assistant'
				),
		],
		[
			/^The SEO plugin changed since the preview \(now: (.*)\)\.$/,
			( m ) =>
				sprintf(
					/* translators: %s: SEO plugin */ __(
						'The SEO plugin changed since the preview (now: %s).',
						'addsynapse-store-assistant'
					),
					m[ 1 ]
				),
		],
		[
			/^SEO texts belong to the parent product \(id (\d+)\)/,
			( m ) =>
				sprintf(
					/* translators: %s: product id */ __(
						'SEO texts belong to the parent product (id %s).',
						'addsynapse-store-assistant'
					),
					m[ 1 ]
				),
		],
		[
			/^The product is in the trash/,
			() =>
				__(
					'The product is in the trash.',
					'addsynapse-store-assistant'
				),
		],
		[
			/^It has several values for this SEO field/,
			() =>
				__(
					'It has several values for this SEO field; it is left unchanged.',
					'addsynapse-store-assistant'
				),
		],
		[
			/^Its SEO field holds structured data/,
			() =>
				__(
					'Its SEO field holds structured data; it is left unchanged.',
					'addsynapse-store-assistant'
				),
		],
		[
			/^Not needed: the products that use it were left out/,
			() =>
				__(
					'Not created: the products that use it were left out.',
					'addsynapse-store-assistant'
				),
		],
	];
	for ( const [ re, fn ] of rules ) {
		const m = re.exec( reason );
		if ( m ) {
			return fn( m );
		}
	}
	return reason;
}

/**
 * Warnings are English for the model too.
 *
 * @param {string} text Warning.
 * @return {string} Text.
 */
function warningText( text ) {
	if ( text === 'external_link' ) {
		return __(
			'Some links go to other sites — check them before approving.',
			'addsynapse-store-assistant'
		);
	}
	let m = /^(\d+) products\/variations were skipped/.exec( text );
	if ( m ) {
		return sprintf(
			/* translators: %s: number */ __(
				'%s products/variations were skipped (see below).',
				'addsynapse-store-assistant'
			),
			m[ 1 ]
		);
	}
	m = /^(\d+) products will have negative stock/.exec( text );
	if ( m ) {
		return sprintf(
			/* translators: %s: number */ __(
				'%s products will have negative stock (they allow backorders).',
				'addsynapse-store-assistant'
			),
			m[ 1 ]
		);
	}
	m = /^(\d+) products have a sale end date in the past/.exec( text );
	if ( m ) {
		return sprintf(
			/* translators: %s: number */ __(
				'%s products have a sale end date in the past: their sale price will not show until the sale dates change.',
				'addsynapse-store-assistant'
			),
			m[ 1 ]
		);
	}
	m = /^(\d+) variations were mapped to their parent products/.exec( text );
	if ( m ) {
		return sprintf(
			/* translators: %s: number */ __(
				'%s variations were mapped to their parent products (categories, tags and brands belong to the parent).',
				'addsynapse-store-assistant'
			),
			m[ 1 ]
		);
	}
	m = /^(\d+) products would be left without a category/.exec( text );
	if ( m ) {
		return sprintf(
			/* translators: %s: number */ __(
				'%s products would be left without a category, so WooCommerce keeps its default category on them.',
				'addsynapse-store-assistant'
			),
			m[ 1 ]
		);
	}
	m = /^The store timezone is (\S+) \(no city/.exec( text );
	if ( m ) {
		return sprintf(
			/* translators: %s: timezone like UTC+00:00 */ __(
				'The store timezone is %s (no city is set in Settings → General): the sale starts and ends at midnight in that timezone.',
				'addsynapse-store-assistant'
			),
			m[ 1 ]
		);
	}
	m = /^The store has more than one brand storage; used (\S+)/.exec( text );
	if ( m ) {
		return sprintf(
			/* translators: %s: taxonomy */ __(
				'The store keeps brands in more than one place; this plan uses %s.',
				'addsynapse-store-assistant'
			),
			m[ 1 ]
		);
	}
	m = /^(\S+) has no dedicated adapter/.exec( text );
	if ( m ) {
		return sprintf(
			/* translators: %s: taxonomy */ __(
				'%s has no dedicated adapter; its terms are set with the standard WordPress functions.',
				'addsynapse-store-assistant'
			),
			m[ 1 ]
		);
	}
	m = /^"(.*)" is not a term of (\S+), so there is nothing to remove\.$/.exec(
		text
	);
	if ( m ) {
		return sprintf(
			/* translators: %s: term name */ __(
				'“%s” does not exist, so there is nothing to remove.',
				'addsynapse-store-assistant'
			),
			m[ 1 ]
		);
	}
	m =
		/^SEO title outside the recommended (\d+)–(\d+) characters: products (.*)\.$/.exec(
			text
		);
	if ( m ) {
		return sprintf(
			/* translators: 1: min, 2: max, 3: product ids */ __(
				'SEO title outside the recommended %1$s–%2$s characters: products %3$s.',
				'addsynapse-store-assistant'
			),
			m[ 1 ],
			m[ 2 ],
			m[ 3 ]
		);
	}
	m =
		/^Meta description outside the recommended (\d+)–(\d+) characters: products (.*)\.$/.exec(
			text
		);
	if ( m ) {
		return sprintf(
			/* translators: 1: min, 2: max, 3: product ids */ __(
				'Meta description outside the recommended %1$s–%2$s characters: products %3$s.',
				'addsynapse-store-assistant'
			),
			m[ 1 ],
			m[ 2 ],
			m[ 3 ]
		);
	}
	m = /^The focus keyword is not in the SEO title: products (.*)\.$/.exec(
		text
	);
	if ( m ) {
		return sprintf(
			/* translators: %s: product ids */ __(
				'The focus keyword is not in the SEO title: products %s.',
				'addsynapse-store-assistant'
			),
			m[ 1 ]
		);
	}
	m =
		/^The focus keyword is not in the meta description: products (.*)\.$/.exec(
			text
		);
	if ( m ) {
		return sprintf(
			/* translators: %s: product ids */ __(
				'The focus keyword is not in the meta description: products %s.',
				'addsynapse-store-assistant'
			),
			m[ 1 ]
		);
	}
	m = /^Duplicate SEO titles: products (.*) have the same title\.$/.exec(
		text
	);
	if ( m ) {
		return sprintf(
			/* translators: %s: product ids */ __(
				'Duplicate SEO titles: products %s have the same title.',
				'addsynapse-store-assistant'
			),
			m[ 1 ]
		);
	}
	m =
		/^Text does not look written in the store language \((\S+)\): products (.*)\.$/.exec(
			text
		);
	if ( m ) {
		return sprintf(
			/* translators: %s: product ids */ __(
				'The text does not look written in the store language: products %s.',
				'addsynapse-store-assistant'
			),
			m[ 2 ]
		);
	}
	m =
		/^Disallowed HTML \((.*)\) was removed from the descriptions of products (.*)\.$/.exec(
			text
		);
	if ( m ) {
		return sprintf(
			/* translators: 1: removed HTML, 2: product ids */ __(
				'Disallowed HTML (%1$s) was removed from the descriptions of products %2$s.',
				'addsynapse-store-assistant'
			),
			m[ 1 ],
			m[ 2 ]
		);
	}
	m =
		/^Another SEO plugin is also active \((.*)\); only the (.*) fields are changed\.$/.exec(
			text
		);
	if ( m ) {
		return sprintf(
			/* translators: 1: other SEO plugin, 2: SEO plugin used */ __(
				'Another SEO plugin is also active (%1$s); only the %2$s fields are changed.',
				'addsynapse-store-assistant'
			),
			m[ 1 ],
			m[ 2 ]
		);
	}
	m = /^No (category|tag) matches "(.*)"\.$/.exec( text );
	if ( m ) {
		return sprintf(
			/* translators: %s: name */ __(
				'No category or tag matches “%s”.',
				'addsynapse-store-assistant'
			),
			m[ 2 ]
		);
	}
	return text;
}

const signed = ( value ) =>
	Number( value ) > 0 ? `+${ value }` : String( value );

/**
 * One-line description of what a change set does.
 *
 * @param {string} operation price_update | stock_update.
 * @param {Object} p         Params.
 * @param {string} currency  Currency.
 * @return {string} Text.
 */
export function describe( operation, p = {}, currency = '' ) {
	if ( operation === 'price_update' ) {
		const field =
			p.target === 'sale'
				? __( 'Sale price', 'addsynapse-store-assistant' )
				: __( 'Regular price', 'addsynapse-store-assistant' );
		let text;
		if ( p.mode === 'percent' ) {
			text =
				p.target === 'sale'
					? sprintf(
							/* translators: %s: percent like -20 */ __(
								'Sale price %s%% of the regular price',
								'addsynapse-store-assistant'
							),
							signed( p.value )
					  )
					: `${ field } ${ signed( p.value ) }%`;
		} else if ( p.mode === 'fixed' ) {
			const amount =
				( Number( p.value ) > 0 ? '+' : '−' ) +
				money( Math.abs( Number( p.value ) ), currency );
			text =
				p.target === 'sale'
					? sprintf(
							/* translators: %s: amount like −2,00 € */ __(
								'Sale price %s from the regular price',
								'addsynapse-store-assistant'
							),
							amount
					  )
					: `${ field } ${ amount }`;
		} else {
			text = `${ field } = ${ money( p.value, currency ) }`;
		}
		if ( p.sale_from || p.sale_to ) {
			text += ` (${ p.sale_from ? date( p.sale_from ) : '…' } – ${
				p.sale_to ? date( p.sale_to ) : '…'
			})`;
		}
		if ( p.rounding && p.rounding !== '2dp' ) {
			text +=
				p.rounding === 'none'
					? ` · ${ __(
							'no rounding',
							'addsynapse-store-assistant'
					  ) }`
					: ` · ${ sprintf(
							/* translators: %s: .90 or .99 */ __(
								'rounded up to %s',
								'addsynapse-store-assistant'
							),
							p.rounding.replace( 'x', '' )
					  ) }`;
		}
		return text;
	}
	if ( operation === 'taxonomy_terms' || operation === 'brand_assign' ) {
		const terms = ( p.terms || [] ).join( ', ' );
		const what = p.taxonomy_label || p.taxonomy || '';
		let text;
		if ( operation === 'brand_assign' && p.brand_mode === 'set' ) {
			text = sprintf(
				/* translators: %s: brand name */ __(
					'Set brand: %s',
					'addsynapse-store-assistant'
				),
				terms
			);
		} else if ( p.mode === 'add' ) {
			text = sprintf(
				/* translators: 1: taxonomy like Categories, 2: terms */ __(
					'%1$s: add %2$s',
					'addsynapse-store-assistant'
				),
				what,
				terms
			);
		} else if ( p.mode === 'remove' ) {
			text = sprintf(
				/* translators: 1: taxonomy like Categories, 2: terms */ __(
					'%1$s: remove %2$s',
					'addsynapse-store-assistant'
				),
				what,
				terms
			);
		} else {
			text = sprintf(
				/* translators: 1: taxonomy like Categories, 2: terms */ __(
					'%1$s: replace with %2$s',
					'addsynapse-store-assistant'
				),
				what,
				terms
			);
		}
		if ( operation === 'brand_assign' && p.brand_plugin ) {
			text += ` · ${ p.brand_plugin }`;
		}
		return text;
	}
	if ( operation === 'seo_update' ) {
		const names = {
			seo_title: __( 'SEO title', 'addsynapse-store-assistant' ),
			seo_description: __(
				'meta description',
				'addsynapse-store-assistant'
			),
			focus_keyword: __( 'focus keyword', 'addsynapse-store-assistant' ),
			short_description: __(
				'short description',
				'addsynapse-store-assistant'
			),
			description: __( 'description', 'addsynapse-store-assistant' ),
		};
		const fields = ( p.fields || [] )
			.map( ( f ) => names[ f ] || f )
			.join( ', ' );
		return p.seo_plugin && p.seo_plugin !== 'none'
			? sprintf(
					/* translators: 1: fields, 2: SEO plugin */ __(
						'SEO: %1$s · %2$s',
						'addsynapse-store-assistant'
					),
					fields,
					p.seo_plugin_label || p.seo_plugin
			  )
			: sprintf(
					/* translators: %s: fields */ __(
						'Product texts: %s',
						'addsynapse-store-assistant'
					),
					fields
			  );
	}
	if ( operation === 'product_update' ) {
		const names = FIELD_NAMES();
		return sprintf(
			/* translators: %s: list of fields, e.g. "name, weight, featured" */
			__( 'Product details: %s', 'addsynapse-store-assistant' ),
			( p.fields || [] ).map( ( f ) => names[ f ] || f ).join( ', ' )
		);
	}
	if ( operation === 'stock_update' ) {
		let text;
		if ( p.value === undefined ) {
			text = __( 'Stock per product', 'addsynapse-store-assistant' );
		} else if ( p.mode === 'adjust' ) {
			text = `${ __( 'Stock', 'addsynapse-store-assistant' ) } ${ signed(
				p.value
			) }`;
		} else {
			text = `${ __( 'Stock', 'addsynapse-store-assistant' ) } = ${
				p.value
			}`;
		}
		if ( p.enable_manage_stock ) {
			text += ` · ${ __(
				'turn on stock tracking',
				'addsynapse-store-assistant'
			) }`;
		}
		return text;
	}
	return operation;
}

/**
 * Selector chips (category, search, SKUs…).
 *
 * @param {Object} p Params.
 * @return {Array} [label, value] pairs.
 */
function selectorChips( p = {} ) {
	const chips = [];
	if ( p.skus && p.skus.length ) {
		chips.push( [
			skuLabel(),
			p.skus.slice( 0, 5 ).join( ', ' ) +
				( p.skus.length > 5 ? '…' : '' ),
		] );
	}
	if ( p.ids && p.ids.length ) {
		chips.push( [
			'ID',
			p.ids.slice( 0, 5 ).join( ', ' ) + ( p.ids.length > 5 ? '…' : '' ),
		] );
	}
	return chips;
}

function Direction( { field, before, after } ) {
	if ( ! PRICE_FIELDS.includes( field ) && field !== 'stock_quantity' ) {
		return null;
	}
	const a = Number( before );
	const b = Number( after );
	if (
		before === '' ||
		before === null ||
		Number.isNaN( a ) ||
		Number.isNaN( b ) ||
		a === b
	) {
		return null;
	}
	return (
		<Icon
			icon={ b > a ? arrowUp : arrowDown }
			size={ 14 }
			className={ `aiops-cs__dir ${ b > a ? 'is-up' : 'is-down' }` }
		/>
	);
}

const termKey = ( t ) => ( t.new ? `new:${ t.name }` : `id:${ t.id }` );

/**
 * Term chips: on the "after" side added terms are highlighted (new terms flagged), on the
 * "before" side removed terms are struck through.
 *
 * @param {Object} props       Props.
 * @param {Array}  props.terms Terms [{id, name, new}].
 * @param {Array}  props.other The other side.
 * @param {string} props.side  before|after.
 */
function TermChips( { terms, other = [], side } ) {
	if ( ! terms || ! terms.length ) {
		return <span className="aiops-cs__none">—</span>;
	}
	const otherKeys = ( other || [] ).map( termKey );
	return (
		<span className="aiops-cs__terms">
			{ terms.map( ( t ) => {
				const changed = ! otherKeys.includes( termKey( t ) );
				let cls = 'aiops-cs__term';
				if ( changed ) {
					cls += side === 'after' ? ' is-added' : ' is-removed';
				}
				if ( t.new ) {
					cls += ' is-new';
				}
				return (
					<span className={ cls } key={ termKey( t ) }>
						{ t.name }
						{ t.new && (
							<em>
								{ __( 'new', 'addsynapse-store-assistant' ) }
							</em>
						) }
					</span>
				);
			} ) }
		</span>
	);
}

/**
 * The typed unit key of a row ("product:12", "variation:34", "combination:7"): what the checkboxes select and a
 * partial approval sends. Ids of different object types may collide (PrestaShop), so a plain id is never enough;
 * old servers without `unit` fall back to the id (they only ever had one id space per card).
 *
 * @param {Object} row Row.
 * @return {string} Unit key.
 */
function unitOf( row ) {
	return row.unit ? String( row.unit ) : String( row.object_id );
}

/**
 * Is a unit key in a set of left-out units? Legacy entries (plain ids from older drafts) match by id.
 *
 * @param {Set}    set Left-out units.
 * @param {string} key Unit key.
 * @return {boolean} Left out.
 */
function isLeftOut( set, key ) {
	return set.has( key ) || set.has( key.split( ':' ).pop() );
}

/**
 * Product images of one side (image / gallery changes); on the "after" side new images are highlighted.
 *
 * @param {Object} props        Props.
 * @param {Array}  props.images Images [{id, url, title}].
 * @param {?Array} props.other  The other side.
 * @return {Element} Images.
 */
function ImageStrip( { images, other = null } ) {
	const before = ( other || [] ).map( ( i ) => i.id );
	return (
		<span className="aiops-cs__images">
			{ images.map( ( img ) => (
				<span
					key={ img.id }
					className={ `aiops-cs__image${
						other && ! before.includes( img.id ) ? ' is-added' : ''
					}` }
					title={ img.title || `#${ img.id }` }
				>
					{ img.url ? (
						<img src={ img.url } alt={ img.title || '' } />
					) : (
						<span className="aiops-cs__image-missing">
							#{ img.id }
						</span>
					) }
				</span>
			) ) }
		</span>
	);
}

/**
 * Before → after table. With `select` (draft only) every product gets a checkbox (default on)
 * and the header one selects / unselects every product of the change set (all pages).
 *
 * @param {Object}      props            Props.
 * @param {Array}       props.rows       Rows.
 * @param {string}      props.currency   Currency.
 * @param {boolean}     props.showStatus Show item results.
 * @param {Object|null} props.select     { isOn(unit), toggle(unit, on), all, some, toggleAll(on) }.
 */
function ItemsTable( { rows, currency, showStatus, select = null } ) {
	return (
		<div className="aiops-table-wrap">
			<table
				className={ `aiops-table aiops-cs__table${
					select ? ' is-selectable' : ''
				}` }
			>
				<thead>
					<tr>
						{ select && (
							<th className="aiops-check">
								<RowCheck
									checked={ select.all }
									indeterminate={ select.some }
									label={ __(
										'Select all products of this change',
										'addsynapse-store-assistant'
									) }
									onChange={ ( on ) =>
										select.toggleAll( on )
									}
								/>
							</th>
						) }
						<th>
							{ __( 'Product', 'addsynapse-store-assistant' ) }
						</th>
						<th>{ __( 'Field', 'addsynapse-store-assistant' ) }</th>
						<th>
							{ __( 'Before', 'addsynapse-store-assistant' ) }
						</th>
						<th>{ __( 'After', 'addsynapse-store-assistant' ) }</th>
						{ showStatus && (
							<th>
								{ __( 'Result', 'addsynapse-store-assistant' ) }
							</th>
						) }
					</tr>
				</thead>
				<tbody>
					{ rows.map( ( row ) =>
						row.changes.map( ( c, i ) => {
							const link = objectLink(
								row.edit_link,
								'product',
								row.parent_id || row.object_id
							);
							const off =
								select && ! select.isOn( unitOf( row ) );
							return (
								<tr
									key={ `${ row.object_id }-${ c.field }` }
									className={ `is-item-${ c.status }${
										off ? ' is-unselected' : ''
									}` }
								>
									{ select && i === 0 && (
										<td
											rowSpan={ row.changes.length }
											className="aiops-check"
											data-label={ __(
												'Apply',
												'addsynapse-store-assistant'
											) }
										>
											<RowCheck
												checked={ ! off }
												label={ sprintf(
													/* translators: %s: product name */ __(
														'Apply the change to %s',
														'addsynapse-store-assistant'
													),
													row.name
												) }
												onChange={ ( on ) =>
													select.toggle(
														unitOf( row ),
														on
													)
												}
											/>
										</td>
									) }
									{ i === 0 && (
										<td
											rowSpan={ row.changes.length }
											data-label={ __(
												'Product',
												'addsynapse-store-assistant'
											) }
										>
											{ link ? (
												<a
													className="aiops-r__link"
													href={ link }
													target="_blank"
													rel="noopener noreferrer"
												>
													<span className="aiops-r__name">
														{ row.name }
													</span>
													<Icon
														icon={ external }
														size={ 14 }
													/>
												</a>
											) : (
												<span className="aiops-r__name">
													{ row.name }
												</span>
											) }
											{ row.sku && (
												<span className="aiops-r__sub">
													{ row.sku }
												</span>
											) }
										</td>
									) }
									<td
										data-label={ __(
											'Field',
											'addsynapse-store-assistant'
										) }
									>
										{ fieldLabel( c.field, c ) }
									</td>
									<td
										data-label={ __(
											'Before',
											'addsynapse-store-assistant'
										) }
										className="aiops-cs__before"
									>
										{ c.before_terms && (
											<TermChips
												terms={ c.before_terms }
												other={ c.after_terms }
												side="before"
											/>
										) }
										{ c.seo_part && (
											<SeoChangeValue
												c={ c }
												side="before"
											/>
										) }

										{ c.before_images &&
											c.before_images.length > 0 && (
												<ImageStrip
													images={ c.before_images }
												/>
											) }
										{ ! c.seo_part &&
											! c.before_terms &&
											( ( c.before_local &&
												storeLocal(
													c.before_local
												) ) ||
												c.before_text ||
												valueText(
													c.field,
													c.before,
													currency
												) ) }
									</td>
									<td
										data-label={ __(
											'After',
											'addsynapse-store-assistant'
										) }
										className="aiops-cs__after"
									>
										{ c.after_terms && (
											<TermChips
												terms={ c.after_terms }
												other={ c.before_terms }
												side="after"
											/>
										) }

										{ c.seo_part && (
											<strong>
												<SeoChangeValue
													c={ c }
													side="after"
												/>
											</strong>
										) }
										{ c.after_images &&
											c.after_images.length > 0 && (
												<ImageStrip
													images={ c.after_images }
													other={ c.before_images }
												/>
											) }
										{ ! c.seo_part && ! c.after_terms && (
											<strong>
												{ ( c.after_local &&
													storeLocal(
														c.after_local
													) ) ||
													c.after_text ||
													valueText(
														c.field,
														c.after,
														currency
													) }
											</strong>
										) }
										<Direction
											field={ c.field }
											before={ c.before }
											after={ c.after }
										/>
									</td>
									{ showStatus && (
										<td
											data-label={ __(
												'Result',
												'addsynapse-store-assistant'
											) }
										>
											<span
												className={ `aiops-badge is-item-${ c.status }` }
											>
												{ itemStatusLabel( c.status ) }
											</span>
											{ c.error && (
												<span className="aiops-r__sub">
													{ reasonText( c.error ) }
												</span>
											) }
										</td>
									) }
								</tr>
							);
						} )
					) }
				</tbody>
			</table>
		</div>
	);
}

function Skipped( { items, total } ) {
	if ( ! items || ! items.length ) {
		return null;
	}
	return (
		<details className="aiops-cs__skipped">
			<summary>
				{ sprintf(
					/* translators: %d: number of skipped products */ __(
						'Skipped: %d',
						'addsynapse-store-assistant'
					),
					total || items.length
				) }
			</summary>
			<ul>
				{ items.map( ( s, i ) => (
					<li key={ `${ s.id }-${ i }` }>
						<strong>{ s.name || `#${ s.id }` }</strong>
						{ s.sku ? ` (${ s.sku })` : '' } —{ ' ' }
						{ reasonText( s.reason ) }
					</li>
				) ) }
			</ul>
			{ total > items.length && (
				<p className="aiops-r__foot">
					{ sprintf(
						/* translators: %d: number of more rows */ __(
							'and %d more',
							'addsynapse-store-assistant'
						),
						total - items.length
					) }
				</p>
			) }
		</details>
	);
}

function ResultSummary( { changeset } ) {
	const c = changeset.counts || {};
	const s = changeset.status;
	if ( [ 'applied', 'partially_applied', 'failed' ].includes( s ) ) {
		return (
			<div className={ `aiops-cs__result is-${ s }` } role="status">
				<Icon icon={ s === 'applied' ? check : caution } size={ 18 } />
				<span>
					{ sprintf(
						/* translators: %d: number of changes */ __(
							'%d changes applied.',
							'addsynapse-store-assistant'
						),
						c.applied || 0
					) }
					{ c.conflict > 0 &&
						' ' +
							sprintf(
								/* translators: %d: number */ __(
									'%d skipped because the product changed after the preview.',
									'addsynapse-store-assistant'
								),
								c.conflict
							) }
					{ c.failed > 0 &&
						' ' +
							sprintf(
								/* translators: %d: number */ __(
									'%d failed.',
									'addsynapse-store-assistant'
								),
								c.failed
							) }
					{ c.excluded > 0 &&
						' ' +
							sprintf(
								/* translators: %d: number */ __(
									'%d left out (not approved) — unchanged.',
									'addsynapse-store-assistant'
								),
								c.excluded
							) }
				</span>
			</div>
		);
	}
	if ( s === 'reverted' ) {
		return (
			<div
				className={ `aiops-cs__result is-${
					c.revert_conflict > 0 ? 'partially_applied' : 'reverted'
				}` }
				role="status"
			>
				<Icon icon={ undo } size={ 18 } />
				<span>
					{ sprintf(
						/* translators: %d: number of changes */ __(
							'%d changes undone — the previous values are back.',
							'addsynapse-store-assistant'
						),
						c.reverted || 0
					) }
					{ c.revert_conflict > 0 &&
						' ' +
							sprintf(
								/* translators: %d: number */ __(
									'%d were not undone because they changed after the update.',
									'addsynapse-store-assistant'
								),
								c.revert_conflict
							) }
				</span>
			</div>
		);
	}
	if ( s === 'rejected' ) {
		return (
			<div className="aiops-cs__result is-rejected">
				{ __(
					'Rejected — nothing was changed.',
					'addsynapse-store-assistant'
				) }
			</div>
		);
	}
	if ( s === 'expired' ) {
		return (
			<div className="aiops-cs__result is-expired">
				{ __(
					'This preview expired without being approved — nothing was changed. Ask again to create a new one.',
					'addsynapse-store-assistant'
				) }
			</div>
		);
	}
	return null;
}

/**
 * The card.
 *
 * @param {Object}    props             Props.
 * @param {number}    props.id          Change set id.
 * @param {Object}    props.plan        Tool result (optional): skipped/warnings/totals at plan time.
 * @param {?Function} props.onReplanned Called after «Επανάληψη» created a new draft (optional).
 */
export default function ChangesetCard( {
	id,
	plan = null,
	onReplanned = null,
} ) {
	const [ changeset, setChangeset ] = useState( null );
	const [ retryAction, setRetryAction ] = useState( '' );
	const [ replanned, setReplanned ] = useState( 0 );
	const [ items, setItems ] = useState( null );
	const [ page, setPage ] = useState( 1 );
	const [ confirm, setConfirm ] = useState( '' );
	const [ acting, setActing ] = useState( false );
	const [ error, setError ] = useState( '' );
	// Partial approval: products the manager unticked (default: everything ticked).
	const [ deselected, setDeselected ] = useState( () => new Set() );
	const deselectInit = useRef( false );
	const mounted = useRef( true );
	const status = changeset ? changeset.status : '';

	// «Επανάληψη» of a partial approval: the products left out last time start unticked.
	useEffect( () => {
		if ( changeset && ! deselectInit.current ) {
			deselectInit.current = true;
			const ids =
				( changeset.params && changeset.params.deselected ) || [];
			if ( Array.isArray( ids ) && ids.length ) {
				setDeselected( new Set( ids.map( String ) ) );
			}
		}
	}, [ changeset ] );

	useEffect( () => {
		mounted.current = true;
		return () => {
			mounted.current = false;
		};
	}, [] );

	// keepError === true: reload after a refused action (usage limit, approval mode…) — its message stays visible.
	const loadChangeset = useCallback(
		async ( keepError ) => {
			try {
				const data = await getChangeset( id );
				if ( mounted.current ) {
					setChangeset( data );
					if ( keepError !== true ) {
						setError( '' ); // A newer successful load replaces a stale error (e.g. an expired read).
					}
				}
			} catch ( e ) {
				if ( mounted.current ) {
					setError( errorMessage( e ) );
				}
			}
		},
		[ id ]
	);

	const loadItems = useCallback(
		async ( p ) => {
			try {
				const data = await getChangesetItems( id, p, PER_PAGE );
				if ( mounted.current ) {
					setItems( data );
					setError( '' );
				}
			} catch ( e ) {
				if ( mounted.current ) {
					setError( errorMessage( e ) );
				}
			}
		},
		[ id ]
	);

	useEffect( () => {
		loadChangeset();
	}, [ loadChangeset ] );

	// Items reload on page change and whenever the status changes (item results).
	useEffect( () => {
		if ( status ) {
			loadItems( page );
		}
	}, [ status, page, loadItems ] );

	// Live progress while applying / undoing (each poll also nudges the background job).
	useEffect( () => {
		if ( ! RUNNING.includes( status ) ) {
			return undefined;
		}
		const timer = setInterval( loadChangeset, POLL_MS );
		return () => clearInterval( timer );
	}, [ status, loadChangeset ] );

	// Partial approval state (only a non-menu draft that can still be approved).
	// Typed unit keys of every unit (all pages); older servers: plain ids (see unitOf).
	const allIds =
		( items &&
			( Array.isArray( items.units ) && items.units.length
				? items.units
				: ( items.object_ids || [] ).map( String ) ) ) ||
		[];
	const selectable =
		!! changeset &&
		status === 'draft' &&
		changeset.can.approve &&
		allIds.length > 0;
	const selectedIds = allIds.filter(
		( key ) => ! isLeftOut( deselected, key )
	);
	const partial = selectable && selectedIds.length < allIds.length;

	// Approve / Undo are NEVER re-sent automatically: after a lost connection the card asks the
	// server what really happened and offers a manual «Try again» only if nothing was done.
	const act = async ( action ) => {
		setActing( true );
		setError( '' );
		setRetryAction( '' );
		try {
			const data = await changesetAction(
				id,
				action,
				action === 'approve' && partial
					? { object_ids: selectedIds }
					: {}
			);
			if ( ! mounted.current ) {
				return;
			}
			if ( action === 'replan' ) {
				setReplanned( data.changeset ? data.changeset.id : 0 );
				if ( onReplanned ) {
					onReplanned( data );
				} else {
					announceReplan( data.conversation_id );
				}
			} else {
				setChangeset( data );
			}
			setConfirm( '' );
		} catch ( e ) {
			if ( ! mounted.current ) {
				return;
			}
			setConfirm( '' );
			if ( lostConnection( e ) ) {
				let fresh = null;
				try {
					fresh = await getChangeset( id );
				} catch ( e2 ) {
					// Still offline: say so below.
				}
				if ( ! mounted.current ) {
					return;
				}
				if ( fresh ) {
					setChangeset( fresh );
				}
				if (
					fresh &&
					DONE_AFTER[ action ] &&
					DONE_AFTER[ action ]( fresh.status )
				) {
					return; // It went through; the card now shows the real state.
				}
				if ( action === 'replan' && changeset ) {
					announceReplan( changeset.conversation_id ); // Shows a new card if it was made.
				}
				setError(
					__(
						'The connection was interrupted, so this was not confirmed. Nothing is sent again automatically — press “Try again” to send it once more.',
						'addsynapse-store-assistant'
					)
				);
				setRetryAction( action );
			} else {
				setError( errorMessage( e ) );
				loadChangeset( true );
			}
		} finally {
			if ( mounted.current ) {
				setActing( false );
			}
		}
	};

	if ( ! changeset ) {
		return (
			<div className="aiops-cs is-loading">
				{ error ? (
					<div className="aiops-result is-error">
						{ error }{ ' ' }
						<Button variant="link" onClick={ loadChangeset }>
							{ __( 'Try again', 'addsynapse-store-assistant' ) }
						</Button>
					</div>
				) : (
					<Spinner />
				) }
			</div>
		);
	}

	const currency =
		( items && items.currency ) || ( plan && plan.currency ) || '';
	const counts = changeset.counts || {};
	const products = counts.products || 0;
	const chips = selectorChips( changeset.params );
	const title =
		{
			stock_update: __( 'Stock change', 'addsynapse-store-assistant' ),
			price_update: __( 'Price change', 'addsynapse-store-assistant' ),
			taxonomy_terms: __(
				'Category / tag change',
				'addsynapse-store-assistant'
			),
			brand_assign: __( 'Brand change', 'addsynapse-store-assistant' ),
			seo_update: __( 'SEO improvement', 'addsynapse-store-assistant' ),
			product_update: __(
				'Product details change',
				'addsynapse-store-assistant'
			),
		}[ changeset.operation ] || changeset.operation;
	const running = RUNNING.includes( status );
	const select = selectable
		? {
				isOn: ( key ) => ! isLeftOut( deselected, key ),
				all: selectedIds.length === allIds.length,
				some:
					selectedIds.length > 0 &&
					selectedIds.length < allIds.length,
				toggle: ( key, on ) =>
					setDeselected( ( current ) => {
						const next = new Set( current );
						if ( on ) {
							next.delete( key );
							next.delete( key.split( ':' ).pop() );
						} else {
							next.add( key );
						}
						return next;
					} ),
				toggleAll: ( on ) =>
					setDeselected( on ? new Set() : new Set( allIds ) ),
		  }
		: null;
	const pct = changeset.progress.total
		? Math.round(
				( changeset.progress.done / changeset.progress.total ) * 100
		  )
		: 0;

	return (
		<div className={ `aiops-cs is-${ status }` }>
			<div className="aiops-cs__head">
				<span className="aiops-cs__title">
					{ title }{ ' ' }
					<span className="aiops-cs__id">#{ changeset.id }</span>
				</span>
				<span
					className={ `aiops-badge aiops-cs__status is-cs-${ status }` }
				>
					{ statusLabel( status ) }
				</span>
			</div>
			<p className="aiops-cs__desc">
				{ describe( changeset.operation, changeset.params, currency ) }
			</p>
			{ changeset.replanned_from > 0 && (
				<p className="aiops-cs__replan-of">
					{ sprintf(
						/* translators: %d: change set number */ __(
							'Repeat of #%d',
							'addsynapse-store-assistant'
						),
						changeset.replanned_from
					) }
				</p>
			) }
			{ chips.length > 0 && (
				<div className="aiops-cs__chips">
					{ chips.map( ( [ label, value ] ) => (
						<span className="aiops-cs__chip" key={ label }>
							<span>{ label }:</span> { value }
						</span>
					) ) }
				</div>
			) }
			{
				<div className="aiops-kpis aiops-cs__kpis">
					<div className="aiops-kpi">
						<span className="aiops-kpi__value">
							{ number( products ) }
						</span>
						<span className="aiops-kpi__label">
							{ __( 'Products', 'addsynapse-store-assistant' ) }
						</span>
					</div>
					<div className="aiops-kpi">
						<span className="aiops-kpi__value">
							{ number( counts.items || 0 ) }
						</span>
						<span className="aiops-kpi__label">
							{ __( 'Changes', 'addsynapse-store-assistant' ) }
						</span>
					</div>
					{ partial && (
						<div className="aiops-kpi is-selected">
							<span className="aiops-kpi__value">
								{ number( selectedIds.length ) }
							</span>
							<span className="aiops-kpi__label">
								{ __(
									'Selected',
									'addsynapse-store-assistant'
								) }
							</span>
						</div>
					) }
					{ plan && plan.counts && plan.counts.skipped > 0 && (
						<div className="aiops-kpi">
							<span className="aiops-kpi__value">
								{ number( plan.counts.skipped ) }
							</span>
							<span className="aiops-kpi__label">
								{ __(
									'Skipped',
									'addsynapse-store-assistant'
								) }
							</span>
						</div>
					) }
					{ plan &&
						plan.totals &&
						plan.totals.change_pct !== undefined &&
						plan.totals.change_pct !== null && (
							<div className="aiops-kpi">
								<span className="aiops-kpi__value">
									{ signed( plan.totals.change_pct ) }%
								</span>
								<span className="aiops-kpi__label">
									{ __(
										'Total price change',
										'addsynapse-store-assistant'
									) }
								</span>
							</div>
						) }
				</div>
			}

			{ plan &&
				plan.warnings &&
				plan.warnings.map( ( w ) => (
					<div className="aiops-r__warning" key={ w }>
						{ warningText( w ) }
					</div>
				) ) }

			{ items ? (
				<>
					<ItemsTable
						rows={ items.rows }
						currency={ currency }
						showStatus={
							status !== 'draft' &&
							status !== 'rejected' &&
							status !== 'expired'
						}
						select={ select }
					/>
					{ items.pages > 1 && (
						<div className="aiops-cs__pager">
							<Button
								variant="tertiary"
								size="small"
								disabled={ page <= 1 }
								onClick={ () => setPage( page - 1 ) }
							>
								{ __(
									'Previous',
									'addsynapse-store-assistant'
								) }
							</Button>
							<span>
								{ sprintf(
									/* translators: 1: page, 2: pages */ __(
										'Page %1$d of %2$d',
										'addsynapse-store-assistant'
									),
									page,
									items.pages
								) }
							</span>
							<Button
								variant="tertiary"
								size="small"
								disabled={ page >= items.pages }
								onClick={ () => setPage( page + 1 ) }
							>
								{ __( 'Next', 'addsynapse-store-assistant' ) }
							</Button>
						</div>
					) }
				</>
			) : (
				! items && <Spinner />
			) }

			{ plan && (
				<Skipped
					items={ plan.skipped }
					total={ plan.counts ? plan.counts.skipped : 0 }
				/>
			) }

			{ running && (
				<div
					className="aiops-cs__progress"
					role="status"
					aria-live="polite"
				>
					<div className="aiops-cs__bar">
						<span style={ { width: `${ pct }%` } } />
					</div>
					<span>
						{ sprintf(
							/* translators: 1: done, 2: total */ __(
								'%1$d of %2$d changes',
								'addsynapse-store-assistant'
							),
							changeset.progress.done,
							changeset.progress.total
						) }
					</span>
				</div>
			) }

			<ResultSummary changeset={ changeset } />

			{ error && (
				<div className="aiops-result is-error" role="alert">
					{ error }
					{ retryAction && (
						<div className="aiops-cs__actions">
							<Button
								variant="secondary"
								size="small"
								isBusy={ acting }
								disabled={ acting }
								onClick={ () => act( retryAction ) }
							>
								{ __(
									'Try again',
									'addsynapse-store-assistant'
								) }
							</Button>
						</div>
					) }
				</div>
			) }

			{ confirm === 'approve' && (
				<div
					className="aiops-cs__confirm"
					role="alertdialog"
					aria-label={ __(
						'Confirm approval',
						'addsynapse-store-assistant'
					) }
				>
					<p>
						<strong>
							{ sprintf(
								/* translators: %d: number of products */ __(
									'%d products will change.',
									'addsynapse-store-assistant'
								),
								partial ? selectedIds.length : products
							) }
							{ partial &&
								' ' +
									( allIds.length - selectedIds.length === 1
										? __(
												'1 product is left out and stays as it is.',
												'addsynapse-store-assistant'
										  )
										: sprintf(
												/* translators: %d: number of products */ __(
													'%d products are left out and stay as they are.',
													'addsynapse-store-assistant'
												),
												allIds.length -
													selectedIds.length
										  ) ) }
						</strong>{ ' ' }
						{ __(
							'Values that changed since this preview will be skipped. You can undo afterwards.',
							'addsynapse-store-assistant'
						) }
					</p>
					<div className="aiops-cs__actions">
						<Button
							variant="primary"
							isBusy={ acting }
							disabled={ acting }
							onClick={ () => act( 'approve' ) }
						>
							{ __(
								'Yes, apply the changes',
								'addsynapse-store-assistant'
							) }
						</Button>
						<Button
							variant="tertiary"
							disabled={ acting }
							onClick={ () => setConfirm( '' ) }
						>
							{ __( 'Cancel', 'addsynapse-store-assistant' ) }
						</Button>
					</div>
				</div>
			) }

			{ confirm === 'revert' && (
				<div
					className="aiops-cs__confirm"
					role="alertdialog"
					aria-label={ __(
						'Confirm undo',
						'addsynapse-store-assistant'
					) }
				>
					<p>
						<strong>
							{ sprintf(
								/* translators: %d: number of changes */ __(
									'The previous values of %d changes will be restored.',
									'addsynapse-store-assistant'
								),
								counts.applied || 0
							) }
						</strong>{ ' ' }
						{ __(
							'Anything changed after the update is left as it is.',
							'addsynapse-store-assistant'
						) }
					</p>
					<div className="aiops-cs__actions">
						<Button
							variant="primary"
							isBusy={ acting }
							disabled={ acting }
							onClick={ () => act( 'revert' ) }
						>
							{ __( 'Yes, undo', 'addsynapse-store-assistant' ) }
						</Button>
						<Button
							variant="tertiary"
							disabled={ acting }
							onClick={ () => setConfirm( '' ) }
						>
							{ __( 'Cancel', 'addsynapse-store-assistant' ) }
						</Button>
					</div>
				</div>
			) }

			{ ! confirm && changeset.can.approve && (
				<div className="aiops-cs__actions">
					<Button
						variant="primary"
						disabled={
							acting || ( selectable && ! selectedIds.length )
						}
						onClick={ () => setConfirm( 'approve' ) }
					>
						{ partial
							? sprintf(
									/* translators: %d: number of selected products */ __(
										'Approve selected (%d)',
										'addsynapse-store-assistant'
									),
									selectedIds.length
							  )
							: __( 'Approve', 'addsynapse-store-assistant' ) }
					</Button>
					<Button
						variant="secondary"
						isDestructive
						isBusy={ acting }
						disabled={ acting }
						onClick={ () => act( 'reject' ) }
					>
						{ __( 'Reject', 'addsynapse-store-assistant' ) }
					</Button>
					<span className="aiops-cs__expires">
						{ sprintf(
							/* translators: %s: date and time */ __(
								'Expires %s',
								'addsynapse-store-assistant'
							),
							date( changeset.expires_at, true )
						) }
					</span>
				</div>
			) }

			{ ! confirm && changeset.can.revert && (
				<div className="aiops-cs__actions">
					<Button
						variant="secondary"
						icon={ <Icon icon={ undo } /> }
						disabled={ acting }
						onClick={ () => setConfirm( 'revert' ) }
					>
						{ __( 'Undo', 'addsynapse-store-assistant' ) }
					</Button>
				</div>
			) }

			{ ! confirm && changeset.can.replan && ! retryAction && (
				<div className="aiops-cs__actions">
					<Button
						variant="secondary"
						icon={ <Icon icon={ repeat } /> }
						isBusy={ acting }
						disabled={ acting }
						onClick={ () => act( 'replan' ) }
					>
						{ __( 'Repeat', 'addsynapse-store-assistant' ) }
					</Button>
					<span className="aiops-cs__expires">
						{ replanned > 0
							? sprintf(
									/* translators: %d: change set number */ __(
										'New preview #%d is ready below — nothing changes until you approve it.',
										'addsynapse-store-assistant'
									),
									replanned
							  )
							: __(
									'Prepares the same change again with the current values, as a new preview to approve.',
									'addsynapse-store-assistant'
							  ) }
					</span>
				</div>
			) }

			{ status === 'draft' && (
				<p className="aiops-r__foot">
					{ __(
						'Nothing has changed yet: the changes are applied only when you approve them.',
						'addsynapse-store-assistant'
					) }
				</p>
			) }
		</div>
	);
}

/**
 * Result view for the plan tools (chat).
 *
 * @param {Object} props      Props.
 * @param {Object} props.data Tool result.
 */
export function PlanResult( { data } ) {
	if ( ! data.changeset_id ) {
		return (
			<div className="aiops-cs is-empty">
				<div className="aiops-r__empty">
					{ __(
						'Nothing would change, so no preview was created.',
						'addsynapse-store-assistant'
					) }
				</div>
				{ data.warnings &&
					data.warnings.map( ( w ) => (
						<div className="aiops-r__warning" key={ w }>
							{ warningText( w ) }
						</div>
					) ) }
				<Skipped
					items={ data.skipped }
					total={ data.counts ? data.counts.skipped : 0 }
				/>
			</div>
		);
	}
	return <ChangesetCard id={ data.changeset_id } plan={ data } />;
}
