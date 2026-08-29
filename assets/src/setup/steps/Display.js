/**
 * Additive or full replacement, with the real thing to look at.
 *
 * The preview is markup rendered by the storefront's own renderer and handed
 * over by the REST layer, not a mock-up drawn here: a preview that draws its own
 * markup is a preview that can be wrong about the page it is previewing.
 */

import { __ } from '@wordpress/i18n';
import Question from '../Question';

/**
 * Renders the display-mode screen.
 *
 * @param {Object}   props          Component props.
 * @param {Array}    props.modes    Modes, as `{ value, label }`.
 * @param {string}   props.value    Selected mode.
 * @param {string}   props.conflict Warning text, empty when there is none.
 * @param {string}   props.preview  Preview markup, already sanitised server-side.
 * @param {Function} props.onChange Called with a mode.
 * @return {Element} The screen.
 */
export default function Display( {
	modes,
	value,
	conflict,
	preview,
	onChange,
} ) {
	return (
		<Question
			title={ __(
				'How should this appear on your order pages?',
				'wpmake-post-purchase-hub'
			) }
			help={ __(
				'Additive adds these sections to the pages your theme already draws. Full replacement takes the order page over, which looks different in every theme — and is the single biggest source of support tickets for plugins like this one.',
				'wpmake-post-purchase-hub'
			) }
		>
			{ conflict && (
				<p
					className="wpmphub-setup__warning"
					data-wpmphub-wizard-conflict
					role="status"
				>
					{ conflict }
				</p>
			) }

			<div className="wpmphub-setup__choices" role="radiogroup">
				{ modes.map( ( mode ) => (
					<div
						key={ mode.value }
						className={ `wpmphub-setup__choice ${
							value === mode.value ? 'is-selected' : ''
						}` }
						data-wpmphub-wizard-mode={ mode.value }
					>
						<input
							id={ `wpmphub-mode-${ mode.value }` }
							type="radio"
							name="wpmphub-setup-mode"
							value={ mode.value }
							checked={ value === mode.value }
							onChange={ () => onChange( mode.value ) }
						/>
						<label
							className="wpmphub-setup__choice-body"
							htmlFor={ `wpmphub-mode-${ mode.value }` }
						>
							<span className="wpmphub-setup__choice-label">
								{ mode.label }
							</span>
						</label>
					</div>
				) ) }
			</div>

			<h2 className="wpmphub-setup__subheading">
				{ __( 'What your customers will see', 'wpmake-post-purchase-hub' ) }
			</h2>

			<div
				className="wpmphub-setup__preview"
				data-wpmphub-wizard-preview
				// The renderer escapes at output and the REST layer runs the
				// result through wp_kses_post() on the way here, because this
				// string leaves the one place escaping is normally proved.
				dangerouslySetInnerHTML={ { __html: preview } }
			/>
		</Question>
	);
}
