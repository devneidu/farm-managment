<?php

namespace App\Services\Account;

use App\Enums\ConfirmationStatus;
use App\Enums\DealStatus;
use App\Enums\FarmRole;
use App\Enums\ListingStatus;
use App\Enums\MembershipStatus;
use App\Enums\OfferStatus;
use App\Enums\ShopRole;
use App\Enums\ShopStatus;
use App\Models\CalendarFeed;
use App\Models\Farm;
use App\Models\FarmInvitation;
use App\Models\FarmMembership;
use App\Models\MarketplaceDeal;
use App\Models\MarketplaceDealConfirmation;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOffer;
use App\Models\MarketplaceShop;
use App\Models\MarketplaceShopMember;
use App\Models\OneTimeCode;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\AccountDeletedNotification;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\TokenAuthenticator;
use App\Services\Marketplace\MarketplaceConfirmationLifecycle;
use App\Services\Marketplace\MarketplaceListingHistory;
use App\Services\Marketplace\MarketplaceOfferLifecycle;
use App\Services\Platform\PlatformAudit;
use App\Services\Subscription\SubscriptionService;
use App\Support\Api\ApiHttpException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Account deletion by ANONYMISATION. The user row stays (ledgers, records, tasks and audit logs point at it) but no longer holds anything
 * personal; the real email is freed for a new sign-up. One transaction under a lock on the user row; running it again on an already
 * anonymised account does nothing. Self-service and platform-admin deletion share this one code path and the same blockers.
 *
 * Kept on purpose: every farm record, sale, ledger entry, stock movement, task and audit log, and business data about OTHER people (buyer and
 * customer names, contacts). Anywhere the user's name is shown it now reads "Deleted user", because names are read from the user row, not copied.
 */
class AccountDeletionService
{
    public const DELETED_NAME = 'Deleted user';

    public const PLACEHOLDER_DOMAIN = 'deleted.invalid';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PlatformAudit $platformAudit,
        private readonly SubscriptionService $subscriptions,
        private readonly TokenAuthenticator $tokens,
        private readonly MarketplaceOfferLifecycle $offers,
        private readonly MarketplaceConfirmationLifecycle $confirmations,
        private readonly MarketplaceListingHistory $listingHistory,
    ) {}

    /**
     * Everything that currently stops this account from being deleted. Empty means deletion is allowed.
     *
     * @return list<array{code: string, message: string, farm?: array{id: string, name: string}, shops?: list<array{id: string, name: string}>, count?: int}>
     */
    public function blockers(User $user): array
    {
        $blockers = [];

        if ($user->platformAdmin()->exists()) {
            $blockers[] = ['code' => 'platform_admin_protected', 'message' => 'This account holds a platform administrator grant. Revoke it before deleting the account.'];
        }

        $owned = FarmMembership::active()->where('user_id', $user->id)->where('role', FarmRole::Owner->value)->with('farm')->orderBy('id')->get();

        foreach ($owned as $membership) {
            $others = FarmMembership::active()->where('farm_id', $membership->farm_id)->where('user_id', '!=', $user->id);

            if ($others->exists() && ! (clone $others)->where('role', FarmRole::Owner->value)->exists()) {
                $blockers[] = [
                    'code' => 'owner_must_transfer',
                    'message' => "You own the farm \"{$membership->farm->name}\" and it has other members. Transfer ownership to another member before deleting your account.",
                    'farm' => ['id' => $membership->farm_id, 'name' => $membership->farm->name],
                ];
            }
        }

        // "In progress" = a marketplace deal in status `accepted` (DealStatus::isOpen: both sides agreed, neither has completed or cancelled it),
        // either as the buyer or on a shop this user owns (and so would close).
        $ownedShopIds = $this->ownedShopIds($user);
        $open = MarketplaceDeal::query()->where('status', DealStatus::Accepted->value)
            ->where(fn ($q) => $q->where('buyer_id', $user->id)->orWhereIn('shop_id', $ownedShopIds));

        if (($count = $open->count()) > 0) {
            $shops = MarketplaceShop::whereIn('id', (clone $open)->whereIn('shop_id', $ownedShopIds)->select('shop_id'))->orderBy('name')->get(['id', 'name'])
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values()->all();

            $blockers[] = array_filter([
                'code' => 'marketplace_deals_open',
                'message' => "You have {$count} marketplace deal(s) in progress. Complete or cancel them before deleting your account.",
                'count' => $count,
                'shops' => $shops ?: null,
            ]);
        }

        return $blockers;
    }

    /**
     * @return array{already_deleted: bool}
     *
     * @throws ApiHttpException 409 account_deletion_blocked
     */
    public function delete(User $user, ?User $admin = null, ?string $reason = null, string $method = 'password'): array
    {
        $oldEmail = null;

        $result = DB::transaction(function () use ($user, $admin, $reason, $method, &$oldEmail) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($locked->isAnonymised()) {
                return ['already_deleted' => true];
            }

            if ($blockers = $this->blockers($locked)) {
                throw new ApiHttpException(409, 'account_deletion_blocked', 'This account cannot be deleted yet.', details: ['blockers' => $blockers]);
            }

            $oldEmail = $locked->email;
            $stats = ['farms_archived' => 0, 'memberships_ended' => 0, 'shops_closed' => 0];

            $this->endMemberships($locked, $stats);
            $this->closeMarketplace($locked, $stats);
            $this->settleInvitations($locked);
            $this->removeAccountData($locked);
            $this->scrubAuditLabels($locked, $oldEmail);
            $this->anonymise($locked);

            if ($admin) {
                $this->platformAudit->record($admin, 'platform.user_deleted', 'user', $locked->id, null, [], [], ['reason' => $reason] + $stats);
            } else {
                $this->audit->record(null, $locked->id, 'account.deleted', 'user', $locked->id, null, ['method' => $method] + $stats);
            }

            return ['already_deleted' => false];
        });

        if (! $result['already_deleted'] && $oldEmail !== null) {
            try {
                Notification::route('mail', $oldEmail)->notify(new AccountDeletedNotification);
            } catch (Throwable $e) {
                report(new \RuntimeException('Account-deleted email could not be queued: '.get_class($e)));
            }
        }

        return $result;
    }

    /** @param  array<string, int>  $stats */
    private function endMemberships(User $user, array &$stats): void
    {
        $active = FarmMembership::active()->where('user_id', $user->id)->orderBy('id')->lockForUpdate()->get();

        foreach ($active as $membership) {
            $farm = Farm::whereKey($membership->farm_id)->lockForUpdate()->firstOrFail();
            $hasOthers = FarmMembership::active()->where('farm_id', $farm->id)->where('user_id', '!=', $user->id)->exists();

            $membership->forceFill(['status' => MembershipStatus::Removed, 'removed_at' => now(), 'removed_by_user_id' => $user->id, 'notification_preferences' => null])->save();
            $stats['memberships_ended']++;
            $this->audit->record($farm->id, $user->id, 'account.member_left', 'membership', $membership->id, null, ['role' => $membership->role->value]);

            if (! $hasOthers) {
                $this->archiveFarm($farm, $user);
                $stats['farms_archived']++;
            }
        }

        // Already-ended memberships keep their history but no longer hold the user's notification choices.
        FarmMembership::where('user_id', $user->id)->whereNotNull('notification_preferences')->update(['notification_preferences' => null]);
    }

    /** The farm's only member left: nobody can open it any more, nothing is deleted, and a paid plan stops renewing. */
    private function archiveFarm(Farm $farm, User $user): void
    {
        $farm->forceFill(['archived_at' => now()])->save();

        FarmInvitation::where('farm_id', $farm->id)->whereNull('accepted_at')->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_by_user_id' => $user->id]);

        try {
            // Billing is internal: there is no payment provider holding a recurring mandate for farm plans, so "cancel auto-renew" is the
            // subscription's own end-of-period cancellation. No refund is issued.
            $this->subscriptions->cancel($farm, $user);
        } catch (ApiHttpException) {
            // free plan, already cancelled or no longer current: nothing to stop
        }
    }

    /** @param  array<string, int>  $stats */
    private function closeMarketplace(User $user, array &$stats): void
    {
        // As a BUYER: pending offers and waiting seller confirmations are withdrawn (they could only ever lead to a deal with a deleted account).
        MarketplaceOffer::where('buyer_id', $user->id)->where('status', OfferStatus::Pending->value)->lockForUpdate()->get()
            ->each(fn (MarketplaceOffer $o) => $this->offers->move($o, OfferStatus::Voided, 'system', null, 'account_deleted'));
        MarketplaceDealConfirmation::where('buyer_id', $user->id)->where('status', ConfirmationStatus::AwaitingBuyer->value)->lockForUpdate()->get()
            ->each(fn (MarketplaceDealConfirmation $c) => $this->confirmations->settle($c, ConfirmationStatus::Voided, 'system', null, 'account_deleted'));
        MarketplaceDeal::where('buyer_id', $user->id)->whereNotNull('buyer_contact_phone')->update(['buyer_contact_phone' => null]);

        // As a SELLER: shops this user owns are closed and their listings withdrawn; manager/staff seats are simply given up.
        foreach (MarketplaceShop::whereIn('id', $this->ownedShopIds($user))->lockForUpdate()->get() as $shop) {
            MarketplaceOffer::where('shop_id', $shop->id)->where('status', OfferStatus::Pending->value)->lockForUpdate()->get()
                ->each(fn (MarketplaceOffer $o) => $this->offers->move($o, OfferStatus::Voided, 'system', null, 'shop_closed'));
            MarketplaceDealConfirmation::where('shop_id', $shop->id)->where('status', ConfirmationStatus::AwaitingBuyer->value)->lockForUpdate()->get()
                ->each(fn (MarketplaceDealConfirmation $c) => $this->confirmations->settle($c, ConfirmationStatus::Voided, 'system', null, 'shop_closed'));

            MarketplaceListing::where('shop_id', $shop->id)->whereIn('status', [ListingStatus::Draft->value, ListingStatus::Published->value, ListingStatus::Paused->value])->lockForUpdate()->get()
                ->each(function (MarketplaceListing $l) {
                    $from = $l->status->value;
                    $l->forceFill(['status' => ListingStatus::Archived, 'archived_at' => now(), 'version' => $l->version + 1])->save();
                    $this->listingHistory->record($l, 'system', null, 'archived', $from, ListingStatus::Archived->value, 'account_deleted');
                });

            // A suspended shop stays suspended (that is a moderation decision); every other shop is closed. The seller's private contact details go either way.
            $shop->forceFill(array_filter([
                'status' => $shop->status === ShopStatus::Suspended ? null : ShopStatus::Closed,
                'closed_at' => $shop->status === ShopStatus::Suspended ? null : now(),
            ]) + ['contact_phone' => null, 'contact_whatsapp' => null, 'contact_email' => null, 'address_line' => null])->save();
            $stats['shops_closed']++;
        }

        MarketplaceShopMember::where('user_id', $user->id)->delete();
    }

    /** Invitations they sent are cancelled; invitations addressed to their old email can never be accepted and no longer carry it. */
    private function settleInvitations(User $user): void
    {
        FarmInvitation::where('invited_by_user_id', $user->id)->whereNull('accepted_at')->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_by_user_id' => $user->id]);

        FarmInvitation::whereRaw('lower(email) = ?', [mb_strtolower($user->email)])->whereNull('accepted_at')->whereNull('revoked_at')
            ->update(['expires_at' => now()->subSecond()]);

        // One placeholder per row so the (farm, email) lookups never collide.
        FarmInvitation::whereRaw('lower(email) = ?', [mb_strtolower($user->email)])->get()
            ->each(fn (FarmInvitation $i) => $i->forceFill(['email' => 'deleted+'.$i->id.'@'.self::PLACEHOLDER_DOMAIN])->save());
    }

    private function removeAccountData(User $user): void
    {
        $this->tokens->revokeAll($user);   // every Bearer token and every web session
        SocialAccount::where('user_id', $user->id)->delete();
        OneTimeCode::where('user_id', $user->id)->delete();
        CalendarFeed::where('user_id', $user->id)->delete();
        DB::table('notifications')->where('user_id', $user->id)->delete();   // the user's personal inbox, not a farm record
    }

    /**
     * audit_logs.resource_label copies a person's name or email at write time (member removed / role changed carry the member's name,
     * invitation entries the invitee's email, a platform suspension the user's email). The entry and its facts stay; only the label is replaced.
     */
    private function scrubAuditLabels(User $user, string $oldEmail): void
    {
        $membershipIds = FarmMembership::where('user_id', $user->id)->pluck('id');
        $invitationIds = FarmInvitation::whereRaw('lower(email) = ?', [mb_strtolower($oldEmail)])->pluck('id');

        DB::table('audit_logs')->whereNotNull('resource_label')->where(function ($q) use ($user, $membershipIds, $invitationIds) {
            $q->where(fn ($w) => $w->where('resource_type', 'user')->where('resource_id', $user->id))
                ->orWhere(fn ($w) => $w->where('resource_type', 'membership')->whereIn('resource_id', $membershipIds))
                ->orWhere(fn ($w) => $w->where('resource_type', 'invitation')->whereIn('resource_id', $invitationIds));
        })->update(['resource_label' => self::DELETED_NAME]);
    }

    private function anonymise(User $user): void
    {
        // email_verified_at becomes NULL: the placeholder is not a verified mailbox, and an unverified account cannot be added to a shop or reach any app route.
        $user->forceFill([
            'name' => self::DELETED_NAME,
            'email' => 'deleted+'.$user->id.'@'.self::PLACEHOLDER_DOMAIN,
            'password' => null,
            'remember_token' => null,
            'email_verified_at' => null,
            'locale' => null,
            'anonymised_at' => now(),
        ])->save();
    }

    /** @return list<string> */
    private function ownedShopIds(User $user): array
    {
        return MarketplaceShopMember::where('user_id', $user->id)->where('role', ShopRole::Owner->value)->pluck('shop_id')->all();
    }
}
