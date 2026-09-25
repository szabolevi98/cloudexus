<?php

namespace Cloudexus\Core;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Egy HTML-dokumentum PDF-ként, A4-en, a szerveren rajzolva — hogy egy
 * számla csatolható legyen egy levélhez, vagy letölthető legyen úgy, ahogy
 * van.
 *
 * A betű a DejaVu Sans, ami a könyvtárral jön: minden magyar ékezetes betű
 * megvan benne, az ő és az ű is, ami a PDF saját beépített betűiből
 * hiányzik. A dokumentumon kívülről semmi nem töltődik be.
 */
final class Pdf
{
    public static function render(string $html, string $footerText = ''): string
    {
        $cache = dirname(__DIR__, 2) . '/var/cache/dompdf';
        if (!is_dir($cache)) {
            @mkdir($cache, 0775, true);
        }

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('fontCache', $cache);
        $options->set('tempDir', $cache);
        $options->set('chroot', dirname(__DIR__, 2) . '/vendor/dompdf/dompdf');

        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        // Az oldalszám minden oldal sarkában, amikor már tudni, hány oldal van.
        $font = $pdf->getFontMetrics()->getFont('DejaVu Sans');
        if ($footerText !== '' && $font !== null) {
            $canvas = $pdf->getCanvas();
            $canvas->page_text($canvas->get_width() - 110, $canvas->get_height() - 30, $footerText, $font, 7, [0.45, 0.47, 0.52]);
        }

        return (string) $pdf->output();
    }
}
