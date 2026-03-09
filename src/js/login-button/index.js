/**
 * WorkOS Login Button — Block registration.
 *
 * @package WorkOS
 */
import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import Edit from './edit';
import Save from './save';
import icon from './icon';

registerBlockType(
	metadata.name,
	{
		...metadata,
		icon,
		edit: Edit,
		save: Save,
	}
);
