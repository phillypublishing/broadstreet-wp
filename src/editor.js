import { registerPlugin } from '@wordpress/plugins';

/**
 * Reserve a stable editor extension entry point for Broadstreet features.
 *
 * Individual editor panels will be added by their own migrations. Keeping the
 * foundation renderless makes this build harness safe to ship independently.
 */
registerPlugin( 'broadstreet-editor', {
	render: () => null,
} );
