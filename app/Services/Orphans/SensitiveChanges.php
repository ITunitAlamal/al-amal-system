<?php

namespace App\Services\Orphans;

use App\Models\Orphan;
use App\Models\Person;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Notifications\NotificationCenter;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * «التعديلات الحساسة» (req-04 §4.1, §6, §11): on a record linked to an approved orphan file, a change of name,
 * identity, birth date, sex, life status, parent link or orphanhood class made by staff is kept as a request with the
 * old and the new value. It is applied only when the head of the department or the acting head
 * (OrphanAccess::canDecide) approves it, never by its author (also a CHECK of 000036). The head's own edit is the
 * decision itself and applies directly, audited as before. Values are stored
 * encrypted (identity numbers, restricted causes); a request decided against changed data is refused as stale.
 * Draft and provisional files are edited directly (OPEN_STATUSES).
 */
final class SensitiveChanges
{
    public const KINDS = ['person' => 'البيانات الأساسية', 'vital_status' => 'الحالة الحياتية', 'parent' => 'ربط الأب أو الأم', 'orphan_class' => 'تصنيف اليتم'];

    public const STATUS = ['pending' => 'بانتظار اعتماد رئيس القسم', 'approved' => 'معتمد ومطبق', 'rejected' => 'مرفوض'];

    /** Input keys of Persons::rules() that change the sensitive attributes. */
    public const PERSON_INPUT = ['first_name', 'father_name', 'grandfather_name', 'family_name', 'extra_names', 'full_name', 'id_type_value_id', 'id_number', 'sex', 'date_of_birth', 'dob_status', 'vital_status'];

    /** The attributes compared (id_hash stands for the identity number). */
    private const PERSON_FIELDS = ['first_name', 'father_name', 'grandfather_name', 'family_name', 'extra_names', 'full_name', 'id_type_value_id', 'id_hash', 'sex', 'date_of_birth', 'dob_status', 'vital_status'];

    public const LABELS = ['first_name' => 'الاسم الأول', 'father_name' => 'اسم الأب', 'grandfather_name' => 'اسم الجد', 'family_name' => 'اسم العائلة', 'extra_names' => 'أسماء إضافية',
        'full_name' => 'الاسم الكامل', 'id_type_value_id' => 'نوع الوثيقة', 'id_number' => 'رقم الهوية', 'sex' => 'الجنس', 'date_of_birth' => 'تاريخ الميلاد', 'dob_status' => 'دقة تاريخ الميلاد',
        'vital_status' => 'الحالة', 'status' => 'الحالة المبلغ عنها', 'event_date' => 'تاريخ الحدث', 'place' => 'المكان', 'cause' => 'السبب', 'evidence_note' => 'الإثبات',
        'verification' => 'درجة التحقق', 'parent' => 'الشخص المرتبط', 'parent_swap' => 'تبديل الأب والأم', 'parent_swap_files' => 'ملفات الأشقاء المشمولة بالتبديل', 'orphan_class_value_id' => 'تصنيف اليتم'];

    private const DOB_STATUS = ['declared' => 'مُفاد', 'verified' => 'متحقق', 'estimated' => 'تقديري'];

    private const VERIFICATION = ['reported' => 'مُفاد (غير متحقق)', 'verified' => 'متحقق منه بمستند'];

    public function __construct(private readonly OrphanAccess $access, private readonly Persons $persons, private readonly Registry $registry,
        private readonly AuditService $audit, private readonly NotificationCenter $notifications) {}

    /**
     * File statuses edited directly: the draft and the provisional («معتمد مبدئيًا», not an approval) files still
     * being completed. Protection starts with the final approval ('active') and stays on suspended, ended or archived files.
     */
    public const OPEN_STATUSES = ['draft', 'provisional'];

    /** A file past its final approval. */
    public function protectsFile(Orphan $orphan): bool
    {
        return ! in_array((string) $orphan->status, self::OPEN_STATUSES, true);
    }

    /** A person linked to an approved file: the child, a parent, or the guardian of an approved guardianship. */
    public function protectsPerson(int $personId): bool
    {
        return DB::table('orphans')->whereNotIn('status', self::OPEN_STATUSES)->where(fn ($q) => $q->where('person_id', $personId)
            ->orWhereIn('id', DB::table('orphan_parent_links')->where('person_id', $personId)->select('orphan_id'))
            ->orWhereIn('id', DB::table('orphan_guardianships as g')->join('orphan_guardians as gg', 'gg.id', '=', 'g.guardian_id')
                ->where('gg.person_id', $personId)->where('g.status', 'approved')->select('g.orphan_id')))->exists();
    }

    /** The change waits for the head: a protected record, edited by someone who does not hold the decision. */
    public function mustRequest(User $user, bool $protected): bool
    {
        return $protected && ! $this->access->canDecide($user);
    }

    /** Pending requests the department works on (head's queue). */
    public function pendingCount(): int
    {
        return DB::table('orphan_sensitive_changes')->where('status', 'pending')->count();
    }

    /**
     * Basic data: returns the request for the sensitive part, or null when no sensitive attribute changes. The
     * caller saves the other fields directly.
     */
    public function requestPerson(Person $person, array $data, User $actor): ?object
    {
        $proposed = array_intersect_key($data, array_flip(self::PERSON_INPUT));
        $copy = $this->persons->preview($proposed, $person);
        $changes = [];
        foreach (self::PERSON_FIELDS as $field) {
            if (! $copy->isDirty($field)) {
                continue;
            }
            if ($field === 'id_hash') {
                $changes['id_number'] = [$person->id_number, $copy->id_number];
            } else {
                $changes[$field] = [$this->plain($person->getAttribute($field)), $this->plain($copy->getAttribute($field))];
            }
        }
        if ($changes === []) {
            return null;
        }

        return $this->store('person', 'person:'.$person->id, $person->id, $this->orphanOf($person->id), ['proposed' => $proposed, 'state' => $this->personState($person), 'changes' => $changes],
            $actor, 'full_name');
    }

    /** A dated life-status event (death, missing, alive): the whole record waits. */
    public function requestStatus(Person $person, array $data, User $actor): object
    {
        $changes = ['vital_status' => [$person->vital_status, $data['status']]];
        foreach (['event_date', 'place', 'cause', 'evidence_note', 'verification'] as $field) {
            if (filled($data[$field] ?? null)) {
                $changes[$field] = [null, $data[$field]];
            }
        }

        return $this->store('vital_status', 'status:'.$person->id, $person->id, $this->orphanOf($person->id),
            ['proposed' => $data, 'state' => ['vital_status' => $person->vital_status], 'changes' => $changes], $actor, 'status');
    }

    /** Linking or replacing the father or mother of a file (it drives the derived orphanhood class). */
    public function requestParent(Orphan $orphan, string $relation, array $data, User $actor): object
    {
        if (empty($data['person_id'])) {
            // The same name and duplicate-identity checks a direct link makes, before anything waits for the head.
            $this->persons->preview($data, new Person, $relation.'.id_number');
        }
        $current = DB::table('orphan_parent_links as l')->join('persons as p', 'p.id', '=', 'l.person_id')->where('l.orphan_id', $orphan->id)->where('l.relation', $relation)
            ->first(['l.person_id', 'p.full_name']);
        $new = ! empty($data['person_id']) ? DB::table('persons')->where('id', $data['person_id'])->value('full_name')
            : (filled($data['full_name'] ?? null) ? $data['full_name'] : trim(implode(' ', array_filter([$data['first_name'] ?? null, $data['father_name'] ?? null, $data['grandfather_name'] ?? null, $data['family_name'] ?? null]))));
        $changes = ['parent' => [$current?->full_name, ($relation === 'father' ? 'الأب: ' : 'الأم: ').$new]];
        $state = ['person_id' => $current?->person_id ? (string) $current->person_id : null];
        // Choosing the file's other parent swaps the two links on this file and on every sibling file with the same
        // pair (DB-01): the head sees both changes and the files before approving. A chosen person also pins the other
        // parent and the set of files, so a request is never turned into a swap the head did not see.
        if (! empty($data['person_id'])) {
            $otherNow = DB::table('orphan_parent_links')->where('orphan_id', $orphan->id)->where('relation', $relation === 'father' ? 'mother' : 'father')->value('person_id');
            $state['other_person_id'] = $otherNow ? (string) $otherNow : null;
            $swap = $this->registry->parentSwap($orphan, $relation, (int) $data['person_id']);
            $state['swap_files'] = Registry::swapFiles($swap);
            if ($swap) {
                $otherLabel = $swap[0][0]->relation === 'father' ? 'الأب: ' : 'الأم: ';
                $changes['parent_swap'] = [$otherLabel.$new, $otherLabel.$current?->full_name];
                $siblings = DB::table('orphans')->whereIn('id', array_diff($state['swap_files'], [(int) $orphan->id]))->orderBy('reference_no')->pluck('reference_no')->all();
                if ($siblings !== []) {
                    $changes['parent_swap_files'] = [null, implode('، ', $siblings)];
                }
            }
        }
        if (filled($data['status'] ?? null)) {
            $changes['status'] = [null, $data['status']];
        }

        return $this->store('parent', 'parent:'.$orphan->id.':'.$relation, null, $orphan->id,
            ['proposed' => $data, 'relation' => $relation, 'state' => $state, 'changes' => $changes], $actor, 'full_name');
    }

    public function requestClass(Orphan $orphan, ?int $classId, User $actor): object
    {
        return $this->store('orphan_class', 'class:'.$orphan->id, null, $orphan->id, [
            'proposed' => ['orphan_class_value_id' => $classId],
            'state' => ['orphan_class_value_id' => $this->plain($orphan->orphan_class_value_id), 'class_basis' => $orphan->class_basis],
            'changes' => ['orphan_class_value_id' => [$orphan->orphan_class_value_id, $classId]],
        ], $actor, 'orphan_class_value_id');
    }

    /** The head's decision: approve applies the change in the same transaction; reject keeps the record as it is. */
    public function decide(string $publicId, string $decision, ?string $note, User $actor): object
    {
        $this->access->assertDecide($actor, 'اعتماد التعديلات الحساسة لرئيس قسم شؤون الأيتام والكفالات أو القائم بأعماله؛ لا يمر بدور مدير النظام.');
        if ($decision === 'reject' && blank($note)) {
            throw ValidationException::withMessages(['note' => 'اذكر سبب الرفض.']);
        }

        return DB::transaction(function () use ($publicId, $decision, $note, $actor): object {
            $row = DB::table('orphan_sensitive_changes')->where('public_id', $publicId)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless($row->status === 'pending', 409, 'تم البت في هذا الطلب.');
            abort_if((int) $row->requested_by === (int) $actor->id, 403, 'لا يعتمد صاحب التعديل تعديله؛ يعتمده رئيس القسم أو القائم بأعماله.');
            $payload = self::payload($row);
            if ($decision === 'approve') {
                $this->apply($row, $payload, $actor);
            }
            DB::table('orphan_sensitive_changes')->where('id', $row->id)->update(['status' => $decision === 'approve' ? 'approved' : 'rejected', 'decided_by' => $actor->id,
                'decided_at' => now(), 'decision_note' => $note, 'applied_at' => $decision === 'approve' ? now() : null, 'updated_at' => now()]);
            $this->audit->recordRow('orphans.sensitive_change.'.($decision === 'approve' ? 'approved' : 'rejected'), 'orphan_sensitive_changes', $row->id, ['status' => 'pending'],
                ['status' => $decision === 'approve' ? 'approved' : 'rejected', 'reference' => $row->reference_no, 'requested_by' => (int) $row->requested_by, 'note' => $note], $actor->id, request()?->ip());
            $this->notifications->notify((int) $row->requested_by, 'orphans_sensitive_change', ['title' => ($decision === 'approve' ? 'اعتُمد وطُبّق' : 'رُفض').' طلب التعديل الحساس '.$row->reference_no,
                'body' => (string) $note, 'url' => '/orphans/reviews/sensitive?status=all', 'dedupe' => 'sensitive-decided-'.$row->id]);

            return $row;
        });
    }

    /**
     * One row as the queue shows it. Identity numbers are always masked here (full values only through the logged
     * revealIdentity); restricted causes only for «health».
     */
    public function present(object $row, User $viewer): array
    {
        $payload = self::payload($row);
        $health = $this->access->allows($viewer, 'health');
        $changes = [];
        foreach ($payload['changes'] ?? [] as $field => [$before, $after]) {
            $changes[] = ['field' => $field, 'label' => self::LABELS[$field] ?? $field,
                'before' => $this->display($field, $before, $health), 'after' => $this->display($field, $after, $health)];
        }
        $canReveal = array_key_exists('id_number', $payload['changes'] ?? []) && $this->access->allows($viewer, 'identity');

        return ['public_id' => $row->public_id, 'reference_no' => $row->reference_no, 'kind' => $row->kind, 'kind_label' => self::KINDS[$row->kind] ?? $row->kind,
            'status' => $row->status, 'status_label' => self::STATUS[$row->status] ?? $row->status, 'changes' => $changes, 'requested_by' => $row->requester ?? null,
            'requested_by_id' => (int) $row->requested_by, 'created_at' => $row->created_at, 'decided_by' => $row->decider ?? null, 'decided_at' => $row->decided_at,
            'decision_note' => $row->decision_note, 'subject' => $row->subject_name ?? null, 'orphan_public_id' => $row->orphan_public_id ?? null,
            'orphan_reference' => $row->orphan_reference ?? null, 'guardian_public_id' => $row->guardian_public_id ?? null, 'can_reveal' => $canReveal,
            'can_decide' => $row->status === 'pending' && (int) $row->requested_by !== (int) $viewer->id && $this->access->canDecide($viewer)];
    }

    /** The full current and proposed identity numbers of one request, for holders of «identity»; every reveal is logged. */
    public function revealIdentity(string $publicId, User $viewer): array
    {
        $this->access->assert($viewer, 'identity');
        $row = DB::table('orphan_sensitive_changes')->where('public_id', $publicId)->first();
        abort_unless($row, 404);
        [$before, $after] = self::payload($row)['changes']['id_number'] ?? [null, null];
        $this->access->logRead($viewer, 'orphan_sensitive_change', (int) $row->id, 'id_revealed');

        return ['before' => $before !== null && $before !== '' ? (string) $before : null, 'after' => $after !== null && $after !== '' ? (string) $after : null];
    }

    private static function payload(object $row): array
    {
        return json_decode(Crypt::decryptString($row->payload), true) ?: [];
    }

    private function store(string $kind, string $subjectKey, ?int $personId, ?int $orphanId, array $payload, User $actor, string $errorKey): object
    {
        return DB::transaction(function () use ($kind, $subjectKey, $personId, $orphanId, $payload, $actor, $errorKey): object {
            $busy = fn (string $reference) => ValidationException::withMessages([$errorKey => 'يوجد طلب تعديل حساس لهذا السجل بانتظار قرار رئيس القسم ('.$reference.')؛ يُبت فيه أولًا.']);
            if ($open = DB::table('orphan_sensitive_changes')->where('subject_key', $subjectKey)->where('status', 'pending')->value('reference_no')) {
                throw $busy($open);
            }
            $reference = Registry::reference('SCR', 'orphan_sensitive_change_seq');
            try {
                // Own savepoint: a concurrent pending request (partial unique index) leaves the outer transaction usable.
                $id = DB::transaction(fn (): int => DB::table('orphan_sensitive_changes')->insertGetId(['public_id' => (string) Str::ulid(), 'reference_no' => $reference, 'kind' => $kind,
                    'subject_key' => $subjectKey, 'person_id' => $personId, 'orphan_id' => $orphanId, 'payload' => Crypt::encryptString(json_encode($payload, JSON_UNESCAPED_UNICODE)),
                    'fields' => json_encode(array_keys($payload['changes'] ?? [])), 'status' => 'pending', 'requested_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]));
            } catch (UniqueConstraintViolationException) {
                throw $busy((string) DB::table('orphan_sensitive_changes')->where('subject_key', $subjectKey)->where('status', 'pending')->value('reference_no'));
            }
            $this->audit->recordRow('orphans.sensitive_change.requested', 'orphan_sensitive_changes', $id, null,
                ['reference' => $reference, 'kind' => $kind, 'fields' => array_keys($payload['changes'] ?? [])], $actor->id, request()?->ip());
            $heads = array_values(array_filter($this->access->headUserIds(), fn (int $userId): bool => $userId !== (int) $actor->id));
            $this->notifications->notifyMany($heads, 'orphans_sensitive_change', ['title' => 'تعديل حساس بانتظار اعتمادك '.$reference.' ('.(self::KINDS[$kind] ?? $kind).')',
                'url' => '/orphans/reviews/sensitive', 'dedupe' => 'sensitive-'.$id]);

            return DB::table('orphan_sensitive_changes')->find($id);
        });
    }

    private function apply(object $row, array $payload, User $actor): void
    {
        $proposed = $payload['proposed'] ?? [];
        if ($row->kind === 'orphan_class') {
            $orphan = Orphan::query()->lockForUpdate()->findOrFail($row->orphan_id);
            $this->assertCurrent(['orphan_class_value_id' => $this->plain($orphan->orphan_class_value_id), 'class_basis' => $orphan->class_basis], $payload['state'] ?? []);
            $classId = $proposed['orphan_class_value_id'] ?? null;
            $before = $orphan->only(['status', 'orphan_class_value_id', 'assigned_to']);
            $orphan->forceFill(['orphan_class_value_id' => $classId, 'class_basis' => $classId ? 'manual' : 'provisional', 'updated_by' => $actor->id])->save();
            if (! $classId) {
                $this->registry->deriveClass($orphan);
            }
            $this->audit->recordRow('orphans.orphan.updated', 'orphans', $orphan->id, $before, [...$orphan->only(array_keys($before)), 'change_request' => $row->reference_no], $actor->id, request()?->ip());

            return;
        }
        if ($row->kind === 'parent') {
            $orphan = Orphan::query()->lockForUpdate()->findOrFail($row->orphan_id);
            $relation = (string) ($payload['relation'] ?? '');
            abort_unless(in_array($relation, ['father', 'mother'], true), 422);
            $links = DB::table('orphan_parent_links')->where('orphan_id', $orphan->id)->pluck('person_id', 'relation');
            $current = $links[$relation] ?? null;
            $otherNow = $links[$relation === 'father' ? 'mother' : 'father'] ?? null;
            $state = $payload['state'] ?? [];
            $this->assertCurrent(['person_id' => $current ? (string) $current : null, 'other_person_id' => $otherNow ? (string) $otherNow : null],
                array_diff_key($state, ['swap_files' => true]));
            // The swap changes exactly the files the head saw (siblings included), or the request is stale.
            try {
                // A request made before swap_files existed expects no swap, so it never becomes one the head did not see.
                $expected = array_key_exists('swap_files', $state) ? (array) $state['swap_files'] : (! empty($proposed['person_id']) ? [] : null);
                $this->registry->linkParent($orphan, $relation, $proposed, $actor, $expected);
            } catch (ValidationException $e) {
                // Shown on the head's decision form (a swap conflict names the files to correct first).
                throw ValidationException::withMessages(['decision' => collect($e->errors())->flatten()->first() ?? $e->getMessage()]);
            }
            $this->registry->refreshCompleteness($orphan);

            return;
        }
        $person = Person::query()->lockForUpdate()->findOrFail($row->person_id);
        if ($row->kind === 'vital_status') {
            $this->assertCurrent(['vital_status' => $person->vital_status], $payload['state'] ?? []);
            $this->persons->recordStatus($person, $proposed, $actor->id);
            foreach (Orphan::query()->whereIn('id', DB::table('orphan_parent_links')->where('person_id', $person->id)->pluck('orphan_id'))->get() as $orphan) {
                $this->registry->deriveClass($orphan);
            }
            $this->audit->recordRow('orphans.person.status_recorded', 'persons', $person->id, null, ['status' => $proposed['status'] ?? null, 'verification' => $proposed['verification'] ?? null,
                'change_request' => $row->reference_no], $actor->id, request()?->ip());

            return;
        }
        $this->assertCurrent($this->personState($person), $payload['state'] ?? []);
        $before = $person->only(['full_name', 'date_of_birth', 'sex', 'id_format']);
        $this->persons->save($proposed, $person, $actor->id);
        if ($orphan = Orphan::query()->where('person_id', $person->id)->first()) {
            $this->registry->refreshCompleteness($orphan);
        }
        $this->audit->recordRow('orphans.person.updated', 'persons', $person->id, $before, [...$person->fresh()->only(array_keys($before)), 'change_request' => $row->reference_no],
            $actor->id, request()?->ip());
    }

    /** The record must still hold the values the request was made against. */
    private function assertCurrent(array $now, array $then): void
    {
        foreach ($then as $field => $value) {
            if ($this->plain($now[$field] ?? null) !== $this->plain($value)) {
                throw ValidationException::withMessages(['decision' => 'تغيّرت بيانات السجل بعد تقديم الطلب؛ ارفضه ليُقدَّم طلب جديد على القيم الحالية.']);
            }
        }
    }

    /** @return array<string, ?string> */
    private function personState(Person $person): array
    {
        return collect(self::PERSON_FIELDS)->mapWithKeys(fn (string $field): array => [$field => $this->plain($person->getAttribute($field))])->all();
    }

    private function plain(mixed $value): ?string
    {
        return match (true) {
            $value === null || $value === '' => null,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            default => (string) $value,
        };
    }

    private function orphanOf(int $personId): ?int
    {
        $id = DB::table('orphans')->where('person_id', $personId)->value('id');

        return $id ? (int) $id : null;
    }

    private function display(string $field, mixed $value, bool $health): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match (true) {
            $field === 'id_number' => Identity::mask(mb_substr((string) $value, -4)),
            $field === 'cause' => $health ? (string) $value : 'مقيد',
            str_ends_with($field, '_value_id') => DB::table('master_data_values')->where('id', $value)->value('name_ar') ?? (string) $value,
            $field === 'sex' => Persons::SEX_LABELS[$value] ?? (string) $value,
            in_array($field, ['vital_status', 'status'], true) => Persons::VITAL_LABELS[$value] ?? (string) $value,
            $field === 'dob_status' => self::DOB_STATUS[$value] ?? (string) $value,
            $field === 'verification' => self::VERIFICATION[$value] ?? (string) $value,
            default => (string) $value,
        };
    }
}
