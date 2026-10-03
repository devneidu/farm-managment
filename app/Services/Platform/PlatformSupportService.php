<?php

namespace App\Services\Platform;

use App\Enums\Feature;
use App\Enums\Limit;
use App\Enums\MembershipStatus;
use App\Models\AuditLog;
use App\Models\Farm;
use App\Models\FarmMembership;
use App\Models\FarmOperation;
use App\Models\SubscriptionEvent;
use App\Models\User;
use App\Services\Subscription\EntitlementService;
use App\Support\Api\ApiHttpException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The cross-farm SUPPORT surface: READ MODELS over users, farms and memberships (identity, plan, team, limits and usage counts) plus the
 * reversible user suspension that already exists on the account. It deliberately exposes no farm business data - no population, stock,
 * finance, sales, health or breeding records or totals - and never password hashes, tokens, OTPs or provider ids. Every list is paginated
 * and uses aggregate subqueries / eager loads, never a per-row query.
 */
class PlatformSupportService
{
    public function __construct(private PlatformAudit $audit, private EntitlementService $entitlements) {}

    /** @param  array{q?: string, status?: string, platform_role?: string, verified?: bool, per_page?: int}  $f */
    public function users(array $f): LengthAwarePaginator
    {
        $like = isset($f['q']) ? '%'.PlatformPlanService::escapeLike($f['q']).'%' : null;

        return User::query()->with('platformAdmin')
            ->withCount(['memberships as active_farms_count' => fn ($q) => $q->where('status', MembershipStatus::Active->value)])
            ->when($like, fn (Builder $q) => $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like)))
            ->when(($f['status'] ?? null) === 'suspended', fn (Builder $q) => $q->whereNotNull('suspended_at'))
            ->when(($f['status'] ?? null) === 'active', fn (Builder $q) => $q->whereNull('suspended_at'))
            ->when(isset($f['platform_role']), fn (Builder $q) => $q->whereHas('platformAdmin', fn ($a) => $a->where('role', $f['platform_role'])))
            ->when(isset($f['verified']), fn (Builder $q) => $f['verified'] ? $q->whereNotNull('email_verified_at') : $q->whereNull('email_verified_at'))
            ->orderBy('created_at', 'desc')->orderBy('id')
            ->paginate($f['per_page'] ?? 25);
    }

    public function user(string $id): User
    {
        return User::query()->with(['platformAdmin', 'memberships.farm', 'socialAccounts'])->findOrFail($id);
    }

    public function suspend(User $actor, string $id, string $reason): User
    {
        return DB::transaction(function () use ($actor, $id, $reason) {
            $user = User::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($user->id === $actor->id) {
                throw new ApiHttpException(409, 'cannot_suspend_self', 'You cannot suspend your own account.');
            }
            if ($user->platformAdmin()->exists()) {
                throw new ApiHttpException(409, 'platform_admin_protected', 'A platform administrator cannot be suspended here; revoke the platform grant first.');
            }
            if ($user->isSuspended()) {
                throw new ApiHttpException(409, 'already_suspended', 'This account is already suspended.');
            }

            $user->forceFill(['suspended_at' => now()])->save();
            $user->tokens()->delete();   // personal access tokens end now; cookie sessions end at the next request (account.active)
            $this->audit->record($actor, 'platform.user_suspended', 'user', $user->id, $user->email, ['suspended' => false], ['suspended' => true], ['reason' => $reason]);

            return $this->user($user->id);
        });
    }

    public function restore(User $actor, string $id): User
    {
        return DB::transaction(function () use ($actor, $id) {
            $user = User::whereKey($id)->lockForUpdate()->firstOrFail();
            if (! $user->isSuspended()) {
                throw new ApiHttpException(409, 'not_suspended', 'This account is not suspended.');
            }
            $user->forceFill(['suspended_at' => null])->save();
            $this->audit->record($actor, 'platform.user_restored', 'user', $user->id, $user->email, ['suspended' => true], ['suspended' => false]);

            return $this->user($user->id);
        });
    }

    /** @param  array{q?: string, plan?: string, subscription_status?: string, sort?: string, direction?: string, per_page?: int}  $f */
    public function farms(array $f): LengthAwarePaginator
    {
        $like = isset($f['q']) ? '%'.PlatformPlanService::escapeLike($f['q']).'%' : null;
        $sort = in_array($f['sort'] ?? null, ['name', 'created_at'], true) ? $f['sort'] : 'created_at';

        return Farm::query()->with(['subscription.plan', 'ownerMembership.user'])
            ->withCount(['memberships as members_count' => fn ($q) => $q->where('status', MembershipStatus::Active->value)])
            ->when($like, fn (Builder $q) => $q->where(fn ($w) => $w->where('farms.name', 'like', $like)
                ->orWhereHas('memberships.user', fn ($u) => $u->where('email', 'like', $like)->orWhere('name', 'like', $like))))
            ->when(isset($f['plan']), fn (Builder $q) => $q->whereHas('subscription.plan', fn ($p) => $p->where('slug', $f['plan'])))
            ->when(isset($f['subscription_status']), fn (Builder $q) => $q->whereHas('subscription', fn ($s) => $s->where('status', $f['subscription_status'])))
            ->orderBy('farms.'.$sort, ($f['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc')->orderBy('farms.id')
            ->paginate($f['per_page'] ?? 25);
    }

    /** @return array<string, mixed> */
    public function farm(string $id): array
    {
        $farm = Farm::query()->with(['subscription.plan', 'ownerMembership.user'])->findOrFail($id);
        $set = $this->entitlements->for($farm);

        return [
            'farm' => $farm,
            'members' => FarmMembership::where('farm_id', $farm->id)->with('user')->orderBy('created_at')->get(),
            'operations' => FarmOperation::where('farm_id', $farm->id)->with('operationType')->get()->map(fn ($o) => $o->operationType->code)->sort()->values()->all(),
            'entitlements' => [
                'plan' => $set->plan ? ['id' => $set->plan->id, 'slug' => $set->plan->slug, 'name' => $set->plan->name] : null,
                'subscription_inactive' => $set->subscriptionInactive,
                'features' => collect(Feature::cases())->mapWithKeys(fn (Feature $f) => [$f->value => $set->allows($f)])->all(),
                'limits' => collect(Limit::cases())->mapWithKeys(function (Limit $l) use ($farm, $set) {
                    $value = $set->limit($l);

                    return [$l->value => ['limit' => $value->value, 'unlimited' => $value->unlimited, 'usage' => $this->entitlements->usage($farm, $l)]];
                })->all(),
            ],
            'subscription_events' => SubscriptionEvent::where('farm_id', $farm->id)->orderByDesc('occurred_at')->limit(10)->get(),
        ];
    }

    /** @param  array{from?: string, to?: string, actor_id?: string, action?: string, resource_type?: string, resource_id?: string, request_id?: string, per_page?: int}  $f */
    public function audit(array $f): LengthAwarePaginator
    {
        $page = AuditLog::query()->whereNull('farm_id')
            ->when(isset($f['from']), fn (Builder $q) => $q->where('created_at', '>=', CarbonImmutable::parse($f['from'], 'UTC')->startOfDay()))
            ->when(isset($f['to']), fn (Builder $q) => $q->where('created_at', '<', CarbonImmutable::parse($f['to'], 'UTC')->addDay()->startOfDay()))
            ->when(isset($f['actor_id']), fn (Builder $q) => $q->where('actor_id', $f['actor_id']))
            ->when(isset($f['action']), fn (Builder $q) => $q->where('action', $f['action']))
            ->when(isset($f['resource_type']), fn (Builder $q) => $q->where('resource_type', $f['resource_type']))
            ->when(isset($f['resource_id']), fn (Builder $q) => $q->where('resource_id', $f['resource_id']))
            ->when(isset($f['request_id']), fn (Builder $q) => $q->where('request_id', $f['request_id']))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($f['per_page'] ?? 50);

        // One lookup for every actor on the page.
        $names = User::whereIn('id', $page->getCollection()->pluck('actor_id')->filter()->unique()->all())->pluck('name', 'id');
        $page->getCollection()->each(fn ($row) => $row->setAttribute('actor_name', $names->get($row->actor_id)));

        return $page;
    }
}
