/**
 * Editor registration for the pph/orders block.
 *
 * The block renders server-side, so the editor shows a static placeholder
 * rather than a preview: rendering it here would mean duplicating the view
 * model in JavaScript, and the orders it lists belong to the visitor, not to
 * the administrator editing the page.
 */

import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';

function Edit() {
	return (
		<div { ...useBlockProps() }>
			<p>
				{ __(
					'Order timeline — each signed-in customer sees their own orders here.',
					'wpmake-post-purchase-hub'
				) }
			</p>
		</div>
	);
}

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => null,
} );
