import { createRoot, createElement } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import App from './components/App';
import './styles.css';

const rootElement = document.getElementById( 'lift-teleport-admin-app' );

if ( rootElement ) {
	let props = {};

	try {
		props = JSON.parse( rootElement.dataset.props || '{}' );
	} catch ( error ) {
		props = {};
	}

	if ( props.nonce ) {
		apiFetch.use( apiFetch.createNonceMiddleware( props.nonce ) );
	}

	createRoot( rootElement ).render( createElement( App, { props } ) );
}
