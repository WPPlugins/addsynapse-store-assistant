/**
 * Date helpers (user locale from WP).
 */
import { __ } from '@wordpress/i18n';

const locale =
	( window.addsynapseAssistant && window.addsynapseAssistant.locale ) ||
	undefined;

const startOfDay = ( d ) =>
	new Date( d.getFullYear(), d.getMonth(), d.getDate() );

/**
 * Groups conversations by updated_at: Today / Yesterday / Previous 7 days / Older.
 *
 * @param {Array} conversations Conversations (sorted desc).
 * @return {Array<{key:string,label:string,items:Array}>} Groups.
 */
export function groupConversations( conversations ) {
	const today = startOfDay( new Date() ).getTime();
	const day = 86400000;
	const groups = [
		{
			key: 'today',
			label: __( 'Today', 'addsynapse-store-assistant' ),
			items: [],
		},
		{
			key: 'yesterday',
			label: __( 'Yesterday', 'addsynapse-store-assistant' ),
			items: [],
		},
		{
			key: 'week',
			label: __( 'Previous 7 days', 'addsynapse-store-assistant' ),
			items: [],
		},
		{
			key: 'older',
			label: __( 'Older', 'addsynapse-store-assistant' ),
			items: [],
		},
	];
	conversations.forEach( ( c ) => {
		const t = startOfDay( new Date( c.updated_at ) ).getTime();
		if ( t >= today ) {
			groups[ 0 ].items.push( c );
		} else if ( t >= today - day ) {
			groups[ 1 ].items.push( c );
		} else if ( t >= today - 7 * day ) {
			groups[ 2 ].items.push( c );
		} else {
			groups[ 3 ].items.push( c );
		}
	} );
	return groups.filter( ( g ) => g.items.length );
}

/**
 * Short time (today) or date + time.
 *
 * @param {string} iso ISO date.
 * @return {string} Formatted.
 */
export function formatTime( iso ) {
	if ( ! iso ) {
		return '';
	}
	const d = new Date( iso );
	const sameDay =
		startOfDay( d ).getTime() === startOfDay( new Date() ).getTime();
	try {
		return new Intl.DateTimeFormat(
			locale,
			sameDay
				? { hour: '2-digit', minute: '2-digit' }
				: {
						day: 'numeric',
						month: 'short',
						hour: '2-digit',
						minute: '2-digit',
				  }
		).format( d );
	} catch ( e ) {
		return d.toLocaleString();
	}
}

/**
 * Full date for tooltips.
 *
 * @param {string} iso ISO date.
 * @return {string} Formatted.
 */
export function formatFull( iso ) {
	if ( ! iso ) {
		return '';
	}
	try {
		return new Intl.DateTimeFormat( locale, {
			dateStyle: 'full',
			timeStyle: 'short',
		} ).format( new Date( iso ) );
	} catch ( e ) {
		return new Date( iso ).toLocaleString();
	}
}
