/**
 * Views for the discovery tools: store map, raw product data, term matches.
 *
 * Everything is store DATA rendered as React text nodes (never HTML). Long sections are
 * collapsible (<details>) so a big store map stays readable in the chat.
 */
import { __, sprintf } from '@wordpress/i18n';
import { adminLink, number, skuLabel } from '../lib/format';

function Section( { title, count, open = false, children } ) {
	return (
		<details className="aiops-sec" open={ open }>
			<summary>
				<span className="aiops-sec__title">{ title }</span>
				{ count !== undefined && (
					<span className="aiops-sec__count">
						{ number( count ) }
					</span>
				) }
			</summary>
			<div className="aiops-sec__body">{ children }</div>
		</details>
	);
}

function Table( { head, rows } ) {
	return (
		<div className="aiops-table-wrap">
			<table className="aiops-table">
				<thead>
					<tr>
						{ head.map( ( h ) => (
							<th key={ h }>{ h }</th>
						) ) }
					</tr>
				</thead>
				<tbody>
					{ rows.map( ( cells, i ) => (
						<tr key={ i }>
							{ cells.map( ( c, j ) => (
								<td key={ j } data-label={ head[ j ] }>
									{ c }
								</td>
							) ) }
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}

function Muted( { children } ) {
	return <div className="aiops-r__meta">{ children }</div>;
}

function Code( { children } ) {
	return <code className="aiops-code">{ children }</code>;
}

/**
 * A value as short text (objects as compact JSON).
 *
 * @param {*} value Value.
 * @return {string} Text.
 */
function valueText( value ) {
	if ( value === null || value === undefined || value === '' ) {
		return '—';
	}
	if ( typeof value === 'boolean' ) {
		return value
			? __( 'Yes', 'addsynapse-store-assistant' )
			: __( 'No', 'addsynapse-store-assistant' );
	}
	if ( typeof value === 'object' ) {
		return JSON.stringify( value );
	}
	return String( value );
}

const KIND_LABELS = {
	custom: () => __( 'custom', 'addsynapse-store-assistant' ),
	wc_internal: () => __( 'WooCommerce', 'addsynapse-store-assistant' ),
	sensitive: () => __( 'sensitive', 'addsynapse-store-assistant' ),
	variation_attributes: () =>
		__( 'variation attributes', 'addsynapse-store-assistant' ),
	acf_reference: () => __( 'ACF reference', 'addsynapse-store-assistant' ),
};

function Kind( { kind } ) {
	return (
		<span className={ `aiops-badge is-kind-${ kind }` }>
			{ KIND_LABELS[ kind ] ? KIND_LABELS[ kind ]() : kind }
		</span>
	);
}

function RawJson( { data } ) {
	return (
		<Section title={ __( 'Raw JSON', 'addsynapse-store-assistant' ) }>
			<pre className="aiops-json">
				{ JSON.stringify( data, null, 2 ) }
			</pre>
		</Section>
	);
}

function TermList( { terms } ) {
	if ( ! terms || ! terms.length ) {
		return '—';
	}
	return terms
		.map(
			( t ) => t.name + ( t.count !== undefined ? ` (${ t.count })` : '' )
		)
		.join( ', ' );
}

/**
 * Site map (describe-store on a WordPress site without WooCommerce).
 *
 * @param {Object} props      Props.
 * @param {Object} props.data Result.
 */
function SiteMapView( { data } ) {
	const content = data.content || {};
	const pages = data.pages || [];
	const menus = data.menus || {};
	const seo = data.seo_plugin || {};
	const theme = data.theme || {};
	const front = content.front_page;
	return (
		<>
			<h4 className="aiops-r__title">
				{ __( 'Site map', 'addsynapse-store-assistant' ) }
			</h4>
			<div className="aiops-kpis">
				{ [
					[
						__( 'Pages', 'addsynapse-store-assistant' ),
						number( ( content.pages || {} ).publish || 0 ),
					],
					[
						__( 'Posts', 'addsynapse-store-assistant' ),
						number( ( content.posts || {} ).publish || 0 ),
					],
					[
						__( 'Menus', 'addsynapse-store-assistant' ),
						number( ( menus.menus || [] ).length ),
					],
				].map( ( [ label, value ] ) => (
					<div className="aiops-kpi" key={ label }>
						<span className="aiops-kpi__value">{ value }</span>
						<span className="aiops-kpi__label">{ label }</span>
					</div>
				) ) }
			</div>
			<Facts
				entries={ [
					[
						__( 'Theme', 'addsynapse-store-assistant' ),
						theme.name || '—',
					],
					[
						__( 'Homepage', 'addsynapse-store-assistant' ),
						front
							? front.title
							: __(
									'Latest posts',
									'addsynapse-store-assistant'
							  ),
					],
					[
						__( 'SEO plugin', 'addsynapse-store-assistant' ),
						seo.supported
							? seo.label
							: __(
									'none supported',
									'addsynapse-store-assistant'
							  ),
					],
				] }
			/>
			<Section
				title={ __( 'Pages', 'addsynapse-store-assistant' ) }
				count={ pages.length }
				open
			>
				<Table
					head={ [
						__( 'Page', 'addsynapse-store-assistant' ),
						__( 'Status', 'addsynapse-store-assistant' ),
						__( 'Builder', 'addsynapse-store-assistant' ),
					] }
					rows={ pages.map( ( p ) => [
						<>
							<span className="aiops-r__name">{ p.title }</span>
							<span className="aiops-r__sub">#{ p.id }</span>
						</>,
						p.status,
						<Code key="b">{ p.builder }</Code>,
					] ) }
				/>
			</Section>
		</>
	);
}

export function StoreMapView( { data } ) {
	if ( data.site_type === 'site' ) {
		return <SiteMapView data={ data } />;
	}
	const taxonomies = data.taxonomies || [];
	const attributes = data.attributes || [];
	const local = data.local_attributes || [];
	const meta = data.meta_keys || { keys: [] };
	const plugins = data.plugins || [];
	const acf = data.acf || {};
	const brand = data.brand || {};
	const types = data.product_types || {};
	const products = Object.values( types ).reduce( ( a, b ) => a + b, 0 );

	return (
		<>
			<h4 className="aiops-r__title">
				{ __( 'Store map', 'addsynapse-store-assistant' ) }
			</h4>
			<div className="aiops-kpis">
				{ [
					[
						__( 'Products', 'addsynapse-store-assistant' ),
						number( products ),
					],
					[
						__( 'Taxonomies', 'addsynapse-store-assistant' ),
						number( taxonomies.length ),
					],
					[
						__( 'Global attributes', 'addsynapse-store-assistant' ),
						number( attributes.length ),
					],
					[
						__( 'Local attributes', 'addsynapse-store-assistant' ),
						number( local.length ),
					],
					[
						__( 'Meta keys', 'addsynapse-store-assistant' ),
						number( meta.total_keys || 0 ),
					],
				].map( ( [ label, value ] ) => (
					<div className="aiops-kpi" key={ label }>
						<span className="aiops-kpi__value">{ value }</span>
						<span className="aiops-kpi__label">{ label }</span>
					</div>
				) ) }
			</div>
			<div className="aiops-r__brand">
				<strong>
					{ __( 'Brand:', 'addsynapse-store-assistant' ) }
				</strong>{ ' ' }
				{ brand.primary ? (
					<>
						<Code>{ brand.primary }</Code>
						{ ( brand.sources || [] )[ 0 ] &&
							` · ${
								brand.sources[ 0 ].plugin ||
								__( 'attribute', 'addsynapse-store-assistant' )
							} · ` +
								sprintf(
									/* translators: %s: number of terms */
									__(
										'%s terms',
										'addsynapse-store-assistant'
									),
									number( brand.sources[ 0 ].terms_count )
								) }
					</>
				) : (
					__(
						'not found (no brand taxonomy or attribute)',
						'addsynapse-store-assistant'
					)
				) }
			</div>

			<Section
				title={ __( 'Taxonomies', 'addsynapse-store-assistant' ) }
				count={ taxonomies.length }
				open
			>
				<Table
					head={ [
						__( 'Taxonomy', 'addsynapse-store-assistant' ),
						__( 'Terms', 'addsynapse-store-assistant' ),
						__( 'Most used', 'addsynapse-store-assistant' ),
					] }
					rows={ taxonomies.map( ( t ) => [
						<>
							<span className="aiops-r__name">{ t.label }</span>
							<span className="aiops-r__sub">
								<Code>{ t.taxonomy }</Code>
								{ t.brand_plugin && ` · ${ t.brand_plugin }` }
							</span>
						</>,
						number( t.terms_count ),
						<TermList key="t" terms={ t.terms } />,
					] ) }
				/>
			</Section>

			<Section
				title={ __(
					'Global attributes',
					'addsynapse-store-assistant'
				) }
				count={ attributes.length }
			>
				{ attributes.length ? (
					<Table
						head={ [
							__( 'Attribute', 'addsynapse-store-assistant' ),
							__( 'Terms', 'addsynapse-store-assistant' ),
							__( 'Values', 'addsynapse-store-assistant' ),
						] }
						rows={ attributes.map( ( a ) => [
							<>
								<span className="aiops-r__name">
									{ a.label }
								</span>
								<span className="aiops-r__sub">
									<Code>{ a.taxonomy }</Code>
								</span>
							</>,
							number( a.terms_count ),
							<TermList key="t" terms={ a.terms } />,
						] ) }
					/>
				) : (
					<Muted>
						{ __(
							'No global attributes.',
							'addsynapse-store-assistant'
						) }
					</Muted>
				) }
			</Section>

			<Section
				title={ __( 'Local attributes', 'addsynapse-store-assistant' ) }
				count={ local.length }
			>
				{ local.length ? (
					<Table
						head={ [
							__( 'Name', 'addsynapse-store-assistant' ),
							__( 'Products', 'addsynapse-store-assistant' ),
						] }
						rows={ local.map( ( a ) => [
							a.name,
							number( a.products ),
						] ) }
					/>
				) : (
					<Muted>
						{ __(
							'No local attributes.',
							'addsynapse-store-assistant'
						) }
					</Muted>
				) }
			</Section>

			<Section
				title={ __(
					'Product meta keys',
					'addsynapse-store-assistant'
				) }
				count={ meta.total_keys || 0 }
			>
				<Muted>
					{ sprintf(
						/* translators: %s: number of products/variations sampled */
						__(
							'Sample of the newest %s products and variations.',
							'addsynapse-store-assistant'
						),
						number( meta.sampled_posts || 0 )
					) }
				</Muted>
				<Table
					head={ [
						__( 'Key', 'addsynapse-store-assistant' ),
						__( 'Kind', 'addsynapse-store-assistant' ),
						__( 'Products', 'addsynapse-store-assistant' ),
						__( 'Variations', 'addsynapse-store-assistant' ),
						__( 'Type', 'addsynapse-store-assistant' ),
						__( 'Sample', 'addsynapse-store-assistant' ),
					] }
					rows={ ( meta.keys || [] ).map( ( k ) => [
						<Code key="k">{ k.key }</Code>,
						<Kind key="kind" kind={ k.kind } />,
						number( k.products ),
						number( k.variations ),
						k.type,
						k.names ? k.names.join( ', ' ) : k.sample || '—',
					] ) }
				/>
			</Section>

			<Section
				title={ __( 'Active plugins', 'addsynapse-store-assistant' ) }
				count={ plugins.length }
			>
				<Table
					head={ [
						__( 'Plugin', 'addsynapse-store-assistant' ),
						__( 'Version', 'addsynapse-store-assistant' ),
						__( 'Products', 'addsynapse-store-assistant' ),
					] }
					rows={ plugins.map( ( p ) => [
						p.name,
						p.version,
						p.product_related
							? __( 'Yes', 'addsynapse-store-assistant' )
							: '—',
					] ) }
				/>
			</Section>

			{ acf.active && (
				<Section
					title={ __(
						'ACF field groups',
						'addsynapse-store-assistant'
					) }
					count={ ( acf.groups || [] ).length }
				>
					{ ( acf.groups || [] ).map( ( g ) => (
						<div key={ g.key } className="aiops-sec__group">
							<strong>{ g.title }</strong>
							<Table
								head={ [
									__( 'Field', 'addsynapse-store-assistant' ),
									__( 'Type', 'addsynapse-store-assistant' ),
									__(
										'Choices',
										'addsynapse-store-assistant'
									),
								] }
								rows={ g.fields.map( ( f ) => [
									<>
										{ f.label } <Code>{ f.name }</Code>
									</>,
									f.type,
									f.choices
										? Object.values( f.choices ).join(
												', '
										  )
										: '—',
								] ) }
							/>
						</div>
					) ) }
				</Section>
			) }
			<Muted>
				{ data.cached
					? __( 'From cache.', 'addsynapse-store-assistant' )
					: __( 'Freshly built.', 'addsynapse-store-assistant' ) }
			</Muted>
		</>
	);
}

function Facts( { entries } ) {
	return (
		<dl className="aiops-card__facts">
			{ entries.map( ( [ k, v ] ) => (
				<div key={ k } className="aiops-fact">
					<dt>
						<Code>{ k }</Code>
					</dt>
					<dd>{ v }</dd>
				</div>
			) ) }
		</dl>
	);
}

export function ProductRawView( { data } ) {
	const core = data.core || {};
	const edit = adminLink( data.edit_link );
	const taxonomies = Object.entries( data.taxonomies || {} ).filter(
		( [ , terms ] ) => terms.length
	);
	const attributes = data.attributes || [];
	const meta = data.meta || [];
	const variations = data.variations || [];
	const coreEntries = Object.entries( core ).map( ( [ k, v ] ) => [
		k,
		v && typeof v === 'object' && 'text' in v
			? v.text || '—'
			: valueText( v ),
	] );

	return (
		<>
			<h4 className="aiops-r__title">
				{ edit ? (
					<a
						className="aiops-r__link"
						href={ edit }
						target="_blank"
						rel="noopener noreferrer"
					>
						<span className="aiops-r__name">{ data.name }</span>
					</a>
				) : (
					data.name
				) }{ ' ' }
				<span className="aiops-r__sub">
					#{ data.id } · { data.type }
				</span>
			</h4>
			<Section
				title={ __( 'Core fields', 'addsynapse-store-assistant' ) }
				count={ coreEntries.length }
			>
				<Facts entries={ coreEntries } />
			</Section>
			<Section
				title={ __( 'Taxonomies', 'addsynapse-store-assistant' ) }
				count={ taxonomies.length }
				open
			>
				{ taxonomies.length ? (
					<Facts
						entries={ taxonomies.map( ( [ tax, terms ] ) => [
							tax,
							terms
								.map( ( t ) => `${ t.name } (#${ t.id })` )
								.join( ', ' ),
						] ) }
					/>
				) : (
					<Muted>—</Muted>
				) }
			</Section>
			<Section
				title={ __( 'Attributes', 'addsynapse-store-assistant' ) }
				count={ attributes.length }
				open
			>
				{ attributes.length ? (
					<Table
						head={ [
							__( 'Attribute', 'addsynapse-store-assistant' ),
							__( 'Kind', 'addsynapse-store-assistant' ),
							__( 'Values', 'addsynapse-store-assistant' ),
							__( 'Flags', 'addsynapse-store-assistant' ),
						] }
						rows={ attributes.map( ( a ) => [
							<>
								{ a.label } <Code>{ a.key }</Code>
							</>,
							a.global
								? __( 'global', 'addsynapse-store-assistant' )
								: __( 'local', 'addsynapse-store-assistant' ),
							( a.terms || [] )
								.map( ( t ) => t.name )
								.concat( a.options || [] )
								.join( ', ' ) || '—',
							[
								a.visible &&
									__(
										'visible',
										'addsynapse-store-assistant'
									),
								a.variation &&
									__(
										'used for variations',
										'addsynapse-store-assistant'
									),
							]
								.filter( Boolean )
								.join( ', ' ) || '—',
						] ) }
					/>
				) : (
					<Muted>—</Muted>
				) }
			</Section>
			<Section
				title={ __( 'Meta', 'addsynapse-store-assistant' ) }
				count={ ( data.meta_info || {} ).total ?? meta.length }
			>
				{ meta.length ? (
					<Table
						head={ [
							__( 'Key', 'addsynapse-store-assistant' ),
							__( 'Kind', 'addsynapse-store-assistant' ),
							__( 'Value', 'addsynapse-store-assistant' ),
						] }
						rows={ meta.map( ( m ) => [
							<Code key="k">{ m.key }</Code>,
							<Kind key="kind" kind={ m.kind } />,
							m.summarized && m.value === null
								? m.summarized
								: valueText( m.value ),
						] ) }
					/>
				) : (
					<Muted>
						{ __(
							'No custom meta.',
							'addsynapse-store-assistant'
						) }
					</Muted>
				) }
			</Section>
			{ Array.isArray( data.acf ) && (
				<Section title="ACF" count={ data.acf.length }>
					<Facts
						entries={ data.acf.map( ( f ) => [
							f.name,
							valueText( f.value ),
						] ) }
					/>
				</Section>
			) }
			{ variations.length > 0 && (
				<Section
					title={ __( 'Variations', 'addsynapse-store-assistant' ) }
					count={ data.variations_total ?? variations.length }
				>
					<Table
						head={ [
							'ID',
							__( 'Attributes', 'addsynapse-store-assistant' ),
							skuLabel(),
							__( 'Price', 'addsynapse-store-assistant' ),
							__( 'Stock', 'addsynapse-store-assistant' ),
						] }
						rows={ variations.map( ( v ) => [
							`#${ v.id }`,
							Object.entries( v.attributes || {} )
								.map( ( [ k, val ] ) => `${ k }: ${ val }` )
								.join( ', ' ),
							v.sku || '—',
							v.sale_price
								? `${ v.sale_price } (${ v.regular_price })`
								: v.regular_price || '—',
							v.stock_quantity !== null &&
							v.stock_quantity !== undefined
								? `${ v.stock_status } · ${ v.stock_quantity }`
								: v.stock_status,
						] ) }
					/>
				</Section>
			) }
			<RawJson data={ data } />
		</>
	);
}

export function TermMatchesView( { data } ) {
	const matches = data.matches || [];
	return (
		<>
			<h4 className="aiops-r__title">
				{ sprintf(
					/* translators: 1: what the user searched, 2: taxonomy label */
					__( '“%1$s” in %2$s', 'addsynapse-store-assistant' ),
					data.query,
					data.taxonomy_label
				) }{ ' ' }
				<span className="aiops-r__sub">
					<Code>{ data.taxonomy }</Code>
				</span>
			</h4>
			{ matches.length ? (
				<Table
					head={ [
						__( 'Term', 'addsynapse-store-assistant' ),
						'Slug',
						__( 'Products', 'addsynapse-store-assistant' ),
						__( 'Similarity', 'addsynapse-store-assistant' ),
					] }
					rows={ matches.map( ( m ) => [
						<>
							<span className="aiops-r__name">{ m.name }</span>{ ' ' }
							<span className="aiops-r__sub">#{ m.id }</span>
						</>,
						m.slug,
						number( m.count ),
						<span
							key="s"
							className={ `aiops-badge is-match-${ m.match }` }
						>
							{ Math.round( m.score * 100 ) }% · { m.match }
						</span>,
					] ) }
				/>
			) : (
				<div className="aiops-r__empty">
					{ __(
						'No similar term found.',
						'addsynapse-store-assistant'
					) }
				</div>
			) }
			<Muted>
				{ sprintf(
					/* translators: %s: number of terms in the taxonomy */
					__( '%s terms in total.', 'addsynapse-store-assistant' ),
					number( data.total_terms )
				) }{ ' ' }
				{ ! data.exact &&
					matches.length > 0 &&
					__(
						'No exact match — confirm the closest term.',
						'addsynapse-store-assistant'
					) }
			</Muted>
		</>
	);
}
