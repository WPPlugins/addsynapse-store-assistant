/**
 * Conversation history sidebar.
 */
import { useState, useRef, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Button,
	DropdownMenu,
	MenuGroup,
	MenuItem,
	SearchControl,
	Spinner,
} from '@wordpress/components';
import {
	Icon,
	plus,
	moreVertical,
	pencil,
	trash,
	close,
} from '@wordpress/icons';
import { groupConversations } from '../lib/dates';

function RenameInput( { initial, onSave, onCancel } ) {
	const [ value, setValue ] = useState( initial );
	const input = useRef();
	useEffect( () => {
		if ( input.current ) {
			input.current.focus();
			input.current.select();
		}
	}, [] );
	const commit = () => {
		const v = value.trim();
		if ( v && v !== initial ) {
			onSave( v );
		} else {
			onCancel();
		}
	};
	return (
		<input
			ref={ input }
			className="aiops-conv__rename"
			value={ value }
			maxLength={ 120 }
			aria-label={ __(
				'Conversation title',
				'addsynapse-store-assistant'
			) }
			onChange={ ( e ) => setValue( e.target.value ) }
			onBlur={ commit }
			onKeyDown={ ( e ) => {
				if ( e.key === 'Enter' ) {
					e.preventDefault();
					commit();
				} else if ( e.key === 'Escape' ) {
					e.preventDefault();
					onCancel();
				}
			} }
		/>
	);
}

export default function Sidebar( {
	conversations,
	loading,
	activeId,
	search,
	onSearch,
	onSelect,
	onNew,
	onRename,
	onDelete,
	onClose,
	busy,
} ) {
	const [ renamingId, setRenamingId ] = useState( null );
	const groups = groupConversations( conversations );

	return (
		<aside
			className="aiops-sidebar"
			aria-label={ __( 'Conversations', 'addsynapse-store-assistant' ) }
		>
			<div className="aiops-sidebar__top">
				<Button
					variant="primary"
					className="aiops-sidebar__new"
					icon={ <Icon icon={ plus } /> }
					onClick={ onNew }
					disabled={ busy }
				>
					{ __( 'New chat', 'addsynapse-store-assistant' ) }
				</Button>
				{ onClose && (
					<Button
						className="aiops-sidebar__close"
						icon={ <Icon icon={ close } /> }
						label={ __( 'Close', 'addsynapse-store-assistant' ) }
						onClick={ onClose }
					/>
				) }
			</div>
			<div className="aiops-sidebar__search">
				<SearchControl
					__nextHasNoMarginBottom
					value={ search }
					onChange={ onSearch }
					label={ __(
						'Search conversations',
						'addsynapse-store-assistant'
					) }
					placeholder={ __(
						'Search conversations',
						'addsynapse-store-assistant'
					) }
				/>
			</div>
			<nav className="aiops-sidebar__list">
				{ loading && ! conversations.length && (
					<div className="aiops-sidebar__loading">
						<Spinner />
					</div>
				) }
				{ ! loading && ! conversations.length && (
					<p className="aiops-sidebar__empty">
						{ search
							? __(
									'No conversations match your search.',
									'addsynapse-store-assistant'
							  )
							: __(
									'No conversations yet.',
									'addsynapse-store-assistant'
							  ) }
					</p>
				) }
				{ groups.map( ( group ) => (
					<div className="aiops-group" key={ group.key }>
						<h3 className="aiops-group__label">{ group.label }</h3>
						<ul>
							{ group.items.map( ( c ) => (
								<li
									key={ c.id }
									className={ `aiops-conv${
										c.id === activeId ? ' is-active' : ''
									}` }
								>
									{ renamingId === c.id ? (
										<RenameInput
											initial={ c.title }
											onSave={ ( title ) => {
												setRenamingId( null );
												onRename( c.id, title );
											} }
											onCancel={ () =>
												setRenamingId( null )
											}
										/>
									) : (
										<button
											type="button"
											className="aiops-conv__title"
											onClick={ () => onSelect( c.id ) }
											aria-current={
												c.id === activeId
													? 'page'
													: undefined
											}
											title={ c.title }
										>
											{ c.title ||
												__(
													'New conversation',
													'addsynapse-store-assistant'
												) }
										</button>
									) }
									<DropdownMenu
										className="aiops-conv__menu"
										icon={ moreVertical }
										label={ __(
											'Conversation actions',
											'addsynapse-store-assistant'
										) }
										toggleProps={ { size: 'small' } }
										popoverProps={ {
											placement: 'bottom-end',
										} }
									>
										{ ( { onClose: closeMenu } ) => (
											<MenuGroup>
												<MenuItem
													icon={ pencil }
													onClick={ () => {
														closeMenu();
														setRenamingId( c.id );
													} }
												>
													{ __(
														'Rename',
														'addsynapse-store-assistant'
													) }
												</MenuItem>
												<MenuItem
													icon={ trash }
													isDestructive
													onClick={ () => {
														closeMenu();
														onDelete( c );
													} }
												>
													{ __(
														'Delete',
														'addsynapse-store-assistant'
													) }
												</MenuItem>
											</MenuGroup>
										) }
									</DropdownMenu>
								</li>
							) ) }
						</ul>
					</div>
				) ) }
			</nav>
		</aside>
	);
}
