<?php
/**
 * Renders the cancellation-request modal once per page.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Frontend;

use PostPurchaseHub\Actions\Cancel;

/**
 * Draws `templates/partials/request-modal.php` in the footer of any page
 * this plugin's assets already load on.
 *
 * Reuses `Assets::is_required()` rather than its own copy of that gating
 * logic: the modal only ever matters where a "Request cancellation" trigger
 * could appear, which is exactly where the assets that make it interactive
 * are already scoped to load.
 *
 * @since 0.8.0
 */
final class RequestModalRenderer {

	/**
	 * Constructor.
	 *
	 * @since 0.8.0
	 *
	 * @param TemplateLoader $templates Template loader.
	 * @param Assets         $assets    Decides whether this page needs the modal.
	 */
	public function __construct( private TemplateLoader $templates, private Assets $assets ) {}

	/**
	 * Wires the rendering hook.
	 *
	 * @since 0.8.0
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_footer', array( $this, 'render' ) );
	}

	/**
	 * Renders the modal when this page needs it.
	 *
	 * @since 0.8.0
	 * @return void
	 */
	public function render(): void {
		if ( ! $this->assets->is_required() ) {
			return;
		}

		$this->templates->render(
			'partials/request-modal.php',
			array(
				'modal' => array(
					'reason_codes'            => Cancel::reason_code_labels(),
					'expected_response_hours' => Cancel::response_time_hours(),
				),
			)
		);
	}
}
