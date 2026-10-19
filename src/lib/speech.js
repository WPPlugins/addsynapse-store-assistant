/**
 * Voice input (speech-to-text) with the browser's Web Speech API.
 *
 * Recognition runs in the browser (in Chrome the audio goes to Google); nothing is sent to our
 * hub. The recognised text is only put in the message box — never sent automatically.
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

const STORE_KEY = 'addsynapseAssistantSpeechLang';
const SILENCE_MS = 2000;

/**
 * The SpeechRecognition constructor, or null when the browser has none.
 *
 * @param {Object} win Window (for tests).
 * @return {Function|null} Constructor.
 */
export function speechRecognitionCtor( win = window ) {
	return (
		( win && ( win.SpeechRecognition || win.webkitSpeechRecognition ) ) ||
		null
	);
}

/**
 * WordPress locale (el, el_GR, en-GB, de_DE_formal…) → a BCP 47 recognition tag.
 * A bare language gets its own region (el → el-GR, fr → fr-FR); English → en-US.
 *
 * @param {string} locale Locale.
 * @return {string} Tag such as el-GR or en-US.
 */
export function speechLangFor( locale ) {
	const parts = String( locale || '' )
		.trim()
		.split( /[-_]/ )
		.filter( Boolean );
	const lang = ( parts[ 0 ] || '' ).toLowerCase();
	if ( ! /^[a-z]{2,3}$/.test( lang ) || 'en' === lang ) {
		return 'en' === lang && /^[a-z]{2}$/i.test( parts[ 1 ] || '' )
			? `en-${ parts[ 1 ].toUpperCase() }`
			: 'en-US';
	}
	const region = /^[a-z]{2}$/i.test( parts[ 1 ] || '' )
		? parts[ 1 ].toUpperCase()
		: lang.toUpperCase();
	return `${ lang }-${ region }`;
}

/**
 * Languages offered: the user's own language first, then English (one entry for English users).
 *
 * @param {string} locale User locale.
 * @return {string[]} Recognition tags.
 */
export function speechLangsFor( locale ) {
	const own = speechLangFor( locale );
	return own.startsWith( 'en-' ) ? [ own ] : [ own, 'en-US' ];
}

/**
 * Short button text for a tag: the language code in capitals (EL, EN, DE…).
 *
 * @param {string} tag Recognition tag.
 * @return {string} Code.
 */
export function speechLangCode( tag ) {
	return String( tag || '' )
		.split( '-' )[ 0 ]
		.toUpperCase();
}

/**
 * Name of a recognition language in the UI language (Intl), falling back to the code.
 *
 * @param {string} tag    Recognition tag.
 * @param {string} locale UI locale.
 * @return {string} Name, e.g. "Greek" / "Ελληνικά".
 */
export function speechLangName( tag, locale ) {
	const lang = String( tag || '' ).split( '-' )[ 0 ];
	try {
		const names = new window.Intl.DisplayNames(
			[ String( locale || 'en' ).replace( '_', '-' ), 'en' ],
			{ type: 'language' }
		);
		const name = names.of( lang );
		if ( name ) {
			return name.charAt( 0 ).toUpperCase() + name.slice( 1 );
		}
	} catch ( e ) {}
	return speechLangCode( tag );
}

/**
 * The remembered language for this user (localStorage may be unavailable), if still offered.
 *
 * @param {string|number} user   User id.
 * @param {string}        locale User locale.
 * @return {string} Language.
 */
export function loadSpeechLang( user, locale ) {
	const offered = speechLangsFor( locale );
	try {
		const saved = window.localStorage.getItem( `${ STORE_KEY }:${ user }` );
		if ( offered.includes( saved ) ) {
			return saved;
		}
	} catch ( e ) {}
	return offered[ 0 ];
}

/**
 * @param {string|number} user User id.
 * @param {string}        lang Language.
 */
export function saveSpeechLang( user, lang ) {
	try {
		window.localStorage.setItem( `${ STORE_KEY }:${ user }`, lang );
	} catch ( e ) {}
}

/**
 * Where the recognised text goes: at the cursor, with a space on either side when needed.
 *
 * @param {string} before Text before the cursor.
 * @param {string} spoken Recognised text.
 * @param {string} after  Text after the cursor (or the replaced selection's end).
 * @return {string} Joined text.
 */
export function joinSpoken( before, spoken, after ) {
	const text = String( spoken || '' ).trim();
	if ( ! text ) {
		return before + after;
	}
	const left = before && ! /\s$/.test( before ) ? ' ' : '';
	const right = after && ! /^\s/.test( after ) ? ' ' : '';
	return before + left + text + right + after;
}

/**
 * Speech recognition state for the composer.
 *
 * @param {Object}   opts         Options.
 * @param {string}   opts.lang    Recognition language.
 * @param {Function} opts.onText  ( spoken, isFinal ) — spoken = everything recognised so far.
 * @param {Function} opts.onError ( code ) — not called for `aborted`.
 * @param {Function} opts.onEnd   Called once when listening stops.
 * @return {Object} { supported, listening, start, stop }.
 */
export function useSpeech( { lang, onText, onError, onEnd } ) {
	const Ctor = speechRecognitionCtor();
	const [ listening, setListening ] = useState( false );
	const rec = useRef( null );
	const timer = useRef( null );
	const handlers = useRef( {} );
	handlers.current = { onText, onError, onEnd };

	const clearTimer = () => {
		if ( timer.current ) {
			window.clearTimeout( timer.current );
			timer.current = null;
		}
	};

	const stop = useCallback( () => {
		clearTimer();
		if ( rec.current ) {
			try {
				rec.current.stop();
			} catch ( e ) {}
		}
	}, [] );

	const start = useCallback( () => {
		if ( ! Ctor || rec.current ) {
			return;
		}
		const r = new Ctor();
		r.lang = lang;
		r.continuous = true;
		r.interimResults = true;
		r.maxAlternatives = 1;
		const armSilence = () => {
			clearTimer();
			timer.current = window.setTimeout( () => {
				try {
					r.stop();
				} catch ( e ) {}
			}, SILENCE_MS );
		};
		r.onresult = ( event ) => {
			let finalText = '';
			let interim = '';
			for ( let i = 0; i < event.results.length; i++ ) {
				const res = event.results[ i ];
				const piece = res[ 0 ] ? res[ 0 ].transcript : '';
				if ( res.isFinal ) {
					finalText += piece;
				} else {
					interim += piece;
				}
			}
			handlers.current.onText(
				( finalText + interim ).trim(),
				! interim
			);
			armSilence();
		};
		r.onerror = ( event ) => {
			if ( event.error !== 'aborted' && handlers.current.onError ) {
				handlers.current.onError( event.error );
			}
		};
		r.onend = () => {
			clearTimer();
			rec.current = null;
			setListening( false );
			if ( handlers.current.onEnd ) {
				handlers.current.onEnd();
			}
		};
		rec.current = r;
		try {
			r.start();
			setListening( true );
			// No speech at all → stop after a while (the browser also reports `no-speech`).
			timer.current = window.setTimeout( () => {
				try {
					r.stop();
				} catch ( e ) {}
			}, SILENCE_MS * 4 );
		} catch ( e ) {
			rec.current = null;
			if ( handlers.current.onError ) {
				handlers.current.onError( 'start' );
			}
		}
	}, [ Ctor, lang ] );

	useEffect(
		() => () => {
			clearTimer();
			if ( rec.current ) {
				rec.current.onend = null;
				try {
					rec.current.abort();
				} catch ( e ) {}
			}
		},
		[]
	);

	return { supported: !! Ctor, listening, start, stop };
}
