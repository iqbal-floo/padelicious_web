<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Session;
use App\Services\OrderOfPlay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderOfPlayController extends Controller
{
    /**
     * GET /api/v1/sessions/{session}/order-of-play
     *
     * The same rows the printable sheet shows, plus the HTML and PDF
     * links, so the app can render it natively or just open the file.
     */
    public function show(Request $request, Session $session, OrderOfPlay $orderOfPlay): JsonResponse
    {
        $input = $request->validate([
            'start' => ['nullable', 'date_format:H:i'],
            'slot' => ['nullable', 'integer', 'min:5', 'max:120'],
        ]);

        $query = array_filter(['start' => $input['start'] ?? null, 'slot' => $input['slot'] ?? null]);

        return response()->json([
            'data' => [
                ...$orderOfPlay->build($session, $input['start'] ?? null, (int) ($input['slot'] ?? 15)),
                'html_url' => route('sessions.order-of-play', [$session, ...$query]),
                'pdf_url' => route('sessions.order-of-play', [$session, ...$query, 'format' => 'pdf']),
            ],
        ]);
    }
}
