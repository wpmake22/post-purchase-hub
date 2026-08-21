<?php
/**
 * Pro edition bootstrap.
 *
 * @package PostPurchaseHub\Pro
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Pro;

use PostPurchaseHub\Plugin;

/**
 * Attaches Pro's features to core's extension points.
 *
 * A stub on purpose. This milestone builds the boundary and the pipeline and
 * verifies them empty, because a pipeline proven on a stub is worth more than
 * one debugged later with three features already on the wrong side of the line.
 *
 * Everything Pro adds arrives through core's public surface — documented
 * filters, the action registry, interfaces and template overrides. If a feature
 * cannot be built that way, core's surface is wrong and the fix belongs there.
 *
 * @since 0.5.0
 */
final class Bootstrap {

	/**
	 * Core service container.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Constructor.
	 *
	 * @since 0.5.0
	 *
	 * @param Plugin $plugin Core service container.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Registers Pro's hooks.
	 *
	 * @since 0.5.0
	 * @return void
	 */
	public function register(): void {
		$this->plugin->logger()->debug( 'Pro edition loaded.' );
	}
}
