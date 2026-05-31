<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/../utility/functions.php';
require_once __DIR__ . '/../config/config.php';

final class FunctionsTest extends TestCase
{
    #[DataProvider('cleanImageNameProvider')]
    public function testCleanImageName(string $imageUrl, string $expected): void
    {
        $this->assertSame($expected, cleanImageName($imageUrl));
    }

    public static function cleanImageNameProvider(): array
    {
        return [
            'simple filename' => [
                'https://example.com/images/product.jpg',
                'product.jpg',
            ],
            'filename with query params' => [
                'https://example.com/images/product.jpg?v=123&width=500',
                'product.jpg',
            ],
            'nested path' => [
                'https://cdn.example.com/assets/products/2024/image.png',
                'image.png',
            ],
            'filename with spaces encoded' => [
                'https://example.com/images/my%20product.jpg',
                'my%20product.jpg',
            ],
        ];
    }

    #[DataProvider('correctTyposProvider')]
    public function testCorrectTypos(string $input, string $expected): void
    {
        $this->assertSame($expected, correctTypos($input));
    }

    public static function correctTyposProvider(): array
    {
        return [
            'Saltwater Taffy correction' => ['Saltwater Taffy', 'Salt Water Taffy'],
            'Sterling Siver correction' => ['Sterling Siver', 'Sterling Silver'],
            'Tiger Lil correction' => ['Tiger Lil', 'Tiger Lily'],
            'Teaberry Ice Crea correction' => ['Teaberry Ice Crea', 'Teaberry Ice Cream'],
            'Diluent correction' => ['Diluent', 'Dilution Solution'],
            'Dilution correction' => ['Dilution', 'Dilution Solution'],
            'no correction needed' => ['Blue Velvet', 'Blue Velvet'],
            'unknown name passes through' => ['Random Ink Name', 'Random Ink Name'],
        ];
    }

    #[DataProvider('quantityClassProvider')]
    public function testGetQuantityClass(int $quantity, string $expected): void
    {
        $this->assertSame($expected, getQuantityClass($quantity));
    }

    public static function quantityClassProvider(): array
    {
        return [
            'quantity 1 is low' => [1, 'qty-low'],
            'quantity 10 is low' => [10, 'qty-low'],
            'quantity 11 is medium' => [11, 'qty-medium'],
            'quantity 50 is medium' => [50, 'qty-medium'],
            'quantity 51 is high' => [51, 'qty-high'],
            'quantity 100 is high' => [100, 'qty-high'],
        ];
    }

    #[DataProvider('footerRowProvider')]
    public function testGenerateFooterRow(string $label, array $data, string $expectedContains): void
    {
        $result = generateFooterRow($label, $data);

        $this->assertStringContainsString("<tr><td>$label</td>", $result);
        $this->assertStringContainsString('</tr>', $result);
        foreach ($data as $value) {
            $this->assertStringContainsString("<td>$value</td>", $result);
        }
    }

    public static function footerRowProvider(): array
    {
        return [
            'simple row' => [
                'Recipe Count',
                [5, 10, 15],
                '<tr><td>Recipe Count</td>',
            ],
            'quantity row' => [
                'Quantity Count',
                [100, 200, 300],
                '<tr><td>Quantity Count</td>',
            ],
        ];
    }

    public function testFetchProductPageReturnsBodyOnFirstAttemptSuccess(): void
    {
        $calls = 0;
        $fetcher = function (string $url) use (&$calls): array {
            $calls++;
            return ['status' => 200, 'body' => 'hello'];
        };
        $sleeps = [];
        $sleeper = function (int $s) use (&$sleeps): void { $sleeps[] = $s; };

        $body = fetchProductPage('https://example.test/x', 3, $fetcher, $sleeper);

        $this->assertSame('hello', $body);
        $this->assertSame(1, $calls);
        $this->assertSame([], $sleeps, 'must not sleep on first-attempt success');
    }

    public function testFetchProductPageReturnsNullAfterMaxAttemptsAllFail(): void
    {
        $calls = 0;
        $fetcher = function (string $url) use (&$calls): array {
            $calls++;
            return ['status' => 500, 'body' => 'err'];
        };
        $sleeper = function (int $s): void {};

        $body = fetchProductPage('https://example.test/x', 4, $fetcher, $sleeper);

        $this->assertNull($body);
        $this->assertSame(4, $calls);
    }

    public function testFetchProductPageRetriesUntilSuccess(): void
    {
        $statuses = [500, 503, 200];
        $bodies = ['', '', 'recipe-html'];
        $i = 0;
        $fetcher = function (string $url) use (&$i, $statuses, $bodies): array {
            $r = ['status' => $statuses[$i], 'body' => $bodies[$i]];
            $i++;
            return $r;
        };
        $sleeps = [];
        $sleeper = function (int $s) use (&$sleeps): void { $sleeps[] = $s; };

        $body = fetchProductPage('https://example.test/x', 5, $fetcher, $sleeper);

        $this->assertSame('recipe-html', $body);
        $this->assertSame(3, $i);
        $this->assertCount(2, $sleeps, 'sleeps once between each retry, not after success');
    }

    public function testFetchProductPageBackoffIsExponential(): void
    {
        $fetcher = function (string $url): array {
            return ['status' => 500, 'body' => ''];
        };
        $sleeps = [];
        $sleeper = function (int $s) use (&$sleeps): void { $sleeps[] = $s; };

        fetchProductPage('https://example.test/x', 4, $fetcher, $sleeper);

        $this->assertSame([2, 4, 8], $sleeps, 'expected 2,4,8 second backoff between 4 attempts');
    }

    public function testFetchProductPageDoesNotSleepAfterFinalAttempt(): void
    {
        $fetcher = function (string $url): array {
            return ['status' => 500, 'body' => ''];
        };
        $sleeps = [];
        $sleeper = function (int $s) use (&$sleeps): void { $sleeps[] = $s; };

        fetchProductPage('https://example.test/x', 3, $fetcher, $sleeper);

        $this->assertCount(2, $sleeps, '3 attempts → 2 sleeps (none after the last failed attempt)');
    }

    public function testFetchProductPageTreatsCurlErrorAsRetryable(): void
    {
        $i = 0;
        $fetcher = function (string $url) use (&$i): array {
            $i++;
            if ($i < 3) {
                return ['status' => 0, 'body' => false]; // curl error
            }
            return ['status' => 200, 'body' => 'ok'];
        };
        $sleeper = function (int $s): void {};

        $body = fetchProductPage('https://example.test/x', 5, $fetcher, $sleeper);

        $this->assertSame('ok', $body);
        $this->assertSame(3, $i);
    }

    public function testFetchProductPageTreatsNon200StatusAsFailure(): void
    {
        // 403 is what Shopify returns when bot-blocking — must not be treated as success.
        $fetcher = function (string $url): array {
            return ['status' => 403, 'body' => '<html>blocked</html>'];
        };
        $sleeper = function (int $s): void {};

        $body = fetchProductPage('https://example.test/x', 2, $fetcher, $sleeper);

        $this->assertNull($body);
    }

    public function testExtractRecipeHtmlFromPageReturnsMetafieldInnerHtml(): void
    {
        $page = '<html><body>'
              . '<div class="metafield-rich_text_field"><p>+ 4 Parts Electron</p><p>+ 1 Part Gunpowder</p></div>'
              . '</body></html>';

        $result = extractRecipeHtmlFromPage($page);

        $this->assertStringContainsString('Electron', $result);
        $this->assertStringContainsString('Gunpowder', $result);
        $this->assertStringNotContainsString('metafield-rich_text_field', $result, 'should return inner HTML, not the wrapper');
    }

    public function testExtractRecipeHtmlFromPageReturnsEmptyWhenMissing(): void
    {
        $page = '<html><body><p>no recipe here</p></body></html>';

        $this->assertSame('', extractRecipeHtmlFromPage($page));
    }

    #[DataProvider('parseRecipeProvider')]
    public function testParseRecipeComponents(string $label, string $html, array $expected): void
    {
        $this->assertSame($expected, parseRecipeComponents($html));
    }

    public static function parseRecipeProvider(): array
    {
        return [
            'plus-prefix lowercase parts' => [
                'sugar-kelp',
                '<p>+ 10 parts Tiger Lily</p><p>+ 5 parts Airline</p><p>+ 2 parts Sterling Silver</p>',
                ['Tiger Lily' => 10, 'Airline' => 5, 'Sterling Silver' => 2],
            ],
            'plus-prefix uppercase Parts' => [
                'diving-bell',
                '<p>+ 4 Parts Electron</p><p>+ 1 Part Gunpowder</p>',
                ['Electron' => 4, 'Gunpowder' => 1],
            ],
            'br-separated single paragraph (oil beetle)' => [
                'oil-beetle',
                "<p>2 Parts Electron<br>\n3 Parts Gunpowder</p>",
                ['Electron' => 2, 'Gunpowder' => 3],
            ],
            'applies typo correction (Sterling Siver → Silver)' => [
                'alfalfa-typo',
                '<p>+ 5 Parts Sterling Siver</p>',
                ['Sterling Silver' => 5],
            ],
            'filters lowercase-starting names' => [
                'lowercase',
                '<p>+ 5 parts ml of water</p>',
                [],
            ],
            'comma-separated ingredients in one li (Voltaic Arc format)' => [
                'voltaic-arc',
                '<li><strong>Sample Recipe:</strong> 1 part Gunpowder, 1 part Tesla Coil</li>',
                ['Gunpowder' => 1, 'Tesla Coil' => 1],
            ],
            'comma-separated with mixed quantities (Quantum Teal format)' => [
                'quantum-teal',
                '<li><strong>Sample Recipe:</strong> 4 parts Tesla Coil, 1 part Emerald Fusion</li>',
                ['Tesla Coil' => 4, 'Emerald Fusion' => 1],
            ],
            'three comma-separated ingredients' => [
                'three-comma',
                '<li>2 parts Apple, 3 parts Banana, 5 parts Cherry</li>',
                ['Apple' => 2, 'Banana' => 3, 'Cherry' => 5],
            ],
        ];
    }

    public function testCreateHttpContextReturnsContextWithUserAgent(): void
    {
        $ctx = createHttpContext();
        $opts = stream_context_get_options($ctx);

        $this->assertArrayHasKey('http', $opts);
        $this->assertArrayHasKey('header', $opts['http']);
        $this->assertStringContainsString('User-Agent:', $opts['http']['header']);
        $this->assertStringContainsString('Mozilla/5.0', $opts['http']['header']);
    }

    public function testFormatNodeRendersTextNode(): void
    {
        $dom = new DOMDocument();
        $dom->loadHTML('<div>hello</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $output = formatNode($dom->documentElement);

        $this->assertStringContainsString('hello', $output);
    }

    public function testFormatNodeIndentsNestedElements(): void
    {
        $dom = new DOMDocument();
        // formatNode iterates the children of the passed node, so passing <div>
        // produces output starting with its <p> child at level 0.
        $dom->loadHTML('<div><p>inner</p></div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $output = formatNode($dom->documentElement);

        $this->assertStringContainsString("<p>\n", $output, '<p> child at level 0 — no leading whitespace');
        $this->assertStringContainsString('  inner', $output, 'text inside <p> at level 1 indented two spaces');
    }

    public function testFormatNodeSelfClosesVoidElements(): void
    {
        $dom = new DOMDocument();
        $dom->loadHTML('<div><br><img src="x.png"><hr></div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $output = formatNode($dom->documentElement);

        $this->assertStringContainsString('<br />', $output);
        $this->assertStringContainsString('<img src="x.png" />', $output);
        $this->assertStringContainsString('<hr />', $output);
    }

    public function testFormatNodeRendersAttributesWithEscaping(): void
    {
        $dom = new DOMDocument();
        $dom->loadHTML('<div><a href="https://example.com/?q=1&amp;x=2">link</a></div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $output = formatNode($dom->documentElement);

        $this->assertStringContainsString('href="https://example.com/?q=1&amp;x=2"', $output);
    }

    public function testFormatNodeRendersEmptyElementInline(): void
    {
        $dom = new DOMDocument();
        $dom->loadHTML('<div><span></span></div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $output = formatNode($dom->documentElement);

        $this->assertStringContainsString('<span></span>', $output);
    }

    public function testPrettifyHTMLProducesIndentedOutput(): void
    {
        $html = '<html><body><div><p>hi</p></div></body></html>';

        $pretty = prettifyHTML($html);

        // prettifyHTML calls formatNode($documentElement), which renders the
        // documentElement's children (not the element itself). With NOIMPLIED,
        // <html> is the documentElement and <body> is its first child at level 0.
        $this->assertStringContainsString('<body>', $pretty);
        $this->assertStringContainsString('<div>', $pretty);
        $this->assertStringContainsString('<p>', $pretty);
        $this->assertStringContainsString('hi', $pretty);
        $this->assertMatchesRegularExpression('/  <div>/', $pretty, '<div> nested in <body> indented two spaces');
        $this->assertMatchesRegularExpression('/    <p>/', $pretty, '<p> nested in <div> indented four spaces');
    }

    public function testGenerateRecipeCardWithoutImageRendersPlaceholder(): void
    {
        $product = ['handle' => 'oil-beetle', 'title' => 'Oil Beetle', 'recipe_components' => []];
        $images = ['Oil Beetle' => '/nonexistent/path/foo.png'];

        $html = generateRecipeCard($product, $images);

        $this->assertStringContainsString('<div class="card-image"></div>', $html);
        $this->assertStringNotContainsString('<img class="card-image"', $html);
        $this->assertStringContainsString('Oil Beetle', $html);
        $this->assertStringContainsString('https://www.birminghampens.com/products/oil-beetle', $html);
    }

    public function testGenerateRecipeCardWithImageThatExistsIncludesImg(): void
    {
        $imageDir = __DIR__ . '/../output/images';
        if (!is_dir($imageDir)) mkdir($imageDir, 0777, true);
        $imagePath = $imageDir . '/__phpunit_card_test.png';
        file_put_contents($imagePath, 'fake');

        try {
            $product = ['handle' => 'foo', 'title' => 'Foo', 'recipe_components' => []];
            $images = ['Foo' => $imagePath];

            $html = generateRecipeCard($product, $images);

            $this->assertStringContainsString('<img class="card-image"', $html);
            $this->assertStringContainsString('../images/__phpunit_card_test.png', $html);
            $this->assertStringContainsString('alt="Foo"', $html);
        } finally {
            @unlink($imagePath);
        }
    }

    public function testGenerateRecipeCardRendersIngredientBadges(): void
    {
        $product = [
            'handle' => 'foo',
            'title' => 'Foo',
            'recipe_components' => ['Electron' => 4, 'Gunpowder' => 1, 'Hibiscus' => 60],
        ];

        $html = generateRecipeCard($product, []);

        $this->assertStringContainsString('class="ingredients-list"', $html);
        $this->assertStringContainsString('Electron', $html);
        $this->assertStringContainsString('qty-low', $html);
        $this->assertStringContainsString('qty-high', $html);
    }

    public function testGenerateRecipeCardOmitsBadgesWhenNoComponents(): void
    {
        $product = ['handle' => 'foo', 'title' => 'Foo', 'recipe_components' => []];

        $html = generateRecipeCard($product, []);

        $this->assertStringNotContainsString('class="ingredients-list"', $html);
    }

    public function testGenerateRecipeCardEscapesTitleAndHandle(): void
    {
        $product = ['handle' => "a&b", 'title' => "<scary>", 'recipe_components' => []];

        $html = generateRecipeCard($product, []);

        $this->assertStringContainsString('&lt;scary&gt;', $html);
        $this->assertStringNotContainsString('<scary>', $html);
        $this->assertStringContainsString('https://www.birminghampens.com/products/a%26b', $html);
    }

    public function testGenerateTableRowEmitsCellPerIngredient(): void
    {
        $product = [
            'handle' => 'foo', 'title' => 'Foo',
            'recipe_components' => ['Electron' => 4, 'Hibiscus' => 5],
        ];
        $allIngredients = ['Airline', 'Electron', 'Hibiscus'];

        $html = generateTableRow($product, $allIngredients, []);

        // 3 qty-cell tds + 1 product-cell td
        $this->assertSame(3, substr_count($html, 'class="qty-cell"'));
        $this->assertStringContainsString('<td class="qty-cell">4</td>', $html);
        $this->assertStringContainsString('<td class="qty-cell">5</td>', $html);
        $this->assertStringContainsString('<td class="qty-cell"></td>', $html, 'ingredient absent for this product → empty cell');
    }

    public function testGenerateTableRowOmitsImageWhenAbsent(): void
    {
        $product = ['handle' => 'foo', 'title' => 'Foo', 'recipe_components' => []];
        $images = ['Foo' => '/no/such/image.png'];

        $html = generateTableRow($product, [], $images);

        $this->assertStringNotContainsString('class="product-img"', $html);
    }

    public function testGenerateTableRowIncludesImageWhenPresent(): void
    {
        $imageDir = __DIR__ . '/../output/images';
        if (!is_dir($imageDir)) mkdir($imageDir, 0777, true);
        $imagePath = $imageDir . '/__phpunit_row_test.png';
        file_put_contents($imagePath, 'fake');

        try {
            $product = ['handle' => 'foo', 'title' => 'Foo', 'recipe_components' => []];
            $html = generateTableRow($product, [], ['Foo' => $imagePath]);

            $this->assertStringContainsString('class="product-img"', $html);
            $this->assertStringContainsString('../images/__phpunit_row_test.png', $html);
        } finally {
            @unlink($imagePath);
        }
    }

    public function testGenerateTableHeaderListsIngredients(): void
    {
        $html = generateTableHeader(['Electron', 'Hibiscus'], []);

        $this->assertStringContainsString('<th class="sortable">Product</th>', $html);
        $this->assertStringContainsString('Electron', $html);
        $this->assertStringContainsString('Hibiscus', $html);
        $this->assertStringContainsString('https://www.birminghampens.com/products/electron', $html);
        $this->assertStringContainsString('https://www.birminghampens.com/products/hibiscus', $html);
    }

    public function testGenerateTableHeaderUrlEncodesSpacesInIngredients(): void
    {
        $html = generateTableHeader(['Sterling Silver'], []);

        $this->assertStringContainsString('https://www.birminghampens.com/products/sterling-silver', $html);
    }

    public function testGenerateTableHeaderIncludesIngredientImageWhenPresent(): void
    {
        $html = generateTableHeader(['Electron'], ['Electron' => '/path/electron.png']);

        $this->assertStringContainsString('<img src="../images/electron.png"', $html);
        $this->assertStringContainsString('class="ingredient-img"', $html);
    }

    public function testGenerateTableFooterEmitsRecipeAndQuantityCounts(): void
    {
        $allIngredients = ['Electron', 'Hibiscus'];
        $enrichedProducts = [
            ['recipe_components' => ['Electron' => 4]],
            ['recipe_components' => ['Electron' => 1, 'Hibiscus' => 5]],
            ['recipe_components' => ['Hibiscus' => 3]],
        ];
        $ingredientTotals = ['Electron' => 5, 'Hibiscus' => 8];

        $html = generateTableFooter($allIngredients, $enrichedProducts, $ingredientTotals);

        $this->assertStringContainsString('<tfoot>', $html);
        $this->assertStringContainsString('<tr><td>Recipe Count</td><td>2</td><td>2</td></tr>', $html);
        $this->assertStringContainsString('<tr><td>Quantity Count</td><td>5</td><td>8</td></tr>', $html);
    }

    public function testGenerateHTMLProducesCompleteDocumentWithStats(): void
    {
        $enrichedProducts = [
            ['handle' => 'a', 'title' => 'A', 'recipe_components' => ['Electron' => 4]],
            ['handle' => 'b', 'title' => 'B', 'recipe_components' => ['Hibiscus' => 5]],
        ];
        $allIngredients = ['Electron', 'Hibiscus'];
        $ingredientTotals = ['Electron' => 4, 'Hibiscus' => 5];

        $html = generateHTML($enrichedProducts, $allIngredients, $ingredientTotals, []);

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('<title>Birmingham Ink Recipes', $html);
        $this->assertStringContainsString('<h1>Birmingham Ink Recipes</h1>', $html);
        $this->assertStringContainsString('id="searchInput"', $html);
        $this->assertStringContainsString('id="cardView"', $html);
        $this->assertStringContainsString('id="tableView"', $html);
        // Stat values: 2 recipes, 2 ingredients
        $this->assertMatchesRegularExpression('/stat-value">2<.*?stat-label">Recipes/s', $html);
        $this->assertMatchesRegularExpression('/stat-value">2<.*?stat-label">Ingredients/s', $html);
        // The "Captured" stat is computed against a hardcoded $totalTagged = 146.
        $this->assertStringContainsString('Captured', $html);
    }

    public function testGenerateHTMLShowsRemainingIngredientsPillWhenManyIngredients(): void
    {
        $allIngredients = array_map(fn($n) => "Ingredient$n", range(1, 20));
        $ingredientTotals = array_combine($allIngredients, array_fill(0, 20, 1));

        $html = generateHTML([], $allIngredients, $ingredientTotals, []);

        $this->assertStringContainsString('+ 8 more', $html);
    }

    public function testGenerateHTMLComputesCaptureRateFromTotalTagged(): void
    {
        $enrichedProducts = array_map(
            fn($i) => ['handle' => "h$i", 'title' => "T$i", 'recipe_components' => ['X' => 1]],
            range(1, 60)
        );
        $allIngredients = ['X'];
        $ingredientTotals = ['X' => 60];

        $html = generateHTML($enrichedProducts, $allIngredients, $ingredientTotals, [], 150);

        // 60/150 = 40%
        $this->assertMatchesRegularExpression('/stat-value">40%?<.*?stat-label">Captured/s', $html);
    }

    public function testGenerateHTMLDefaultsTo100PercentWhenTotalTaggedOmitted(): void
    {
        $enrichedProducts = [
            ['handle' => 'a', 'title' => 'A', 'recipe_components' => ['X' => 1]],
            ['handle' => 'b', 'title' => 'B', 'recipe_components' => ['X' => 1]],
        ];

        $html = generateHTML($enrichedProducts, ['X'], ['X' => 2], []);

        $this->assertMatchesRegularExpression('/stat-value">100%?<.*?stat-label">Captured/s', $html);
    }

    public function testGenerateHTMLAvoidsDivisionByZero(): void
    {
        $html = generateHTML([], [], [], [], 0);

        $this->assertMatchesRegularExpression('/stat-value">0%?<.*?stat-label">Captured/s', $html);
    }

    public function testGenerateDocumentHeadIncludesTitleAndStylesheet(): void
    {
        $head = generateDocumentHead('March 14, 2026');

        $this->assertStringStartsWith('<!DOCTYPE html>', $head);
        $this->assertStringContainsString('<title>Birmingham Ink Recipes - March 14, 2026</title>', $head);
        $this->assertStringContainsString('href="../template/styles.css"', $head);
        $this->assertStringContainsString('<body>', $head);
    }

    public function testGeneratePageHeaderEmbedsStats(): void
    {
        $header = generatePageHeader('March 14, 2026', 12, 5, 80.0);

        $this->assertStringContainsString('Updated March 14, 2026', $header);
        $this->assertStringContainsString('<div class="stat-value">12</div>', $header);
        $this->assertStringContainsString('<div class="stat-value">5</div>', $header);
        $this->assertStringContainsString('<div class="stat-value">80%</div>', $header);
    }

    public function testGenerateSearchControlsContainsSearchInputAndViewToggle(): void
    {
        $controls = generateSearchControls();

        $this->assertStringContainsString('id="searchInput"', $controls);
        $this->assertStringContainsString('data-view="cards"', $controls);
        $this->assertStringContainsString('data-view="table"', $controls);
    }

    public function testGenerateFilterPillsRendersTopTwelve(): void
    {
        $ingredients = array_map(fn($n) => "Ingredient $n", range(1, 5));

        $html = generateFilterPills($ingredients);

        foreach ($ingredients as $i) {
            $this->assertStringContainsString($i, $html);
        }
        $this->assertStringNotContainsString('+ ', $html, 'no "X more" pill when ≤ 12 ingredients');
    }

    public function testGenerateFilterPillsTruncatesAndShowsRemainder(): void
    {
        $ingredients = array_map(fn($n) => "Ingredient $n", range(1, 15));

        $html = generateFilterPills($ingredients);

        $this->assertStringContainsString('Ingredient 1', $html);
        $this->assertStringContainsString('Ingredient 12', $html);
        $this->assertStringNotContainsString('Ingredient 13', $html);
        $this->assertStringContainsString('+ 3 more', $html);
    }

    public function testGenerateCardViewWrapsCardsInGrid(): void
    {
        $products = [
            ['handle' => 'a', 'title' => 'A', 'recipe_components' => []],
            ['handle' => 'b', 'title' => 'B', 'recipe_components' => []],
        ];

        $html = generateCardView($products, []);

        $this->assertStringStartsWith('<div id="cardView"', $html);
        $this->assertSame(2, substr_count($html, 'class="recipe-card"'));
    }

    public function testGenerateTableViewIncludesHeaderBodyAndFooter(): void
    {
        $products = [['handle' => 'a', 'title' => 'A', 'recipe_components' => ['X' => 4]]];

        $html = generateTableView($products, ['X'], ['X' => 4], []);

        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('<thead>', $html);
        $this->assertStringContainsString('<tbody>', $html);
        $this->assertStringContainsString('<tfoot>', $html);
        $this->assertStringContainsString('Recipe Count', $html);
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/bre_test_' . bin2hex(random_bytes(8));
        mkdir($dir);
        return $dir;
    }

    private function rmrf(string $path): void
    {
        if (!file_exists($path)) return;
        if (is_dir($path)) {
            foreach (scandir($path) as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                $this->rmrf($path . '/' . $entry);
            }
            rmdir($path);
        } else {
            unlink($path);
        }
    }

    public function testCheckInputFileReturnsVoidForExistingFile(): void
    {
        $dir = $this->makeTempDir();
        try {
            $file = $dir . '/exists.txt';
            file_put_contents($file, 'x');
            $this->assertNull(checkInputFile($file));
        } finally {
            $this->rmrf($dir);
        }
    }

    public function testCheckInputFileThrowsWhenMissing(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to load input file: /no/such/file/123abc');
        checkInputFile('/no/such/file/123abc');
    }

    public function testCheckInputFileThrowsForDirectoryNotFile(): void
    {
        $dir = $this->makeTempDir();
        try {
            $this->expectException(Exception::class);
            $this->expectExceptionMessage('Failed to load input file');
            checkInputFile($dir); // is_file() returns false for directories
        } finally {
            $this->rmrf($dir);
        }
    }

    public function testCheckOutputDirCreatesMissingDirectory(): void
    {
        $base = $this->makeTempDir();
        try {
            $target = $base . '/nested/created/dir';
            $this->assertDirectoryDoesNotExist($target);

            checkOutputDir($target);

            $this->assertDirectoryExists($target);
        } finally {
            $this->rmrf($base);
        }
    }

    public function testCheckOutputDirIsNoOpWhenDirectoryExists(): void
    {
        $dir = $this->makeTempDir();
        try {
            $this->assertNull(checkOutputDir($dir));
            $this->assertDirectoryExists($dir);
        } finally {
            $this->rmrf($dir);
        }
    }

    public function testExtractTableContentReturnsTableHtml(): void
    {
        $dir = $this->makeTempDir();
        try {
            $file = $dir . '/with-table.html';
            file_put_contents($file, '<html><body><p>before</p><table id="t"><tr><td>cell</td></tr></table><p>after</p></body></html>');

            $result = extractTableContent($file);

            $this->assertStringContainsString('<table', $result);
            $this->assertStringContainsString('cell', $result);
            $this->assertStringNotContainsString('before', $result, 'returns only the table, not surrounding markup');
            $this->assertStringNotContainsString('after', $result);
        } finally {
            $this->rmrf($dir);
        }
    }

    public function testExtractTableContentReturnsEmptyWhenNoTable(): void
    {
        $dir = $this->makeTempDir();
        try {
            $file = $dir . '/no-table.html';
            file_put_contents($file, '<html><body><p>nothing here</p></body></html>');

            $this->assertSame('', extractTableContent($file));
        } finally {
            $this->rmrf($dir);
        }
    }

    public function testExtractTableContentReturnsFirstTableWhenMultiple(): void
    {
        $dir = $this->makeTempDir();
        try {
            $file = $dir . '/two-tables.html';
            file_put_contents($file, '<html><body><table><tr><td>first</td></tr></table><table><tr><td>second</td></tr></table></body></html>');

            $result = extractTableContent($file);

            $this->assertStringContainsString('first', $result);
            $this->assertStringNotContainsString('second', $result);
        } finally {
            $this->rmrf($dir);
        }
    }

    public function testExtractTableContentFromStringReturnsTableHtml(): void
    {
        $result = extractTableContentFromString('<html><body><p>before</p><table><tr><td>cell</td></tr></table></body></html>');

        $this->assertStringContainsString('<table', $result);
        $this->assertStringContainsString('cell', $result);
        $this->assertStringNotContainsString('before', $result);
    }

    public function testExtractTableContentFromStringReturnsEmptyForEmptyInput(): void
    {
        $this->assertSame('', extractTableContentFromString(''));
    }

    public function testExtractTableContentFromStringReturnsEmptyWhenNoTable(): void
    {
        $this->assertSame('', extractTableContentFromString('<html><body><p>no table</p></body></html>'));
    }

    public function testLoadProductsReturnsDecodedArray(): void
    {
        $dir = $this->makeTempDir();
        try {
            $file = $dir . '/products.json';
            file_put_contents($file, json_encode([
                ['id' => 1, 'title' => 'A'],
                ['id' => 2, 'title' => 'B'],
            ]));

            $result = loadProducts($file);

            $this->assertCount(2, $result);
            $this->assertSame('A', $result[0]['title']);
        } finally {
            $this->rmrf($dir);
        }
    }

    public function testLoadProductsThrowsForMissingFile(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to load input file');
        loadProducts('/no/such/file.json');
    }

    public function testLoadProductsThrowsForInvalidJson(): void
    {
        $dir = $this->makeTempDir();
        try {
            $file = $dir . '/bad.json';
            file_put_contents($file, '{not valid json');

            $this->expectException(Exception::class);
            $this->expectExceptionMessage('Invalid or missing JSON data');
            loadProducts($file);
        } finally {
            $this->rmrf($dir);
        }
    }

    public function testLoadProductsThrowsForNonArrayJson(): void
    {
        $dir = $this->makeTempDir();
        try {
            $file = $dir . '/scalar.json';
            file_put_contents($file, '"just a string"');

            $this->expectException(Exception::class);
            $this->expectExceptionMessage('Invalid or missing JSON data');
            loadProducts($file);
        } finally {
            $this->rmrf($dir);
        }
    }

    public function testUpdatePathsInIndexRewritesRelativePaths(): void
    {
        $dir = $this->makeTempDir();
        try {
            $file = $dir . '/index.html';
            file_put_contents($file, '<img src="../images/foo.png"><link href="../template/styles.css">');

            updatePathsInIndex($file);

            $updated = file_get_contents($file);
            $this->assertStringContainsString('src="images/foo.png"', $updated);
            $this->assertStringContainsString('href="template/styles.css"', $updated);
            $this->assertStringNotContainsString('../images', $updated);
            $this->assertStringNotContainsString('../template', $updated);
        } finally {
            $this->rmrf($dir);
        }
    }

    public function testUpdatePathsInIndexRewritesArchiveLink(): void
    {
        $dir = $this->makeTempDir();
        try {
            $file = $dir . '/index.html';
            file_put_contents($file, '<a href="index.html" class="btn btn-icon" title="Archive">x</a>');

            updatePathsInIndex($file);

            $updated = file_get_contents($file);
            $this->assertStringContainsString('<a href="archive/" class="btn btn-icon" title="Archive">', $updated);
            $this->assertStringNotContainsString('href="index.html"', $updated);
        } finally {
            $this->rmrf($dir);
        }
    }

    public function testDownloadImageIfNeededWritesFileFromFetcher(): void
    {
        $dir = $this->makeTempDir();
        try {
            $imagePath = $dir . '/image.png';
            $fetcher = fn(string $url): string => 'image-bytes-' . $url;
            $logs = [];
            $logger = function (string $m) use (&$logs): void { $logs[] = $m; };

            downloadImageIfNeeded('https://example.test/x.png', $imagePath, $fetcher, $logger);

            $this->assertFileExists($imagePath);
            $this->assertSame('image-bytes-https://example.test/x.png', file_get_contents($imagePath));
            $this->assertContains('Downloaded: ' . $imagePath, $logs);
        } finally {
            $this->rmrf($dir);
        }
    }

    public function testDownloadImageIfNeededSkipsExistingFile(): void
    {
        $dir = $this->makeTempDir();
        try {
            $imagePath = $dir . '/cached.png';
            file_put_contents($imagePath, 'preexisting');
            $called = 0;
            $fetcher = function (string $url) use (&$called) {
                $called++;
                return 'fresh-bytes';
            };
            $logger = function (string $m): void {};

            downloadImageIfNeeded('https://example.test/cached.png', $imagePath, $fetcher, $logger);

            $this->assertSame(0, $called, 'fetcher must not be called when file already exists');
            $this->assertSame('preexisting', file_get_contents($imagePath));
        } finally {
            $this->rmrf($dir);
        }
    }

    public function testDownloadImageIfNeededHandlesFetchFailure(): void
    {
        $dir = $this->makeTempDir();
        try {
            $imagePath = $dir . '/fail.png';
            $fetcher = fn(string $url): string|false => false;
            $logs = [];
            $logger = function (string $m) use (&$logs): void { $logs[] = $m; };

            downloadImageIfNeeded('https://example.test/fail.png', $imagePath, $fetcher, $logger);

            $this->assertFileDoesNotExist($imagePath);
            $this->assertNotEmpty(array_filter($logs, fn($m) => str_contains($m, 'Failed to download image')));
        } finally {
            $this->rmrf($dir);
        }
    }

    public function testFetchPageReturnsProductsOnFirstAttempt(): void
    {
        $fetcher = fn(string $url): string => json_encode(['products' => [['id' => 1], ['id' => 2]]]);
        $sleeper = function (int $s): void {};
        $logs = [];
        $logger = function (string $m) use (&$logs): void { $logs[] = $m; };

        $products = fetchPage(1, $fetcher, $sleeper, $logger);

        $this->assertCount(2, $products);
        $this->assertSame(1, $products[0]['id']);
        $this->assertNotEmpty($logs, 'logger should receive at least the "Fetching page" line');
    }

    public function testFetchPageReturnsEmptyArrayWhenProductsKeyMissing(): void
    {
        $fetcher = fn(string $url): string => json_encode(['other' => 'data']);
        $sleeper = function (int $s): void {};
        $logger = function (string $m): void {};

        $products = fetchPage(1, $fetcher, $sleeper, $logger);

        $this->assertSame([], $products);
    }

    public function testFetchPageRetriesUntilSuccess(): void
    {
        $i = 0;
        $fetcher = function (string $url) use (&$i): string|false {
            $i++;
            if ($i < 3) return false;
            return json_encode(['products' => [['id' => 99]]]);
        };
        $sleeps = [];
        $sleeper = function (int $s) use (&$sleeps): void { $sleeps[] = $s; };
        $logger = function (string $m): void {};

        $products = fetchPage(1, $fetcher, $sleeper, $logger);

        $this->assertSame(99, $products[0]['id']);
        $this->assertSame(3, $i);
        $this->assertCount(2, $sleeps, 'two sleeps before the third attempt succeeds');
    }

    public function testFetchPageThrowsAfterMaxRetries(): void
    {
        $fetcher = fn(string $url): string|false => false;
        $sleeper = function (int $s): void {};
        $logger = function (string $m): void {};

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Max retries reached for page 1');

        fetchPage(1, $fetcher, $sleeper, $logger);
    }

    public function testFetchPageBackoffIsExponential(): void
    {
        $fetcher = fn(string $url): string|false => false;
        $sleeps = [];
        $sleeper = function (int $s) use (&$sleeps): void { $sleeps[] = $s; };
        $logger = function (string $m): void {};

        try {
            fetchPage(1, $fetcher, $sleeper, $logger);
        } catch (Exception $e) {
            // expected
        }

        // Backoff goes 2, 4, 8, 16 between attempts 1→2, 2→3, 3→4, 4→5; the 5th
        // failure throws without sleeping.
        $this->assertSame([2, 4, 8, 16], $sleeps);
    }

    public function testFetchAllProductsAccumulatesPagesUntilEmpty(): void
    {
        $pageData = [
            1 => ['products' => [['id' => 1], ['id' => 2]]],
            2 => ['products' => [['id' => 3]]],
            3 => ['products' => []],
        ];
        $fetcher = function (string $url) use ($pageData): string {
            preg_match('/[?&]page=(\d+)/', $url, $m);
            $page = (int) $m[1];
            return json_encode($pageData[$page] ?? ['products' => []]);
        };
        $sleeper = function (int $s): void {};
        $logger = function (string $m): void {};

        $all = fetchAllProducts($fetcher, $sleeper, $logger);

        $this->assertCount(3, $all);
        $this->assertSame([1, 2, 3], array_column($all, 'id'));
    }

    public function testFetchAllProductsBreaksOnFetchException(): void
    {
        $fetcher = fn(string $url): string|false => false;
        $sleeper = function (int $s): void {};
        $logger = function (string $m): void {};

        $all = fetchAllProducts($fetcher, $sleeper, $logger);

        $this->assertSame([], $all, 'returns whatever was accumulated when fetchPage throws');
    }

    public function testProcessProductsExcludesProductsWithoutComponents(): void
    {
        $products = [
            ['title' => 'A', 'recipe_components' => ['Electron' => 4]],
            ['title' => 'B', 'recipe_components' => []],
            ['title' => 'C'], // no key at all
        ];

        [$enriched] = processProducts($products, fn($url) => '');

        $this->assertCount(1, $enriched);
        $this->assertSame('A', $enriched[0]['title']);
    }

    public function testProcessProductsAccumulatesIngredientTotals(): void
    {
        $products = [
            ['title' => 'A', 'recipe_components' => ['Electron' => 4, 'Hibiscus' => 2]],
            ['title' => 'B', 'recipe_components' => ['Electron' => 1, 'Gunpowder' => 3]],
        ];

        [, $totals] = processProducts($products, fn($url) => '');

        $this->assertSame(5, $totals['Electron']);
        $this->assertSame(2, $totals['Hibiscus']);
        $this->assertSame(3, $totals['Gunpowder']);
    }

    public function testProcessProductsSortsEnrichedByTitle(): void
    {
        $products = [
            ['title' => 'Charlie', 'recipe_components' => ['X' => 1]],
            ['title' => 'Alpha', 'recipe_components' => ['X' => 1]],
            ['title' => 'Bravo', 'recipe_components' => ['X' => 1]],
        ];

        [$enriched] = processProducts($products, fn($url) => '');

        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], array_column($enriched, 'title'));
    }

    public function testProcessProductsCallsImageHandlerForProductsWithImages(): void
    {
        $products = [
            ['title' => 'A', 'images' => [['src' => 'https://cdn/a.png']], 'recipe_components' => ['X' => 1]],
            ['title' => 'B', 'images' => [['src' => 'https://cdn/b.png']], 'recipe_components' => ['X' => 1]],
        ];
        $calls = [];
        $imageHandler = function (string $url) use (&$calls): string {
            $calls[] = $url;
            return '/local/' . basename(parse_url($url, PHP_URL_PATH));
        };

        [, , $images] = processProducts($products, $imageHandler);

        $this->assertSame(['https://cdn/a.png', 'https://cdn/b.png'], $calls);
        $this->assertSame('/local/a.png', $images['A']);
        $this->assertSame('/local/b.png', $images['B']);
    }

    public function testProcessProductsSkipsImageHandlerWhenNoImage(): void
    {
        $products = [
            ['title' => 'A', 'recipe_components' => ['X' => 1]],
            ['title' => 'B', 'images' => [], 'recipe_components' => ['X' => 1]],
        ];
        $called = 0;
        $imageHandler = function (string $url) use (&$called): string {
            $called++;
            return '/dummy';
        };

        [, , $images] = processProducts($products, $imageHandler);

        $this->assertSame(0, $called);
        $this->assertSame([], $images);
    }

    public function testRecipeFetchCurlOptionsContainsUrlAndUserAgent(): void
    {
        $opts = recipeFetchCurlOptions('https://example.test/products/foo');

        $this->assertSame('https://example.test/products/foo', $opts[CURLOPT_URL]);
        $this->assertStringContainsString('Mozilla/5.0', $opts[CURLOPT_USERAGENT]);
        $this->assertStringContainsString('Safari', $opts[CURLOPT_USERAGENT], 'full Safari UA, not the bare Mozilla/5.0 string');
    }

    public function testRecipeFetchCurlOptionsRequestsResponseAsString(): void
    {
        $opts = recipeFetchCurlOptions('https://example.test/x');

        $this->assertTrue($opts[CURLOPT_RETURNTRANSFER]);
        $this->assertTrue($opts[CURLOPT_FOLLOWLOCATION]);
    }

    public function testRecipeFetchCurlOptionsSetsTimeouts(): void
    {
        $opts = recipeFetchCurlOptions('https://example.test/x');

        $this->assertSame(RECIPE_FETCH_TIMEOUT_SECONDS, $opts[CURLOPT_TIMEOUT]);
        $this->assertSame(RECIPE_FETCH_CONNECT_TIMEOUT_SECONDS, $opts[CURLOPT_CONNECTTIMEOUT]);
        $this->assertGreaterThan(0, $opts[CURLOPT_TIMEOUT], 'must have a non-zero timeout to prevent hung requests');
    }

    public function testCurlFetchProductPageReturnsStatusAndBodyShape(): void
    {
        // Integration smoke test against a known-good URL on the live site.
        // The recipe-fetch curl options + Birmingham's storefront together
        // should always return 200 and a body containing the metafield div.
        $result = curlFetchProductPage('https://www.birminghampens.com/products/diving-bell');

        $this->assertSame(200, $result['status']);
        $this->assertIsString($result['body']);
        $this->assertStringContainsString('metafield-rich_text_field', $result['body']);
    }
}
