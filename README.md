# Birmingham Recipe Extractor

A simple, unofficial utility that keeps a repository of every ink recipe
[Birmingham Pen Company](https://www.birminghampens.com) has published, and
renders it as a searchable page.

Birmingham removed nearly all recipe content from their storefront in mid-2026.
The committed repository in `data/` holds every recipe observed since
November 2024. The daily scan looks for new or changed recipes on the
storefront and merges them in; recipes the storefront no longer lists stay in
the repository and are shown with a "Not listed since" badge.

## Architecture

- **Repository on `main`** — `data/recipes.json` (one entry per product handle:
  title, image, formula, `first_seen`, `unlisted_on`) and `data/changelog.json`
  (append-only `added` / `changed` events). Both are sorted for stable diffs.
- **Source on `main`** — PHP scripts under `operations/`, shared helpers in
  `utility/`, entry point `run.php`. The pipeline is: fetch products →
  extract recipes → merge into the repository → render `index.html` →
  render the Changes page.
- **Daily run on GitHub Actions** — `.github/workflows/deploy.yml` runs
  `phpstan` → `phpunit` → `php run.php` daily at 00:00 UTC. If `data/`
  changed, it commits and pushes to GitLab `main` (the authoritative copy,
  via the `GITLAB_URL` secret), then publishes `output/` to `gh-pages`.
- **Mirrors via GitLab CI** — `.gitlab-ci.yml` mirrors `main` from GitLab to
  GitHub on every commit, mirrors `gh-pages` from GitHub back to GitLab, and
  serves GitLab Pages from the mirrored `gh-pages` branch.
- **Output** — `index.html` shows the whole repository. `archive/index.html`
  is the change log plus links to the frozen legacy snapshots
  (`archive/*-recipes.html`, Nov 2024 – Aug 2026). No new snapshots are
  written.

## Run locally

```sh
composer install
php run.php
```

Generated artifacts land in `output/` (gitignored).

## Run in Docker

```sh
docker build -t birmingham-recipe-extractor .
docker run --rm -v "$PWD/output:/app/output" -v "$PWD/data:/app/data" birmingham-recipe-extractor
```

## Develop

```sh
./vendor/bin/phpunit             # tests
./vendor/bin/phpstan analyse     # static analysis (level 8)
composer test                    # alias for phpunit
composer lint                    # alias for phpstan
```

PHP 8.5+ required. CI pins PHP 8.5.

## Operational notes

- A product-list page that fails after all retries, or a product list with
  zero products, aborts the run before the merge. A partial list would read as
  "these recipes are gone".
- A recipe page that fails to fetch is reported as `recipe_fetch_failed` and
  the merge leaves that recipe untouched. Only recipes genuinely absent from a
  successful scan get `unlisted_on` set.
- Listing flips are not change-log events; only `added` and formula `changed`
  are. Listing state lives in `unlisted_on`.
- `index.html` and `archive/index.html` are regenerated every run, so
  `gh-pages` receives a small commit daily carrying the "Last checked" date.
- Rebuilding the repository from the legacy snapshots (normally never needed):
  `php utility/rebuild_from_archive.php <dir-of-*-recipes.html> data`.
- Secret required on GitHub: `GITLAB_URL` =
  `https://<user>:<deploy-token>@<gitlab-host>/<group>/<project>.git` with
  `write_repository` scope. Without it, a run that changes `data/` fails
  rather than publish a page that disagrees with the committed repository.
