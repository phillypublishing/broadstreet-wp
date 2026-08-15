import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

import AdmZip from 'adm-zip';

const scriptDirectory = path.dirname( fileURLToPath( import.meta.url ) );
const pluginRoot = path.dirname( scriptDirectory );
const archivePath = path.join( pluginRoot, 'broadstreet.zip' );
const packageScript = path.join( scriptDirectory, 'package-plugin.mjs' );

function archiveHash() {
	return crypto
		.createHash( 'sha256' )
		.update( fs.readFileSync( archivePath ) )
		.digest( 'hex' );
}

const firstHash = archiveHash();
const packageResult = spawnSync( process.execPath, [ packageScript ], {
	cwd: pluginRoot,
	stdio: 'inherit',
} );

if ( packageResult.status !== 0 ) {
	process.exit( packageResult.status || 1 );
}

const secondHash = archiveHash();

if ( firstHash !== secondHash ) {
	throw new Error(
		'Packaging the same runtime tree produced different archive bytes.'
	);
}

const entries = new AdmZip( archivePath )
	.getEntries()
	.map( ( entry ) => entry.entryName );
const requiredFiles = [
	'broadstreet/broadstreet.php',
	'broadstreet/Broadstreet/ZoneController.php',
	'broadstreet/build/editor.js',
	'broadstreet/build/editor.asset.php',
];

for ( const requiredFile of requiredFiles ) {
	if ( ! entries.includes( requiredFile ) ) {
		throw new Error( `Runtime archive is missing ${ requiredFile }.` );
	}
}

const unexpectedEntries = entries.filter( ( entry ) => {
	if ( entry.endsWith( '/' ) ) {
		return ! (
			entry.startsWith( 'broadstreet/Broadstreet/' ) ||
			entry.startsWith( 'broadstreet/build/' )
		);
	}

	return ! (
		entry.startsWith( 'broadstreet/Broadstreet/' ) ||
		entry.startsWith( 'broadstreet/build/' ) ||
		[
			'broadstreet/broadstreet.php',
			'broadstreet/LICENSE.txt',
			'broadstreet/readme.txt',
		].includes( entry )
	);
} );

if ( unexpectedEntries.length ) {
	throw new Error(
		`Runtime archive contains unexpected entries:\n${ unexpectedEntries.join(
			'\n'
		) }`
	);
}

const forbiddenEntries = entries.filter(
	( entry ) =>
		/(^|\/)node_modules(\/|$)/.test( entry ) ||
		/(^|\/)(src|tests|scripts|\.github)(\/|$)/.test( entry ) ||
		/\.map$/.test( entry ) ||
		/(^|\/)package(?:-lock)?\.json$/.test( entry )
);

if ( forbiddenEntries.length ) {
	throw new Error(
		`Runtime archive contains development files:\n${ forbiddenEntries.join(
			'\n'
		) }`
	);
}

process.stdout.write(
	`Runtime archive is deterministic and contains ${ entries.length } entries (${ secondHash }).\n`
);
