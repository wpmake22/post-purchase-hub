/**
 * The fixed progress header.
 *
 * Steps behind the current one are clickable, because a merchant who wants to
 * change an answer they already gave should not have to finish the wizard to do
 * it. Steps ahead are not: reaching one means answering or skipping the screens
 * between, and a stepper that let you jump the queue would be a stepper that
 * silently skipped questions without recording that it had.
 */

import { __, sprintf } from '@wordpress/i18n';

/**
 * Renders the header.
 *
 * @param {Object}   props             Component props.
 * @param {Array}    props.steps       Visible steps, as `{ id, label, number }`.
 * @param {number}   props.current     Number of the step being shown.
 * @param {Function} props.onStepClick Called with a step number.
 * @param {Function} props.onExit      Called when the merchant leaves the wizard.
 * @return {Element} The header.
 */
export default function Stepper( { steps, current, onStepClick, onExit } ) {
	return (
		<header className="wpmphub-setup__header" data-wpmphub-wizard-progress>
			<p className="wpmphub-setup__brand">
				{ __( 'Post-Purchase Hub', 'wpmake-post-purchase-hub' ) }
			</p>

			<ol className="wpmphub-setup__steps">
				{ steps.map( ( step, index ) => {
					const done = step.number < current;
					const isCurrent = step.number === current;

					return (
						<li
							key={ step.id }
							className={ [
								'wpmphub-setup__step',
								done ? 'is-done' : '',
								isCurrent ? 'is-current' : '',
							]
								.filter( Boolean )
								.join( ' ' ) }
							data-wpmphub-wizard-step-item={ step.id }
						>
							<button
								type="button"
								className="wpmphub-setup__step-button"
								disabled={ ! done }
								aria-current={ isCurrent ? 'step' : undefined }
								onClick={ () => onStepClick( step.number ) }
							>
								<span
									className="wpmphub-setup__step-marker"
									aria-hidden="true"
								>
									{ done ? '✓' : step.number }
								</span>
								<span className="wpmphub-setup__step-label">
									{ step.label }
								</span>
							</button>

							{ index < steps.length - 1 && (
								<span
									className="wpmphub-setup__step-line"
									aria-hidden="true"
								/>
							) }
						</li>
					);
				} ) }
			</ol>

			<button
				type="button"
				className="wpmphub-setup__exit"
				onClick={ onExit }
				data-wpmphub-wizard-exit
				title={ sprintf(
					/* translators: %s: number of steps in the wizard. */
					__(
						'Leave setup. You can come back and finish all %s steps later.',
						'wpmake-post-purchase-hub'
					),
					steps.length
				) }
			>
				<span aria-hidden="true">×</span>
				<span className="screen-reader-text">
					{ __( 'Leave setup', 'wpmake-post-purchase-hub' ) }
				</span>
			</button>
		</header>
	);
}
