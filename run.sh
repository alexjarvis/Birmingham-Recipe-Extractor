#!/bin/sh

echo "Initializing npm dependencies..."
if ! npm install; then
    echo "Failed to install npm dependencies."
    exit 1
fi

echo "Running fetch products..."
if ! node fetch_products.js; then
    echo "fetch products failed."
    exit 1
fi

echo "Running recipe extraction..."
if ! php recipe_extractor.php; then
    echo "Recipe extraction failed."
    exit 1
fi

echo "Running table generation..."
if ! php generate_table.php; then
    echo "Table generation failed."
    exit 1
fi

echo "Done"
