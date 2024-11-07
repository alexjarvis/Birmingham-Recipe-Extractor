#!/bin/sh

echo "Initializing npm dependencies..."
if ! npm install; then
    echo "Failed to install npm dependencies."
    exit 1
fi

echo "Running scraper with Node.js..."
if ! node scrape.js; then
    echo "Scraping failed."
    exit 1
fi

echo "Running PHP extractor..."
if ! php recipe_extractor.php; then
    echo "PHP extraction failed."
    exit 1
fi

echo "Done"