# Development

## Block editor toolchain

The block-editor sources use the WordPress 7.0 package line and the standard
`@wordpress/scripts` commands. Node.js is pinned in `.nvmrc`, npm is recorded in
`package.json`, and all JavaScript dependencies are locked by
`package-lock.json`. No global Node tools are required.

Start from a fresh checkout with:

```sh
npm ci
npm test
npm run lint
npm run build
```

Use `npm run dev` (or its standard `npm start` alias) while developing to
rebuild `src/editor.js` into `build/` on changes. Development builds may contain
source maps; run `npm run build` before committing.

## Build output policy

`src/` is authored source. `build/` is distributable output and **is committed**
so a checkout, GitHub source archive, or WordPress installation does not need
Node.js at runtime. Every source change must include the production build from
`npm run build`, including the generated `build/editor.asset.php` dependency and
version metadata. PHP reads that file instead of maintaining dependency handles
or cache versions manually.

CI runs `npm run check:build`, which rebuilds from the lockfile-backed toolchain
and fails if `build/` is missing, untracked, or different from the committed
output.

## Release artifact

Run `npm run check:package` to create and verify `broadstreet.zip`. The packager
uses an explicit runtime allowlist and stable entry ordering, permissions, and
timestamps. It packages only `broadstreet.php`, `Broadstreet/`, `build/`, the
license, and the WordPress readme. The check builds the same archive twice,
compares its SHA-256 digest, and rejects source maps or development paths such
as `node_modules`, `src`, `tests`, `scripts`, and `.github`.

The generated zip is ignored by Git. Attach that verified artifact to a release;
do not publish a working tree that contains development dependencies.

## Sponsored tracker ownership

Remote sponsored tracker IDs are server-owned state. Each ID is paired with a
protected canonical WordPress owner-post ID before it may be updated. A Yoast
Duplicate Post Rewrite & Republish draft uses its positive `_dp_original` value
as that canonical identity, so it can keep the original public permalink and
tracker. A normal duplicate is a separate owner and receives a guarded new
tracker instead of updating the copied ID.

The original and every Rewrite & Republish draft serialize reconciliation on
that same canonical owner lock. A rewrite draft with blank local tracker state
may hydrate only a tracker whose original-post ownership is proven locally; if
the original has no provable tracker, synchronization stops in `needs_action`
without creating a draft-specific tracker.

Legacy tracker IDs are stamped in place only when an authoritative postmeta
lookup finds the canonical post plus, optionally, drafts whose `_dp_original`
all point to it. Ambiguous references stop in `needs_action`; they are never
resolved from matching titles or URLs.
