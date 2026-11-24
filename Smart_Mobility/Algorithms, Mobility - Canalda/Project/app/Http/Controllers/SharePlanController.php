<?php

namespace App\Http\Controllers;

use App\Models\SharedPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SharePlanController extends Controller
{
    /**
     * Store a shared planned route option and return a unique code.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'option' => ['required', 'array'],
            'option.itineraries' => ['required', 'array'],
            'destination' => ['nullable', 'array'],
            'destination.name' => ['nullable', 'string'],
            'destination.lat' => ['nullable', 'numeric'],
            'destination.lon' => ['nullable', 'numeric'],
            'participants' => ['nullable', 'array'],
        ]);

        SharedPlan::pruneExpired();

        $code = SharedPlan::generateUniqueCode();

        $plan = SharedPlan::create([
            'code' => $code,
            'option' => $request->input('option'),
            'destination' => $validated['destination'] ?? null,
            'participants' => $request->input('participants'),
            'expires_at' => now()->addMinutes(30),
        ]);

        return response()->json([
            'code' => $plan->code,
            'expires_at' => $plan->expires_at?->toISOString(),
            'share_url' => url('/?code='.$plan->code),
        ], 201);
    }

    /**
     * Retrieve a shared planned route option by code.
     */
    public function show(string $code): JsonResponse
    {
        $plan = SharedPlan::where('code', strtoupper(trim($code)))->first();

        if (! $plan || $plan->isExpired()) {
            if ($plan && $plan->isExpired()) {
                $plan->delete();
            }

            return response()->json([
                'message' => 'Shared plan expired or not found.',
            ], 404);
        }

        return response()->json([
            'code' => $plan->code,
            'option' => $plan->option,
            'destination' => $plan->destination,
            'participants' => $plan->participants,
            'expires_at' => $plan->expires_at?->toISOString(),
            'created_at' => $plan->created_at?->toISOString(),
        ]);
    }
}
