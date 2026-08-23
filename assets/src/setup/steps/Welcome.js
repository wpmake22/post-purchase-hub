/**
 * Step 1 — what the merchant is here for.
 *
 * The one branching question in the wizard. Every answer leads to a working
 * store; the shorter paths just stop asking about the half this merchant said
 * they do not care about, and leave those settings on the defaults they would
 * have got by skipping the questions anyway.
 */

import { __, sprintf } from '@wordpress/i18n';
import Question from '../Question';

/**
 * Renders the welcome screen.
 *
 * @param {Object}   props          Component props.
 * @param {Array}    props.choices  Paths, as `{ id, label, description }`.
 * @param {string}   props.value    Selected path id.
 * @param {Function} props.onChange Called with a path id.
 * @param {string}   props.store    The store's name, when it has one.
 * @return {Element} The screen.
 */
export default function Welcome( { choices, value, onChange, store } ) {
	const title = store
		? sprintf(
				/* translators: %s: the store's name. */
				__( 'Let’s get %s set up', 'post-purchase-hub' ),
				store
		  )
		: __( 'Let’s get your store set up', 'post-purchase-hub' );

	return (
		<Question
			title={ title }
			help={ __(
				'Nothing is showing to your customers yet, and nothing will change until you finish. Pick what matters most and this asks only the questions that go with it — every answer is changeable later on the settings screen.',
				'post-purchase-hub'
			) }
		>
			<div className="pph-setup__choices" role="radiogroup">
				{ choices.map( ( choice ) => (
					<div
						key={ choice.id }
						className={ `pph-setup__choice ${
							value === choice.id ? 'is-selected' : ''
						}` }
						data-pph-wizard-path={ choice.id }
					>
						<input
							id={ `pph-path-${ choice.id }` }
							type="radio"
							name="pph-setup-path"
							value={ choice.id }
							checked={ value === choice.id }
							onChange={ () => onChange( choice.id ) }
						/>
						<label
							className="pph-setup__choice-body"
							htmlFor={ `pph-path-${ choice.id }` }
						>
							<span className="pph-setup__choice-label">
								{ choice.label }
							</span>
							<span className="pph-setup__choice-help">
								{ choice.description }
							</span>
						</label>
					</div>
				) ) }
			</div>
		</Question>
	);
}
