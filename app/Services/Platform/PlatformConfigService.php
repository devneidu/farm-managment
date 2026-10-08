<?php

namespace App\Services\Platform;

use App\Models\FeatureFlag;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Support\Api\ApiHttpException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Platform settings and feature flags. Settings are a closed registry (SETTINGS): an unknown key or an invalid value is rejected, so a
 * setting can never become a free-form behaviour switch. Flags are switchable but never deleted. The rest of the application reads
 * them through setting()/flag(); nothing here is a farm setting.
 */
class PlatformConfigService
{
    /** key => [validation rules for the value, description] */
    public const SETTINGS = [
        'support_email' => [['nullable', 'email:rfc', 'max:150'], 'Address shown to farmers who need help.'],
        'support_whatsapp' => [['nullable', 'string', 'regex:/^\+?[0-9]{7,15}$/'], 'WhatsApp number (digits, optional leading +) for support.'],
        'marketplace_max_shops_per_user' => [['nullable', 'integer', 'min:1', 'max:20'], 'How many seller shops one user may own (null = the built-in default of 3).'],
        'marketplace_max_offers_per_buyer' => [['nullable', 'integer', 'min:1', 'max:10'], 'How many offers one buyer may make on one negotiable listing, voided offers excluded (null = the built-in default of 3).'],
        'marketplace_offer_expiry_hours' => [['nullable', 'integer', 'min:1', 'max:720'], 'How long a pending offer stays open before it expires (null = the built-in default of 48 hours).'],
        'marketplace_min_offer_percent' => [['nullable', 'numeric', 'min:1', 'max:99', 'regex:/^\d{1,2}(\.\d{1,2})?$/'], 'Lowest offer allowed, as a percentage of the listed UNIT price (1-99, up to 2 decimals; null = the built-in default of 70).'],
        'marketplace_deal_confirmation_hours' => [['nullable', 'integer', 'min:1', 'max:720'], 'How long the buyer has to confirm a deal after the seller accepts an offer or confirms a purchase request (null = the built-in default of 72 hours).'],
        'marketplace_max_promoted_per_page' => [['nullable', 'integer', 'min:0', 'max:10'], 'How many promoted listings may lead the FIRST page of marketplace discovery (0 = no priority placement; null = the built-in default of 3). Organic listings are never hidden.'],
        'announcement' => [['nullable', 'string', 'max:500'], 'Short platform-wide notice for farmers; null clears it.'],
    ];

    public function __construct(private PlatformAudit $audit) {}

    public function setting(string $key): mixed
    {
        return PlatformSetting::where('key', $key)->first()?->value['value'] ?? null;
    }

    public function flag(string $key): bool
    {
        return FeatureFlag::where('key', $key)->value('enabled') === true;
    }

    /** @return list<array{key: string, description: string, value: mixed, updated_at: string|null}> every registry key, set or not */
    public function settings(): array
    {
        $rows = PlatformSetting::all()->keyBy('key');

        return collect(self::SETTINGS)->map(fn ($def, $key) => [
            'key' => $key, 'description' => $def[1], 'value' => $rows->get($key)?->value['value'] ?? null, 'updated_at' => $rows->get($key)?->updated_at?->toIso8601String(),
        ])->values()->all();
    }

    public function putSetting(User $actor, string $key, mixed $value): array
    {
        if (! isset(self::SETTINGS[$key])) {
            throw new ApiHttpException(404, 'unknown_setting', 'This setting does not exist.');
        }
        $validator = Validator::make(['value' => $value], ['value' => self::SETTINGS[$key][0]]);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
        $value = $validator->validated()['value'];

        DB::transaction(function () use ($actor, $key, $value) {
            $row = PlatformSetting::where('key', $key)->lockForUpdate()->first();
            $before = ['value' => $row?->value['value'] ?? null];
            $row ??= new PlatformSetting(['key' => $key]);
            $row->fill(['value' => ['value' => $value], 'updated_by' => $actor->id])->save();
            $this->audit->record($actor, 'platform.setting_updated', 'platform_setting', $row->id, $key, $before, ['value' => $value]);
        });

        return collect($this->settings())->firstWhere('key', $key);
    }

    /** @return list<FeatureFlag> */
    public function flags(): array
    {
        return FeatureFlag::orderBy('key')->get()->all();
    }

    public function createFlag(User $actor, array $data): FeatureFlag
    {
        return DB::transaction(function () use ($actor, $data) {
            $flag = FeatureFlag::create($data + ['enabled' => false, 'updated_by' => $actor->id]);
            $this->audit->record($actor, 'platform.flag_created', 'feature_flag', $flag->id, $flag->key, [], $flag->only(['description', 'enabled']));

            return $flag;
        });
    }

    public function updateFlag(User $actor, string $key, array $data): FeatureFlag
    {
        return DB::transaction(function () use ($actor, $key, $data) {
            $flag = FeatureFlag::where('key', $key)->lockForUpdate()->firstOrFail();
            $before = $flag->only(['description', 'enabled']);
            $flag->update($data + ['updated_by' => $actor->id]);
            $this->audit->record($actor, 'platform.flag_updated', 'feature_flag', $flag->id, $flag->key, $before, $flag->only(['description', 'enabled']));

            return $flag;
        });
    }
}
