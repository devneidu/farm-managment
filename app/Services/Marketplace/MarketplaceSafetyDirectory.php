<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceDeal;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOffer;
use App\Models\MarketplaceShop;
use App\Models\MarketplaceShopMember;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** Read models for platform oversight; reuses the existing entity directories. */
class MarketplaceSafetyDirectory
{
    public function summary(MarketplaceReportService $reports): array
    {
        $out = [];
        foreach (['shops' => MarketplaceShop::class, 'listings' => MarketplaceListing::class, 'offers' => MarketplaceOffer::class, 'deals' => MarketplaceDeal::class] as $name => $model) {
            $out[$name] = $model::query()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status')->map(fn ($n) => (int) $n)->all();
        }
        $out['sellers'] = MarketplaceShopMember::where('role', 'owner')->distinct()->count('user_id');
        $out['offers'] = MarketplaceOffer::selectRaw("CASE WHEN status = 'pending' AND expires_at <= ? THEN 'expired' ELSE status END AS effective_status, COUNT(*) AS total", [now()])->groupBy('effective_status')->pluck('total', 'effective_status')->map(fn ($n) => (int) $n)->all();
        $out['reports'] = ['content' => [], 'deal' => [], 'total' => array_fill_keys(MarketplaceReportService::STATES, 0)];
        foreach (['content', 'deal'] as $type) {
            $counts = $reports->query($type)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
            foreach (MarketplaceReportService::STATES as $state) {
                $out['reports'][$type][$state] = (int) ($counts[$state] ?? 0);
                $out['reports']['total'][$state] += $out['reports'][$type][$state];
            }
        }

        return $out;
    }

    public function reports(array $f, MarketplaceReportService $reports): LengthAwarePaginator
    {

        $type = $f['type'] ?? 'content';
        $q = $reports->query($type);
        foreach (['status', 'reason'] as $key) {
            if (isset($f[$key])) {
                $q->where($key, $f[$key]);
            }
        }
        if (isset($f['q'])) {
            $q->where('reference', 'like', '%'.addcslashes($f['q'], '%_\\').'%');
        }
        if (isset($f['target_id'])) {
            $q->where($type === 'deal' ? 'deal_id' : 'target_id', $f['target_id']);
        }
        if ($type === 'content' && isset($f['target_type'])) {
            $q->where('target_type', $f['target_type']);
        }
        $page = $q->orderByDesc('created_at')->orderByDesc('id')->paginate($f['per_page'] ?? 20);

        return $page;
    }

    public function offers(array $f, MarketplaceOfferService $offers): LengthAwarePaginator
    {

        $q = MarketplaceOffer::with($offers->relations());
        foreach (['shop_id', 'listing_id'] as $key) {
            if (isset($f[$key])) {
                $q->where($key, $f[$key]);
            }
        }
        if (isset($f['q'])) {
            $q->where('reference', 'like', '%'.addcslashes($f['q'], '%_\\').'%');
        }
        $status = $f['offer_status'] ?? null;
        if ($status === 'pending') {
            $q->where('status', 'pending')->where('expires_at', '>', now());
        } elseif ($status === 'expired') {
            $q->where(fn ($w) => $w->where('status', 'expired')->orWhere(fn ($p) => $p->where('status', 'pending')->where('expires_at', '<=', now())));
        } elseif ($status) {
            $q->where('status', $status);
        }
        $page = $q->orderByDesc('created_at')->orderByDesc('id')->paginate($f['per_page'] ?? 20);

        return $page;
    }
}
