/**
 * Handling time — the number that turns an order date into "arrives Tuesday to
 * Thursday".
 *
 * Per-method overrides are behind a disclosure rather than on the screen: most
 * stores answer one number and move on, and putting a row per shipping method
 * in front of them is how a two-minute wizard becomes a five-minute one.
 */

import { __ } from '@wordpress/i18n';
import Question from '../Question';

/**
 * Renders the handling-time screen.
 *
 * @param {Object}   props                   Component props.
 * @param {Object}   props.bounds            `{ min, max, default }` from the field declaration.
 * @param {Array}    props.methods           Shipping methods, as `{ value, label }`.
 * @param {number}   props.days              Current global handling time.
 * @param {Object}   props.overrides         Per-method overrides.
 * @param {Function} props.onDaysChange      Called with the new global value.
 * @param {Function} props.onOverridesChange Called with the whole new override map.
 * @return {Element} The screen.
 */
export default function Delivery( {
	bounds,
	methods,
	days,
	overrides,
	onDaysChange,
	onOverridesChange,
} ) {
	/**
	 * Records one method's override, dropping it when the box is emptied.
	 *
	 * A blank means "use the default", so it is stored as an absent key rather
	 * than as a zero — zero is a real answer meaning "ships the same day".
	 *
	 * @param {string} method Shipping method id.
	 * @param {string} raw    Raw input value.
	 * @return {void}
	 */
	const setOverride = ( method, raw ) => {
		const next = { ...overrides };

		if ( '' === raw ) {
			delete next[ method ];
		} else {
			next[ method ] = Number( raw );
		}

		onOverridesChange( next );
	};

	return (
		<Question
			title={ __(
				'How long before an order leaves you?',
				'post-purchase-hub'
			) }
			help={ __(
				'Business days between payment and dispatch. This is what turns an order date into “arrives Tuesday to Thursday” — and the non-working days come from your own settings, not from a guess.',
				'post-purchase-hub'
			) }
		>
			<div className="pph-setup__field">
				<label
					className="pph-setup__field-label"
					htmlFor="pph-handling-days"
				>
					{ __( 'Handling time', 'post-purchase-hub' ) }
				</label>
				<div className="pph-setup__field-inline">
					<input
						id="pph-handling-days"
						type="number"
						inputMode="numeric"
						min={ bounds.min }
						max={ bounds.max }
						value={ days }
						data-pph-wizard-handling
						onChange={ ( event ) =>
							onDaysChange( event.target.value )
						}
					/>
					<span className="pph-setup__field-suffix">
						{ __( 'business days', 'post-purchase-hub' ) }
					</span>
				</div>
			</div>

			{ methods.length > 0 && (
				<details className="pph-setup__disclosure">
					<summary>
						{ __(
							'Some shipping methods take longer',
							'post-purchase-hub'
						) }
					</summary>
					<p className="pph-setup__help">
						{ __(
							'Leave a box blank to use the handling time above.',
							'post-purchase-hub'
						) }
					</p>
					{ methods.map( ( method ) => (
						<div className="pph-setup__field" key={ method.value }>
							<label
								className="pph-setup__field-label"
								htmlFor={ `pph-override-${ method.value }` }
							>
								{ method.label }
							</label>
							<div className="pph-setup__field-inline">
								<input
									id={ `pph-override-${ method.value }` }
									type="number"
									inputMode="numeric"
									min={ bounds.min }
									max={ bounds.max }
									value={ overrides[ method.value ] ?? '' }
									onChange={ ( event ) =>
										setOverride(
											method.value,
											event.target.value
										)
									}
								/>
								<span className="pph-setup__field-suffix">
									{ __(
										'business days',
										'post-purchase-hub'
									) }
								</span>
							</div>
						</div>
					) ) }
				</details>
			) }
		</Question>
	);
}
