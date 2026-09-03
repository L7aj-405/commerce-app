<?php

declare(strict_types=1);

namespace App\Services\Documents;

use Illuminate\Support\Facades\View;
use Mpdf\Mpdf;

/**
 * Turns a ResolvedDocumentTemplate + a data bag into PDF bytes with mPDF.
 * Paper format, orientation, margins and font all come from the template's
 * merged settings, so a future custom template changes the output without
 * any code change here.
 *
 * Only for INTERNAL / fallback documents — provider PDFs never pass through.
 */
class DocumentRenderer
{
    /**
     * @param  array<string, mixed>  $data  passed to the Blade view as `$data`
     * @param  array<string, mixed>  $htmlSections  optional extra view vars (e.g. `orders` for a batch view)
     */
    public function render(ResolvedDocumentTemplate $template, array $data, array $htmlSections = []): string
    {
        $mpdf = $this->makeMpdf($template);

        $html = View::make($template->view, array_merge([
            'data' => $data,
            'template' => $template,
        ], $htmlSections))->render();

        $mpdf->WriteHTML($html);

        return $mpdf->Output('', 'S');
    }

    /**
     * Render several data bags into ONE PDF, one order per page.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    public function renderBatch(ResolvedDocumentTemplate $template, array $items): string
    {
        $mpdf = $this->makeMpdf($template);

        $first = true;

        foreach ($items as $data) {
            $html = View::make($template->view, ['data' => $data, 'template' => $template])->render();

            if (! $first) {
                $mpdf->AddPage();
            }

            $mpdf->WriteHTML($html);
            $first = false;
        }

        return $mpdf->Output('', 'S');
    }

    private function makeMpdf(ResolvedDocumentTemplate $template): Mpdf
    {
        $tempDir = storage_path('app/mpdf-tmp');

        if (! is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }

        $margins = is_array($template->setting('margins')) ? $template->setting('margins') : [];

        return new Mpdf([
            'tempDir' => $tempDir,
            'mode' => 'utf-8',
            'format' => $this->mpdfFormat($template->setting('paper_format', 'A5')),
            'orientation' => $this->orientation($template->setting('orientation', 'P')),
            'margin_top' => $this->margin($margins['top'] ?? null, 10),
            'margin_right' => $this->margin($margins['right'] ?? null, 10),
            'margin_bottom' => $this->margin($margins['bottom'] ?? null, 10),
            'margin_left' => $this->margin($margins['left'] ?? null, 10),
            'default_font' => is_string($template->setting('font')) ? $template->setting('font') : 'dejavusans',
        ]);
    }

    private function mpdfFormat(mixed $paper): string|array
    {
        if (is_array($paper) && count($paper) === 2 && is_numeric($paper[0]) && is_numeric($paper[1])) {
            return [(float) $paper[0], (float) $paper[1]];
        }

        $p = is_string($paper) ? strtoupper(trim($paper)) : 'A5';

        return in_array($p, ['A3', 'A4', 'A5', 'A6', 'LETTER', 'LEGAL'], true) ? $p : 'A5';
    }

    private function orientation(mixed $value): string
    {
        return strtoupper((string) $value) === 'L' ? 'L' : 'P';
    }

    private function margin(mixed $value, float $default): float
    {
        return is_numeric($value) ? (float) $value : $default;
    }
}
