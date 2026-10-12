<?php

namespace App\Http\Controllers;

use App\Models\Session;
use App\Services\OrderOfPlay;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Printable order of play. Public by session uuid, like the live
 * scoreboard, so the host can hand the link to the opposing club.
 */
class OrderOfPlayController extends Controller
{
    /**
     * GET /sessions/{session}/order-of-play
     *
     *   ?format=pdf        download instead of rendering HTML
     *   ?start=08:15       print a time column, one slot per round
     *   ?slot=15           minutes per round (default 15)
     */
    public function show(Request $request, Session $session, OrderOfPlay $orderOfPlay): View|Response
    {
        $input = $request->validate([
            'format' => ['nullable', 'in:html,pdf'],
            'start' => ['nullable', 'date_format:H:i'],
            'slot' => ['nullable', 'integer', 'min:5', 'max:120'],
        ]);

        $start = $input['start'] ?? null;
        $sheet = $orderOfPlay->build($session, $start, (int) ($input['slot'] ?? 15));

        $data = ['sheet' => $sheet, 'hasTime' => $start !== null];

        if (($input['format'] ?? 'html') !== 'pdf') {
            return view('order-of-play', [...$data, 'pdf' => false]);
        }

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('order-of-play', [...$data, 'pdf' => true])->render());
        $dompdf->setPaper('a4', 'portrait');
        $dompdf->render();

        $filename = 'order-of-play-'.Str::slug($session->name).'.pdf';

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
