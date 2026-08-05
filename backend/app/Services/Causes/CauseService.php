<?php

namespace App\Services\Causes;

use App\Models\Cause;
use App\Models\CauseMember;
use App\Models\User;
use Illuminate\Support\Str;
use RuntimeException;

class CauseService
{
    public function __construct(protected CausePaymentService $payments)
    {
    }

    /**
     * Creates the Cause in 'pending_payment' status and kicks off the
     * Flutterwave checkout for its creation fee. The cause only flips to
     * 'active' once CausePaymentService::verifyAndMarkPaid() runs (via the
     * redirect callback / webhook) — or a dev waives it.
     *
     * @return array{cause: Cause, payment_link: string}
     */
    public function create(User $owner, array $attributes, string $paymentRedirectUrl): array
    {
        $cause = Cause::create([
            'owner_id' => $owner->id,
            'title' => $attributes['title'],
            'description' => $attributes['description'] ?? null,
            'goal' => $attributes['goal'] ?? null,
            'creation_fee_amount' => (float) config('causes.creation_fee', 10.00),
            'creation_fee_currency' => config('causes.creation_fee_currency', 'USD'),
        ]);

        // The owner is automatically a joined member of their own cause.
        CauseMember::create([
            'cause_id' => $cause->id,
            'user_id' => $owner->id,
            'invited_by' => $owner->id,
            'status' => 'joined',
            'joined_at' => now(),
        ]);

        $payment = $this->payments->initiate($cause, $paymentRedirectUrl);

        return ['cause' => $cause->fresh(), 'payment_link' => $payment['payment_link']];
    }

    public function invite(Cause $cause, User $inviter, User $invitee): CauseMember
    {
        if ($invitee->id === $cause->owner_id) {
            throw new RuntimeException('The owner is already part of this cause.');
        }

        return CauseMember::firstOrCreate(
            ['cause_id' => $cause->id, 'user_id' => $invitee->id],
            ['invited_by' => $inviter->id, 'status' => 'invited']
        );
    }

    public function join(Cause $cause, User $user): CauseMember
    {
        if (!$cause->isActive()) {
            throw new RuntimeException('This cause is not accepting members yet.');
        }

        $member = CauseMember::firstOrCreate(
            ['cause_id' => $cause->id, 'user_id' => $user->id],
            ['invited_by' => $user->id, 'status' => 'invited']
        );

        $member->join();

        return $member->fresh();
    }

    public function optOut(Cause $cause, User $user): CauseMember
    {
        $member = $cause->memberFor($user->id);

        if (!$member) {
            throw new RuntimeException('Not a member of this cause.');
        }

        $member->optOut();

        return $member->fresh();
    }

    public function search(?string $term, int $perPage = 20)
    {
        return Cause::discoverable()
            ->search($term)
            ->withCount('joinedMembers')
            ->latest()
            ->paginate($perPage);
    }
}
