<?php

// Load and validate JSON data
function loadProducts($filePath) {
    if (!file_exists($filePath)) {
        die("File not found: $filePath\n");
    }

    $jsonData = file_get_contents($filePath);
    $products = json_decode($jsonData, true);

    if ($products === null || !is_array($products)) {
        die("Invalid or missing JSON data in $filePath\n");
    }

    return $products;
}

// Process products to build necessary data structures
function processProducts($products) {
    $enrichedProducts = [];
    $ingredientTotals = [];
    $productImages = [];

    foreach ($products as $product) {
        // Capture main image for the product
        if (!empty($product['images'][0]['src'])) {
            $productImages[$product['title']] = $product['images'][0]['src'];
        }

        // Collect ingredients and quantities
        if (!empty($product['recipe_components']) && is_array($product['recipe_components'])) {
            $enrichedProducts[] = $product;
            foreach ($product['recipe_components'] as $ingredient => $quantity) {
                $ingredientTotals[$ingredient] = ($ingredientTotals[$ingredient] ?? 0) + $quantity;
            }
        }
    }

    // Sort enriched products by title
    usort($enrichedProducts, fn($a, $b) => strcmp($a['title'], $b['title']));

    return [$enrichedProducts, $ingredientTotals, $productImages];
}

// Generate HTML header for the table
function generateTableHeader($allIngredients, $productImages) {
    $headerHtml = '<thead><tr><th>Product/Ingredients</th>';
    foreach ($allIngredients as $ingredient) {
        $ingredientUrl = "https://www.birminghampens.com/products/" . urlencode(strtolower(str_replace(' ', '-', $ingredient)));
        $headerHtml .= '<th><a href="' . htmlspecialchars($ingredientUrl) . '" target="_blank">' . htmlspecialchars($ingredient);

        if (isset($productImages[$ingredient])) {
            $headerHtml .= '<img src="' . htmlspecialchars($productImages[$ingredient]) . '" alt="' . htmlspecialchars($ingredient) . '" class="ingredient-img">';
        }

        $headerHtml .= '</a></th>';
    }
    $headerHtml .= '</tr></thead>';
    return $headerHtml;
}

// Generate HTML footer for Recipe Count and Quantity Count
function generateTableFooter($allIngredients, $enrichedProducts, $ingredientTotals) {
    $footerHtml = '<tfoot><tr><td>Recipe Count</td>';
    foreach ($allIngredients as $ingredient) {
        $recipeCount = count(array_filter($enrichedProducts, fn($product) => isset($product['recipe_components'][$ingredient])));
        $footerHtml .= '<td>' . $recipeCount . '</td>';
    }
    $footerHtml .= '</tr><tr><td>Quantity Count</td>';
    foreach ($allIngredients as $ingredient) {
        $footerHtml .= '<td>' . ($ingredientTotals[$ingredient] ?? 0) . '</td>';
    }
    $footerHtml .= '</tr></tfoot>';
    return $footerHtml;
}

// Generate HTML for the complete table
function generateHTML($enrichedProducts, $allIngredients, $ingredientTotals, $productImages) {
    $generationDate = date('F j, Y');
    $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Birmingham Ink Recipes as of ' . $generationDate . '</title>';
    $html .= '<style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 8px; text-align: center; vertical-align: middle; border: 1px solid #ddd; }
        th { background-color: #f2f2f2; cursor: pointer; position: sticky; top: 0; }
        th a { color: inherit; text-decoration: none; display: flex; align-items: center; flex-direction: column; }
        tbody tr:nth-child(even) { background-color: #f9f9f9; }
        tr:hover { background-color: #f1f1f1; }
        tfoot { background-color: #f2f2f2; font-weight: bold; }
        .product-name { font-weight: bold; }
        .product-img { max-width: 60px; height: auto; margin-top: 5px; }
        .ingredient-img { max-width: 30px; height: auto; margin-top: 5px; }
        .sort-asc::after { content: " ▲"; }
        .sort-desc::after { content: " ▼"; }
    </style></head><body>';
    $html .= '<header><h1>Birmingham Ink Recipes as of ' . $generationDate . '</h1></header>';
    $html .= '<main><table>';

    // Generate table header and footer
    $html .= generateTableHeader($allIngredients, $productImages);

    // Table body with product data
    $html .= '<tbody>';
    foreach ($enrichedProducts as $product) {
        $productUrl = "https://www.birminghampens.com/products/" . urlencode($product['handle']);
        $productImage = $productImages[$product['title']] ?? '';

        $html .= '<tr><td><div class="product-name"><a href="' . htmlspecialchars($productUrl) . '" target="_blank">' . htmlspecialchars($product['title']) . '</a></div>';
        if ($productImage) {
            $html .= '<img src="' . htmlspecialchars($productImage) . '" alt="' . htmlspecialchars($product['title']) . '" class="product-img">';
        }
        $html .= '</td>';

        foreach ($allIngredients as $ingredient) {
            $html .= '<td>' . ($product['recipe_components'][$ingredient] ?? '') . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody>';

    // Add footer with counts
    $html .= generateTableFooter($allIngredients, $enrichedProducts, $ingredientTotals);
    $html .= '</table></main>';

    // Footer and script for table sorting
    $html .= '<footer><p>&copy; ' . date('Y') . ' Birmingham Pens</p></footer>';
    $html .= '<script>
        document.addEventListener("DOMContentLoaded", function() {
            document.querySelectorAll("th").forEach((header, index) => {
                header.addEventListener("click", () => sortTable(index, header));
            });
        });
        
        function sortTable(columnIndex, header) {
            const table = document.querySelector("table tbody");
            const rows = Array.from(table.rows);
            const isAscending = header.classList.toggle("sort-desc", !header.classList.contains("sort-desc"));
            
            document.querySelectorAll("th").forEach(th => {
                if (th !== header) th.classList.remove("sort-asc", "sort-desc");
            });
            
            rows.sort((a, b) => {
                const aText = a.cells[columnIndex].textContent.trim();
                const bText = b.cells[columnIndex].textContent.trim();
                
                return isAscending 
                    ? bText.localeCompare(aText, undefined, {numeric: true})
                    : aText.localeCompare(bText, undefined, {numeric: true});
            });

            table.innerHTML = "";
            rows.forEach(row => table.appendChild(row));
        }
    </script></body></html>';
    return $html;
}

// Main execution
$products = loadProducts('output/products_enriched.json');
[$enrichedProducts, $ingredientTotals, $productImages] = processProducts($products);

// Gather all unique ingredients sorted alphabetically
$allIngredients = array_keys($ingredientTotals);
sort($allIngredients);

// Generate and save the HTML file
$html = generateHTML($enrichedProducts, $allIngredients, $ingredientTotals, $productImages);
$outputFile = 'output/products_table.html';

if (file_put_contents($outputFile, $html) !== false) {
    echo "HTML table written to $outputFile\n";
} else {
    echo "Failed to write to $outputFile\n";
}