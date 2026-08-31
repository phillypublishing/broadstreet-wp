import apiFetch from '@wordpress/api-fetch';
// The configured classic JSX transform consumes these imports at build time.
/* eslint-disable no-unused-vars */
import {
	Button,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import {
	createElement,
	Fragment,
	useCallback,
	useEffect,
	useRef,
	useState,
} from '@wordpress/element';
/* eslint-enable no-unused-vars */
import { __ } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';

export const SPONSOR_ENABLED_KEY = 'bs_sponsor_is_sponsored';
export const SPONSOR_ADVERTISER_KEY = 'bs_sponsor_advertiser_id';
export const AD_VISIBILITY_KEY = 'bs_ads_disabled';

const EMPTY_STATUS = {
	state: 'idle',
	message: '',
	retryable: false,
	updated_at: 0,
};

const isSponsoredValue = ( value ) =>
	value === true || value === 1 || value === '1' || value === 'true';

const isPositiveId = ( value ) => /^[1-9][0-9]*$/.test( String( value ) );

const unicodeLength = ( value ) => Array.from( value ).length;

const advertiserCreateErrorStatus = ( code ) => {
	switch ( code ) {
		case 'broadstreet_invalid_advertiser_name':
			return {
				state: 'error',
				message: __(
					'Advertiser names must be between 3 and 127 characters.',
					'broadstreet_textdomain'
				),
				retryable: false,
			};
		case 'broadstreet_advertiser_create_in_progress':
			return {
				state: 'error',
				message: __(
					'Broadstreet advertiser creation is already in progress. Wait for it to finish before trying again.',
					'broadstreet_textdomain'
				),
				retryable: false,
			};
		case 'broadstreet_advertiser_rejected':
			return {
				state: 'error',
				message: __(
					'Broadstreet rejected that advertiser name. Correct the name before trying again.',
					'broadstreet_textdomain'
				),
				retryable: false,
			};
		default:
			return {
				state: 'error',
				message: __(
					'Broadstreet could not create the advertiser. Try again.',
					'broadstreet_textdomain'
				),
				retryable: true,
			};
	}
};

/**
 * Per-post ad visibility feature for the shared document-settings root.
 */
export function BroadstreetAdVisibilityPanel() {
	const meta = useSelect( ( select ) => {
		const store = select( 'core/editor' );
		return store ? store.getEditedPostAttribute( 'meta' ) || {} : {};
	}, [] );
	const { editPost } = useDispatch( 'core/editor' );

	if ( ! Object.prototype.hasOwnProperty.call( meta, AD_VISIBILITY_KEY ) ) {
		return null;
	}

	return (
		<PluginDocumentSettingPanel
			name="broadstreet-options"
			title={ __( 'Broadstreet Options', 'broadstreet_textdomain' ) }
		>
			<ToggleControl
				label={ __(
					'Disable ads on this post',
					'broadstreet_textdomain'
				) }
				help={ __(
					'Prevent Broadstreet ads from rendering on this post.',
					'broadstreet_textdomain'
				) }
				checked={ isSponsoredValue( meta[ AD_VISIBILITY_KEY ] ) }
				onChange={ ( disabled ) =>
					editPost( {
						meta: { [ AD_VISIBILITY_KEY ]: Boolean( disabled ) },
					} )
				}
			/>
		</PluginDocumentSettingPanel>
	);
}

/**
 * Read-only cached zone reference for post and page editors.
 */
export function BroadstreetZoneInfoPanel() {
	const editor = useSelect( ( select ) => {
		const store = select( 'core/editor' );
		if ( ! store ) {
			return { postId: 0, postType: '' };
		}

		return {
			postId: store.getCurrentPostId(),
			postType: store.getCurrentPostType(),
		};
	}, [] );
	const [ zones, setZones ] = useState( [] );
	const [ catalogState, setCatalogState ] = useState( 'idle' );
	const zoneRequest = useRef( 0 );
	const currentPostId = useRef( editor.postId );
	const currentPostType = useRef( editor.postType );
	const supported = [ 'post', 'page' ].includes( editor.postType );
	currentPostId.current = editor.postId;
	currentPostType.current = editor.postType;

	useEffect( () => {
		const requestId = ++zoneRequest.current;
		if ( ! supported || ! editor.postId ) {
			setZones( [] );
			setCatalogState( 'idle' );
			return;
		}

		const postId = editor.postId;
		const postType = editor.postType;
		setZones( [] );
		setCatalogState( 'loading' );
		apiFetch( {
			path: `/broadstreet/v1/zones?post_id=${ encodeURIComponent(
				postId
			) }`,
		} )
			.then( ( items ) => {
				if (
					requestId !== zoneRequest.current ||
					postId !== currentPostId.current ||
					postType !== currentPostType.current
				) {
					return;
				}

				setZones( Array.isArray( items ) ? items : [] );
				setCatalogState( 'ready' );
			} )
			.catch( () => {
				if (
					requestId === zoneRequest.current &&
					postId === currentPostId.current &&
					postType === currentPostType.current
				) {
					setCatalogState( 'error' );
				}
			} );
	}, [ editor.postId, editor.postType, supported ] );

	if ( ! supported ) {
		return null;
	}

	return (
		<PluginDocumentSettingPanel
			name="broadstreet-zone-info"
			title={ __( 'Broadstreet Zone Info', 'broadstreet_textdomain' ) }
		>
			<p>
				{ __(
					'Here is a list of the zones you have registered in Broadstreet. To embed a zone in the post, paste in its shortcode. You can also have zones auto-injected on the',
					'broadstreet_textdomain'
				) }{ ' ' }
				<a href="admin.php?page=Broadstreet-Zone-Options">
					{ __( 'zone settings page', 'broadstreet_textdomain' ) }
				</a>
				{ '.' }
			</p>

			{ catalogState === 'loading' && <Spinner /> }
			{ catalogState === 'error' && (
				<Notice status="error" isDismissible={ false }>
					{ __(
						'Broadstreet zones could not be loaded. Try again.',
						'broadstreet_textdomain'
					) }
				</Notice>
			) }
			{ catalogState === 'ready' && zones.length === 0 && (
				<p>
					{ __(
						"You either have no zones or Broadstreet isn't configured correctly. Go to 'Settings', then 'Broadstreet', and make sure your access token is correct, and make sure you have zones set up.",
						'broadstreet_textdomain'
					) }
				</p>
			) }
			{ catalogState === 'ready' && zones.length > 0 && (
				<ul>
					{ zones.map( ( zone ) => (
						<li key={ zone.id }>
							<strong>{ zone.name }</strong>
							<br />
							<code>{ zone.shortcode }</code>
						</li>
					) ) }
				</ul>
			) }
		</PluginDocumentSettingPanel>
	);
}

/**
 * Sponsored-content feature for the shared Broadstreet document-settings root.
 */
export function BroadstreetSponsorPanel() {
	const editor = useSelect( ( select ) => {
		const store = select( 'core/editor' );
		if ( ! store ) {
			return {
				postId: 0,
				meta: {},
				isSaving: false,
				isAutosaving: false,
			};
		}

		return {
			postId: store.getCurrentPostId(),
			meta: store.getEditedPostAttribute( 'meta' ) || {},
			isSaving: store.isSavingPost(),
			isAutosaving: store.isAutosavingPost(),
		};
	}, [] );
	const { editPost } = useDispatch( 'core/editor' );
	const [ advertisers, setAdvertisers ] = useState( [] );
	const [ advertisersState, setAdvertisersState ] = useState( 'idle' );
	const [ advertiserName, setAdvertiserName ] = useState( '' );
	const [ isCreating, setIsCreating ] = useState( false );
	const [ status, setStatus ] = useState( EMPTY_STATUS );
	const previousSaving = useRef( false );
	const statusRequest = useRef( 0 );
	const advertisersRequest = useRef( 0 );
	const createRequest = useRef( 0 );
	const currentPostId = useRef( editor.postId );
	const currentSponsored = useRef( false );

	const supported =
		Object.prototype.hasOwnProperty.call(
			editor.meta,
			SPONSOR_ENABLED_KEY
		) &&
		Object.prototype.hasOwnProperty.call(
			editor.meta,
			SPONSOR_ADVERTISER_KEY
		);
	const isSponsored = isSponsoredValue( editor.meta[ SPONSOR_ENABLED_KEY ] );
	currentPostId.current = editor.postId;
	currentSponsored.current = isSponsored;

	const patchMeta = useCallback(
		( key, value ) => {
			editPost( { meta: { [ key ]: value } } );
		},
		[ editPost ]
	);

	const acceptStatus = useCallback( ( nextStatus, requestId, postId ) => {
		if (
			requestId !== statusRequest.current ||
			postId !== currentPostId.current
		) {
			return;
		}

		setStatus( ( current ) => {
			const nextUpdatedAt = Number( nextStatus.updated_at || 0 );
			const currentUpdatedAt = Number( current.updated_at || 0 );
			if (
				nextUpdatedAt > 0 &&
				currentUpdatedAt > 0 &&
				nextUpdatedAt < currentUpdatedAt
			) {
				return current;
			}

			return { ...EMPTY_STATUS, ...nextStatus };
		} );
	}, [] );

	const loadStatus = useCallback( () => {
		if ( ! supported || ! editor.postId ) {
			return Promise.resolve();
		}

		const requestId = ++statusRequest.current;
		const postId = editor.postId;
		return apiFetch( {
			path: `/broadstreet/v1/sponsor-status?post_id=${ encodeURIComponent(
				postId
			) }`,
		} )
			.then( ( nextStatus ) =>
				acceptStatus( nextStatus, requestId, postId )
			)
			.catch( () => {
				acceptStatus(
					{
						state: 'error',
						message: __(
							'Broadstreet synchronization status could not be loaded. Try again.',
							'broadstreet_textdomain'
						),
						retryable: true,
					},
					requestId,
					postId
				);
			} );
	}, [ acceptStatus, editor.postId, supported ] );

	useEffect( () => {
		if ( ! supported || ! editor.postId ) {
			++statusRequest.current;
			return;
		}

		loadStatus();
	}, [ editor.postId, loadStatus, supported ] );

	useEffect( () => {
		const requestId = ++advertisersRequest.current;
		++createRequest.current;
		setIsCreating( false );

		if ( ! supported || ! editor.postId || ! isSponsored ) {
			setAdvertisersState( 'idle' );
			return;
		}

		const postId = editor.postId;
		setAdvertisersState( 'loading' );
		apiFetch( {
			path: `/broadstreet/v1/advertisers?post_id=${ encodeURIComponent(
				postId
			) }`,
		} )
			.then( ( items ) => {
				if (
					requestId !== advertisersRequest.current ||
					postId !== currentPostId.current ||
					! currentSponsored.current
				) {
					return;
				}
				setAdvertisers( Array.isArray( items ) ? items : [] );
				setAdvertisersState( 'ready' );
			} )
			.catch( () => {
				if (
					requestId === advertisersRequest.current &&
					postId === currentPostId.current &&
					currentSponsored.current
				) {
					setAdvertisersState( 'error' );
				}
			} );
	}, [ editor.postId, isSponsored, supported ] );

	useEffect( () => {
		const saving = editor.isSaving && ! editor.isAutosaving;
		if ( previousSaving.current && ! saving ) {
			loadStatus();
		}
		previousSaving.current = saving;
	}, [ editor.isAutosaving, editor.isSaving, loadStatus ] );

	const createAdvertiser = () => {
		const name = advertiserName.trim();
		const nameLength = unicodeLength( name );
		if (
			nameLength < 3 ||
			nameLength > 127 ||
			isCreating ||
			! isSponsored
		) {
			return;
		}

		const requestId = ++createRequest.current;
		const postId = editor.postId;
		setIsCreating( true );
		apiFetch( {
			path: '/broadstreet/v1/advertisers',
			method: 'POST',
			data: { post_id: postId, name },
		} )
			.then( ( advertiser ) => {
				if (
					requestId !== createRequest.current ||
					postId !== currentPostId.current ||
					! currentSponsored.current
				) {
					return;
				}
				setAdvertisers( ( current ) => [
					...current.filter(
						( item ) =>
							String( item.id ) !== String( advertiser.id )
					),
					advertiser,
				] );
				patchMeta( SPONSOR_ADVERTISER_KEY, String( advertiser.id ) );
				setAdvertiserName( '' );
				setStatus( {
					...EMPTY_STATUS,
					state: 'waiting',
					message: __(
						'Advertiser created and selected. Save the post to synchronize tracking.',
						'broadstreet_textdomain'
					),
				} );
			} )
			.catch( ( error ) => {
				if (
					requestId === createRequest.current &&
					postId === currentPostId.current &&
					currentSponsored.current
				) {
					setStatus( {
						...EMPTY_STATUS,
						...advertiserCreateErrorStatus( error && error.code ),
					} );
				}
			} )
			.finally( () => {
				if ( requestId === createRequest.current ) {
					setIsCreating( false );
				}
			} );
	};

	const retryStatus = () => {
		const requestId = ++statusRequest.current;
		const postId = editor.postId;
		setStatus( ( current ) => ( {
			...current,
			state: 'syncing',
			retryable: false,
		} ) );
		apiFetch( {
			path: '/broadstreet/v1/sponsor-status',
			method: 'POST',
			data: { post_id: postId },
		} )
			.then( ( nextStatus ) =>
				acceptStatus( nextStatus, requestId, postId )
			)
			.catch( () => {
				acceptStatus(
					{
						state: 'error',
						message: __(
							'Broadstreet could not retry synchronization. Save the post or try again.',
							'broadstreet_textdomain'
						),
						retryable: true,
					},
					requestId,
					postId
				);
			} );
	};

	if ( ! supported ) {
		return null;
	}

	const advertiserId = isPositiveId( editor.meta[ SPONSOR_ADVERTISER_KEY ] )
		? String( editor.meta[ SPONSOR_ADVERTISER_KEY ] )
		: '';
	const advertiserOptions = [
		{
			label: __( 'Select an advertiser', 'broadstreet_textdomain' ),
			value: '',
		},
		...advertisers.map( ( advertiser ) => ( {
			label: `${ advertiser.name } (ID: ${ advertiser.id })`,
			value: String( advertiser.id ),
		} ) ),
	];

	if (
		advertiserId &&
		! advertisers.some(
			( advertiser ) => String( advertiser.id ) === advertiserId
		)
	) {
		advertiserOptions.push( {
			label: `${ __(
				'Advertiser',
				'broadstreet_textdomain'
			) } ID ${ advertiserId }`,
			value: advertiserId,
		} );
	}

	const advertiserNameLength = unicodeLength( advertiserName.trim() );

	return (
		<PluginDocumentSettingPanel
			name="broadstreet-sponsored-content"
			title={ __( 'Sponsored Content', 'broadstreet_textdomain' ) }
		>
			<ToggleControl
				label={ __( 'Performance tracking', 'broadstreet_textdomain' ) }
				checked={ isSponsored }
				onChange={ ( enabled ) =>
					patchMeta( SPONSOR_ENABLED_KEY, Boolean( enabled ) )
				}
			/>

			{ status.message && (
				<Notice
					status={ status.state === 'error' ? 'error' : 'info' }
					isDismissible={ false }
				>
					{ status.message }
				</Notice>
			) }
			{ status.retryable && (
				<Button variant="secondary" onClick={ retryStatus }>
					{ __( 'Retry synchronization', 'broadstreet_textdomain' ) }
				</Button>
			) }

			{ isSponsored && advertisersState === 'loading' && <Spinner /> }
			{ isSponsored && advertisersState === 'error' && (
				<Notice status="error" isDismissible={ false }>
					{ __(
						'Broadstreet advertisers could not be loaded. Try again.',
						'broadstreet_textdomain'
					) }
				</Notice>
			) }
			{ isSponsored && advertisersState === 'ready' && (
				<>
					<SelectControl
						label={ __( 'Advertiser', 'broadstreet_textdomain' ) }
						value={ advertiserId }
						options={ advertiserOptions }
						onChange={ ( value ) => {
							if ( isPositiveId( value ) ) {
								patchMeta(
									SPONSOR_ADVERTISER_KEY,
									String( value )
								);
							}
						} }
					/>
					<TextControl
						label={ __(
							'New advertiser name',
							'broadstreet_textdomain'
						) }
						value={ advertiserName }
						onChange={ setAdvertiserName }
					/>
					<Button
						variant="secondary"
						isBusy={ isCreating }
						disabled={
							isCreating ||
							advertiserNameLength < 3 ||
							advertiserNameLength > 127
						}
						onClick={ createAdvertiser }
					>
						{ __( 'Create advertiser', 'broadstreet_textdomain' ) }
					</Button>
				</>
			) }
		</PluginDocumentSettingPanel>
	);
}

/**
 * Sole plugin entry point. Future document-setting features compose here.
 */
export function BroadstreetDocumentSettings() {
	return (
		<>
			<BroadstreetAdVisibilityPanel />
			<BroadstreetSponsorPanel />
			<BroadstreetZoneInfoPanel />
		</>
	);
}

registerPlugin( 'broadstreet-editor', {
	render: BroadstreetDocumentSettings,
} );
