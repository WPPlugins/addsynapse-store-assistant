/**
 * Renders structured ability results (tables, product card, KPI summaries).
 *
 * Everything shown here is store DATA: rendered as React text nodes (never HTML),
 * and only same-site wp-admin URLs become links.
 */
import { MediaView, PageView } from './ContentViews';
import { BarList, SalesChart } from './Charts';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Spinner } from '@wordpress/components';
import { Icon, external } from '@wordpress/icons';
import {
	date,
	editLabel,
	money,
	number,
	periodLabel,
	productStatusLabel,
	objectLink,
	skuLabel,
	stockLabel,
	typeLabel,
	variationLabel,
	variationsLabel,
} from '../lib/format';
import {
	ProductRawView,
	StoreMapView,
	TermMatchesView,
} from './DiscoveryViews';
import { PlanResult } from './ChangesetCard';
import { MenusView } from './MenuViews';
import { SeoView } from './SeoViews';
import { useSelection } from '../lib/selection';
import { errorMessage, resultPage } from '../lib/api';
import { ProOfferCta } from './ProOffer';

function EditLink( { href, children, label, kind = '', id = 0 } ) {
	const safe = objectLink( href, kind, id );
	if ( ! safe ) {
		return children || null;
	}
	return (
		<a
			className="aiops-r__link"
			href={ safe }
			target="_blank"
			rel="noopener noreferrer"
			aria-label={ label }
			title={ label }
		>
			{ children }
			<Icon icon={ external } size={ 14 } />
		</a>
	);
}

function StockBadge( { status, quantity, lowThreshold = null } ) {
	// In stock but at/below the low-stock threshold → amber "Low · N", not green.
	const low =
		status === 'instock' &&
		lowThreshold !== null &&
		quantity !== null &&
		quantity !== undefined &&
		Number( quantity ) <= Number( lowThreshold );
	return (
		<span
			className={ `aiops-badge is-${ low ? 'low' : status || 'none' }` }
		>
			{ low
				? __( 'Low', 'addsynapse-store-assistant' )
				: stockLabel( status ) }
			{ quantity !== null &&
				quantity !== undefined &&
				` · ${ number( quantity ) }` }
		</span>
	);
}

function Price( { price, regular, sale, currency } ) {
	if ( sale && regular && sale !== regular ) {
		return (
			<span className="aiops-r__price">
				<del>{ money( regular, currency ) }</del>{ ' ' }
				<strong>{ money( sale, currency ) }</strong>
			</span>
		);
	}
	return <span className="aiops-r__price">{ money( price, currency ) }</span>;
}

function Empty( { children } ) {
	return <div className="aiops-r__empty">{ children }</div>;
}

function Kpis( { items } ) {
	return (
		<div className="aiops-kpis">
			{ items.map( ( k ) => (
				<div className="aiops-kpi" key={ k.label }>
					<span className="aiops-kpi__value">{ k.value }</span>
					<span className="aiops-kpi__label">{ k.label }</span>
				</div>
			) ) }
		</div>
	);
}

function Warning( { children } ) {
	return <div className="aiops-r__warning">{ children }</div>;
}

/**
 * Checkbox of one row / of the whole page (select-all). Native inputs: they work in the
 * responsive card layout too.
 *
 * @param {Object}   props               Props.
 * @param {boolean}  props.checked       Checked.
 * @param {boolean}  props.indeterminate Some (not all) rows checked.
 * @param {Function} props.onChange      ( checked ) => void.
 * @param {string}   props.label         Accessible label.
 */
export function RowCheck( {
	checked,
	indeterminate = false,
	onChange,
	label,
} ) {
	return (
		<input
			type="checkbox"
			className="aiops-check__box"
			checked={ checked }
			ref={ ( el ) => {
				if ( el ) {
					el.indeterminate = indeterminate;
				}
			} }
			aria-label={ label }
			title={ label }
			onChange={ ( e ) => onChange( e.target.checked ) }
		/>
	);
}

/**
 * Selection state of a list of rows for the page checkbox.
 *
 * @param {Object|null} sel  Selection API.
 * @param {Array}       rows Rows with ids.
 * @return {{all:boolean,some:boolean}} State.
 */
function pageState( sel, rows ) {
	const on = rows.filter( ( r ) => sel.has( r.id ) ).length;
	return {
		all: rows.length > 0 && on === rows.length,
		some: on > 0 && on < rows.length,
	};
}

export function ProductTable( {
	items,
	currency,
	lowThreshold = null,
	source = '',
} ) {
	const sel = useSelection();
	const selectable = !! sel && !! source;
	const rows = selectable ? items.filter( ( p ) => p.id > 0 ) : [];
	const state = selectable ? pageState( sel, rows ) : null;
	return (
		<div className="aiops-table-wrap">
			<table
				className={ `aiops-table${
					selectable ? ' is-selectable' : ''
				}` }
			>
				<thead>
					<tr>
						{ selectable && (
							<th className="aiops-check">
								<RowCheck
									checked={ state.all }
									indeterminate={ state.some }
									label={ __(
										'Select all products on this page',
										'addsynapse-store-assistant'
									) }
									onChange={ ( checked ) =>
										sel.toggle(
											rows,
											checked,
											source,
											currency
										)
									}
								/>
							</th>
						) }
						<th>
							{ __( 'Product', 'addsynapse-store-assistant' ) }
						</th>
						<th>{ __( 'Type', 'addsynapse-store-assistant' ) }</th>
						<th>{ __( 'Price', 'addsynapse-store-assistant' ) }</th>
						<th>{ __( 'Stock', 'addsynapse-store-assistant' ) }</th>
						<th>
							{ __( 'Categories', 'addsynapse-store-assistant' ) }
						</th>
					</tr>
				</thead>
				<tbody>
					{ items.map( ( p ) => (
						<tr
							key={ p.id }
							className={
								selectable && sel.has( p.id )
									? 'is-selected'
									: ''
							}
						>
							{ selectable && (
								<td
									className="aiops-check"
									data-label={ __(
										'Select',
										'addsynapse-store-assistant'
									) }
								>
									<RowCheck
										checked={ sel.has( p.id ) }
										label={ sprintf(
											/* translators: %s: product name */ __(
												'Select %s',
												'addsynapse-store-assistant'
											),
											p.name
										) }
										onChange={ ( checked ) =>
											sel.toggle(
												[ p ],
												checked,
												source,
												currency
											)
										}
									/>
								</td>
							) }
							<td
								data-label={ __(
									'Product',
									'addsynapse-store-assistant'
								) }
							>
								<EditLink
									href={ p.edit_link }
									kind="product"
									id={ p.id }
									label={ editLabel() }
								>
									<span className="aiops-r__name">
										{ p.name }
									</span>
								</EditLink>
								<span className="aiops-r__sub">
									{ p.sku
										? `${ skuLabel() } ${ p.sku }`
										: `#${ p.id }` }
									{ p.status &&
										p.status !== 'publish' &&
										` · ${ productStatusLabel(
											p.status
										) }` }
								</span>
							</td>
							<td
								data-label={ __(
									'Type',
									'addsynapse-store-assistant'
								) }
							>
								{ typeLabel( p.type ) }
							</td>
							<td
								data-label={ __(
									'Price',
									'addsynapse-store-assistant'
								) }
							>
								<Price
									price={ p.price }
									regular={ p.regular_price }
									sale={ p.sale_price }
									currency={ currency }
								/>
							</td>
							<td
								data-label={ __(
									'Stock',
									'addsynapse-store-assistant'
								) }
							>
								<StockBadge
									status={ p.stock_status }
									quantity={ p.stock_quantity }
									lowThreshold={ lowThreshold }
								/>
							</td>
							<td
								data-label={ __(
									'Categories',
									'addsynapse-store-assistant'
								) }
							>
								{ ( p.categories || [] ).join( ', ' ) || '—' }
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}

function Pager( { data, noun } ) {
	return (
		<div className="aiops-r__meta">
			{ sprintf(
				/* translators: 1: rows shown, 2: total rows, 3: noun (e.g. products) */
				__( 'Showing %1$s of %2$s %3$s', 'addsynapse-store-assistant' ),
				number( data.items.length ),
				number( data.total ),
				noun
			) }
			{ data.pages > 1 &&
				' · ' +
					sprintf(
						/* translators: 1: current page, 2: number of pages */
						__( 'page %1$d of %2$d', 'addsynapse-store-assistant' ),
						data.page,
						data.pages
					) }
		</div>
	);
}

const SORT_LABELS = {
	date: () => __( 'newest first', 'addsynapse-store-assistant' ),
	modified: () =>
		__( 'recently modified first', 'addsynapse-store-assistant' ),
	title: () => __( 'by name', 'addsynapse-store-assistant' ),
	price: () => __( 'by price', 'addsynapse-store-assistant' ),
	sales: () => __( 'by sales', 'addsynapse-store-assistant' ),
	stock: () => __( 'by stock', 'addsynapse-store-assistant' ),
};

/**
 * Product search result. More pages are fetched from the same read-only ability (no AI, not
 * stored in the conversation); ticked products stay ticked across pages.
 *
 * @param {Object} props      Props.
 * @param {Object} props.data Tool result (page 1 / the page the tool returned).
 * @param {Object} props.part Tool part (id + input) — enables selection and paging.
 */
function SearchResult( { data: initial, part = null } ) {
	const [ loaded, setLoaded ] = useState( null );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );
	const data = loaded || initial;
	const items = data.items || [];
	const source = part && part.id ? part.id : '';
	const canPage = !! part && data.pages > 1;

	const go = async ( page ) => {
		setLoading( true );
		setError( '' );
		try {
			const input =
				part.input && typeof part.input === 'object' ? part.input : {};
			const res = await resultPage(
				String( part.name || '' ).startsWith( 'commerce__' )
					? 'commerce/search-products'
					: 'addsynapse-assistant/search-products',
				input,
				page
			);
			setLoaded( res.data );
		} catch ( e ) {
			setError( errorMessage( e ) );
		} finally {
			setLoading( false );
		}
	};

	return (
		<>
			<FiltersLine filters={ data.filters } />
			{ ( data.warnings || [] ).length > 0 && (
				<Warning>
					{ __(
						'Some filters matched nothing or the search was too broad.',
						'addsynapse-store-assistant'
					) }
				</Warning>
			) }
			{ items.length ? (
				<>
					<Pager
						data={ data }
						noun={ __( 'products', 'addsynapse-store-assistant' ) }
					/>
					{ data.orderby &&
						data.orderby !== 'date' &&
						SORT_LABELS[ data.orderby ] && (
							<div className="aiops-r__meta">
								{ SORT_LABELS[ data.orderby ]() }
								{ data.order === 'desc' &&
									! [ 'sales', 'stock', 'modified' ].includes(
										data.orderby
									) &&
									' ↓' }
								{ data.order === 'asc' &&
									[ 'sales', 'stock', 'modified' ].includes(
										data.orderby
									) &&
									' ↑' }
							</div>
						) }
					<div className={ loading ? 'aiops-r__loading' : '' }>
						<ProductTable
							items={ items }
							currency={ data.currency }
							source={ source }
						/>
					</div>
					{ canPage && (
						<div className="aiops-cs__pager">
							<Button
								variant="tertiary"
								size="small"
								disabled={ loading || data.page <= 1 }
								onClick={ () => go( data.page - 1 ) }
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
									data.page,
									data.pages
								) }
							</span>
							<Button
								variant="tertiary"
								size="small"
								disabled={ loading || data.page >= data.pages }
								onClick={ () => go( data.page + 1 ) }
							>
								{ __( 'Next', 'addsynapse-store-assistant' ) }
							</Button>
							{ loading && <Spinner /> }
						</div>
					) }
					{ error && <Warning>{ error }</Warning> }
				</>
			) : (
				<Empty>
					{ __( 'No products found.', 'addsynapse-store-assistant' ) }
				</Empty>
			) }
		</>
	);
}

function ProductCard( { data } ) {
	const variations = data.variations || [];
	const kpis = [
		{
			label: __( 'Price', 'addsynapse-store-assistant' ),
			value: (
				<Price
					price={ data.price }
					regular={ data.regular_price }
					sale={ data.sale_price }
					currency={ data.currency }
				/>
			),
		},
		{
			label: __( 'Stock', 'addsynapse-store-assistant' ),
			value:
				data.stock_quantity !== null &&
				data.stock_quantity !== undefined
					? number( data.stock_quantity )
					: stockLabel( data.stock_status ),
		},
		{
			label: __( 'Total sales', 'addsynapse-store-assistant' ),
			value: number( data.total_sales ),
		},
	];
	if ( data.type === 'variable' ) {
		kpis.push( {
			label: variationsLabel(),
			value: number( data.variations_total ),
		} );
	}
	const yes = __( 'Yes', 'addsynapse-store-assistant' );
	const no = __( 'No', 'addsynapse-store-assistant' );

	return (
		<div className="aiops-card">
			<div className="aiops-card__head">
				<div>
					<EditLink
						href={ data.edit_link }
						kind="product"
						id={ data.id }
						label={ editLabel() }
					>
						<span className="aiops-card__title">{ data.name }</span>
					</EditLink>
					<div className="aiops-card__badges">
						<span className="aiops-badge">
							{ typeLabel( data.type ) }
						</span>
						<span
							className={ `aiops-badge is-status-${ data.status }` }
						>
							{ productStatusLabel( data.status ) }
						</span>
						<StockBadge status={ data.stock_status } />
						{ data.on_sale && (
							<span className="aiops-badge is-sale">
								{ __(
									'On sale',
									'addsynapse-store-assistant'
								) }
							</span>
						) }
					</div>
				</div>
			</div>
			<Kpis items={ kpis } />
			<dl className="aiops-card__facts">
				<dt>{ skuLabel() }</dt>
				<dd>{ data.sku || '—' }</dd>
				<dt>{ __( 'Categories', 'addsynapse-store-assistant' ) }</dt>
				<dd>{ ( data.categories || [] ).join( ', ' ) || '—' }</dd>
				<dt>{ __( 'Tags', 'addsynapse-store-assistant' ) }</dt>
				<dd>{ ( data.tags || [] ).join( ', ' ) || '—' }</dd>
				<dt>{ __( 'Image', 'addsynapse-store-assistant' ) }</dt>
				<dd className={ data.has_image ? '' : 'is-missing' }>
					{ data.has_image ? yes : no }
					{ data.gallery_count > 0 &&
						' · ' +
							sprintf(
								/* translators: %d: number of gallery images */
								__(
									'gallery: %d',
									'addsynapse-store-assistant'
								),
								data.gallery_count
							) }
				</dd>
				<dt>{ __( 'Description', 'addsynapse-store-assistant' ) }</dt>
				<dd className={ data.description_length ? '' : 'is-missing' }>
					{ sprintf(
						/* translators: %d: number of characters */
						__( '%d characters', 'addsynapse-store-assistant' ),
						data.description_length || 0
					) }
				</dd>
				<dt>
					{ __( 'Short description', 'addsynapse-store-assistant' ) }
				</dt>
				<dd
					className={
						data.short_description_length ? '' : 'is-missing'
					}
				>
					{ sprintf(
						/* translators: %d: number of characters */
						__( '%d characters', 'addsynapse-store-assistant' ),
						data.short_description_length || 0
					) }
				</dd>
				{ ( data.attributes || [] ).map( ( a ) => (
					<AttributeRow
						key={ a.name }
						term={ a.name }
						value={ ( a.options || [] ).join( ', ' ) }
					/>
				) ) }
			</dl>
			{ variations.length > 0 && (
				<>
					<h4 className="aiops-card__subtitle">
						{ variationsLabel() }
					</h4>
					<div className="aiops-table-wrap">
						<table className="aiops-table">
							<thead>
								<tr>
									<th>{ variationLabel() }</th>
									<th>{ skuLabel() }</th>
									<th>
										{ __(
											'Price',
											'addsynapse-store-assistant'
										) }
									</th>
									<th>
										{ __(
											'Stock',
											'addsynapse-store-assistant'
										) }
									</th>
								</tr>
							</thead>
							<tbody>
								{ variations.map( ( v ) => (
									<tr
										key={ v.id }
										className={ [
											v.enabled ? '' : 'is-disabled',
											v.id === data.matched_variation_id
												? 'is-matched'
												: '',
										].join( ' ' ) }
									>
										<td data-label={ variationLabel() }>
											{ ( v.attributes || [] )
												.map(
													( a ) =>
														`${ a.name }: ${
															a.value === 'any'
																? __(
																		'Any',
																		'addsynapse-store-assistant'
																  )
																: a.value
														}`
												)
												.join( ', ' ) || `#${ v.id }` }
											{ ! v.enabled && (
												<span className="aiops-r__sub">
													{ __(
														'Disabled',
														'addsynapse-store-assistant'
													) }
												</span>
											) }
										</td>
										<td data-label={ skuLabel() }>
											{ v.sku || '—' }
										</td>
										<td
											data-label={ __(
												'Price',
												'addsynapse-store-assistant'
											) }
										>
											<Price
												price={ v.price }
												regular={ v.regular_price }
												sale={ v.sale_price }
												currency={ data.currency }
											/>
										</td>
										<td
											data-label={ __(
												'Stock',
												'addsynapse-store-assistant'
											) }
										>
											<StockBadge
												status={ v.stock_status }
												quantity={ v.stock_quantity }
											/>
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
					{ data.variations_truncated && (
						<Warning>
							{ sprintf(
								/* translators: 1: variations shown, 2: total variations */
								__(
									'Showing the first %1$d of %2$d variations.',
									'addsynapse-store-assistant'
								),
								variations.length,
								data.variations_total
							) }
						</Warning>
					) }
				</>
			) }
		</div>
	);
}

function AttributeRow( { term, value } ) {
	return (
		<>
			<dt>{ term }</dt>
			<dd>{ value || '—' }</dd>
		</>
	);
}

const REPORT_TITLES = {
	low_stock: () => __( 'Low stock', 'addsynapse-store-assistant' ),
	out_of_stock: () => __( 'Out of stock', 'addsynapse-store-assistant' ),
	missing_image: () =>
		__( 'Products without image', 'addsynapse-store-assistant' ),
	missing_description: () =>
		__( 'Products without description', 'addsynapse-store-assistant' ),
	missing_price: () =>
		__( 'Products without price', 'addsynapse-store-assistant' ),
	best_sellers: () => __( 'Best sellers', 'addsynapse-store-assistant' ),
	sales_summary: () => __( 'Sales summary', 'addsynapse-store-assistant' ),
};

function PeriodLine( { period } ) {
	if ( ! period ) {
		return null;
	}
	return (
		<div className="aiops-r__meta">
			{ periodLabel( period.period ) } · { date( period.from ) } –{ ' ' }
			{ date( period.to ) }
		</div>
	);
}

const FILTER_LABELS = {
	category: () => __( 'Category', 'addsynapse-store-assistant' ),
	tag: () => __( 'Tag', 'addsynapse-store-assistant' ),
	brand: () => __( 'Brand', 'addsynapse-store-assistant' ),
	attribute: () => __( 'Attribute', 'addsynapse-store-assistant' ),
	product_ids: () => __( 'Products', 'addsynapse-store-assistant' ),
};

/**
 * The product filters a report / search applied (+ why nothing matched).
 *
 * @param {Object} props          Props.
 * @param {Object} props.filters  Applied filters ({category: {name}, product_ids: [..]}).
 * @param {Array}  props.warnings Warnings.
 * @return {JSX.Element|null} Line.
 */
function FiltersLine( { filters, warnings = [] } ) {
	const entries = Object.entries( filters || {} ).filter(
		( [ key ] ) => FILTER_LABELS[ key ]
	);
	if ( ! entries.length && ! warnings.length ) {
		return null;
	}
	return (
		<>
			{ entries.length > 0 && (
				<div className="aiops-r__filters">
					{ entries.map( ( [ key, value ] ) => (
						<span key={ key } className="aiops-chip">
							{ FILTER_LABELS[ key ]() }:{ ' ' }
							{ Array.isArray( value )
								? sprintf(
										/* translators: %s: number of products */
										__(
											'%s selected',
											'addsynapse-store-assistant'
										),
										number( value.length )
								  )
								: value.name }
						</span>
					) ) }
				</div>
			) }
			{ warnings.map( ( w, i ) => (
				<Warning key={ i }>{ w }</Warning>
			) ) }
		</>
	);
}

function TopProducts( { items, currency, source = '' } ) {
	const sel = useSelection();
	if ( ! items.length ) {
		return (
			<Empty>
				{ __(
					'No sales in this period.',
					'addsynapse-store-assistant'
				) }
			</Empty>
		);
	}
	const selectable = !! sel && !! source;
	// Rows of deleted products have no id: they cannot be selected.
	const rows = selectable
		? items
				.filter( ( p ) => p.product_id > 0 )
				.map( ( p ) => ( { ...p, id: p.product_id } ) )
		: [];
	const state = selectable ? pageState( sel, rows ) : null;
	return (
		<div className="aiops-table-wrap">
			<table
				className={ `aiops-table${
					selectable ? ' is-selectable' : ''
				}` }
			>
				<thead>
					<tr>
						{ selectable && (
							<th className="aiops-check">
								{ rows.length > 0 && (
									<RowCheck
										checked={ state.all }
										indeterminate={ state.some }
										label={ __(
											'Select all products on this page',
											'addsynapse-store-assistant'
										) }
										onChange={ ( checked ) =>
											sel.toggle(
												rows,
												checked,
												source,
												currency
											)
										}
									/>
								) }
							</th>
						) }
						<th>#</th>
						<th>
							{ __( 'Product', 'addsynapse-store-assistant' ) }
						</th>
						<th>{ __( 'Units', 'addsynapse-store-assistant' ) }</th>
						<th>
							{ __( 'Revenue', 'addsynapse-store-assistant' ) }
						</th>
					</tr>
				</thead>
				<tbody>
					{ items.map( ( p, i ) => (
						<tr
							key={ `${ p.product_id }-${ i }` }
							className={
								selectable && sel.has( p.product_id )
									? 'is-selected'
									: ''
							}
						>
							{ selectable && (
								<td
									className="aiops-check"
									data-label={ __(
										'Select',
										'addsynapse-store-assistant'
									) }
								>
									{ p.product_id > 0 && (
										<RowCheck
											checked={ sel.has( p.product_id ) }
											label={ sprintf(
												/* translators: %s: product name */ __(
													'Select %s',
													'addsynapse-store-assistant'
												),
												p.name
											) }
											onChange={ ( checked ) =>
												sel.toggle(
													[
														{
															...p,
															id: p.product_id,
														},
													],
													checked,
													source,
													currency
												)
											}
										/>
									) }
								</td>
							) }
							<td data-label="#">{ i + 1 }</td>
							<td
								data-label={ __(
									'Product',
									'addsynapse-store-assistant'
								) }
							>
								<EditLink
									href={ p.edit_link }
									kind="product"
									id={ p.id }
									label={ editLabel() }
								>
									<span className="aiops-r__name">
										{ p.name }
									</span>
								</EditLink>
								{ p.sku && (
									<span className="aiops-r__sub">
										{ skuLabel() } { p.sku }
									</span>
								) }
								{ ( p.variations || [] ).length > 0 && (
									<ul className="aiops-r__variations">
										{ p.variations.map( ( v ) => (
											<li key={ v.variation_id }>
												{ v.name ||
													`#${ v.variation_id }` }
												{ ' · ' }
												{ sprintf(
													/* translators: %s: units sold */
													__(
														'%s units',
														'addsynapse-store-assistant'
													),
													number( v.quantity )
												) }
											</li>
										) ) }
									</ul>
								) }
							</td>
							<td
								data-label={ __(
									'Units',
									'addsynapse-store-assistant'
								) }
							>
								{ number( p.quantity ) }
							</td>
							<td
								data-label={ __(
									'Revenue',
									'addsynapse-store-assistant'
								) }
							>
								{ money( p.revenue, currency ) }
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}

function ReportResult( { data, part = null } ) {
	const source = part && part.id ? part.id : '';
	const title = REPORT_TITLES[ data.report ]
		? REPORT_TITLES[ data.report ]()
		: data.report;
	const sales =
		data.report === 'sales_summary' || data.report === 'best_sellers';

	if ( ! sales ) {
		const items = data.items || [];
		const kpis = [
			{
				label: __( 'Products', 'addsynapse-store-assistant' ),
				value: number( data.total ),
			},
		];
		if ( data.report === 'low_stock' ) {
			kpis.push( {
				label: __( 'Threshold', 'addsynapse-store-assistant' ),
				value: `≤ ${ number( data.threshold ) }`,
			} );
		}
		return (
			<>
				<h4 className="aiops-r__title">{ title }</h4>
				<FiltersLine
					filters={ data.filters }
					warnings={ data.warnings }
				/>
				<Kpis items={ kpis } />
				{ items.length ? (
					<>
						{ data.total > items.length && (
							<div className="aiops-r__meta">
								{ sprintf(
									/* translators: 1: rows shown, 2: total rows */
									__(
										'Showing the first %1$s of %2$s.',
										'addsynapse-store-assistant'
									),
									number( items.length ),
									number( data.total )
								) }
							</div>
						) }
						<ProductTable
							items={ items }
							currency={ data.currency }
							lowThreshold={
								data.report === 'low_stock'
									? data.threshold
									: null
							}
							source={ source }
						/>
					</>
				) : (
					<Empty>
						{ __(
							'Nothing to report — all good.',
							'addsynapse-store-assistant'
						) }
					</Empty>
				) }
				<div className="aiops-r__foot">
					{ __(
						'Published products only.',
						'addsynapse-store-assistant'
					) }
				</div>
			</>
		);
	}

	const s = data.summary;
	return (
		<>
			<h4 className="aiops-r__title">{ title }</h4>
			<PeriodLine period={ data.period } />
			<FiltersLine filters={ data.filters } warnings={ data.warnings } />
			{ s && (
				<Kpis
					items={ [
						{
							label: __(
								'Revenue',
								'addsynapse-store-assistant'
							),
							value: money( s.revenue, data.currency ),
						},
						{
							label: __( 'Orders', 'addsynapse-store-assistant' ),
							value: number( s.orders_count ),
						},
						{
							label: __(
								'Average order',
								'addsynapse-store-assistant'
							),
							value: money( s.avg_order_value, data.currency ),
						},
						{
							label: __(
								'Items sold',
								'addsynapse-store-assistant'
							),
							value: number( s.items_sold ),
						},
						{
							label: __(
								'Refunds',
								'addsynapse-store-assistant'
							),
							value: money( s.refunds, data.currency ),
						},
						{
							label: __(
								'Net revenue',
								'addsynapse-store-assistant'
							),
							value: money( s.net_revenue, data.currency ),
						},
					] }
				/>
			) }
			{ data.partial && (
				<Warning>
					{ sprintf(
						/* translators: 1: orders scanned, 2: orders in the period */
						__(
							'Partial result: only the newest %1$s of %2$s orders were counted. Choose a shorter period for exact figures.',
							'addsynapse-store-assistant'
						),
						number( data.orders_scanned ),
						number( data.orders_in_period )
					) }
				</Warning>
			) }
			{ s && data.series && (
				<SalesChart
					series={ data.series }
					currency={ data.currency }
					formatters={ { money, number } }
				/>
			) }
			{ ! s && (
				<BarList
					title={ __( 'Units sold', 'addsynapse-store-assistant' ) }
					rows={ ( data.top_products || [] ).map( ( p, i ) => ( {
						key: `${ p.product_id }-${ i }`,
						label: p.name,
						value: p.quantity,
						text: number( p.quantity ),
					} ) ) }
				/>
			) }
			{ s && (
				<h4 className="aiops-card__subtitle">
					{ __( 'Top products', 'addsynapse-store-assistant' ) }
				</h4>
			) }
			<TopProducts
				items={ data.top_products || [] }
				currency={ data.currency }
				source={ source }
			/>
			<div className="aiops-r__foot">
				{ sprintf(
					/* translators: %s: list of order statuses */
					__(
						'Paid orders only (%s). Revenue includes tax and shipping.',
						'addsynapse-store-assistant'
					),
					( data.statuses || [] ).join( ', ' )
				) }
			</div>
		</>
	);
}

function OrdersResult( { data } ) {
	const items = data.items || [];
	if ( ! items.length ) {
		return (
			<>
				<PeriodLine period={ data.period } />
				<Empty>
					{ __( 'No orders found.', 'addsynapse-store-assistant' ) }
				</Empty>
			</>
		);
	}
	return (
		<>
			<PeriodLine period={ data.period } />
			<Pager
				data={ data }
				noun={ __( 'orders', 'addsynapse-store-assistant' ) }
			/>
			<div className="aiops-table-wrap">
				<table className="aiops-table">
					<thead>
						<tr>
							<th>
								{ __( 'Order', 'addsynapse-store-assistant' ) }
							</th>
							<th>
								{ __( 'Date', 'addsynapse-store-assistant' ) }
							</th>
							<th>
								{ __( 'Status', 'addsynapse-store-assistant' ) }
							</th>
							<th>
								{ __( 'Total', 'addsynapse-store-assistant' ) }
							</th>
							<th>
								{ __( 'Items', 'addsynapse-store-assistant' ) }
							</th>
							<th>
								{ __(
									'Payment',
									'addsynapse-store-assistant'
								) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ items.map( ( o ) => (
							<tr key={ o.id }>
								<td
									data-label={ __(
										'Order',
										'addsynapse-store-assistant'
									) }
								>
									<EditLink
										href={ o.edit_link }
										kind="order"
										id={ o.id }
										label={ __(
											'Open the order (new tab)',
											'addsynapse-store-assistant'
										) }
									>
										<span className="aiops-r__name">
											#{ o.number }
										</span>
									</EditLink>
								</td>
								<td
									data-label={ __(
										'Date',
										'addsynapse-store-assistant'
									) }
								>
									{ date( o.date_created, true ) }
								</td>
								<td
									data-label={ __(
										'Status',
										'addsynapse-store-assistant'
									) }
								>
									<span
										className={ `aiops-badge is-order-${ o.status }` }
									>
										{ o.status_label }
									</span>
								</td>
								<td
									data-label={ __(
										'Total',
										'addsynapse-store-assistant'
									) }
								>
									{ money( o.total, o.currency ) }
								</td>
								<td
									data-label={ __(
										'Items',
										'addsynapse-store-assistant'
									) }
								>
									{ number( o.item_count ) }
								</td>
								<td
									data-label={ __(
										'Payment',
										'addsynapse-store-assistant'
									) }
								>
									{ o.payment_method_title || '—' }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
		</>
	);
}

/**
 * Top customers (addsynapse-assistant/customer-report). The result has only references (c:12 / g:…); the names come from
 * `data.display`, which the plugin adds for this browser only — never in what the assistant or the hub sees.
 * In the addsynapse portal there are no names: the reference and a link to the shop admin.
 *
 * @param {Object} props      Props.
 * @param {Object} props.data Result.
 * @return {JSX.Element} View.
 */
function CustomersResult( { data } ) {
	const rows = data.customers || [];
	const display = data.display || {};
	return (
		<>
			<h4 className="aiops-r__title">
				{ data.sort === 'orders'
					? __(
							'Top customers by number of orders',
							'addsynapse-store-assistant'
					  )
					: __(
							'Top customers by total spent',
							'addsynapse-store-assistant'
					  ) }
			</h4>
			<PeriodLine period={ data.period } />
			{ data.partial && (
				<Warning>
					{ sprintf(
						/* translators: 1: orders scanned, 2: orders in the period */
						__(
							'Partial result: only the newest %1$s of %2$s orders were counted. Choose a shorter period for exact figures.',
							'addsynapse-store-assistant'
						),
						number( data.orders_scanned ),
						number( data.orders_in_period )
					) }
				</Warning>
			) }
			<BarList
				title={
					data.sort === 'orders'
						? __(
								'Orders per customer',
								'addsynapse-store-assistant'
						  )
						: __(
								'Total spent per customer',
								'addsynapse-store-assistant'
						  )
				}
				rows={ rows.map( ( c ) => ( {
					key: c.customer_ref,
					label:
						( display[ c.customer_ref ] &&
							display[ c.customer_ref ].name ) ||
						c.customer_ref,
					value:
						data.sort === 'orders' ? c.orders_count : c.total_spent,
					text:
						data.sort === 'orders'
							? number( c.orders_count )
							: money( c.total_spent, data.currency ),
				} ) ) }
			/>
			{ ! rows.length ? (
				<Empty>
					{ __(
						'No orders in this period.',
						'addsynapse-store-assistant'
					) }
				</Empty>
			) : (
				<div className="aiops-table-wrap">
					<table className="aiops-table">
						<thead>
							<tr>
								<th>#</th>
								<th>
									{ __(
										'Customer',
										'addsynapse-store-assistant'
									) }
								</th>
								<th>
									{ __(
										'Orders',
										'addsynapse-store-assistant'
									) }
								</th>
								<th>
									{ __(
										'Total spent',
										'addsynapse-store-assistant'
									) }
								</th>
								<th>
									{ __(
										'First order',
										'addsynapse-store-assistant'
									) }
								</th>
								<th>
									{ __(
										'Last order',
										'addsynapse-store-assistant'
									) }
								</th>
							</tr>
						</thead>
						<tbody>
							{ rows.map( ( c, i ) => {
								const name = display[ c.customer_ref ]
									? display[ c.customer_ref ].name
									: '';
								return (
									<tr key={ c.customer_ref }>
										<td data-label="#">{ i + 1 }</td>
										<td
											data-label={ __(
												'Customer',
												'addsynapse-store-assistant'
											) }
										>
											<EditLink
												href={ c.link }
												kind={
													c.registered ? '' : 'order'
												}
												id={ c.last_order_id }
												label={
													c.registered
														? __(
																'Open the customer’s orders (new tab)',
																'addsynapse-store-assistant'
														  )
														: __(
																'Open the latest order (new tab)',
																'addsynapse-store-assistant'
														  )
												}
											>
												<span className="aiops-r__name">
													{ name || c.customer_ref }
												</span>
											</EditLink>
											<span className="aiops-r__sub">
												{ c.registered
													? __(
															'Registered',
															'addsynapse-store-assistant'
													  )
													: __(
															'Guest',
															'addsynapse-store-assistant'
													  ) }
												{ name
													? ` · ${ c.customer_ref }`
													: '' }
											</span>
										</td>
										<td
											data-label={ __(
												'Orders',
												'addsynapse-store-assistant'
											) }
										>
											{ number( c.orders_count ) }
										</td>
										<td
											data-label={ __(
												'Total spent',
												'addsynapse-store-assistant'
											) }
										>
											{ money(
												c.total_spent,
												data.currency
											) }
										</td>
										<td
											data-label={ __(
												'First order',
												'addsynapse-store-assistant'
											) }
										>
											{ date( c.first_order ) }
										</td>
										<td
											data-label={ __(
												'Last order',
												'addsynapse-store-assistant'
											) }
										>
											{ date( c.last_order ) }
										</td>
									</tr>
								);
							} ) }
						</tbody>
					</table>
				</div>
			) }
			<div className="aiops-r__foot">
				{ sprintf(
					/* translators: %s: list of order statuses */
					__(
						'Paid orders only (%s); total spent = order totals minus refunds. The assistant sees only the customer references, not names.',
						'addsynapse-store-assistant'
					),
					( data.statuses || [] ).join( ', ' )
				) }
			</div>
		</>
	);
}

/**
 * A request this plugin does not do (e.g. a bulk change): a calm note that it is coming in Store Assistant Pro.
 *
 * @param {Object} props      Props.
 * @param {Object} props.data { status: 'pro_feature', feature, message }.
 * @param {Object} props.part Tool part (the first Pro reply of a conversation carries the account offer).
 * @return {JSX.Element} Notice.
 */
function ProFeatureNotice( { data, part } ) {
	return (
		<div className="aiops-pro-note">
			<p className="aiops-pro-note__text">
				{ data.message ||
					__(
						'Bulk changes are coming in Store Assistant Pro.',
						'addsynapse-store-assistant'
					) }
			</p>
			<ProOfferCta part={ part } />
		</div>
	);
}

const RENDERERS = {
	'addsynapse-assistant__coming-in-pro': ProFeatureNotice,
	'addsynapse-assistant__search-products': SearchResult,
	'addsynapse-assistant__get-product': ProductCard,
	'addsynapse-assistant__product-report': ReportResult,
	'addsynapse-assistant__list-orders': OrdersResult,
	'addsynapse-assistant__describe-store': StoreMapView,
	'addsynapse-assistant__get-product-raw': ProductRawView,
	'addsynapse-assistant__find-terms': TermMatchesView,
	'addsynapse-assistant__plan-price-update': PlanResult,
	'addsynapse-assistant__plan-stock-update': PlanResult,
	'addsynapse-assistant__plan-taxonomy-terms': PlanResult,
	'addsynapse-assistant__plan-brand-assign': PlanResult,
	'addsynapse-assistant__describe-menus': MenusView,
	'addsynapse-assistant__get-seo': SeoView,
	'addsynapse-assistant__plan-seo-update': PlanResult,
	'addsynapse-assistant__describe-page': PageView,
	'addsynapse-assistant__find-media': MediaView,
	'addsynapse-assistant__plan-product-update': PlanResult,
	'addsynapse-assistant__customer-report': CustomersResult,
};

/**
 * Commerce contract v1 (addsynapse portal — PrestaShop, Magento): the canonical tools (`commerce/<kebab>`) return the
 * same result shapes as their WooCommerce abilities, so they use the same views.
 */
const CONTRACT_RENDERERS = {
	'commerce__search-products': SearchResult,
	'commerce__get-product': ProductCard,
	'commerce__product-report': ReportResult,
	'commerce__list-orders': OrdersResult,
	'commerce__describe-store': StoreMapView,
	'commerce__find-terms': TermMatchesView,
	'commerce__get-seo': SeoView,
	'commerce__plan-price-update': PlanResult,
	'commerce__plan-stock-update': PlanResult,
	'commerce__plan-categories': PlanResult,
	'commerce__plan-product-update': PlanResult,
	'commerce__customer-report': CustomersResult,
	'commerce__plan-seo-update': PlanResult,
};

/**
 * Renderer registered for a tool name. Our own abilities (`addsynapse-assistant__x`) are also matched
 * under another namespace with the same tool (the portal shows the hub's internal names).
 *
 * @param {string} name Tool name (`<namespace>__<tool>`).
 * @return {Function|undefined} Component.
 */
function knownView( name ) {
	const key = String( name || '' );
	if ( RENDERERS[ key ] || CONTRACT_RENDERERS[ key ] ) {
		return RENDERERS[ key ] || CONTRACT_RENDERERS[ key ];
	}
	const at = key.indexOf( '__' );
	return at > 0
		? RENDERERS[ 'addsynapse-assistant__' + key.slice( at + 2 ) ]
		: undefined;
}

/**
 * View component for a tool part. Any result carrying a changeset id falls back
 * to the changeset card, so a plan tool missing from RENDERERS still shows its
 * Approve/Reject buttons.
 *
 * @param {Object} part Tool part.
 * @return {Function|undefined} Component.
 */
function viewFor( part ) {
	if (
		part.data &&
		typeof part.data === 'object' &&
		part.data.status === 'pro_feature'
	) {
		return ProFeatureNotice;
	}
	const known = knownView( part.name );
	if ( known ) {
		return known;
	}
	if (
		part.data &&
		typeof part.data === 'object' &&
		part.data.changeset_id
	) {
		return PlanResult;
	}
	return undefined;
}

/**
 * Whether a tool part has a dedicated view.
 *
 * @param {Object} part Tool part.
 * @return {boolean} True when rendered.
 */
export function hasResultView( part ) {
	return (
		part.state === 'done' &&
		part.data !== null &&
		typeof part.data === 'object' &&
		!! viewFor( part )
	);
}

/**
 * Human text for known tool errors (tool errors are written in English for the model).
 *
 * @param {string} message Raw message.
 * @return {string} Text.
 */
function errorText( message ) {
	if ( /does not have necessary permission/.test( message ) ) {
		return __(
			'You do not have permission to use this tool.',
			'addsynapse-store-assistant'
		);
	}
	if ( /has invalid input/.test( message ) ) {
		const reason = message.replace( /^.*Reason:\s*/, '' );
		return sprintf(
			/* translators: %s: technical reason */
			__( 'Invalid parameters (%s).', 'addsynapse-store-assistant' ),
			reason
		);
	}
	const known = {
		'Product not found.': __(
			'Product not found.',
			'addsynapse-store-assistant'
		),
		'Give a product id or a SKU.': __(
			'Give a product id or SKU.',
			'addsynapse-store-assistant'
		),
		'Give a term name to look for.': __(
			'Give a term name to look for.',
			'addsynapse-store-assistant'
		),
	};
	if ( /^Say which product/.test( message ) ) {
		return __(
			'Say which product: its id or SKU.',
			'addsynapse-store-assistant'
		);
	}
	if ( /^Unknown product taxonomy or attribute/.test( message ) ) {
		return __(
			'Unknown taxonomy or attribute. Use /store to see the available ones.',
			'addsynapse-store-assistant'
		);
	}
	return known[ message ] || message;
}

export default function ResultView( { part } ) {
	if (
		part.state === 'error' &&
		typeof part.result === 'string' &&
		knownView( part.name )
	) {
		return (
			<div className="aiops-result is-error" role="alert">
				{ errorText( part.result ) }
			</div>
		);
	}
	if ( ! hasResultView( part ) ) {
		return null;
	}
	const View = viewFor( part );
	return (
		<div
			className="aiops-result"
			id={ part.id ? `addsynapse-assistant-src-${ part.id }` : undefined }
		>
			<View data={ part.data } part={ part } />
			{ part.data._truncated && (
				<Warning>
					{ __(
						'The result was too large; some rows are not shown.',
						'addsynapse-store-assistant'
					) }
				</Warning>
			) }
		</div>
	);
}
