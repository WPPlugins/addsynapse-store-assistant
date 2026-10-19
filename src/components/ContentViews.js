/**
 * Read-only appearance views: the elements of a page (describe-page) and media library images
 * (find-media).
 *
 * Page texts are store DATA: shown as text nodes (HTML tags are stripped for display, never
 * rendered). Links only to this site (siteLink / adminLink); thumbnails only from this site.
 */
import { __, sprintf } from '@wordpress/i18n';
import { adminLink, number, siteLink } from '../lib/format';

/**
 * HTML → plain text (display only).
 *
 * @param {string} html HTML.
 * @return {string} Text.
 */
export function plain( html ) {
	if ( typeof html !== 'string' || ! html ) {
		return '';
	}
	const doc = new window.DOMParser().parseFromString( html, 'text/html' );
	return ( doc.body.textContent || '' ).replace( /\s+/g, ' ' ).trim();
}

export const nodeTypeLabel = ( type ) =>
	( {
		heading: __( 'Heading', 'addsynapse-store-assistant' ),
		text: __( 'Text', 'addsynapse-store-assistant' ),
		button: __( 'Button', 'addsynapse-store-assistant' ),
		image: __( 'Image', 'addsynapse-store-assistant' ),
		unsupported: __( 'Not supported', 'addsynapse-store-assistant' ),
		new: __( 'New', 'addsynapse-store-assistant' ),
	} )[ type ] || type;

const builderLabel = ( builder ) =>
	( {
		gutenberg: __( 'Block editor', 'addsynapse-store-assistant' ),
		elementor: 'Elementor',
		classic: __( 'Classic editor', 'addsynapse-store-assistant' ),
		empty: __( 'No content', 'addsynapse-store-assistant' ),
		prestashop: 'PrestaShop',
	} )[ builder ] || builder;

// Objects a PrestaShop change set creates (items.content.created — contract ext.prestashop; never sent by WordPress).
/**
 * A thumbnail from this site (nothing for other hosts).
 *
 * @param {Object} props     Props.
 * @param {string} props.src URL.
 * @param {string} props.alt Alt.
 */
function Thumb( { src, alt = '' } ) {
	const safe = siteLink( src );
	if ( ! safe ) {
		return (
			<span className="aiops-content__noimg">
				{ __( 'no preview', 'addsynapse-store-assistant' ) }
			</span>
		);
	}
	return (
		<img
			className="aiops-content__thumb"
			src={ safe }
			alt={ alt }
			loading="lazy"
		/>
	);
}

/**
 * Page header: title, builder, links.
 *
 * @param {Object} props      Props.
 * @param {Object} props.page Page summary.
 */
function PageHead( { page } ) {
	const view = siteLink( page.url );
	const edit = adminLink( page.edit_url );
	return (
		<div className="aiops-content__head">
			<strong>{ page.title || `#${ page.id }` }</strong>
			<span className="aiops-r__sub">#{ page.id }</span>
			{ page.builder && (
				<span className="aiops-badge">
					{ builderLabel( page.builder ) }
				</span>
			) }
			{ page.front_page && (
				<span className="aiops-badge">
					{ __( 'Homepage', 'addsynapse-store-assistant' ) }
				</span>
			) }
			{ page.shop_page && (
				<span className="aiops-badge">
					{ __( 'Shop page', 'addsynapse-store-assistant' ) }
				</span>
			) }
			{ view && (
				<a href={ view } target="_blank" rel="noopener noreferrer">
					{ __( 'Open page', 'addsynapse-store-assistant' ) }
				</a>
			) }
			{ edit && (
				<a href={ edit } target="_blank" rel="noopener noreferrer">
					{ __( 'Edit', 'addsynapse-store-assistant' ) }
				</a>
			) }
		</div>
	);
}

/**
 * Short value of a node.
 *
 * @param {Object} props      Props.
 * @param {Object} props.node Node.
 * @return {Object} Element.
 */
function NodeValue( { node } ) {
	const v = node.value;
	if ( node.type === 'image' && v ) {
		return (
			<span className="aiops-content__img">
				<Thumb src={ v.thumb || v.url } alt="" />
				{ v.alt ? (
					<span>{ v.alt }</span>
				) : (
					<span className="aiops-r__meta">
						{ __( '(no alt text)', 'addsynapse-store-assistant' ) }
					</span>
				) }
			</span>
		);
	}
	if ( node.type === 'button' && v ) {
		return (
			<span>
				{ v.text }{ ' ' }
				{ v.url && (
					<code className="aiops-content__url">{ v.url }</code>
				) }
			</span>
		);
	}
	return (
		<span>{ node.text || plain( typeof v === 'string' ? v : '' ) }</span>
	);
}

/**
 * describe-page result.
 *
 * @param {Object} props      Props.
 * @param {Object} props.data Tool result.
 */
export function PageView( { data } ) {
	if ( data.ambiguous ) {
		return (
			<div className="aiops-result aiops-content">
				<p className="aiops-r__meta">
					{ __(
						'Several pages match — which one?',
						'addsynapse-store-assistant'
					) }
				</p>
				<ul className="aiops-content__candidates">
					{ ( data.candidates || [] ).map( ( c ) => (
						<li key={ c.id }>
							<PageHead page={ c } />
						</li>
					) ) }
				</ul>
			</div>
		);
	}
	const page = {
		...( data.page || {} ),
		builder: data.builder || ( data.page || {} ).builder,
	};
	if ( data.needs_human ) {
		return (
			<div className="aiops-result aiops-content">
				<PageHead page={ page } />
				<div className="aiops-r__warning">
					{ __(
						'This page cannot be edited from the chat (unsupported page builder or content). Edit it in WordPress or ask your developer — it was added to Settings → Pages that need a human.',
						'addsynapse-store-assistant'
					) }
				</div>
			</div>
		);
	}
	const nodes = data.nodes || [];
	const counts = data.counts || {};
	return (
		<div className="aiops-result aiops-content">
			<PageHead page={ page } />
			<p className="aiops-r__meta">
				{ sprintf(
					/* translators: 1: editable elements, 2: all elements */
					__(
						'%1$s editable of %2$s elements',
						'addsynapse-store-assistant'
					),
					number( counts.editable || 0 ),
					number( counts.nodes || nodes.length )
				) }
			</p>
			<div className="aiops-table-wrap">
				<table className="aiops-table aiops-content__nodes">
					<thead>
						<tr>
							<th>
								{ __(
									'Element',
									'addsynapse-store-assistant'
								) }
							</th>
							<th>
								{ __(
									'Content',
									'addsynapse-store-assistant'
								) }
							</th>
							<th>
								{ __( 'Id', 'addsynapse-store-assistant' ) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ nodes.map( ( node ) => (
							<tr
								key={ node.id }
								className={
									node.editable && node.editable.length
										? ''
										: 'is-readonly'
								}
							>
								<td>
									<span
										className={ `aiops-badge aiops-content__type is-${ node.type }` }
									>
										{ nodeTypeLabel( node.type ) }
									</span>
									<div className="aiops-r__sub">
										{ node.label }
									</div>
									{ node.path && (
										<div className="aiops-r__meta">
											{ node.path }
										</div>
									) }
								</td>
								<td>
									<NodeValue node={ node } />
									{ ! (
										node.editable && node.editable.length
									) && (
										<div className="aiops-r__meta">
											{ __(
												'Not editable from the chat — edit it in the page builder.',
												'addsynapse-store-assistant'
											) }
										</div>
									) }
								</td>
								<td>
									<code>{ node.id }</code>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
			{ data.truncated && (
				<p className="aiops-r__meta">
					{ __(
						'Only the first 150 elements are shown.',
						'addsynapse-store-assistant'
					) }
				</p>
			) }
			{ ( data.sections || [] ).length > 0 && (
				<details className="aiops-content__sections">
					<summary>
						{ sprintf(
							/* translators: %s: number of sections */
							__(
								'Sections (%s) — where a new banner can go',
								'addsynapse-store-assistant'
							),
							number( data.sections.length )
						) }
					</summary>
					<ul>
						{ data.sections.map( ( sec ) => (
							<li
								key={ sec.id }
								style={ {
									marginLeft: `${ sec.depth * 16 }px`,
								} }
							>
								<strong>{ sec.title || sec.label }</strong>{ ' ' }
								{ sec.excerpt && (
									<span>«{ sec.excerpt }»</span>
								) }{ ' ' }
								<span className="aiops-r__meta">
									{ ( sec.contains || [] ).join( ', ' ) }
								</span>{ ' ' }
								<code className="aiops-r__meta">
									{ sec.id }
								</code>
							</li>
						) ) }
					</ul>
				</details>
			) }
		</div>
	);
}

/**
 * find-media result: image candidates with their ids.
 *
 * @param {Object} props      Props.
 * @param {Object} props.data Tool result.
 */
export function MediaView( { data } ) {
	const images = data.images || [];
	return (
		<div className="aiops-result aiops-content">
			<p className="aiops-r__meta">
				{ sprintf(
					/* translators: %s: number of images */
					__(
						'%s images of the media library',
						'addsynapse-store-assistant'
					),
					number( images.length )
				) }
				{ data.missing_files > 0 &&
					' · ' +
						sprintf(
							/* translators: %s: number of images */
							__(
								'%s left out (file missing on the server)',
								'addsynapse-store-assistant'
							),
							number( data.missing_files )
						) }
			</p>
			<ul className="aiops-media">
				{ images.map( ( img ) => (
					<li key={ img.id } className="aiops-media__item">
						<Thumb src={ img.thumb || img.url } alt={ img.alt } />
						<span className="aiops-media__id">#{ img.id }</span>
						<span className="aiops-r__meta">
							{ img.product_name || img.title || img.filename }
						</span>
					</li>
				) ) }
			</ul>
		</div>
	);
}
