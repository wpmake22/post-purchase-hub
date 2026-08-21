/**
 * Playwright configuration.
 *
 * Extends the WordPress default so the wp-env credentials, storage state and
 * artifact paths come for free, and adds the two viewports the acceptance
 * criteria name: a desktop width and the 375px phone that most of this
 * plugin's traffic actually arrives on.
 */

const baseConfig = require( '@wordpress/scripts/config/playwright.config' );

module.exports = {
	...baseConfig,
	testDir: './tests/e2e',
	projects: [
		{
			name: 'desktop',
			use: { ...baseConfig.use, viewport: { width: 1440, height: 900 } },
		},
		{
			name: 'mobile',
			use: { ...baseConfig.use, viewport: { width: 375, height: 812 } },
		},
	],
};
