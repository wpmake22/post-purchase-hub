/**
 * Shared frontend entry point.
 *
 * Carries the stylesheet for every surface this plugin renders — the timeline
 * on the orders list and the order detail page, the shortcode and the block.
 * Behaviour arrives with the request modal in M08; until then this file exists
 * so the styles have a bundle and Frontend\Assets has a manifest to version
 * against.
 */

import './styles/frontend.scss';
