<?php
/**
 * LinkInjector unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Emails;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Emails\AbstractEmail;
use PostPurchaseHub\Emails\LinkInjector;
use PostPurchaseHub\Security\TokenService;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * Covers the opt-in gating: which core email ids are eligible, the filter
 * that widens or narrows them, and every reason `maybe_inject()` bails
 * before touching a target email's own settings.
 *
 * Rendering itself (`wc_get_template()`) is not exercised here — that is
 * integration-suite territory, same as every other template in this
 * milestone.
 *
 * @since 0.10.0
 *
 * @covers \PostPurchaseHub\Emails\LinkInjector
 */
final class LinkInjectorTest extends TestCase {

	/**
	 * Resets the in-memory WordPress state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();
	}

	/**
	 * The default target list, absent any filter.
	 *
	 * @return void
	 */
	public function test_default_targets(): void {
		$this->assertSame(
			array( 'customer_processing_order', 'customer_on_hold_order', 'customer_completed_order' ),
			LinkInjector::targets()
		);
	}

	/**
	 * The pph_email_link_injection_targets filter can narrow or widen the list.
	 *
	 * @return void
	 */
	public function test_filter_can_replace_the_target_list(): void {
		add_filter(
			'pph_email_link_injection_targets',
			static function () {
				return array( 'customer_invoice' );
			}
		);

		$this->assertSame( array( 'customer_invoice' ), LinkInjector::targets() );
	}

	/**
	 * A filter returning junk falls back to an empty list rather than widening
	 * what gets a checkbox added to it.
	 *
	 * @return void
	 */
	public function test_a_non_array_filter_result_yields_no_targets(): void {
		add_filter(
			'pph_email_link_injection_targets',
			static function () {
				return false;
			}
		);

		$this->assertSame( array(), LinkInjector::targets() );
	}

	/**
	 * Adds exactly one, off-by-default checkbox to a target's own settings.
	 *
	 * @return void
	 */
	public function test_add_settings_field_adds_an_off_by_default_checkbox(): void {
		$injector = new LinkInjector( new TokenService() );

		$fields = $injector->add_settings_field( array( 'enabled' => array( 'type' => 'checkbox' ) ) );

		$this->assertArrayHasKey( LinkInjector::SETTINGS_FIELD, $fields );
		$this->assertSame( 'checkbox', $fields[ LinkInjector::SETTINGS_FIELD ]['type'] );
		$this->assertSame( 'no', $fields[ LinkInjector::SETTINGS_FIELD ]['default'] );
	}

	/**
	 * Never injects into an admin-facing copy of the email.
	 *
	 * @return void
	 */
	public function test_never_injects_when_sent_to_admin(): void {
		$email = $this->opted_in_target_email();

		( new LinkInjector( new TokenService() ) )->maybe_inject( new \WC_Order( 1 ), true, false, $email );

		$this->assertNothingInjected();
	}

	/**
	 * Never injects into an email id outside the target list.
	 *
	 * @return void
	 */
	public function test_never_injects_outside_the_target_list(): void {
		$email     = $this->opted_in_target_email();
		$email->id = 'some_other_email';

		( new LinkInjector( new TokenService() ) )->maybe_inject( new \WC_Order( 1 ), false, false, $email );

		$this->assertNothingInjected();
	}

	/**
	 * Never injects when the target email has not opted in.
	 *
	 * @return void
	 */
	public function test_never_injects_when_not_opted_in(): void {
		$id = 'customer_processing_order';

		FakeWordPress::$options[ 'woocommerce_' . $id . '_settings' ] = array( LinkInjector::SETTINGS_FIELD => 'no' );
		$email = $this->target_email( $id );

		( new LinkInjector( new TokenService() ) )->maybe_inject( new \WC_Order( 1 ), false, false, $email );

		$this->assertNothingInjected();
	}

	/**
	 * Never injects into one of this plugin's own emails, even if a filter
	 * misconfigures the target list to include one.
	 *
	 * @return void
	 */
	public function test_never_injects_into_this_plugins_own_emails(): void {
		$own = new class() extends AbstractEmail {
			/**
			 * Minimal own-email stand-in.
			 */
			public function __construct() {
				$this->id             = 'customer_processing_order';
				$this->customer_email = true;

				parent::__construct();
			}

			/**
			 * No fields; this stand-in only needs to declare an id.
			 *
			 * @return void
			 */
			public function init_form_fields(): void {}
		};

		FakeWordPress::$options[ 'woocommerce_' . $own->id . '_settings' ] = array( LinkInjector::SETTINGS_FIELD => 'yes' );

		( new LinkInjector( new TokenService() ) )->maybe_inject( new \WC_Order( 1 ), false, false, $own );

		$this->assertNothingInjected();
	}

	/**
	 * The one path that actually renders: admin copy false, target email,
	 * opted in.
	 *
	 * @return void
	 */
	public function test_injects_into_an_opted_in_target(): void {
		FakeWordPress::$options['pph_token_secret'] = base64_encode( random_bytes( 64 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test fixture, not obfuscation.

		$order = new \WC_Order( 1 );
		$order->set_order_key( 'wc_order_abc' );
		$email = $this->opted_in_target_email();

		( new LinkInjector( new TokenService() ) )->maybe_inject( $order, false, false, $email );

		$this->assertCount( 1, FakeWordPress::$rendered_templates );
		$this->assertSame( 'emails/partials/secure-link-notice.php', FakeWordPress::$rendered_templates[0]['name'] );
		$this->assertFalse( FakeWordPress::$rendered_templates[0]['args']['plain_text'] );
	}

	/**
	 * An install with no token secret injects nothing, and does not throw.
	 *
	 * This runs inside WooCommerce's rendering of an order confirmation the
	 * customer is waiting on. `TokenService::issue()` throws when it has no
	 * secret, and letting that escape here would turn a missing option into a
	 * fatal partway through a transactional send.
	 *
	 * @return void
	 */
	public function test_an_install_without_a_token_secret_injects_nothing(): void {
		unset( FakeWordPress::$options['pph_token_secret'] );

		$order = new \WC_Order( 1 );
		$order->set_order_key( 'wc_order_abc' );

		( new LinkInjector( new TokenService() ) )->maybe_inject( $order, false, false, $this->opted_in_target_email() );

		$this->assertSame( array(), FakeWordPress::$rendered_templates );
	}

	/**
	 * A stand-in WC_Email at one of the default target ids, opted in.
	 *
	 * @return \WC_Email
	 */
	private function opted_in_target_email(): \WC_Email {
		$id = 'customer_processing_order';

		FakeWordPress::$options[ 'woocommerce_' . $id . '_settings' ] = array( LinkInjector::SETTINGS_FIELD => 'yes' );

		return $this->target_email( $id );
	}

	/**
	 * A bare WC_Email instance carrying the given id, settled through the
	 * stub's own settings resolution.
	 *
	 * @param string $id Email id.
	 * @return \WC_Email
	 */
	private function target_email( string $id ): \WC_Email {
		$email = new class() extends \WC_Email {
			/**
			 * Carries only the one field this test cares about.
			 *
			 * @return void
			 */
			public function init_form_fields(): void {
				$this->form_fields = array(
					LinkInjector::SETTINGS_FIELD => array( 'default' => 'no' ),
				);
			}
		};

		$email->id = $id;
		$email->init_settings();

		return $email;
	}

	/**
	 * Asserts the most recent maybe_inject() call rendered nothing.
	 *
	 * @return void
	 */
	private function assertNothingInjected(): void {
		$this->assertSame( array(), FakeWordPress::$rendered_templates );
	}
}
