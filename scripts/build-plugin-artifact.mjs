import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

import AdmZip from 'adm-zip';

import { readAlignedPluginVersion } from './plugin-version.mjs';

const scriptDirectory = path.dirname( fileURLToPath( import.meta.url ) );
const pluginRoot = path.dirname( scriptDirectory );
const outputDirectory = path.resolve(
	process.argv[ 2 ] || path.join( pluginRoot, 'dist' )
);

function run( command, args, options = {} ) {
	const result = spawnSync( command, args, {
		cwd: pluginRoot,
		encoding: 'utf8',
		...options,
	} );

	if ( result.error ) {
		throw new Error(
			`Unable to run ${ command }: ${ result.error.message }`
		);
	}

	if ( result.status !== 0 ) {
		if ( result.stdout ) {
			process.stdout.write( result.stdout );
		}
		if ( result.stderr ) {
			process.stderr.write( result.stderr );
		}
		throw new Error(
			`${ command } exited with status ${ result.status }.`
		);
	}

	return typeof result.stdout === 'string' ? result.stdout.trim() : '';
}

function git( ...args ) {
	return run( 'git', args );
}

function main() {
	const status = git( 'status', '--porcelain', '--untracked-files=all' );
	if (
		process.env.BROADSTREET_ARTIFACT_ALLOW_DIRTY !== '1' &&
		status !== ''
	) {
		throw new Error(
			'Refusing to package a dirty checkout. Commit the source or set BROADSTREET_ARTIFACT_ALLOW_DIRTY=1 for local testing.'
		);
	}

	const sourceCommit = git( 'rev-parse', 'HEAD' );
	if ( ! /^[0-9a-f]{40}$/.test( sourceCommit ) ) {
		throw new Error(
			'The checked-out source must resolve to a full lowercase Git SHA.'
		);
	}

	const sourceEpoch = git( 'show', '-s', '--format=%ct', sourceCommit );
	if ( ! /^\d+$/.test( sourceEpoch ) ) {
		throw new Error( 'The source commit timestamp must be an integer.' );
	}

	const pluginVersion = readAlignedPluginVersion( pluginRoot );

	run( 'npm', [ 'run', 'check:build' ], { stdio: 'inherit' } );
	run( process.execPath, [ 'scripts/package-plugin.mjs' ], {
		stdio: 'inherit',
	} );
	run( process.execPath, [ 'scripts/check-plugin-package.mjs' ], {
		stdio: 'inherit',
	} );

	fs.mkdirSync( outputDirectory, { recursive: true } );
	const artifactName = `broadstreet-${ pluginVersion }-${ sourceCommit.slice(
		0,
		12
	) }.zip`;
	const artifactPath = path.join( outputDirectory, artifactName );
	fs.copyFileSync( path.join( pluginRoot, 'broadstreet.zip' ), artifactPath );
	fs.chmodSync( artifactPath, 0o644 );

	const artifactBytes = fs.readFileSync( artifactPath );
	const artifactSha256 = crypto
		.createHash( 'sha256' )
		.update( artifactBytes )
		.digest( 'hex' );
	fs.writeFileSync(
		`${ artifactPath }.sha256`,
		`${ artifactSha256 }  ${ artifactName }\n`,
		{ mode: 0o644 }
	);

	const files = new AdmZip( artifactPath )
		.getEntries()
		.filter( ( entry ) => ! entry.isDirectory )
		.map( ( entry ) => entry.entryName )
		.sort();
	const manifest = {
		schema: 'broadstreet-plugin-artifact/v1',
		repository: process.env.GITHUB_REPOSITORY || 'local',
		ref:
			process.env.GITHUB_REF_NAME ||
			git( 'branch', '--show-current' ) ||
			'detached',
		commit: sourceCommit,
		pluginVersion,
		artifact: artifactName,
		sha256: artifactSha256,
		builtAt: new Date( Number( sourceEpoch ) * 1000 ).toISOString(),
		files,
	};
	fs.writeFileSync(
		path.join( outputDirectory, 'plugin-artifact-manifest.json' ),
		`${ JSON.stringify( manifest, null, 2 ) }\n`,
		{ mode: 0o644 }
	);

	process.stdout.write( `Built ${ artifactPath }\n` );
	process.stdout.write( `SHA-256 ${ artifactSha256 }\n` );
}

try {
	main();
} catch ( error ) {
	process.stderr.write( `${ error.message }\n` );
	process.exitCode = 1;
}
