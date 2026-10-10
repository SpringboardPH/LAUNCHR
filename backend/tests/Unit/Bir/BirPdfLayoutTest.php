<?php

namespace Tests\Unit\Bir;

use App\Services\BIR\BirPdfService;
use App\Services\BIR\Pdf\Form1601CLayout;
use App\Services\BIR\Pdf\Form2316Layout;
use App\Services\BIR\Schemas\Form1601CSchema;
use App\Services\BIR\Schemas\Form2316Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The PDF layouts against the schemas they draw: every anchor on the form has a place on
 * the page, nothing points at an anchor that no longer exists, and every box is on the
 * page. A schema edit that adds or renames an anchor fails here, not on a printed form.
 */
class BirPdfLayoutTest extends TestCase
{
    /** Anchors deliberately not drawn, and why. */
    private const NOT_DRAWN = [
        '1601C.5' => 'ATC WW010 is printed on the template itself',
    ];

    /** @return array<string, array{array<int, array<string, mixed>>, array<string, array<string, mixed>>}> */
    public static function forms(): array
    {
        return [
            '2316' => [Form2316Schema::fields(), Form2316Layout::BOXES],
            '1601-C' => [Form1601CSchema::fields(), Form1601CLayout::BOXES],
        ];
    }

    #[DataProvider('forms')]
    public function test_every_anchor_on_the_form_has_a_place_on_the_page(array $schema, array $layout): void
    {
        $drawn = array_keys($layout);
        foreach ($layout as $spec) {
            array_push($drawn, ...($spec['parts'] ?? [])); // the name box draws last, first and middle
        }

        foreach (array_filter(array_column($schema, 'pdf_anchor')) as $anchor) {
            if (!isset(self::NOT_DRAWN[$anchor])) {
                $this->assertContains($anchor, $drawn, "{$anchor} is on the form but has no place on the page.");
            }
        }
    }

    #[DataProvider('forms')]
    public function test_the_layout_points_only_at_anchors_the_schema_has(array $schema, array $layout): void
    {
        $anchors = array_filter(array_column($schema, 'pdf_anchor'));

        foreach ($layout as $anchor => $spec) {
            $base = preg_replace('/\.desc$/', '', $anchor);
            $known = in_array($anchor, $anchors, true) || in_array($base, $anchors, true) || isset($spec['parts']);
            $this->assertTrue($known, "{$anchor} is in the layout but no field has that anchor.");
        }
    }

    #[DataProvider('forms')]
    public function test_every_box_is_on_the_page(array $schema, array $layout): void
    {
        foreach ($layout as $anchor => $spec) {
            $boxes = [...array_filter([$spec['box'] ?? null]), ...($spec['cells'] ?? []), ...($spec['int'] ?? []), ...($spec['dec'] ?? []), ...array_values($spec['options'] ?? [])];
            $this->assertNotEmpty($boxes, "{$anchor} has nowhere to draw.");

            foreach ($boxes as [$x, $y, $w, $h]) {
                $this->assertTrue($x >= 0 && $y >= 0 && $w > 0 && $h > 0 && $x + $w <= 612 && $y + $h <= 936, "{$anchor} has a box off the page.");
            }
        }
    }

    public function test_cell_boxes_get_the_characters_bir_forms_expect(): void
    {
        $this->assertSame('05311990', BirPdfService::cellText('1990-05-31', 'mmddyyyy'), 'Dates are MMDDYYYY on the form.');
        $this->assertSame('12345678900000', BirPdfService::cellText('123-456-789-00000', 'digits'), 'A TIN loses its dashes.');
        $this->assertSame('082026', BirPdfService::cellText('08/2026', 'mmyyyy'));
        $this->assertSame('1231', BirPdfService::cellText('12/31', 'mmdd'));
        $this->assertSame('DELA CRUZ, JUAN PEÑA', BirPdfService::cellText(' Dela Cruz, Juan Peña ', 'upper'), 'Capital letters, as the 1601-C asks.');
        $this->assertSame('', BirPdfService::cellText('31/05/1990', 'mmddyyyy'), 'A date not stored as YYYY-MM-DD is left blank, not guessed.');
    }
}
