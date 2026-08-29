/**
 * What tracking data this store actually has, stated either way.
 *
 * Asks nothing and stores nothing. It is a screen because the honest answer is
 * worth a merchant's attention before they finish: a store with no tracking
 * plugin gets delivery estimates, and finding that out here is better than
 * finding it out from a customer.
 */

import { __ } from '@wordpress/i18n';
import Question from '../Question';

/**
 * Renders the tracking screen.
 *
 * @param {Object} props          Component props.
 * @param {Object} props.tracking `{ plugin, message, search_url }`.
 * @return {Element} The screen.
 */
export default function Tracking( { tracking } ) {
	const found = '' !== tracking.plugin;

	return (
		<Question
			title={ __(
				'Where does tracking come from?',
				'wpmake-post-purchase-hub'
			) }
			help={ __(
				'This plugin never invents tracking data. It shows a delivery estimate until something real exists, and then gets out of the way.',
				'wpmake-post-purchase-hub'
			) }
		>
			<p
				className={ `wpmphub-setup__detected ${
					found ? 'is-found' : 'is-missing'
				}` }
				data-wpmphub-wizard-tracking={ found ? 'found' : 'none' }
			>
				{ tracking.message }
			</p>

			{ ! found && (
				<p>
					<a
						className="wpmphub-setup__link-button"
						href={ tracking.search_url }
						target="_blank"
						rel="noopener noreferrer"
					>
						{ __( 'Browse tracking plugins', 'wpmake-post-purchase-hub' ) }
					</a>
				</p>
			) }
		</Question>
	);
}
