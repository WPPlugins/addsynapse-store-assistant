/**
 * «How should the assistant answer?» — shown while no AI key works: the manager's own Anthropic or OpenAI key. The key
 * goes to this site's server only (REST, administrators); it is never sent back, stored in the browser or sent to
 * AddSynapse.
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Button,
	Notice,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { saveAiKey } from '../lib/api';

const KEY_PAGES = {
	anthropic: 'https://console.anthropic.com/settings/keys',
	openai: 'https://platform.openai.com/api-keys',
};

/**
 * @param {Object}   props
 * @param {Object}   props.status  /status payload.
 * @param {Function} props.onSaved Called with the new key state after a passing test.
 * @return {JSX.Element} Card.
 */
export default function AiModePicker( { status, onSaved } ) {
	const state = ( status && status.ai_key ) || {};
	const [ provider, setProvider ] = useState( state.provider || 'anthropic' );
	const [ key, setKey ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( '' );

	const submit = async ( event ) => {
		event.preventDefault();
		setSaving( true );
		setError( '' );
		try {
			const result = await saveAiKey( { provider, model: '', key } );
			setKey( '' );
			onSaved( result );
		} catch ( e ) {
			setError(
				( e && e.message ) ||
					__(
						'The key could not be tested.',
						'addsynapse-store-assistant'
					)
			);
		} finally {
			setSaving( false );
		}
	};

	return (
		<div className="aiops-mode-picker">
			<h2 className="aiops-mode-picker__title">
				{ __(
					'How should the assistant answer?',
					'addsynapse-store-assistant'
				) }
			</h2>
			<div className="aiops-mode-picker__options">
				<section className="aiops-mode-picker__option">
					<h3>
						{ __(
							'Use your own AI key',
							'addsynapse-store-assistant'
						) }
					</h3>
					<p>
						{ __(
							'Paste an Anthropic or OpenAI API key. You pay your AI provider directly for what you use; the plugin charges nothing.',
							'addsynapse-store-assistant'
						) }
					</p>
					{ state.can_manage === false ? (
						<p className="description">
							{ __(
								'Only an administrator can add the AI key.',
								'addsynapse-store-assistant'
							) }
						</p>
					) : (
						<form onSubmit={ submit }>
							<SelectControl
								label={ __(
									'AI provider',
									'addsynapse-store-assistant'
								) }
								value={ provider }
								options={ [
									{
										label: 'Anthropic (Claude)',
										value: 'anthropic',
									},
									{
										label: 'OpenAI (GPT)',
										value: 'openai',
									},
								] }
								onChange={ setProvider }
								__nextHasNoMarginBottom
							/>
							<TextControl
								label={ __(
									'API key',
									'addsynapse-store-assistant'
								) }
								type="password"
								autoComplete="new-password"
								value={ key }
								onChange={ setKey }
								help={
									<>
										{ __(
											'Stored encrypted on this site and used only by its server.',
											'addsynapse-store-assistant'
										) }{ ' ' }
										<a
											href={ KEY_PAGES[ provider ] }
											target="_blank"
											rel="noopener noreferrer"
										>
											{ __(
												'Get a key',
												'addsynapse-store-assistant'
											) }
										</a>
									</>
								}
								__nextHasNoMarginBottom
							/>
							<p className="description">
								{ __(
									'Your messages and the store data the assistant reads for them (never customer names, emails or phone numbers) go from this server to the provider you choose. Nothing is sent before you press the button, and nothing goes to AddSynapse.',
									'addsynapse-store-assistant'
								) }
							</p>
							{ error && (
								<Notice status="error" isDismissible={ false }>
									{ error }
								</Notice>
							) }
							<Button
								variant="primary"
								type="submit"
								isBusy={ saving }
								disabled={ saving || ! key.trim() }
							>
								{ __(
									'Save and test',
									'addsynapse-store-assistant'
								) }
							</Button>
						</form>
					) }
				</section>
			</div>
		</div>
	);
}
