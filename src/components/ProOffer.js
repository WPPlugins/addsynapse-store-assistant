/**
 * Links to Store Assistant Pro, the separate add-on (information only: nothing in this plugin is locked).
 * The page comes from the server (Admin\ProOffers: the manager's language); every placement adds its own
 * utm_source spot. No link at all once another plugin provides the Pro features (settings.pro.show false).
 */
import { __ } from '@wordpress/i18n';
import { Icon, lockSmall } from '@wordpress/icons';

const settings = window.addsynapseAssistant || {};
const pro = settings.pro || {};

/**
 * Whether the Pro links are shown.
 *
 * @return {boolean} Shown.
 */
export function proShown() {
	return !! ( pro.show && pro.url );
}

/**
 * Link of one placement.
 *
 * @param {string} spot Placement (letters, digits, «-»).
 * @return {string} URL ('' when hidden or invalid).
 */
export function proUrl( spot ) {
	if ( ! proShown() ) {
		return '';
	}
	try {
		const url = new URL( pro.url );
		if ( url.protocol !== 'https:' ) {
			return '';
		}
		url.searchParams.set(
			'utm_source',
			String( spot || 'chat' )
				.toLowerCase()
				.replace( /[^a-z0-9-]/g, '' )
		);
		url.searchParams.set( 'utm_medium', 'wp-admin' );
		url.searchParams.set( 'utm_campaign', 'gopro' );
		return url.toString();
	} catch ( e ) {
		return '';
	}
}

/**
 * «See Pro» link styled as a button (opens the add-on's page in a new tab).
 *
 * @param {Object} props           Props.
 * @param {string} props.spot      Placement.
 * @param {string} props.className Extra class.
 * @return {JSX.Element|null} Link.
 */
export function SeeProLink( { spot, className = '' } ) {
	const href = proUrl( spot );
	if ( ! href ) {
		return null;
	}
	return (
		<a
			className={ `components-button is-secondary is-compact aiops-pro-link ${ className }` }
			href={ href }
			target="_blank"
			rel="noopener noreferrer"
		>
			{ __( 'See Pro', 'addsynapse-store-assistant' ) }
			<span className="screen-reader-text">
				{ __( '(opens in a new tab)', 'addsynapse-store-assistant' ) }
			</span>
		</a>
	);
}

/**
 * Small «Pro» badge with a lock (marks what the separate add-on adds; free features are never marked).
 *
 * @return {JSX.Element} Badge.
 */
export function ProBadge() {
	return (
		<span className="aiops-pro-badge">
			<Icon icon={ lockSmall } size={ 16 } />
			{ __( 'Pro', 'addsynapse-store-assistant' ) }
		</span>
	);
}
