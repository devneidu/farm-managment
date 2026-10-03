<?php

namespace App\Services\Notifications;

use App\Models\FarmNotification;
use App\Support\Access\FarmContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/** The notification centre: a user sees and changes only their OWN in-app notifications on the CURRENT farm. Read state lives on the notification. */
class NotificationInbox
{
    public function list(FarmContext $ctx, array $f): LengthAwarePaginator
    {
        $q = $this->mine($ctx);
        if (isset($f['unread'])) {
            filter_var($f['unread'], FILTER_VALIDATE_BOOLEAN) ? $q->whereNull('read_at') : $q->whereNotNull('read_at');
        }

        return $q->when($f['type'] ?? null, fn ($w, $v) => $w->where('type', $v))->when($f['severity'] ?? null, fn ($w, $v) => $w->where('severity', $v))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate($f['per_page'] ?? 25);
    }

    public function unreadCount(FarmContext $ctx): int
    {
        return $this->mine($ctx)->whereNull('read_at')->count();
    }

    /** Idempotent: reading an already-read notification keeps its first read time. Another user's or farm's id is a 404. */
    public function markRead(FarmContext $ctx, string $id): FarmNotification
    {
        $n = $this->mine($ctx)->findOrFail($id);
        if ($n->read_at === null) {
            $n->forceFill(['read_at' => now()])->save();
        }

        return $n;
    }

    public function markAllRead(FarmContext $ctx): int
    {
        return $this->mine($ctx)->whereNull('read_at')->update(['read_at' => now()]);
    }

    private function mine(FarmContext $ctx): Builder
    {
        return FarmNotification::inboxOf($ctx->farm->id, $ctx->membership->user_id);
    }
}
