#!/bin/sh

echo "Running fetch products..."
if ! php fetch_products.php; then
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
