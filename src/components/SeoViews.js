/**
 * SEO views: the get-seo table and the SEO/product-text values inside the changeset card
 * (before → after with character counters, coloured length indicators, collapsible long
 * texts and a word diff for descriptions).
 *
 * Everything is store DATA or AI text rendered as React text nodes (never HTML).
 */
import { useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { Icon, external } from '@wordpress/icons';
import { adminLink, number } from '../lib/format';

const LONG_TEXT = 220;
const DIFF_MAX_WORDS = 1500;

/**
 * Length state against the guidance [min, max]: ok | warn | bad | none.
 *
 * @param {number}     chars Characters.
 * @param {Array|null} guide [min, max].
 * @return {string} State.
 */
export function lengthState( chars, guide ) {
	if ( ! guide || guide.length !== 2 ) {
		return 'none';
	}
	const [ min, max ] = guide;
	if ( ! chars ) {
		return 'bad';
	}
	if ( chars >= min && chars <= max ) {
		return 'ok';
	}
	const slack = Math.round( ( max - min ) * 1.5 ) + 10;
	return chars >= min - slack && chars <= max + slack ? 'warn' : 'bad';
}

/**
 * Character counter badge (coloured when there is a guidance).
 *
 * @param {Object}     props       Props.
 * @param {number}     props.chars Characters.
 * @param {Array|null} props.guide [min, max].
 */
export function Chars( { chars, guide = null } ) {
	const state = lengthState( chars, guide );
	const title = guide
		? sprintf(
				/* translators: 1: min, 2: max */ __(
					'Recommended: %1$d–%2$d characters',
					'addsynapse-store-assistant'
				),
				guide[ 0 ],
				guide[ 1 ]
		  )
		: undefined;
	return (
		<span
			className={ `aiops-seo__chars is-len-${ state }` }
			title={ title }
		>
			{ sprintf(
				/* translators: %s: number of characters */ __(
					'%s chars',
					'addsynapse-store-assistant'
				),
				number( chars || 0 )
			) }
		</span>
	);
}

/**
 * Long text: first part + «Show all».
 *
 * @param {Object} props      Props.
 * @param {string} props.text Text.
 */
export function LongText( { text } ) {
	const [ open, setOpen ] = useState( false );
	if ( ! text || text.length <= LONG_TEXT ) {
		return <span className="aiops-seo__text">{ text }</span>;
	}
	return (
		<span className="aiops-seo__text">
			{ open ? text : `${ text.slice( 0, LONG_TEXT ).trimEnd() }…` }{ ' ' }
			<Button
				variant="link"
				className="aiops-seo__more"
				onClick={ () => setOpen( ! open ) }
			>
				{ open
					? __( 'Show less', 'addsynapse-store-assistant' )
					: __( 'Show all', 'addsynapse-store-assistant' ) }
			</Button>
		</span>
	);
}

/**
 * Word-level diff (LCS) → [{type: same|del|ins, text}].
 *
 * @param {string} before Before.
 * @param {string} after  After.
 * @return {Array|null} Parts, null when too long to compare.
 */
export function wordDiff( before, after ) {
	const a = ( before || '' ).split( /(\s+)/ ).filter( ( w ) => w !== '' );
	const b = ( after || '' ).split( /(\s+)/ ).filter( ( w ) => w !== '' );
	if ( a.length > DIFF_MAX_WORDS * 2 || b.length > DIFF_MAX_WORDS * 2 ) {
		return null;
	}
	const rows = a.length + 1;
	const cols = b.length + 1;
	const table = new Array( rows );
	for ( let i = 0; i < rows; i++ ) {
		table[ i ] = new Uint16Array( cols );
	}
	for ( let i = a.length - 1; i >= 0; i-- ) {
		for ( let j = b.length - 1; j >= 0; j-- ) {
			table[ i ][ j ] =
				a[ i ] === b[ j ]
					? table[ i + 1 ][ j + 1 ] + 1
					: Math.max( table[ i + 1 ][ j ], table[ i ][ j + 1 ] );
		}
	}
	const out = [];
	const push = ( type, text ) => {
		const last = out[ out.length - 1 ];
		if ( last && last.type === type ) {
			last.text += text;
		} else {
			out.push( { type, text } );
		}
	};
	let i = 0;
	let j = 0;
	while ( i < a.length && j < b.length ) {
		if ( a[ i ] === b[ j ] ) {
			push( 'same', a[ i ] );
			i++;
			j++;
		} else if ( table[ i + 1 ][ j ] >= table[ i ][ j + 1 ] ) {
			push( 'del', a[ i++ ] );
		} else {
			push( 'ins', b[ j++ ] );
		}
	}
	while ( i < a.length ) {
		push( 'del', a[ i++ ] );
	}
	while ( j < b.length ) {
		push( 'ins', b[ j++ ] );
	}
	return out;
}

/**
 * Collapsible word diff of two texts.
 *
 * @param {Object} props        Props.
 * @param {string} props.before Before.
 * @param {string} props.after  After.
 */
export function DiffDetails( { before, after } ) {
	const [ open, setOpen ] = useState( false );
	const parts = useMemo(
		() => ( open ? wordDiff( before, after ) : null ),
		[ open, before, after ]
	);
	return (
		<details
			className="aiops-seo__diff"
			onToggle={ ( e ) => setOpen( e.currentTarget.open ) }
		>
			<summary>
				{ __( 'Show changes', 'addsynapse-store-assistant' ) }
			</summary>
			{ open && ! parts && (
				<p className="aiops-r__meta">
					{ __(
						'Too long to compare word by word.',
						'addsynapse-store-assistant'
					) }
				</p>
			) }
			{ open && parts && (
				<p className="aiops-seo__diff-body">
					{ parts.map( ( p, k ) => {
						if ( p.type === 'del' ) {
							return <del key={ k }>{ p.text }</del>;
						}
						if ( p.type === 'ins' ) {
							return <ins key={ k }>{ p.text }</ins>;
						}
						return <span key={ k }>{ p.text }</span>;
					} ) }
				</p>
			) }
		</details>
	);
}

/**
 * Label of an SEO change field.
 *
 * @param {string} part seo_part.
 * @return {string} Label.
 */
export function seoFieldLabel( part ) {
	return (
		{
			title: __( 'SEO title', 'addsynapse-store-assistant' ),
			description: __( 'Meta description', 'addsynapse-store-assistant' ),
			focus_keyword: __( 'Focus keyword', 'addsynapse-store-assistant' ),
			product_short_description: __(
				'Short description',
				'addsynapse-store-assistant'
			),
			product_description: __(
				'Description',
				'addsynapse-store-assistant'
			),
		}[ part ] || part
	);
}

/**
 * One side of an SEO / product text change in the card.
 *
 * @param {Object} props      Props.
 * @param {Object} props.c    Change (from the REST items endpoint).
 * @param {string} props.side before | after.
 */
export function SeoChangeValue( { c, side } ) {
	const text = c[ `${ side }_text` ] || '';
	const effective = c[ `${ side }_effective` ] || '';
	const chars = c[ `${ side }_chars` ] || 0;
	const isDefault = !! c[ `${ side }_default` ];
	const long =
		c.seo_part === 'product_description' ||
		c.seo_part === 'product_short_description';
	return (
		<span className="aiops-seo__value">
			{ c[ `${ side }_absent` ] && ! isDefault && (
				<em className="aiops-cs__absent">
					{ __( 'absent', 'addsynapse-store-assistant' ) }
				</em>
			) }
			{ isDefault && (
				<>
					<em className="aiops-cs__absent">
						{ c[ `${ side }_absent` ]
							? __(
									'not set — template:',
									'addsynapse-store-assistant'
							  )
							: __(
									'empty — template:',
									'addsynapse-store-assistant'
							  ) }
					</em>{ ' ' }
					<span className="aiops-seo__text is-default">
						{ effective || '—' }
					</span>
				</>
			) }
			{ ! isDefault && ! c[ `${ side }_absent` ] && text === '' && (
				<em className="aiops-cs__absent">
					{ __( '(empty)', 'addsynapse-store-assistant' ) }
				</em>
			) }
			{ ! isDefault &&
				text !== '' &&
				( long ? (
					<LongText text={ text } />
				) : (
					<span className="aiops-seo__text">{ text }</span>
				) ) }
			{ ! isDefault && effective && effective !== text && (
				<span className="aiops-r__sub">
					{ __( 'Shown as:', 'addsynapse-store-assistant' ) }{ ' ' }
					{ effective }
				</span>
			) }
			{ c.seo_part !== 'focus_keyword' && (
				<Chars chars={ chars } guide={ c.guide } />
			) }
			{ side === 'after' && long && c.before_text !== text && (
				<DiffDetails before={ c.before_text || '' } after={ text } />
			) }
		</span>
	);
}

const ISSUE_LABELS = {
	seo_title_not_set: () =>
		__( 'SEO title not set (template)', 'addsynapse-store-assistant' ),
	seo_title_short: () =>
		__( 'SEO title too short', 'addsynapse-store-assistant' ),
	seo_title_long: () =>
		__( 'SEO title too long', 'addsynapse-store-assistant' ),
	seo_title_missing: () => __( 'No SEO title', 'addsynapse-store-assistant' ),
	seo_description_not_set: () =>
		__(
			'Meta description not set (template)',
			'addsynapse-store-assistant'
		),
	seo_description_short: () =>
		__( 'Meta description too short', 'addsynapse-store-assistant' ),
	seo_description_long: () =>
		__( 'Meta description too long', 'addsynapse-store-assistant' ),
	seo_description_missing: () =>
		__( 'No meta description', 'addsynapse-store-assistant' ),
	focus_keyword_missing: () =>
		__( 'No focus keyword', 'addsynapse-store-assistant' ),
	focus_keyword_not_in_title: () =>
		__( 'Keyword not in title', 'addsynapse-store-assistant' ),
	focus_keyword_not_in_description: () =>
		__( 'Keyword not in description', 'addsynapse-store-assistant' ),
	duplicate_title: () =>
		__( 'Duplicate title', 'addsynapse-store-assistant' ),
	short_description_missing: () =>
		__( 'No short description', 'addsynapse-store-assistant' ),
	description_missing: () =>
		__( 'No description', 'addsynapse-store-assistant' ),
};

/**
 * Result of addsynapse-assistant/get-seo.
 *
 * @param {Object} props      Props.
 * @param {Object} props.data Result.
 */
export function SeoView( { data } ) {
	const plugin = data.seo_plugin || {};
	const guide = data.guidance || {};
	const products = data.products || [];
	// Plain WordPress site (no WooCommerce): the rows are pages/posts.
	const isSite = data.site_type === 'site';
	const unsupported = isSite
		? __(
				'No supported SEO plugin (Rank Math / Yoast): the SEO texts of pages and posts cannot be stored.',
				'addsynapse-store-assistant'
		  )
		: __(
				'No supported SEO plugin (Rank Math / Yoast): only the product descriptions can be improved.',
				'addsynapse-store-assistant'
		  );
	const notShown = isSite
		? sprintf(
				/* translators: %d: number of pages/posts */ __(
					'Not shown: %d (missing or not editable).',
					'addsynapse-store-assistant'
				),
				( data.skipped || [] ).length
		  )
		: sprintf(
				/* translators: %d: number of products */ __(
					'Not shown: %d (variations or missing products).',
					'addsynapse-store-assistant'
				),
				( data.skipped || [] ).length
		  );
	return (
		<div className="aiops-r aiops-seo">
			<div className="aiops-r__meta">
				{ plugin.supported
					? sprintf(
							/* translators: %s: SEO plugin name */ __(
								'SEO plugin: %s',
								'addsynapse-store-assistant'
							),
							plugin.label
					  )
					: unsupported }
			</div>
			<div className="aiops-table-wrap">
				<table className="aiops-table">
					<thead>
						<tr>
							<th>
								{ isSite
									? __( 'Page', 'addsynapse-store-assistant' )
									: __(
											'Product',
											'addsynapse-store-assistant'
									  ) }
							</th>
							{ plugin.supported && (
								<th>
									{ __(
										'SEO title',
										'addsynapse-store-assistant'
									) }
								</th>
							) }
							{ plugin.supported && (
								<th>
									{ __(
										'Meta description',
										'addsynapse-store-assistant'
									) }
								</th>
							) }
							{ plugin.supported && (
								<th>
									{ __(
										'Focus keyword',
										'addsynapse-store-assistant'
									) }
								</th>
							) }
							<th>
								{ __( 'Issues', 'addsynapse-store-assistant' ) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ products.map( ( p ) => {
							const link = adminLink( p.edit_link );
							const seo = p.seo || {};
							return (
								<tr key={ p.id }>
									<td
										data-label={
											isSite
												? __(
														'Page',
														'addsynapse-store-assistant'
												  )
												: __(
														'Product',
														'addsynapse-store-assistant'
												  )
										}
									>
										{ link ? (
											<a
												className="aiops-r__link"
												href={ link }
												target="_blank"
												rel="noopener noreferrer"
											>
												<span className="aiops-r__name">
													{ p.name }
												</span>
												<Icon
													icon={ external }
													size={ 14 }
												/>
											</a>
										) : (
											<span className="aiops-r__name">
												{ p.name }
											</span>
										) }
										{ p.sku && (
											<span className="aiops-r__sub">
												{ p.sku }
											</span>
										) }
									</td>
									{ plugin.supported &&
										[ 'title', 'description' ].map(
											( part ) => {
												const s = seo[ part ] || {};
												return (
													<td
														key={ part }
														data-label={
															part === 'title'
																? __(
																		'SEO title',
																		'addsynapse-store-assistant'
																  )
																: __(
																		'Meta description',
																		'addsynapse-store-assistant'
																  )
														}
													>
														<span
															className={ `aiops-seo__text${
																s.source ===
																'custom'
																	? ''
																	: ' is-default'
															}` }
														>
															{ s.effective ||
																'—' }
														</span>
														{ s.source !==
															'custom' && (
															<span className="aiops-r__sub">
																{ __(
																	'from the template',
																	'addsynapse-store-assistant'
																) }
															</span>
														) }
														<Chars
															chars={ s.chars }
															guide={
																part === 'title'
																	? guide.title_chars
																	: guide.description_chars
															}
														/>
													</td>
												);
											}
										) }
									{ plugin.supported && (
										<td
											data-label={ __(
												'Focus keyword',
												'addsynapse-store-assistant'
											) }
										>
											{ ( seo.focus_keyword &&
												seo.focus_keyword.primary ) ||
												'—' }
										</td>
									) }
									<td
										data-label={ __(
											'Issues',
											'addsynapse-store-assistant'
										) }
									>
										{ ( p.issues || [] ).length === 0 && (
											<span className="aiops-badge is-item-applied">
												{ __(
													'OK',
													'addsynapse-store-assistant'
												) }
											</span>
										) }
										{ ( p.issues || [] ).map( ( code ) => (
											<span
												key={ code }
												className="aiops-badge is-item-conflict aiops-seo__issue"
											>
												{ ISSUE_LABELS[ code ]
													? ISSUE_LABELS[ code ]()
													: code }
											</span>
										) ) }
									</td>
								</tr>
							);
						} ) }
					</tbody>
				</table>
			</div>
			{ ( data.skipped || [] ).length > 0 && (
				<div className="aiops-r__meta">{ notShown }</div>
			) }
		</div>
	);
}
