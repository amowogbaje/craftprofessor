<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Causes\CausePaymentService;
use App\Services\FlutterwaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CausePaymentController extends Controller
{
    /**
     * GET /causes/payment/callback?status=&transaction_id=
     * Flutterwave redirects the browser here after checkout.
     */
    public function handleRedirectCallback(Request $request, CausePaymentService $payments): JsonResponse
    {
        $transactionId = $request->query('transaction_id');
        $status = $request->query('status');

        if ($status !== 'successful' || !$transactionId) {
            return response()->json(['message' => 'Payment was not completed.'], 400);
        }

        try {
            $cause = $payments->verifyAndMarkPaid($transactionId);
        } catch (\Throwable $e) {
            Log::error('CausePaymentController: verify failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Could not verify payment — contact support.'], 422);
        }

        return response()->json(['message' => 'Cause activated.', 'cause' => $cause]);
    }

    /**
     * POST /webhooks/flutterwave/causes
     * Server-to-server webhook — the reliable path.
     */
    public function handleWebhook(Request $request, FlutterwaveService $flutterwave, CausePaymentService $payments): JsonResponse
    {
        if (!$flutterwave->isValidWebhookSignature($request->header('verif-hash'))) {
            Log::warning('CausePaymentController: rejected webhook with invalid signature');
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $transactionId = data_get($request->json()->all(), 'data.id');
        if (!$transactionId) {
            return response()->json(['message' => 'Missing transaction id.'], 422);
        }

        // Only cause-creation payments carry this meta key — coin top-ups
        // are handled by WalletController's own webhook instead.
        if (data_get($request->json()->all(), 'data.meta.type') !== 'cause_creation') {
            return response()->json(['message' => 'Not a cause payment — ignored.']);
        }

        try {
            $payments->verifyAndMarkPaid((string) $transactionId);
        } catch (\Throwable $e) {
            Log::error('CausePaymentController: webhook verify failed', ['error' => $e->getMessage()]);
        }

        return response()->json(['message' => 'ok']);
    }
}
