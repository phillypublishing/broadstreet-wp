import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { createRequire } from 'node:module';

import {
	evaluateVersionGate,
	readAlignedPluginVersion,
} from '../scripts/plugin-version.mjs';
import {
	GitHubApi,
	GitHubApiError,
	publishPluginRelease,
} from '../scripts/publish-plugin-release.mjs';

const require = createRequire( import.meta.url );
const YAML = require( 'yaml' );
const repoRoot = path.dirname(
	path.dirname( new URL( import.meta.url ).pathname )
);

async function runTest( name, callback ) {
	try {
		await callback();
		process.stdout.write( `ok - ${ name }\n` );
	} catch ( error ) {
		process.stderr.write( `not ok - ${ name }\n${ error.stack }\n` );
		process.exitCode = 1;
	}
}

function workflowStep( workflow, job, id ) {
	const step = workflow.jobs[ job ].steps.find(
		( candidate ) => candidate.id === id
	);
	assert.ok( step, `Workflow step ${ job }.${ id } is missing.` );
	return step;
}

function writeVersionFixture( root, versions = {} ) {
	const header = versions.header || '1.2.3';
	const runtime = versions.runtime || '1.2.3';
	const packageVersion = versions.packageVersion || '1.2.3';
	const stableTag = versions.stableTag || '1.2.3';

	fs.mkdirSync( path.join( root, 'Broadstreet' ), { recursive: true } );
	fs.writeFileSync(
		path.join( root, 'broadstreet.php' ),
		`<?php\n/*\nVersion: ${ header }\n*/\n`
	);
	fs.writeFileSync(
		path.join( root, 'Broadstreet/Config.php' ),
		`<?php\ndefine('BROADSTREET_VERSION', '${ runtime }');\n`
	);
	fs.writeFileSync(
		path.join( root, 'package.json' ),
		`${ JSON.stringify( { version: packageVersion } ) }\n`
	);
	fs.writeFileSync(
		path.join( root, 'readme.txt' ),
		`=== Broadstreet ===\nStable tag: ${ stableTag }\n`
	);
}

function fakeGitForPrevious( source, options = {} ) {
	return ( args ) => {
		if ( args[ 0 ] === 'rev-parse' ) {
			return {
				status: 0,
				stdout: `${ 'b'.repeat( 40 ) }\n`,
				stderr: '',
			};
		}
		if ( args[ 0 ] === 'cat-file' ) {
			return options.historyFailure
				? { status: 128, stdout: '', stderr: 'missing object' }
				: { status: 0, stdout: '', stderr: '' };
		}
		if ( args[ 0 ] === 'merge-base' ) {
			return options.siblingHistory
				? { status: 1, stdout: '', stderr: '' }
				: { status: 0, stdout: '', stderr: '' };
		}
		if ( args[ 0 ] === 'ls-tree' ) {
			return {
				status: 0,
				stdout: options.missingFile ? '' : 'broadstreet.php\0',
				stderr: '',
			};
		}
		if ( args[ 0 ] === 'show' ) {
			return { status: 0, stdout: source, stderr: '' };
		}
		if ( args[ 0 ] === 'check-ref-format' ) {
			return { status: 0, stdout: '', stderr: '' };
		}
		throw new Error( `Unexpected git invocation: ${ args.join( ' ' ) }` );
	};
}

function releaseAssets() {
	return [
		{
			name: 'broadstreet-1.2.3-0123456789ab.zip',
			contentType: 'application/zip',
			bytes: Buffer.from( 'zip-bytes' ),
		},
		{
			name: 'broadstreet-1.2.3-0123456789ab.zip.sha256',
			contentType: 'text/plain',
			bytes: Buffer.from( 'checksum' ),
		},
		{
			name: 'plugin-artifact-manifest.json',
			contentType: 'application/json',
			bytes: Buffer.from( '{"manifest":true}' ),
		},
	];
}

class FakeReleaseApi {
	constructor( options = {} ) {
		this.expectedSha =
			options.sha || '0123456789abcdef0123456789abcdef01234567';
		this.tagRef = options.tagRef || null;
		this.release = options.release || null;
		this.assetBytes = new Map( options.assetBytes || [] );
		this.nextAssetId = 100;
		this.calls = [];
		this.tagRace = options.tagRace || false;
		this.releaseRace = options.releaseRace || false;
		this.uploadRace = options.uploadRace || null;
	}

	async getTag() {
		this.calls.push( 'getTag' );
		return this.tagRef ? structuredClone( this.tagRef ) : null;
	}

	async createTag( repository, tag, sha ) {
		this.calls.push( 'createTag' );
		const ref = { object: { type: 'commit', sha } };
		if ( this.tagRace ) {
			this.tagRace = false;
			this.tagRef = ref;
			throw new GitHubApiError( 'tag raced', 422 );
		}
		this.tagRef = ref;
		return structuredClone( ref );
	}

	async getRelease() {
		this.calls.push( 'getRelease' );
		return this.release ? structuredClone( this.release ) : null;
	}

	async listAssets() {
		this.calls.push( 'listAssets' );
		return structuredClone( this.release?.assets || [] );
	}

	async createDraftRelease( repository, input ) {
		this.calls.push( 'createDraftRelease' );
		const release = {
			id: 7,
			tag_name: input.tag,
			target_commitish: input.sha,
			name: input.title,
			body: input.notes,
			prerelease: false,
			draft: true,
			assets: [],
			upload_url:
				'https://uploads.test/repos/owner/repo/releases/7/assets{?name,label}',
		};
		if ( this.releaseRace ) {
			this.releaseRace = false;
			this.release = release;
			throw new GitHubApiError( 'release raced', 422 );
		}
		this.release = release;
		return structuredClone( release );
	}

	async downloadAsset( repository, asset ) {
		this.calls.push( `download:${ asset.name }` );
		return Buffer.from( this.assetBytes.get( asset.id ) || '' );
	}

	async uploadAsset( repository, releaseId, uploadUrl, asset ) {
		this.calls.push( `upload:${ asset.name }` );
		assert.equal( releaseId, this.release.id );
		assert.equal( uploadUrl, this.release.upload_url );
		const addAsset = () => {
			const uploaded = {
				id: this.nextAssetId++,
				name: asset.name,
				size: asset.bytes.length,
			};
			this.release.assets.push( uploaded );
			this.assetBytes.set( uploaded.id, Buffer.from( asset.bytes ) );
			return uploaded;
		};
		if ( this.uploadRace === asset.name ) {
			this.uploadRace = null;
			addAsset();
			throw new GitHubApiError( 'asset raced', 422 );
		}
		return structuredClone( addAsset() );
	}

	async publishRelease() {
		this.calls.push( 'publishRelease' );
		this.release.draft = false;
		return structuredClone( this.release );
	}
}

function releaseFixture( assets, options = {} ) {
	const release = {
		id: 7,
		tag_name: 'broadstreet-v1.2.3',
		target_commitish: '0123456789abcdef0123456789abcdef01234567',
		name: 'Broadstreet 1.2.3',
		body: 'Fixture release',
		prerelease: false,
		draft: options.draft ?? true,
		assets: [],
		upload_url:
			'https://uploads.test/repos/owner/repo/releases/7/assets{?name,label}',
	};
	const bytes = [];
	let id = 1;
	for ( const asset of assets ) {
		release.assets.push( {
			id,
			name: asset.name,
			size: asset.bytes.length,
		} );
		bytes.push( [ id, Buffer.from( asset.bytes ) ] );
		id++;
	}
	return { release, bytes };
}

async function publishWith( api, assets = releaseAssets() ) {
	return publishPluginRelease( {
		api,
		repository: 'owner/repo',
		sha: '0123456789abcdef0123456789abcdef01234567',
		tag: 'broadstreet-v1.2.3',
		title: 'Broadstreet 1.2.3',
		notes: 'Fixture release',
		assets,
	} );
}

await runTest( 'workflow is structurally release-safe', () => {
	const workflowPath = path.join(
		repoRoot,
		'.github/workflows/wp-plugin-release.yml'
	);
	const workflow = YAML.parse( fs.readFileSync( workflowPath, 'utf8' ) );
	const ci = YAML.parse(
		fs.readFileSync(
			path.join( repoRoot, '.github/workflows/ci.yml' ),
			'utf8'
		)
	);
	assert.deepEqual( workflow.on, {
		push: {
			branches: [ 'master' ],
			paths: [ 'broadstreet.php' ],
		},
		workflow_dispatch: null,
	} );
	assert.deepEqual( workflow.permissions, { contents: 'read' } );
	assert.equal( workflow.concurrency.group, 'broadstreet-plugin-release' );
	assert.equal( workflow.concurrency.queue, 'max' );
	assert.equal( workflow.concurrency[ 'cancel-in-progress' ], false );
	assert.equal( workflow.env, undefined );
	for ( const [ jobName, job ] of Object.entries( workflow.jobs ) ) {
		assert.equal( job.env, undefined, `${ jobName } must not define job env.` );
		for ( const step of job.steps.filter( ( item ) => item.uses ) ) {
			assert.match( step.uses, /@[0-9a-f]{40}$/ );
		}
	}

	assert.equal( workflow.jobs.detect.if, "github.ref == 'refs/heads/master'" );
	for ( const jobName of [ 'detect', 'php-smoke', 'validate', 'artifact' ] ) {
		assert.deepEqual( workflow.jobs[ jobName ].permissions, {
			contents: 'read',
		} );
	}
	assert.deepEqual( workflow.jobs.publish.permissions, { contents: 'write' } );
	assert.deepEqual( workflow.jobs.artifact.needs, [
		'detect',
		'php-smoke',
		'validate',
	] );
	assert.deepEqual( workflow.jobs.publish.needs, [
		'detect',
		'php-smoke',
		'validate',
		'artifact',
	] );
	assert.equal(
		workflow.jobs.publish.if,
		"needs.detect.outputs.release == 'true'"
	);

	const checkout = workflow.jobs.detect.steps.find( ( step ) =>
		step.uses?.startsWith( 'actions/checkout@' )
	);
	assert.equal( checkout.with[ 'fetch-depth' ], 0 );
	for ( const job of Object.values( workflow.jobs ) ) {
		for ( const step of job.steps.filter( ( candidate ) =>
			candidate.uses?.startsWith( 'actions/checkout@' )
		) ) {
			assert.equal( step.with[ 'persist-credentials' ], false );
		}
	}

	const version = workflowStep( workflow, 'detect', 'version' );
	assert.equal(
		version.env.BEFORE_SHA,
		'${{ github.event.before || github.sha }}'
	);
	assert.equal( version.env.CURRENT_SHA, '${{ github.sha }}' );
	assert.equal( version.env.EVENT_NAME, '${{ github.event_name }}' );
	assert.match( version.run, /node scripts\/plugin-version\.mjs gate/ );
	assert.match( version.run, /--current "\$\{CURRENT_SHA\}"/ );
	assert.match( version.run, /workflow_dispatch[\s\S]*--retry/ );
	assert.doesNotMatch( version.run, /\$\{GITHUB_SHA\}\^/ );

	assert.deepEqual( workflow.jobs[ 'php-smoke' ].strategy.matrix.php, [
		'7.2',
		'7.4',
		'8.0',
		'8.1',
		'8.2',
		'8.3',
		'8.4',
	] );
	const phpSmoke = workflow.jobs[ 'php-smoke' ].steps.find( ( step ) =>
		/Check PHP syntax/.test( step.name )
	);
	assert.match( phpSmoke.run, /php -l[\s\S]*tests\/php\/\*\.php/ );

	const artifact = workflow.jobs.artifact.steps.find(
		( step ) => step.name === 'Build and verify release assets'
	);
	assert.match( artifact.run, /node scripts\/build-plugin-artifact\.mjs/ );
	assert.match( artifact.run, /node scripts\/verify-plugin-artifact\.mjs/ );
	assert.equal( artifact.env.BROADSTREET_ARTIFACT_ALLOW_DIRTY, 0 );
	assert.equal( artifact.env.ARTIFACT_DIR, '${{ runner.temp }}/plugin-release' );
	const transfer = workflow.jobs.artifact.steps.find( ( step ) =>
		step.uses?.startsWith( 'actions/upload-artifact@' )
	);
	assert.ok( transfer );
	assert.equal( transfer.with[ 'if-no-files-found' ], 'error' );

	const reverify = workflow.jobs.publish.steps.find(
		( step ) => step.name === 'Re-verify transferred release assets'
	);
	const publishInstall = workflow.jobs.publish.steps.find(
		( step ) => step.name === 'Install locked release helper dependencies'
	);
	assert.ok( publishInstall );
	assert.equal( publishInstall.run, 'npm ci --ignore-scripts' );
	assert.ok(
		workflow.jobs.publish.steps.indexOf( publishInstall ) <
			workflow.jobs.publish.steps.indexOf( reverify )
	);
	assert.equal( reverify.env.ARTIFACT_DIR, '${{ runner.temp }}/plugin-release' );
	assert.match( reverify.run, /verify-plugin-artifact\.mjs/ );

	const publish = workflow.jobs.publish.steps.find(
		( step ) => step.name === 'Publish or resume GitHub Release'
	);
	assert.match( publish.run, /node scripts\/publish-plugin-release\.mjs/ );
	assert.equal( publish.env.GITHUB_TOKEN, '${{ github.token }}' );
	assert.equal( publish.env.ARTIFACT_DIR, '${{ runner.temp }}/plugin-release' );
	assert.doesNotMatch( publish.run, /gh release create/ );

	const validationGates = {
		engines: /npm run check:engines/,
		lint: /npm run lint/,
		release_contract: /.\/tests\/plugin-release-workflow\.test\.sh/,
		unit: /npm test/,
		audit: /npm audit --omit=dev --audit-level=low/,
		php_tools: /composer install .*--prefer-dist/,
		php_compat: /phpcs[\s\S]*--standard=\.github\/phpcs\.xml\.dist/,
	};
	for ( const [ id, command ] of Object.entries( validationGates ) ) {
		const gate = workflow.jobs.validate.steps.find(
			( step ) => step.run && command.test( step.run )
		);
		assert.ok( gate, `${ id } validation gate is missing.` );
		assert.match( gate.run, command );
	}

	for ( const [ jobName, job ] of Object.entries( workflow.jobs ) ) {
		for ( const step of job.steps ) {
			if ( jobName !== 'publish' || step !== publish ) {
			assert.equal( step.env?.GITHUB_TOKEN, undefined );
			assert.equal( step.env?.GH_TOKEN, undefined );
			assert.doesNotMatch(
				step.run || '',
				/(?:publish-plugin-release|gh release|git push)/
			);
			}
		}
	}

	const editorSteps = ci.jobs.editor.steps;
	assert.ok(
		editorSteps.some(
			( step ) => step.run === './tests/plugin-release-workflow.test.sh'
		)
	);
} );

await runTest( 'canonical plugin versions must align', () => {
	const root = fs.mkdtempSync(
		path.join( os.tmpdir(), 'broadstreet-version-' )
	);
	try {
		writeVersionFixture( root );
		assert.equal( readAlignedPluginVersion( root ), '1.2.3' );
		for ( const mismatch of [
			{ header: '1.2.4' },
			{ runtime: '1.2.4' },
			{ packageVersion: '1.2.4' },
			{ stableTag: '1.2.4' },
		] ) {
			writeVersionFixture( root, mismatch );
			assert.throws(
				() => readAlignedPluginVersion( root ),
				/versions must match/
			);
		}
		writeVersionFixture( root, {
			header: '1.02.3',
			runtime: '1.02.3',
			packageVersion: '1.02.3',
			stableTag: '1.02.3',
		} );
		assert.throws(
			() => readAlignedPluginVersion( root ),
			/not valid SemVer/
		);
	} finally {
		fs.rmSync( root, { recursive: true, force: true } );
	}
} );

await runTest(
	'push-range version gates cover changed, unchanged, and new files',
	() => {
		const root = fs.mkdtempSync(
			path.join( os.tmpdir(), 'broadstreet-gate-' )
		);
		try {
			writeVersionFixture( root );
			const unchanged = evaluateVersionGate( {
				root,
				beforeSha: 'a'.repeat( 40 ),
				currentSha: 'b'.repeat( 40 ),
				git: fakeGitForPrevious( 'Version: 1.2.3\n' ),
			} );
			assert.deepEqual( unchanged, {
				version: '1.2.3',
				tag: '',
				release: false,
				previousVersion: '1.2.3',
			} );
			const changed = evaluateVersionGate( {
				root,
				beforeSha: 'a'.repeat( 40 ),
				currentSha: 'b'.repeat( 40 ),
				git: fakeGitForPrevious( 'Version: 1.2.2\n' ),
			} );
			assert.equal( changed.release, true );
			assert.equal( changed.tag, 'broadstreet-v1.2.3' );
			const retry = evaluateVersionGate( {
				root,
				beforeSha: 'b'.repeat( 40 ),
				currentSha: 'b'.repeat( 40 ),
				forceRelease: true,
				git: fakeGitForPrevious( 'Version: 1.2.3\n' ),
			} );
			assert.equal( retry.release, true );
			assert.equal( retry.tag, 'broadstreet-v1.2.3' );
			assert.throws(
				() =>
					evaluateVersionGate( {
						root,
						beforeSha: 'a'.repeat( 40 ),
						currentSha: 'b'.repeat( 40 ),
						git: fakeGitForPrevious( 'Version: 1.2.4\n' ),
					} ),
				/Plugin version must increase/
			);
			for ( const gate of [
				evaluateVersionGate( {
					root,
					beforeSha: '0'.repeat( 40 ),
					currentSha: 'b'.repeat( 40 ),
					git: fakeGitForPrevious( '' ),
				} ),
				evaluateVersionGate( {
					root,
					beforeSha: 'a'.repeat( 40 ),
					currentSha: 'b'.repeat( 40 ),
					git: fakeGitForPrevious( '', { missingFile: true } ),
				} ),
			] ) {
				assert.equal( gate.release, true );
				assert.equal( gate.previousVersion, '' );
			}
			assert.throws(
				() =>
					evaluateVersionGate( {
						root,
						beforeSha: 'a'.repeat( 40 ),
						currentSha: 'b'.repeat( 40 ),
						git: fakeGitForPrevious( '', { historyFailure: true } ),
					} ),
				/Unable to inspect the pre-push commit/
			);
			assert.throws(
				() =>
					evaluateVersionGate( {
						root,
						beforeSha: 'a'.repeat( 40 ),
						currentSha: 'b'.repeat( 40 ),
						git: fakeGitForPrevious( 'Version: 1.2.2\n', {
							siblingHistory: true,
						} ),
					} ),
				/not an ancestor of CURRENT_SHA/
			);
		} finally {
			fs.rmSync( root, { recursive: true, force: true } );
		}
	}
);

await runTest(
	'new release creates an exact tag and publishes exact assets',
	async () => {
		const api = new FakeReleaseApi();
		const result = await publishWith( api );
		assert.equal( result.state, 'published' );
		assert.equal( api.release.draft, false );
		assert.deepEqual(
			api.release.assets.map( ( asset ) => asset.name ).sort(),
			releaseAssets()
				.map( ( asset ) => asset.name )
				.sort()
		);
		const publishIndex = api.calls.indexOf( 'publishRelease' );
		assert.equal( api.calls[ publishIndex - 1 ], 'getTag' );
		assert.equal( api.calls[ publishIndex + 1 ], 'getTag' );
	}
);

await runTest(
	'tag and release creation races resume only exact state',
	async () => {
		const api = new FakeReleaseApi( { tagRace: true, releaseRace: true } );
		const result = await publishWith( api );
		assert.equal( result.state, 'published' );
		assert.ok(
			api.calls.filter( ( call ) => call === 'getTag' ).length >= 2
		);
		assert.ok(
			api.calls.filter( ( call ) => call === 'getRelease' ).length >= 2
		);
	}
);

await runTest( 'wrong-target tags fail closed', async () => {
	const api = new FakeReleaseApi( {
		tagRef: { object: { type: 'commit', sha: 'f'.repeat( 40 ) } },
	} );
	await assert.rejects(
		() => publishWith( api ),
		/does not target GITHUB_SHA/
	);
} );

await runTest(
	'partial draft resumes missing assets before publication',
	async () => {
		const assets = releaseAssets();
		const partial = releaseFixture( assets.slice( 0, 1 ) );
		const api = new FakeReleaseApi( {
			tagRef: {
				object: {
					type: 'commit',
					sha: '0123456789abcdef0123456789abcdef01234567',
				},
			},
			release: partial.release,
			assetBytes: partial.bytes,
		} );
		await publishWith( api, assets );
		assert.deepEqual(
			api.calls.filter( ( call ) => call.startsWith( 'upload:' ) ).sort(),
			assets
				.slice( 1 )
				.map( ( asset ) => `upload:${ asset.name }` )
				.sort()
		);
		assert.equal( api.release.draft, false );
	}
);

await runTest(
	'asset upload races verify the winner and continue',
	async () => {
		const assets = releaseAssets();
		const api = new FakeReleaseApi( {
			tagRef: {
				object: {
					type: 'commit',
					sha: '0123456789abcdef0123456789abcdef01234567',
				},
			},
			uploadRace: assets[ 0 ].name,
		} );
		await publishWith( api, assets );
		assert.equal( api.release.draft, false );
	}
);

await runTest( 'conflicting draft assets fail closed', async () => {
	const assets = releaseAssets();
	const conflicting = releaseFixture( [
		{ ...assets[ 0 ], bytes: Buffer.from( 'wrong-zip' ) },
	] );
	const api = new FakeReleaseApi( {
		tagRef: {
			object: {
				type: 'commit',
				sha: '0123456789abcdef0123456789abcdef01234567',
			},
		},
		release: conflicting.release,
		assetBytes: conflicting.bytes,
	} );
	await assert.rejects(
		() => publishWith( api, assets ),
		/asset .* does not match/
	);
} );

await runTest(
	'published releases must already contain the exact asset set',
	async () => {
		const assets = releaseAssets();
		const complete = releaseFixture( assets, { draft: false } );
		const completeApi = new FakeReleaseApi( {
			tagRef: {
				object: {
					type: 'commit',
					sha: '0123456789abcdef0123456789abcdef01234567',
				},
			},
			release: complete.release,
			assetBytes: complete.bytes,
		} );
		assert.equal(
			( await publishWith( completeApi, assets ) ).state,
			'already-published'
		);

		const incomplete = releaseFixture( assets.slice( 0, 2 ), {
			draft: false,
		} );
		const incompleteApi = new FakeReleaseApi( {
			tagRef: completeApi.tagRef,
			release: incomplete.release,
			assetBytes: incomplete.bytes,
		} );
		await assert.rejects(
			() => publishWith( incompleteApi, assets ),
			/Published release asset set is incomplete or conflicting/
		);

		const conflicting = releaseFixture(
			[
				assets[ 0 ],
				{ ...assets[ 1 ], bytes: Buffer.from( 'wrong' ) },
				assets[ 2 ],
			],
			{ draft: false }
		);
		const conflictingApi = new FakeReleaseApi( {
			tagRef: completeApi.tagRef,
			release: conflicting.release,
			assetBytes: conflicting.bytes,
		} );
		await assert.rejects(
			() => publishWith( conflictingApi, assets ),
			/asset .* does not match/
		);

		const wrongMetadata = releaseFixture( assets, { draft: false } );
		wrongMetadata.release.name = 'Conflicting release';
		const wrongMetadataApi = new FakeReleaseApi( {
			tagRef: completeApi.tagRef,
			release: wrongMetadata.release,
			assetBytes: wrongMetadata.bytes,
		} );
		await assert.rejects(
			() => publishWith( wrongMetadataApi, assets ),
			/GitHub release name conflicts/
		);
	}
);

await runTest(
	'GitHub API uploads only to the verified numeric release id',
	async () => {
		const requests = [];
		const api = new GitHubApi( {
			token: 'token',
			apiUrl: 'https://api.test',
			fetchImpl: async ( url, options ) => {
				requests.push( { url, options } );
				return new Response( '{}', { status: 201 } );
			},
		} );
		const asset = releaseAssets()[ 0 ];
		await api.uploadAsset(
			'owner/repo',
			7,
			'https://uploads.test/repos/owner/repo/releases/7/assets{?name,label}',
			asset
		);
		assert.equal( requests.length, 1 );
		assert.equal(
			requests[ 0 ].url,
			`https://uploads.test/repos/owner/repo/releases/7/assets?name=${ encodeURIComponent(
				asset.name
			) }`
		);
		assert.throws(
			() =>
				api.uploadAsset(
					'owner/repo',
					7,
					'https://uploads.test/repos/owner/repo/releases/8/assets{?name,label}',
					asset
				),
			/numeric release id/
		);
	}
);

await runTest(
	'GitHub API discovers hidden drafts across bounded release pages',
	async () => {
		const tag = 'broadstreet-v1.2.3';
		const draft = { id: 42, tag_name: tag, draft: true };
		const firstPage = Array.from( { length: 100 }, ( value, index ) => ( {
			id: index + 1,
			tag_name: `other-${ index }`,
			draft: false,
		} ) );
		const requests = [];
		const api = new GitHubApi( {
			token: 'token',
			apiUrl: 'https://api.test',
			fetchImpl: async ( url, options ) => {
				requests.push( { url, options } );
				if ( url.includes( '/releases/tags/' ) ) {
					return new Response( '{}', { status: 404 } );
				}
				const page = Number(
					new URL( url ).searchParams.get( 'page' )
				);
				return new Response(
					JSON.stringify( page === 1 ? firstPage : [ draft ] ),
					{ status: 200 }
				);
			},
		} );
		assert.deepEqual( await api.getRelease( 'owner/repo', tag ), draft );
		assert.equal( requests.length, 3 );
		assert.match( requests[ 2 ].url, /per_page=100&page=2$/ );
		for ( const request of requests ) {
			assert.equal(
				request.options.headers.Authorization,
				'Bearer token'
			);
		}
	}
);

await runTest(
	'GitHub API rejects ambiguous or unbounded release pagination',
	async () => {
		const tag = 'broadstreet-v1.2.3';
		const target = { id: 42, tag_name: tag, draft: true };
		const fullPage = Array.from( { length: 100 }, ( value, index ) => ( {
			id: index + 100,
			tag_name: `other-${ index }`,
		} ) );
		fullPage[ 0 ] = target;
		const ambiguous = new GitHubApi( {
			token: 'token',
			apiUrl: 'https://api.test',
			fetchImpl: async ( url ) => {
				if ( url.includes( '/releases/tags/' ) ) {
					return new Response( '{}', { status: 404 } );
				}
				const page = Number(
					new URL( url ).searchParams.get( 'page' )
				);
				return new Response(
					JSON.stringify( page === 1 ? fullPage : [ target ] ),
					{ status: 200 }
				);
			},
		} );
		await assert.rejects(
			() => ambiguous.getRelease( 'owner/repo', tag ),
			/multiple releases for tag/
		);

		const unbounded = new GitHubApi( {
			token: 'token',
			apiUrl: 'https://api.test',
			fetchImpl: async ( url ) =>
				url.includes( '/releases/tags/' )
					? new Response( '{}', { status: 404 } )
					: new Response(
							JSON.stringify(
								fullPage.slice( 1 ).concat( {
									id: 999,
									tag_name: 'another-release',
								} )
							),
							{ status: 200 }
					  ),
		} );
		await assert.rejects(
			() => unbounded.getRelease( 'owner/repo', tag ),
			/release pagination exceeded 20 pages/
		);
		await assert.rejects(
			() => unbounded.listAssets( 'owner/repo', 7 ),
			/release asset pagination exceeded 20 pages/
		);
	}
);

await runTest(
	'GitHub API distinguishes absence from API and network failure',
	async () => {
		const requests = [];
		const absent = new GitHubApi( {
			token: 'token',
			apiUrl: 'https://api.test',
			fetchImpl: async ( url, options ) => {
				requests.push( { url, options } );
				return url.includes( '/releases?' )
					? new Response( '[]', { status: 200 } )
					: new Response( '{}', { status: 404 } );
			},
		} );
		assert.equal( await absent.getTag( 'owner/repo', 'tag' ), null );
		assert.equal( await absent.getRelease( 'owner/repo', 'tag' ), null );
		assert.equal( requests.length, 3 );
		for ( const request of requests ) {
			assert.equal(
				request.options.headers.Authorization,
				'Bearer token'
			);
			assert.equal(
				request.options.headers[ 'X-GitHub-Api-Version' ],
				'2022-11-28'
			);
		}

		const failed = new GitHubApi( {
			token: 'token',
			apiUrl: 'https://api.test',
			fetchImpl: async () =>
				new Response( '{"message":"down"}', { status: 503 } ),
		} );
		await assert.rejects(
			() => failed.getRelease( 'owner/repo', 'tag' ),
			( error ) => error instanceof GitHubApiError && error.status === 503
		);

		const network = new GitHubApi( {
			token: 'token',
			apiUrl: 'https://api.test',
			fetchImpl: async () => {
				throw new Error( 'socket closed' );
			},
		} );
		await assert.rejects(
			() => network.getTag( 'owner/repo', 'tag' ),
			/GitHub API request failed: socket closed/
		);
	}
);

await runTest( 'GitHub API aborts stalled requests at its deadline', async () => {
	const stalled = new GitHubApi( {
		token: 'token',
		apiUrl: 'https://api.test',
		requestTimeoutMs: 10,
		fetchImpl: async ( url, options ) =>
			new Promise( ( resolve, reject ) => {
				options.signal.addEventListener(
					'abort',
					() => reject( new Error( 'aborted' ) ),
					{ once: true }
				);
			} ),
	} );
	await assert.rejects(
		() => stalled.getTag( 'owner/repo', 'tag' ),
		/GitHub API request timed out after 10ms/
	);
} );

if ( process.exitCode ) {
	process.exit( process.exitCode );
}

process.stdout.write( 'Plugin release workflow contract passed.\n' );
