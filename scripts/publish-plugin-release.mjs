import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { verifyPluginArtifact } from './verify-plugin-artifact.mjs';

const PAGE_SIZE = 100;
const MAX_PAGES = 20;
const DEFAULT_REQUEST_TIMEOUT_MS = 30_000;

function requestDeadline( timeoutMs ) {
	const controller = new AbortController();
	const timer = setTimeout( () => controller.abort(), timeoutMs );
	return {
		signal: controller.signal,
		clear: () => clearTimeout( timer ),
	};
}

export class GitHubApiError extends Error {
	constructor( message, status = null, responseBody = '' ) {
		super( message );
		this.name = 'GitHubApiError';
		this.status = status;
		this.responseBody = responseBody;
	}
}

export class GitHubApi {
	constructor( {
		token,
		apiUrl = 'https://api.github.com',
		fetchImpl = global.fetch,
		requestTimeoutMs = DEFAULT_REQUEST_TIMEOUT_MS,
	} ) {
		if ( ! token ) {
			throw new Error( 'GITHUB_TOKEN is required to publish a release.' );
		}
		if ( typeof fetchImpl !== 'function' ) {
			throw new Error(
				'A Fetch-compatible GitHub API transport is required.'
			);
		}
		if ( ! Number.isInteger( requestTimeoutMs ) || requestTimeoutMs <= 0 ) {
			throw new Error(
				'GitHub API request timeout must be a positive integer.'
			);
		}
		this.token = token;
		this.apiUrl = apiUrl.replace( /\/$/, '' );
		this.fetchImpl = fetchImpl;
		this.requestTimeoutMs = requestTimeoutMs;
	}

	async request( method, url, options = {} ) {
		const deadline = requestDeadline( this.requestTimeoutMs );
		try {
			const response = await this.fetchImpl( url, {
				method,
				headers: {
					Accept: 'application/vnd.github+json',
					Authorization: `Bearer ${ this.token }`,
					'X-GitHub-Api-Version': '2022-11-28',
					...options.headers,
				},
				body: options.body,
				signal: deadline.signal,
			} );
			if ( options.allowNotFound && response.status === 404 ) {
				return null;
			}
			if ( ! response.ok ) {
				const responseBody = await response.text();
				throw new GitHubApiError(
					`GitHub API ${ method } ${ url } failed with status ${
						response.status
					}${ responseBody ? `: ${ responseBody }` : '' }`,
					response.status,
					responseBody
				);
			}
			if ( options.binary ) {
				return Buffer.from( await response.arrayBuffer() );
			}
			if ( response.status === 204 ) {
				return null;
			}
			return await response.json();
		} catch ( error ) {
			if ( error instanceof GitHubApiError ) {
				throw error;
			}
			if ( deadline.signal.aborted ) {
				throw new GitHubApiError(
					`GitHub API request timed out after ${ this.requestTimeoutMs }ms.`
				);
			}
			throw new GitHubApiError(
				`GitHub API request failed: ${ error.message }`
			);
		} finally {
			deadline.clear();
		}
	}

	repositoryUrl( repository, suffix ) {
		const encodedRepository = repository
			.split( '/' )
			.map( encodeURIComponent )
			.join( '/' );
		return `${ this.apiUrl }/repos/${ encodedRepository }${ suffix }`;
	}

	getTag( repository, tag ) {
		return this.request(
			'GET',
			this.repositoryUrl(
				repository,
				`/git/ref/tags/${ encodeURIComponent( tag ) }`
			),
			{ allowNotFound: true }
		);
	}

	createTag( repository, tag, sha ) {
		return this.request(
			'POST',
			this.repositoryUrl( repository, '/git/refs' ),
			{
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( {
					ref: `refs/tags/${ tag }`,
					sha,
				} ),
			}
		);
	}

	async getRelease( repository, tag ) {
		const published = await this.request(
			'GET',
			this.repositoryUrl(
				repository,
				`/releases/tags/${ encodeURIComponent( tag ) }`
			),
			{ allowNotFound: true }
		);
		if ( published ) {
			return published;
		}

		const matches = [];
		for ( let page = 1; page <= MAX_PAGES; page++ ) {
			const batch = await this.request(
				'GET',
				this.repositoryUrl(
					repository,
					`/releases?per_page=${ PAGE_SIZE }&page=${ page }`
				)
			);
			if ( ! Array.isArray( batch ) ) {
				throw new Error( 'GitHub releases response is not an array.' );
			}
			matches.push(
				...batch.filter( ( release ) => release.tag_name === tag )
			);
			if ( matches.length > 1 ) {
				throw new Error(
					`GitHub contains multiple releases for tag ${ tag }.`
				);
			}
			if ( batch.length < PAGE_SIZE ) {
				return matches[ 0 ] || null;
			}
		}
		throw new Error(
			`GitHub release pagination exceeded ${ MAX_PAGES } pages.`
		);
	}

	async listAssets( repository, releaseId ) {
		const assets = [];
		for ( let page = 1; page <= MAX_PAGES; page++ ) {
			const batch = await this.request(
				'GET',
				this.repositoryUrl(
					repository,
					`/releases/${ releaseId }/assets?per_page=${ PAGE_SIZE }&page=${ page }`
				)
			);
			if ( ! Array.isArray( batch ) ) {
				throw new Error(
					'GitHub release assets response is not an array.'
				);
			}
			assets.push( ...batch );
			if ( batch.length < PAGE_SIZE ) {
				return assets;
			}
		}
		throw new Error(
			`GitHub release asset pagination exceeded ${ MAX_PAGES } pages.`
		);
	}

	createDraftRelease( repository, input ) {
		return this.request(
			'POST',
			this.repositoryUrl( repository, '/releases' ),
			{
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( {
					tag_name: input.tag,
					target_commitish: input.sha,
					name: input.title,
					body: input.notes,
					draft: true,
					prerelease: false,
				} ),
			}
		);
	}

	downloadAsset( repository, asset ) {
		const url =
			asset.url ||
			this.repositoryUrl( repository, `/releases/assets/${ asset.id }` );
		return this.request( 'GET', url, {
			headers: { Accept: 'application/octet-stream' },
			binary: true,
		} );
	}

	uploadAsset( repository, releaseId, uploadUrl, asset ) {
		if ( ! Number.isInteger( releaseId ) || releaseId <= 0 ) {
			throw new Error( 'GitHub release upload requires a numeric id.' );
		}
		const uploadBase = uploadUrl.replace( /\{.*$/, '' );
		const encodedRepository = repository
			.split( '/' )
			.map( encodeURIComponent )
			.join( '/' );
		const expectedPath = `/repos/${ encodedRepository }/releases/${ releaseId }/assets`;
		if ( new URL( uploadBase ).pathname !== expectedPath ) {
			throw new Error(
				'GitHub release upload URL does not match the verified numeric release id.'
			);
		}
		const url = `${ uploadBase }?name=${ encodeURIComponent(
			asset.name
		) }`;
		return this.request( 'POST', url, {
			headers: {
				Accept: 'application/vnd.github+json',
				'Content-Type': asset.contentType,
			},
			body: asset.bytes,
		} );
	}

	publishRelease( repository, release ) {
		return this.request(
			'PATCH',
			this.repositoryUrl( repository, `/releases/${ release.id }` ),
			{
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( { draft: false } ),
			}
		);
	}
}

function validateInputs( { repository, sha, tag, assets } ) {
	if ( ! /^[^/]+\/[^/]+$/.test( repository ) ) {
		throw new Error( 'Repository must use the owner/name form.' );
	}
	if ( ! /^[0-9a-f]{40}$/.test( sha ) ) {
		throw new Error( 'GITHUB_SHA must be a full lowercase Git SHA.' );
	}
	if ( ! /^broadstreet-v[A-Za-z0-9._+-]+$/.test( tag ) ) {
		throw new Error(
			'Release tag is outside the broadstreet-v namespace.'
		);
	}
	if ( assets.length !== 3 ) {
		throw new Error( 'A release requires exactly three local assets.' );
	}
	const names = assets.map( ( asset ) => asset.name );
	if ( new Set( names ).size !== names.length ) {
		throw new Error( 'Release asset names must be unique.' );
	}
	const zipNames = names.filter( ( name ) => name.endsWith( '.zip' ) );
	if (
		zipNames.length !== 1 ||
		! names.includes( `${ zipNames[ 0 ] }.sha256` ) ||
		! names.includes( 'plugin-artifact-manifest.json' )
	) {
		throw new Error(
			'Release assets must be one ZIP, its .sha256 checksum, and plugin-artifact-manifest.json.'
		);
	}
	for ( const asset of assets ) {
		if ( ! Buffer.isBuffer( asset.bytes ) || ! asset.contentType ) {
			throw new Error( `Release asset ${ asset.name } is incomplete.` );
		}
	}
}

function assertTagTarget( ref, sha ) {
	if ( ! ref || ref.object?.type !== 'commit' || ref.object.sha !== sha ) {
		throw new Error( 'Release tag does not target GITHUB_SHA exactly.' );
	}
}

async function ensureTag( api, repository, tag, sha ) {
	let ref = await api.getTag( repository, tag );
	if ( ! ref ) {
		try {
			ref = await api.createTag( repository, tag, sha );
		} catch ( error ) {
			if (
				! ( error instanceof GitHubApiError ) ||
				error.status !== 422
			) {
				throw error;
			}
			ref = await api.getTag( repository, tag );
			if ( ! ref ) {
				throw new Error(
					'Release tag creation conflicted, but no tag can be verified.'
				);
			}
		}
	}
	assertTagTarget( ref, sha );
	return ref;
}

function assertReleaseMetadata( release, input, expectedDraft ) {
	if ( ! Number.isInteger( release?.id ) ) {
		throw new Error( 'GitHub release does not have a numeric id.' );
	}
	const expected = {
		tag_name: input.tag,
		name: input.title,
		body: input.notes,
		prerelease: false,
	};
	for ( const [ field, value ] of Object.entries( expected ) ) {
		if ( release[ field ] !== value ) {
			throw new Error(
				`GitHub release ${ field } conflicts with the requested release.`
			);
		}
	}
	if ( release.draft !== expectedDraft ) {
		throw new Error(
			`GitHub release draft state does not match ${ expectedDraft }.`
		);
	}
}

async function ensureDraftRelease( api, input ) {
	let release = await api.getRelease( input.repository, input.tag );
	if ( release ) {
		return release;
	}
	try {
		release = await api.createDraftRelease( input.repository, input );
	} catch ( error ) {
		if ( ! ( error instanceof GitHubApiError ) || error.status !== 422 ) {
			throw error;
		}
		release = await api.getRelease( input.repository, input.tag );
		if ( ! release ) {
			throw new Error(
				'Release creation conflicted, but no release can be verified.'
			);
		}
	}
	return release;
}

function indexReleaseAssets( assets ) {
	const indexed = new Map();
	for ( const asset of assets ) {
		if ( indexed.has( asset.name ) ) {
			throw new Error(
				`Release contains duplicate asset ${ asset.name }.`
			);
		}
		indexed.set( asset.name, asset );
	}
	return indexed;
}

async function verifyAsset( api, repository, remote, expected ) {
	if ( remote.size !== expected.bytes.length ) {
		throw new Error(
			`Release asset ${ expected.name } does not match local bytes.`
		);
	}
	const remoteBytes = await api.downloadAsset( repository, remote );
	if ( ! Buffer.from( remoteBytes ).equals( expected.bytes ) ) {
		throw new Error(
			`Release asset ${ expected.name } does not match local bytes.`
		);
	}
}

async function verifyExactAssets( api, repository, release, assets, state ) {
	const indexed = indexReleaseAssets(
		await api.listAssets( repository, release.id )
	);
	const expectedNames = assets.map( ( asset ) => asset.name ).sort();
	const actualNames = [ ...indexed.keys() ].sort();
	if ( JSON.stringify( actualNames ) !== JSON.stringify( expectedNames ) ) {
		throw new Error(
			`${ state } release asset set is incomplete or conflicting.`
		);
	}
	for ( const asset of assets ) {
		await verifyAsset( api, repository, indexed.get( asset.name ), asset );
	}
}

async function verifyExistingDraftAssets( api, repository, release, assets ) {
	const indexed = indexReleaseAssets(
		await api.listAssets( repository, release.id )
	);
	const expectedNames = new Set( assets.map( ( asset ) => asset.name ) );
	for ( const name of indexed.keys() ) {
		if ( ! expectedNames.has( name ) ) {
			throw new Error(
				`Draft release contains unexpected asset ${ name }.`
			);
		}
	}
	for ( const asset of assets ) {
		const remote = indexed.get( asset.name );
		if ( remote ) {
			await verifyAsset( api, repository, remote, asset );
		}
	}
	return indexed;
}

export async function publishPluginRelease( input ) {
	validateInputs( input );
	const { api, repository, sha, tag, assets } = input;
	await ensureTag( api, repository, tag, sha );
	let release = await ensureDraftRelease( api, input );

	if ( ! release.draft ) {
		assertReleaseMetadata( release, input, false );
		await verifyExactAssets(
			api,
			repository,
			release,
			assets,
			'Published'
		);
		await ensureTag( api, repository, tag, sha );
		return { state: 'already-published', releaseId: release.id };
	}
	assertReleaseMetadata( release, input, true );

	let indexed = await verifyExistingDraftAssets(
		api,
		repository,
		release,
		assets
	);
	for ( const asset of assets ) {
		if ( indexed.has( asset.name ) ) {
			continue;
		}
		try {
			await api.uploadAsset(
				repository,
				release.id,
				release.upload_url,
				asset
			);
		} catch ( error ) {
			if (
				! ( error instanceof GitHubApiError ) ||
				error.status !== 422
			) {
				throw error;
			}
			release = await api.getRelease( repository, tag );
			if ( ! release ) {
				throw new Error(
					'Release disappeared during an asset upload race.'
				);
			}
			assertReleaseMetadata( release, input, release.draft );
			indexed = indexReleaseAssets(
				await api.listAssets( repository, release.id )
			);
			const racedAsset = indexed.get( asset.name );
			if ( ! racedAsset ) {
				throw new Error(
					`Upload for ${ asset.name } conflicted without a verifiable asset.`
				);
			}
			await verifyAsset( api, repository, racedAsset, asset );
		}
	}

	release = await api.getRelease( repository, tag );
	if ( ! release ) {
		throw new Error( 'Release disappeared before publication.' );
	}
	if ( ! release.draft ) {
		assertReleaseMetadata( release, input, false );
		await verifyExactAssets(
			api,
			repository,
			release,
			assets,
			'Published'
		);
		await ensureTag( api, repository, tag, sha );
		return { state: 'already-published', releaseId: release.id };
	}
	assertReleaseMetadata( release, input, true );
	await verifyExactAssets( api, repository, release, assets, 'Draft' );
	await ensureTag( api, repository, tag, sha );

	const draftId = release.id;
	try {
		release = await api.publishRelease( repository, release );
	} catch ( error ) {
		if ( ! ( error instanceof GitHubApiError ) || error.status !== 422 ) {
			throw error;
		}
		release = await api.getRelease( repository, tag );
	}
	await ensureTag( api, repository, tag, sha );
	assertReleaseMetadata( release, input, false );
	if ( release.id !== draftId ) {
		throw new Error( 'Published release id changed during publication.' );
	}
	release = await api.getRelease( repository, tag );
	assertReleaseMetadata( release, input, false );
	if ( release.id !== draftId ) {
		throw new Error( 'Published release id changed during verification.' );
	}
	await verifyExactAssets( api, repository, release, assets, 'Published' );
	await ensureTag( api, repository, tag, sha );
	return { state: 'published', releaseId: release.id };
}

function optionValue( args, name ) {
	const index = args.indexOf( name );
	if ( index === -1 || ! args[ index + 1 ] ) {
		throw new Error( `Missing required option ${ name }.` );
	}
	return args[ index + 1 ];
}

async function main() {
	const args = process.argv.slice( 2 );
	const artifact = verifyPluginArtifact(
		path.resolve( optionValue( args, '--artifact-dir' ) )
	);
	const version = optionValue( args, '--version' );
	const sha = optionValue( args, '--sha' );
	const tag = optionValue( args, '--tag' );
	if ( artifact.pluginVersion !== version ) {
		throw new Error(
			'Verified artifact version does not match the release decision.'
		);
	}
	if ( artifact.commit !== sha ) {
		throw new Error(
			'Verified artifact commit does not match GITHUB_SHA.'
		);
	}
	if ( tag !== `broadstreet-v${ version }` ) {
		throw new Error(
			'Release tag does not match the verified plugin version.'
		);
	}
	const assets = [
		{
			path: artifact.zipPath,
			contentType: 'application/zip',
		},
		{
			path: artifact.checksumPath,
			contentType: 'text/plain',
		},
		{
			path: artifact.manifestPath,
			contentType: 'application/json',
		},
	].map( ( asset ) => ( {
		name: path.basename( asset.path ),
		contentType: asset.contentType,
		bytes: fs.readFileSync( asset.path ),
	} ) );
	const api = new GitHubApi( {
		token: process.env.GITHUB_TOKEN,
		apiUrl: process.env.GITHUB_API_URL,
	} );
	const result = await publishPluginRelease( {
		api,
		repository: optionValue( args, '--repository' ),
		sha,
		tag,
		title: optionValue( args, '--title' ),
		notes: optionValue( args, '--notes' ),
		assets,
	} );
	process.stdout.write(
		`Release ${ result.state } (id ${ result.releaseId }).\n`
	);
}

if (
	process.argv[ 1 ] &&
	path.resolve( process.argv[ 1 ] ) === fileURLToPath( import.meta.url )
) {
	try {
		await main();
	} catch ( error ) {
		process.stderr.write( `${ error.message }\n` );
		process.exitCode = 1;
	}
}
