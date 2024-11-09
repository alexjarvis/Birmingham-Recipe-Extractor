<?php

require_once(__DIR__ . '/../config/config.php');
require_once(__DIR__ . '/../utility/functions.php');

try {
  // Ensure the archive directory exists
  checkOutputDir(ARCHIVE_DIR);

  // Initialize the HTML content for the archive index
  $archiveIndexContent = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
  $archiveIndexContent .= '<title>Recipe Archive</title></head><body>';
  $archiveIndexContent .= '<header><h1>Recipe Archive</h1></header>';
  $archiveIndexContent .= '<main>
<p>Here are all of the Recipes captures since Nov 8th 2024. Note a file will only be written if changes are detected.</p>
<ul>';

  // Get a list of archive files in ARCHIVE_DIR
  $archiveFiles = glob(ARCHIVE_DIR . '/*-recipes.html');

  // Sort files by date, most recent first
  rsort($archiveFiles);

  // Loop through each file and generate a link
  foreach ($archiveFiles as $filePath) {
    // Extract the date part from the filename (e.g., "2024-11-08" from "2024-11-08-recipes.html")
    $fileName = basename($filePath);
    if (preg_match('/(\d{4}-\d{2}-\d{2})/', $fileName, $matches)) {
      $dateString = $matches[1];
      $formattedDate = date('M j, Y', strtotime($dateString));

      // Create a link to the archive file with the formatted date
      $archiveIndexContent .= '<li><a href="' . htmlspecialchars($fileName) . '">Recipes as of ' . $formattedDate . '</a></li>';
    }
  }

  // Close the HTML tags
  $archiveIndexContent .= '</ul></main>';
  $archiveIndexContent .= '<footer><p>&copy; ' . date('Y') . ' Birmingham Pens</p></footer>';
  $archiveIndexContent .= '</body></html>';

  // Write the archive index HTML to ARCHIVE_DIR
  $indexFilePath = ARCHIVE_DIR . '/index.html';
  if (file_put_contents($indexFilePath, $archiveIndexContent) !== FALSE) {
    echo "Archive index written to $indexFilePath\n";
  }
  else {
    echo "Failed to write archive index.\n";
  }
}
catch (Exception $e) {
  echo "Error: " . $e->getMessage() . PHP_EOL;
}