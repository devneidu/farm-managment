<?php

namespace App\Services\Marketplace;

use App\Enums\MembershipStatus;
use App\Enums\Permission;
use App\Enums\ShopRole;
use App\Enums\ShopStatus;
use App\Enums\ShopVerificationStatus;
use App\Models\FarmMembership;
use App\Models\MarketplaceShop;
use App\Models\MarketplaceShopMember;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Platform\PlatformConfigService;
use App\Support\Api\ApiHttpException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Seller-side shop management: onboarding, profile, private contact configuration, lifecycle requests and shop members. Everything is scoped
 * to a shop the caller is a MEMBER of (a non-member gets 404, never a hint that the shop exists); the shop role then decides the action
 * (MarketplaceShopPolicy). Publishing is never decided here: only a platform admin approval makes a shop public (MarketplaceModerationService).
 */
class MarketplaceShopService
{
    public const DEFAULT_MAX_SHOPS = 3;

    private const PROFILE = ['name', 'tagline', 'description', 'seller_type', 'categories', 'country_code', 'state', 'city', 'area'];

    private const CONTACT = ['address_line', 'contact_phone', 'contact_whatsapp', 'contact_email', 'preferred_contact_method'];

    public function __construct(private AuditLogger $audit, private PlatformConfigService $config) {}

    /** @return Collection<int, MarketplaceShop> shops the user belongs to, newest first, each with the user's membership as `viewer` */
    public function mine(User $user): Collection
    {
        $members = MarketplaceShopMember::with('shop')->where('user_id', $user->id)->get();

        return $members->each(fn ($m) => $m->shop->setRelation('viewer', $m))->map->shop->sortByDesc('created_at')->values();
    }

    /** The shop, with the caller's membership loaded as `viewer`. 404 when the caller is not a member. */
    public function memberShop(User $user, string $shopId, bool $lock = false): MarketplaceShop
    {
        $member = MarketplaceShopMember::where('shop_id', $shopId)->where('user_id', $user->id)->first();
        abort_if($member === null, 404);
        $shop = MarketplaceShop::query()->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($shopId);
        $shop->setRelation('viewer', $member);

        return $shop;
    }

    public function show(User $user, string $shopId): MarketplaceShop
    {
        $shop = $this->memberShop($user, $shopId);
        $this->authorize($user, 'view', $shop);

        return $shop;
    }

    /** The private contact configuration; only a member who may manage it can read it. */
    public function contact(User $user, string $shopId): MarketplaceShop
    {
        $shop = $this->memberShop($user, $shopId);
        $this->authorize($user, 'manageContact', $shop);

        return $shop;
    }

    /** @param  array<string, mixed>  $data */
    public function create(User $user, array $data): MarketplaceShop
    {
        $farmId = $data['farm_id'] ?? null;
        if ($farmId !== null) {
            $this->authorizeFarmLink($user, $farmId);
        }

        // One global lock serialises reference/slug allocation and the per-user limit check; released after the transaction commits.
        $lock = 'marketplace_shop_create';
        DB::select('SELECT GET_LOCK(?, 10)', [$lock]);
        try {
            return DB::transaction(function () use ($user, $data, $farmId) {
                if ($farmId !== null && MarketplaceShop::where('farm_id', $farmId)->exists()) {
                    throw new ApiHttpException(409, 'farm_shop_exists', 'This farm already has a marketplace shop.');
                }
                $limit = (int) ($this->config->setting('marketplace_max_shops_per_user') ?? self::DEFAULT_MAX_SHOPS);
                if (MarketplaceShopMember::where('user_id', $user->id)->where('role', ShopRole::Owner->value)->count() >= $limit) {
                    throw new ApiHttpException(409, 'shop_limit_reached', 'You have reached the maximum number of shops.', details: ['limit' => $limit]);
                }
                $this->assertNameFree($user->id, $data['name']);

                $shop = MarketplaceShop::create($this->profile($data) + ['categories' => []] + [
                    'reference' => $this->nextReference(), 'slug' => $this->uniqueSlug($data['name']), 'farm_id' => $farmId, 'created_by' => $user->id,
                    'status' => ShopStatus::Draft, 'verification_status' => ShopVerificationStatus::Unverified,
                ]);
                $member = MarketplaceShopMember::create(['shop_id' => $shop->id, 'user_id' => $user->id, 'role' => ShopRole::Owner, 'added_by' => $user->id]);
                $shop->setRelation('viewer', $member);
                $this->audit($user, $shop, 'marketplace.shop_created', ['farm_backed' => $farmId !== null, 'seller_type' => $shop->seller_type]);

                return $shop;
            });
        } finally {
            DB::select('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    /** @param  array<string, mixed>  $data */
    public function updateProfile(User $user, string $shopId, array $data): MarketplaceShop
    {
        return DB::transaction(function () use ($user, $shopId, $data) {
            $shop = $this->memberShop($user, $shopId, lock: true);
            $this->authorize($user, 'update', $shop);
            $this->assertWritable($shop);
            if (isset($data['name'])) {
                $this->assertNameFree($shop->created_by, $data['name'], $shop->id);
            }
            $shop->fill($this->profile($data));
            $changed = array_keys($shop->getDirty());
            if ($changed !== []) {
                $shop->save();
                $this->audit($user, $shop, 'marketplace.shop_updated', ['fields' => $changed]);
            }

            return $shop;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function updateContact(User $user, string $shopId, array $data): MarketplaceShop
    {
        return DB::transaction(function () use ($user, $shopId, $data) {
            $shop = $this->memberShop($user, $shopId, lock: true);
            $this->authorize($user, 'manageContact', $shop);
            $this->assertWritable($shop);
            $shop->fill(array_intersect_key($data, array_flip(self::CONTACT)));

            $preferred = $shop->preferred_contact_method;
            $channel = ['phone' => 'contact_phone', 'whatsapp' => 'contact_whatsapp', 'email' => 'contact_email'][$preferred] ?? null;
            if ($channel !== null && ! $shop->{$channel}) {
                throw ValidationException::withMessages(['preferred_contact_method' => 'Configure this contact channel before choosing it as the preferred one.']);
            }
            // Field names only: the values are private and never copied into the audit trail.
            $changed = array_keys($shop->getDirty());
            if ($changed !== []) {
                $shop->save();
                $this->audit($user, $shop, 'marketplace.shop_contact_updated', ['fields' => $changed]);
            }

            return $shop;
        });
    }

    /** draft | rejected -> pending_review, once the profile is complete. */
    public function submit(User $user, string $shopId): MarketplaceShop
    {
        return $this->transition($user, $shopId, 'submit', [ShopStatus::Draft, ShopStatus::Rejected], function (MarketplaceShop $shop) {
            if ($missing = $this->missingForReview($shop)) {
                throw new ApiHttpException(422, 'shop_incomplete', 'Complete the shop profile before submitting it for review.', details: ['missing' => $missing]);
            }
            $shop->forceFill(['status' => ShopStatus::PendingReview, 'status_reason' => null, 'submitted_at' => now()]);
        }, 'marketplace.shop_submitted');
    }

    /** active -> closed: the seller withdraws the shop from the public. */
    public function close(User $user, string $shopId): MarketplaceShop
    {
        return $this->transition($user, $shopId, 'close', [ShopStatus::Active], fn (MarketplaceShop $shop) => $shop->forceFill(['status' => ShopStatus::Closed, 'closed_at' => now()]), 'marketplace.shop_closed');
    }

    /** closed -> active again. No new review: the shop was approved before (a never-approved shop cannot be closed, so it cannot reach here). */
    public function reopen(User $user, string $shopId): MarketplaceShop
    {
        return $this->transition($user, $shopId, 'reopen', [ShopStatus::Closed], function (MarketplaceShop $shop) {
            if ($shop->approved_at === null) {
                throw new ApiHttpException(409, 'invalid_shop_state', 'This shop has never been approved.');
            }
            $shop->forceFill(['status' => ShopStatus::Active, 'closed_at' => null]);
        }, 'marketplace.shop_reopened');
    }

    /** Ask for the verified badge. Only an approved, active shop may ask. */
    public function requestVerification(User $user, string $shopId): MarketplaceShop
    {
        return DB::transaction(function () use ($user, $shopId) {
            $shop = $this->memberShop($user, $shopId, lock: true);
            $this->authorize($user, 'manageLifecycle', $shop);
            $this->assertWritable($shop);
            if ($shop->status !== ShopStatus::Active) {
                throw new ApiHttpException(409, 'invalid_shop_state', 'Only an active shop can request verification.');
            }
            if (! in_array($shop->verification_status, [ShopVerificationStatus::Unverified, ShopVerificationStatus::Rejected], true)) {
                throw new ApiHttpException(409, 'verification_not_requestable', 'Verification is already pending or granted.');
            }
            $shop->forceFill(['verification_status' => ShopVerificationStatus::Pending, 'verification_reason' => null, 'verification_requested_at' => now()])->save();
            $this->audit($user, $shop, 'marketplace.verification_requested');

            return $shop;
        });
    }

    // ------------------------------------------------------------------ members

    /** @return Collection<int, MarketplaceShopMember> */
    public function members(User $user, string $shopId): Collection
    {
        $shop = $this->memberShop($user, $shopId);
        $this->authorize($user, 'manageMembers', $shop);

        return $shop->members()->with('user:id,name,email')->orderBy('created_at')->orderBy('id')->get();
    }

    /** Adds an EXISTING, verified, active account. Every other outcome answers identically so account existence is not revealed. */
    public function addMember(User $user, string $shopId, string $email, ShopRole $role): MarketplaceShopMember
    {
        return DB::transaction(function () use ($user, $shopId, $email, $role) {
            $shop = $this->memberShop($user, $shopId, lock: true);
            $this->authorize($user, 'manageMembers', $shop);
            $this->assertWritable($shop);
            $target = User::where('email', $email)->first();
            if ($target === null || ! $target->hasVerifiedEmail() || $target->isSuspended()) {
                throw ValidationException::withMessages(['email' => 'This person cannot be added to the shop.']);
            }
            if ($shop->members()->where('user_id', $target->id)->exists()) {
                throw new ApiHttpException(409, 'already_member', 'This person is already a member of the shop.');
            }
            $member = MarketplaceShopMember::create(['shop_id' => $shop->id, 'user_id' => $target->id, 'role' => $role, 'added_by' => $user->id]);
            $this->audit($user, $shop, 'marketplace.member_added', ['member_id' => $member->id, 'role' => $role->value]);

            return $member->setRelation('user', $target);
        });
    }

    public function changeMemberRole(User $user, string $shopId, string $memberId, ShopRole $role): MarketplaceShopMember
    {
        return DB::transaction(function () use ($user, $shopId, $memberId, $role) {
            $shop = $this->memberShop($user, $shopId, lock: true);
            $this->authorize($user, 'manageMembers', $shop);
            $this->assertWritable($shop);
            $member = $this->target($shop, $memberId);
            $from = $member->role;
            if ($from !== $role) {
                $member->update(['role' => $role]);
                $this->audit($user, $shop, 'marketplace.member_role_changed', ['member_id' => $member->id, 'from' => $from->value, 'to' => $role->value]);
            }

            return $member->load('user:id,name,email');
        });
    }

    public function removeMember(User $user, string $shopId, string $memberId): void
    {
        DB::transaction(function () use ($user, $shopId, $memberId) {
            $shop = $this->memberShop($user, $shopId, lock: true);
            $this->authorize($user, 'manageMembers', $shop);
            $this->assertWritable($shop);
            $member = $this->target($shop, $memberId);
            $member->delete();
            $this->audit($user, $shop, 'marketplace.member_removed', ['member_id' => $memberId, 'role' => $member->role->value]);
        });
    }

    // ------------------------------------------------------------------ internals

    private function target(MarketplaceShop $shop, string $memberId): MarketplaceShopMember
    {
        $member = $shop->members()->whereKey($memberId)->firstOrFail();
        if ($member->role === ShopRole::Owner) {
            throw new ApiHttpException(409, 'owner_protected', 'The shop owner cannot be changed or removed.');
        }

        return $member;
    }

    /** @param  list<ShopStatus>  $from */
    private function transition(User $user, string $shopId, string $verb, array $from, callable $apply, string $action): MarketplaceShop
    {
        return DB::transaction(function () use ($user, $shopId, $from, $apply, $action) {
            $shop = $this->memberShop($user, $shopId, lock: true);
            $this->authorize($user, 'manageLifecycle', $shop);
            if ($shop->status === ShopStatus::Suspended) {
                $this->assertWritable($shop);
            }
            if (! in_array($shop->status, $from, true)) {
                throw new ApiHttpException(409, 'invalid_shop_state', "This action is not available while the shop is {$shop->status->value}.", details: ['status' => $shop->status->value]);
            }
            $before = $shop->status->value;
            $apply($shop);
            $shop->save();
            $this->audit($user, $shop, $action, ['from' => $before, 'to' => $shop->status->value]);

            return $shop;
        });
    }

    /** @return list<string> profile fields still missing before a review can start */
    private function missingForReview(MarketplaceShop $shop): array
    {
        $missing = [];
        if (mb_strlen(trim((string) $shop->description)) < 20) {
            $missing[] = 'description';
        }
        foreach (['state', 'city'] as $field) {
            if (! $shop->{$field}) {
                $missing[] = $field;
            }
        }
        if (empty($shop->categories)) {
            $missing[] = 'categories';
        }
        if (! $shop->contact_phone && ! $shop->contact_whatsapp && ! $shop->contact_email) {
            $missing[] = 'contact';
        }

        return $missing;
    }

    private function authorizeFarmLink(User $user, string $farmId): void
    {
        $membership = FarmMembership::where('farm_id', $farmId)->where('user_id', $user->id)->where('status', MembershipStatus::Active->value)->first();
        // Not a member and no such farm answer identically: the client-supplied farm_id is never trusted and its existence is not revealed.
        if ($membership === null) {
            throw ValidationException::withMessages(['farm_id' => 'You cannot create a shop for this farm.']);
        }
        if (! $membership->can(Permission::MarketplaceManage)) {
            throw new AuthorizationException;
        }
    }

    private function authorize(User $user, string $ability, MarketplaceShop $shop): void
    {
        if (! $user->can($ability, $shop)) {
            throw new AuthorizationException;
        }
    }

    private function assertWritable(MarketplaceShop $shop): void
    {
        if ($shop->status === ShopStatus::Suspended) {
            throw new ApiHttpException(409, 'shop_suspended', 'This shop is suspended; contact support.');
        }
    }

    private function assertNameFree(string $ownerId, string $name, ?string $ignoreShopId = null): void
    {
        $taken = MarketplaceShop::where('created_by', $ownerId)->where('name', $name)->when($ignoreShopId, fn ($q) => $q->whereKeyNot($ignoreShopId))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['name' => 'You already have a shop with this name.']);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function profile(array $data): array
    {
        return array_intersect_key($data, array_flip(self::PROFILE));
    }

    private function nextReference(): string
    {
        $year = now()->format('Y');
        $last = MarketplaceShop::where('reference', 'like', "SHP-$year-%")->orderByDesc('reference')->value('reference');
        $next = $last ? ((int) substr($last, -5)) + 1 : 1;

        return "SHP-$year-".str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::limit(Str::slug($name) ?: 'shop', 90, '');
        $slug = $base;
        for ($i = 2; MarketplaceShop::where('slug', $slug)->exists(); $i++) {
            $slug = "$base-$i";
        }

        return $slug;
    }

    /** @param  array<string, mixed>  $changes */
    private function audit(User $user, MarketplaceShop $shop, string $action, array $changes = []): void
    {
        // Safe facts only (field names, states, roles) - never contact values.
        $this->audit->record($shop->farm_id, $user->id, $action, 'marketplace_shop', $shop->id, $shop->name, $changes === [] ? null : $changes);
    }
}
