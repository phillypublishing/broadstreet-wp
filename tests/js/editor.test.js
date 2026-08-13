import { registerPlugin } from '@wordpress/plugins';

import '../../src/editor';

jest.mock( '@wordpress/plugins', () => ( {
	registerPlugin: jest.fn(),
} ) );

describe( 'Broadstreet editor extension', () => {
	test( 'registers a renderable editor plugin', () => {
		expect( registerPlugin ).toHaveBeenCalledTimes( 1 );

		const [ name, settings ] = registerPlugin.mock.calls[ 0 ];

		expect( name ).toBe( 'broadstreet-editor' );
		expect( settings.render ).toEqual( expect.any( Function ) );
		expect( settings.render() ).toBeNull();
	} );
} );
