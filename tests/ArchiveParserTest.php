<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/../utility/functions.php';
require_once __DIR__ . '/../utility/recipe_repository.php';
require_once __DIR__ . '/../utility/archive_parser.php';

final class ArchiveParserTest extends TestCase
{
    #[DataProvider('headerProvider')]
    public function testSplitIngredientHeader(string $header, string $primary, array $extras): void
    {
        $this->assertSame([$primary, $extras], splitIngredientHeader($header));
    }

    public static function headerProvider(): array
    {
        return [
            'plain' => ['Airline', 'Airline', []],
            'surrounding whitespace' => ["\n  Tesla Coil\n ", 'Tesla Coil', []],
            'typo corrected' => ['Diluent', 'Dilution Solution', []],
            'entity decoded' => ['Stoker&#039;s Ash', "Stoker's Ash", []],
            'comma artifact singular' => ['Gunpowder, 1 part Tesla Coil', 'Gunpowder', ['Tesla Coil' => 1]],
            'comma artifact plural' => ['Tesla Coil, 2 parts Teaberry Ice Cream', 'Tesla Coil', ['Teaberry Ice Cream' => 2]],
            'comma artifact with typo in extra' => ['Tesla Coil, 1 part Diluent', 'Tesla Coil', ['Dilution Solution' => 1]],
            'placeholder kept verbatim' => ['(Unreleased Element)', '(Unreleased Element)', []],
        ];
    }

    private function snapshot(string $thead, string $tbody): string
    {
        return '<head><meta charset="UTF-8" /><title>x</title></head><body><main><table>'
            . '<thead><tr><th>Product</th>' . $thead . '</tr></thead>'
            . '<tbody>' . $tbody . '</tbody></table></main></body>';
    }

    private function th(string $name, ?string $img = null): string
    {
        $slug = strtolower(str_replace(' ', '-', $name));
        $html = '<th><a href="https://www.birminghampens.com/products/' . $slug . '" target="_blank">' . $name;
        if ($img !== null) {
            $html .= '<img src="../images/' . $img . '" alt="' . $name . '" class="ingredient-img" />';
        }
        return $html . '</a></th>';
    }

    public function testParses2024ShapeWithoutProductNameWrapper(): void
    {
        $html = $this->snapshot(
            $this->th('Airline', 'Airline.jpg') . $this->th('Chimney Soot'),
            '<tr><td><a href="https://www.birminghampens.com/products/abacus" target="_blank">Abacus</a>'
            . '<img src="../images/Abacus.jpg" alt="Abacus" class="product-img" /></td>'
            . '<td>3</td><td></td></tr>'
        );

        $scan = parseArchiveSnapshot($html, '2024-11-09');

        $this->assertSame('2024-11-09', $scan['date']);
        $this->assertSame([], $scan['failed']);
        $this->assertSame([
            'abacus' => ['title' => 'Abacus', 'image' => 'Abacus.jpg', 'components' => ['Airline' => 3]],
        ], $scan['recipes']);
    }

    public function testParses2026ShapeWithProductCellAndQtyCells(): void
    {
        $html = $this->snapshot(
            $this->th('Airline') . $this->th('Chimney Soot'),
            '<tr><td><div class="product-cell">'
            . '<img class="product-img" src="../images/Abacus.jpg" alt="Abacus" />'
            . '<div class="product-name"><a href="https://www.birminghampens.com/products/abacus" target="_blank">Abacus</a></div>'
            . '</div></td><td class="qty-cell"></td><td class="qty-cell">2</td></tr>'
        );

        $scan = parseArchiveSnapshot($html, '2026-02-14');

        $this->assertSame(['Chimney Soot' => 2], $scan['recipes']['abacus']['components']);
        $this->assertSame('Abacus.jpg', $scan['recipes']['abacus']['image']);
    }

    public function testDecodesUrlEncodedHandleAndEntitiesInTitle(): void
    {
        $html = $this->snapshot(
            $this->th('Stoker&#039;s Ash'),
            '<tr><td><div class="product-name"><a href="https://www.birminghampens.com/products/%28unreleased-element%29" target="_blank">(Unreleased Element)</a></div></td><td>1</td></tr>'
        );

        $scan = parseArchiveSnapshot($html, '2025-06-06');

        $this->assertArrayHasKey('(unreleased-element)', $scan['recipes']);
        $this->assertSame(["Stoker's Ash" => 1], $scan['recipes']['(unreleased-element)']['components']);
    }

    public function testPreservesUtf8InTitles(): void
    {
        $html = $this->snapshot(
            $this->th('Airline'),
            '<tr><td><a href="https://www.birminghampens.com/products/cafe">Café — Ink</a></td><td>1</td></tr>'
        );

        $this->assertSame('Café — Ink', parseArchiveSnapshot($html, '2025-01-01')['recipes']['cafe']['title']);
    }

    public function testSplitsCommaArtifactHeaderIntoTwoComponents(): void
    {
        $html = $this->snapshot(
            $this->th('Gunpowder, 1 part Tesla Coil') . $this->th('Tesla Coil'),
            '<tr><td><div class="product-name"><a href="https://www.birminghampens.com/products/a">A</a></div></td><td>4</td><td></td></tr>'
            . '<tr><td><div class="product-name"><a href="https://www.birminghampens.com/products/b">B</a></div></td><td>4</td><td>3</td></tr>'
        );

        $scan = parseArchiveSnapshot($html, '2026-05-10');

        $this->assertSame(['Gunpowder' => 4, 'Tesla Coil' => 1], $scan['recipes']['a']['components']);
        $this->assertSame(['Gunpowder' => 4, 'Tesla Coil' => 3], $scan['recipes']['b']['components'], 'larger value wins on collision');
    }

    public function testFoldsDiluentColumnIntoDilutionSolution(): void
    {
        $html = $this->snapshot(
            $this->th('Diluent') . $this->th('Dilution Solution'),
            '<tr><td><a href="https://www.birminghampens.com/products/a">A</a></td><td>2</td><td></td></tr>'
        );

        $scan = parseArchiveSnapshot($html, '2025-01-08');

        $this->assertSame(['Dilution Solution' => 2], $scan['recipes']['a']['components']);
    }

    public function testEmptyTbodyYieldsNoRecipes(): void
    {
        $scan = parseArchiveSnapshot($this->snapshot($this->th('Airline'), ''), '2026-07-21');

        $this->assertSame([], $scan['recipes']);
    }

    public function testRowsWithoutQuantitiesAreSkipped(): void
    {
        $html = $this->snapshot(
            $this->th('Airline'),
            '<tr><td><a href="https://www.birminghampens.com/products/a">A</a></td><td></td></tr>'
        );

        $this->assertSame([], parseArchiveSnapshot($html, '2025-01-01')['recipes']);
    }

    public function testMissingProductImageYieldsNull(): void
    {
        $html = $this->snapshot(
            $this->th('Airline'),
            '<tr><td><a href="https://www.birminghampens.com/products/a">A</a></td><td>1</td></tr>'
        );

        $this->assertNull(parseArchiveSnapshot($html, '2025-01-01')['recipes']['a']['image']);
    }

    public function testColumnCountMismatchThrows(): void
    {
        $html = $this->snapshot(
            $this->th('Airline') . $this->th('Chimney Soot'),
            '<tr><td><a href="https://www.birminghampens.com/products/a">A</a></td><td>1</td></tr>'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('2025-01-01');
        parseArchiveSnapshot($html, '2025-01-01');
    }

    public function testNonNumericQuantityThrows(): void
    {
        $html = $this->snapshot(
            $this->th('Airline'),
            '<tr><td><a href="https://www.birminghampens.com/products/a">A</a></td><td>lots</td></tr>'
        );

        $this->expectException(RuntimeException::class);
        parseArchiveSnapshot($html, '2025-01-01');
    }

    public function testMissingTableThrows(): void
    {
        $this->expectException(RuntimeException::class);
        parseArchiveSnapshot('<html><body><p>nothing</p></body></html>', '2025-01-01');
    }
}
