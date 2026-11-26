<?php

namespace App\Services\Orphans;

use App\Models\Guardian;
use App\Models\OrphanChild;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Orphans\Import\ArabicText;
use App\Services\Orphans\Import\RegistryStaging;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The guardian-centred registry: a child is always created WITH a direct link to a legal guardian («الوصي الشرعي»),
 * in one transaction; the database refuses a child without a live link at commit. Each link is reviewed on its own:
 * «بانتظار التحقق» → «معتمدة» or «مرفوضة», and a child has at most one approved live guardian (owner decision
 * 2026-10-30); a replacement ends the current one in the same step. Household membership never creates a link.
 */
final class GuardianLinks
{
    public const STATUS_LABELS = ['pending' => 'بانتظار التحقق', 'approved' => 'معتمدة', 'rejected' => 'مرفوضة'];

    public const ORIGIN_LABELS = ['staff' => 'سجّلها موظف', 'portal' => 'طلب من البوابة', 'application' => 'قرار ملف', 'change' => 'طلب تعديل', 'import' => 'كشف مستورد'];

    public const PER_PAGE = 50;

    public function __construct(private readonly OrphanAccess $access, private readonly AuditService $audit, private readonly OrphanRegistry $registry) {}

    /**
     * Exact identity lookup before registering a guardian (duplicates are refused, never merged).
     *
     * @return array<string, mixed>|null
     */
    public function guardianByIdentity(User $actor, string $nationalId): ?array
    {
        $this->access->assert($actor, 'records');
        $guardian = Guardian::where('national_id_hash', RegistryIdentity::hash(ArabicText::digits($nationalId)))->first();

        return $guardian ? $this->guardianSummary($actor, $guardian) : null;
    }

    /**
     * «إضافة وصي»: a guardian may be registered without children; no portal login is created.
     *
     * @param  array<string, mixed>  $input
     */
    public function createGuardian(User $actor, array $input): Guardian
    {
        $this->access->assert($actor, 'records');
        $data = Validator::make($input, [
            'first_name' => 'required|string|max:60', 'father_name' => 'required|string|max:60', 'grandfather_name' => 'nullable|string|max:60', 'family_name' => 'required|string|max:80',
            'national_id' => 'required|string|max:20', 'phone' => ['nullable', 'string', 'regex:/^\+?[0-9]{8,15}$/'], 'notes' => 'nullable|string|max:1000',
        ], [], ['first_name' => 'الاسم الأول', 'father_name' => 'اسم الأب', 'grandfather_name' => 'اسم الجد', 'family_name' => 'اسم العائلة', 'national_id' => 'رقم هوية الوصي', 'phone' => 'رقم الجوال'])->validate();
        $identity = ArabicText::digits($data['national_id']);
        $hash = RegistryIdentity::hash($identity);
        if (Guardian::where('national_id_hash', $hash)->exists()) {
            throw ValidationException::withMessages(['national_id' => 'رقم هوية الوصي مسجل لوصي قائم؛ اختر الوصي الموجود بدل إنشاء سجل مكرر.']);
        }
        if (! empty($data['phone']) && Guardian::where('phone', $data['phone'])->exists()) {
            throw ValidationException::withMessages(['phone' => 'رقم الجوال مسجل لوصي آخر.']);
        }
        $parts = array_map(fn ($v) => $v === null ? null : trim((string) $v), array_intersect_key($data, array_flip(NameIndex::PARTS)));
        $guardian = new Guardian;
        $guardian->forceFill([
            'name' => implode(' ', array_filter($parts)), 'name_parts' => $parts, 'national_id' => $identity, 'national_id_hash' => $hash,
            'phone' => ($data['phone'] ?? null) ?: null, 'password' => null, 'is_active' => false, 'origin' => 'staff', 'created_by' => $actor->id,
            'reference_no' => $this->registry->number('orphan_guardian_ref_seq', 'GRD-'),
        ])->save();
        $this->audit->recordRow('orphans.guardian.registered', 'guardians', $guardian->id, null, ['origin' => 'staff', 'reference_no' => $guardian->reference_no], $actor->id, request()?->ip());

        return $guardian;
    }

    /**
     * «إضافة يتيم»: guardian first (existing guardian_id, or a new guardian in the same transaction), then the child,
     * then the direct link «بانتظار التحقق». With $approve and the «approve» capability the link is approved at once.
     *
     * @param  array<string, mixed>  $input
     */
    public function createChild(User $actor, array $input, bool $approve = false): OrphanChild
    {
        $this->access->assert($actor, 'records');
        if ($approve) {
            $this->access->assert($actor, 'approve');
        }
        $data = Validator::make($input, [
            'guardian_id' => 'nullable|integer|required_without:guardian', 'guardian' => 'nullable|array|required_without:guardian_id',
            'first_name' => 'required|string|max:60', 'father_name' => 'required|string|max:60', 'grandfather_name' => 'nullable|string|max:60', 'family_name' => 'required|string|max:80',
            'national_id' => 'nullable|string|max:20', 'date_of_birth' => 'required|date|before_or_equal:today', 'sex' => 'nullable|in:male,female',
            'orphan_class_value_id' => ['nullable', 'integer', fn ($a, $v, $fail) => $v && ! $this->masterValue('orphan_classifications', (int) $v) ? $fail('اختر نوع اليتم من ثوابت النظام.') : null],
            'kinship' => 'nullable|string|max:60', 'authority_kind' => 'nullable|string|max:40',
            'evidence_note' => 'required|string|min:10|max:2000',
        ], [], ['guardian_id' => 'الوصي', 'first_name' => 'الاسم الأول', 'father_name' => 'اسم الأب', 'family_name' => 'اسم العائلة', 'national_id' => 'رقم هوية اليتيم',
            'date_of_birth' => 'تاريخ الميلاد', 'evidence_note' => 'دليل الوصاية أو مرجع الوثيقة'])->validate();
        $identity = ($data['national_id'] ?? '') !== '' ? ArabicText::digits($data['national_id']) : null;
        $hash = $identity ? RegistryIdentity::hash($identity) : null;

        try {
            return DB::transaction(function () use ($actor, $data, $identity, $hash, $approve): OrphanChild {
                $guardian = isset($data['guardian_id'])
                    ? Guardian::whereKey($data['guardian_id'])->lockForUpdate()->first() ?? throw ValidationException::withMessages(['guardian_id' => 'الوصي غير موجود.'])
                    : $this->createGuardian($actor, (array) $data['guardian']);
                if ($hash && OrphanChild::where('national_id_hash', $hash)->exists()) {
                    throw ValidationException::withMessages(['national_id' => 'رقم هوية اليتيم مسجل لطفل قائم؛ لا يُنشأ ملف مكرر. اربط الطفل الموجود بهذا الوصي من ملفه.']);
                }
                $parts = array_map(fn ($v) => $v === null ? null : trim((string) $v), array_intersect_key($data, array_flip(NameIndex::PARTS)));
                $child = OrphanChild::create([
                    'public_id' => (string) Str::ulid(), 'source' => 'staff', 'source_child_id' => (string) Str::ulid(), 'household_id' => null,
                    'full_name' => implode(' ', array_filter($parts)), 'name_parts' => $parts, 'national_id' => $identity, 'national_id_hash' => $hash,
                    'date_of_birth' => $data['date_of_birth'] ?? null, 'sex' => $data['sex'] ?? null, 'orphan_class_value_id' => $data['orphan_class_value_id'] ?? null,
                    'status' => 'applicant', 'verification_status' => 'unverified', 'registered_at' => now(), 'created_by' => $actor->id,
                ]);
                $linkId = $this->insertLink($guardian->id, $child->id, ['kinship' => $data['kinship'] ?? null, 'authority_kind' => $data['authority_kind'] ?? null,
                    'evidence_note' => $data['evidence_note'], 'origin' => 'staff', 'created_by' => $actor->id]);
                $this->audit->recordRow('orphans.child.registered', 'orphan_children', $child->id, null, ['guardian' => $guardian->reference_no, 'link' => $linkId, 'origin' => 'staff'], $actor->id, request()?->ip());
                if ($approve) {
                    $this->approve($actor, $linkId, $data['evidence_note'], false);
                }

                return $child;
            });
        } catch (QueryException $e) {
            throw $this->translate($e);
        }
    }

    /**
     * Links an existing child to a guardian as a new claim «بانتظار التحقق» (e.g. a second claimant, or a replacement).
     *
     * @param  array<string, mixed>  $input
     */
    public function linkExisting(User $actor, int $guardianId, array $input): int
    {
        $this->access->assert($actor, 'records');
        $data = Validator::make($input, ['child_national_id' => 'required|string|max:20', 'kinship' => 'nullable|string|max:60', 'authority_kind' => 'nullable|string|max:40',
            'evidence_note' => 'required|string|min:10|max:2000'], [], ['child_national_id' => 'رقم هوية اليتيم', 'evidence_note' => 'دليل الوصاية أو مرجع الوثيقة'])->validate();
        $child = OrphanChild::where('national_id_hash', RegistryIdentity::hash(ArabicText::digits($data['child_national_id'])))->first()
            ?? throw ValidationException::withMessages(['child_national_id' => 'لا يوجد يتيم مسجل بهذا الرقم؛ استخدم «إضافة يتيم».']);
        abort_unless(Guardian::whereKey($guardianId)->exists(), 404);

        return DB::transaction(function () use ($actor, $guardianId, $child, $data): int {
            $id = $this->insertLink($guardianId, $child->id, ['kinship' => $data['kinship'] ?? null, 'authority_kind' => $data['authority_kind'] ?? null,
                'evidence_note' => $data['evidence_note'], 'origin' => 'staff', 'created_by' => $actor->id]);
            $this->audit->recordRow('orphans.guardianship.claimed', 'orphan_guardianships', $id, null, ['child' => $child->public_id], $actor->id, request()?->ip());

            return $id;
        });
    }

    /** Approve or reject one link. Approving while another guardian is approved needs $replace (one active guardian). */
    public function decide(User $actor, int $linkId, string $decision, string $note, bool $replace = false): void
    {
        abort_unless(in_array($decision, ['approve', 'reject'], true), 422);
        if (mb_strlen(trim($note)) < 10) {
            throw ValidationException::withMessages(['note' => 'اكتب الدليل أو سبب القرار (10 أحرف على الأقل).']);
        }
        try {
            DB::transaction(fn () => $decision === 'approve' ? $this->approve($actor, $linkId, $note, $replace) : $this->reject($actor, $linkId, $note));
        } catch (QueryException $e) {
            throw $this->translate($e);
        }
    }

    /**
     * For decisions taken in other workflows (file activation, guardian change request): approves the link of
     * (child, guardian), creating it when absent. Refuses when another guardian is approved — that is a transfer.
     *
     * @param  array<string, mixed>  $refs
     */
    public function approveFromDecision(int $childId, int $guardianId, int $actorId, string $origin, array $refs = [], ?string $authority = null): void
    {
        $today = today(config('orphans.timezone'))->toDateString();
        $other = DB::table('orphan_guardianships')->where('orphan_child_id', $childId)->where('guardian_id', '<>', $guardianId)->where('status', 'approved')->whereNull('ended_reason')->exists();
        if ($other) {
            throw ValidationException::withMessages(['guardian' => 'لليتيم وصي معتمد آخر؛ نقل الوصاية يتم بطلب تعديل يُنهي الوصاية الحالية.']);
        }
        $live = DB::table('orphan_guardianships')->where('orphan_child_id', $childId)->where('guardian_id', $guardianId)->whereNull('ended_reason')->lockForUpdate()->first();
        $approval = ['status' => 'approved', 'approved_by' => $actorId, 'starts_on' => $today, 'decided_by' => $actorId, 'decided_at' => now(), 'updated_at' => now()];
        if ($live) {
            if ($live->status !== 'approved') {
                DB::table('orphan_guardianships')->where('id', $live->id)->update($approval + $refs + ['decision_note' => 'اعتُمدت بقرار '.($origin === 'change' ? 'طلب التعديل' : 'تفعيل الملف')]);
            }

            return;
        }
        DB::table('orphan_guardianships')->insert($approval + $refs + ['guardian_id' => $guardianId, 'orphan_child_id' => $childId, 'origin' => $origin,
            'authority_kind' => $authority, 'created_by' => $actorId, 'created_at' => now()]);
    }

    /** Pending link written by another workflow (portal approval); no capability check here, the caller checked it. */
    public function claimFromWorkflow(int $childId, int $guardianId, int $actorId, string $origin, array $refs = [], ?string $kinship = null, ?string $authority = null): void
    {
        $exists = DB::table('orphan_guardianships')->where('orphan_child_id', $childId)->where('guardian_id', $guardianId)->whereNull('ended_reason')->exists();
        if (! $exists) {
            $this->insertLink($guardianId, $childId, $refs + ['origin' => $origin, 'created_by' => $actorId, 'kinship' => $kinship, 'authority_kind' => $authority]);
        }
    }

    // ---------------------------------------------------------------- lists for «الأيتام والأوصياء»

    /** @param array<string, mixed> $f */
    public function children(User $user, array $f): array
    {
        $this->access->assert($user, 'view');
        $q = DB::table('orphan_children as c');
        $this->nameFilters($q, 'c.name_index', $f, 'cn');
        if (! empty($f['q'])) {
            $q->whereRaw('c.name_index @> ?::text[]', [NameIndex::literal(NameIndex::words((string) $f['q']))]);
        }
        if (trim((string) ($f['child_id'] ?? '')) !== '') {
            $this->access->assert($user, 'sensitive');
            $q->where('c.national_id_hash', RegistryIdentity::hash(ArabicText::digits((string) $f['child_id'])));
        }
        $guardianFilter = fn (Builder $l) => $l->from('orphan_guardianships as l')->join('guardians as g', 'g.id', '=', 'l.guardian_id')->whereColumn('l.orphan_child_id', 'c.id')->whereNull('l.ended_reason');
        if (trim((string) ($f['guardian_id'] ?? '')) !== '') {
            $this->access->assert($user, 'sensitive');
            $q->whereExists(fn (Builder $l) => $guardianFilter($l)->where('g.national_id_hash', RegistryIdentity::hash(ArabicText::digits((string) $f['guardian_id']))));
        }
        if ($this->hasNameFilter($f, 'gn')) {
            $q->whereExists(fn (Builder $l) => $this->nameFilters($guardianFilter($l), 'g.name_index', $f, 'gn'));
        }
        foreach (['sex' => 'c.sex', 'status' => 'c.status', 'orphan_class' => 'c.orphan_class_value_id', 'source' => 'c.source', 'verification' => 'c.verification_status'] as $param => $column) {
            if (($f[$param] ?? '') !== '') {
                $f[$param] === RegistryStaging::MISSING ? $q->whereNull($column) : $q->where($column, $f[$param]);
            }
        }
        if (($f['link'] ?? '') === 'approved') {
            $q->whereExists(fn (Builder $l) => $l->from('orphan_guardianships as l')->whereColumn('l.orphan_child_id', 'c.id')->whereNull('l.ended_reason')->where('l.status', 'approved'));
        } elseif (($f['link'] ?? '') === 'pending') {
            $q->whereNotExists(fn (Builder $l) => $l->from('orphan_guardianships as l')->whereColumn('l.orphan_child_id', 'c.id')->whereNull('l.ended_reason')->where('l.status', 'approved'));
        }
        $tz = config('orphans.timezone');
        if (($f['age_min'] ?? '') !== '') {
            $q->where('c.date_of_birth', '<=', now($tz)->subYears((int) $f['age_min'])->toDateString());
        }
        if (($f['age_max'] ?? '') !== '') {
            $q->where('c.date_of_birth', '>', now($tz)->subYears((int) $f['age_max'] + 1)->toDateString());
        }
        if (! empty($f['age_unknown'])) {
            $q->whereNull('c.date_of_birth');
        }
        $page = $q->orderByDesc('c.id')->paginate(self::PER_PAGE, ['c.id']);
        $children = OrphanChild::whereIn('id', $page->getCollection()->pluck('id'))->get()->keyBy('id');
        $links = $this->linksFor($page->getCollection()->pluck('id')->all());
        $classes = DB::table('master_data_values')->whereIn('id', $children->pluck('orphan_class_value_id')->filter())->pluck('name_ar', 'id');
        $sensitive = $this->access->allows($user, 'sensitive');
        $page->setCollection($page->getCollection()->map(fn (object $row): array => $this->childRow($children[$row->id], $links[$row->id] ?? [], $classes, $sensitive)));

        return $this->paged($page);
    }

    /** @param array<string, mixed> $f */
    public function guardians(User $user, array $f): array
    {
        $this->access->assert($user, 'view');
        $q = DB::table('guardians as g');
        $this->nameFilters($q, 'g.name_index', $f, 'gn');
        if (! empty($f['q'])) {
            $q->whereRaw('g.name_index @> ?::text[]', [NameIndex::literal(NameIndex::words((string) $f['q']))]);
        }
        if (trim((string) ($f['guardian_id'] ?? '')) !== '') {
            $this->access->assert($user, 'sensitive');
            $q->where('g.national_id_hash', RegistryIdentity::hash(ArabicText::digits((string) $f['guardian_id'])));
        }
        if (($f['origin'] ?? '') !== '') {
            $q->where('g.origin', $f['origin']);
        }
        // Child criteria in the guardian view: guardians with a live link to a matching child.
        $childLink = fn (Builder $l) => $l->from('orphan_guardianships as l')->join('orphan_children as c', 'c.id', '=', 'l.orphan_child_id')->whereColumn('l.guardian_id', 'g.id')->whereNull('l.ended_reason');
        if ($this->hasNameFilter($f, 'cn')) {
            $q->whereExists(fn (Builder $l) => $this->nameFilters($childLink($l), 'c.name_index', $f, 'cn'));
        }
        if (trim((string) ($f['child_id'] ?? '')) !== '') {
            $this->access->assert($user, 'sensitive');
            $q->whereExists(fn (Builder $l) => $childLink($l)->where('c.national_id_hash', RegistryIdentity::hash(ArabicText::digits((string) $f['child_id']))));
        }
        $count = fn (string $status) => DB::raw("(select count(*) from orphan_guardianships l where l.guardian_id = g.id and l.ended_reason is null and l.status = '$status') as $status");
        if (($f['link'] ?? '') === 'pending') {
            $q->whereExists(fn (Builder $l) => $l->from('orphan_guardianships as l')->whereColumn('l.guardian_id', 'g.id')->whereNull('l.ended_reason')->where('l.status', 'pending'));
        } elseif (($f['link'] ?? '') === 'none') {
            $q->whereNotExists(fn (Builder $l) => $l->from('orphan_guardianships as l')->whereColumn('l.guardian_id', 'g.id')->whereNull('l.ended_reason'));
        }
        $page = $q->orderByDesc('g.id')->paginate(self::PER_PAGE, ['g.id', 'g.name', 'g.reference_no', 'g.origin', 'g.is_active', $count('pending'), $count('approved')]);
        $sensitive = $this->access->allows($user, 'sensitive');
        // The identity is encrypted: decrypt through the model for the page only.
        $identities = Guardian::whereIn('id', $page->getCollection()->pluck('id'))->get()->mapWithKeys(fn (Guardian $g): array => [$g->id => $g->national_id]);
        $page->getCollection()->transform(fn (object $g): array => ['id' => $g->id, 'name' => $g->name, 'reference_no' => $g->reference_no,
            'national_id' => $this->identity($identities[$g->id] ?? null, $sensitive), 'origin' => $g->origin, 'portal' => (bool) $g->is_active && $g->origin === 'portal',
            'pending' => (int) $g->pending, 'approved' => (int) $g->approved]);

        return $this->paged($page);
    }

    /** «الوصي ← الأيتام المرتبطون به»: the guardian and every live and past link, each with its own state. */
    public function guardian(User $user, int $id): array
    {
        $this->access->assert($user, 'view');
        $guardian = Guardian::findOrFail($id);
        $this->access->logRead($user, 'guardian', $guardian->id);
        $links = DB::table('orphan_guardianships')->where('guardian_id', $id)->orderByRaw('ended_reason is not null')->orderByDesc('id')->get();
        $children = OrphanChild::whereIn('id', $links->pluck('orphan_child_id'))->get()->keyBy('id');
        $sensitive = $this->access->allows($user, 'sensitive');

        return $this->guardianSummary($user, $guardian) + [
            'links' => $links->map(fn (object $l): array => $this->linkRow($l) + ['child' => $this->childBrief($children[$l->orphan_child_id], $sensitive),
                'other_approved' => DB::table('orphan_guardianships')->where('orphan_child_id', $l->orphan_child_id)->where('guardian_id', '<>', $id)->where('status', 'approved')->whereNull('ended_reason')->exists()])->values(),
        ];
    }

    /** Child preview: identity, every guardian link with its state, and the next permitted action. */
    public function child(User $user, int $id): array
    {
        $this->access->assert($user, 'view');
        $child = OrphanChild::findOrFail($id);
        $this->access->logRead($user, 'child', $child->id);
        $sensitive = $this->access->allows($user, 'sensitive');
        $links = DB::table('orphan_guardianships as l')->join('guardians as g', 'g.id', '=', 'l.guardian_id')->where('l.orphan_child_id', $id)
            ->orderByRaw('l.ended_reason is not null')->orderByDesc('l.id')->get(['l.*', 'g.name as guardian_name', 'g.reference_no as guardian_ref']);
        $live = $links->whereNull('ended_reason');
        $next = $live->contains('status', 'approved')
            ? ($live->contains('status', 'pending') ? 'ادعاء وصاية آخر بانتظار القرار؛ اعتماده يستبدل الوصي الحالي.' : 'لا إجراء على الوصاية؛ أكمل الملف والمستندات.')
            : 'مراجعة دليل الوصاية واعتماد الرابط أو رفضه (لا يُرفض آخر رابط قبل إضافة وصي بديل).';

        return ['child' => $this->childBrief($child, $sensitive) + ['status' => $child->status, 'status_label' => OrphanChild::STATUS_LABELS[$child->status] ?? $child->status,
            'verification' => OrphanChild::VERIFICATION_LABELS[$child->verification_status] ?? $child->verification_status, 'source' => $child->source,
            'orphan_class' => $child->orphan_class_value_id ? DB::table('master_data_values')->where('id', $child->orphan_class_value_id)->value('name_ar') : null,
            'household' => $child->household_id ? DB::table('orphan_households')->where('id', $child->household_id)->value('reference_no') : null],
            'links' => $links->map(fn (object $l): array => $this->linkRow($l) + ['guardian' => ['id' => $l->guardian_id, 'name' => $l->guardian_name, 'reference_no' => $l->guardian_ref]])->values(),
            'next_action' => $next, 'can' => ['approve' => $this->access->allows($user, 'approve'), 'reject' => $this->access->allows($user, 'reject')]];
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function options(): array
    {
        $values = fn (string $type) => DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')->where('t.code', $type)->where('v.is_active', true)
            ->orderBy('v.sort_order')->get(['v.id', 'v.code', 'v.name_ar'])->map(fn ($v) => (array) $v)->all();

        return ['orphan_classes' => $values('orphan_classifications'), 'kinships' => $values('kinship_types'), 'authority_kinds' => $values('guardian_authority_kinds')];
    }

    // ---------------------------------------------------------------- internals

    private function approve(User $actor, int $linkId, string $note, bool $replace): void
    {
        $this->access->assert($actor, 'approve');
        $link = DB::table('orphan_guardianships')->where('id', $linkId)->lockForUpdate()->first();
        abort_unless($link && $link->ended_reason === null, 404);
        if ($link->status === 'approved') {
            return;
        }
        $today = today(config('orphans.timezone'))->toDateString();
        $current = DB::table('orphan_guardianships')->where('orphan_child_id', $link->orphan_child_id)->where('status', 'approved')->whereNull('ended_reason')->lockForUpdate()->first();
        if ($current) {
            if (! $replace) {
                throw ValidationException::withMessages(['replace' => 'لليتيم وصي معتمد حالي؛ اختر «استبدال الوصي الحالي» لإنهاء وصايته واعتماد هذا الرابط.']);
            }
            DB::table('orphan_guardianships')->where('id', $current->id)->update(['ends_on' => $today, 'ended_reason' => 'استُبدل بوصي معتمد آخر', 'ended_by' => $actor->id, 'updated_at' => now()]);
            $this->audit->recordRow('orphans.guardianship.replaced', 'orphan_guardianships', $current->id, ['status' => 'approved'], ['replaced_by' => $linkId], $actor->id, request()?->ip());
        }
        DB::table('orphan_guardianships')->where('id', $linkId)->update(['status' => 'approved', 'approved_by' => $actor->id, 'starts_on' => $today,
            'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note, 'updated_at' => now()]);
        $this->audit->recordRow('orphans.guardianship.approved', 'orphan_guardianships', $linkId, ['status' => $link->status], ['status' => 'approved', 'note' => $note], $actor->id, request()?->ip());
    }

    private function reject(User $actor, int $linkId, string $note): void
    {
        $this->access->assert($actor, 'reject');
        $link = DB::table('orphan_guardianships')->where('id', $linkId)->lockForUpdate()->first();
        abort_unless($link && $link->ended_reason === null, 404);
        if ($link->status === 'approved') {
            throw ValidationException::withMessages(['decision' => 'الوصاية معتمدة؛ إنهاؤها يتم باستبدال الوصي أو بطلب تعديل.']);
        }
        DB::table('orphan_guardianships')->where('id', $linkId)->update(['status' => 'rejected', 'ended_reason' => 'رُفض الادعاء', 'ends_on' => today(config('orphans.timezone'))->toDateString(),
            'ended_by' => $actor->id, 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note, 'updated_at' => now()]);
        $this->audit->recordRow('orphans.guardianship.rejected', 'orphan_guardianships', $linkId, ['status' => $link->status], ['status' => 'rejected', 'note' => $note], $actor->id, request()?->ip());
    }

    /** @param array<string, mixed> $values */
    private function insertLink(int $guardianId, int $childId, array $values): int
    {
        try {
            return (int) DB::table('orphan_guardianships')->insertGetId($values + ['guardian_id' => $guardianId, 'orphan_child_id' => $childId, 'status' => 'pending',
                'created_at' => now(), 'updated_at' => now()]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['guardian_id' => 'هذا الوصي مرتبط بهذا اليتيم بالفعل.']);
        }
    }

    private function translate(QueryException $e): \Throwable
    {
        return str_contains($e->getMessage(), 'orphan_child_without_guardian')
            ? ValidationException::withMessages(['decision' => 'لا يمكن ترك اليتيم بلا وصي: أضف وصيًا بديلًا قبل رفض آخر رابط أو إنهائه.'])
            : $e;
    }

    /** @param array<string, mixed> $f */
    private function nameFilters(Builder $q, string $column, array $f, string $prefix): Builder
    {
        $tokens = [];
        foreach (array_keys(NameIndex::PARTS) as $n) {
            if (trim((string) ($f[$prefix.$n] ?? '')) !== '') {
                $tokens[] = NameIndex::part($n, (string) $f[$prefix.$n], ($f['name_mode'] ?? 'starts') === 'exact');
            }
        }
        if ($tokens !== []) {
            $q->whereRaw("$column @> ?::text[]", [NameIndex::literal(array_values(array_filter($tokens)))]);
        }

        return $q;
    }

    /** @param array<string, mixed> $f */
    private function hasNameFilter(array $f, string $prefix): bool
    {
        return collect(array_keys(NameIndex::PARTS))->contains(fn (int $n) => trim((string) ($f[$prefix.$n] ?? '')) !== '');
    }

    /** @return array<int, list<object>> live links per child */
    private function linksFor(array $childIds): array
    {
        return DB::table('orphan_guardianships as l')->join('guardians as g', 'g.id', '=', 'l.guardian_id')->whereIn('l.orphan_child_id', $childIds)->whereNull('l.ended_reason')
            ->orderByRaw("l.status = 'approved' desc")->get(['l.id', 'l.orphan_child_id', 'l.status', 'l.origin', 'l.kinship', 'g.id as guardian_id', 'g.name as guardian_name'])
            ->groupBy('orphan_child_id')->map(fn ($g) => $g->all())->all();
    }

    private function childRow(OrphanChild $c, array $links, $classes, bool $sensitive): array
    {
        $first = $links[0] ?? null;

        return $this->childBrief($c, $sensitive) + ['status_label' => OrphanChild::STATUS_LABELS[$c->status] ?? $c->status, 'orphan_class' => $classes[$c->orphan_class_value_id] ?? null,
            'guardian' => $first ? ['id' => $first->guardian_id, 'name' => $first->guardian_name, 'status' => $first->status, 'kinship' => $first->kinship] : null,
            'claims' => count($links), 'source' => $c->source];
    }

    private function childBrief(OrphanChild $c, bool $sensitive): array
    {
        return ['id' => $c->id, 'public_id' => $c->public_id, 'name' => $c->full_name, 'national_id' => $this->identity($c->national_id, $sensitive),
            'dob' => $c->date_of_birth?->toDateString(), 'age' => $c->date_of_birth ? $c->ageOn() : null, 'sex' => $c->sex, 'file_no' => $c->file_no, 'has_household' => $c->household_id !== null];
    }

    private function linkRow(object $l): array
    {
        return ['id' => $l->id, 'status' => $l->status, 'status_label' => self::STATUS_LABELS[$l->status] ?? $l->status, 'origin' => self::ORIGIN_LABELS[$l->origin] ?? $l->origin,
            'kinship' => $l->kinship, 'authority_kind' => $l->authority_kind, 'starts_on' => $l->starts_on, 'ends_on' => $l->ends_on, 'ended_reason' => $l->ended_reason,
            'live' => $l->ended_reason === null, 'evidence_note' => $l->evidence_note, 'decision_note' => $l->decision_note, 'decided_at' => $l->decided_at];
    }

    private function guardianSummary(User $user, Guardian $g): array
    {
        return ['guardian' => ['id' => $g->id, 'name' => $g->name, 'reference_no' => $g->reference_no, 'national_id' => $this->identity($g->national_id, $this->access->allows($user, 'sensitive')),
            'origin' => $g->origin, 'portal' => $g->origin === 'portal' && $g->is_active,
            'pending' => DB::table('orphan_guardianships')->where('guardian_id', $g->id)->whereNull('ended_reason')->where('status', 'pending')->count(),
            'approved' => DB::table('orphan_guardianships')->where('guardian_id', $g->id)->whereNull('ended_reason')->where('status', 'approved')->count()]];
    }

    private function identity(?string $id, bool $sensitive): ?string
    {
        return $sensitive ? $id : RegistryStaging::mask($id);
    }

    private function masterValue(string $type, int $id): bool
    {
        return DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')->where('t.code', $type)->where('v.id', $id)->exists();
    }

    private function paged($page): array
    {
        return ['data' => $page->items(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()];
    }
}
