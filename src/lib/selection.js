/**
 * Product selection of a conversation (checkboxes in the result tables).
 *
 * Lives in the conversation's UI state and survives a reload through sessionStorage (per
 * conversation, per tab). Storage may be unavailable (private mode, blocked site data): every
 * access is wrapped and the UI works without it.
 *
 * Shape: { ids: number[], items: { [id]: row }, sources: { [id]: sourceKey } } where row keeps
 * the few fields the compact modal shows and sourceKey is the tool call whose table it came
 * from («Εμφάνιση» scrolls there).
 */
import { createContext, useContext } from '@wordpress/element';

export const MAX_SELECTION = 500;

export const EMPTY_SELECTION = Object.freeze( {
	ids: [],
	items: {},
	sources: {},
} );

export const SelectionContext = createContext( null );

/**
 * The selection API for tables (null outside the chat, e.g. in the change history).
 *
 * @return {Object|null} { has(id), toggle(rows, checked, source, currency), clear(), count }.
 */
export const useSelection = () => useContext( SelectionContext );

const storageKey = ( conversationId ) =>
	`addsynapse-assistant:selection:${ conversationId }`;

const ROW_FIELDS = [
	'id',
	'name',
	'sku',
	'type',
	'price',
	'regular_price',
	'sale_price',
	'stock_status',
	'stock_quantity',
	'currency',
	'edit_link',
];

/**
 * Keeps only what the modal needs (and only plain values).
 *
 * @param {Object} row      Table row.
 * @param {string} currency Currency.
 * @return {Object} Row.
 */
export function compactRow( row, currency = '' ) {
	const out = {};
	ROW_FIELDS.forEach( ( key ) => {
		const value =
			key === 'currency' ? row.currency || currency : row[ key ];
		if (
			value === null ||
			[ 'string', 'number', 'boolean' ].includes( typeof value )
		) {
			out[ key ] = value;
		}
	} );
	out.id = Number( row.id );
	return out;
}

/**
 * Validates a stored/loaded selection.
 *
 * @param {*} raw Anything.
 * @return {Object} Selection.
 */
function clean( raw ) {
	if ( ! raw || ! Array.isArray( raw.ids ) ) {
		return EMPTY_SELECTION;
	}
	const ids = [
		...new Set(
			raw.ids
				.map( Number )
				.filter( ( id ) => Number.isInteger( id ) && id > 0 )
		),
	].slice( 0, MAX_SELECTION );
	const items = {};
	const sources = {};
	ids.forEach( ( id ) => {
		if (
			raw.items &&
			raw.items[ id ] &&
			typeof raw.items[ id ] === 'object'
		) {
			items[ id ] = compactRow( { ...raw.items[ id ], id } );
		}
		if ( raw.sources && typeof raw.sources[ id ] === 'string' ) {
			sources[ id ] = raw.sources[ id ];
		}
	} );
	return { ids, items, sources };
}

/**
 * Selection saved for a conversation (empty when none / storage unavailable).
 *
 * @param {number} conversationId Conversation.
 * @return {Object} Selection.
 */
export function loadSelection( conversationId ) {
	if ( ! conversationId ) {
		return EMPTY_SELECTION;
	}
	try {
		const raw = window.sessionStorage.getItem(
			storageKey( conversationId )
		);
		return raw ? clean( JSON.parse( raw ) ) : EMPTY_SELECTION;
	} catch ( e ) {
		return EMPTY_SELECTION;
	}
}

/**
 * Saves (or forgets, when empty) a conversation's selection.
 *
 * @param {number} conversationId Conversation.
 * @param {Object} selection      Selection.
 */
export function saveSelection( conversationId, selection ) {
	if ( ! conversationId ) {
		return;
	}
	try {
		if ( ! selection.ids.length ) {
			window.sessionStorage.removeItem( storageKey( conversationId ) );
		} else {
			window.sessionStorage.setItem(
				storageKey( conversationId ),
				JSON.stringify( selection )
			);
		}
	} catch ( e ) {
		// Storage unavailable: the selection lives in memory only.
	}
}

/**
 * Adds or removes rows.
 *
 * @param {Object}  selection Current selection.
 * @param {Array}   rows      Rows ({id, …}).
 * @param {boolean} checked   Add (true) or remove (false).
 * @param {string}  source    Source table key.
 * @param {string}  currency  Currency of the table.
 * @return {Object} New selection.
 */
export function toggleRows(
	selection,
	rows,
	checked,
	source = '',
	currency = ''
) {
	const ids = [ ...selection.ids ];
	const items = { ...selection.items };
	const sources = { ...selection.sources };
	rows.forEach( ( row ) => {
		const id = Number( row.id );
		if ( ! Number.isInteger( id ) || id < 1 ) {
			return;
		}
		const index = ids.indexOf( id );
		if ( checked && index === -1 && ids.length < MAX_SELECTION ) {
			ids.push( id );
			items[ id ] = compactRow( row, currency );
			sources[ id ] = source;
		} else if ( ! checked && index !== -1 ) {
			ids.splice( index, 1 );
			delete items[ id ];
			delete sources[ id ];
		}
	} );
	return { ids, items, sources };
}
