<?php

/**
 * Cleanup script to remove duplicate archive files
 * Keeps only the oldest version when table content is identical
 */

/**
 * Extracts the HTML content of the <table> element from a given HTML file.
 *
 * @param string $filePath The path to the HTML file.
 *
 * @return string The HTML content of the <table> element.
 */
function extractTableContent(string $filePath): string {
  $dom = new DOMDocument();
  libxml_use_internal_errors(TRUE); // Suppress warnings for invalid HTML
  $dom->loadHTMLFile($filePath);
  libxml_clear_errors();

  // Find the table element
  $table = $dom->getElementsByTagName('table')->item(0);

  // Return the table HTML as a string, or an empty string if not found
  return $table ? $dom->saveHTML($table) : '';
}

echo "=== Archive Duplicate Cleanup Script ===\n\n";

// Get all archive files
$archiveDir = __DIR__ . '/archive';
$archiveFiles = glob($archiveDir . '/*-recipes.html');

if (empty($archiveFiles)) {
    echo "No archive files found.\n";
    exit(0);
}

// Sort files by name (which is date-based, so chronological)
sort($archiveFiles);

echo "Found " . count($archiveFiles) . " archive files.\n";
echo "Analyzing content...\n\n";

// Group files by their table content hash
$contentGroups = [];
$fileHashes = [];

foreach ($archiveFiles as $file) {
    $basename = basename($file);

    // Extract table content
    $tableContent = extractTableContent($file);

    // Normalize paths before hashing to ensure consistent comparison
    // (archives use '../images' while index.html uses 'images')
    $tableContentNormalized = str_replace(['../images', '../template'], ['images', 'template'], $tableContent);

    // Create a hash of the normalized table content (excluding date/timestamp and path differences)
    $hash = md5($tableContentNormalized);

    $fileHashes[$file] = $hash;

    if (!isset($contentGroups[$hash])) {
        $contentGroups[$hash] = [];
    }

    $contentGroups[$hash][] = $file;
}

echo "Found " . count($contentGroups) . " unique versions.\n\n";

// Process each group
$toDelete = [];
$toKeep = [];

foreach ($contentGroups as $hash => $files) {
    if (count($files) > 1) {
        // Keep the first (oldest) file, mark rest for deletion
        $keep = array_shift($files);
        $toKeep[] = basename($keep);

        foreach ($files as $file) {
            $toDelete[] = $file;
        }

        echo "Content group: " . substr($hash, 0, 8) . "... (" . (count($files) + 1) . " files)\n";
        echo "  Keeping:  " . basename($keep) . "\n";
        echo "  Deleting: " . count($files) . " duplicate(s)\n";
    } else {
        // Only one file with this content
        $toKeep[] = basename($files[0]);
    }
}

echo "\n=== Summary ===\n";
echo "Unique versions to keep: " . count($toKeep) . "\n";
echo "Duplicates to delete:    " . count($toDelete) . "\n";

if (empty($toDelete)) {
    echo "\nNo duplicates found. Archive is already clean!\n";
    exit(0);
}

// Ask for confirmation
echo "\nProceed with deletion? (y/N): ";
$handle = fopen("php://stdin", "r");
$line = trim(fgets($handle));
fclose($handle);

if (strtolower($line) !== 'y') {
    echo "Aborted. No files were deleted.\n";
    exit(0);
}

// Delete the duplicate files
echo "\nDeleting duplicate files...\n";
$deletedCount = 0;

foreach ($toDelete as $file) {
    if (unlink($file)) {
        echo "  ✓ Deleted: " . basename($file) . "\n";
        $deletedCount++;
    } else {
        echo "  ✗ Failed to delete: " . basename($file) . "\n";
    }
}

echo "\n=== Cleanup Complete ===\n";
echo "Deleted: $deletedCount files\n";
echo "Kept:    " . count($toKeep) . " files\n";

// Now regenerate the archive index
echo "\nRegenerating archive index...\n";

// Get the updated list of archive files
$remainingFiles = glob($archiveDir . '/*-recipes.html');
sort($remainingFiles);
rsort($remainingFiles); // Most recent first

$html = '<!DOCTYPE html><html lang="en" data-theme="light"><head><meta charset="UTF-8">';
$html .= '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
$html .= '<title>Birmingham Ink Recipe Archive</title>';
$html .= '<link rel="stylesheet" href="../template/styles.css">';
$html .= '<style>';
$html .= '.archive-list { max-width: 800px; margin: 2rem auto; padding: 0 1rem; }';
$html .= '.archive-list h2 { color: var(--text-primary); margin-bottom: 1.5rem; font-size: 1.5rem; }';
$html .= '.archive-list ul { list-style: none; padding: 0; }';
$html .= '.archive-list li { background: var(--bg-secondary); border: 1px solid var(--border-color); ';
$html .= 'border-radius: 8px; margin-bottom: 0.75rem; transition: all 0.2s ease; }';
$html .= '.archive-list li:hover { transform: translateX(4px); box-shadow: 0 2px 8px var(--shadow); }';
$html .= '.archive-list a { display: block; padding: 1rem 1.5rem; color: var(--text-primary); ';
$html .= 'text-decoration: none; font-size: 1.1rem; }';
$html .= '.archive-list a:hover { color: var(--accent-primary); }';
$html .= '.archive-list .current { color: var(--accent-primary); font-weight: 600; }';
$html .= '.archive-list em { color: var(--text-secondary); font-style: normal; ';
$html .= 'font-size: 0.9rem; margin-left: 0.5rem; }';
$html .= 'footer { text-align: center; padding: 2rem; color: var(--text-secondary); ';
$html .= 'border-top: 1px solid var(--border-color); margin-top: 3rem; }';
$html .= '</style>';
$html .= '</head><body>';
$html .= '<header>';
$html .= '<div class="header-content">';
$html .= '<div class="header-top">';
$html .= '<div><h1>Birmingham Ink Recipes</h1><div class="header-date">Recipe Archive</div></div>';
$html .= '<div class="header-actions">';
$html .= '<div class="theme-toggle" id="themeToggle"></div>';
$html .= '<a href="../" class="btn btn-icon" title="Current Recipes">🏠</a>';
$html .= '</div></div></div></header>';
$html .= '<main><div class="archive-list"><h2>Recipe History</h2><ul>';

foreach ($remainingFiles as $file) {
    $basename = basename($file);
    $date = str_replace(['-recipes.html', '-'], ['', '/'], $basename);

    // Check if this is the current version
    $currentFile = file_get_contents(__DIR__ . '/index.html');
    $archiveFileContent = file_get_contents($file);

    // Extract just table content for comparison
    $currentTable = extractTableContent(__DIR__ . '/index.html');
    $archiveTable = extractTableContent($file);

    // Normalize paths before comparison
    $currentTableNormalized = str_replace(['../images', '../template'], ['images', 'template'], $currentTable);
    $archiveTableNormalized = str_replace(['../images', '../template'], ['images', 'template'], $archiveTable);

    if ($currentTableNormalized === $archiveTableNormalized) {
        // This is the current version
        $html .= '<li><a href="../" class="current">Recipes as of ' . $date . ' <em>(Current)</em></a></li>';
    } else {
        $html .= '<li><a href="' . htmlspecialchars($basename) . '">Recipes as of ' . $date . '</a></li>';
    }
}

$html .= '</ul></div></main>';
$html .= '<footer><p>&copy; ' . date('Y') . ' Birmingham Pens</p></footer>';
$html .= '<script src="../template/script.js"></script>';
$html .= '</body></html>';

file_put_contents($archiveDir . '/index.html', $html);
echo "Archive index regenerated.\n";
echo "Done!\n";
