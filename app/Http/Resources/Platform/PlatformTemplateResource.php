<?php

namespace App\Http\Resources\Platform;

use App\Http\Resources\WorkTemplateResource;
use App\Models\WorkTemplate;
use App\Services\Platform\PlatformTemplateService;
use Illuminate\Http\Request;

/**
 * A platform work template with its publication `state` (draft | published | archived).
 *
 * @property WorkTemplate $resource
 */
class PlatformTemplateResource extends WorkTemplateResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return ['state' => PlatformTemplateService::state($this->resource), 'published_at' => $this->published_at?->toIso8601String()] + parent::toArray($request);
    }
}
