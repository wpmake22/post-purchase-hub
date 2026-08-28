/**
 * Which self-service actions customers get.
 *
 * Each switch carries the sentence explaining what turning it on actually
 * means, because "Cancel" reads like the plugin will cancel orders and it never
 * does — a cancellation is a request the merchant approves.
 */

import { ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import Question from '../Question';

/**
 * Renders the actions screen.
 *
 * @param {Object}   props          Component props.
 * @param {Array}    props.actions  Actions, as `{ id, label, description }`.
 * @param {Object}   props.value    Current switches, action id => boolean.
 * @param {Function} props.onChange Called with the whole new switch map.
 * @return {Element} The screen.
 */
export default function Actions( { actions, value, onChange } ) {
	return (
		<Question
			title={ __(
				'What should customers be able to do?',
				'wpmake-post-purchase-hub'
			) }
			help={ __(
				'You can change any of this later. Cancellation is always a request you approve or decline — this plugin never cancels an order by itself, and never issues a refund.',
				'wpmake-post-purchase-hub'
			) }
		>
			<div className="pph-setup__toggles">
				{ actions.map( ( action ) => (
					<div
						className="pph-setup__toggle"
						key={ action.id }
						data-pph-wizard-action={ action.id }
					>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ action.label }
							help={ action.description }
							checked={ false !== value[ action.id ] }
							onChange={ ( checked ) =>
								onChange( {
									...value,
									[ action.id ]: checked,
								} )
							}
						/>
					</div>
				) ) }
			</div>
		</Question>
	);
}
