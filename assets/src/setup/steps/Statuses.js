/**
 * The stage map: which of this store's order statuses a customer sees, and as
 * what.
 *
 * The statuses listed are the ones the store actually has, and the ones it has
 * recently used are called out — a merchant with a custom "awaiting parts"
 * status should be looking at that row, not scrolling a list of WooCommerce
 * defaults trying to remember which ones they use.
 */

import { SelectControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import Question from '../Question';

/**
 * Renders the stage-map screen.
 *
 * @param {Object}   props          Component props.
 * @param {Array}    props.statuses Statuses, as `{ value, label }`.
 * @param {Array}    props.stages   Stages, as `{ value, label }`.
 * @param {Array}    props.detected Slugs found on recent orders.
 * @param {Object}   props.value    Current map, status slug => stage key.
 * @param {Function} props.onChange Called with the whole new map.
 * @return {Element} The screen.
 */
export default function Statuses( {
	statuses,
	stages,
	detected,
	value,
	onChange,
} ) {
	return (
		<Question
			title={ __(
				'Which statuses do your customers see?',
				'wpmake-post-purchase-hub'
			) }
			help={ __(
				'Each status becomes a stage on the customer’s timeline. A status set to “not shown” contributes nothing to it, which is how an internal status stays internal — and a stage with nothing in it is never shown to a customer.',
				'wpmake-post-purchase-hub'
			) }
		>
			{ detected.length > 0 && (
				<p className="wpmphub-setup__detected" data-wpmphub-wizard-detected>
					{ sprintf(
						/* translators: %s: comma-separated list of order statuses found on the store's recent orders. */
						__(
							'Found on your recent orders: %s.',
							'wpmake-post-purchase-hub'
						),
						detected.join( ', ' )
					) }
				</p>
			) }

			<div className="wpmphub-setup__map">
				{ statuses.map( ( status ) => (
					<div
						className={ `wpmphub-setup__map-row ${
							detected.includes( status.value )
								? 'is-detected'
								: ''
						}` }
						key={ status.value }
					>
						<SelectControl
							__nextHasNoMarginBottom
							label={ status.label }
							value={ value[ status.value ] ?? '' }
							options={ stages }
							onChange={ ( stage ) =>
								onChange( {
									...value,
									[ status.value ]: stage,
								} )
							}
						/>
					</div>
				) ) }
			</div>
		</Question>
	);
}
