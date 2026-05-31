# Birmingham Recipe Extractor

A simple, unofficial utility to extract recipes from [Birmingham Pen Company](https://www.birminghampens.com) ink formulas.

The pipeline fetches every product from the Shopify storefront, pulls the recipe metafield off each recipe-tagged product page, and renders an HTML table + per-day archive.

## Architecture

- **Source on `main`** — PHP scripts under `operations/`, shared helpers in `utility/functions.php`, entry point `run.php`.
- **Daily run on GitHub Actions** — `.github/workflows/deploy.yml` runs `phpstan` → `phpunit` → `php run.php` daily at 00:00 UTC and pushes the generated HTML to the `gh-pages` branch.
- **Mirrors via GitLab CI** — `.gitlab-ci.yml` mirrors `main` between GitLab and GitHub, mirrors `gh-pages` from GitHub back to GitLab, and serves GitLab Pages from the mirrored `gh-pages` branch.
- **Output** — current snapshot at `index.html`, dated archive HTML files in `archive/`, generated only when the recipe set actually changes.

## Run locally

```sh
composer install
php run.php
```

Generated artifacts land in `output/` (gitignored).

## Run in Docker

```sh
docker build -t birmingham-recipe-extractor .
docker run --rm -v "$PWD/output:/app/output" birmingham-recipe-extractor
```

## Develop

```sh
./vendor/bin/phpunit             # tests
./vendor/bin/phpstan analyse     # static analysis (level 8)
composer test                    # alias for phpunit
composer lint                    # alias for phpstan
```

PHP 8.3+ required. CI pins PHP 8.3.

## Operational notes

- The recipe extraction step refuses to publish a snapshot if it captures less than 80% of recipe-tagged products — better to keep yesterday's data than overwrite with a broken half-snapshot. Threshold lives in `operations/recipe_extractor.php` (`RECIPE_CAPTURE_MIN_RATIO`).
- `index.html` is only rewritten when the recipe set actually differs from the previous run, keeping the dated archive directory free of duplicate snapshots.
