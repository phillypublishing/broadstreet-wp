import '@testing-library/jest-dom';
import {
	act,
	fireEvent,
	render,
	screen,
	waitFor,
} from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import { useDispatch, useSelect } from '@wordpress/data';
// The configured classic JSX transform consumes this import during tests.
// eslint-disable-next-line no-unused-vars
import { createElement } from '@wordpress/element';
import { registerPlugin } from '@wordpress/plugins';

import {
	AD_VISIBILITY_KEY,
	BroadstreetAdVisibilityPanel,
	BroadstreetSponsorPanel,
	BroadstreetDocumentSettings,
	BroadstreetZoneInfoPanel,
	SPONSOR_ADVERTISER_KEY,
	SPONSOR_ENABLED_KEY,
} from '../../src/editor';

jest.mock(
	'@wordpress/api-fetch',
	() => ( {
		__esModule: true,
		default: jest.fn(),
	} ),
	{ virtual: true }
);

jest.mock( '@wordpress/plugins', () => ( {
	registerPlugin: jest.fn(),
} ) );

jest.mock(
	'@wordpress/editor',
	() => ( {
		PluginDocumentSettingPanel: ( { children, title } ) => (
			<section aria-label={ title }>{ children }</section>
		),
	} ),
	{ virtual: true }
);

jest.mock( '@wordpress/components', () => ( {
	Button: ( { children, isBusy, variant, ...props } ) => (
		<button type="button" { ...props }>
			{ children }
		</button>
	),
	Notice: ( { children } ) => <div role="alert">{ children }</div>,
	SelectControl: ( { label, onChange, options, ...props } ) => (
		<label htmlFor="broadstreet-test-select">
			{ label }
			<select
				id="broadstreet-test-select"
				onChange={ ( event ) => onChange( event.target.value ) }
				{ ...props }
			>
				{ options.map( ( option ) => (
					<option key={ option.value } value={ option.value }>
						{ option.label }
					</option>
				) ) }
			</select>
		</label>
	),
	Spinner: () => <span>Loading…</span>,
	TextControl: ( { label, onChange, ...props } ) => (
		<label htmlFor="broadstreet-test-text">
			{ label }
			<input
				id="broadstreet-test-text"
				onChange={ ( event ) => onChange( event.target.value ) }
				{ ...props }
			/>
		</label>
	),
	ToggleControl: ( { label, checked, onChange } ) => {
		const id = `broadstreet-test-toggle-${ label
			.toLowerCase()
			.replaceAll( ' ', '-' ) }`;
		return (
			<label htmlFor={ id }>
				{ label }
				<input
					id={ id }
					type="checkbox"
					checked={ checked }
					onChange={ ( event ) => onChange( event.target.checked ) }
				/>
			</label>
		);
	},
} ) );

let editorState;
let editPost;

jest.mock( '@wordpress/data', () => ( {
	useDispatch: jest.fn(),
	useSelect: jest.fn(),
} ) );

const selectEditor = () => ( {
	getCurrentPostId: () => editorState.postId,
	getCurrentPostType: () => editorState.postType,
	getEditedPostAttribute: ( attribute ) =>
		attribute === 'meta' ? editorState.meta : undefined,
	isAutosavingPost: () => editorState.isAutosaving,
	isSavingPost: () => editorState.isSaving,
} );

const installStoreMocks = () => {
	useSelect.mockImplementation( ( callback ) =>
		callback( ( storeName ) =>
			storeName === 'core/editor' ? selectEditor() : undefined
		)
	);
	useDispatch.mockImplementation( () => ( { editPost } ) );
};

const installApiResponses = ( overrides = {} ) => {
	apiFetch.mockImplementation( ( request ) => {
		if ( request.path.startsWith( '/broadstreet/v1/zones' ) ) {
			return Promise.resolve( [
				{
					id: '3',
					name: 'Article Inline',
					shortcode: '[broadstreet zone="3"]',
				},
			] );
		}

		if ( request.path.startsWith( '/broadstreet/v1/advertisers' ) ) {
			if ( request.method === 'POST' ) {
				return Promise.resolve( { id: '41', name: request.data.name } );
			}

			return Promise.resolve( [
				{ id: '3', name: 'Alpha' },
				{ id: '20', name: 'Zulu' },
			] );
		}

		if ( request.path.startsWith( '/broadstreet/v1/sponsor-status' ) ) {
			return Promise.resolve( {
				state: 'synced',
				message: 'Broadstreet tracking is synchronized.',
				retryable: false,
			} );
		}

		return Promise.reject( new Error( 'Unexpected request' ) );
	} );

	if ( overrides.implementation ) {
		apiFetch.mockImplementation( overrides.implementation );
	}
};

const deferred = () => {
	let resolve;
	let reject;
	const promise = new Promise( ( promiseResolve, promiseReject ) => {
		resolve = promiseResolve;
		reject = promiseReject;
	} );
	return { promise, reject, resolve };
};

describe( 'Broadstreet sponsored-content editor extension', () => {
	beforeEach( () => {
		apiFetch.mockClear();
		editorState = {
			postId: 42,
			postType: 'post',
			meta: {
				[ AD_VISIBILITY_KEY ]: false,
				[ SPONSOR_ENABLED_KEY ]: '1',
				[ SPONSOR_ADVERTISER_KEY ]: '20',
			},
			isSaving: false,
			isAutosaving: false,
		};
		editPost = jest.fn( ( patch ) => {
			editorState.meta = { ...editorState.meta, ...patch.meta };
		} );
		installStoreMocks();
		installApiResponses();
	} );

	test( 'registers the shared PluginDocumentSettingPanel from the sole entry point', () => {
		expect( registerPlugin ).toHaveBeenCalledTimes( 1 );

		const [ name, settings ] = registerPlugin.mock.calls[ 0 ];

		expect( name ).toBe( 'broadstreet-editor' );
		expect( settings.render ).toBe( BroadstreetDocumentSettings );
	} );

	test( 'composes all document settings from the shared root', async () => {
		render( <BroadstreetDocumentSettings /> );

		expect(
			screen.getByRole( 'checkbox', { name: 'Disable ads on this post' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'checkbox', { name: 'Performance tracking' } )
		).toBeInTheDocument();
		await screen.findByText( 'Broadstreet tracking is synchronized.' );
		await screen.findByRole( 'combobox', { name: 'Advertiser' } );
		await screen.findByRole( 'region', { name: 'Broadstreet Zone Info' } );
		await screen.findByText( 'Article Inline' );
	} );

	test( 'reads current shared meta directly on every store-driven render', async () => {
		const view = render( <BroadstreetSponsorPanel /> );

		expect(
			screen.getByRole( 'checkbox', { name: 'Performance tracking' } )
		).toBeChecked();
		await waitFor( () =>
			expect(
				screen.getByRole( 'combobox', { name: 'Advertiser' } )
			).toHaveValue( '20' )
		);

		// Simulate a peer editor updating the shared core/editor store.
		editorState.meta = {
			...editorState.meta,
			[ SPONSOR_ENABLED_KEY ]: false,
			[ SPONSOR_ADVERTISER_KEY ]: '3',
		};
		view.rerender( <BroadstreetSponsorPanel /> );

		expect(
			screen.getByRole( 'checkbox', { name: 'Performance tracking' } )
		).not.toBeChecked();
		fireEvent.click(
			screen.getByRole( 'checkbox', { name: 'Performance tracking' } )
		);
		expect( editPost ).toHaveBeenLastCalledWith( {
			meta: { [ SPONSOR_ENABLED_KEY ]: true },
		} );

		view.rerender( <BroadstreetSponsorPanel /> );
		await screen.findByRole( 'combobox', { name: 'Advertiser' } );
		fireEvent.change(
			screen.getByRole( 'combobox', { name: 'Advertiser' } ),
			{
				target: { value: '20' },
			}
		);
		expect( editPost ).toHaveBeenLastCalledWith( {
			meta: { [ SPONSOR_ADVERTISER_KEY ]: '20' },
		} );
	} );

	test( 'creates an advertiser only after the explicit button action', async () => {
		render( <BroadstreetSponsorPanel /> );

		await waitFor( () =>
			expect(
				screen.getByRole( 'combobox', { name: 'Advertiser' } )
			).toBeInTheDocument()
		);
		expect(
			apiFetch.mock.calls.filter(
				( [ request ] ) => request.method === 'POST'
			)
		).toHaveLength( 0 );

		fireEvent.change(
			screen.getByRole( 'textbox', { name: 'New advertiser name' } ),
			{
				target: { value: 'New Sponsor' },
			}
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Create advertiser' } )
		);

		await waitFor( () =>
			expect( editPost ).toHaveBeenCalledWith( {
				meta: { [ SPONSOR_ADVERTISER_KEY ]: '41' },
			} )
		);
		const createRequests = apiFetch.mock.calls.filter(
			( [ request ] ) => request.method === 'POST'
		);
		expect( createRequests ).toEqual( [
			[
				{
					path: '/broadstreet/v1/advertisers',
					method: 'POST',
					data: { post_id: 42, name: 'New Sponsor' },
				},
			],
		] );
	} );

	test( 'never renders raw REST failure details', async () => {
		installApiResponses( {
			implementation: ( request ) => {
				if (
					request.path.startsWith( '/broadstreet/v1/advertisers' )
				) {
					return Promise.reject(
						new Error(
							'access_token=super-secret raw vendor response'
						)
					);
				}

				return Promise.resolve( {
					state: 'idle',
					message: '',
					retryable: false,
				} );
			},
		} );
		render( <BroadstreetSponsorPanel /> );

		await waitFor( () =>
			expect( screen.getByRole( 'alert' ) ).toBeInTheDocument()
		);
		expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
			'Broadstreet advertisers could not be loaded. Try again.'
		);
		expect( screen.queryByText( /super-secret/ ) ).not.toBeInTheDocument();
	} );

	test( 'does not render for unsupported post types without registered shared meta', () => {
		editorState.meta = {};
		const { container } = render( <BroadstreetSponsorPanel /> );

		expect( container ).toBeEmptyDOMElement();
		expect( apiFetch ).not.toHaveBeenCalled();
	} );

	test( 'does not load the advertiser catalog until sponsorship is enabled', async () => {
		editorState.meta[ SPONSOR_ENABLED_KEY ] = false;
		const view = render( <BroadstreetSponsorPanel /> );

		await waitFor( () =>
			expect(
				apiFetch.mock.calls.some( ( [ request ] ) =>
					request.path.startsWith( '/broadstreet/v1/sponsor-status' )
				)
			).toBe( true )
		);
		expect(
			apiFetch.mock.calls.some( ( [ request ] ) =>
				request.path.startsWith( '/broadstreet/v1/advertisers' )
			)
		).toBe( false );

		editorState.meta[ SPONSOR_ENABLED_KEY ] = true;
		view.rerender( <BroadstreetSponsorPanel /> );
		await waitFor( () =>
			expect(
				apiFetch.mock.calls.some( ( [ request ] ) =>
					request.path.startsWith( '/broadstreet/v1/advertisers' )
				)
			).toBe( true )
		);
	} );

	test( 'ignores out-of-order advertiser and status responses', async () => {
		const advertisers42 = deferred();
		const advertisers43 = deferred();
		const status42 = deferred();
		const status43 = deferred();
		apiFetch.mockImplementation( ( request ) => {
			const isPost43 = request.path.includes( 'post_id=43' );
			if ( request.path.startsWith( '/broadstreet/v1/advertisers' ) ) {
				return isPost43 ? advertisers43.promise : advertisers42.promise;
			}
			return isPost43 ? status43.promise : status42.promise;
		} );

		const view = render( <BroadstreetSponsorPanel /> );
		editorState.postId = 43;
		view.rerender( <BroadstreetSponsorPanel /> );

		await act( async () => {
			advertisers43.resolve( [ { id: '43', name: 'Current' } ] );
			status43.resolve( {
				state: 'synced',
				message: 'Current status',
				retryable: false,
				updated_at: 200,
			} );
		} );
		await waitFor( () =>
			expect(
				screen.getByRole( 'combobox', { name: 'Advertiser' } )
			).toHaveTextContent( 'Current' )
		);

		await act( async () => {
			advertisers42.resolve( [ { id: '42', name: 'Stale' } ] );
			status42.resolve( {
				state: 'error',
				message: 'Stale status',
				retryable: true,
				updated_at: 100,
			} );
		} );

		expect( screen.queryByText( 'Stale' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Stale status' ) ).not.toBeInTheDocument();
		expect( screen.getByText( 'Current status' ) ).toBeInTheDocument();
	} );

	test( 'maps known advertiser-create failures without treating all errors as outcome unknown', async () => {
		installApiResponses( {
			implementation: ( request ) => {
				if ( request.method === 'POST' ) {
					return Promise.reject( {
						code: 'broadstreet_advertiser_rejected',
					} );
				}
				if (
					request.path.startsWith( '/broadstreet/v1/advertisers' )
				) {
					return Promise.resolve( [] );
				}
				return Promise.resolve( {
					state: 'idle',
					message: '',
					retryable: false,
				} );
			},
		} );
		render( <BroadstreetSponsorPanel /> );
		await screen.findByRole( 'textbox', { name: 'New advertiser name' } );
		fireEvent.change(
			screen.getByRole( 'textbox', { name: 'New advertiser name' } ),
			{ target: { value: 'Rejected Sponsor' } }
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Create advertiser' } )
		);

		await waitFor( () =>
			expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
				'Broadstreet rejected that advertiser name.'
			)
		);
		expect( screen.getByRole( 'alert' ) ).not.toHaveTextContent(
			'could not confirm advertiser creation'
		);
	} );

	test( 'uses Unicode code-point name bounds', async () => {
		render( <BroadstreetSponsorPanel /> );
		const nameInput = await screen.findByRole( 'textbox', {
			name: 'New advertiser name',
		} );
		expect( nameInput ).not.toHaveAttribute( 'maxlength' );
		fireEvent.change( nameInput, { target: { value: '猫猫猫' } } );
		expect(
			screen.getByRole( 'button', { name: 'Create advertiser' } )
		).toBeEnabled();
	} );

	test( 'polls queued reconciliation status until cron work becomes visible', async () => {
		jest.useFakeTimers();
		let statusReads = 0;
		apiFetch.mockImplementation( ( request ) => {
			if ( request.path.startsWith( '/broadstreet/v1/advertisers' ) ) {
				return Promise.resolve( [] );
			}
			statusReads += 1;
			return Promise.resolve(
				statusReads <= 2
					? {
							state: 'queued',
							message: 'Queued',
							retryable: false,
							poll_after: 2,
							updated_at: 100,
					  }
					: {
							state: 'synced',
							message: 'Visible after cron',
							retryable: false,
							updated_at: 101,
					  }
			);
		} );
		render( <BroadstreetSponsorPanel /> );
		await screen.findByText( 'Queued' );

		await act( async () => {
			jest.advanceTimersByTime( 2000 );
			await Promise.resolve();
		} );
		await screen.findByText( 'Queued' );
		await act( async () => {
			jest.advanceTimersByTime( 2000 );
			await Promise.resolve();
		} );
		await screen.findByText( 'Visible after cron' );
		expect( statusReads ).toBe( 3 );
		jest.useRealTimers();
	} );
} );

describe( 'Broadstreet Disable Ads editor extension', () => {
	beforeEach( () => {
		apiFetch.mockClear();
		editorState = {
			postId: 42,
			postType: 'post',
			meta: {
				[ AD_VISIBILITY_KEY ]: '1',
			},
			isSaving: false,
			isAutosaving: false,
		};
		editPost = jest.fn( ( patch ) => {
			editorState.meta = { ...editorState.meta, ...patch.meta };
		} );
		installStoreMocks();
	} );

	test( 'reads historical values and follows peer store updates without reload', () => {
		const view = render( <BroadstreetAdVisibilityPanel /> );
		const toggle = screen.getByRole( 'checkbox', {
			name: 'Disable ads on this post',
		} );

		expect( toggle ).toBeChecked();

		// Simulate a collaborator updating the shared core/editor meta state.
		editorState.meta[ AD_VISIBILITY_KEY ] = '';
		view.rerender( <BroadstreetAdVisibilityPanel /> );
		expect(
			screen.getByRole( 'checkbox', {
				name: 'Disable ads on this post',
			} )
		).not.toBeChecked();

		fireEvent.click(
			screen.getByRole( 'checkbox', {
				name: 'Disable ads on this post',
			} )
		);
		expect( editPost ).toHaveBeenLastCalledWith( {
			meta: { [ AD_VISIBILITY_KEY ]: true },
		} );
	} );

	test( 'does not render when the post type lacks registered shared meta', () => {
		editorState.meta = {};
		const { container } = render( <BroadstreetAdVisibilityPanel /> );

		expect( container ).toBeEmptyDOMElement();
		expect( editPost ).not.toHaveBeenCalled();
	} );
} );

describe( 'Broadstreet Zone Info editor extension', () => {
	beforeEach( () => {
		apiFetch.mockClear();
		editorState = {
			postId: 42,
			postType: 'post',
			meta: {},
			isSaving: false,
			isAutosaving: false,
		};
		editPost = jest.fn();
		installStoreMocks();
	} );

	test( 'loads and renders only the exact read-only zone display contract', async () => {
		apiFetch.mockResolvedValue( [
			{
				id: '3',
				name: 'Alpha & Sons',
				shortcode: '[broadstreet zone="3"]',
			},
			{
				id: '20',
				name: 'Zulu <script>',
				shortcode: '[broadstreet zone="20"]',
			},
		] );

		const { container } = render( <BroadstreetZoneInfoPanel /> );

		expect( screen.getByText( 'Loading…' ) ).toBeInTheDocument();
		await screen.findByText( 'Alpha & Sons' );
		expect(
			screen.getByText( '[broadstreet zone="3"]' )
		).toBeInTheDocument();
		expect( screen.getByText( 'Zulu <script>' ) ).toBeInTheDocument();
		expect( container.querySelector( 'script' ) ).toBeNull();
		expect(
			screen.getByRole( 'link', { name: 'zone settings page' } )
		).toHaveAttribute( 'href', 'admin.php?page=Broadstreet-Zone-Options' );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/broadstreet/v1/zones?post_id=42',
		} );
		expect( editPost ).not.toHaveBeenCalled();
	} );

	test( 'preserves the legacy empty and unconfigured guidance', async () => {
		apiFetch.mockResolvedValue( [] );
		render( <BroadstreetZoneInfoPanel /> );

		await screen.findByText(
			/You either have no zones or Broadstreet isn't configured correctly/
		);
		expect( editPost ).not.toHaveBeenCalled();
	} );

	test( 'uses a fixed safe message when the private catalog is unavailable', async () => {
		apiFetch.mockRejectedValue(
			new Error( 'access_token=raw-secret vendor failure' )
		);
		render( <BroadstreetZoneInfoPanel /> );

		await waitFor( () =>
			expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
				'Broadstreet zones could not be loaded. Try again.'
			)
		);
		expect( screen.getByRole( 'alert' ) ).not.toHaveTextContent(
			'raw-secret'
		);
		expect( editPost ).not.toHaveBeenCalled();
	} );

	test.each( [ 'story', 'bs_business' ] )(
		'does not render or request zones for unsupported %s editors',
		( postType ) => {
			editorState.postType = postType;
			const { container } = render( <BroadstreetZoneInfoPanel /> );

			expect( container ).toBeEmptyDOMElement();
			expect( apiFetch ).not.toHaveBeenCalled();
			expect( editPost ).not.toHaveBeenCalled();
		}
	);

	test( 'ignores a delayed catalog response after navigating to another post', async () => {
		const zones42 = deferred();
		const zones43 = deferred();
		apiFetch.mockImplementation( ( request ) =>
			request.path.includes( 'post_id=43' )
				? zones43.promise
				: zones42.promise
		);

		const view = render( <BroadstreetZoneInfoPanel /> );
		editorState.postId = 43;
		view.rerender( <BroadstreetZoneInfoPanel /> );

		await act( async () => {
			zones43.resolve( [
				{
					id: '43',
					name: 'Current zone',
					shortcode: '[broadstreet zone="43"]',
				},
			] );
		} );
		await screen.findByText( 'Current zone' );

		await act( async () => {
			zones42.resolve( [
				{
					id: '42',
					name: 'Stale zone',
					shortcode: '[broadstreet zone="42"]',
				},
			] );
		} );

		expect( screen.queryByText( 'Stale zone' ) ).not.toBeInTheDocument();
		expect( screen.getByText( 'Current zone' ) ).toBeInTheDocument();
		expect( editPost ).not.toHaveBeenCalled();
	} );
} );
