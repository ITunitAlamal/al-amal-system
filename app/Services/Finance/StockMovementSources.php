<?php

namespace App\Services\Finance;

use App\Models\User;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The approved source behind a stock movement entered by hand in the Stores (Accounts requirements §3 «ربط كل حركة
 * مخزنية بالطلب أو أمر الشراء ومحضر الاستلام»; TRANSFORM §10, §17). Each kind accepts only the sources listed in
 * SOURCES, and the cited record must exist and allow the movement:
 * - an issue: a «طلب صيانة» or «طلب شراء» now at a Stores stage held by the storekeeper who issues, or an assistance
 *   approved for a beneficiary, or a sponsorship in force (in-kind delivery);
 * - a return to stock: an earlier issue of the same item from the same warehouse, up to what is still out (not a
 *   campaign or transfer issue, which their own procedures return);
 * - a return to the supplier: a purchase goods receipt of the same item into the same warehouse, up to what it received;
 * - a receipt outside purchasing (in-kind donation, opening balance): the number of its receipt record («محضر استلام»),
 *   once per item. Purchases are received from their order only.
 * Movements made by their own procedures (purchase receipts, counts, transfers, campaigns) do not pass here.
 */
final class StockMovementSources
{
    /** @var array<string, array<string, string>> kind => (source type => Arabic label) */
    public const SOURCES = [
        'issue' => ['aims_request' => 'طلب صيانة أو شراء بانتظار المخزن', 'assistance_request' => 'مساعدة معتمدة لمستفيد', 'sponsorship' => 'كفالة سارية (تسليم عيني)'],
        'return_in' => ['stock_movement' => 'حركة صرف سابقة'],
        'return_out' => ['purchase_receipt' => 'محضر استلام مشتريات'],
        'receipt' => ['receipt_record' => 'محضر استلام موثق'],
    ];

    public function __construct(private readonly WorkflowEngine $engine) {}

    /**
     * Checks the source and returns the movement data with its canonical reference. Call inside the movement's
     * transaction, after locking the warehouse, so two returns cannot both pass the remaining quantity.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function assert(array $data, User $actor): array
    {
        $kind = (string) $data['kind'];
        $type = (string) ($data['reference_type'] ?? '');
        $reference = trim((string) ($data['reference_id'] ?? ''));
        $allowed = self::SOURCES[$kind] ?? [];
        if (! isset($allowed[$type]) || $reference === '') {
            throw ValidationException::withMessages(['reference_type' => 'حدد مرجع الحركة: '.($allowed === [] ? 'لا يُسجَّل هذا النوع يدويًا' : implode(' أو ', $allowed)).' مع رقمه.']);
        }
        $quantity = StockExact::scaled((string) $data['quantity'], 3);

        $data['reference_id'] = match ($type) {
            'aims_request' => $this->request($reference, $actor),
            'assistance_request' => $this->assistance($reference),
            'sponsorship' => $this->sponsorship($reference),
            'stock_movement' => $this->issued($reference, $data, $quantity),
            'purchase_receipt' => $this->received($reference, $data, $quantity),
            'receipt_record' => $this->receiptRecord($reference, $data),
        };

        return $data;
    }

    /** A generic request at a Stores stage, held now by the storekeeper who issues. */
    private function request(string $reference, User $actor): string
    {
        $instance = DB::table('workflow_instances as i')->join('workflow_steps as s', 's.id', '=', 'i.current_step_id')
            ->where('i.subject_type', WorkflowEngine::SUBJECT_TYPE)->whereRaw('upper(i.reference_no) = ?', [strtoupper($reference)])
            ->first(['i.id', 'i.reference_no', 'i.status', 'i.held_at', 's.assignment_type', 's.assigned_role_code']);
        if (! $instance) {
            throw ValidationException::withMessages(['reference_id' => 'الطلب '.$reference.' غير موجود.']);
        }
        $atStores = $instance->status === 'in_progress' && $instance->held_at === null
            && $instance->assignment_type === 'role' && $instance->assigned_role_code === 'finance_warehouse';
        if (! $atStores || ! $this->engine->isCurrentAssignee((int) $instance->id, $actor)) {
            throw ValidationException::withMessages(['reference_id' => 'يُصرف للطلب '.$instance->reference_no.' وهو في مرحلة المخزن ومسند إليك فقط.']);
        }

        return (string) $instance->reference_no;
    }

    private function assistance(string $reference): string
    {
        $row = DB::table('assistance_requests')->whereRaw('upper(reference_no) = ?', [strtoupper($reference)])->first(['reference_no', 'status']);
        if (! $row || $row->status !== 'approved') {
            throw ValidationException::withMessages(['reference_id' => 'لا توجد مساعدة معتمدة بالرقم '.$reference.'.']);
        }

        return (string) $row->reference_no;
    }

    /** An in-kind delivery under a sponsorship that is in force (Orphans «تسليم الاستفادة» with source sponsorship). */
    private function sponsorship(string $reference): string
    {
        $row = DB::table('sponsorships')->whereRaw('upper(reference_no) = ?', [strtoupper($reference)])->first(['reference_no', 'status']);
        if (! $row || ! in_array($row->status, ['active', 'partial'], true)) {
            throw ValidationException::withMessages(['reference_id' => 'لا توجد كفالة سارية بالرقم '.$reference.'.']);
        }

        return (string) $row->reference_no;
    }

    /**
     * An earlier issue of the same item from the same warehouse. Issues written by their own procedures (campaigns,
     * warehouse transfers) are returned through those procedures, which keep their own ledgers.
     *
     * @param  array<string, mixed>  $data
     */
    private function issued(string $reference, array $data, int $quantity): string
    {
        $id = ltrim($reference, '#');
        $issue = ctype_digit($id) ? DB::table('finance_stock_movements')->where('id', (int) $id)->where('kind', 'issue')
            ->where('warehouse_id', $data['warehouse_id'])->where('stock_item_id', $data['stock_item_id'])
            ->where(fn ($q) => $q->whereNull('reference_type')->orWhereNotIn('reference_type', ['campaign', 'warehouse_transfer']))
            ->first(['id', 'quantity']) : null;
        if (! $issue) {
            throw ValidationException::withMessages(['reference_id' => 'لا توجد حركة صرف بالرقم '.$reference.' للصنف نفسه من المخزن نفسه.']);
        }
        $returned = DB::table('finance_stock_movements')->where('kind', 'return_in')->where('reference_type', 'stock_movement')
            ->where('reference_id', (string) $issue->id)->pluck('quantity')->sum(fn ($q): int => StockExact::scaled((string) $q, 3));
        if ($returned + $quantity > StockExact::scaled((string) $issue->quantity, 3)) {
            throw ValidationException::withMessages(['quantity' => 'المرتجع يتجاوز ما بقي من حركة الصرف '.$issue->id.' ('.StockExact::decimal(StockExact::scaled((string) $issue->quantity, 3) - $returned, 3).').']);
        }

        return (string) $issue->id;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function received(string $reference, array $data, int $quantity): string
    {
        $receipt = DB::table('finance_purchase_receipts')->whereRaw('upper(number) = ?', [strtoupper($reference)])->first(['id', 'number']);
        if (! $receipt) {
            throw ValidationException::withMessages(['reference_id' => 'محضر الاستلام '.$reference.' غير موجود.']);
        }
        $received = DB::table('finance_purchase_receipt_items')->where('receipt_id', $receipt->id)
            ->where('warehouse_id', $data['warehouse_id'])->where('stock_item_id', $data['stock_item_id'])
            ->pluck('quantity')->sum(fn ($q): int => StockExact::scaled((string) $q, 3));
        $returned = DB::table('finance_stock_movements')->where('kind', 'return_out')->where('reference_type', 'purchase_receipt')
            ->where('reference_id', (string) $receipt->id)->where('warehouse_id', $data['warehouse_id'])->where('stock_item_id', $data['stock_item_id'])
            ->pluck('quantity')->sum(fn ($q): int => StockExact::scaled((string) $q, 3));
        if ($received === 0 || $returned + $quantity > $received) {
            throw ValidationException::withMessages(['quantity' => 'المرتجع للمورد يتجاوز ما استُلم من هذا الصنف في المحضر '.$receipt->number.'.']);
        }

        return (string) $receipt->id;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function receiptRecord(string $reference, array $data): string
    {
        if (preg_match('/^(GRN|PO|PR)-/i', $reference)) {
            throw ValidationException::withMessages(['reference_id' => 'استلام المشتريات يُسجَّل من أمر الشراء نفسه، لا من هنا.']);
        }
        if (DB::table('finance_stock_movements')->where('kind', 'receipt')->where('reference_type', 'receipt_record')
            ->where('reference_id', $reference)->where('stock_item_id', $data['stock_item_id'])->exists()) {
            throw ValidationException::withMessages(['reference_id' => 'سُجّل استلام هذا الصنف بمحضر الاستلام '.$reference.' من قبل.']);
        }

        return mb_substr($reference, 0, 140);
    }
}
