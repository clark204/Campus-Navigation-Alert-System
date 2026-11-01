<?php

namespace Tests\Support;

use Dompdf\Dompdf;
use Illuminate\Http\UploadedFile;

/**
 * COR text and PDFs built from tests/Fixtures/cor (see the README there).
 *
 * The PDF is a real dompdf render (A4, one page) of fixture text, so it goes through
 * the same smalot/pdfparser path as a real COR.
 */
final class CorFixture
{
    /** Current layout, pdftotext -layout. AY 2026-2027 1st, student 20240001, 9 subjects / 27 units. */
    public const CURRENT_LAYOUT = 'sample-cor.txt';

    /** Current layout as smalot extracts it from the real PDF (units glued to the day). */
    public const CURRENT_SMALOT = 'sample-cor-smalot.txt';

    /** Older layout as smalot extracts it. AY 2025-2026 2nd Term, student 20230002, 9 subjects / 26 units. */
    public const OLDER_SMALOT = 'sample-cor-2025-2nd-term-smalot.txt';

    public const STUDENT_ID = '20240001';

    private const HEADER = 'Tagoloan Community College';

    public static function text(string $fixture = self::CURRENT_LAYOUT): string
    {
        return file_get_contents(dirname(__DIR__).'/Fixtures/cor/'.$fixture);
    }

    /**
     * Replace text in one printed copy (1 or 2) or, with null, in both.
     */
    public static function tampered(string $search, string $replace, ?int $copy = null, string $fixture = self::CURRENT_LAYOUT): string
    {
        $text = self::text($fixture);
        $secondCopyStart = strpos($text, self::HEADER, strpos($text, self::HEADER) + 1);
        $copies = [substr($text, 0, $secondCopyStart), substr($text, $secondCopyStart)];

        foreach ($copies as $index => $copyText) {
            if ($copy === null || $copy === $index + 1) {
                $copies[$index] = str_replace($search, $replace, $copyText);
            }
        }

        return implode('', $copies);
    }

    public static function pdf(?string $text = null): string
    {
        $html = '<html><head><style>@page { size: A4; margin: 10mm } body { font-family: "DejaVu Sans Mono"; font-size: 6.5pt } pre { margin: 0 }</style></head>'
            .'<body><pre>'.htmlspecialchars($text ?? self::text()).'</pre></body></html>';

        $dompdf = new Dompdf;
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4');
        $dompdf->render();

        return $dompdf->output();
    }

    public static function upload(?string $text = null): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('cor.pdf', self::pdf($text));
    }

    /**
     * A real COR PDF padded past the 5 MB limit (PDF readers ignore trailing comments).
     */
    public static function oversizedUpload(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('cor.pdf', self::pdf()."\n%".str_repeat('x', 5 * 1024 * 1024 + 1024));
    }
}
