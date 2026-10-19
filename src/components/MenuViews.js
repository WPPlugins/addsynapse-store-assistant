/**
 * Menu views (read-only): the result of describe-menus (locations + menu trees).
 *
 * Menu titles/URLs are store DATA rendered as React text nodes (never HTML, never links).
 */
import { __, sprintf } from '@wordpress/i18n';
import { number } from '../lib/format';

const stateLabel = ( state ) =>
	( {
		new: __( 'new', 'addsynapse-store-assistant' ),
		renamed: __( 'renamed', 'addsynapse-store-assistant' ),
		moved: __( 'moved', 'addsynapse-store-assistant' ),
		removed: __( 'removed', 'addsynapse-store-assistant' ),
	} )[ state ] || state;

const typeLabel = ( node ) => {
	if ( node.type === 'custom' ) {
		return __( 'Link', 'addsynapse-store-assistant' );
	}
	if ( node.type === 'block' ) {
		return __( 'Block', 'addsynapse-store-assistant' );
	}
	return (
		{
			product_cat: __( 'Category', 'addsynapse-store-assistant' ),
			product: __( 'Product', 'addsynapse-store-assistant' ),
			page: __( 'Page', 'addsynapse-store-assistant' ),
			post: __( 'Post', 'addsynapse-store-assistant' ),
		}[ node.object ] || node.object
	);
};

/**
 * One tree (flat list with depth) as an indented list.
 *
 * @param {Object}  props       Props.
 * @param {Array}   props.nodes Nodes.
 * @param {string}  props.empty Text when empty.
 * @param {boolean} props.after After-side (shows old titles of renamed nodes).
 */
function Tree( { nodes, empty, after = false } ) {
	if ( ! nodes || ! nodes.length ) {
		return <div className="aiops-menu-tree__empty">{ empty }</div>;
	}
	return (
		<ul className="aiops-menu-tree">
			{ nodes.map( ( node ) => (
				<li
					key={ node.id }
					className={ `aiops-menu-node ${ ( node.states || [] )
						.map( ( s ) => `is-${ s }` )
						.join( ' ' ) }` }
					style={ {
						paddingInlineStart: `${
							8 + Math.min( node.depth || 0, 6 ) * 18
						}px`,
					} }
				>
					{ node.depth > 0 && (
						<span
							className="aiops-menu-node__branch"
							aria-hidden="true"
						>
							└
						</span>
					) }
					<span className="aiops-menu-node__title">
						{ node.title || '—' }
					</span>
					<span className="aiops-menu-node__type">
						{ typeLabel( node ) }
					</span>
					{ node.url && (
						<span className="aiops-menu-node__url">
							{ node.url }
						</span>
					) }
					{ after && node.title_before && (
						<span className="aiops-menu-node__was">
							{ sprintf(
								/* translators: %s: previous title */ __(
									'was “%s”',
									'addsynapse-store-assistant'
								),
								node.title_before
							) }
						</span>
					) }
					{ ( node.states || [] ).map( ( s ) => (
						<span
							key={ s }
							className={ `aiops-badge aiops-menu-badge is-${ s }` }
						>
							{ stateLabel( s ) }
						</span>
					) ) }
				</li>
			) ) }
		</ul>
	);
}

/**
 * Result of describe-menus.
 *
 * @param {Object} props      Props.
 * @param {Object} props.data Tool result.
 */
export function MenusView( { data } ) {
	const menus = data.menus || [];
	const locations = data.locations || [];
	return (
		<div className="aiops-r aiops-menus">
			<div className="aiops-r__meta">
				{ sprintf(
					/* translators: 1: theme name, 2: classic menus | block navigation */
					__( 'Theme %1$s · %2$s', 'addsynapse-store-assistant' ),
					data.theme ? data.theme.name : '',
					data.theme && data.theme.adapter === 'block'
						? __( 'block navigation', 'addsynapse-store-assistant' )
						: __( 'classic menus', 'addsynapse-store-assistant' )
				) }
			</div>
			{ locations.length > 0 && (
				<div className="aiops-table-wrap">
					<table className="aiops-table">
						<thead>
							<tr>
								<th>
									{ __(
										'Location',
										'addsynapse-store-assistant'
									) }
								</th>
								<th>
									{ __(
										'Menu',
										'addsynapse-store-assistant'
									) }
								</th>
							</tr>
						</thead>
						<tbody>
							{ locations.map( ( l ) => (
								<tr key={ l.slug }>
									<td
										data-label={ __(
											'Location',
											'addsynapse-store-assistant'
										) }
									>
										{ l.label }{ ' ' }
										<code className="aiops-code">
											{ l.slug }
										</code>
									</td>
									<td
										data-label={ __(
											'Menu',
											'addsynapse-store-assistant'
										) }
									>
										{ l.menu_name || '—' }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }
			{ menus.length === 0 && (
				<div className="aiops-r__empty">
					{ __(
						'There are no menus yet.',
						'addsynapse-store-assistant'
					) }
				</div>
			) }
			{ menus.map( ( m ) => (
				<details
					className="aiops-sec"
					key={ m.id }
					open={ menus.length === 1 }
				>
					<summary>
						<span className="aiops-sec__title">
							{ m.name }{ ' ' }
							<span className="aiops-r__meta">#{ m.id }</span>
						</span>
						<span className="aiops-sec__count">
							{ number( m.count ) }
						</span>
					</summary>
					<div className="aiops-sec__body">
						<Tree
							nodes={ m.items }
							empty={ __(
								'No items.',
								'addsynapse-store-assistant'
							) }
						/>
					</div>
				</details>
			) ) }
		</div>
	);
}
