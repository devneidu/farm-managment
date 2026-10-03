<?php

namespace App\Services\Reports\Reports;

use App\Enums\Feature;
use App\Enums\Permission;
use App\Models\Contact;
use App\Models\Purchase;
use App\Models\Sale;
use App\Services\Reports\Report;
use App\Services\Reports\ReportDefinition;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportResult;
use App\Support\Access\FarmContext;
use App\Support\Finance\Money;

/**
 * Supplier and customer history per contact for the period. The money columns follow the viewer's permissions: purchase totals need
 * purchase.view and sale totals need sale.view, so the same report shows a Finance or Manager viewer more than a contact-only viewer.
 */
class ContactHistoryReport extends Report
{
    public function definition(): ReportDefinition
    {
        return new ReportDefinition('contact_history', 'Supplier and customer history', 'contacts', 'Purchases from suppliers and sales to customers per contact in the period; cancelled purchases and sales are excluded.',
            [Permission::ContactView], ['from', 'to'], Feature::AdvancedReports, null, [Permission::PurchaseView, Permission::SaleView]);
    }

    public function run(FarmContext $ctx, ReportFilters $f): ReportResult
    {
        $purchases = $ctx->can(Permission::PurchaseView) ? $this->totals(Purchase::class, $ctx, $f) : null;
        $sales = $ctx->can(Permission::SaleView) ? $this->totals(Sale::class, $ctx, $f) : null;
        $ids = array_values(array_unique([...array_keys($purchases ?? []), ...array_keys($sales ?? [])]));
        $contacts = Contact::ofFarm($ctx->farm)->whereIn('id', $ids)->orderBy('normalized_name')->orderBy('id')->get();
        $cur = $ctx->farm->currency;

        $rows = [];
        $sum = ['purchase_total' => '0.00', 'sales_total' => '0.00'];
        foreach ($contacts as $c) {
            $row = ['contact' => $c->name, 'kind' => $c->kind instanceof \BackedEnum ? $c->kind->value : (string) $c->kind, 'phone' => $c->phone];
            if ($purchases !== null) {
                $row['purchases'] = (int) ($purchases[$c->id]->n ?? 0);
                $row['purchase_total'] = Money::add((string) ($purchases[$c->id]->total ?? '0'), '0');
                $sum['purchase_total'] = Money::add($sum['purchase_total'], $row['purchase_total']);
            }
            if ($sales !== null) {
                $row['sales'] = (int) ($sales[$c->id]->n ?? 0);
                $row['sales_total'] = Money::add((string) ($sales[$c->id]->total ?? '0'), '0');
                $sum['sales_total'] = Money::add($sum['sales_total'], $row['sales_total']);
            }
            $rows[] = $row;
        }
        $cols = [ReportResult::col('contact', 'Contact'), ReportResult::col('kind', 'Type'), ReportResult::col('phone', 'Phone')];
        $summary = ['currency' => $cur, 'contacts' => count($rows)];
        if ($purchases !== null) {
            array_push($cols, ReportResult::col('purchases', 'Purchases', 'integer'), ReportResult::col('purchase_total', "Purchased ($cur)", 'money'));
            $summary['purchase_total'] = $sum['purchase_total'];
        }
        if ($sales !== null) {
            array_push($cols, ReportResult::col('sales', 'Sales', 'integer'), ReportResult::col('sales_total', "Sold ($cur)", 'money'));
            $summary['sales_total'] = $sum['sales_total'];
        }

        return new ReportResult($cols, $rows, $summary, ['Sales without a saved contact (walk-in customers) are not attributed to a contact.']);
    }

    /** @return array<string, object> contact_id => {n, total} */
    private function totals(string $model, FarmContext $ctx, ReportFilters $f): array
    {
        return $model::where('farm_id', $ctx->farm->id)->where('status', 'active')->whereNotNull('contact_id')->where('recorded_at', '>=', $f->fromTs())->where('recorded_at', '<', $f->toTs())
            ->toBase()->selectRaw('contact_id, COUNT(*) as n, COALESCE(SUM(total_amount), 0) as total')->groupBy('contact_id')->get()->keyBy('contact_id')->all();
    }
}
