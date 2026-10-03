<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\PutSettingRequest;
use App\Http\Requests\Platform\StoreFeatureFlagRequest;
use App\Http\Requests\Platform\UpdateFeatureFlagRequest;
use App\Models\FeatureFlag;
use App\Services\Platform\PlatformConfigService;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

/**
 * Platform settings (a closed registry of validated keys) and feature flags (switchable, never deleted).
 * Reads: any platform role. Writes: role `admin`, audited as `platform.setting_updated` / `platform.flag_*`.
 */
class PlatformConfigController extends Controller
{
    /**
     * List platform settings
     *
     * Every supported setting key with its description and current value (`null` when unset).
     *
     * @response array{data: array<int, array{key: string, description: string, value: mixed, updated_at: string|null}>, meta: object, message: string|null}
     */
    public function settings(PlatformConfigService $config): JsonResponse
    {
        return ApiResponse::success($config->settings());
    }

    /**
     * Set a platform setting
     *
     * Replaces the value of a supported key (`404 unknown_setting` for any other key; `422` when the value breaks that key's rule).
     * `null` clears it. Audited as `platform.setting_updated` with the previous and new value.
     *
     * @response array{data: array{key: string, description: string, value: mixed, updated_at: string|null}, meta: object, message: string|null}
     */
    public function putSetting(PutSettingRequest $request, string $key, PlatformConfigService $config): JsonResponse
    {
        return ApiResponse::success($config->putSetting($request->user(), $key, $request->input('value')));
    }

    /**
     * List feature flags
     *
     * @response array{data: array<int, array{key: string, description: string|null, enabled: bool, updated_at: string|null}>, meta: object, message: string|null}
     */
    public function flags(PlatformConfigService $config): JsonResponse
    {
        return ApiResponse::success(array_map($this->flag(...), $config->flags()));
    }

    /**
     * Create a feature flag
     *
     * Created switched OFF. `key` is permanent. Audited as `platform.flag_created`.
     */
    #[Response(status: 201, type: 'array{data: array{key: string, description: string|null, enabled: bool, updated_at: string|null}, meta: object, message: string|null}')]
    public function storeFlag(StoreFeatureFlagRequest $request, PlatformConfigService $config): JsonResponse
    {
        return ApiResponse::success($this->flag($config->createFlag($request->user(), $request->validated())), status: 201);
    }

    /**
     * Update a feature flag
     *
     * Switch on/off or change the description. Audited as `platform.flag_updated` with before/after. Flags cannot be deleted.
     *
     * @response array{data: array{key: string, description: string|null, enabled: bool, updated_at: string|null}, meta: object, message: string|null}
     */
    public function updateFlag(UpdateFeatureFlagRequest $request, string $key, PlatformConfigService $config): JsonResponse
    {
        return ApiResponse::success($this->flag($config->updateFlag($request->user(), $key, $request->validated())));
    }

    /** @return array{key: string, description: string|null, enabled: bool, updated_at: string|null} */
    private function flag(FeatureFlag $f): array
    {
        return ['key' => $f->key, 'description' => $f->description, 'enabled' => $f->enabled, 'updated_at' => $f->updated_at?->toIso8601String()];
    }
}
