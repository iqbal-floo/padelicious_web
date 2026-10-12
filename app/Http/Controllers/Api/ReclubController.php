<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ReclubImportException;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlayerResource;
use App\Services\ReclubImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReclubController extends Controller
{
    /**
     * POST /api/v1/reclub/import {code}
     *
     * Reads a Reclub meet's roster and returns it as players the app can put
     * on a session, each with the status they had on Reclub (confirmed or
     * waitlisted). Nothing is added to a session here: the client sends the
     * chosen players when it creates the session, with source "reclub".
     */
    public function import(Request $request, ReclubImporter $importer): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:20']]);

        try {
            $result = $importer->import($request->string('code')->toString(), $request->user()->player);
        } catch (ReclubImportException $e) {
            return response()->json(
                ['message' => $e->getMessage(), 'errors' => ['code' => [$e->getMessage()]]],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return response()->json([
            'data' => [
                'code' => $result['code'],
                'title' => $result['title'],
                'url' => $result['url'],
                'players' => array_map(fn (array $row) => [
                    'status' => $row['status'],
                    'player' => new PlayerResource($row['player']),
                ], $result['players']),
            ],
        ]);
    }
}
