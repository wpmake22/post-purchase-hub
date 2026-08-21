<?php
/**
 * Free edition bootstrap.
 *
 * @package PostPurchaseHub\Free
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Free;

use PostPurchaseHub\Plugin;

/**
 * Registers the locked teasers that stand where Pro's features would be.
 *
 * A stub on purpose, like its Pro counterpart. When settings arrive, this is
 * what registers a disabled field with the same key Pro would register a real
 * one under, so both editions render the same shape and core never has to know
 * which it is running inside.
 *
 * This is the only place in the codebase where marketing lives. Keep it small,
 * and keep it honest: hard rule 19 says the free plugin must be coherent alone,
 * so a teaser explains what Pro adds and never leaves a dead control behind.
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
	 * Registers the free edition's hooks.
	 *
	 * @since 0.5.0
	 * @return void
	 */
	public function register(): void {
		$this->plugin->logger()->debug( 'Free edition loaded.' );
	}
}
