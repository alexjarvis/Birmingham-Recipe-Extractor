# Recipe Repository: design

Date: 2026-09-05
Status: approved for planning

## Problem

The pipeline treats the Birmingham Pen Company storefront as the source of
truth and publishes a daily snapshot of whatever recipes it finds there. In
mid-2026 Birmingham removed nearly all recipe content. As of 2026-09-05 the
storefront lists 396 products, none tagged `recipe`, and only two with an
"Ink Recipe" block in the description. The published page therefore shows two
recipes, and the dated archive has become the only place the other 154 exist.

The archive HTML pages on `gh-pages` (121 snapshots, 2024-11-09 through
2026-08-13) are the only historical record. The daily JSON was never kept.

## Goal

Invert the model. A committed repository of every recipe ever observed is the
source of truth. The daily job scans the storefront for new or changed
recipes and merges them into the repository. The published page shows the
whole repository, marking which recipes are currently listed on the site.

## Decisions already made

- Repository lives as a JSON file on `main`, not on `gh-pages`.
- Existing dated snapshots are frozen as read-only legacy pages. No new
  snapshots are written. The archive index becomes a change log.
- Only the latest formula per recipe is stored, plus dates. No per-recipe
  formula version history beyond what the change log records.
- The daily job auto-commits repository changes. Commits are pushed to
  GitLab `main`, which is the authoritative copy, and GitLab mirrors to
  GitHub as it does for every other commit today.

## Data model

### `data/recipes.json`

```json
{
  "schema_version": 1,
  "recipes": {
    "abacus": {
      "title": "Abacus",
      "handle": "abacus",
      "image": "Abacus.jpg",
      "components": {
        "Chimney Soot": 3,
        "Dilution Solution": 2
      },
      "first_seen": "2024-11-09",
      "unlisted_on": "2026-07-21"
    }
  }
}
```

- Keyed by Shopify product handle. Handles are stable identifiers; titles are
  display text. Across all 121 snapshots no handle ever changed title, but the
  key choice protects against it.
- `recipes` is sorted by handle and `components` by ingredient name so the
  file diffs cleanly.
- `image` is the basename of the product image already stored under
  `images/` on `gh-pages`. Null when unknown.
- `components` maps ingredient name to integer part count. A recipe with an
  empty component map is not a recipe and is never stored.
- `first_seen` is the date of the scan that first observed the recipe.
- `unlisted_on` is null while the recipe is on the site. It holds the date of
  the first scan that did not find it. It is cleared if the recipe reappears.
  This field was chosen over a `last_seen` field because `last_seen` would
  change every day for every listed recipe and force a commit per day. With
  `unlisted_on`, a day with no change produces no diff.

### `data/changelog.json`

```json
{
  "schema_version": 1,
  "events": [
    {
      "date": "2025-02-21",
      "event": "added",
      "handle": "kyanite",
      "title": "Kyanite"
    },
    {
      "date": "2025-05-31",
      "event": "changed",
      "handle": "abacus",
      "title": "Abacus",
      "from": { "Chimney Soot": 3, "Dilution Solution": 1 },
      "to":   { "Chimney Soot": 3, "Dilution Solution": 2 }
    }
  ]
}
```

- Append-only, chronological. Rendered newest first.
- Only `added` and `changed` are recorded. Listing flips are not. The 2026
  snapshots show recipe counts wobbling between 141 and 145 from transient
  page-fetch failures; logging flips would bury real changes in noise. Listing
  state is still visible through `unlisted_on`.

## Scan result

The extractor and the archive parser both produce the same shape, so the
merge has one input type:

```
[
  'date'    => 'YYYY-MM-DD',
  'recipes' => [
    handle => ['title' => string, 'image' => ?string, 'components' => [name => int]],
  ],
  'failed'  => [handle, ...],   // recipe pages that could not be fetched
]
```

`failed` lets the merge distinguish "not on the site" from "could not check".
Recipes in `failed` are neither updated nor unlisted. The archive parser
always produces an empty `failed` list because snapshots carry no failure
information.

## Merge

`mergeScan(array $repository, array $changelog, array $scan): array`
returns the new repository, the new changelog, and a boolean saying whether
anything changed. It is a pure function with no I/O.

Rules, applied in this order:

1. For each recipe in the scan:
   - Not in repository: add it with `first_seen = scan.date`,
     `unlisted_on = null`. Append an `added` event.
   - In repository: replace `title`; replace `image` if the scan value is not
     null; if `components` differ in keys or values, append a `changed` event
     with `from` and `to`, then replace `components`. Set `unlisted_on = null`.
2. For each repository recipe not in the scan and not in `scan.failed`:
   if `unlisted_on` is null, set it to `scan.date`.
3. Sort recipes by handle and components by name.

A scan with zero recipes is valid input and unlists everything. That is what
happened on 2026-07-21. Protection against a broken scan being mistaken for
an empty site lives upstream in the pipeline (see Guards).

## Archive rebuild

`utility/rebuild_from_archive.php <archive-dir> <data-dir>` is a one-time
CLI script that stays in the repository. It:

1. Globs `*-recipes.html`, sorts by the date in the filename.
2. Parses each page into a scan result dated from the filename.
3. Starts from an empty repository and replays every scan through `mergeScan`.
4. Writes `recipes.json` and `changelog.json` to the data directory.

### Parsing a snapshot

All 121 snapshots share this structure, verified by a prototype parser:

- `thead` first `th` is the product column. Each remaining `th` contains an
  `<a>` whose first text node is the ingredient name.
- Each `tbody tr` has one `td` per column. The first `td` contains an `<a>`
  to `https://www.birminghampens.com/products/<handle>` whose text is the
  title, and optionally an `<img src="../images/<file>">`.
- Remaining `td` cells hold an integer or are empty.
- The 2024 pages lack the `product-name` wrapper and the 2026 pages add a
  `product-cell` wrapper and `qty-cell` class. The parser must key off the
  anchor and image, not the wrappers.
- Three snapshots (2026-07-09, 07-17, 07-21) have an empty `tbody`. They
  parse to zero recipes.

### Normalizing ingredient names

Apply in this order to every ingredient header:

1. Split comma-joined artifacts. Snapshots from the May 2026 parser bug
   (fixed in commit c075cbf) contain headers such as
   `Gunpowder, 1 part Tesla Coil`. A row with quantity `q` under that header
   means `q` parts Gunpowder and 1 part Tesla Coil. Pattern:
   `^(.+?),\s*(\d+)\s*parts?\s+(.+)$`. The first name takes the cell value,
   the second name takes the captured count. Merge into any existing entry
   for the same name by keeping the larger value.
2. Decode HTML entities (`Stoker&#039;s Ash`).
3. Pass through `correctTypos()`. This folds `Diluent`, present in 39 early
   snapshots, into `Dilution Solution`.

Labels such as `(Unreleased Element)` are genuine site content from mid-2025
and are kept verbatim. Later snapshots supersede them under latest-wins.

### Expected result

156 recipes, 2 with `unlisted_on = null` (Aluminum Oxide, Cherry Blossom),
the rest unlisted on 2026-07-21 or earlier. The first event block is 139
`added` events dated 2024-11-09.

## Pipeline

`run.php` runs these steps in order. Steps 1 and 2 are the existing scripts
with the changes noted.

1. **fetch_products** (existing). Change: a page fetch that fails after all
   retries throws instead of logging and breaking, so a partial product list
   cannot reach the merge. Zero products total also throws.
2. **recipe_extractor** (existing). Changes: remove `RECIPE_CAPTURE_MIN_RATIO`
   and its exception; emit a scan result with `failed` populated from fetch
   failures instead of the enriched product array. Keep the enriched product
   array for image lookup.
3. **merge_recipes** (new). Load `data/recipes.json` and `data/changelog.json`
   (empty repository if absent), call `mergeScan` with today's date, write both
   files back only if the changed flag is true. Print a summary of added,
   changed, and newly unlisted recipes.
4. **generate_table** (existing, reworked). Read the repository instead of
   the enriched products. Regenerate `output/index.html` on every run.
5. **generate_changes** (replaces generate_archive). Write
   `output/archive/index.html` from the changelog plus the legacy snapshot
   list.

### Guards

- Product list fetch failure or zero products: abort before merge. Nothing is
  committed or deployed; the next scheduled run retries.
- Recipe page fetch failure: recipe goes into `scan.failed`, is left as-is.
- Products present but zero recipes: legitimate, merge proceeds.

## Page

### index.html

- Stats bar: **Recipes** (repository total), **Ingredients**, **On site now**
  (count with `unlisted_on` null). Replaces the Captured percentage.
- Header date reads "Last checked <date>" instead of "Updated <date>".
- Unlisted recipes show a muted badge "Not listed since <Mon D, YYYY>" in
  both table row and card. They remain searchable and filterable. No listed-
  only filter in this iteration.
- Ingredient header images: look up by title in the repository first, then in
  today's product list. Image download to `images/` continues as now.
- Product links continue to point at the storefront handle URL even when the
  product may no longer resolve.

### archive/index.html (Changes page)

- Title "Recipe Changes", styled with the existing `template/styles.css`.
- Events newest first, grouped by date. `added` shows title; `changed` shows
  title and a from/to list of the differing ingredients.
- A "Legacy snapshots" section below lists the frozen `*-recipes.html` pages
  newest first, using the existing date-formatted link text. The "(Current)"
  entry pointing at `../` is removed.

## CI

### GitHub Actions (`deploy.yml`)

Triggers unchanged: daily schedule plus `workflow_dispatch`. A `push` trigger
was considered and rejected: the bot commit mirrored back from GitLab would
re-trigger a full scan, and `[skip ci]` cannot be used because GitLab honours
it too and would skip the mirror.

Steps:

1. Check out `gh-pages`; preserve `archive/` and `images/`. Stop preserving
   `index.html`; it is regenerated every run.
2. Check out `main`; restore preserved files into `output/`.
3. PHP setup, `composer install`, `phpstan`, `phpunit` (unchanged).
4. `php run.php`.
5. **Commit repository changes** (new). If `git status --porcelain data/`
   is non-empty:
   - Fail with a clear message if the `GITLAB_URL` secret is unset.
   - Configure the bot identity, `git add data/`, commit with message
     `Update recipe repository (<date>)` and the standard trailer.
   - `git remote add gitlab "$GITLAB_URL"`, `git fetch gitlab main`,
     `git rebase gitlab/main` so mirror lag cannot cause a non-fast-forward
     push. A rebase conflict fails the job; it means a human edited the data
     files at the same time.
   - `git push gitlab HEAD:main`.
6. Deploy `output/` to `gh-pages` with `peaceiris/actions-gh-pages`
   (unchanged). Runs after step 5 so data and page never disagree.

`GITLAB_URL` is a repository secret of the form
`https://<user>:<deploy-token>@rivendell.fivedigitinteger.com/ajarvis/birmingham-recipe-extractor.git`
with `write_repository` scope. It mirrors the `GITHUB_URL` variable GitLab
already holds for the reverse direction. Workflow `permissions` stay at
`contents: write` for the gh-pages deploy only; the bot never pushes to
GitHub `main`.

### GitLab CI (`.gitlab-ci.yml`)

No behavioural change required. The bot push arrives as an ordinary push to
`main`, which runs lint, test, docker-build, `pages`, and
`push-main-to-github`, mirroring the commit to GitHub within a minute. The
scheduled `sync-from-github` job will see `main` equal or GitLab ahead and do
nothing for `main`; its `gh-pages` handling is unchanged. The `pages` job
copies `archive images index.html template .nojekyll`, all of which keep
their layout.

### Docker

`docker run` documentation adds `-v "$PWD/data:/app/data"` so the merge can
read and write the repository.

## Code layout

Follows the existing procedural style: plain functions, callables injected
for I/O and clocks, PHPStan level 8.

- `utility/recipe_repository.php`: `emptyRepository()`, `loadRepository()`,
  `saveRepository()`, `mergeScan()`, sort helpers.
- `utility/archive_parser.php`: `parseArchiveSnapshot(string $html, string
  $date): array`, `normalizeIngredientHeader()`.
- `utility/rebuild_from_archive.php`: CLI wrapper.
- `operations/merge_recipes.php`, `operations/generate_changes.php`: new
  steps. `operations/generate_archive.php` is deleted.
- `utility/functions.php`: page generators gain the unlisted badge and new
  stats; `generateHTML` signature takes the repository and a checked date.
- `config/config.php`: `DATA_DIR`, `RECIPES_FILE`, `CHANGELOG_FILE`.
- `.gitignore`: unchanged. `output/index.html` and `output/archive/*.html`
  remain ignored; `data/` is committed.

## Testing

Unit, in new test classes beside `tests/FunctionsTest.php`:

- `RecipeRepositoryTest`: add, formula change with event payload, unlist,
  relist clears `unlisted_on`, `failed` handles are untouched, empty scan
  unlists all, unchanged scan reports no change, output ordering is stable,
  title and image updates do not emit events.
- `ArchiveParserTest`: fixtures for the 2024, 2025, and 2026 table shapes,
  comma-joined header split, entity decoding, `Diluent` correction, empty
  `tbody`, column-count mismatch raises.
- `FunctionsTest` additions: stats bar values, unlisted badge presence and
  absence, Changes page rendering for both event types.
- Rebuild end-to-end against a fixture directory of three small snapshots
  asserting final repository and changelog.

Verification after the real rebuild: 156 recipes, 2 listed, first event
block dated 2024-11-09, no ingredient name matching the comma-artifact
pattern or `Diluent`.

## Rollout

1. Implement modules and tests; `phpstan` and `phpunit` green.
2. Export the `archive/` directory from `origin/gh-pages`, run the rebuild,
   review the diff, commit `data/recipes.json` and `data/changelog.json`.
3. Create a GitLab deploy token with `write_repository`; add `GITLAB_URL` to
   GitHub repository secrets.
4. Merge the workflow change; run `workflow_dispatch`; confirm `gh-pages`
   shows 156 recipes and the Changes page, and that GitLab `main` receives
   any bot commit and mirrors it back.
5. Update `README.md` architecture and operational notes.

## Out of scope

- Full per-recipe formula version history.
- A listed-only filter toggle on the page.
- Deleting legacy snapshots from `gh-pages`.
- Pull-request based review of scan results.
- Product availability or price display.
