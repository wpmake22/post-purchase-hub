<?php
/**
 * The wizard's chrome: the form, the progress counter and the ways out.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Admin;

use PostPurchaseHub\Install\SetupState;

/**
 * Every screen is the same shape, and this is that shape.
 *
 * A heading, one question — drawn by `WizardSteps` — and three ways out:
 * answer it, skip it, or go back. Skipping is offered on every step because a
 * merchant who cannot answer "what is your handling time" inside two minutes
 * must still be able to reach the end: a default they can change later beats an
 * abandoned setup that leaves the storefront dark.
 *
 * @since 0.14.0
 */
final class WizardScreen {

	/**
	 * Constructor.
	 *
	 * @since 0.14.0
	 *
	 * @param WizardSteps $steps The body of whichever question is being asked.
	 */
	public function __construct( private WizardSteps $steps ) {}

	/**
	 * Renders one step.
	 *
	 * @since 0.14.0
	 *
	 * @param int                  $step    Step to draw.
	 * @param array<string, mixed> $context Prepared by Wizard::context().
	 * @return void
	 */
	public function render( int $step, array $context ): void {
		printf(
			'<div class="wrap pph-wizard" data-pph-wizard data-pph-wizard-step="%s">',
			esc_attr( (string) $step )
		);

		printf( '<h1>%s</h1>', esc_html__( 'Set up Post-Purchase Hub', 'post-purchase-hub' ) );

		$this->render_progress( $step );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-pph-wizard-form>';

		wp_nonce_field( Wizard::NONCE_ACTION );

		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( Wizard::SAVE_ACTION ) );
		printf( '<input type="hidden" name="%1$s" value="%2$d" />', esc_attr( Wizard::STEP_FIELD ), (int) $step );

		$this->render_step( $step, $context );
		$this->render_navigation( $step );

		echo '</form></div>';
	}

	/**
	 * The step counter.
	 *
	 * @since 0.14.0
	 *
	 * @param int $step Current step.
	 * @return void
	 */
	private function render_progress( int $step ): void {
		echo '<ol class="pph-wizard__progress" data-pph-wizard-progress>';

		foreach ( self::titles() as $number => $title ) {
			$state = '';

			if ( $number === $step ) {
				$state = ' is-current';
			} elseif ( $number < $step ) {
				$state = ' is-done';
			}

			printf(
				'<li class="pph-wizard__progress-item%1$s">%2$s</li>',
				esc_attr( $state ),
				esc_html( $title )
			);
		}

		echo '</ol>';
	}

	/**
	 * The short title of each step.
	 *
	 * @since 0.14.0
	 *
	 * @return array<int, string>
	 */
	private static function titles(): array {
		return array(
			1                      => __( 'Statuses', 'post-purchase-hub' ),
			2                      => __( 'Handling time', 'post-purchase-hub' ),
			3                      => __( 'Tracking', 'post-purchase-hub' ),
			4                      => __( 'Display', 'post-purchase-hub' ),
			SetupState::FINAL_STEP => __( 'Actions', 'post-purchase-hub' ),
		);
	}

	/**
	 * Dispatches to the body of one question.
	 *
	 * @since 0.14.0
	 *
	 * @param int                  $step    Step to draw.
	 * @param array<string, mixed> $context Prepared context.
	 * @return void
	 */
	private function render_step( int $step, array $context ): void {
		switch ( $step ) {
			case 1:
				$this->steps->statuses( $context );
				return;
			case 2:
				$this->steps->handling( $context );
				return;
			case 3:
				$this->steps->tracking( $context );
				return;
			case 4:
				$this->steps->display( $context );
				return;
			default:
				$this->steps->actions( $context );
		}
	}

	/**
	 * Answer, skip, or go back.
	 *
	 * @since 0.14.0
	 *
	 * @param int $step Current step.
	 * @return void
	 */
	private function render_navigation( int $step ): void {
		$last = SetupState::FINAL_STEP === $step;

		echo '<p class="pph-wizard__actions">';

		printf(
			'<button type="submit" class="button button-primary" data-pph-wizard-continue>%s</button> ',
			esc_html( $last ? __( 'Finish and go live', 'post-purchase-hub' ) : __( 'Continue', 'post-purchase-hub' ) )
		);

		printf(
			'<button type="submit" class="button-link" name="%1$s" value="1" data-pph-wizard-skip>%2$s</button>',
			esc_attr( Wizard::SKIP_FIELD ),
			esc_html( $last ? __( 'Finish with the defaults', 'post-purchase-hub' ) : __( 'Skip this step', 'post-purchase-hub' ) )
		);

		if ( $step > SetupState::FIRST_STEP ) {
			printf(
				' <a class="button-link" href="%1$s" data-pph-wizard-back>%2$s</a>',
				esc_url( Wizard::url( $step - 1 ) ),
				esc_html__( 'Back', 'post-purchase-hub' )
			);
		}

		echo '</p>';
	}
}
