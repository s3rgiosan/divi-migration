/**
 * Playwright settings for the end-to-end tests, which run against an
 * existing WordPress site (see tests/e2e/serializer.spec.js).
 */
const { defineConfig } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: './tests/e2e',
	timeout: 120000,
	reporter: 'list',
	use: {
		baseURL: process.env.WP_BASE_URL || 'http://localhost:8888',
		ignoreHTTPSErrors: true,
	},
} );
