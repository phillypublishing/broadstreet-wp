import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const tagSafeVersion = /^[A-Za-z0-9._+-]+$/;
const semanticVersion =
	/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*))*))?(?:\+([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?$/;
const fullSha = /^[0-9a-f]{40}$/;

function requiredMatch( source, pattern, label ) {
	const value = source.match( pattern )?.[ 1 ]?.trim();
	if ( ! value ) {
		throw new Error( `Unable to parse ${ label }.` );
	}
	if ( ! tagSafeVersion.test( value ) ) {
		throw new Error( `${ label } is not tag-safe: ${ value }.` );
	}
	parseSemanticVersion( value, label );
	return value;
}

export function parseSemanticVersion( value, label = 'Plugin version' ) {
	const match = value?.match( semanticVersion );
	if ( ! match ) {
		throw new Error(
			`${ label } is not valid SemVer: ${ value || '<missing>' }.`
		);
	}
	return {
		major: match[ 1 ],
		minor: match[ 2 ],
		patch: match[ 3 ],
		prerelease: match[ 4 ] ? match[ 4 ].split( '.' ) : [],
	};
}

function compareNumericIdentifiers( left, right ) {
	if ( left.length !== right.length ) {
		return left.length > right.length ? 1 : -1;
	}
	if ( left === right ) {
		return 0;
	}
	return left > right ? 1 : -1;
}

function compareIdentifiers( left, right ) {
	const leftNumeric = /^\d+$/.test( left );
	const rightNumeric = /^\d+$/.test( right );
	if ( leftNumeric && rightNumeric ) {
		return compareNumericIdentifiers( left, right );
	}
	if ( leftNumeric ) {
		return -1;
	}
	if ( rightNumeric ) {
		return 1;
	}
	if ( left === right ) {
		return 0;
	}
	return left > right ? 1 : -1;
}

export function compareSemanticVersions( left, right ) {
	const a = parseSemanticVersion( left, 'Current plugin version' );
	const b = parseSemanticVersion( right, 'Previous plugin version' );
	for ( const field of [ 'major', 'minor', 'patch' ] ) {
		const comparison = compareNumericIdentifiers( a[ field ], b[ field ] );
		if ( comparison !== 0 ) {
			return comparison;
		}
	}
	if ( a.prerelease.length === 0 || b.prerelease.length === 0 ) {
		if ( a.prerelease.length === b.prerelease.length ) {
			return 0;
		}
		return a.prerelease.length === 0 ? 1 : -1;
	}
	const length = Math.max( a.prerelease.length, b.prerelease.length );
	for ( let index = 0; index < length; index++ ) {
		if ( a.prerelease[ index ] === undefined ) {
			return -1;
		}
		if ( b.prerelease[ index ] === undefined ) {
			return 1;
		}
		const comparison = compareIdentifiers(
			a.prerelease[ index ],
			b.prerelease[ index ]
		);
		if ( comparison !== 0 ) {
			return comparison;
		}
	}
	return 0;
}

export function parsePluginHeaderVersion( source ) {
	return requiredMatch(
		source,
		/^[\t ]*(?:\*[\t ]*)?Version:[\t ]*([^\s]+)[\t ]*$/m,
		'broadstreet.php Version header'
	);
}

export function parseRuntimeVersion( source ) {
	return requiredMatch(
		source,
		/define\s*\(\s*['"]BROADSTREET_VERSION['"]\s*,\s*['"]([^'"]+)['"]\s*\)\s*;/,
		'Broadstreet/Config.php BROADSTREET_VERSION'
	);
}

export function parseStableTag( source ) {
	return requiredMatch(
		source,
		/^Stable tag:[\t ]*([^\s]+)[\t ]*$/m,
		'readme.txt Stable tag'
	);
}

export function readAlignedPluginVersion( pluginRoot ) {
	const headerVersion = parsePluginHeaderVersion(
		fs.readFileSync( path.join( pluginRoot, 'broadstreet.php' ), 'utf8' )
	);
	const runtimeVersion = parseRuntimeVersion(
		fs.readFileSync(
			path.join( pluginRoot, 'Broadstreet/Config.php' ),
			'utf8'
		)
	);
	const packageVersion = JSON.parse(
		fs.readFileSync( path.join( pluginRoot, 'package.json' ), 'utf8' )
	).version;
	const stableTag = parseStableTag(
		fs.readFileSync( path.join( pluginRoot, 'readme.txt' ), 'utf8' )
	);
	parseSemanticVersion( packageVersion, 'package.json version' );

	if (
		! packageVersion ||
		! tagSafeVersion.test( packageVersion ) ||
		new Set( [ headerVersion, runtimeVersion, packageVersion, stableTag ] )
			.size !== 1
	) {
		throw new Error(
			'Plugin versions must match across broadstreet.php, Broadstreet/Config.php, package.json, and readme.txt ' +
				`(header=${ headerVersion }, runtime=${ runtimeVersion }, package=${
					packageVersion || '<missing>'
				}, stableTag=${ stableTag }).`
		);
	}

	return headerVersion;
}

function defaultGit( args, root ) {
	const result = spawnSync( 'git', args, {
		cwd: root,
		encoding: 'utf8',
	} );
	if ( result.error ) {
		throw new Error( `Unable to run git: ${ result.error.message }` );
	}
	return {
		status: result.status,
		stdout: result.stdout || '',
		stderr: result.stderr || '',
	};
}

function requireGitSuccess( result, message ) {
	if ( result.status !== 0 ) {
		throw new Error(
			`${ message }${
				result.stderr.trim() ? `: ${ result.stderr.trim() }` : '.'
			}`
		);
	}
	return result.stdout;
}

export function evaluateVersionGate( {
	root,
	beforeSha,
	currentSha,
	forceRelease = false,
	git = ( args ) => defaultGit( args, root ),
} ) {
	if ( ! fullSha.test( beforeSha ) ) {
		throw new Error( 'BEFORE_SHA must be a full lowercase Git SHA.' );
	}
	if ( ! fullSha.test( currentSha ) ) {
		throw new Error( 'CURRENT_SHA must be a full lowercase Git SHA.' );
	}

	const checkoutSha = requireGitSuccess(
		git( [ 'rev-parse', 'HEAD' ] ),
		'Unable to resolve the checked-out commit'
	).trim();
	if ( checkoutSha !== currentSha ) {
		throw new Error( 'CURRENT_SHA does not match the checked-out commit.' );
	}

	const version = readAlignedPluginVersion( root );
	let previousVersion = '';

	if ( ! /^0{40}$/.test( beforeSha ) ) {
		requireGitSuccess(
			git( [ 'cat-file', '-e', `${ beforeSha }^{commit}` ] ),
			'Unable to inspect the pre-push commit'
		);
		const ancestry = git( [
			'merge-base',
			'--is-ancestor',
			beforeSha,
			currentSha,
		] );
		if ( ancestry.status === 1 ) {
			throw new Error(
				'The pre-push commit is not an ancestor of CURRENT_SHA; refusing a force-push or sibling-history release.'
			);
		}
		requireGitSuccess(
			ancestry,
			'Unable to verify the pre-push commit ancestry'
		);
		const priorFiles = requireGitSuccess(
			git( [
				'ls-tree',
				'-z',
				'--name-only',
				beforeSha,
				'--',
				'broadstreet.php',
			] ),
			'Unable to inspect broadstreet.php in the pre-push commit'
		);
		if ( priorFiles !== '' ) {
			const priorSource = requireGitSuccess(
				git( [ 'show', `${ beforeSha }:broadstreet.php` ] ),
				'Unable to read broadstreet.php from the pre-push commit'
			);
			previousVersion = parsePluginHeaderVersion( priorSource );
		}
	}

	if ( version === previousVersion ) {
		if ( forceRelease ) {
			const tag = `broadstreet-v${ version }`;
			requireGitSuccess(
				git( [ 'check-ref-format', `refs/tags/${ tag }` ] ),
				`Version ${ version } does not produce a valid release tag`
			);
			return { version, tag, release: true, previousVersion };
		}
		return {
			version,
			tag: '',
			release: false,
			previousVersion,
		};
	}
	if (
		previousVersion &&
		compareSemanticVersions( version, previousVersion ) <= 0
	) {
		throw new Error(
			`Plugin version must increase (${ previousVersion } -> ${ version }).`
		);
	}

	const tag = `broadstreet-v${ version }`;
	requireGitSuccess(
		git( [ 'check-ref-format', `refs/tags/${ tag }` ] ),
		`Version ${ version } does not produce a valid release tag`
	);

	return {
		version,
		tag,
		release: true,
		previousVersion,
	};
}

function optionValue( args, name ) {
	const index = args.indexOf( name );
	if ( index === -1 || ! args[ index + 1 ] ) {
		throw new Error( `Missing required option ${ name }.` );
	}
	return args[ index + 1 ];
}

function main() {
	const [ command, ...args ] = process.argv.slice( 2 );
	if ( command !== 'gate' ) {
		throw new Error(
			'Usage: node scripts/plugin-version.mjs gate --before <sha> --current <sha> --github-output <path>'
		);
	}

	const pluginRoot = path.dirname(
		path.dirname( fileURLToPath( import.meta.url ) )
	);
	const result = evaluateVersionGate( {
		root: pluginRoot,
		beforeSha: optionValue( args, '--before' ),
		currentSha: optionValue( args, '--current' ),
		forceRelease: args.includes( '--retry' ),
	} );
	const githubOutput = optionValue( args, '--github-output' );
	fs.appendFileSync(
		githubOutput,
		`version=${ result.version }\ntag=${ result.tag }\nrelease=${ result.release }\n`
	);

	if ( result.release ) {
		process.stdout.write(
			`Version changed: ${
				result.previousVersion || '<new plugin>'
			} -> ${ result.version }\n`
		);
	} else {
		process.stdout.write(
			`Version unchanged (${ result.version }); no release will be created.\n`
		);
	}
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
