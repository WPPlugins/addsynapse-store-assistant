/**
 * REST client for addsynapse-assistant/v1.
 */
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const settings = window.addsynapseAssistant || {};
const BASE = '/addsynapse-assistant/v1';

export const listConversations = ( search = '' ) =>
	apiFetch( {
		path:
			`${ BASE }/conversations` +
			( search ? `?search=${ encodeURIComponent( search ) }` : '' ),
	} );

export const createConversation = () =>
	apiFetch( { path: `${ BASE }/conversations`, method: 'POST', data: {} } );

export const renameConversation = ( id, title ) =>
	apiFetch( {
		path: `${ BASE }/conversations/${ id }`,
		method: 'PATCH',
		data: { title },
	} );

export const deleteConversation = ( id ) =>
	apiFetch( { path: `${ BASE }/conversations/${ id }`, method: 'DELETE' } );

export const getMessages = ( id ) =>
	apiFetch( { path: `${ BASE }/conversations/${ id }/messages` } );

export const getStatus = () => apiFetch( { path: `${ BASE }/status` } );

/**
 * Saves and tests the manager's own AI key (the key is never sent back).
 *
 * @param {Object} data { provider, model, key }.
 * @return {Promise<Object>} Key state.
 */
export const saveAiKey = ( data ) =>
	apiFetch( { path: `${ BASE }/ai-key`, method: 'POST', data } );

/**
 * The optional AddSynapse account (administrators): the chat's «Try Pro» dialog.
 *
 * @param {Object} data { email, marketing }.
 * @return {Promise<Object>} { status, message, link, account }.
 */
export const createAccount = ( data ) =>
	apiFetch( {
		path: `${ BASE }/account/optin`,
		method: 'POST',
		data,
		parse: false,
	} ).then( ( response ) => response.json() );

export const stopConversation = ( id ) =>
	apiFetch( {
		path: `${ BASE }/conversations/${ id }/stop`,
		method: 'POST',
		data: {},
	} );

/**
 * Request body of a send: the text plus, when products are ticked, the structured selection
 * (ids only — never concatenated into the text).
 *
 * @param {string}  content   Text.
 * @param {boolean} retry     Retry of the last message.
 * @param {Array}   selection Product ids.
 * @param {number}  image     Attachment id of an image uploaded in the chat, or 0.
 * @return {Object} Body.
 */
export const sendBody = (
	content,
	retry = false,
	selection = [],
	image = 0
) => {
	const body = { content, retry };
	if ( selection && selection.length && ! retry ) {
		body.selection = { product_ids: selection };
	}
	if ( image && ! retry ) {
		// An uploaded image travels as its attachment id only (the server checks it is yours).
		body.image = { attachment_id: image };
	}
	return body;
};

export const sendMessage = (
	id,
	content,
	retry = false,
	selection = [],
	image = 0
) =>
	apiFetch( {
		path: `${ BASE }/conversations/${ id }/messages`,
		method: 'POST',
		data: sendBody( content, retry, selection, image ),
	} );

/* ---------- images from the chat ---------- */

/**
 * Uploads an image with progress (XMLHttpRequest: fetch has no upload progress).
 *
 * @param {File}     file       File.
 * @param {Function} onProgress ( percent ) => void.
 * @return {Promise<Object>} The image { attachment_id, thumb, … }.
 */
export function uploadImage( file, onProgress = () => {} ) {
	return new Promise( ( resolve, reject ) => {
		const xhr = new window.XMLHttpRequest();
		const url = `${ settings.restRoot }images`;
		xhr.open(
			'POST',
			url + ( url.includes( '?' ) ? '&' : '?' ) + '_locale=user'
		);
		xhr.setRequestHeader( 'X-WP-Nonce', settings.nonce );
		xhr.upload.onprogress = ( e ) => {
			if ( e.lengthComputable ) {
				onProgress( Math.round( ( e.loaded / e.total ) * 100 ) );
			}
		};
		xhr.onload = () => {
			let data = null;
			try {
				data = JSON.parse( xhr.responseText );
			} catch ( e ) {
				// Not JSON.
			}
			if ( xhr.status >= 200 && xhr.status < 300 && data && data.image ) {
				resolve( data.image );
				return;
			}
			reject(
				new ApiError(
					( data && data.message ) ||
						__(
							'The file could not be uploaded.',
							'addsynapse-store-assistant'
						),
					( data && data.code ) || '',
					xhr.status
				)
			);
		};
		xhr.onerror = () => reject( new TypeError( 'Network error' ) );
		const form = new window.FormData();
		form.append( 'file', file, file.name );
		xhr.send( form );
	} );
}

export const deleteImage = ( id ) =>
	apiFetch( { path: `${ BASE }/images/${ id }`, method: 'DELETE' } );

/**
 * Another page of a product table (same read-only ability; nothing is stored or sent to the AI).
 *
 * @param {string} ability Ability name.
 * @param {Object} input   The tool input of the table.
 * @param {number} page    Page.
 * @return {Promise<Object>} { data }.
 */
export const resultPage = ( ability, input, page ) =>
	apiFetch( {
		path: `${ BASE }/results/page`,
		method: 'POST',
		data: { ability, input, page },
	} );

/**
 * Error carrying the server's code/status.
 */
export class ApiError extends Error {
	constructor( message, code = '', status = 0 ) {
		super( message );
		this.code = code;
		this.status = status;
	}
}

/**
 * The browser lost the streaming connection (the answer keeps being generated and stored
 * on the server; the UI reloads it).
 */
export class StreamDroppedError extends Error {
	constructor() {
		super(
			__(
				'The live connection to the server was interrupted.',
				'addsynapse-store-assistant'
			)
		);
		this.name = 'StreamDroppedError';
		this.code = 'stream_dropped';
	}
}

/**
 * Whether an error is a browser-level network failure (fetch TypeError).
 *
 * @param {*} error Error.
 * @return {boolean} True for network failures.
 */
export function isNetworkError( error ) {
	return !! error && error.name === 'TypeError';
}

/**
 * Human message for anything thrown by apiFetch / fetch.
 *
 * @param {*} error Error.
 * @return {string} Message.
 */
export function errorMessage( error ) {
	if ( error && error.name === 'AbortError' ) {
		return '';
	}
	if (
		isNetworkError( error ) ||
		( error && error.code === 'fetch_error' )
	) {
		return __(
			'Could not reach the server (network error). Check your connection and try again.',
			'addsynapse-store-assistant'
		);
	}
	if ( error && error.code === 'invalid_json' ) {
		return __(
			'The server returned an unexpected response.',
			'addsynapse-store-assistant'
		);
	}
	if ( error && typeof error.message === 'string' && error.message ) {
		return error.message;
	}
	return __(
		'Something went wrong. Please try again.',
		'addsynapse-store-assistant'
	);
}

/**
 * Streams a reply via Server-Sent Events over fetch (POST).
 *
 * @param {number}      id      Conversation id.
 * @param {Object}      body    { content, retry, selection? } (see sendBody).
 * @param {Function}    onEvent ( name, data ) => void.
 * @param {AbortSignal} signal  Abort signal (Stop button).
 * @return {Promise<void>} Resolves when the stream ends.
 */
export async function streamMessage( id, body, onEvent, signal ) {
	const url = `${ settings.restRoot }conversations/${ id }/stream`;
	// `_locale=user` like apiFetch does, so live tool labels use the admin's language.
	const response = await window.fetch(
		url + ( url.includes( '?' ) ? '&' : '?' ) + '_locale=user',
		{
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				Accept: 'text/event-stream',
				'X-WP-Nonce': settings.nonce,
			},
			body: JSON.stringify( body ),
			signal,
		}
	);

	const type = response.headers.get( 'content-type' ) || '';
	if ( ! response.ok || ! type.includes( 'text/event-stream' ) ) {
		let data = null;
		try {
			data = await response.json();
		} catch ( e ) {
			// Not JSON.
		}
		if ( response.ok && data && data.messages ) {
			// Server answered without streaming (e.g. a proxy): hand over as "done".
			onEvent( 'done', {
				status: data.status,
				conversation: data.conversation,
			} );
			return;
		}
		throw new ApiError(
			( data && data.message ) ||
				__(
					'The server returned an unexpected response.',
					'addsynapse-store-assistant'
				),
			( data && data.code ) || '',
			response.status
		);
	}

	const reader = response.body.getReader();
	const decoder = new window.TextDecoder();
	let buffer = '';
	let finished = false;

	for (;;) {
		let chunk;
		try {
			chunk = await reader.read();
		} catch ( e ) {
			if ( e && e.name === 'AbortError' ) {
				throw e;
			}
			// e.g. Chrome's ERR_NETWORK_CHANGED: the server keeps going and stores the answer.
			throw new StreamDroppedError();
		}
		const { value, done } = chunk;
		if ( done ) {
			if ( ! finished ) {
				throw new StreamDroppedError();
			}
			break;
		}
		buffer += decoder
			.decode( value, { stream: true } )
			.replace( /\r\n/g, '\n' );
		let index;
		while ( ( index = buffer.indexOf( '\n\n' ) ) !== -1 ) {
			const raw = buffer.slice( 0, index );
			buffer = buffer.slice( index + 2 );
			let name = 'message';
			let data = '';
			raw.split( '\n' ).forEach( ( line ) => {
				if ( line.startsWith( 'event:' ) ) {
					name = line.slice( 6 ).trim();
				} else if ( line.startsWith( 'data:' ) ) {
					data += line.slice( 5 ).trim();
				}
			} );
			if ( data ) {
				let parsed = null;
				try {
					parsed = JSON.parse( data );
				} catch ( e ) {
					// Ignore malformed frames.
				}
				if ( parsed ) {
					if ( name === 'done' ) {
						finished = true;
					}
					onEvent( name, parsed );
				}
			}
		}
	}
}

/* ---------- change sets (approve / reject / revert are UI buttons only) ---------- */

export const getChangeset = ( id ) =>
	apiFetch( { path: `${ BASE }/changesets/${ id }` } );

export const getChangesetItems = ( id, page = 1, perPage = 10 ) =>
	apiFetch( {
		path: `${ BASE }/changesets/${ id }/items?page=${ page }&per_page=${ perPage }`,
	} );

export const changesetAction = ( id, action, data = {} ) =>
	apiFetch( {
		path: `${ BASE }/changesets/${ id }/${ action }`,
		method: 'POST',
		data,
	} );
