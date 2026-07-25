<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\FlutterwaveService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class WalletController extends Controller
{
    /** GET /api/wallet */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'balance' => $user->wallet->balance,
            'lifetime_purchased' => $user->wallet->lifetime_purchased,
            'lifetime_spent' => $user->wallet->lifetime_spent,
            'packages' => config('coins.packages'),
            'costs' => config('coins.costs'),
        ]);
    }

    /** GET /api/wallet/transactions */
    public function transactions(Request $request): JsonResponse
    {
        return response()->json(
            $request->user()->coinTransactions()->latest()->paginate(30)
        );
    }

    /**
     * POST /api/wallet/topup
     * { package_index: int }  — index into config('coins.packages')
     * Returns a Flutterwave payment_link to redirect the user to.
     */
    public function initiateTopup(Request $request, FlutterwaveService $flutterwave): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'package_index' => ['required', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $packages = config('coins.packages');
        $index = $request->input('package_index');

        if (!isset($packages[$index])) {
            return response()->json(['message' => 'Unknown package.'], 422);
        }

        $package = $packages[$index];
        $user = $request->user();

        $result = $flutterwave->initialize(
            $user,
            $package['amount'],
            $package['currency'],
            $package['coins'],
            route('wallet.topup.callback'),
        );

        return response()->json($result);
    }

    /**
     * GET /wallet/topup/callback?status=&tx_ref=&transaction_id=
     * Flutterwave redirects the browser here after checkout. We verify
     * server-side before crediting anything — never trust the redirect alone.
     */
    public function handleRedirectCallback(Request $request, FlutterwaveService $flutterwave, WalletService $wallet): JsonResponse
    {
        $transactionId = $request->query('transaction_id');
        $status = $request->query('status');

        if ($status !== 'successful' || !$transactionId) {
            return response()->json(['message' => 'Payment was not completed.'], 400);
        }

        return $this->verifyAndCredit($transactionId, $flutterwave, $wallet);
    }

    /**
     * POST /webhooks/flutterwave
     * Server-to-server webhook — the reliable path (the redirect callback
     * above can be skipped/closed by the user before it fires).
     */
    public function handleWebhook(Request $request, FlutterwaveService $flutterwave, WalletService $wallet): JsonResponse
    {
        if (!$flutterwave->isValidWebhookSignature($request->header('verif-hash'))) {
            Log::warning('WalletController: rejected webhook with invalid signature');
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $transactionId = data_get($request->json()->all(), 'data.id');
        if (!$transactionId) {
            return response()->json(['message' => 'Missing transaction id.'], 422);
        }

        $this->verifyAndCredit((string) $transactionId, $flutterwave, $wallet);

        return response()->json(['message' => 'ok']);
    }

    protected function verifyAndCredit(string $transactionId, FlutterwaveService $flutterwave, WalletService $wallet): JsonResponse
    {
        $data = $flutterwave->verify($transactionId);

        if (($data['status'] ?? null) !== 'successful') {
            return response()->json(['message' => 'Transaction not successful.'], 400);
        }

        $txRef = $data['tx_ref'] ?? null;

        // Idempotency: never credit the same Flutterwave transaction twice.
        $alreadyCredited = \App\Models\CoinTransaction::where('provider_reference', $txRef)->exists();
        if ($alreadyCredited) {
            return response()->json(['message' => 'Already processed.']);
        }

        $userId = data_get($data, 'meta.user_id');
        $coins = (int) data_get($data, 'meta.coins', 0);

        $user = User::find($userId);
        if (!$user || $coins <= 0) {
            Log::error('WalletController: could not resolve user/coins from verified transaction', ['tx_ref' => $txRef]);
            return response()->json(['message' => 'Could not credit coins — contact support.'], 422);
        }

        $wallet->credit($user, $coins, 'purchase', $txRef, [
            'amount' => $data['amount'] ?? null,
            'currency' => $data['currency'] ?? null,
        ]);

        Log::info('WalletController: credited coin purchase', ['user_id' => $user->id, 'coins' => $coins, 'tx_ref' => $txRef]);

        return response()->json(['message' => 'Coins credited.', 'coins' => $coins]);
    }
}
