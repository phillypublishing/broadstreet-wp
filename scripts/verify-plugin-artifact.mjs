import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

import { readAlignedPluginVersion } from './plugin-version.mjs';

const scriptDirectory = path.dirname( fileURLToPath( import.meta.url ) );
const pluginRoot = path.dirname( scriptDirectory );

function git( ...args ) {
	const result = spawnSync( 'git', args, {
		cwd: pluginRoot,
		encoding: 'utf8',
	} );
	if ( result.error ) {
		throw new Error( `Unable to run git: ${ result.error.message }` );
	}
	if ( result.status !== 0 ) {
		throw new Error(
			`git ${ args.join( ' ' ) } failed${
				result.stderr ? `: ${ result.stderr.trim() }` : '.'
			}`
		);
	}
	return result.stdout.trim();
}

function zipFiles( artifactPath ) {
	const result = spawnSync( 'unzip', [ '-Z1', artifactPath ], {
		encoding: 'utf8',
	} );
	if ( result.error ) {
		throw new Error(
			`Unable to inspect plugin ZIP: ${ result.error.message }`
		);
	}
	if ( result.status !== 0 ) {
		throw new Error(
			`Unable to inspect plugin ZIP${
				result.stderr ? `: ${ result.stderr.trim() }` : '.'
			}`
		);
	}
	return result.stdout
		.trimEnd()
		.split( '\n' )
		.filter( ( entry ) => entry && ! entry.endsWith( '/' ) )
		.sort();
}

function assertEqual( field, actual, expected ) {
	if ( actual !== expected ) {
		throw new Error(
			`Artifact ${ field } does not match the packaged source.`
		);
	}
}

export function verifyPluginArtifact( outputDirectory ) {
	const manifestPath = path.join(
		outputDirectory,
		'plugin-artifact-manifest.json'
	);
	if ( ! fs.existsSync( manifestPath ) ) {
		throw new Error( 'Plugin artifact manifest is missing.' );
	}
	const manifest = JSON.parse( fs.readFileSync( manifestPath, 'utf8' ) );
	const pluginVersion = readAlignedPluginVersion( pluginRoot );
	const sourceCommit = process.env.GITHUB_SHA || git( 'rev-parse', 'HEAD' );
	const sourceEpoch = git( 'show', '-s', '--format=%ct', sourceCommit );
	const expectedBuiltAt = new Date(
		Number( sourceEpoch ) * 1000
	).toISOString();
	const expectedRepository = process.env.GITHUB_REPOSITORY || 'local';
	const expectedRef =
		process.env.GITHUB_REF_NAME ||
		git( 'branch', '--show-current' ) ||
		'detached';
	const expectedArtifact = `broadstreet-${ pluginVersion }-${ sourceCommit.slice(
		0,
		12
	) }.zip`;
	const artifactPath = path.join( outputDirectory, expectedArtifact );
	const checksumPath = `${ artifactPath }.sha256`;

	for ( const releasePath of [ artifactPath, checksumPath ] ) {
		if ( ! fs.existsSync( releasePath ) ) {
			throw new Error(
				`Plugin release asset is missing: ${ releasePath }`
			);
		}
	}

	const artifactBytes = fs.readFileSync( artifactPath );
	const artifactSha256 = crypto
		.createHash( 'sha256' )
		.update( artifactBytes )
		.digest( 'hex' );
	const files = zipFiles( artifactPath );

	assertEqual( 'schema', manifest.schema, 'broadstreet-plugin-artifact/v1' );
	assertEqual( 'repository', manifest.repository, expectedRepository );
	assertEqual( 'ref', manifest.ref, expectedRef );
	assertEqual( 'commit', manifest.commit, sourceCommit );
	assertEqual( 'pluginVersion', manifest.pluginVersion, pluginVersion );
	assertEqual( 'artifact', manifest.artifact, expectedArtifact );
	assertEqual( 'sha256', manifest.sha256, artifactSha256 );
	assertEqual( 'builtAt', manifest.builtAt, expectedBuiltAt );
	assertEqual(
		'files',
		JSON.stringify( manifest.files ),
		JSON.stringify( files )
	);
	assertEqual(
		'checksum',
		fs.readFileSync( checksumPath, 'utf8' ),
		`${ artifactSha256 }  ${ expectedArtifact }\n`
	);

	return {
		pluginVersion,
		commit: sourceCommit,
		zipPath: artifactPath,
		checksumPath,
		manifestPath,
	};
}

function optionValue( args, name ) {
	const index = args.indexOf( name );
	if ( index === -1 ) {
		return null;
	}
	if ( ! args[ index + 1 ] ) {
		throw new Error( `Missing value for ${ name }.` );
	}
	return args[ index + 1 ];
}

function main() {
	const [ outputDirectory = path.join( pluginRoot, 'dist' ), ...args ] =
		process.argv.slice( 2 );
	const result = verifyPluginArtifact( path.resolve( outputDirectory ) );
	const githubOutput = optionValue( args, '--github-output' );
	if ( githubOutput ) {
		fs.appendFileSync(
			githubOutput,
			`zip_path=${ result.zipPath }\nchecksum_path=${ result.checksumPath }\nmanifest_path=${ result.manifestPath }\n`
		);
	}
	process.stdout.write( `Verified ${ result.zipPath }\n` );
}

if (
	process.argv[ 1 ] &&
	path.resolve( process.argv[ 1 ] ) === fileURLToPath( import.meta.url )
) {
	try {
		main();
	} catch ( error ) {
		process.stderr.write( `${ error.message }\n` );
		process.exitCode = 1;
	}
}
