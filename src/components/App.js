/**
 * Store Assistant chat app.
 */
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Modal, Notice, Spinner } from '@wordpress/components';
import { Icon, cog, menu } from '@wordpress/icons';
import Sidebar from './Sidebar';
import MessageTurn from './MessageTurn';
import Composer from './Composer';
import EmptyState from './EmptyState';
import AiModePicker from './AiModePicker';
import SelectionBar from './SelectionBar';
import {
	ApiError,
	createConversation,
	deleteConversation,
	errorMessage,
	getMessages,
	getStatus,
	isNetworkError,
	listConversations,
	stopConversation,
	renameConversation,
	sendBody,
	sendMessage,
	streamMessage,
	uploadImage,
	deleteImage,
} from '../lib/api';
import { buildTurns } from '../lib/turns';
import {
	EMPTY_SELECTION,
	SelectionContext,
	loadSelection,
	saveSelection,
	toggleRows,
} from '../lib/selection';

const settings = window.addsynapseAssistant || {};

const readHashId = () => {
	const m = /^#\/c\/(\d+)$/.exec( window.location.hash || '' );
	return m ? parseInt( m[ 1 ], 10 ) : null;
};

const writeHashId = ( id ) => {
	const hash = id ? `#/c/${ id }` : '';
	if ( window.location.hash !== hash ) {
		window.history.replaceState(
			null,
			'',
			window.location.pathname + window.location.search + hash
		);
	}
};

const EMPTY_LIVE = { active: false, parts: [], phase: 'waiting' };

export default function App() {
	const [ status, setStatus ] = useState( null );
	const [ conversations, setConversations ] = useState( [] );
	const [ listLoading, setListLoading ] = useState( true );
	const [ search, setSearch ] = useState( '' );
	const [ activeId, setActiveId ] = useState( null );
	const [ messages, setMessages ] = useState( [] );
	const [ threadLoading, setThreadLoading ] = useState( false );
	// «Try the chat» of the setup wizard opens the chat with a fixed first question in the composer.
	const [ input, setInput ] = useState( () => settings.initialPrompt || '' );
	const [ busy, setBusy ] = useState( false );
	const [ live, setLive ] = useState( EMPTY_LIVE );
	const [ error, setError ] = useState( null );
	const [ notice, setNotice ] = useState( '' );
	const [ toDelete, setToDelete ] = useState( null );
	const [ sidebarOpen, setSidebarOpen ] = useState( false );
	const [ connectionNoticeHidden, setConnectionNoticeHidden ] =
		useState( false );
	// Ticked products of the open conversation (kept until the manager clears them).
	const [ selection, setSelection ] = useState( EMPTY_SELECTION );
	// Image attached in the composer: { status: uploading|ready|error, name, progress, image, error }.
	const [ attachment, setAttachment ] = useState( null );
	// Drag & drop of an image onto the chat: the drop-zone overlay is shown while a file is dragged over it.
	const [ dropping, setDropping ] = useState( false );
	const dragDepth = useRef( 0 );
	const sendRef = useRef( null );

	const abortRef = useRef( null );
	// The server spent its time budget between two model calls (own AI key on a short PHP time limit, or
	// compatibility mode): the same turn continues with another request.
	const continueRef = useRef( false );
	const threadRef = useRef( null );
	const stickRef = useRef( true );
	const composerRef = useRef( null );
	const activeRef = useRef( null );
	const skipLoadRef = useRef( null );
	const messagesRef = useRef( [] );
	activeRef.current = activeId;

	const streaming =
		settings.streaming !== false &&
		( ! status || status.streaming !== false );

	/* ---------- loading ---------- */

	const refreshList = useCallback( async ( term = '' ) => {
		try {
			const rows = await listConversations( term );
			setConversations( rows );
			return rows;
		} catch ( e ) {
			setError( { message: errorMessage( e ), retry: false } );
			return [];
		} finally {
			setListLoading( false );
		}
	}, [] );

	const loadThread = useCallback( async ( id ) => {
		if ( ! id ) {
			setMessages( [] );
			return;
		}
		setThreadLoading( true );
		try {
			const data = await getMessages( id );
			if ( activeRef.current === id ) {
				setMessages( data.messages );
				setConversations( ( list ) =>
					list.map( ( c ) =>
						c.id === id ? { ...c, ...data.conversation } : c
					)
				);
			}
		} catch ( e ) {
			if ( e && e.code === 'addsynapse_assistant_not_found' ) {
				setActiveId( null );
				writeHashId( null );
			} else {
				setError( { message: errorMessage( e ), retry: false } );
			}
		} finally {
			setThreadLoading( false );
		}
	}, [] );

	const refreshStatus = useCallback( () => {
		getStatus()
			.then( setStatus )
			.catch( () => {} );
	}, [] );

	useEffect( () => {
		getStatus()
			.then( setStatus )
			.catch( () => setStatus( { connection_configured: false } ) );
		// Read the hash now: the activeId effect below clears it before the list arrives.
		const hashId = readHashId();
		refreshList().then( ( rows ) => {
			if ( hashId && rows.some( ( c ) => c.id === hashId ) ) {
				setActiveId( hashId );
			}
		} );
	}, [ refreshList ] );

	// Each conversation has its own selection (sessionStorage, per tab).
	useEffect( () => {
		setSelection( loadSelection( activeId ) );
	}, [ activeId ] );

	const updateSelection = useCallback( ( fn ) => {
		setSelection( ( current ) => {
			const next = fn( current );
			saveSelection( activeRef.current, next );
			return next;
		} );
	}, [] );

	const selectionApi = useMemo( () => {
		const set = new Set( selection.ids );
		return {
			has: ( id ) => set.has( Number( id ) ),
			count: selection.ids.length,
			toggle: ( rows, checked, source = '', currency = '' ) =>
				updateSelection( ( current ) =>
					toggleRows( current, rows, checked, source, currency )
				),
			clear: () => updateSelection( () => EMPTY_SELECTION ),
		};
	}, [ selection, updateSelection ] );

	useEffect( () => {
		writeHashId( activeId );
		setError( null );
		setNotice( '' );
		stickRef.current = true;
		if ( skipLoadRef.current && skipLoadRef.current === activeId ) {
			// Just created by send(): nothing to load, keep the optimistic message.
			skipLoadRef.current = null;
			return;
		}
		loadThread( activeId );
	}, [ activeId, loadThread ] );

	// Debounced search.
	useEffect( () => {
		const t = setTimeout( () => refreshList( search.trim() ), 250 );
		return () => clearTimeout( t );
	}, [ search, refreshList ] );

	/* ---------- scrolling ---------- */

	// Stick to the bottom unless the manager scrolls UP. The thread scrolls smoothly (CSS), so an automatic scroll
	// passes through positions far from the bottom: only a move upwards un-sticks, never the animation itself.
	const lastTopRef = useRef( 0 );
	const onScroll = () => {
		const el = threadRef.current;
		if ( el ) {
			if ( el.scrollHeight - el.scrollTop - el.clientHeight < 80 ) {
				stickRef.current = true;
			} else if ( el.scrollTop < lastTopRef.current - 2 ) {
				stickRef.current = false;
			}
			lastTopRef.current = el.scrollTop;
		}
	};

	useEffect( () => {
		const el = threadRef.current;
		if ( el && stickRef.current ) {
			el.scrollTop = el.scrollHeight;
		}
	}, [ messages, live, error, threadLoading ] );

	// Cards (changesets, results) render a spinner first and grow after their data loads — AFTER the scroll above.
	// Keep sticking to the bottom while the content grows (unless the manager scrolled up), so the newest card's
	// Approve button is not left below the fold.
	const innerObserver = useRef( null );
	const innerRef = useCallback( ( node ) => {
		if ( innerObserver.current ) {
			innerObserver.current.disconnect();
			innerObserver.current = null;
		}
		if ( node && typeof window.ResizeObserver !== 'undefined' ) {
			innerObserver.current = new window.ResizeObserver( () => {
				const el = threadRef.current;
				if ( el && stickRef.current ) {
					el.scrollTop = el.scrollHeight;
				}
			} );
			innerObserver.current.observe( node );
		}
	}, [] );

	useEffect( () => {
		messagesRef.current = messages;
	}, [ messages ] );

	// «Repeat» on a card stored a new tool_use/tool_result pair: show the new card.
	useEffect( () => {
		const onReplanned = ( event ) => {
			const id = event.detail && event.detail.conversationId;
			if ( id && id === activeRef.current && ! abortRef.current ) {
				stickRef.current = true;
				loadThread( id );
			}
		};
		window.addEventListener(
			'addsynapse-assistant:replanned',
			onReplanned
		);
		return () =>
			window.removeEventListener(
				'addsynapse-assistant:replanned',
				onReplanned
			);
	}, [ loadThread ] );

	/* ---------- sending ---------- */

	const upsertConversation = ( conv ) => {
		if ( ! conv ) {
			return;
		}
		setConversations( ( list ) => {
			const others = list.filter( ( c ) => c.id !== conv.id );
			return [ conv, ...others ];
		} );
	};

	const handleEvent = ( name, data ) => {
		switch ( name ) {
			case 'user_message':
				setMessages( ( list ) => [
					...list.filter( ( m ) => ! m.pending ),
					data,
				] );
				break;
			case 'conversation':
				upsertConversation( data );
				break;
			case 'thinking':
				setLive( ( l ) => ( { ...l, phase: 'thinking' } ) );
				break;
			case 'text_delta':
				setLive( ( l ) => {
					const parts = [ ...l.parts ];
					const last = parts[ parts.length - 1 ];
					if ( last && last.type === 'text' ) {
						parts[ parts.length - 1 ] = {
							...last,
							text: last.text + data.text,
						};
					} else {
						parts.push( { type: 'text', text: data.text } );
					}
					return { ...l, parts, phase: 'writing' };
				} );
				break;
			case 'tool_use_start':
			case 'tool_use':
				setLive( ( l ) => {
					const exists = l.parts.find(
						( p ) => p.type === 'tool' && p.id === data.id
					);
					const parts = exists
						? l.parts.map( ( p ) =>
								p.type === 'tool' && p.id === data.id
									? {
											...p,
											input: data.input ?? p.input,
											label: data.label || p.label,
									  }
									: p
						  )
						: [
								...l.parts,
								{
									type: 'tool',
									id: data.id,
									name: data.name,
									label: data.label,
									input: data.input,
									state: 'running',
									result: null,
								},
						  ];
					return { ...l, parts, phase: 'tool' };
				} );
				break;
			case 'tool_result':
				setLive( ( l ) => ( {
					...l,
					parts: l.parts.map( ( p ) =>
						p.type === 'tool' && p.id === data.tool_use_id
							? {
									...p,
									state: data.is_error ? 'error' : 'done',
									// Same shape as the stored history (MessageFormatter::tool_result), so
									// result views / changeset cards render live, not only after a reload.
									data:
										data.data !== undefined
											? data.data
											: null,
									result:
										data.data !== undefined
											? data.data
											: data.content ?? null,
							  }
							: p
					),
					phase: 'thinking',
				} ) );
				break;
			case 'notice':
				if ( data.code === 'max_iterations' ) {
					setNotice(
						__(
							'The assistant stopped after too many tool steps. Ask it to continue if needed.',
							'addsynapse-store-assistant'
						)
					);
				}
				break;
			case 'error':
				setError( {
					message: data.message,
					retry: data.retryable !== false,
				} );
				break;
			case 'done':
				upsertConversation( data.conversation );
				continueRef.current = data.status === 'continue';
				break;
			default:
				break;
		}
	};

	/**
	 * Polls the stored conversation while the server is still generating (after the live
	 * stream dropped, or after Stop), showing progress as it is saved.
	 *
	 * @param {number}    conversationId Conversation.
	 * @param {?Function} onMessages     Called with the stored messages on every poll (optional).
	 * @return {Promise<boolean>} Whether the server finished (false on timeout/error).
	 */
	const waitForServer = async ( conversationId, onMessages = null ) => {
		const deadline = Date.now() + 330000;
		while (
			Date.now() < deadline &&
			activeRef.current === conversationId
		) {
			let data;
			try {
				data = await getMessages( conversationId );
			} catch ( e ) {
				await new Promise( ( r ) => setTimeout( r, 3000 ) );
				continue;
			}
			if ( activeRef.current !== conversationId ) {
				return false;
			}
			setMessages( data.messages );
			upsertConversation( data.conversation );
			if ( onMessages ) {
				onMessages( data.messages );
			}
			if ( ! data.running ) {
				return true;
			}
			await new Promise( ( r ) => setTimeout( r, 1500 ) );
		}
		return false;
	};

	const run = async (
		conversationId,
		content,
		retry,
		selected = [],
		attached = null,
		hop = 0
	) => {
		continueRef.current = false;
		const imageId =
			attached && attached.image ? attached.image.attachment_id : 0;
		setBusy( true );
		setError( null );
		setNotice( '' );
		setLive( { active: true, parts: [], phase: 'waiting' } );
		stickRef.current = true;
		// Newest stored message before this send: tells whether a dropped request reached the server.
		const lastStoredId = messagesRef.current.reduce(
			( max, m ) =>
				typeof m.id === 'number' && m.id > max ? m.id : max,
			0
		);

		const controller = new window.AbortController();
		abortRef.current = controller;
		let aborted = false;

		try {
			if ( streaming ) {
				await streamMessage(
					conversationId,
					sendBody( content, retry, selected, imageId ),
					handleEvent,
					controller.signal
				);
			} else {
				const data = await sendMessage(
					conversationId,
					content,
					retry,
					selected,
					imageId
				);
				upsertConversation( data.conversation );
				continueRef.current = data.status === 'continue';
				if ( data.error ) {
					setError( {
						message: data.error.message,
						retry: data.error.retryable !== false,
					} );
				}
			}
		} catch ( e ) {
			if ( e && e.name === 'AbortError' ) {
				aborted = true;
			} else if (
				e &&
				( e.code === 'stream_dropped' || isNetworkError( e ) )
			) {
				// The browser lost the live connection (e.g. Chrome's ERR_NETWORK_CHANGED, also
				// before the response started); the server keeps generating and saves the answer.
				// Show it as soon as it is stored — never a hard error for a transient drop.
				setLive( EMPTY_LIVE );
				setNotice(
					__(
						'The live connection was interrupted — the answer is still being prepared and will appear here.',
						'addsynapse-store-assistant'
					)
				);
				let reached = retry;
				const finished = await waitForServer(
					conversationId,
					( list ) => {
						reached =
							reached ||
							list.some(
								( m ) =>
									typeof m.id === 'number' &&
									m.id > lastStoredId
							);
					}
				);
				setNotice( '' );
				if ( activeRef.current === conversationId ) {
					if ( finished && ! reached ) {
						// The request never reached the server: nothing was stored, give the text back.
						setInput( content );
						setError( {
							message: errorMessage( new TypeError() ),
							retry: false,
						} );
					} else if ( ! finished ) {
						setError( {
							message: __(
								'The answer is taking too long. Reload the conversation in a moment, or retry.',
								'addsynapse-store-assistant'
							),
							retry: true,
						} );
					}
				}
			} else {
				// Rejected before anything was stored (validation / limits): restore the draft.
				const storedNothing =
					e instanceof ApiError || ( e && e.data && e.data.status );
				if ( storedNothing && ! retry ) {
					setMessages( ( list ) =>
						list.filter( ( m ) => ! m.pending )
					);
					setInput( content );
					if (
						attached &&
						! (
							e &&
							( e.status === 404 ||
								( e.data && e.data.status === 404 ) )
						)
					) {
						setAttachment( attached ); // Still yours: attach it again.
					}
				}
				setError( {
					message: errorMessage( e ),
					retry: ! (
						e &&
						[ 400, 403, 404, 429 ].includes(
							e.status || ( e.data && e.data.status )
						)
					),
				} );
			}
		} finally {
			abortRef.current = null;
			if ( aborted ) {
				// Wait until the server stored the partial reply.
				await waitForServer( conversationId );
			}
			if ( activeRef.current === conversationId ) {
				await loadThread( conversationId );
			}
			setLive( EMPTY_LIVE );
			setBusy( false );
			setTimeout(
				() => composerRef.current && composerRef.current.focus(),
				0
			);
		}
		if (
			continueRef.current &&
			! aborted &&
			hop < 8 &&
			activeRef.current === conversationId
		) {
			continueRef.current = false;
			await run( conversationId, '', true, [], null, hop + 1 );
		}
	};

	const send = async ( text ) => {
		const content = ( text ?? input ).trim();
		if ( ! content || busy ) {
			return;
		}
		let id = activeId;
		if ( ! id ) {
			try {
				setBusy( true );
				const conv = await createConversation();
				upsertConversation( conv );
				id = conv.id;
				activeRef.current = id;
				skipLoadRef.current = id;
				setActiveId( id );
			} catch ( e ) {
				setBusy( false );
				setError( { message: errorMessage( e ), retry: false } );
				return;
			}
		}
		// The selection travels as structured context and stays until the manager clears it.
		const selected = id === activeId ? [ ...selection.ids ] : [];
		// A ready image goes with this message (its id only) and leaves the composer.
		const attached =
			attachment && attachment.status === 'ready' ? attachment : null;
		if ( attached ) {
			setAttachment( null );
		}
		if ( text === undefined ) {
			setInput( '' ); // A card's command leaves the manager's draft alone.
		}
		setMessages( ( list ) => [
			...list,
			{
				id: `tmp-${ Date.now() }`,
				role: 'user',
				status: 'complete',
				created_at: new Date().toISOString(),
				blocks: [ { type: 'text', text: content } ],
				selection: selected.length
					? { product_ids: selected, count: selected.length }
					: null,
				image:
					attached && attached.image
						? {
								attachment_id: attached.image.attachment_id,
								name: attached.image.name,
								width: attached.image.width,
								height: attached.image.height,
								thumb: attached.image.thumb,
						  }
						: null,
				pending: true,
			},
		] );
		await run( id, content, false, selected, attached );
	};
	sendRef.current = send;

	/* ---------- Attachment: image (media library) ---------- */

	const imageSettings = settings.images || {};
	const attach = async ( file ) => {
		const name = file.name || 'file';
		const isImage =
			/\.(jpe?g|png|webp)$/i.test( name ) &&
			( ! file.type || /^image\/(jpeg|png|webp)$/.test( file.type ) );
		if ( ! isImage ) {
			setAttachment( {
				status: 'error',
				kind: 'image',
				name,
				error: __(
					'Only JPG, PNG and WebP images can be attached.',
					'addsynapse-store-assistant'
				),
			} );
			return;
		}
		const maxBytes = imageSettings.maxBytes;
		if ( maxBytes && file.size > maxBytes ) {
			setAttachment( {
				status: 'error',
				kind: 'image',
				name,
				error: sprintf(
					/* translators: %s: size in MB */ __(
						'The file is larger than %s MB.',
						'addsynapse-store-assistant'
					),
					Math.round( maxBytes / 1048576 )
				),
			} );
			return;
		}
		const preview =
			window.URL && window.URL.createObjectURL
				? window.URL.createObjectURL( file )
				: '';
		setAttachment( {
			status: 'uploading',
			kind: 'image',
			name,
			progress: 0,
			preview,
		} );
		try {
			const result = await uploadImage( file, ( progress ) =>
				setAttachment( ( current ) =>
					current && current.status === 'uploading'
						? { ...current, progress }
						: current
				)
			);
			setAttachment( {
				status: 'ready',
				kind: 'image',
				name: result.name || name,
				image: result,
				preview,
			} );
		} catch ( e ) {
			setAttachment( {
				status: 'error',
				kind: 'image',
				name,
				preview,
				error: errorMessage( e ),
			} );
		}
	};

	// Drag & drop / paste: the first image of the dropped files goes through the same checks as the 📎 button.
	const attachFiles = ( files ) => {
		const list = Array.from( files || [] );
		if ( ! list.length || busy ) {
			return;
		}
		const image = list.find( ( f ) =>
			/^image\/(jpeg|png|webp)$/.test( f.type || '' )
		);
		attach( image || list[ 0 ] );
		if ( image && list.length > 1 ) {
			setNotice(
				__(
					'One image per message: the first image was attached.',
					'addsynapse-store-assistant'
				)
			);
		}
	};
	const carriesFiles = ( event ) =>
		!! event.dataTransfer &&
		Array.from( event.dataTransfer.types || [] ).includes( 'Files' );
	const dropProps = imageSettings.enabled
		? {
				onDragEnter: ( event ) => {
					if ( ! carriesFiles( event ) ) {
						return;
					}
					event.preventDefault();
					dragDepth.current += 1;
					setDropping( true );
				},
				onDragOver: ( event ) => {
					if ( carriesFiles( event ) ) {
						event.preventDefault();
						event.dataTransfer.dropEffect = 'copy';
					}
				},
				onDragLeave: ( event ) => {
					if ( ! carriesFiles( event ) ) {
						return;
					}
					dragDepth.current = Math.max( 0, dragDepth.current - 1 );
					if ( ! dragDepth.current ) {
						setDropping( false );
					}
				},
				onDrop: ( event ) => {
					if ( ! carriesFiles( event ) ) {
						return;
					}
					event.preventDefault();
					dragDepth.current = 0;
					setDropping( false );
					attachFiles( event.dataTransfer.files );
				},
		  }
		: {};

	const removeAttachment = () => {
		if ( attachment && attachment.status === 'ready' ) {
			if ( attachment.image ) {
				deleteImage( attachment.image.attachment_id ).catch( () => {} );
			}
		}
		setAttachment( null );
	};

	// Extension point: cards of other plugins send a command through the chat (event `addsynapse-assistant:send`).
	useEffect( () => {
		const onSend = ( event ) => {
			const text = event.detail && event.detail.text;
			if ( text && sendRef.current ) {
				sendRef.current( text );
			}
		};
		window.addEventListener( 'addsynapse-assistant:send', onSend );
		return () =>
			window.removeEventListener( 'addsynapse-assistant:send', onSend );
	}, [] );

	const retry = () => {
		if ( activeId && ! busy ) {
			run( activeId, '', true );
		}
	};

	const stop = () => {
		if ( abortRef.current ) {
			// Closing the connection alone no longer cancels the run (network drops must not
			// lose answers): tell the server explicitly.
			if ( activeId ) {
				stopConversation( activeId ).catch( () => {} );
			}
			abortRef.current.abort();
		}
	};

	/* ---------- conversation actions ---------- */

	const newChat = () => {
		if ( busy ) {
			return;
		}
		setActiveId( null );
		setMessages( [] );
		setInput( '' );
		setSidebarOpen( false );
		setTimeout(
			() => composerRef.current && composerRef.current.focus(),
			0
		);
	};

	const select = ( id ) => {
		if ( busy || id === activeId ) {
			setSidebarOpen( false );
			return;
		}
		setMessages( [] );
		setActiveId( id );
		setSidebarOpen( false );
	};

	const rename = async ( id, title ) => {
		const previous = conversations;
		setConversations( ( list ) =>
			list.map( ( c ) => ( c.id === id ? { ...c, title } : c ) )
		);
		try {
			const conv = await renameConversation( id, title );
			setConversations( ( list ) =>
				list.map( ( c ) => ( c.id === id ? { ...c, ...conv } : c ) )
			);
		} catch ( e ) {
			setConversations( previous );
			setError( { message: errorMessage( e ), retry: false } );
		}
	};

	const confirmDelete = async () => {
		const conv = toDelete;
		setToDelete( null );
		if ( ! conv ) {
			return;
		}
		try {
			await deleteConversation( conv.id );
			setConversations( ( list ) =>
				list.filter( ( c ) => c.id !== conv.id )
			);
			if ( conv.id === activeId ) {
				setActiveId( null );
				setMessages( [] );
			}
		} catch ( e ) {
			setError( { message: errorMessage( e ), retry: false } );
		}
	};

	/* ---------- render ---------- */

	const turns = useMemo( () => buildTurns( messages ), [ messages ] );
	const active = conversations.find( ( c ) => c.id === activeId );
	const liveTurn = live.active
		? {
				key: 'live',
				role: 'assistant',
				parts: live.parts,
				status: 'complete',
				createdAt: '',
		  }
		: null;
	const showEmpty =
		! activeId || ( ! threadLoading && ! turns.length && ! liveTurn );
	const notConfigured = status && status.connection_configured === false;

	return (
		<SelectionContext.Provider value={ activeId ? selectionApi : null }>
			<div
				className={ `aiops-app${
					sidebarOpen ? ' is-sidebar-open' : ''
				}` }
			>
				<Sidebar
					conversations={ conversations }
					loading={ listLoading }
					activeId={ activeId }
					search={ search }
					onSearch={ setSearch }
					onSelect={ select }
					onNew={ newChat }
					onRename={ rename }
					onDelete={ setToDelete }
					onClose={ () => setSidebarOpen( false ) }
					busy={ busy }
				/>
				{ sidebarOpen && (
					<button
						type="button"
						className="aiops-scrim"
						aria-label={ __(
							'Close',
							'addsynapse-store-assistant'
						) }
						onClick={ () => setSidebarOpen( false ) }
					/>
				) }

				<section
					className={ `aiops-main${
						dropping ? ' is-dropping' : ''
					}` }
					aria-label={ __( 'Chat', 'addsynapse-store-assistant' ) }
					{ ...dropProps }
				>
					{ dropping && (
						<div className="aiops-dropzone" aria-hidden="true">
							<div className="aiops-dropzone__inner">
								<strong>
									{ __(
										'Drop the image here',
										'addsynapse-store-assistant'
									) }
								</strong>
								<span>
									{ __(
										'JPG, PNG or WebP — it goes to the media library and is attached to your next message.',
										'addsynapse-store-assistant'
									) }
								</span>
							</div>
						</div>
					) }
					<header className="aiops-main__header">
						<Button
							className="aiops-main__menu"
							icon={ <Icon icon={ menu } /> }
							label={ __(
								'Conversations',
								'addsynapse-store-assistant'
							) }
							onClick={ () => setSidebarOpen( true ) }
						/>
						<h2 className="aiops-main__title">
							{ active
								? active.title ||
								  __(
										'New conversation',
										'addsynapse-store-assistant'
								  )
								: __(
										'Store Assistant',
										'addsynapse-store-assistant'
								  ) }
						</h2>
						{ settings.settingsUrl && (
							<Button
								className="aiops-main__settings"
								icon={ <Icon icon={ cog } /> }
								label={ __(
									'Settings',
									'addsynapse-store-assistant'
								) }
								href={ settings.settingsUrl }
							/>
						) }
					</header>
					{ notConfigured && status && status.ai_key && (
						<AiModePicker
							status={ status }
							onSaved={ () => refreshStatus() }
						/>
					) }
					{ notConfigured &&
						! ( status && status.ai_key ) &&
						! connectionNoticeHidden && (
							<Notice
								status="warning"
								className="aiops-config-notice"
								onRemove={ () =>
									setConnectionNoticeHidden( true )
								}
							>
								<strong>
									{ __(
										'The AI is not set up yet.',
										'addsynapse-store-assistant'
									) }
								</strong>{ ' ' }
								{ __(
									'The AI answers once an administrator adds an AI provider key in Settings. Slash commands (type /help) already work.',
									'addsynapse-store-assistant'
								) }{ ' ' }
								{ ( settings.setupUrl || settings.settingsUrl ) && (
									<a
										href={
											settings.setupUrl ||
											settings.settingsUrl
										}
									>
										{ __(
											'Set up',
											'addsynapse-store-assistant'
										) }
									</a>
								) }
							</Notice>
						) }

					<div
						className="aiops-thread"
						ref={ threadRef }
						onScroll={ onScroll }
					>
						{ threadLoading && ! turns.length && (
							<div className="aiops-thread__loading">
								<Spinner />
							</div>
						) }
						{ showEmpty && ! threadLoading && (
							<EmptyState
								onPick={ ( s ) => send( s ) }
								disabled={ busy }
								siteType={ settings.siteType }
							/>
						) }
						{ ! showEmpty && (
							<div
								className="aiops-thread__inner"
								ref={ innerRef }
							>
								{ turns.map( ( turn ) => (
									<MessageTurn
										key={ turn.key }
										turn={ turn }
									/>
								) ) }
								{ liveTurn && (
									<MessageTurn
										turn={ liveTurn }
										live
										phase={ live.phase }
									/>
								) }
								{ notice && (
									<Notice
										status="info"
										isDismissible={ false }
										className="aiops-inline-notice"
									>
										{ notice }
									</Notice>
								) }
								{ error && (
									<div className="aiops-error" role="alert">
										<span>{ error.message }</span>
										{ error.retry && (
											<Button
												variant="secondary"
												size="small"
												onClick={ retry }
												disabled={ busy }
											>
												{ __(
													'Retry',
													'addsynapse-store-assistant'
												) }
											</Button>
										) }
									</div>
								) }
							</div>
						) }
						{ showEmpty && error && (
							<div className="aiops-thread__inner">
								<div className="aiops-error" role="alert">
									<span>{ error.message }</span>
								</div>
							</div>
						) }
					</div>

					{ activeId && (
						<SelectionBar
							selection={ selection }
							onToggle={ selectionApi.toggle }
							onClear={ selectionApi.clear }
							disabled={ busy }
						/>
					) }
					<Composer
						ref={ composerRef }
						value={ input }
						onChange={ setInput }
						onSend={ () => send() }
						onStop={ stop }
						busy={ busy }
						streaming={ streaming }
						attachment={ attachment }
						onAttach={ imageSettings.enabled ? attach : undefined }
						onPasteFiles={
							imageSettings.enabled ? attachFiles : undefined
						}
						onRemoveAttachment={ removeAttachment }
						voiceOptIn={ !! settings.voice }
						locale={ settings.locale || '' }
						userId={ settings.userId || 0 }
					/>
				</section>

				{ toDelete && (
					<Modal
						title={ __(
							'Delete conversation?',
							'addsynapse-store-assistant'
						) }
						onRequestClose={ () => setToDelete( null ) }
						size="small"
					>
						<p>
							{ sprintf(
								/* translators: %s: conversation title */
								__(
									'“%s” will be removed from your history.',
									'addsynapse-store-assistant'
								),
								toDelete.title ||
									__(
										'New conversation',
										'addsynapse-store-assistant'
									)
							) }
						</p>
						<div className="aiops-modal-actions">
							<Button
								variant="tertiary"
								onClick={ () => setToDelete( null ) }
							>
								{ __( 'Cancel', 'addsynapse-store-assistant' ) }
							</Button>
							<Button
								variant="primary"
								isDestructive
								onClick={ confirmDelete }
							>
								{ __( 'Delete', 'addsynapse-store-assistant' ) }
							</Button>
						</div>
					</Modal>
				) }
			</div>
		</SelectionContext.Provider>
	);
}
