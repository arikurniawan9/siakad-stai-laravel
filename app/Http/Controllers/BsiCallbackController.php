<?php

namespace App\Http\Controllers;

use App\Domain\Finance\BsiPaymentAllocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class BsiCallbackController extends Controller
{
    public function __invoke(Request $request, BsiPaymentAllocationService $processor): JsonResponse
    {
        $payload = $request->validate([
            'provider' => ['nullable', 'string', 'max:30'],
            'event_id' => ['required', 'string', 'max:100'],
            'va_number' => ['required', 'string', 'max:40'],
            'external_reference' => ['nullable', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency' => ['required', 'string', 'size:3'],
            'paid_at' => ['nullable', 'date'],
        ]);
        try {
            return response()->json($processor->process($payload));
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
