<?php

namespace App\Services\Workflow;

use App\Services\Configuration\CustomFieldEntities;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Document numbers a maintenance, service or purchase request records on a hand-off («رقم سند الصرف المخزني»,
 * «رقم سند استلام المواد», «رقم سند صرف الأجرة») are checked against the AIMS records they name, where such records
 * exist (TRANSFORM §17; Accounts requirements §3 «ربط كل حركة مخزنية بالطلب أو أمر الشراء ومحضر الاستلام»), the way
 * orphan aid deliveries are (App\Services\Orphans\Aid::assertFulfillmentRef):
 * - a goods receipt number of AIMS (GRN-…) must exist and must not already stand behind another request;
 * - an Accounts voucher number found in AIMS must be an executed payment that was not reversed, used by one request;
 * - once the Stores have issued in AIMS against the request (reference «aims_request» = its number), the stock voucher
 *   number must be one of those issue movements.
 * A number AIMS does not hold is a document of the external books and is kept as given. Nothing is written here:
 * no stock movement, receipt or accounting record is created by the request (owner decision pending).
 */
final class AimsRequestReferences
{
    /** Field code => kind of document it names. */
    private const FIELDS = [
        'stock_issue_voucher_no' => 'stock_issue',
        'materials_receipt_no' => 'goods_receipt',
        'payment_voucher_no' => 'payment_voucher',
    ];

    /**
     * @param  array<string, mixed>  $values  the step's validated field values
     */
    public function assert(object $instance, string $definitionCode, array $values): void
    {
        foreach (self::FIELDS as $code => $kind) {
            $value = isset($values[$code]) && is_string($values[$code]) ? trim($values[$code]) : '';
            if ($value === '') {
                continue;
            }
            match ($kind) {
                'stock_issue' => $this->stockIssue($instance, $code, $value),
                'goods_receipt' => $this->goodsReceipt($instance, $definitionCode, $code, $value),
                'payment_voucher' => $this->paymentVoucher($instance, $definitionCode, $code, $value),
            };
        }
    }

    private function stockIssue(object $instance, string $code, string $value): void
    {
        $issued = DB::table('finance_stock_movements')->where('kind', 'issue')
            ->where('reference_type', WorkflowEngine::SUBJECT_TYPE)->where('reference_id', (string) $instance->reference_no)
            ->pluck('id')->map(fn ($id): string => (string) $id)->all();
        if ($issued !== [] && ! in_array(ltrim($value, '#'), $issued, true)) {
            throw ValidationException::withMessages([$code => 'صُرفت مواد هذا الطلب في مخازن النظام؛ اكتب رقم حركة الصرف كما سُجّلت ('.implode('، ', $issued).').']);
        }
    }

    private function goodsReceipt(object $instance, string $definitionCode, string $code, string $value): void
    {
        if (! preg_match('/^GRN-[0-9A-F]{16}$/i', $value)) {
            return;
        }
        if (! DB::table('finance_purchase_receipts')->whereRaw('upper(number) = ?', [strtoupper($value)])->exists()) {
            throw ValidationException::withMessages([$code => 'محضر الاستلام '.$value.' غير موجود في سجل المشتريات.']);
        }
        $this->usedOnce($instance, $definitionCode, $code, $value, 'محضر الاستلام');
    }

    private function paymentVoucher(object $instance, string $definitionCode, string $code, string $value): void
    {
        $voucher = DB::table('finance_transactions')->whereRaw('upper(document_no) = ?', [strtoupper($value)])->first(['id', 'kind', 'state']);
        if (! $voucher) {
            return;
        }
        if ($voucher->kind !== 'payment' || $voucher->state !== 'executed' || DB::table('finance_transactions')->where('reversal_of_id', $voucher->id)->where('state', 'executed')->exists()) {
            throw ValidationException::withMessages([$code => 'السند '.$value.' ليس سند صرف منفذًا غير معكوس.']);
        }
        $this->usedOnce($instance, $definitionCode, $code, $value, 'السند');
    }

    /** The same AIMS document stands behind one request only (another request of the same type already cites it). */
    private function usedOnce(object $instance, string $definitionCode, string $code, string $value, string $label): void
    {
        $other = DB::table('custom_field_values as v')->join('custom_field_definitions as d', 'd.id', '=', 'v.definition_id')
            ->join('workflow_instances as i', fn ($join) => $join->on('i.subject_id', '=', 'v.entity_id')->where('i.subject_type', WorkflowEngine::SUBJECT_TYPE))
            ->where('v.entity_type', CustomFieldEntities::AIMS_REQUEST)->where('d.scope_key', $definitionCode)->where('d.code', $code)
            ->whereRaw('upper(trim(v.value_text)) = ?', [strtoupper($value)])->where('v.entity_id', '<>', $instance->subject_id)
            ->value('i.reference_no');
        if ($other !== null) {
            throw ValidationException::withMessages([$code => $label.' '.$value.' مسجّل على الطلب '.$other.'؛ لا يُستخدم لطلبين.']);
        }
    }
}
