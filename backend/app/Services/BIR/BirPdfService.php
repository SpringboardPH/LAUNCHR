<?php

namespace App\Services\BIR;

use App\Models\BirFormDraft;
use App\Services\BIR\Pdf\Form1601CLayout;
use App\Services\BIR\Pdf\Form2316Layout;
use App\Services\BIR\Schemas\Form1601CSchema;
use App\Services\BIR\Schemas\Form2316Schema;
use InvalidArgumentException;
use setasign\Fpdi\Fpdi;

/**
 * Fills the official BIR PDF with a draft's values (Week 6, finished in Week 7).
 *
 * The BIR templates have no form fields, so each value is drawn on top of the official
 * page at the position its pdf_anchor has in Form2316Layout / Form1601CLayout. Values are
 * the draft's own, as stored: nothing is calculated here.
 *
 * Until a form is approved, every page says DRAFT across the top, so a draft can be
 * printed and checked but is never mistaken for one ready to file.
 */
class BirPdfService
{
    /** Statuses that have passed review; anything else is printed as a draft. */
    public const FILEABLE_STATUSES = ['approved', 'finalized'];

    private const FONT = 'Helvetica';

    /** @return string the PDF itself */
    public function render(BirFormDraft $draft, bool $compress = true): string
    {
        [$layout, $schema] = match ($draft->form_type) {
            Form2316Schema::FORM_TYPE => [Form2316Layout::class, Form2316Schema::fields()],
            Form1601CSchema::FORM_TYPE => [Form1601CLayout::class, Form1601CSchema::fields()],
            default => throw new InvalidArgumentException("Unknown BIR form type: {$draft->form_type}"),
        };

        $values = $this->valuesByAnchor($schema, $draft->fields ?? []);

        $pdf = new Fpdi('P', 'pt');
        $pdf->SetCompression($compress);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0);
        $pages = $pdf->setSourceFile(resource_path('bir-templates/' . $layout::TEMPLATE));

        for ($page = 1; $page <= $pages; $page++) {
            $template = $pdf->importPage($page);
            $size = $pdf->getTemplateSize($template);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($template);
            $pdf->SetTextColor(0, 0, 0);

            foreach ($layout::BOXES as $anchor => $spec) {
                if ($spec['page'] === $page) {
                    $this->draw($pdf, $spec, $spec['kind'] === 'name' ? $values : ($values[$anchor] ?? null));
                }
            }

            if (!in_array($draft->status, self::FILEABLE_STATUSES, true)) {
                $this->markAsDraft($pdf, $draft, $size['width']);
            }
        }

        return $pdf->Output('S');
    }

    /**
     * The characters a cell box gets, one per cell. Public so the formats can be tested
     * on their own; dates are stored as YYYY-MM-DD but printed MMDDYYYY on BIR forms.
     */
    public static function cellText(mixed $value, string $format): string
    {
        $value = (string) $value;

        return match ($format) {
            'mmddyyyy' => preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) ? $m[2] . $m[3] . $m[1] : '',
            'upper' => mb_strtoupper(trim($value)),
            default => preg_replace('/\D/', '', $value), // digits, mmdd, mmyyyy: the digits in order
        };
    }

    /**
     * pdf_anchor => value, for every field that has an anchor and a value.
     *
     * The 1601-C schema gives an amount and its "specify" text the same anchor (items 20
     * and 29), where the 2316 uses .amount and .desc. Until the schema tells them apart,
     * a text field sharing an amount's anchor is placed at the anchor's .desc box.
     *
     * @param  array<int, array<string, mixed>>  $schema
     * @param  array<string, array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    private function valuesByAnchor(array $schema, array $fields): array
    {
        $shared = array_count_values(array_filter(array_column($schema, 'pdf_anchor')));

        $values = [];
        foreach ($schema as $field) {
            $anchor = $field['pdf_anchor'] ?? null;
            $value = $fields[$field['key']]['value'] ?? null;
            if ($anchor === null || $value === null || $value === '') {
                continue;
            }
            if ($shared[$anchor] > 1 && $field['type'] !== 'decimal') {
                $anchor .= '.desc';
            }
            $values[$anchor] = $value;
        }

        return $values;
    }

    /** @param array<string, mixed> $spec  one layout entry */
    private function draw(Fpdi $pdf, array $spec, mixed $value): void
    {
        if ($value === null) {
            return;
        }

        match ($spec['kind']) {
            'amount' => $this->inBox($pdf, $spec['box'], number_format((float) $value, 2), 'R', 9),
            'text' => $this->inBox($pdf, $spec['box'], (string) $value, 'L', 8),
            'name' => $this->inBox($pdf, $spec['box'], $this->fullName($spec['parts'], $value), 'L', 8),
            'check' => $value === true || $value === 1 || $value === '1' ? $this->inBox($pdf, $spec['box'], 'X', 'C', 9) : null,
            'choice' => $this->choose($pdf, $spec['options'], $value),
            'cells' => $this->inCells($pdf, $spec['cells'], self::cellText($value, $spec['format']), ($spec['align'] ?? 'left') === 'right' ? 'R' : 'L'),
            'cell_amount' => $this->cellAmount($pdf, $spec, (float) $value),
            default => null,
        };
    }

    /** "DELA CRUZ, JUAN SANTOS" from the last, first and middle name parts. */
    private function fullName(array $parts, array $values): ?string
    {
        [$last, $first, $middle] = array_map(fn (string $anchor) => trim((string) ($values[$anchor] ?? '')), $parts);
        $name = trim($last . ($first !== '' ? ", {$first}" : '') . ($middle !== '' ? " {$middle}" : ''));

        return $name === '' ? null : $name;
    }

    /** An X in the box matching the answer: yes/no for a yes/no field, else the option itself. */
    private function choose(Fpdi $pdf, array $options, mixed $value): void
    {
        $key = is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value;
        if (isset($options[$key])) {
            $this->inBox($pdf, $options[$key], 'X', 'C', 9);
        }
    }

    /** Text in a box, vertically centred, shrunk until it fits (never below 5pt). */
    private function inBox(Fpdi $pdf, array $box, ?string $text, string $align, float $size): void
    {
        if ($text === null || $text === '') {
            return;
        }
        [$x, $y, $w, $h] = $box;
        $text = self::latin1($text);

        $pdf->SetFont(self::FONT, '', $size);
        while ($size > 5 && $pdf->GetStringWidth($text) > $w - 4) {
            $pdf->SetFont(self::FONT, '', $size -= 0.5);
        }

        $pdf->SetXY($x + 1, $y);
        $pdf->Cell($w - 2, $h, $text, 0, 0, $align);
    }

    /** One character per cell, centred. Right-aligned text fills the cells from the right. */
    private function inCells(Fpdi $pdf, array $cells, string $text, string $align): void
    {
        $chars = str_split(self::latin1($text));
        $chars = array_slice($chars, 0, count($cells));
        $start = $align === 'R' ? count($cells) - count($chars) : 0;

        $pdf->SetFont(self::FONT, '', 9);
        foreach ($chars as $i => $char) {
            [$x, $y, $w, $h] = $cells[$start + $i];
            $pdf->SetXY($x, $y);
            $pdf->Cell($w, $h, $char, 0, 0, 'C');
        }
    }

    /** Whole pesos right-aligned in the int cells, centavos in the two dec cells. */
    private function cellAmount(Fpdi $pdf, array $spec, float $amount): void
    {
        [$pesos, $centavos] = explode('.', number_format(abs($amount), 2, '.', ''));
        $this->inCells($pdf, $spec['int'], ($amount < 0 ? '-' : '') . $pesos, 'R');
        $this->inCells($pdf, $spec['dec'], $centavos, 'L');
    }

    private function markAsDraft(Fpdi $pdf, BirFormDraft $draft, float $pageWidth): void
    {
        $pdf->SetFont(self::FONT, 'B', 8);
        $pdf->SetTextColor(200, 0, 0);
        $pdf->SetXY(0, 0);
        $pdf->Cell($pageWidth, 8, self::latin1("DRAFT #{$draft->id} ({$draft->status}) — NOT FOR FILING"), 0, 0, 'C');
        $pdf->SetTextColor(0, 0, 0);
    }

    /** FPDF's built-in fonts are Windows-1252, which covers Ñ and other Filipino letters. */
    private static function latin1(string $text): string
    {
        return mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
    }
}
