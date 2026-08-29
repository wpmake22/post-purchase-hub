/**
 * Editor registration for the wpmphub/order-lookup block.
 *
 * Server-rendered, like the orders block, and for a second reason: the form
 * disappears entirely on a store that has not enabled guest lookup, which
 * markup saved into a post could not do.
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
					'Order lookup — a customer enters an order number and billing email, and a secure link is emailed to the address on the order.',
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
