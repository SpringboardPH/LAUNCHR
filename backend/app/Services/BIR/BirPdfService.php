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
 *
 * A 2316 whose earlier version was already filed, exported before this version is filed
 * itself, also says so in red on every page (supervisor's request), so a correction is never
 * sent out as if it were the first certificate.
 *
 * Text never runs out of its box: a long address in a box goes onto two lines, and in
 * character cells it breaks between words from one row to the next; when it is longer than
 * all the cells together, it is written small across them instead of being cut off. A box
 * that repeats another (the TIN and name at the top of the 1601-C's page 2) prints the same
 * value.
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
        $filedBefore = $this->filedBefore($draft);

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
                    $this->draw($pdf, $spec, $spec['kind'] === 'name' ? $values : ($values[$spec['same'] ?? $anchor] ?? null));
                }
            }

            $this->markTop($pdf, $draft, $filedBefore, $size['width']);
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
            'code' => preg_replace('/[^A-Z0-9]/', '', strtoupper($value)), // an RDO code: 049, 21A
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
            'text' => $this->inBox($pdf, $spec['box'], (string) $value, 'L', 8, wrap: true),
            'name' => $this->inBox($pdf, $spec['box'], $this->fullName($spec['parts'], $value), 'L', 8),
            'check' => $value === true || $value === 1 || $value === '1' ? $this->inBox($pdf, $spec['box'], 'X', 'C', 9) : null,
            'choice' => $this->choose($pdf, $spec['options'], $value),
            'cells' => $spec['format'] === 'upper'
                ? $this->wordsInCells($pdf, $spec['cells'], self::cellText($value, $spec['format']))
                : $this->inCells($pdf, $spec['cells'], self::cellText($value, $spec['format']), ($spec['align'] ?? 'left') === 'right' ? 'R' : 'L'),
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

    /**
     * Text in a box, vertically centred, shrunk until it fits (never below 5pt). With wrap,
     * text that would need to go below 6.5pt on one line goes onto two, split between words.
     */
    private function inBox(Fpdi $pdf, array $box, ?string $text, string $align, float $size, bool $wrap = false): void
    {
        if ($text === null || $text === '') {
            return;
        }
        [$x, $y, $w, $h] = $box;
        $text = self::latin1($text);

        if ($wrap && $this->fittingSize($pdf, [$text], $w - 4, $size, 6.5) === null && ($lines = self::twoLines($pdf, $text)) !== null) {
            $size = $this->fittingSize($pdf, $lines, $w - 4, min($size, $h / 2 - 0.5), 4) ?? 4;
            $pdf->SetFont(self::FONT, '', $size);
            foreach ($lines as $i => $line) {
                $pdf->SetXY($x + 1, $y + 0.5 + $i * ($h - 1) / 2);
                $pdf->Cell($w - 2, ($h - 1) / 2, $line, 0, 0, $align);
            }

            return;
        }

        $pdf->SetFont(self::FONT, '', $this->fittingSize($pdf, [$text], $w - 4, $size, 5) ?? 5);
        $pdf->SetXY($x + 1, $y);
        $pdf->Cell($w - 2, $h, $text, 0, 0, $align);
    }

    /** The largest size from $size down to $min, in half points, at which every line fits $width; null if none. */
    private function fittingSize(Fpdi $pdf, array $lines, float $width, float $size, float $min): ?float
    {
        for (; $size >= $min; $size -= 0.5) {
            $pdf->SetFont(self::FONT, '', $size);
            if (max(array_map(fn (string $l) => $pdf->GetStringWidth($l), $lines)) <= $width) {
                return $size;
            }
        }

        return null;
    }

    /** The text split between two words so the longer line is as short as it can be; null for a single word. */
    private static function twoLines(Fpdi $pdf, string $text): ?array
    {
        $words = preg_split('/\s+/', trim($text));
        if (count($words) < 2) {
            return null;
        }
        $best = null;
        for ($i = 1; $i < count($words); $i++) {
            $lines = [implode(' ', array_slice($words, 0, $i)), implode(' ', array_slice($words, $i))];
            $longer = max($pdf->GetStringWidth($lines[0]), $pdf->GetStringWidth($lines[1]));
            if ($best === null || $longer < $best[0]) {
                $best = [$longer, $lines];
            }
        }

        return $best[1];
    }

    /**
     * Text in cells that run over more than one row (1601-C item 9, the address): a row ends
     * between two words, never inside one. Text longer than the cells can hold is written
     * small across their rows instead, so none of it is lost.
     */
    private function wordsInCells(Fpdi $pdf, array $cells, string $text): void
    {
        $rows = array_values(array_reduce($cells, function (array $rows, array $cell) {
            $rows[(string) $cell[1]][] = $cell;
            return $rows;
        }, []));
        $placed = [];
        $words = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($rows as $row) {
            $line = '';
            while ($words !== [] && mb_strlen(ltrim($line . ' ' . $words[0])) <= count($row)) {
                $line = ltrim($line . ' ' . array_shift($words));
            }
            if ($line === '' && $words !== []) {
                $line = mb_substr($words[0], 0, count($row)); // one word longer than a whole row
                $words[0] = mb_substr($words[0], count($row));
            }
            $placed[] = [$row, $line];
        }

        if ($words !== []) {
            $this->smallOverRows($pdf, $rows, self::latin1($text));

            return;
        }
        foreach ($placed as [$row, $line]) {
            $this->inCells($pdf, $row, $line, 'L');
        }
    }

    /** Text too long for its cells, written small over their rows: as large as fits, each row to its own width. */
    private function smallOverRows(Fpdi $pdf, array $rows, string $text): void
    {
        $words = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        for ($size = 8; $size >= 4; $size -= 0.5) {
            $pdf->SetFont(self::FONT, '', $size);
            $left = $words;
            $lines = [];
            foreach ($rows as $row) {
                $width = end($row)[0] + end($row)[2] - $row[0][0] - 2;
                $line = '';
                while ($left !== [] && $pdf->GetStringWidth(ltrim($line . ' ' . $left[0])) <= $width) {
                    $line = ltrim($line . ' ' . array_shift($left));
                }
                $lines[] = $line;
            }
            if ($left === [] || $size === 4.0) {
                break;
            }
        }
        foreach ($rows as $i => $row) {
            $pdf->SetXY($row[0][0] + 1, $row[0][1]);
            $pdf->Cell(end($row)[0] + end($row)[2] - $row[0][0] - 2, $row[0][3], $lines[$i] . ($i === count($rows) - 1 && $left !== [] ? ' ' . implode(' ', $left) : ''), 0, 0, 'L');
        }
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

    /**
     * The red line across the top of every page: DRAFT until the form is approved, and on a
     * 2316 whose earlier version was filed, that it was. One line, shrunk to fit if needed, in
     * the margin above the form: the 2316's seal starts 12pt from the top.
     */
    private function markTop(Fpdi $pdf, BirFormDraft $draft, ?BirFormDraft $filed, float $pageWidth): void
    {
        $marks = array_filter([
            in_array($draft->status, self::FILEABLE_STATUSES, true) ? null : "DRAFT #{$draft->id} ({$draft->status}) — NOT FOR FILING",
            $filed === null ? null : 'THIS FORM HAS ALREADY BEEN FILED AS FORM ' . BirFormNames::label($filed)
                . '. THIS IS VERSION ' . $draft->version . ', FORM ' . BirFormNames::label($draft) . '.',
        ]);
        if ($marks === []) {
            return;
        }
        $text = self::latin1(implode('  ·  ', $marks));

        $pdf->SetTextColor(200, 0, 0);
        for ($size = 8; $size > 5; $size -= 0.5) {
            $pdf->SetFont(self::FONT, 'B', $size);
            if ($pdf->GetStringWidth($text) <= $pageWidth - 20) {
                break;
            }
        }
        $pdf->SetXY(0, 1);
        $pdf->Cell($pageWidth, 9, $text, 0, 0, 'C');
        $pdf->SetTextColor(0, 0, 0);
    }

    /**
     * The filed earlier version of this 2316, when this version isn't filed yet itself; else
     * null. Only versions of the same form count (BirFormNames::rootId).
     */
    private function filedBefore(BirFormDraft $draft): ?BirFormDraft
    {
        if ($draft->form_type !== Form2316Schema::FORM_TYPE || $draft->version <= 1 || $draft->status === 'finalized') {
            return null;
        }
        $root = BirFormNames::rootId($draft);

        return BirFormDraft::where('form_type', $draft->form_type)
            ->where('period', $draft->period)
            ->where('employee_id', $draft->employee_id)
            ->where('status', 'finalized')
            ->where('version', '<', $draft->version)
            ->orderByDesc('version')
            ->get()
            ->first(fn (BirFormDraft $d) => BirFormNames::rootId($d) === $root);
    }

    /** FPDF's built-in fonts are Windows-1252, which covers Ñ and other Filipino letters. */
    private static function latin1(string $text): string
    {
        return mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
    }
}
