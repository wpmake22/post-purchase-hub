/**
 * Mount point for the setup wizard.
 *
 * The page this renders into is printed by `Admin\WizardPage` and is a document
 * of its own rather than a screen inside wp-admin: the wizard owns the window
 * while it is running, so there is no admin menu, no other plugin's banner and
 * no notice competing with the one question on the screen.
 */

import { createRoot } from '@wordpress/element';
import App from './App';
import '../styles/setup.scss';

const container = document.getElementById( 'wpmphub-setup-wizard' );

if ( container ) {
	createRoot( container ).render( <App /> );
}
