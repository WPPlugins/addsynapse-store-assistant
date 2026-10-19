/**
 * Optional AddSynapse account in the chat (Store Assistant Pro is not released yet): a small «Get notified» link under the
 * first «coming in Pro» reply of a conversation opens the opt-in dialog. Nothing is locked; nothing is sent before
 * «Create account».
 */
import { createContext, useContext, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Button,
	CheckboxControl,
	Modal,
	Notice,
	TextControl,
} from '@wordpress/components';
import { createAccount } from '../lib/api';

const settings = window.addsynapseAssistant || {};

/** { firstId, account, open } — firstId = the one «coming in Pro» reply of the conversation that carries the link. */
export const ProOfferContext = createContext( {
	firstId: '',
	account: settings.account || {},
	open: () => {},
} );

/**
 * The opt-in dialog (same data and consent as the card in Settings).
 *
 * @param {Object}   props         Props.
 * @param {Object}   props.account Account state.
 * @param {Function} props.onClose Close.
 * @param {Function} props.onDone  New account state.
 * @return {JSX.Element} Modal.
 */
export function OptInDialog( { account, onClose, onDone } ) {
	const [ email, setEmail ] = useState( account.email || '' );
	const [ marketing, setMarketing ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ result, setResult ] = useState( null );

	const submit = ( event ) => {
		event.preventDefault();
		setBusy( true );
		createAccount( { email, marketing } )
			.then( ( answer ) => {
				setResult( answer );
				if ( answer && answer.account ) {
					onDone( answer.account );
				}
			} )
			.catch( () =>
				setResult( {
					status: 'error',
					message: __(
						'The account could not be created. Try again later.',
						'addsynapse-store-assistant'
					),
				} )
			)
			.finally( () => setBusy( false ) );
	};

	const created = result && result.status === 'created';
	return (
		<Modal
			className="aiops-optin-dialog"
			title={ __( 'Get notified', 'addsynapse-store-assistant' ) }
			onRequestClose={ onClose }
			size="medium"
		>
			{ created ? (
				<div className="aiops-optin-dialog__done">
					<p>
						<strong>
							{ __(
								'Your AddSynapse account is ready',
								'addsynapse-store-assistant'
							) }
						</strong>
					</p>
					<p>
						{ __(
							'We will let you know when Store Assistant Pro launches.',
							'addsynapse-store-assistant'
						) }
					</p>
					<p>
						<Button variant="primary" onClick={ onClose }>
							{ __( 'Close', 'addsynapse-store-assistant' ) }
						</Button>
					</p>
				</div>
			) : (
				<form onSubmit={ submit } className="aiops-optin-dialog__form">
					<p className="aiops-optin__lead">
						<strong>
							{ __(
								'Get notified first when Store Assistant Pro launches — with a launch discount. Create a free AddSynapse account.',
								'addsynapse-store-assistant'
							) }
						</strong>
					</p>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						type="email"
						label={ __( 'Email', 'addsynapse-store-assistant' ) }
						value={ email }
						onChange={ setEmail }
						required
					/>
					<CheckboxControl
						__nextHasNoMarginBottom
						label={ __(
							'Send me news and offers by email (optional; you can unsubscribe at any time)',
							'addsynapse-store-assistant'
						) }
						checked={ marketing }
						onChange={ setMarketing }
					/>
					<details className="aiops-optin__privacy">
						<summary>
							{ __(
								'What do we receive?',
								'addsynapse-store-assistant'
							) }
						</summary>
						<p>
							{ __(
								'Your email, the address of your shop and your choice above, only when you click «Create account». Never your AI key.',
								'addsynapse-store-assistant'
							) }{ ' ' }
							<a
								href={ account.termsUrl }
								target="_blank"
								rel="noopener noreferrer"
							>
								{ __( 'Terms', 'addsynapse-store-assistant' ) }
							</a>
							{ ' · ' }
							<a
								href={ account.privacyUrl }
								target="_blank"
								rel="noopener noreferrer"
							>
								{ __(
									'Privacy',
									'addsynapse-store-assistant'
								) }
							</a>
						</p>
					</details>
					{ result && result.message && (
						<Notice status="warning" isDismissible={ false }>
							{ result.message }{ ' ' }
							{ result.link && (
								<a
									href={ result.link }
									target="_blank"
									rel="noopener noreferrer"
								>
									{ __(
										'Sign in',
										'addsynapse-store-assistant'
									) }
								</a>
							) }
						</Notice>
					) }
					<p className="aiops-optin__actions">
						<Button
							variant="primary"
							type="submit"
							isBusy={ busy }
							disabled={ busy }
						>
							{ __(
								'Create account',
								'addsynapse-store-assistant'
							) }
						</Button>{ ' ' }
						<Button variant="tertiary" onClick={ onClose }>
							{ __( 'Not now', 'addsynapse-store-assistant' ) }
						</Button>
					</p>
				</form>
			) }
		</Modal>
	);
}

/**
 * «Get notified» under the FIRST «coming in Pro» reply of a conversation (administrators without an account only).
 *
 * @param {Object} props      Props.
 * @param {Object} props.part Tool part.
 * @return {JSX.Element|null} Link.
 */
export function ProOfferCta( { part } ) {
	const { firstId, account, open } = useContext( ProOfferContext );
	if (
		! part ||
		! part.id ||
		part.id !== firstId ||
		! account ||
		! account.canCreate
	) {
		return null;
	}
	return (
		<Button variant="link" className="aiops-pro-offer" onClick={ open }>
			{ __( 'Get notified', 'addsynapse-store-assistant' ) }
		</Button>
	);
}
