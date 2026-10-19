/**
 * One display turn (user bubble or assistant block).
 */
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { Icon, copy, check } from '@wordpress/icons';
import Markdown from './Markdown';
import ToolChip from './ToolChip';
import ResultView from './ResultView';
import { formatTime, formatFull } from '../lib/dates';
import { turnText } from '../lib/turns';

async function copyText( text ) {
	try {
		await window.navigator.clipboard.writeText( text );
		return true;
	} catch ( e ) {
		const area = document.createElement( 'textarea' );
		area.value = text;
		area.setAttribute( 'readonly', '' );
		area.style.position = 'fixed';
		area.style.opacity = '0';
		document.body.appendChild( area );
		area.select();
		let ok = false;
		try {
			ok = document.execCommand( 'copy' );
		} catch ( err ) {
			ok = false;
		}
		document.body.removeChild( area );
		return ok;
	}
}

function CopyButton( { text } ) {
	const [ copied, setCopied ] = useState( false );
	if ( ! text ) {
		return null;
	}
	return (
		<Button
			className="aiops-msg__action"
			size="small"
			label={
				copied
					? __( 'Copied', 'addsynapse-store-assistant' )
					: __( 'Copy message', 'addsynapse-store-assistant' )
			}
			showTooltip
			icon={ <Icon icon={ copied ? check : copy } size={ 18 } /> }
			onClick={ async () => {
				if ( await copyText( text ) ) {
					setCopied( true );
					setTimeout( () => setCopied( false ), 1500 );
				}
			} }
		/>
	);
}

const STATUS_NOTES = {
	stopped: () => __( 'Stopped by you.', 'addsynapse-store-assistant' ),
	truncated: () =>
		__(
			'The answer was cut off because it was too long.',
			'addsynapse-store-assistant'
		),
	refusal: () =>
		__(
			'The assistant declined to answer this request.',
			'addsynapse-store-assistant'
		),
	error: () =>
		__(
			'This answer could not be completed.',
			'addsynapse-store-assistant'
		),
};

export default function MessageTurn( { turn, live = false, phase = '' } ) {
	if ( turn.role === 'user' ) {
		return (
			<div
				className={ `aiops-msg aiops-msg--user${
					turn.pending ? ' is-pending' : ''
				}${ /^\/[a-z_]+/i.test( turn.text ) ? ' is-command' : '' }` }
			>
				<div className="aiops-msg__bubble">{ turn.text }</div>
				{ turn.selection && turn.selection.count > 0 && (
					<div
						className="aiops-msg__selection"
						title={ ( turn.selection.product_ids || [] )
							.map( ( id ) => `#${ id }` )
							.join( ', ' ) }
					>
						<span aria-hidden="true">☑</span>{ ' ' }
						{ turn.selection.count === 1
							? __(
									'with 1 selected product',
									'addsynapse-store-assistant'
							  )
							: sprintf(
									/* translators: %d: number of products */ __(
										'with %d selected products',
										'addsynapse-store-assistant'
									),
									turn.selection.count
							  ) }
					</div>
				) }
				{ turn.image && turn.image.attachment_id && (
					<div className="aiops-msg__selection aiops-msg__image">
						{ turn.image.thumb ? (
							<img
								className="aiops-msg__thumb"
								src={ turn.image.thumb }
								alt=""
								width="48"
								height="48"
							/>
						) : (
							<span aria-hidden="true">🖼️</span>
						) }
						<span>
							{ turn.image.name }
							{ turn.image.width > 0 &&
								` · ${ turn.image.width }×${ turn.image.height } px` }
							{ ! turn.image.thumb &&
								' · ' +
									__(
										'removed (it was not used)',
										'addsynapse-store-assistant'
									) }
						</span>
					</div>
				) }
				<div className="aiops-msg__meta">
					<time
						dateTime={ turn.createdAt }
						title={ formatFull( turn.createdAt ) }
					>
						{ formatTime( turn.createdAt ) }
					</time>
					<CopyButton text={ turn.text } />
				</div>
			</div>
		);
	}

	const text = turnText( turn );
	const note = STATUS_NOTES[ turn.status ]
		? STATUS_NOTES[ turn.status ]()
		: '';
	const showTyping =
		live &&
		( ! turn.parts.length || phase === 'thinking' || phase === 'tool' );

	return (
		<div className="aiops-msg aiops-msg--assistant">
			<div className="aiops-msg__avatar" aria-hidden="true">
				AI
			</div>
			<div className="aiops-msg__content">
				{ turn.parts.map( ( part, i ) =>
					part.type === 'tool' ? (
						<div className="aiops-toolpart" key={ part.id || i }>
							<ToolChip part={ part } />
							<ResultView part={ part } />
						</div>
					) : (
						<Markdown key={ i } text={ part.text } />
					)
				) }
				{ showTyping && (
					<div
						className="aiops-typing"
						role="status"
						aria-live="polite"
					>
						<span className="aiops-typing__dots" aria-hidden="true">
							<span />
							<span />
							<span />
						</span>
						<span className="aiops-typing__label">
							{ phase === 'thinking' &&
								__(
									'Thinking…',
									'addsynapse-store-assistant'
								) }
							{ phase === 'tool' &&
								__(
									'Working with the store data…',
									'addsynapse-store-assistant'
								) }
							{ ( ! phase || phase === 'waiting' ) &&
								__( 'Writing…', 'addsynapse-store-assistant' ) }
						</span>
					</div>
				) }
				{ note && (
					<div className={ `aiops-msg__note is-${ turn.status }` }>
						{ note }
					</div>
				) }
				{ ! live && (
					<div className="aiops-msg__meta">
						<time
							dateTime={ turn.createdAt }
							title={ formatFull( turn.createdAt ) }
						>
							{ formatTime( turn.createdAt ) }
						</time>
						<CopyButton text={ text } />
					</div>
				) }
			</div>
		</div>
	);
}
