/**
 * The bar above the composer: «☑ N επιλεγμένα προϊόντα · Εμφάνιση · Καθαρισμός».
 *
 * «Εμφάνιση» scrolls to (and highlights) the table the products were ticked in; when they come
 * from several tables, or the table is not on the page, it opens a compact list instead.
 * Store data is rendered as text nodes only.
 */
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Modal } from '@wordpress/components';
import { money, number, skuLabel, stockLabel } from '../lib/format';

/**
 * «N selected products» without _n() (the el .po script has no plurals).
 *
 * @param {number} count Count.
 * @return {string} Text.
 */
export function selectedText( count ) {
	return count === 1
		? __( '1 selected product', 'addsynapse-store-assistant' )
		: sprintf(
				/* translators: %s: number of products */ __(
					'%s selected products',
					'addsynapse-store-assistant'
				),
				number( count )
		  );
}

/**
 * Scrolls to a result table and flashes it.
 *
 * @param {string} source Tool call id.
 * @return {boolean} Whether the table was found.
 */
export function revealSource( source ) {
	const el = source
		? document.getElementById( `addsynapse-assistant-src-${ source }` )
		: null;
	if ( ! el ) {
		return false;
	}
	el.scrollIntoView( { behavior: 'smooth', block: 'center' } );
	el.classList.remove( 'is-flash' );
	// Restart the animation.
	void el.offsetWidth; // eslint-disable-line no-void
	el.classList.add( 'is-flash' );
	window.setTimeout( () => el.classList.remove( 'is-flash' ), 1800 );
	return true;
}

export default function SelectionBar( {
	selection,
	onToggle,
	onClear,
	disabled,
} ) {
	const [ open, setOpen ] = useState( false );
	const count = selection.ids.length;
	if ( ! count ) {
		return null;
	}

	const show = () => {
		const sources = [
			...new Set(
				selection.ids.map( ( id ) => selection.sources[ id ] || '' )
			),
		];
		if ( sources.length === 1 && revealSource( sources[ 0 ] ) ) {
			return;
		}
		setOpen( true );
	};

	return (
		<div className="aiops-selbar" role="status" aria-live="polite">
			<span className="aiops-selbar__count">
				<span aria-hidden="true">☑</span> { selectedText( count ) }
			</span>
			<span aria-hidden="true">·</span>
			<Button variant="link" onClick={ show }>
				{ __( 'Show', 'addsynapse-store-assistant' ) }
			</Button>
			<span aria-hidden="true">·</span>
			<Button variant="link" onClick={ onClear } disabled={ disabled }>
				{ __( 'Clear', 'addsynapse-store-assistant' ) }
			</Button>
			<span className="aiops-selbar__hint">
				{ __(
					'Sent with your next message.',
					'addsynapse-store-assistant'
				) }
			</span>

			{ open && (
				<Modal
					title={ selectedText( count ) }
					onRequestClose={ () => setOpen( false ) }
					className="aiops-selmodal"
				>
					<div className="aiops-table-wrap">
						<table className="aiops-table aiops-table--compact">
							<thead>
								<tr>
									<th className="aiops-check">
										<span className="screen-reader-text">
											{ __(
												'Selected',
												'addsynapse-store-assistant'
											) }
										</span>
									</th>
									<th>
										{ __(
											'Product',
											'addsynapse-store-assistant'
										) }
									</th>
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
								{ selection.ids.map( ( id ) => {
									const p = selection.items[ id ] || { id };
									return (
										<tr key={ id }>
											<td className="aiops-check">
												<input
													type="checkbox"
													checked
													disabled={ disabled }
													aria-label={ sprintf(
														/* translators: %s: product name */ __(
															'Unselect %s',
															'addsynapse-store-assistant'
														),
														p.name || `#${ id }`
													) }
													onChange={ () =>
														onToggle( [ p ], false )
													}
												/>
											</td>
											<td
												data-label={ __(
													'Product',
													'addsynapse-store-assistant'
												) }
											>
												{ p.name || `#${ id }` }
												<span className="aiops-r__sub">
													#{ id }
												</span>
											</td>
											<td data-label={ skuLabel() }>
												{ p.sku || '—' }
											</td>
											<td
												data-label={ __(
													'Price',
													'addsynapse-store-assistant'
												) }
											>
												{ p.price
													? money(
															p.price,
															p.currency
													  )
													: '—' }
											</td>
											<td
												data-label={ __(
													'Stock',
													'addsynapse-store-assistant'
												) }
											>
												{ stockLabel( p.stock_status ) }
												{ p.stock_quantity !== null &&
													p.stock_quantity !==
														undefined &&
													` · ${ number(
														p.stock_quantity
													) }` }
											</td>
										</tr>
									);
								} ) }
							</tbody>
						</table>
					</div>
					<div className="aiops-modal-actions">
						<Button
							variant="tertiary"
							isDestructive
							disabled={ disabled }
							onClick={ () => {
								onClear();
								setOpen( false );
							} }
						>
							{ __( 'Clear', 'addsynapse-store-assistant' ) }
						</Button>
						<Button
							variant="primary"
							onClick={ () => setOpen( false ) }
						>
							{ __( 'Close', 'addsynapse-store-assistant' ) }
						</Button>
					</div>
				</Modal>
			) }
		</div>
	);
}
