/**
 * Shared setup helper for the end-to-end suite.
 *
 * Since M14 this plugin renders nothing on the storefront until the setup
 * wizard has been completed, so every spec that drives a customer-facing page
 * has to state that premise rather than assume it. The wizard's own spec does
 * the opposite: it deletes this state to test the silence before setup.
 */

/**
 * Marks setup complete, so the storefront renders.
 *
 * Written straight to the option through WP-CLI rather than by clicking through
 * the wizard: these specs are about the timeline, reorder and guest lookup, and
 * a four-step click-through in each of their fixtures would be four more things
 * that can fail for reasons unrelated to what is under test.
 *
 * @param {Object} requestUtils Playwright request utils.
 * @return {Promise<void>}
 */
async function completeSetup(requestUtils) {
	const php =
		"update_option( 'wpmphub_setup_state', array( 'step' => 5, 'completed_at' => gmdate( 'Y-m-d H:i:s' ) ), false );";

	await requestUtils.rest({
		method: "POST",
		path: "/wp-cli/v1/run",
		data: { command: `eval "${php}"` },
	});
}

module.exports = { completeSetup };
