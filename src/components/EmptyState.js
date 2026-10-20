/**
 * Empty thread: suggested prompts, slash commands (they run on the site without the AI) and a short, dismissible
 * list of what the separate Store Assistant Pro add-on adds, each line marked «Pro» with a lock, and a «See Pro» link
 * (information only: nothing in this plugin is locked; hidden once another plugin provides the Pro features).
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { ProBadge, SeeProLink, proShown } from './ProOffer';

const COMMANDS = [
	'/help',
	'/report low_stock',
	'/report sales 30d',
	'/orders 7d',
	'/menus',
];

// WordPress site without WooCommerce: no product/order commands.
const SITE_COMMANDS = [ '/help', '/store', '/page front', '/menus' ];

const PRO_NOTE_KEY = 'addsynapse-assistant-pro-note-hidden';

/**
 * Suggested prompts — shop (WooCommerce) or plain site. Every change is one item at a time.
 *
 * @param {boolean} site Plain WordPress site.
 * @return {Array<string>} Suggestions.
 */
function suggestionsFor( site ) {
	if ( site ) {
		return [
			__(
				'Give me an overview of my site',
				'addsynapse-store-assistant'
			),
			__(
				'Which pages does my site have?',
				'addsynapse-store-assistant'
			),
			__( 'Show me the main menu', 'addsynapse-store-assistant' ),
			__(
				'Check the SEO of my About page',
				'addsynapse-store-assistant'
			),
		];
	}
	return [
		__( 'Give me an overview of my store', 'addsynapse-store-assistant' ),
		__( 'Which products are low on stock?', 'addsynapse-store-assistant' ),
		__( 'Summarise this month’s sales', 'addsynapse-store-assistant' ),
		__(
			'Put my best seller on sale at 10% off until Sunday',
			'addsynapse-store-assistant'
		),
		__(
			'Set the photo I attach as the main image of a product',
			'addsynapse-store-assistant'
		),
		__(
			'Improve the SEO title of my best seller',
			'addsynapse-store-assistant'
		),
	];
}

/**
 * What the separate Pro add-on adds (information only).
 *
 * @param {boolean} site Plain WordPress site.
 * @return {Array<string>} Lines.
 */
function proLines( site ) {
	const lines = site
		? []
		: [
				__(
					'Bulk changes by category, brand or the whole catalogue',
					'addsynapse-store-assistant'
				),
				__(
					'Price and stock import from a CSV file',
					'addsynapse-store-assistant'
				),
				__(
					'Creating, renaming and merging categories, tags and brands',
					'addsynapse-store-assistant'
				),
				__(
					'Attributes, variations, custom fields and other product settings',
					'addsynapse-store-assistant'
				),
				__( 'Coupons and orders', 'addsynapse-store-assistant' ),
		  ];
	return [
		...lines,
		__(
			'Site appearance: menus, pages, sections, banners and posts',
			'addsynapse-store-assistant'
		),
		__(
			'SEO and image alt texts at scale, content writing',
			'addsynapse-store-assistant'
		),
		__(
			'Full change history with Undo of older changes',
			'addsynapse-store-assistant'
		),
		__(
			'Our AI included, no API key needed',
			'addsynapse-store-assistant'
		),
	];
}

function readHidden() {
	try {
		return window.localStorage.getItem( PRO_NOTE_KEY ) === '1';
	} catch ( e ) {
		return false;
	}
}

/**
 * «Store Assistant Pro» — collapsed by default, dismissible (remembered in this browser).
 *
 * @param {Object}  props      Props.
 * @param {boolean} props.site Plain WordPress site.
 * @return {?Element} Note.
 */
function ProNote( { site } ) {
	const [ hidden, setHidden ] = useState( readHidden );
	if ( hidden || ! proShown() ) {
		return null;
	}
	const hide = () => {
		try {
			window.localStorage.setItem( PRO_NOTE_KEY, '1' );
		} catch ( e ) {
			// Private mode / blocked storage: hide for this page view only.
		}
		setHidden( true );
	};
	return (
		<details className="aiops-pro-info">
			<summary className="aiops-pro-info__summary">
				{ __(
					'More with Store Assistant Pro',
					'addsynapse-store-assistant'
				) }
			</summary>
			<p className="aiops-pro-info__intro">
				{ __(
					'A separate add-on. Everything above stays free.',
					'addsynapse-store-assistant'
				) }
			</p>
			<ul className="aiops-pro-info__list">
				{ proLines( site ).map( ( line ) => (
					<li key={ line }>
						<ProBadge /> { line }
					</li>
				) ) }
			</ul>
			<div className="aiops-pro-info__actions">
				<SeeProLink spot="chat-empty" />
				<Button variant="link" onClick={ hide }>
					{ __( 'Hide', 'addsynapse-store-assistant' ) }
				</Button>
			</div>
		</details>
	);
}

export default function EmptyState( { onPick, disabled, siteType = 'shop' } ) {
	const site = siteType === 'site';
	const suggestions = suggestionsFor( site );
	const commands = site ? SITE_COMMANDS : COMMANDS;
	return (
		<div className="aiops-empty">
			<div className="aiops-empty__logo" aria-hidden="true">
				AI
			</div>
			<h2 className="aiops-empty__title">
				{ site
					? __(
							'How can I help with your site?',
							'addsynapse-store-assistant'
					  )
					: __(
							'How can I help with your store?',
							'addsynapse-store-assistant'
					  ) }
			</h2>
			<p className="aiops-empty__text">
				{ site
					? __(
							'Ask in your own words, in your language. The assistant reads your pages, menus and SEO with safe tools; changes will always need your approval.',
							'addsynapse-store-assistant'
					  )
					: __(
							'Ask in your own words, in your language. The assistant reads your WooCommerce data with safe tools; changes will always need your approval.',
							'addsynapse-store-assistant'
					  ) }
			</p>
			<div className="aiops-empty__grid">
				{ suggestions.map( ( s ) => (
					<button
						type="button"
						key={ s }
						className="aiops-suggestion"
						disabled={ disabled }
						onClick={ () => onPick( s ) }
					>
						{ s }
					</button>
				) ) }
			</div>
			<div className="aiops-empty__commands">
				<span className="aiops-empty__commands-label">
					{ __(
						'Try a command (works without AI):',
						'addsynapse-store-assistant'
					) }
				</span>
				{ commands.map( ( c ) => (
					<button
						type="button"
						key={ c }
						className="aiops-command-chip"
						disabled={ disabled }
						onClick={ () => onPick( c ) }
					>
						{ c }
					</button>
				) ) }
			</div>
			<ProNote site={ site } />
		</div>
	);
}
