/**
 * Message composer: auto-growing textarea, Enter to send, Shift+Enter newline, and an
 * attachment — an image (paperclip, paste, or drag & drop onto the chat) — shown as a chip with upload
 * progress and a thumbnail — and voice input (browser speech recognition, opt-in).
 */
import {
	useEffect,
	useRef,
	useState,
	forwardRef,
	useImperativeHandle,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { Icon, arrowUp, closeSmall } from '@wordpress/icons';
import {
	useSpeech,
	loadSpeechLang,
	saveSpeechLang,
	speechLangsFor,
	speechLangCode,
	speechLangName,
	joinSpoken,
} from '../lib/speech';

const MAX_CHARS = 8000;

function StopIcon() {
	return (
		<svg
			width="14"
			height="14"
			viewBox="0 0 14 14"
			aria-hidden="true"
			focusable="false"
		>
			<rect
				x="1"
				y="1"
				width="12"
				height="12"
				rx="2"
				fill="currentColor"
			/>
		</svg>
	);
}

function ClipIcon() {
	return (
		<svg
			width="18"
			height="18"
			viewBox="0 0 24 24"
			aria-hidden="true"
			focusable="false"
		>
			<path
				d="M16.5 6.5v10a4.5 4.5 0 0 1-9 0V5a3 3 0 0 1 6 0v10.5a1.5 1.5 0 0 1-3 0V6.5"
				fill="none"
				stroke="currentColor"
				strokeWidth="1.6"
				strokeLinecap="round"
			/>
		</svg>
	);
}

function MicIcon() {
	return (
		<svg
			width="18"
			height="18"
			viewBox="0 0 24 24"
			aria-hidden="true"
			focusable="false"
		>
			<rect
				x="9"
				y="3"
				width="6"
				height="11"
				rx="3"
				fill="none"
				stroke="currentColor"
				strokeWidth="1.6"
			/>
			<path
				d="M5.5 11a6.5 6.5 0 0 0 13 0M12 17.5V21"
				fill="none"
				stroke="currentColor"
				strokeWidth="1.6"
				strokeLinecap="round"
			/>
		</svg>
	);
}

/**
 * A friendly message for a speech recognition error code.
 *
 * @param {string} code Error code.
 * @return {string} Message.
 */
function speechError( code ) {
	switch ( code ) {
		case 'not-allowed':
		case 'service-not-allowed':
			return __(
				'Microphone access is blocked. Click the icon at the left of the address bar, allow the microphone for this site and try again.',
				'addsynapse-store-assistant'
			);
		case 'audio-capture':
			return __(
				'No microphone was found. Connect one and try again.',
				'addsynapse-store-assistant'
			);
		case 'no-speech':
			return __(
				'No speech was heard. Try again.',
				'addsynapse-store-assistant'
			);
		case 'network':
			return __(
				'Voice recognition is unavailable (network). Try again.',
				'addsynapse-store-assistant'
			);
		case 'language-not-supported':
			return __(
				'Your browser does not support voice input in this language.',
				'addsynapse-store-assistant'
			);
		default:
			return __(
				'Voice input failed. Try again.',
				'addsynapse-store-assistant'
			);
	}
}

/**
 * The attached image: uploading (progress) → ready or error.
 *
 * @param {Object}   props            Props.
 * @param {Object}   props.attachment { status: uploading|ready|error, name, progress, image, preview, error }.
 * @param {Function} props.onRemove   Remove.
 */
function AttachmentChip( { attachment, onRemove } ) {
	const { status, name, progress, image, error, preview } = attachment;
	const thumb = ( image && image.thumb ) || preview || '';
	return (
		<div
			className={ `aiops-attach is-${ status } is-image` }
			role="status"
			aria-live="polite"
		>
			{ thumb ? (
				<img
					className="aiops-attach__thumb"
					src={ thumb }
					alt=""
					width="40"
					height="40"
				/>
			) : (
				<span className="aiops-attach__icon" aria-hidden="true">
					IMG
				</span>
			) }
			<span className="aiops-attach__body">
				<span className="aiops-attach__name">{ name }</span>
				{ status === 'uploading' && (
					<span
						className="aiops-attach__bar"
						aria-label={ __(
							'Uploading…',
							'addsynapse-store-assistant'
						) }
					>
						<span style={ { width: `${ progress || 0 }%` } } />
					</span>
				) }
				{ status === 'ready' && image && (
					<span className="aiops-attach__meta">
						{ `${ image.width }×${ image.height } px` }
						{ ' · ' }
						{ __(
							'sent with your next message (e.g. «set it as the main image of SKU…»)',
							'addsynapse-store-assistant'
						) }
					</span>
				) }
				{ status === 'error' && (
					<span className="aiops-attach__error">{ error }</span>
				) }
			</span>
			<Button
				className="aiops-attach__remove"
				size="small"
				icon={ <Icon icon={ closeSmall } /> }
				label={ __( 'Remove the image', 'addsynapse-store-assistant' ) }
				disabled={ status === 'uploading' }
				onClick={ onRemove }
			/>
		</div>
	);
}

const Composer = forwardRef( function Composer(
	{
		value,
		onChange,
		onSend,
		onStop,
		busy,
		streaming,
		disabled,
		attachment = null,
		onAttach = null,
		onRemoveAttachment,
		// Drag & drop is handled by the chat pane; a pasted image comes here (same checks as the paperclip).
		onPasteFiles = null,
		// Voice input is opt-in (Settings → Voice input, default OFF): the browser sends the audio to its own
		// speech service. Hosts that do not pass the prop (the portal) keep their own setting.
		voiceOptIn = true,
		locale = '',
		userId = 0,
	},
	ref
) {
	const area = useRef();
	const fileInput = useRef();
	const [ speechLang, setSpeechLang ] = useState( () =>
		loadSpeechLang( userId, locale )
	);
	const [ voiceError, setVoiceError ] = useState( '' );
	const [ announce, setAnnounce ] = useState( '' );
	// Text around the cursor when listening started; the recognised text goes between.
	const around = useRef( null );
	const caret = useRef( null );

	const speech = useSpeech( {
		lang: speechLang,
		onText: ( spoken ) => {
			const at = around.current;
			if ( ! at ) {
				return;
			}
			onChange( joinSpoken( at.before, spoken, at.after ) );
			caret.current = joinSpoken( at.before, spoken, '' ).length;
		},
		onError: ( code ) => setVoiceError( speechError( code ) ),
		onEnd: () => {
			around.current = null;
			setAnnounce(
				__( 'Voice input stopped.', 'addsynapse-store-assistant' )
			);
			const el = area.current;
			const pos = caret.current;
			caret.current = null;
			if ( el ) {
				window.requestAnimationFrame( () => {
					el.focus();
					if ( pos !== null ) {
						el.setSelectionRange( pos, pos );
					}
				} );
			}
		},
	} );
	const { listening } = speech;
	const voiceOn = speech.supported;

	const startVoice = () => {
		const el = area.current;
		const from = el ? el.selectionStart : value.length;
		const to = el ? el.selectionEnd : value.length;
		around.current = {
			before: value.slice( 0, from ),
			after: value.slice( to ),
		};
		setVoiceError( '' );
		setAnnounce(
			__( 'Listening… speak now.', 'addsynapse-store-assistant' )
		);
		speech.start();
	};
	const toggleVoice = () => {
		if ( listening ) {
			speech.stop();
		} else if ( voiceOn && ! busy && ! disabled ) {
			startVoice();
		}
	};
	const switchLang = () => {
		const langs = speechLangsFor( locale );
		const next =
			langs[ ( langs.indexOf( speechLang ) + 1 ) % langs.length ];
		setSpeechLang( next );
		saveSpeechLang( userId, next );
	};

	// Esc stops listening wherever the focus is.
	useEffect( () => {
		if ( ! listening ) {
			return;
		}
		const onEsc = ( e ) => {
			if ( e.key === 'Escape' ) {
				e.preventDefault();
				speech.stop();
			}
		};
		document.addEventListener( 'keydown', onEsc );
		return () => document.removeEventListener( 'keydown', onEsc );
	}, [ listening, speech ] );

	useImperativeHandle( ref, () => ( {
		focus: () => area.current && area.current.focus(),
	} ) );

	useEffect( () => {
		const el = area.current;
		if ( ! el ) {
			return;
		}
		el.style.height = 'auto';
		el.style.height = Math.min( el.scrollHeight, 220 ) + 'px';
	}, [ value ] );

	const uploading = !! attachment && attachment.status === 'uploading';
	const canSend =
		! listening &&
		! busy &&
		! disabled &&
		! uploading &&
		value.trim().length > 0 &&
		value.length <= MAX_CHARS;

	const onKeyDown = ( event ) => {
		if (
			voiceOn &&
			event.ctrlKey &&
			event.shiftKey &&
			( event.key === 'M' || event.key === 'm' )
		) {
			event.preventDefault();
			toggleVoice();
			return;
		}
		if ( listening && event.key === 'Enter' ) {
			// Never send while listening: Enter just stops, the user reviews first.
			event.preventDefault();
			speech.stop();
			return;
		}
		if (
			event.key === 'Enter' &&
			! event.shiftKey &&
			! event.nativeEvent.isComposing
		) {
			event.preventDefault();
			if ( canSend ) {
				onSend();
			}
		}
	};

	const pick = ( files ) => {
		const file = files && files[ 0 ];
		if ( file && onAttach ) {
			onAttach( file );
		}
	};

	// Ctrl+V of an image (a screenshot, a copied picture) attaches it; a text paste stays a text paste.
	const onPaste = ( event ) => {
		if ( ! onPasteFiles || busy || uploading || disabled ) {
			return;
		}
		const files = Array.from(
			( event.clipboardData && event.clipboardData.files ) || []
		).filter( ( f ) => /^image\//.test( f.type || '' ) );
		if ( files.length ) {
			event.preventDefault();
			onPasteFiles( files );
		}
	};

	let hint = __(
		'Enter to send · Shift+Enter for a new line · /help for commands',
		'addsynapse-store-assistant'
	);
	if ( voiceError ) {
		hint = (
			<span className="is-error" role="alert">
				{ voiceError }
			</span>
		);
	} else if ( listening ) {
		hint = (
			<span className="is-listening">
				{ __(
					'Listening… Esc or the microphone to stop · the text is not sent until you press Send',
					'addsynapse-store-assistant'
				) }
			</span>
		);
	} else if ( value.length > MAX_CHARS ) {
		hint = (
			<span className="is-error">
				{ __(
					'The message is too long.',
					'addsynapse-store-assistant'
				) }
			</span>
		);
	}

	return (
		<div className="aiops-composer">
			{ attachment && (
				<AttachmentChip
					attachment={ attachment }
					onRemove={ onRemoveAttachment }
				/>
			) }
			<div className={ `aiops-composer__box${ busy ? ' is-busy' : '' }` }>
				{ onAttach && (
					<>
						<input
							ref={ fileInput }
							type="file"
							accept="image/jpeg,image/png,image/webp"
							className="screen-reader-text"
							tabIndex={ -1 }
							aria-hidden="true"
							onChange={ ( e ) => {
								pick( e.target.files );
								e.target.value = '';
							} }
						/>
						<Button
							className="aiops-composer__attach"
							label={ __(
								'Attach an image (JPG, PNG, WebP)',
								'addsynapse-store-assistant'
							) }
							showTooltip
							disabled={ busy || uploading || disabled }
							onClick={ () =>
								fileInput.current && fileInput.current.click()
							}
							icon={ <ClipIcon /> }
						/>
					</>
				) }
				{ speech.supported && voiceOptIn && (
					<Button
						className={ `aiops-composer__attach aiops-composer__mic${
							listening ? ' is-listening' : ''
						}` }
						label={
							listening
								? __(
										'Stop voice input (Esc)',
										'addsynapse-store-assistant'
								  )
								: __(
										'Voice input (Ctrl+Shift+M). Speech recognition is done by your browser (e.g. Google in Chrome); nothing is sent until you press Send.',
										'addsynapse-store-assistant'
								  )
						}
						showTooltip
						aria-pressed={ listening }
						disabled={ ! listening && ( busy || disabled ) }
						accessibleWhenDisabled
						onClick={ toggleVoice }
						icon={ <MicIcon /> }
					/>
				) }
				{ voiceOn && speechLangsFor( locale ).length > 1 && (
					<Button
						className="aiops-composer__lang"
						size="small"
						label={ sprintf(
							/* translators: 1: current voice input language, 2: the other language, e.g. "Greek", "English" */
							__(
								'Voice input language: %1$s (switch to %2$s)',
								'addsynapse-store-assistant'
							),
							speechLangName( speechLang, locale ),
							speechLangName(
								speechLangsFor( locale ).find(
									( l ) => l !== speechLang
								),
								locale
							)
						) }
						showTooltip
						disabled={ listening }
						onClick={ switchLang }
					>
						{ speechLangCode( speechLang ) }
					</Button>
				) }
				<span className="screen-reader-text" aria-live="polite">
					{ announce }
				</span>
				<label
					className="screen-reader-text"
					htmlFor="addsynapse-assistant-input"
				>
					{ __( 'Message', 'addsynapse-store-assistant' ) }
				</label>
				<textarea
					id="addsynapse-assistant-input"
					ref={ area }
					rows={ 1 }
					value={ value }
					disabled={ busy || disabled }
					readOnly={ listening }
					aria-busy={ listening }
					placeholder={
						// eslint-disable-next-line no-nested-ternary
						listening
							? __(
									'Listening… speak now',
									'addsynapse-store-assistant'
							  )
							: busy
							? __(
									'Waiting for the reply…',
									'addsynapse-store-assistant'
							  )
							: askPlaceholder()
					}
					onChange={ ( e ) => {
						setVoiceError( '' );
						onChange( e.target.value );
					} }
					onKeyDown={ onKeyDown }
					onPaste={ onPaste }
				/>
				{ busy && streaming ? (
					<Button
						className="aiops-composer__btn is-stop"
						label={ __( 'Stop', 'addsynapse-store-assistant' ) }
						showTooltip
						onClick={ onStop }
						icon={ <StopIcon /> }
					/>
				) : (
					<Button
						className="aiops-composer__btn"
						variant="primary"
						label={ __( 'Send', 'addsynapse-store-assistant' ) }
						showTooltip
						disabled={ ! canSend }
						onClick={ onSend }
						icon={ <Icon icon={ arrowUp } /> }
					/>
				) }
			</div>
			<div className="aiops-composer__hint">{ hint }</div>
		</div>
	);
} );

/**
 * Composer placeholder: «store» on WooCommerce shops, «site» on plain WordPress sites.
 *
 * @return {string} Placeholder.
 */
function askPlaceholder() {
	return ( window.addsynapseAssistant || {} ).siteType === 'site'
		? __( 'Ask about your site…', 'addsynapse-store-assistant' )
		: __( 'Ask about your store…', 'addsynapse-store-assistant' );
}

export default Composer;
