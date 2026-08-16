#!/usr/bin/env bash

set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
test_root="$(mktemp -d)"
dirty_probe="${repo_root}/.plugin-artifact-dirty-probe"
plugin_source="${repo_root}/broadstreet.php"
plugin_backup="${test_root}/broadstreet.php"
runtime_source="${repo_root}/Broadstreet/Config.php"
runtime_backup="${test_root}/Config.php"
package_source="${repo_root}/package.json"
package_backup="${test_root}/package.json"
readme_source="${repo_root}/readme.txt"
readme_backup="${test_root}/readme.txt"
ignored_build_probe="${repo_root}/build/.DS_Store"
dirty_probe_owned=0
ignored_build_probe_owned=0

cleanup() {
	if [[ "${dirty_probe_owned}" == 1 ]]; then
		rm -f "${dirty_probe}"
	fi
	if [[ "${ignored_build_probe_owned}" == 1 ]]; then
		rm -f "${ignored_build_probe}"
	fi
	if [[ -f "${plugin_backup}" ]]; then
		cp -p "${plugin_backup}" "${plugin_source}"
	fi
	if [[ -f "${runtime_backup}" ]]; then
		cp -p "${runtime_backup}" "${runtime_source}"
	fi
	if [[ -f "${package_backup}" ]]; then
		cp -p "${package_backup}" "${package_source}"
	fi
	if [[ -f "${readme_backup}" ]]; then
		cp -p "${readme_backup}" "${readme_source}"
	fi
	rm -rf "${test_root}"
}
trap cleanup EXIT

if [[ -e "${dirty_probe}" ]]; then
	echo "Refusing to overwrite existing dirty-checkout probe: ${dirty_probe}" >&2
	exit 1
fi

touch "${dirty_probe}"
dirty_probe_owned=1
if BROADSTREET_ARTIFACT_ALLOW_DIRTY=0 \
	node "${repo_root}/scripts/build-plugin-artifact.mjs" "${test_root}/rejected" \
	> "${test_root}/dirty-guard.log" 2>&1; then
	echo 'Artifact builder accepted a dirty checkout.' >&2
	exit 1
fi
if ! grep -Fq 'Refusing to package a dirty checkout.' "${test_root}/dirty-guard.log"; then
	cat "${test_root}/dirty-guard.log" >&2
	echo 'Artifact builder did not report the dirty-checkout guard.' >&2
	exit 1
fi
rm -f "${dirty_probe}"
dirty_probe_owned=0

cp -p "${plugin_source}" "${plugin_backup}"
cp -p "${runtime_source}" "${runtime_backup}"
cp -p "${package_source}" "${package_backup}"
cp -p "${readme_source}" "${readme_backup}"
node -e '
	const fs = require( "node:fs" );
	const file = process.argv[ 1 ];
	const source = fs.readFileSync( file, "utf8" );
	fs.writeFileSync( file, source.replace( /^Version:\s*[^\n]+$/m, "Version: 0.0.0-mismatch" ) );
' "${plugin_source}"
if BROADSTREET_ARTIFACT_ALLOW_DIRTY=1 \
	node "${repo_root}/scripts/build-plugin-artifact.mjs" "${test_root}/mismatch" \
	> "${test_root}/version-guard.log" 2>&1; then
	echo 'Artifact builder accepted mismatched PHP and package versions.' >&2
	exit 1
fi
if ! grep -Fq 'Plugin versions must match across broadstreet.php, Broadstreet/Config.php, package.json, and readme.txt' "${test_root}/version-guard.log"; then
	cat "${test_root}/version-guard.log" >&2
	echo 'Artifact builder did not report the version mismatch.' >&2
	exit 1
fi
cp -p "${plugin_backup}" "${plugin_source}"

node -e '
	const fs = require( "node:fs" );
	const file = process.argv[ 1 ];
	const source = fs.readFileSync( file, "utf8" );
	fs.writeFileSync( file, source.replace( /define\(\x27BROADSTREET_VERSION\x27,\s*\x27[^\x27]+\x27\);/, "define(\x27BROADSTREET_VERSION\x27, \x270.0.0-mismatch\x27);" ) );
' "${runtime_source}"
if BROADSTREET_ARTIFACT_ALLOW_DIRTY=1 \
	node "${repo_root}/scripts/build-plugin-artifact.mjs" "${test_root}/runtime-mismatch" \
	> "${test_root}/runtime-version-guard.log" 2>&1; then
	echo 'Artifact builder accepted a mismatched runtime version.' >&2
	exit 1
fi
if ! grep -Fq 'Plugin versions must match across broadstreet.php, Broadstreet/Config.php, package.json, and readme.txt' "${test_root}/runtime-version-guard.log"; then
	cat "${test_root}/runtime-version-guard.log" >&2
	echo 'Artifact builder did not report the runtime version mismatch.' >&2
	exit 1
fi
cp -p "${runtime_backup}" "${runtime_source}"

node -e '
	const fs = require( "node:fs" );
	const file = process.argv[ 1 ];
	const source = JSON.parse( fs.readFileSync( file, "utf8" ) );
	source.version = "0.0.0-mismatch";
	fs.writeFileSync( file, `${ JSON.stringify( source, null, "\t" ) }\n` );
' "${package_source}"
if BROADSTREET_ARTIFACT_ALLOW_DIRTY=1 \
	node "${repo_root}/scripts/build-plugin-artifact.mjs" "${test_root}/package-mismatch" \
	> "${test_root}/package-version-guard.log" 2>&1; then
	echo 'Artifact builder accepted a mismatched package version.' >&2
	exit 1
fi
if ! grep -Fq 'Plugin versions must match across broadstreet.php, Broadstreet/Config.php, package.json, and readme.txt' "${test_root}/package-version-guard.log"; then
	cat "${test_root}/package-version-guard.log" >&2
	echo 'Artifact builder did not report the package version mismatch.' >&2
	exit 1
fi
cp -p "${package_backup}" "${package_source}"

node -e '
	const fs = require( "node:fs" );
	const file = process.argv[ 1 ];
	const source = fs.readFileSync( file, "utf8" );
	fs.writeFileSync( file, source.replace( /^Stable tag:\s*[^\n]+$/m, "Stable tag: 0.0.0-mismatch" ) );
' "${readme_source}"
if BROADSTREET_ARTIFACT_ALLOW_DIRTY=1 \
	node "${repo_root}/scripts/build-plugin-artifact.mjs" "${test_root}/stable-tag-mismatch" \
	> "${test_root}/stable-tag-guard.log" 2>&1; then
	echo 'Artifact builder accepted a mismatched Stable tag.' >&2
	exit 1
fi
if ! grep -Fq 'Plugin versions must match across broadstreet.php, Broadstreet/Config.php, package.json, and readme.txt' "${test_root}/stable-tag-guard.log"; then
	cat "${test_root}/stable-tag-guard.log" >&2
	echo 'Artifact builder did not report the Stable tag mismatch.' >&2
	exit 1
fi
cp -p "${readme_backup}" "${readme_source}"

output_dir="${1:-${test_root}/artifact}"
export BROADSTREET_ARTIFACT_ALLOW_DIRTY="${BROADSTREET_ARTIFACT_ALLOW_DIRTY-0}"
node "${repo_root}/scripts/build-plugin-artifact.mjs" "${output_dir}"
node "${repo_root}/scripts/verify-plugin-artifact.mjs" "${output_dir}"

manifest_path="${output_dir}/plugin-artifact-manifest.json"
artifact_name="$(node -p 'JSON.parse(require("node:fs").readFileSync(process.argv[1], "utf8")).artifact' "${manifest_path}")"
artifact_path="${output_dir}/${artifact_name}"
expected_ref="${GITHUB_REF_NAME:-$(git -C "${repo_root}" branch --show-current)}"
expected_ref="${expected_ref:-detached}"
verify_provenance=1
if [[ "${BROADSTREET_ARTIFACT_ALLOW_DIRTY}" == 1 ]]; then
	verify_provenance=0
fi

(
	cd "${output_dir}"
	sha256sum --check "${artifact_name}.sha256"
)

node -e '
	const crypto = require( "node:crypto" );
	const fs = require( "node:fs" );
	const path = require( "node:path" );
	const AdmZip = require( "adm-zip" );
	const manifest = JSON.parse( fs.readFileSync( process.argv[ 1 ], "utf8" ) );
	const zipPath = process.argv[ 2 ];
	const expectedCommit = process.argv[ 3 ];
	const expectedBuiltAt = process.argv[ 4 ];
	const expectedRepository = process.argv[ 5 ];
	const expectedRef = process.argv[ 6 ];
	const expectedVersion = process.argv[ 7 ];
	const verifyProvenance = process.argv[ 8 ] === "1";
	const expectedArtifact = `broadstreet-${ expectedVersion }-${ expectedCommit.slice( 0, 12 ) }.zip`;
	const expectedSha256 = crypto.createHash( "sha256" ).update( fs.readFileSync( zipPath ) ).digest( "hex" );
	const files = new AdmZip( zipPath ).getEntries()
		.filter( ( entry ) => ! entry.isDirectory )
		.map( ( entry ) => entry.entryName )
		.sort();
	if ( manifest.schema !== "broadstreet-plugin-artifact/v1" ) {
		throw new Error( "Manifest schema is not recognized." );
	}
	if ( JSON.stringify( manifest.files ) !== JSON.stringify( files ) ) {
		throw new Error( "Manifest files do not match the ZIP." );
	}
	const expectedFields = [
		[ "artifact", manifest.artifact, expectedArtifact ],
		[ "sha256", manifest.sha256, expectedSha256 ],
		[ "pluginVersion", manifest.pluginVersion, expectedVersion ],
	];
	if ( verifyProvenance ) {
		expectedFields.push(
			[ "commit", manifest.commit, expectedCommit ],
			[ "builtAt", manifest.builtAt, expectedBuiltAt ],
			[ "repository", manifest.repository, expectedRepository ],
			[ "ref", manifest.ref, expectedRef ]
		);
	}
	for ( const [ field, actual, expected ] of expectedFields ) {
		if ( actual !== expected ) {
			throw new Error( `Manifest ${ field } does not match the packaged source.` );
		}
	}
	if ( path.basename( zipPath ) !== expectedArtifact ) {
		throw new Error( "ZIP filename does not identify the source version and commit." );
	}
' \
	"${manifest_path}" \
	"${artifact_path}" \
	"$(git -C "${repo_root}" rev-parse HEAD)" \
	"$(git -C "${repo_root}" show -s --format=%cI HEAD | node -p 'new Date(require("node:fs").readFileSync(0, "utf8").trim()).toISOString()')" \
	"${GITHUB_REPOSITORY:-local}" \
	"${expected_ref}" \
	"$(node -p 'require(process.argv[1]).version' "${repo_root}/package.json")" \
	"${verify_provenance}"

cp -p "${manifest_path}" "${test_root}/plugin-artifact-manifest.json"
node -e '
	const fs = require( "node:fs" );
	const file = process.argv[ 1 ];
	const manifest = JSON.parse( fs.readFileSync( file, "utf8" ) );
	manifest.pluginVersion = "0.0.0-mismatch";
	fs.writeFileSync( file, `${ JSON.stringify( manifest, null, 2 ) }\n` );
' "${manifest_path}"
if node "${repo_root}/scripts/verify-plugin-artifact.mjs" "${output_dir}" \
	> "${test_root}/manifest-version-guard.log" 2>&1; then
	echo 'Artifact verifier accepted a mismatched manifest version.' >&2
	exit 1
fi
if ! grep -Fq 'Artifact pluginVersion does not match the packaged source.' "${test_root}/manifest-version-guard.log"; then
	cat "${test_root}/manifest-version-guard.log" >&2
	echo 'Artifact verifier did not report the manifest version mismatch.' >&2
	exit 1
fi
cp -p "${test_root}/plugin-artifact-manifest.json" "${manifest_path}"

if [[ -e "${ignored_build_probe}" ]]; then
	echo "Refusing to overwrite existing ignored-build probe: ${ignored_build_probe}" >&2
	exit 1
fi
touch "${ignored_build_probe}"
ignored_build_probe_owned=1
node "${repo_root}/scripts/package-plugin.mjs"
if node -e '
	const AdmZip = require( "adm-zip" );
	const entries = new AdmZip( process.argv[ 1 ] ).getEntries().map( ( entry ) => entry.entryName );
	process.exit( entries.includes( "broadstreet/build/.DS_Store" ) ? 0 : 1 );
' "${repo_root}/broadstreet.zip"; then
	echo 'Runtime packager included an ignored build file.' >&2
	exit 1
fi
rm -f "${ignored_build_probe}"
ignored_build_probe_owned=0

if [[ "${verify_provenance}" == 1 ]]; then
	echo 'Plugin artifact contract passed.'
else
	echo 'Plugin artifact structure passed; source provenance intentionally unverified for dirty local testing.'
fi
