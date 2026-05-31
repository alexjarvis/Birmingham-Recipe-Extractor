<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/../utility/functions.php';

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
        ];
    }
}
