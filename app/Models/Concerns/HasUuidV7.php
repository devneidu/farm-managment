<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Str;

/**
 * Project convention: domain/public resources use a UUIDv7 primary key.
 *
 * Migrations: $table->uuid('id')->primary();
 * UUIDv7 is time-ordered, so it indexes well and never exposes sequential ids.
 * Human-facing reference codes (e.g. BAT-2026-00001) are separate columns.
 */
trait HasUuidV7
{
    use HasUuids;

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }
}
