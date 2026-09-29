<?php

namespace App\Services\Farm;

use App\Models\Farm;
use App\Models\FarmMembership;
use App\Models\User;
use App\Support\Api\ApiHttpException;
use Illuminate\Support\Facades\DB;

class FarmOnboardingService
{
    /**
     * Creates the user's first farm, the owner membership and sets onboarded_at atomically.
     * The user row is locked so concurrent/repeated requests cannot create a second farm.
     */
    public function createInitialFarm(User $user, string $name): Farm
    {
        return DB::transaction(function () use ($user, $name) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($locked->isOnboarded()) {
                throw new ApiHttpException(409, 'already_onboarded', 'Farm setup has already been completed.');
            }

            $farm = Farm::create(['name' => $name]);

            FarmMembership::create([
                'farm_id' => $farm->id,
                'user_id' => $locked->id,
                'role' => FarmMembership::ROLE_OWNER,
            ]);

            $locked->forceFill(['onboarded_at' => now()])->save();
            $user->setRawAttributes($locked->getAttributes(), true);

            return $farm;
        });
    }
}
