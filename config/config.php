<?php

$appRoot = realpath(__DIR__ . '/..');

// The scan date stamps merge events and the "Last checked" header. Override
// with SCAN_DATE=YYYY-MM-DD when replaying or testing.
$scanDate = getenv('SCAN_DATE');
define("SCAN_DATE", is_string($scanDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $scanDate) ? $scanDate : date('Y-m-d'));

define("OUTPUT_DIR", $appRoot . '/output');
define("ARCHIVE_DIR", OUTPUT_DIR . '/archive');
define("TEMPLATE_DIR", OUTPUT_DIR . '/template');
define("IMAGE_DIR", OUTPUT_DIR . '/images');
define("INDEX_FILE", OUTPUT_DIR . '/index.html');
define("CHANGES_FILE", ARCHIVE_DIR . '/index.html');
// Legacy dated snapshot path; removed once generate_table.php stops writing snapshots.
define("ARCHIVE_FILE", ARCHIVE_DIR . '/' . SCAN_DATE . '-recipes.html');

// Scratch inputs for a single run. Not published, not committed.
define("PRODUCTS_DIR", $appRoot . '/var/products');
define("PRODUCTS_FILE", PRODUCTS_DIR . '/' . SCAN_DATE . '-products.json');
define("ENRICHED_PRODUCTS_FILE", PRODUCTS_DIR . '/' . SCAN_DATE . '-products_enriched.json');

// The committed recipe repository.
define("DATA_DIR", $appRoot . '/data');
define("RECIPES_FILE", DATA_DIR . '/recipes.json');
define("CHANGELOG_FILE", DATA_DIR . '/changelog.json');

const BIRMINGHAM_BASE_URL = 'https://www.birminghampens.com';
const FETCH_LIMIT = 100;
const FETCH_MAX_RETRIES = 5;

const PRODUCTS_URL = BIRMINGHAM_BASE_URL . '/products.json';
const PRODUCT_URL = BIRMINGHAM_BASE_URL . '/products/';
