<?php

// Load the JSON data from products_enriched.json
$jsonData = file_get_contents('output/products_enriched.json');
$products = json_decode($jsonData, true);

// Check if data is valid
if ($products === null || !is_array($products)) {
    die("Invalid or missing products_enriched.json data.\n");
}

$enrichedProducts = [];
$ingredientTotals = [];

// Collect all unique ingredients and filter products with recipes
foreach ($products as $product) {
    if (!empty($product['recipe_components']) && is_array($product['recipe_components'])) {
        $enrichedProducts[] = $product;
        foreach ($product['recipe_components'] as $ingredient => $quantity) {
            $ingredientTotals[$ingredient] = ($ingredientTotals[$ingredient] ?? 0) + $quantity;
        }
    }
}

// Sort ingredients and products alphabetically
$allIngredients = array_keys($ingredientTotals);
sort($allIngredients);
usort($enrichedProducts, fn($a, $b) => strcmp($a['title'], $b['title']));

// Current date for the title
$generationDate = date('F j, Y');

// Generate HTML5 structure and style with JavaScript for sorting
$html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Birmingham Ink Recipes - as of ' . $generationDate . '</title>';
$html .= '<style>
    body { font-family: Arial, sans-serif; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: center; vertical-align: top; }
    th { background-color: #f2f2f2; position: sticky; top: 0; cursor: pointer; }
    th a { color: inherit; text-decoration: none; }
    tr:nth-child(even) { background-color: #f9f9f9; }
    tr:hover { background-color: #f1f1f1; }
    tfoot { background-color: #e0e0e0; font-weight: bold; }
    .product-name { font-weight: bold; }
    .product-img { max-width: 100px; height: auto; margin-top: 10px; }
    .sort-asc::after { content: " ▲"; }
    .sort-desc::after { content: " ▼"; }
</style>';
$html .= '<script>
    document.addEventListener("DOMContentLoaded", function() {
        document.querySelectorAll("th").forEach((header, index) => {
            header.addEventListener("click", () => sortTable(index, header));
        });
    });
    
    function sortTable(columnIndex, header) {
        const table = document.querySelector("table tbody");
        const rows = Array.from(table.rows);
        const isAscending = header.classList.toggle("sort-asc", !header.classList.contains("sort-asc"));
        header.classList.toggle("sort-desc", !isAscending);
        
        // Remove sort classes from other headers
        document.querySelectorAll("th").forEach(th => {
            if (th !== header) th.classList.remove("sort-asc", "sort-desc");
        });
        
        rows.sort((a, b) => {
            const aText = a.cells[columnIndex].textContent.trim();
            const bText = b.cells[columnIndex].textContent.trim();
            
            return isAscending 
                ? aText.localeCompare(bText, undefined, {numeric: true})
                : bText.localeCompare(aText, undefined, {numeric: true});
        });

        table.innerHTML = "";
        rows.forEach(row => table.appendChild(row));
    }
</script>';
$html .= '</head><body>';
$html .= '<header><h1>Birmingham Ink Recipes - as of ' . $generationDate . '</h1></header>';
$html .= '<main><table><thead><tr><th>Product</th>';

// Column headers for each unique ingredient with links
foreach ($allIngredients as $ingredient) {
    $ingredientUrl = "https://www.birminghampens.com/products/" . urlencode(strtolower(str_replace(' ', '-', $ingredient)));
    $html .= '<th><a href="' . htmlspecialchars($ingredientUrl) . '" target="_blank">' . htmlspecialchars($ingredient) . '</a></th>';
}
$html .= '</tr></thead><tbody data-sort-dir="asc">';

// Rows for each product with recipe components
foreach ($enrichedProducts as $product) {
    $productUrl = "https://www.birminghampens.com/products/" . urlencode($product['handle']);
    $productImage = !empty($product['images']) ? $product['images'][0]['src'] : ''; // Use the first image if available

    $html .= '<tr><td>';
    $html .= '<div class="product-name"><a href="' . htmlspecialchars($productUrl) . '" target="_blank">' . htmlspecialchars($product['title']) . '</a></div>';

    // Populate cells for ingredients with quantities
    foreach ($allIngredients as $ingredient) {
        if (isset($product['recipe_components'][$ingredient])) {
            $html .= '<td>' . htmlspecialchars($product['recipe_components'][$ingredient]) . '</td>';
        } else {
            $html .= '<td></td>';
        }
    }

    // Display product image at the bottom of the row
    if ($productImage) {
        $html .= '<td colspan="' . (count($allIngredients) + 1) . '"><img src="' . htmlspecialchars($productImage) . '" alt="' . htmlspecialchars($product['title']) . '" class="product-img"></td>';
    }

    $html .= '</tr>';
}

// Footer row for total counts of each ingredient
$html .= '</tbody><tfoot><tr><td>Total Count</td>';
foreach ($allIngredients as $ingredient) {
    $html .= '<td>' . ($ingredientTotals[$ingredient] ?? 0) . '</td>';
}
$html .= '</tr></tfoot></table></main>';
$html .= '<footer><p>&copy; ' . date('Y') . ' Birmingham Pens</p></footer></body></html>';

// Write the HTML output to a file
$outputFile = 'output/products_table.html';
file_put_contents($outputFile, $html);

echo "HTML table written to $outputFile\n";