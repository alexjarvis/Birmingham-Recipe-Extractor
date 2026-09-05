<?php

// Parses legacy snapshot pages (output/archive/*-recipes.html) back into scan
// results so the repository can be rebuilt from them.

/**
 * Normalise an ingredient column header from a legacy snapshot.
 *
 * Snapshots written during the May 2026 parser bug (fixed in c075cbf) carry
 * headers such as "Gunpowder, 1 part Tesla Coil". A cell value q under that
 * header means q parts Gunpowder plus 1 part Tesla Coil, so the header splits
 * into a primary name (which takes the cell value) and fixed extras.
 *
 * @return array{0: string, 1: array<string, int>}
 */
function splitIngredientHeader(string $header): array {
  $header = trim(html_entity_decode($header, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
  $extras = [];
  if (preg_match('/^(.+?),\s*(\d+)\s*parts?\s+(.+)$/i', $header, $m)) {
    $header = trim($m[1]);
    $extras[canonicalIngredientName(trim($m[3]))] = (int) $m[2];
  }
  return [canonicalIngredientName($header), $extras];
}

/**
 * Parse a legacy snapshot page into a scan result (see mergeScan()).
 *
 * Every snapshot from 2024-11 to 2026-08 shares this table shape: the first
 * <th> is the product column and each other <th> wraps an <a> whose text is
 * the ingredient name; each <tbody> row's first <td> holds an <a> to the
 * product URL (text = title) and optionally an <img>; the remaining cells are
 * integer quantities or empty. Wrapper divs and classes vary by year and are
 * ignored on purpose.
 *
 * @return array{date: string, recipes: array<string, array{title: string, image: ?string, components: array<string, int>}>, failed: array<int, string>, ingredients: array<string, array{image: ?string, handle: ?string}>}
 * @throws \RuntimeException on structural surprises
 */
function parseArchiveSnapshot(string $html, string $date): array {
  $dom = new DOMDocument();
  libxml_use_internal_errors(TRUE);
  $dom->loadHTML(mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0xFFFFFF], 'UTF-8'));
  libxml_clear_errors();
  $xpath = new DOMXPath($dom);

  $headerCells = $xpath->query('//table/thead/tr/th');
  if ($headerCells === FALSE || $headerCells->length === 0) {
    throw new RuntimeException("Snapshot $date: no table header found");
  }

  /** @var array<int, array{0: string, 1: array<string, int>}> $columns */
  $columns = [];
  $ingredients = [];
  foreach ($headerCells as $index => $th) {
    if ($index === 0 || !$th instanceof DOMNode) {
      continue;
    }
    $column = splitIngredientHeader($th->textContent);
    $columns[] = $column;
    if ($column[1] !== []) {
      // Comma-artifact headers link to a slug of the whole phrase; nothing to keep.
      continue;
    }

    // Header cells carry the ingredient's product link and image; keep them
    // so the rebuild can recover images for inks the storefront dropped.
    $headerAnchors = $xpath->query('.//a[contains(@href, "/products/")]', $th);
    $headerAnchor = $headerAnchors === FALSE ? NULL : $headerAnchors->item(0);
    $headerImages = $xpath->query('.//img', $th);
    $headerImage = $headerImages === FALSE ? NULL : $headerImages->item(0);
    $ingredients[$column[0]] = [
      'image' => $headerImage instanceof DOMElement ? basename($headerImage->getAttribute('src')) : NULL,
      'handle' => $headerAnchor instanceof DOMElement ? archiveHandleFromHref($headerAnchor->getAttribute('href')) : NULL,
    ];
  }
  $expectedCells = count($columns) + 1;

  $rows = $xpath->query('//table/tbody/tr');
  $recipes = [];
  foreach ($rows === FALSE ? [] : $rows as $row) {
    if (!$row instanceof DOMNode) {
      continue;
    }
    $cells = $xpath->query('./td', $row);
    if ($cells === FALSE || $cells->length !== $expectedCells) {
      throw new RuntimeException(sprintf('Snapshot %s: row has %d cells, expected %d', $date, $cells === FALSE ? 0 : $cells->length, $expectedCells));
    }
    $productCell = $cells->item(0);
    if (!$productCell instanceof DOMNode) {
      throw new RuntimeException("Snapshot $date: row without product cell");
    }
    $anchors = $xpath->query('.//a[contains(@href, "/products/")]', $productCell);
    $anchor = $anchors === FALSE ? NULL : $anchors->item(0);
    if (!$anchor instanceof DOMElement) {
      throw new RuntimeException("Snapshot $date: product cell without product link");
    }
    $handle = archiveHandleFromHref($anchor->getAttribute('href'));
    $title = trim($anchor->textContent);

    $images = $xpath->query('.//img', $productCell);
    $img = $images === FALSE ? NULL : $images->item(0);
    $image = $img instanceof DOMElement ? basename($img->getAttribute('src')) : NULL;

    $components = [];
    for ($i = 1; $i < $cells->length; $i++) {
      $cell = $cells->item($i);
      $value = $cell instanceof DOMNode ? trim($cell->textContent) : '';
      if ($value === '') {
        continue;
      }
      if (!ctype_digit($value)) {
        throw new RuntimeException(sprintf('Snapshot %s: non-numeric quantity "%s" for %s', $date, $value, $handle));
      }
      [$primary, $extras] = $columns[$i - 1];
      $components[$primary] = max($components[$primary] ?? 0, (int) $value);
      foreach ($extras as $name => $qty) {
        $components[$name] = max($components[$name] ?? 0, $qty);
      }
    }
    if ($components === []) {
      continue;
    }
    $recipes[$handle] = ['title' => $title, 'image' => $image, 'components' => $components];
  }

  return ['date' => $date, 'recipes' => $recipes, 'failed' => [], 'ingredients' => $ingredients];
}

/**
 * Product handle from a storefront product URL as written in a snapshot.
 */
function archiveHandleFromHref(string $href): string {
  $marker = '/products/';
  $pos = strrpos($href, $marker);
  return rawurldecode(substr($href, ($pos === FALSE ? 0 : $pos) + strlen($marker)));
}

/**
 * Replay every *-recipes.html snapshot in $archiveDir, oldest first, through
 * mergeScan() starting from an empty repository.
 *
 * @param callable|null $logger fn(string $message): void  Defaults to stdout.
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}  [repository, changelog]
 * @throws \RuntimeException when the directory holds no snapshots
 */
function rebuildRepositoryFromArchive(string $archiveDir, ?callable $logger = NULL): array {
  $logger ??= fn(string $m) => print($m . PHP_EOL);
  $files = glob(rtrim($archiveDir, '/') . '/*-recipes.html') ?: [];
  sort($files, SORT_STRING);
  if ($files === []) {
    throw new RuntimeException("No *-recipes.html snapshots found in $archiveDir");
  }

  $repository = emptyRepository();
  $changelog = emptyChangelog();
  foreach ($files as $file) {
    if (!preg_match('/(\d{4}-\d{2}-\d{2})-recipes\.html$/', $file, $m)) {
      continue;
    }
    $html = file_get_contents($file);
    if ($html === FALSE) {
      throw new RuntimeException("Failed to read $file");
    }
    $scan = parseArchiveSnapshot($html, $m[1]);
    $result = mergeScan($repository, $changelog, $scan);
    $repository = $result['repository'];
    $changelog = $result['changelog'];
    $logger(sprintf(
      '%s: %d recipes in snapshot, +%d added, %d changed, %d unlisted',
      $m[1],
      count($scan['recipes']),
      count($result['summary']['added']),
      count($result['summary']['changed']),
      count($result['summary']['unlisted'])
    ));
  }
  return [$repository, $changelog];
}
