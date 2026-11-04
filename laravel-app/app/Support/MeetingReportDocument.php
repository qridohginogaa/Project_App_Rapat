<?php

namespace App\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Dompdf\Dompdf;
use Dompdf\Options;

final class MeetingReportDocument
{
    public static function render(
        object $meeting,
        array $attendanceRows,
        array $followUps,
        array $signatures,
        string $verificationBaseUrl,
        bool $allSignaturesApproved
    ): string {
        $writer = new Writer(new ImageRenderer(new RendererStyle(180, 2), new SvgImageBackEnd()));
        foreach ($signatures as &$signature) {
            $svg = $writer->writeString($verificationBaseUrl.'/'.rawurlencode($signature->token));
            $signature->qr_code = 'data:image/svg+xml;base64,'.base64_encode($svg);
            $signature->accepted_at_label = $signature->disetujui_pada
                ? \Illuminate\Support\Carbon::parse($signature->disetujui_pada, config('app.timezone'))
                    ->locale('id')
                    ->translatedFormat('l, j F Y H:i').' WITA'
                : null;
        }
        unset($signature);

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('documents.meeting-report', [
            'meeting' => $meeting,
            'attendanceRows' => $attendanceRows,
            'followUps' => $followUps,
            'signatures' => $signatures,
            'allSignaturesApproved' => $allSignaturesApproved,
        ])->render(), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}
