/**
 * Entry: mounts the Store Assistant app.
 */
import { createRoot } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';
import App from './components/App';
import './app.scss';

domReady( () => {
	const root = document.getElementById( 'addsynapse-assistant-root' );
	if ( root ) {
		createRoot( root ).render( <App /> );
	}
} );
