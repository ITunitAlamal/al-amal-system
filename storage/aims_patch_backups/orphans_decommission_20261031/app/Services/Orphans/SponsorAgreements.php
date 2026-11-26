<?php

namespace App\Services\Orphans;

use App\Models\OrphanChild;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Finance\FinanceMoney;
use App\Services\Notifications\NotificationCenter;
use App\Services\Workflow\WorkflowEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * «اتفاقيات الكفالة وقوالبها» (requirements Q35 and Q11).
 *
 * - A template's wording is drafted in the department and activated by the executive director; once active it is
 *   frozen (a change is a new version).
 * - An agreement belongs to one sponsor and groups one or more sponsorship lines: individual (one child), group
 *   (several children), family (households) or fund (a pooled fund's sponsorships). Every line keeps its own
 *   approval, dues and Accounts' records; the agreement never carries money.
 * - Signing is recorded with the date and a snapshot of the text as signed. Before the end date the head and the
 *   sponsorships staff are alerted to renew; nothing ends automatically.
 */
final class SponsorAgreements
{
    public const KINDS = ['individual' => 'كفالة فردية', 'group' => 'كفالة جماعية (عدة أيتام)', 'family' => 'كفالة أسرية', 'fund' => 'صندوق كفالة مجمع'];

    public const STATUS_LABELS = ['draft' => 'مسودة', 'signed' => 'موقعة وسارية', 'renewed' => 'جُددت باتفاقية لاحقة', 'ended' => 'منتهية', 'cancelled' => 'ملغاة'];

    public const TEMPLATE_STATUS = ['draft' => 'مسودة', 'active' => 'معتمد', 'retired' => 'متقاعد'];

    /** Placeholders a template may use; filled when the agreement is previewed and frozen when it is signed. */
    public const PLACEHOLDERS = [
        '{agreement_no}' => 'رقم الاتفاقية', '{sponsor_name}' => 'اسم الكافل', '{donor_no}' => 'رقم الكافل', '{fund_name}' => 'اسم الصندوق',
        '{starts_on}' => 'تاريخ البداية', '{ends_on}' => 'تاريخ النهاية', '{beneficiaries}' => 'قائمة المستفيدين', '{commitments}' => 'التعهدات (المبلغ والدورية)',
        '{signed_on}' => 'تاريخ التوقيع', '{today}' => 'تاريخ اليوم',
    ];

    /** Lines that still belong to the agreement's text. */
    private const LIVE_LINES = ['draft', 'pending_approval', 'active', 'paused'];

    public function __construct(
        private readonly OrphanAccess $access,
        private readonly OrphanRegistry $registry,
        private readonly Sponsorships $sponsorships,
        private readonly NotificationCenter $notifications,
        private readonly AuditService $audit,
    ) {}

    public function available(): bool
    {
        return DB::getSchemaBuilder()->hasTable('sponsorship_agreements');
    }

    // ── Templates ────────────────────────────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $input */
    public function saveTemplate(User $actor, array $input, ?int $templateId = null): int
    {
        $this->access->assert($actor, 'sponsorships');
        $data = Validator::make($input, [
            'code' => ['required', 'string', 'regex:/^[a-z0-9_]{3,40}$/'], 'title' => ['required', 'string', 'min:3', 'max:200'],
            'kind' => ['required', Rule::in(array_keys(self::KINDS))], 'body' => ['required', 'string', 'min:20', 'max:20000'], 'notes' => ['nullable', 'string', 'max:1000'],
        ], ['code.regex' => 'رمز القالب أحرف إنجليزية صغيرة وأرقام وشرطة سفلية (3–40).'], ['code' => 'رمز القالب', 'title' => 'عنوان القالب', 'kind' => 'نوع الاتفاقية', 'body' => 'نص القالب'])->validate();

        return DB::transaction(function () use ($actor, $data, $templateId): int {
            if ($templateId) {
                $row = DB::table('sponsorship_agreement_templates')->where('id', $templateId)->lockForUpdate()->first();
                abort_unless($row, 404);
                if ($row->status !== 'draft') {
                    throw ValidationException::withMessages(['body' => 'القالب المعتمد لا يُعدّل؛ أنشئ إصدارًا جديدًا منه.']);
                }
                if ($row->code !== $data['code'] && (int) $row->version > 1) {
                    throw ValidationException::withMessages(['code' => 'رمز القالب ثابت بين إصداراته.']);
                }
                DB::table('sponsorship_agreement_templates')->where('id', $templateId)->update(['code' => $data['code'], 'title' => trim($data['title']), 'kind' => $data['kind'],
                    'body' => $data['body'], 'notes' => $data['notes'] ?? null, 'updated_by' => $actor->id, 'updated_at' => now()]);
                $this->audit->recordRow('sponsorships.template.updated', 'sponsorship_agreement_templates', $templateId, null, ['code' => $data['code'], 'version' => (int) $row->version], $actor->id, request()?->ip());

                return $templateId;
            }
            $version = (int) DB::table('sponsorship_agreement_templates')->where('code', $data['code'])->max('version') + 1;
            if ($version > 1) {
                throw ValidationException::withMessages(['code' => 'يوجد قالب بهذا الرمز؛ أنشئ منه إصدارًا جديدًا بدل قالب مكرر.']);
            }
            $id = DB::table('sponsorship_agreement_templates')->insertGetId(['public_id' => (string) Str::ulid(), 'code' => $data['code'], 'version' => 1, 'title' => trim($data['title']),
                'kind' => $data['kind'], 'body' => $data['body'], 'notes' => $data['notes'] ?? null, 'status' => 'draft', 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->recordRow('sponsorships.template.created', 'sponsorship_agreement_templates', $id, null, ['code' => $data['code']], $actor->id, request()?->ip());

            return $id;
        });
    }

    /** A new draft version of an active or retired template, copied from it. */
    public function newTemplateVersion(User $actor, int $templateId): int
    {
        $this->access->assert($actor, 'sponsorships');

        return DB::transaction(function () use ($actor, $templateId): int {
            $row = DB::table('sponsorship_agreement_templates')->where('id', $templateId)->first();
            abort_unless($row, 404);
            if (DB::table('sponsorship_agreement_templates')->where('code', $row->code)->where('status', 'draft')->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['template' => 'يوجد إصدار مسودة مفتوح لهذا القالب.']);
            }
            $version = (int) DB::table('sponsorship_agreement_templates')->where('code', $row->code)->max('version') + 1;
            $id = DB::table('sponsorship_agreement_templates')->insertGetId(['public_id' => (string) Str::ulid(), 'code' => $row->code, 'version' => $version, 'title' => $row->title,
                'kind' => $row->kind, 'body' => $row->body, 'notes' => null, 'status' => 'draft', 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->recordRow('sponsorships.template.versioned', 'sponsorship_agreement_templates', $id, null, ['code' => $row->code, 'version' => $version], $actor->id, request()?->ip());

            return $id;
        });
    }

    /** The executive director approves a template's wording; the earlier active version of the same code retires. */
    public function activateTemplate(User $actor, int $templateId): void
    {
        abort_unless($this->access->isExecutive($actor), 403, 'اعتماد نص قالب الاتفاقية للمدير التنفيذي.');
        DB::transaction(function () use ($actor, $templateId): void {
            $row = DB::table('sponsorship_agreement_templates')->where('id', $templateId)->lockForUpdate()->first();
            abort_unless($row, 404);
            if ($row->status !== 'draft') {
                throw ValidationException::withMessages(['template' => 'يُعتمد القالب وهو مسودة فقط.']);
            }
            $unknown = array_diff($this->placeholdersIn($row->body), array_keys(self::PLACEHOLDERS));
            if ($unknown !== []) {
                throw ValidationException::withMessages(['template' => 'في النص حقول غير معروفة: '.implode('، ', $unknown)]);
            }
            DB::table('sponsorship_agreement_templates')->where('code', $row->code)->where('status', 'active')->update(['status' => 'retired', 'updated_at' => now()]);
            DB::table('sponsorship_agreement_templates')->where('id', $templateId)->update(['status' => 'active', 'activated_by' => $actor->id, 'activated_at' => now(), 'updated_at' => now()]);
            $this->audit->recordRow('sponsorships.template.activated', 'sponsorship_agreement_templates', $templateId, ['status' => 'draft'], ['status' => 'active', 'code' => $row->code,
                'version' => (int) $row->version], $actor->id, request()?->ip());
        });
    }

    /** The executive director withdraws an active template; agreements already signed keep their text. */
    public function retireTemplate(User $actor, int $templateId): void
    {
        abort_unless($this->access->isExecutive($actor), 403, 'إيقاف قالب معتمد للمدير التنفيذي.');
        DB::transaction(function () use ($actor, $templateId): void {
            $row = DB::table('sponsorship_agreement_templates')->where('id', $templateId)->lockForUpdate()->first();
            abort_unless($row && $row->status === 'active', 422, 'يُوقف القالب المعتمد فقط.');
            DB::table('sponsorship_agreement_templates')->where('id', $templateId)->update(['status' => 'retired', 'updated_at' => now()]);
            $this->audit->recordRow('sponsorships.template.retired', 'sponsorship_agreement_templates', $templateId, ['status' => 'active'], ['status' => 'retired'], $actor->id, request()?->ip());
        });
    }

    /** @return list<array<string, mixed>> */
    public function templates(): array
    {
        if (! $this->available()) {
            return [];
        }

        return DB::table('sponsorship_agreement_templates as t')->leftJoin('users as a', 'a.id', '=', 't.activated_by')->orderBy('t.code')->orderByDesc('t.version')
            ->get(['t.id', 't.code', 't.version', 't.title', 't.kind', 't.body', 't.status', 't.notes', 't.activated_at', 'a.name as activated_by'])
            ->map(fn (object $row): array => (array) $row + ['kind_label' => self::KINDS[$row->kind] ?? $row->kind, 'status_label' => self::TEMPLATE_STATUS[$row->status] ?? $row->status])->all();
    }

    // ── Agreements ───────────────────────────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $input */
    public function create(User $actor, array $input): string
    {
        $this->access->assert($actor, 'sponsorships');
        $data = $this->validateAgreement($input);
        $sponsorId = $data['kind'] === 'fund'
            ? (int) DB::table('sponsorship_funds')->where('id', $data['fund_id'])->value('sponsor_id')
            : (int) $data['sponsor_id'];

        return DB::transaction(function () use ($actor, $data, $sponsorId): string {
            $publicId = (string) Str::ulid();
            $id = DB::table('sponsorship_agreements')->insertGetId(['public_id' => $publicId, 'agreement_no' => $this->registry->number('sponsorship_agreement_ref_seq', 'AGR-'),
                'sponsor_id' => $sponsorId, 'kind' => $data['kind'], 'fund_id' => $data['kind'] === 'fund' ? (int) $data['fund_id'] : null, 'template_id' => $data['template_id'] ?? null,
                'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'] ?? null, 'renewal_alert_days' => (int) ($data['renewal_alert_days'] ?? 30),
                'notes' => $data['notes'] ?? null, 'status' => 'draft', 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->recordRow('sponsorships.agreement.created', 'sponsorship_agreements', $id, null, ['kind' => $data['kind'], 'sponsor' => $sponsorId], $actor->id, request()?->ip());

            return $publicId;
        });
    }

    /** @param array<string, mixed> $input */
    public function update(User $actor, object $agreement, array $input): void
    {
        $this->access->assert($actor, 'sponsorships');
        if ($agreement->status !== 'draft') {
            throw ValidationException::withMessages(['agreement' => 'تُعدّل الاتفاقية قبل تسجيل توقيعها فقط.']);
        }
        $data = Validator::make($input, [
            'template_id' => ['nullable', 'integer', Rule::exists('sponsorship_agreement_templates', 'id')->where('status', 'active')->where('kind', $agreement->kind)],
            'starts_on' => ['required', 'date_format:Y-m-d'], 'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'renewal_alert_days' => ['required', 'integer', 'between:1,180'], 'notes' => ['nullable', 'string', 'max:2000'],
        ], ['template_id.exists' => 'اختر قالبًا معتمدًا من نوع الاتفاقية نفسه.'], ['starts_on' => 'تاريخ البداية', 'ends_on' => 'تاريخ النهاية', 'renewal_alert_days' => 'التنبيه قبل الانتهاء'])->validate();
        DB::transaction(function () use ($actor, $agreement, $data): void {
            DB::table('sponsorship_agreements')->where('id', $agreement->id)->lockForUpdate()->first();
            DB::table('sponsorship_agreements')->where('id', $agreement->id)->update($data + ['updated_at' => now()]);
            $this->audit->recordRow('sponsorships.agreement.updated', 'sponsorship_agreements', (int) $agreement->id, null, ['fields' => array_keys($data)], $actor->id, request()?->ip());
        });
    }

    /**
     * Creates the agreement's sponsorship lines as drafts in one step (all or none), each with the same terms:
     * one line per beneficiary file number. Each line is then approved like any sponsorship.
     *
     * @param  array<string, mixed>  $input
     * @return int lines created
     */
    public function addLines(User $actor, object $agreement, array $input): int
    {
        $this->access->assert($actor, 'sponsorships');
        if (! in_array($agreement->status, ['draft', 'signed'], true)) {
            throw ValidationException::withMessages(['references' => 'تُضاف المستفيدون لاتفاقية مسودة أو سارية فقط.']);
        }
        $references = collect(preg_split('/[\s,،]+/u', (string) ($input['references'] ?? ''), -1, PREG_SPLIT_NO_EMPTY))->map(fn ($r) => trim($r))->unique()->values();
        if ($references->isEmpty() || $references->count() > 100) {
            throw ValidationException::withMessages(['references' => 'أدخل رقم ملف مستفيد واحد على الأقل (حتى 100).']);
        }
        $type = match ($agreement->kind) {
            'family' => 'household',
            'fund' => in_array($input['beneficiary_type'] ?? 'child', ['child', 'household'], true) ? ($input['beneficiary_type'] ?? 'child') : 'child',
            default => 'child',
        };
        $live = DB::table('sponsorships')->where('agreement_id', $agreement->id)->whereIn('status', self::LIVE_LINES)->count();
        if ($agreement->kind === 'individual' && $live + $references->count() > 1) {
            throw ValidationException::withMessages(['references' => 'الاتفاقية الفردية لمستفيد واحد؛ استخدم اتفاقية جماعية لعدة أيتام.']);
        }

        return DB::transaction(function () use ($actor, $agreement, $input, $references, $type): int {
            DB::table('sponsorship_agreements')->where('id', $agreement->id)->lockForUpdate()->first();
            foreach ($references as $index => $reference) {
                try {
                    $id = $this->sponsorships->save($actor, ['sponsor_id' => $agreement->sponsor_id, 'beneficiary_type' => $type, 'beneficiary_reference' => $reference,
                        'agreement_id' => $agreement->id, 'starts_on' => $input['starts_on'] ?? (string) $agreement->starts_on, 'ends_on' => $input['ends_on'] ?? $agreement->ends_on]
                        + array_intersect_key($input, array_flip(['type_value_id', 'amount', 'currency', 'frequency_value_id', 'purpose_value_id', 'savings_amount', 'conditions', 'source'])));
                } catch (ValidationException $e) {
                    throw ValidationException::withMessages(['references' => 'المستفيد '.$reference.': '.collect($e->errors())->flatten()->first()]);
                }
            }
            $this->audit->recordRow('sponsorships.agreement.lines_added', 'sponsorship_agreements', (int) $agreement->id, null, ['lines' => $references->count()], $actor->id, request()?->ip());

            return $references->count();
        });
    }

    /** Submits every unsubmitted draft line of the agreement that the actor may submit; each goes to the head then the executive director. */
    public function submitLines(User $actor, object $agreement, WorkflowEngine $engine): int
    {
        $this->access->assert($actor, 'sponsorships');
        $count = 0;
        foreach (DB::table('sponsorships')->where('agreement_id', $agreement->id)->where('status', 'draft')->whereNull('workflow_instance_id')->orderBy('id')->get() as $line) {
            if ($this->sponsorships->mayEditDraft($actor, $line)) {
                $this->sponsorships->submit($actor, (int) $line->id, $engine);
                $count++;
            }
        }
        if ($count === 0) {
            throw ValidationException::withMessages(['agreement' => 'لا توجد مسودات لك لم تُرفع في هذه الاتفاقية.']);
        }

        return $count;
    }

    /**
     * Records that the sponsor signed: the text as signed is kept with its hash. At least one line must be approved.
     * Signing a renewal marks the earlier agreement «renewed» and moves its running lines to the new one.
     */
    public function recordSigning(User $actor, object $agreement, string $signedOn): void
    {
        $this->access->assert($actor, 'sponsorships');
        Validator::make(['signed_on' => $signedOn], ['signed_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$this->today()]], [], ['signed_on' => 'تاريخ التوقيع'])->validate();
        DB::transaction(function () use ($actor, $agreement, $signedOn): void {
            $row = DB::table('sponsorship_agreements')->where('id', $agreement->id)->lockForUpdate()->first();
            if ($row->status !== 'draft') {
                throw ValidationException::withMessages(['agreement' => 'سُجل توقيع هذه الاتفاقية أو أُغلقت.']);
            }
            if (! $row->template_id) {
                throw ValidationException::withMessages(['agreement' => 'اختر قالبًا معتمدًا قبل تسجيل التوقيع.']);
            }
            if (! $row->renews_id && ! DB::table('sponsorships')->where('agreement_id', $row->id)->whereIn('status', ['active', 'paused'])->exists()) {
                throw ValidationException::withMessages(['agreement' => 'يُسجل التوقيع بعد اعتماد كفالة واحدة على الأقل في الاتفاقية.']);
            }
            if ($row->renews_id) {
                $old = DB::table('sponsorship_agreements')->where('id', $row->renews_id)->lockForUpdate()->first();
                DB::table('sponsorships')->where('agreement_id', $old->id)->whereIn('status', self::LIVE_LINES)->update(['agreement_id' => $row->id, 'updated_at' => now()]);
                DB::table('sponsorship_agreements')->where('id', $old->id)->update(['status' => 'renewed', 'status_reason' => 'جُددت بالاتفاقية '.$row->agreement_no, 'updated_at' => now()]);
                $this->audit->recordRow('sponsorships.agreement.renewed', 'sponsorship_agreements', (int) $old->id, ['status' => $old->status], ['status' => 'renewed', 'by' => $row->agreement_no], $actor->id, request()?->ip());
            }
            $text = $this->render($row, $signedOn);
            DB::table('sponsorship_agreements')->where('id', $row->id)->update(['status' => 'signed', 'signed_on' => $signedOn, 'signed_recorded_by' => $actor->id,
                'rendered_body' => $text, 'rendered_hash' => hash('sha256', $text), 'updated_at' => now()]);
            $this->audit->recordRow('sponsorships.agreement.signed', 'sponsorship_agreements', (int) $row->id, ['status' => 'draft'], ['status' => 'signed', 'signed_on' => $signedOn,
                'hash' => hash('sha256', $text)], $actor->id, request()?->ip());
        });
    }

    /** Opens the renewal of a signed agreement as a new draft starting the day after it ends. */
    public function renew(User $actor, object $agreement): string
    {
        $this->access->assert($actor, 'sponsorships');
        if ($agreement->status !== 'signed') {
            throw ValidationException::withMessages(['agreement' => 'تُجدد الاتفاقية السارية فقط.']);
        }

        return DB::transaction(function () use ($actor, $agreement): string {
            DB::table('sponsorship_agreements')->where('id', $agreement->id)->lockForUpdate()->first();
            if (DB::table('sponsorship_agreements')->where('renews_id', $agreement->id)->whereIn('status', ['draft', 'signed'])->exists()) {
                throw ValidationException::withMessages(['agreement' => 'يوجد تجديد مفتوح لهذه الاتفاقية.']);
            }
            $starts = $agreement->ends_on ? CarbonImmutable::parse($agreement->ends_on)->addDay() : CarbonImmutable::today(config('orphans.timezone'));
            $ends = $agreement->ends_on ? $starts->addDays(CarbonImmutable::parse($agreement->starts_on)->diffInDays(CarbonImmutable::parse($agreement->ends_on))) : null;
            $template = $agreement->template_id ? DB::table('sponsorship_agreement_templates')->where('code', DB::table('sponsorship_agreement_templates')->where('id', $agreement->template_id)->value('code'))
                ->where('status', 'active')->value('id') : null;
            $publicId = (string) Str::ulid();
            $id = DB::table('sponsorship_agreements')->insertGetId(['public_id' => $publicId, 'agreement_no' => $this->registry->number('sponsorship_agreement_ref_seq', 'AGR-'),
                'sponsor_id' => $agreement->sponsor_id, 'kind' => $agreement->kind, 'fund_id' => $agreement->fund_id, 'template_id' => $template, 'renews_id' => $agreement->id,
                'starts_on' => $starts->toDateString(), 'ends_on' => $ends?->toDateString(), 'renewal_alert_days' => $agreement->renewal_alert_days, 'status' => 'draft',
                'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->recordRow('sponsorships.agreement.renewal_opened', 'sponsorship_agreements', $id, null, ['renews' => $agreement->agreement_no], $actor->id, request()?->ip());

            return $publicId;
        });
    }

    /**
     * The head ends a signed agreement or cancels a draft, with a reason. Its sponsorship lines are not touched:
     * ending a sponsorship is a separate decision on each line.
     */
    public function close(User $actor, object $agreement, string $reason): void
    {
        $reason = trim($reason);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:5', 'max:1000']], [], ['reason' => 'السبب'])->validate();
        $target = match ($agreement->status) {
            'signed' => 'ended',
            'draft' => 'cancelled',
            default => throw ValidationException::withMessages(['agreement' => 'الاتفاقية مغلقة مسبقًا.']),
        };
        abort_unless($target === 'cancelled' ? ($this->access->allows($actor, 'sponsorships') && ((int) $agreement->created_by === (int) $actor->id || $this->access->isHead($actor)))
            : $this->access->isHead($actor), 403, $target === 'ended' ? 'إنهاء اتفاقية سارية لرئيس القسم.' : 'يلغي المسودة من أعدها أو رئيس القسم.');
        DB::transaction(function () use ($actor, $agreement, $reason, $target): void {
            DB::table('sponsorship_agreements')->where('id', $agreement->id)->lockForUpdate()->first();
            DB::table('sponsorship_agreements')->where('id', $agreement->id)->update(['status' => $target, 'status_reason' => $reason, 'closed_by' => $actor->id, 'closed_at' => now(), 'updated_at' => now()]);
            $this->audit->recordRow('sponsorships.agreement.'.$target, 'sponsorship_agreements', (int) $agreement->id, ['status' => $agreement->status], ['status' => $target, 'reason' => $reason],
                $actor->id, request()?->ip());
        });
    }

    /** The agreement's text: the frozen snapshot once signed, else the template filled with today's values. */
    public function text(object $agreement): ?string
    {
        if ($agreement->rendered_body) {
            return $agreement->rendered_body;
        }

        return $agreement->template_id ? $this->render($agreement, null) : null;
    }

    public function render(object $agreement, ?string $signedOn): string
    {
        $template = (string) DB::table('sponsorship_agreement_templates')->where('id', $agreement->template_id)->value('body');
        $sponsor = DB::table('sponsors')->where('id', $agreement->sponsor_id)->first(['name', 'donor_no']);
        $values = DB::table('master_data_values')->pluck('name_ar', 'id');
        $lines = DB::table('sponsorships')->where('agreement_id', $agreement->id)->whereIn('status', self::LIVE_LINES)->orderBy('id')->get();
        $children = OrphanChild::whereIn('id', $lines->pluck('orphan_child_id')->filter())->get(['id', 'full_name', 'file_no'])->keyBy('id');
        $households = DB::table('orphan_households')->whereIn('id', $lines->pluck('household_id'))->pluck('reference_no', 'id');
        $beneficiaries = $lines->map(fn ($line, $i) => ($i + 1).'. '.($line->orphan_child_id
            ? ($children[$line->orphan_child_id]->full_name ?? '').' — ملف '.($children[$line->orphan_child_id]->file_no ?? '')
            : 'أسرة — ملف '.($households[$line->household_id] ?? '')))->implode("\n");
        $commitments = $lines->map(fn ($line, $i) => ($i + 1).'. '.FinanceMoney::format(FinanceMoney::cents((string) $line->amount)).' '.$line->currency.' — '
            .($values[$line->frequency_value_id] ?? '').' — '.($values[$line->purpose_value_id] ?? '').' (من '.$line->starts_on.($line->ends_on ? ' إلى '.$line->ends_on : '').')')->implode("\n");

        return strtr($template, [
            '{agreement_no}' => $agreement->agreement_no, '{sponsor_name}' => (string) $sponsor?->name, '{donor_no}' => (string) $sponsor?->donor_no,
            '{fund_name}' => $agreement->fund_id ? (string) DB::table('sponsorship_funds')->where('id', $agreement->fund_id)->value('name') : '—',
            '{starts_on}' => (string) $agreement->starts_on, '{ends_on}' => $agreement->ends_on ? (string) $agreement->ends_on : 'غير محددة',
            '{beneficiaries}' => $beneficiaries ?: '—', '{commitments}' => $commitments ?: '—', '{signed_on}' => $signedOn ?? '……………', '{today}' => $this->today(),
        ]);
    }

    /**
     * Daily: signed agreements entering their renewal window or past their end date, without a signed renewal,
     * alert the head and the sponsorships staff once per stage. Alerts only.
     *
     * @return array{soon: int, ended: int, notified: int, recipients: int}
     */
    public function sendRenewalAlerts(bool $dryRun = false): array
    {
        $recipients = $this->access->departmentUsersWith('sponsorships');
        $counts = ['soon' => 0, 'ended' => 0, 'notified' => 0, 'recipients' => count($recipients)];
        if (! $this->available()) {
            return $counts;
        }
        $today = $this->today();
        $rows = DB::table('sponsorship_agreements as a')->join('sponsors as s', 's.id', '=', 'a.sponsor_id')->where('a.status', 'signed')->whereNotNull('a.ends_on')
            ->whereRaw('a.ends_on <= (?::date + a.renewal_alert_days)', [$today])
            ->whereNotExists(fn ($q) => $q->from('sponsorship_agreements as r')->whereColumn('r.renews_id', 'a.id')->where('r.status', 'signed'))
            ->get(['a.id', 'a.public_id', 'a.agreement_no', 'a.ends_on', 's.donor_no']);
        foreach ($rows as $row) {
            $ends = substr((string) $row->ends_on, 0, 10);
            $stage = $ends < $today ? 'ended' : 'soon';
            $counts[$stage]++;
            if ($dryRun || $recipients === []) {
                continue;
            }
            $counts['notified'] += $this->notifications->notifyMany($recipients, 'sponsorship_agreement_renewal', [
                'title' => ($stage === 'ended' ? 'انتهت اتفاقية الكفالة ' : 'تنتهي اتفاقية الكفالة ').$row->agreement_no.' في '.$ends,
                'body' => 'الكافل '.$row->donor_no.'. راجع التجديد مع الكافل؛ لا تُغلق أي كفالة آليًا.',
                'url' => '/orphans/agreements/'.$row->public_id, 'dedupe' => 'agreement:'.$row->id.':'.$stage.':'.$ends]);
        }

        return $counts;
    }

    /** @return list<array<string, mixed>> */
    public function index(bool $identity, ?int $sponsorId = null): array
    {
        if (! $this->available()) {
            return [];
        }
        $today = $this->today();

        return DB::table('sponsorship_agreements as a')->join('sponsors as s', 's.id', '=', 'a.sponsor_id')->when($sponsorId, fn ($q, int $id) => $q->where('a.sponsor_id', $id))->orderByRaw("a.status <> 'signed'")->orderBy('a.ends_on')->orderByDesc('a.id')->limit(300)
            ->get(['a.id', 'a.public_id', 'a.agreement_no', 'a.kind', 'a.status', 'a.starts_on', 'a.ends_on', 'a.signed_on', 'a.renewal_alert_days', 's.name', 's.donor_no', 's.confidential',
                DB::raw("(select count(*) from sponsorships l where l.agreement_id = a.id and l.status in ('draft','pending_approval','active','paused')) as lines"),
                DB::raw("(select count(*) from sponsorships l where l.agreement_id = a.id and l.status = 'active') as active_lines")])
            ->map(fn (object $row): array => ['public_id' => $row->public_id, 'agreement_no' => $row->agreement_no, 'kind' => $row->kind, 'kind_label' => self::KINDS[$row->kind] ?? $row->kind,
                'status' => $row->status, 'status_label' => self::STATUS_LABELS[$row->status] ?? $row->status, 'starts_on' => (string) $row->starts_on, 'ends_on' => $row->ends_on ? (string) $row->ends_on : null,
                'signed_on' => $row->signed_on ? (string) $row->signed_on : null, 'lines' => (int) $row->lines, 'active_lines' => (int) $row->active_lines,
                'sponsor' => $row->confidential && ! $identity ? 'كافل '.$row->donor_no.' (سري)' : $row->name,
                'renewal_due' => $row->status === 'signed' && $row->ends_on && CarbonImmutable::parse($row->ends_on)->subDays((int) $row->renewal_alert_days)->toDateString() <= $today])->all();
    }

    public function find(string $publicId): object
    {
        $row = DB::table('sponsorship_agreements')->where('public_id', $publicId)->first();
        abort_unless($row, 404);

        return $row;
    }

    /** Whether a new sponsorship of this sponsor may be linked to the agreement. */
    public function assertLinkable(int $agreementId, int $sponsorId, string $beneficiaryType): void
    {
        $agreement = DB::table('sponsorship_agreements')->where('id', $agreementId)->first();
        if (! $agreement || ! in_array($agreement->status, ['draft', 'signed'], true) || (int) $agreement->sponsor_id !== $sponsorId) {
            throw ValidationException::withMessages(['agreement_id' => 'الاتفاقية غير سارية أو لكافل آخر.']);
        }
        if (($agreement->kind === 'family' && $beneficiaryType !== 'household') || (in_array($agreement->kind, ['individual', 'group'], true) && $beneficiaryType !== 'child')) {
            throw ValidationException::withMessages(['agreement_id' => 'نوع المستفيد لا يطابق نوع الاتفاقية.']);
        }
    }

    /** @return list<string> */
    private function placeholdersIn(string $body): array
    {
        preg_match_all('/\{[a-z_]+\}/', $body, $matches);

        return array_values(array_unique($matches[0]));
    }

    /** @return array<string, mixed> */
    private function validateAgreement(array $input): array
    {
        return Validator::make($input, [
            'kind' => ['required', Rule::in(array_keys(self::KINDS))],
            'sponsor_id' => ['nullable', 'required_unless:kind,fund', 'integer', Rule::exists('sponsors', 'id')->where('status', 'active')],
            'fund_id' => ['nullable', 'required_if:kind,fund', 'integer', Rule::exists('sponsorship_funds', 'id')->where('status', 'active')],
            'template_id' => ['nullable', 'integer', Rule::exists('sponsorship_agreement_templates', 'id')->where('status', 'active')->where('kind', $input['kind'] ?? '')],
            'starts_on' => ['required', 'date_format:Y-m-d'], 'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'renewal_alert_days' => ['nullable', 'integer', 'between:1,180'], 'notes' => ['nullable', 'string', 'max:2000'],
        ], ['sponsor_id.exists' => 'اختر كافلًا نشطًا.', 'template_id.exists' => 'اختر قالبًا معتمدًا من نوع الاتفاقية نفسه.', 'fund_id.exists' => 'اختر صندوقًا قائمًا.'],
            ['kind' => 'نوع الاتفاقية', 'sponsor_id' => 'الكافل', 'fund_id' => 'الصندوق', 'starts_on' => 'تاريخ البداية', 'ends_on' => 'تاريخ النهاية',
                'renewal_alert_days' => 'التنبيه قبل الانتهاء'])->validate();
    }

    private function today(): string
    {
        return CarbonImmutable::today(config('orphans.timezone'))->toDateString();
    }
}
