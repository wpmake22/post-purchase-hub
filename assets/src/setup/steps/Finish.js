/**
 * The last screen: a summary of what is about to change, then the one write.
 *
 * Everything before this has been a draft. This screen is where a merchant is
 * told that in as many words — because the promise that abandoning the wizard
 * changes nothing is only reassuring if they knew it was true — and where
 * "Finish and go live" does the single `update_option()` that turns the
 * storefront on.
 */

import { __, sprintf } from '@wordpress/i18n';
import Question from '../Question';

/**
 * Renders the finish screen, before and after the commit.
 *
 * @param {Object}  props           Component props.
 * @param {boolean} props.completed Whether setup has already been committed.
 * @param {Array}   props.summary   Lines describing what will be applied.
 * @param {string}  props.queueUrl  The request queue.
 * @param {string}  props.exitUrl   The settings screen.
 * @return {Element} The screen.
 */
export default function Finish( { completed, summary, queueUrl, exitUrl } ) {
	if ( completed ) {
		return (
			<Question
				title={ __( 'Your store is live', 'wpmake-post-purchase-hub' ) }
				help={ __(
					'Customers now see their order timeline on the pages your theme already draws, and whatever actions you switched on. Every answer you gave is on the settings screen if you want to change one.',
					'wpmake-post-purchase-hub'
				) }
			>
				<p className="pph-setup__actions" data-pph-wizard-done>
					<a className="pph-setup__button" href={ queueUrl }>
						{ __( 'Go to the request queue', 'wpmake-post-purchase-hub' ) }
					</a>
					<a className="pph-setup__link-button" href={ exitUrl }>
						{ __( 'Review the settings', 'wpmake-post-purchase-hub' ) }
					</a>
				</p>
			</Question>
		);
	}

	return (
		<Question
			title={ __( 'Ready to go live', 'wpmake-post-purchase-hub' ) }
			help={ __(
				'Nothing has been written to your store yet — everything you have answered so far is a draft. Finishing applies all of it at once, and that is the moment your customers start seeing any of this.',
				'wpmake-post-purchase-hub'
			) }
		>
			<ul className="pph-setup__summary" data-pph-wizard-summary>
				{ summary.map( ( line ) => (
					<li key={ line }>{ line }</li>
				) ) }
			</ul>

			<p className="pph-setup__note">
				{ sprintf(
					/* translators: %s: name of the settings screen section. */
					__(
						'Guest order lookup stays switched off. It adds a public endpoint, so it is turned on deliberately under %s rather than in passing here.',
						'wpmake-post-purchase-hub'
					),
					__( 'Settings → Guest Access', 'wpmake-post-purchase-hub' )
				) }
			</p>
		</Question>
	);
}
