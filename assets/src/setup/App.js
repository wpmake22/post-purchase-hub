/**
 * The wizard itself: state, navigation, and one card at a time.
 *
 * Two rules shape everything here. The server owns which step comes next — an
 * answer on the welcome screen can remove steps further along, so the client is
 * told rather than computing it. And nothing reaches `pph_settings` until the
 * last screen, so closing the tab halfway is safe by construction: what a
 * merchant typed is a draft on the server, and their store is untouched.
 *
 * Going backwards is not persisted. It is a correction, not progress, and
 * writing it down would mean a merchant who glances back at step 2 resumes
 * there instead of where they had actually got to.
 */

import { Spinner } from '@wordpress/components';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	fetchContext,
	fetchState,
	finishSetup,
	messageFor,
	saveStep,
	settings,
	skipStep,
} from './api';
import Stepper from './Stepper';
import Actions from './steps/Actions';
import Delivery from './steps/Delivery';
import Display from './steps/Display';
import Finish from './steps/Finish';
import Statuses from './steps/Statuses';
import Tracking from './steps/Tracking';
import Welcome from './steps/Welcome';

/**
 * The answers, seeded from the drafts a previous visit left behind and from the
 * store's live configuration behind those.
 *
 * @param {Object} state   Wizard state from the server.
 * @param {Object} context Reference data from the server.
 * @return {Object} The wizard's working answers.
 */
function initialValues( state, context ) {
	const draft = state.draft || {};
	const stored = state.settings || {};

	return {
		path: state.path,
		statusMap: {
			...( context.live_status_map || {} ),
			...( stored.timeline_status_map || {} ),
			...( draft.timeline_status_map || {} ),
		},
		handlingDays:
			draft.eta_handling_days ??
			stored.eta_handling_days ??
			context.handling.default,
		handlingOverrides:
			draft.eta_handling_days_by_method ??
			stored.eta_handling_days_by_method ??
			{},
		// Filled in for every action rather than left as whatever was stored.
		// The server's sanitiser reads an absent action as off, so a merchant
		// who agrees with the defaults and presses Next must still be sending
		// all four switches — an empty object would silently turn the lot off.
		enabledActions: context.actions.reduce( ( carry, action ) => {
			const known = draft.enabled_actions ?? stored.enabled_actions ?? {};

			return { ...carry, [ action.id ]: false !== known[ action.id ] };
		}, {} ),
		templateMode:
			draft.template_mode ??
			stored.template_mode ??
			( context.display_modes[ 0 ]
				? context.display_modes[ 0 ].value
				: 'additive' ),
	};
}

/**
 * Renders the wizard.
 *
 * @return {Element} The app.
 */
export default function App() {
	const [ state, setState ] = useState( null );
	const [ context, setContext ] = useState( null );
	const [ values, setValues ] = useState( null );
	const [ step, setStep ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		Promise.all( [ fetchState(), fetchContext() ] )
			.then( ( [ nextState, nextContext ] ) => {
				setState( nextState );
				setContext( nextContext );
				setValues( initialValues( nextState, nextContext ) );
				setStep( nextState.current_step );
			} )
			.catch( ( failure ) => setError( messageFor( failure ) ) );
	}, [] );

	/**
	 * Takes the server's word for where the merchant now is.
	 *
	 * @param {Object} next The state a mutation answered with.
	 * @return {void}
	 */
	const accept = useCallback( ( next ) => {
		setState( next );
		setStep( next.current_step );
		setError( '' );
	}, [] );

	/**
	 * Runs one request, holding the buttons while it is in flight.
	 *
	 * @param {Function} request Returns the promise to await.
	 * @return {Promise<void>}
	 */
	const run = useCallback(
		( request ) => {
			setBusy( true );
			setError( '' );

			return request()
				.then( accept )
				.catch( ( failure ) => setError( messageFor( failure ) ) )
				.finally( () => setBusy( false ) );
		},
		[ accept ]
	);

	const steps = useMemo( () => ( state ? state.steps : [] ), [ state ] );

	const current = useMemo(
		() => steps.find( ( item ) => item.id === step ) || steps[ 0 ],
		[ steps, step ]
	);

	const isFinish = current && 'finish' === current.id;

	const update = useCallback(
		( patch ) => setValues( ( previous ) => ( { ...previous, ...patch } ) ),
		[]
	);

	if ( error && ! state ) {
		return (
			<div className="pph-setup pph-setup--message">
				<p className="pph-setup__warning">{ error }</p>
			</div>
		);
	}

	if ( ! state || ! context || ! values || ! current ) {
		return (
			<div className="pph-setup pph-setup--message">
				<Spinner />
			</div>
		);
	}

	/**
	 * The lines the finish screen lists.
	 *
	 * Built from what the merchant actually answered rather than from the whole
	 * settings list, so the summary is about their decisions and not about every
	 * default they never saw.
	 *
	 * @return {Array<string>} Summary lines.
	 */
	const summary = () => {
		const shown = steps.map( ( item ) => item.id );
		const lines = [];

		if ( shown.includes( 'statuses' ) ) {
			const visible = Object.values( values.statusMap ).filter(
				( stage ) => '' !== stage
			).length;

			lines.push(
				`${ __(
					'Timeline stages',
					'wpmake-post-purchase-hub'
				) }: ${ visible }`
			);
		}

		if ( shown.includes( 'delivery' ) ) {
			lines.push(
				`${ __( 'Handling time', 'wpmake-post-purchase-hub' ) }: ${
					values.handlingDays
				}`
			);
		}

		if ( shown.includes( 'actions' ) ) {
			const on = context.actions
				.filter(
					( action ) => false !== values.enabledActions[ action.id ]
				)
				.map( ( action ) => action.label );

			lines.push(
				`${ __( 'Customers can', 'wpmake-post-purchase-hub' ) }: ${
					on.length
						? on.join( ', ' )
						: __( 'nothing yet', 'wpmake-post-purchase-hub' )
				}`
			);
		}

		const mode = context.display_modes.find(
			( option ) => option.value === values.templateMode
		);

		if ( mode ) {
			lines.push(
				`${ __( 'Order pages', 'wpmake-post-purchase-hub' ) }: ${ mode.label }`
			);
		}

		return lines;
	};

	/**
	 * Draws whichever screen is current.
	 *
	 * @return {Element} The screen.
	 */
	const screen = () => {
		switch ( current.id ) {
			case 'welcome':
				return (
					<Welcome
						choices={ context.path_choices }
						value={ values.path }
						store={ settings.storeName }
						onChange={ ( path ) => update( { path } ) }
					/>
				);
			case 'statuses':
				return (
					<Statuses
						statuses={ context.statuses }
						stages={ context.stages }
						detected={ context.detected_statuses }
						value={ values.statusMap }
						onChange={ ( statusMap ) => update( { statusMap } ) }
					/>
				);
			case 'delivery':
				return (
					<Delivery
						bounds={ context.handling }
						methods={ context.shipping_methods }
						days={ values.handlingDays }
						overrides={ values.handlingOverrides }
						onDaysChange={ ( handlingDays ) =>
							update( { handlingDays } )
						}
						onOverridesChange={ ( handlingOverrides ) =>
							update( { handlingOverrides } )
						}
					/>
				);
			case 'tracking':
				return <Tracking tracking={ context.tracking } />;
			case 'actions':
				return (
					<Actions
						actions={ context.actions }
						value={ values.enabledActions }
						onChange={ ( enabledActions ) =>
							update( { enabledActions } )
						}
					/>
				);
			case 'display':
				return (
					<Display
						modes={ context.display_modes }
						value={ values.templateMode }
						conflict={ context.conflict }
						preview={ context.preview }
						onChange={ ( templateMode ) =>
							update( { templateMode } )
						}
					/>
				);
			default:
				return (
					<Finish
						completed={ state.completed }
						summary={ summary() }
						queueUrl={ settings.dashboard }
						exitUrl={ state.settings_url || settings.exitUrl }
					/>
				);
		}
	};

	const goBack = () => {
		const previous = steps[ current.number - 2 ];

		if ( previous ) {
			setStep( previous.id );
			setError( '' );
		}
	};

	const exit = () => {
		window.location.href = settings.exitUrl;
	};

	const jump = ( number ) => {
		const target = steps[ number - 1 ];

		if ( target && number < current.number ) {
			setStep( target.id );
			setError( '' );
		}
	};

	return (
		<div className="pph-setup" data-pph-wizard-step={ current.id }>
			<Stepper
				steps={ steps }
				current={ current.number }
				onStepClick={ jump }
				onExit={ exit }
			/>

			<main className="pph-setup__main">
				<div className="pph-setup__card">
					{ screen() }

					{ error && (
						<p className="pph-setup__warning" role="alert">
							{ error }
						</p>
					) }

					{ ! ( isFinish && state.completed ) && (
						<footer className="pph-setup__footer">
							{ current.number > 1 ? (
								<button
									type="button"
									className="pph-setup__link-button"
									onClick={ goBack }
									disabled={ busy }
									data-pph-wizard-back
								>
									{ __( 'Back', 'wpmake-post-purchase-hub' ) }
								</button>
							) : (
								<span />
							) }

							<div className="pph-setup__footer-actions">
								{ ! isFinish && (
									<button
										type="button"
										className="pph-setup__link-button"
										onClick={ () =>
											run( () => skipStep( current.id ) )
										}
										disabled={ busy }
										data-pph-wizard-skip
									>
										{ __(
											'Skip this step',
											'wpmake-post-purchase-hub'
										) }
									</button>
								) }

								<button
									type="button"
									className="pph-setup__button"
									onClick={ () =>
										run( () =>
											isFinish
												? finishSetup()
												: saveStep( current.id, values )
										)
									}
									disabled={ busy }
									data-pph-wizard-continue
								>
									{ isFinish
										? __(
												'Finish and go live',
												'wpmake-post-purchase-hub'
										  )
										: __( 'Next', 'wpmake-post-purchase-hub' ) }
								</button>
							</div>
						</footer>
					) }
				</div>
			</main>
		</div>
	);
}
