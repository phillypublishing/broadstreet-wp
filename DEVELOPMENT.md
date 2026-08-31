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

## Shared client integration seam

`Broadstreet_Utility::getBroadstreetClient()` applies the `broadstreet_client`
filter to its configured client. The callback receives only that client object;
raw API key, host, and transport settings are not separate filter arguments.
Return an object implementing the Broadstreet client methods used by the calling
feature. The narrower `broadstreet_sponsor_client` filter still takes precedence
for sponsor operations and otherwise falls back through the shared seam.

## Release artifact

Run `npm run check:package` to create and verify `broadstreet.zip`. The packager
uses an explicit runtime allowlist and stable entry ordering, permissions, and
timestamps. It packages only `broadstreet.php`, `Broadstreet/`, `build/`, the
license, and the WordPress readme. The check builds the same archive twice,
compares its SHA-256 digest, and rejects source maps or development paths such
as `node_modules`, `src`, `tests`, `scripts`, and `.github`.

The generated zip is ignored by Git. The Block editor build and tests job also
publishes a 30-day GitHub Actions artifact containing an installable ZIP, its
SHA-256 checksum, and a provenance manifest. The ZIP filename includes the
plugin version and first 12 characters of the source commit. Use that artifact
for staging instead of a GitHub source archive, and verify its checksum before
installing it.

Run `npm run artifact:plugin` to create the same version- and commit-addressed
bundle under `dist/`. The builder refuses a dirty checkout by default. Do not
publish a working tree that contains development dependencies.

## Sponsored tracker synchronization

Sponsorship is synchronized inline on save (`Broadstreet_Sponsor_Sync`): the
first save of a sponsored post with an advertiser creates a tracker
advertisement and stores its ID in the server-owned
`bs_sponsor_advertisement_id` meta; later saves update the tracker's title and
URL in place. `_bs_sponsor_remote_advertiser_id` records which advertiser the
tracker currently lives under so an advertiser change is sent as a move
addressed to the old advertiser. Both keys are protected and deniable through
the generic meta APIs; only the plugin writes them.

Duplicated posts stay safe by construction rather than by ownership proofs:

- The `duplicate_post_excludelist_filter` hook excludes
  `bs_sponsor_advertisement_id` and all `_bs_sponsor_*` keys from plain Yoast
  Duplicate Post copies, so a duplicate that is marked sponsored simply
  creates its own tracker on first save.
- Yoast's Rewrite & Republish paths run with `use_filters = false` and bypass
  that excludelist, on both copy creation and republish-time meta copying.
  R&R drafts are therefore scrubbed of server-owned keys explicitly: on every
  sync attempt of the draft and again on `duplicate_post_before_republish`,
  so a republish can only copy editor-owned fields (toggle, advertiser) back
  onto the original, never a stale tracker identity.
- A Rewrite & Republish draft (`_dp_is_rewrite_republish_copy`) never
  synchronizes itself. Saving the draft re-synchronizes the original post,
  and `duplicate_post_after_republish` re-synchronizes it once more with the
  republished title/fields — the only reliable point for scheduled
  republishes, which Yoast performs after the publish transition hooks. A
  stale `_dp_original` alone (present forever on every Yoast copy) does not
  mark a post as a republish draft.
- The draft's editor panel reads and retries the original post's sync status:
  the status REST routes resolve an R&R draft to its `_dp_original`.

A stored fingerprint of (advertiser, title, URL) short-circuits saves that
change nothing sponsor-relevant, so content-only saves of a synced post make
no Broadstreet HTTP calls at all; explicit retries always re-sync.

If Broadstreet returns 404 for a stored tracker ID, an advertiser move in
flight is first retried once addressed to the intended advertiser (a lost
move response strands the old stamp while the tracker already moved).
Otherwise automatic syncs report a retryable error and never abandon the ID;
the explicit **Retry synchronization** action in the editor is the only path
that replaces a missing tracker with a new one. A short transient guard
narrows — but, being check-then-set, does not eliminate — the window for
double tracker or advertiser creation from overlapping requests. If an API
response is lost after a create succeeded, a retry can produce a duplicate
tracker in the Broadstreet dashboard; that is rare, visible, and cheap to
delete by hand, which is why the plugin no longer maintains write-ahead
journals or atomic locks for it.

Managing sponsorship (the panel, the sponsor REST routes, and sponsor meta
writes) requires the capability from the `broadstreet_sponsor_manage_capability`
filter — `edit_others_posts` by default, i.e. Editors and Administrators —
because trackers and advertisers are billable objects in the Broadstreet
account. Zone info and per-post ad visibility remain available to anyone who
can edit posts. Duplication protection is Yoast-specific: other duplication
tooling (imports, other clone plugins) should exclude `bs_sponsor_*` and
`_bs_sponsor_*` meta itself, or the copy's first save will update the
original's tracker.

A one-time, version-gated migration (`Broadstreet_Core::maybeMigrateSponsorData`,
on `admin_init`) clears reconciler-era cron events, journals, statuses, and
locks, and detaches tracker IDs that legacy duplication copied onto multiple
posts (the oldest post keeps the tracker). `scripts/cleanup-sponsor-meta.php`
(run via `wp eval-file`, dry-run by default) remains for inspecting what the
migration would do, or re-running it manually.
