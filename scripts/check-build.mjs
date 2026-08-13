import { spawnSync } from 'node:child_process';

const result = spawnSync(
	'git',
	[ 'status', '--porcelain=v1', '--untracked-files=all', '--', 'build' ],
	{ encoding: 'utf8' }
);

if ( result.error ) {
	process.stderr.write(
		`Unable to inspect build output: ${ result.error.message }\n`
	);
	process.exit( 1 );
}

if ( result.status !== 0 ) {
	process.stderr.write( result.stderr || 'git status failed.\n' );
	process.exit( result.status || 1 );
}

if ( result.stdout.trim() ) {
	process.stderr.write(
		'Build output is missing or stale. Run npm run build and commit build/.\n\n'
	);
	process.stderr.write( result.stdout );
	process.exit( 1 );
}

process.stdout.write( 'Committed build output is current.\n' );
