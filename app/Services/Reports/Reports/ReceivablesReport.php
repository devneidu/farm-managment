<?php

namespace App\Services\Reports\Reports;

use App\Enums\Feature;
use App\Enums\Permission;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Reports\Report;
use App\Services\Reports\ReportDefinition;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportResult;
use App\Support\Access\FarmContext;
use App\Support\Finance\Money;
use Illuminate\Support\Facades\DB;

/** Invoices issued in the period per customer with money received (reversed payments do not count) and what is still owed. Void invoices are excluded. */
class ReceivablesReport extends Report
{
    public function definition(): ReportDefinition
    {
        return new ReportDefinition('receivables', 'Invoices, payments and receivables', 'sales', 'Invoices issued in the period per customer: invoiced, received (net of reversed payments), outstanding and overdue as of today. Void invoices are excluded.',
            [Permission::InvoiceView, Permission::PaymentView], ['from', 'to'], Feature::AdvancedReports);
    }

    public function run(FarmContext $ctx, ReportFilters $f): ReportResult
    {
        $paid = "(select COALESCE(SUM(p.amount), 0) from payments p where p.invoice_id = i.id and p.entry_type = '".Payment::PAYMENT."' and not exists (select 1 from payments rv where rv.reverses_payment_id = p.id))";
        $data = DB::select("SELECT COALESCE(i.contact_id, CONCAT('n:', LOWER(COALESCE(i.customer_name, '')))) as customer_key, MAX(COALESCE(c.name, i.customer_name)) as customer, COUNT(*) as invoices, "
            ."SUM(i.total_amount) as invoiced, SUM($paid) as received, SUM(i.total_amount - $paid) as outstanding, "
            ."SUM(CASE WHEN i.due_date IS NOT NULL AND i.due_date < ? THEN i.total_amount - $paid ELSE 0 END) as overdue "
            .'FROM invoices i LEFT JOIN contacts c ON c.id = i.contact_id WHERE i.farm_id = ? AND i.status = ? AND i.issue_date >= ? AND i.issue_date <= ? GROUP BY customer_key',
            [$f->clock->today, $ctx->farm->id, Invoice::ISSUED, $f->from, $f->to]);
        usort($data, fn ($a, $b) => [strtolower((string) $a->customer), $a->customer_key] <=> [strtolower((string) $b->customer), $b->customer_key]);

        $rows = [];
        $sum = ['invoiced' => '0.00', 'received' => '0.00', 'outstanding' => '0.00', 'overdue' => '0.00'];
        $count = 0;
        foreach ($data as $d) {
            $row = ['customer' => $d->customer ?: 'Walk-in customer', 'invoices' => (int) $d->invoices];
            foreach ($sum as $k => $_) {
                $row[$k] = Money::add((string) $d->{$k}, '0');
                $sum[$k] = Money::add($sum[$k], $row[$k]);
            }
            $count += $row['invoices'];
            $rows[] = $row;
        }
        $cur = $ctx->farm->currency;

        return new ReportResult([
            ReportResult::col('customer', 'Customer'), ReportResult::col('invoices', 'Invoices', 'integer'), ReportResult::col('invoiced', "Invoiced ($cur)", 'money'),
            ReportResult::col('received', "Received ($cur)", 'money'), ReportResult::col('outstanding', "Outstanding ($cur)", 'money'), ReportResult::col('overdue', "Overdue ($cur)", 'money'),
        ], $rows, ['currency' => $cur, 'invoices' => $count] + $sum, ['Overdue is measured against today in the farm timezone.']);
    }
}
