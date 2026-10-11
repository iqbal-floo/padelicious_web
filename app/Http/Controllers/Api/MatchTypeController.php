<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MatchTypeResource;
use App\Models\MatchType;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MatchTypeController extends Controller
{
    /**
     * GET /api/v1/match-types — static reference data for the setup screen.
     */
    public function index(): AnonymousResourceCollection
    {
        return MatchTypeResource::collection(
            MatchType::query()->orderBy('sort_order')->get()
        );
    }
}
