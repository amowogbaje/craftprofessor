<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PinterestBoard;
use App\Models\SocialAccount;
use App\Services\PinterestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GET    /api/pinterest/boards               list known boards
 * POST   /api/pinterest/boards/sync           pull the latest board list from Pinterest
 * PATCH  /api/pinterest/boards/{board}        toggle active / edit topics
 * PUT    /api/pinterest/posting-mode          dynamic vs fixed (+ which boards, if fixed)
 */
class PinterestBoardController extends Controller
{
    protected function account(Request $request): SocialAccount
    {
        return $request->user()->socialAccounts()
            ->where('provider', 'pinterest')
            ->firstOrFail();
    }

    public function index(Request $request): JsonResponse
    {
        $account = $this->account($request);

        return response()->json([
            'board_posting_mode' => $account->board_posting_mode,
            'boards' => $account->boards()->orderBy('name')->get(),
            'preferred_board_ids' => $account->preferredBoards()->pluck('pinterest_boards.id'),
        ]);
    }

    public function sync(Request $request): JsonResponse
    {
        $account = $this->account($request);
        $pinterest = PinterestService::forAccount($account);

        $remoteBoards = collect($pinterest->listBoards(100)['items'] ?? []);

        $synced = $remoteBoards->map(function (array $remote) use ($account) {
            return PinterestBoard::updateOrCreate(
                ['social_account_id' => $account->id, 'external_board_id' => $remote['id']],
                [
                    'user_id' => $account->user_id,
                    'name' => $remote['name'] ?? 'Untitled board',
                    'description' => $remote['description'] ?? null,
                    // Don't clobber source='ai_created' for boards this app made —
                    // only set 'synced' for boards seen for the first time via this call.
                ] + (PinterestBoard::where('social_account_id', $account->id)
                        ->where('external_board_id', $remote['id'])->exists()
                    ? [] : ['source' => 'synced'])
            );
        });

        return response()->json([
            'message' => "Synced {$synced->count()} board(s) from Pinterest.",
            'boards' => $account->boards()->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, PinterestBoard $board): JsonResponse
    {
        abort_unless($board->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'is_active' => ['sometimes', 'boolean'],
            'description' => ['sometimes', 'nullable', 'string'],
            'topics' => ['sometimes', 'nullable', 'array'],
            'topics.*' => ['string'],
        ]);

        $board->update($data);

        return response()->json(['board' => $board]);
    }

    public function updatePostingMode(Request $request): JsonResponse
    {
        $account = $this->account($request);

        $data = $request->validate([
            'board_posting_mode' => ['required', Rule::in(['dynamic', 'fixed'])],
            'board_ids' => ['required_if:board_posting_mode,fixed', 'array', 'max:3'],
            'board_ids.*' => ['integer', Rule::exists('pinterest_boards', 'id')->where('social_account_id', $account->id)],
        ]);

        $account->update(['board_posting_mode' => $data['board_posting_mode']]);

        if ($data['board_posting_mode'] === 'fixed') {
            $account->preferredBoards()->sync($data['board_ids']);
        } else {
            $account->preferredBoards()->sync([]);
        }

        return response()->json([
            'message' => 'Posting preference updated.',
            'board_posting_mode' => $account->board_posting_mode,
            'preferred_board_ids' => $account->preferredBoards()->pluck('pinterest_boards.id'),
        ]);
    }
}
