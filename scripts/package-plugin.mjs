import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

import AdmZip from 'adm-zip';

process.env.TZ = 'UTC';

const scriptDirectory = path.dirname( fileURLToPath( import.meta.url ) );
const pluginRoot = path.dirname( scriptDirectory );
const archivePath = path.join( pluginRoot, 'broadstreet.zip' );
const archiveRoot = 'broadstreet';
const directories = [];
const files = [];

function collectParentDirectories( relativePath ) {
	let directory = path.dirname( relativePath );

	while ( directory !== '.' ) {
		directories.push( directory );
		directory = path.dirname( directory );
	}
}

function collectRuntimePath( relativePath ) {
	const absolutePath = path.join( pluginRoot, relativePath );
	const stat = fs.lstatSync( absolutePath );

	if ( stat.isSymbolicLink() ) {
		throw new Error(
			`Release inputs may not contain symlinks: ${ relativePath }`
		);
	}

	if ( stat.isDirectory() ) {
		directories.push( relativePath );

		for ( const entry of fs.readdirSync( absolutePath ).sort() ) {
			collectRuntimePath( path.join( relativePath, entry ) );
		}

		return;
	}

	if ( ! stat.isFile() ) {
		throw new Error( `Unsupported release input: ${ relativePath }` );
	}

	if (
		relativePath.endsWith( '.map' ) ||
		path.basename( relativePath ) === '.gitkeep'
	) {
		return;
	}

	files.push( relativePath );
}

const trackedRuntimeFiles = spawnSync(
	'git',
	[
		'ls-files',
		'-z',
		'--',
		'Broadstreet',
		'build',
		'broadstreet.php',
		'LICENSE.txt',
		'readme.txt',
	],
	{ cwd: pluginRoot, encoding: 'utf8' }
);

if ( trackedRuntimeFiles.status !== 0 ) {
	throw new Error( 'Unable to enumerate tracked runtime files.' );
}

for ( const relativePath of trackedRuntimeFiles.stdout
	.split( '\0' )
	.filter( Boolean ) ) {
	collectParentDirectories( relativePath );
	collectRuntimePath( relativePath );
}

for ( const relativePath of files ) {
	collectParentDirectories( relativePath );
}

for ( const relativePath of [ 'Broadstreet', 'build' ] ) {
	if ( ! directories.includes( relativePath ) ) {
		directories.push( relativePath );
	}
}

const zip = new AdmZip();
const stableTimestamp = new Date( Date.UTC( 1980, 0, 1, 0, 0, 0 ) );

function normalizeEntry( entryName ) {
	const entry = zip.getEntry( entryName );

	entry.header.time = stableTimestamp;
}

for ( const relativePath of [ ...new Set( directories ) ].sort() ) {
	const entryName = `${ archiveRoot }/${ relativePath.replaceAll(
		path.sep,
		'/'
	) }/`;

	zip.addFile( entryName, Buffer.alloc( 0 ), '', 0x41ed0000 );
	normalizeEntry( entryName );
}

for ( const relativePath of files.sort() ) {
	const entryName = `${ archiveRoot }/${ relativePath.replaceAll(
		path.sep,
		'/'
	) }`;

	zip.addFile(
		entryName,
		fs.readFileSync( path.join( pluginRoot, relativePath ) ),
		'',
		0x81a40000
	);
	normalizeEntry( entryName );
}

fs.rmSync( archivePath, { force: true } );
zip.writeZip( archivePath );

process.stdout.write(
	`Created ${ path.basename( archivePath ) } with ${
		files.length
	} runtime files.\n`
);
