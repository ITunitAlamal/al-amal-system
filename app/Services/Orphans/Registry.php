<?php

namespace App\Services\Orphans;

use App\Models\Orphan;
use App\Models\OrphanGuardian;
use App\Models\OrphanGuardianship;
use App\Models\OrphanParentLink;
use App\Models\Person;
use App\Models\RegistryAddress;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registry write operations. Every child is written together with its guardian link in one transaction; the
 * database refuses to commit an operational orphan without a live guardianship.
 */
final class Registry
{
    public function __construct(private readonly Persons $persons, private readonly AuditService $audit, private readonly OrphanAccess $access) {}

    public static function reference(string $prefix, string $sequence): string
    {
        return $prefix.'-'.now()->format('Y').'-'.str_pad((string) DB::selectOne("SELECT nextval('{$sequence}') AS n")->n, 6, '0', STR_PAD_LEFT);
    }

    /** A guardian alone (no child yet), or reusing an existing person (e.g. a mother already registered). */
    public function createGuardian(array $person, ?int $personId, array $data, User $actor): OrphanGuardian
    {
        return DB::transaction(function () use ($person, $personId, $data, $actor): OrphanGuardian {
            $row = $personId ? Person::query()->lockForUpdate()->findOrFail($personId) : $this->persons->save($person, null, $actor->id, 'guardian.id_number');
            if ($existing = OrphanGuardian::query()->where('person_id', $row->id)->first()) {
                return $existing;
            }
            $guardian = OrphanGuardian::create(['reference_no' => self::reference('GRD', 'guardian_reference_seq'), 'person_id' => $row->id, 'status' => 'active',
                'notes' => $data['notes'] ?? null, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
            if (! empty($data['address'])) {
                $this->saveAddress(['person_id' => $row->id], 'current', $data['address'], $actor->id);
            }
            $this->audit->recordRow('orphans.guardian.created', 'orphan_guardians', $guardian->id, null, ['reference' => $guardian->reference_no], $actor->id, request()?->ip());

            return $guardian;
        });
    }

    /**
     * Creates the child file and its explicit guardian relationship atomically. $link holds either guardian_id or a
     * new guardian (person data / person_id), plus the relationship facts. Parents are optional at this stage.
     */
    public function createOrphan(array $person, array $orphan, array $link, array $parents, User $actor): Orphan
    {
        return DB::transaction(function () use ($person, $orphan, $link, $parents, $actor): Orphan {
            $guardian = ! empty($link['guardian_id'])
                ? OrphanGuardian::query()->lockForUpdate()->findOrFail($link['guardian_id'])
                : $this->createGuardian($link['guardian'] ?? [], $link['guardian_person_id'] ?? null, ['address' => $link['guardian_address'] ?? null], $actor);
            $childPerson = ! empty($orphan['person_id']) ? Person::query()->lockForUpdate()->findOrFail($orphan['person_id']) : $this->persons->save($person, null, $actor->id, 'child.id_number');
            if ($childPerson->id === $guardian->person_id) {
                throw ValidationException::withMessages(['link' => 'لا يمكن أن يكون اليتيم وصيًا على نفسه.']);
            }
            if (Orphan::query()->where('person_id', $childPerson->id)->exists()) {
                throw ValidationException::withMessages(['child.id_number' => 'لهذا الشخص ملف يتيم قائم؛ افتح الملف الموجود.']);
            }
            $file = Orphan::create([
                'reference_no' => self::reference('ORP', 'orphan_reference_seq'), 'person_id' => $childPerson->id,
                'status' => $orphan['status'] ?? 'draft', 'registration_source' => $orphan['registration_source'] ?? 'staff',
                'registered_on' => $orphan['registered_on'] ?? today()->toDateString(), 'orphan_class_value_id' => $orphan['orphan_class_value_id'] ?? null,
                'class_basis' => ! empty($orphan['orphan_class_value_id']) ? 'manual' : 'provisional', 'assigned_to' => $orphan['assigned_to'] ?? null,
                'notes' => $orphan['notes'] ?? null, 'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);
            $this->addGuardianship($file, $guardian, ['is_primary' => true, ...$link], $actor);
            foreach (['father', 'mother'] as $relation) {
                if (! empty($parents[$relation])) {
                    $this->linkParent($file, $relation, $parents[$relation], $actor);
                }
            }
            if (! empty($orphan['address'])) {
                $this->saveAddress(['person_id' => $childPerson->id], 'current', $orphan['address'], $actor->id);
            }
            $this->refreshCompleteness($file);
            $this->audit->recordRow('orphans.orphan.created', 'orphans', $file->id, null, ['reference' => $file->reference_no, 'guardian' => $guardian->reference_no], $actor->id, request()?->ip());

            return $file;
        });
    }

    /** Adds a direct relationship. Approval needs the «portal» capability and a start date; staff otherwise record a claim. */
    public function addGuardianship(Orphan $orphan, OrphanGuardian $guardian, array $data, User $actor): OrphanGuardianship
    {
        $status = $data['link_status'] ?? 'claimed';
        if (! in_array($status, ['claimed', 'pending', 'approved'], true)) {
            $status = 'claimed';
        }
        if ($status === 'approved') {
            $this->access->assertDecision($actor, 'portal');
            if (empty($data['starts_on'])) {
                throw ValidationException::withMessages(['link.starts_on' => 'اعتماد الوصاية يحتاج تاريخ بدء السريان.']);
            }
        }
        if (OrphanGuardianship::query()->where('orphan_id', $orphan->id)->where('guardian_id', $guardian->id)->whereIn('status', OrphanGuardianship::LIVE)->exists()) {
            throw ValidationException::withMessages(['link' => 'يوجد ارتباط قائم بين هذا اليتيم وهذا الوصي.']);
        }
        if (! empty($data['is_primary'])) {
            OrphanGuardianship::query()->where('orphan_id', $orphan->id)->whereIn('status', OrphanGuardianship::LIVE)->update(['is_primary' => false]);
        }
        $link = OrphanGuardianship::create([
            'orphan_id' => $orphan->id, 'guardian_id' => $guardian->id, 'status' => $status, 'is_primary' => (bool) ($data['is_primary'] ?? false),
            'kinship_value_id' => $data['kinship_value_id'] ?? null, 'authority_kind_value_id' => $data['authority_kind_value_id'] ?? null,
            'legal_basis' => $data['legal_basis'] ?? null, 'authority_scope' => $data['authority_scope'] ?? null,
            'starts_on' => $data['starts_on'] ?? null, 'ends_on' => $data['ends_on'] ?? null, 'source' => $data['source'] ?? 'staff',
            'evidence_note' => $data['evidence_note'] ?? null, 'replaces_id' => $data['replaces_id'] ?? null, 'created_by' => $actor->id,
            'provenance' => $data['provenance'] ?? null,
            ...($status === 'approved' ? ['decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $data['decision_note'] ?? null] : []),
        ]);
        $this->audit->recordRow('orphans.guardianship.created', 'orphan_guardianships', $link->id, null, ['orphan' => $orphan->reference_no, 'guardian' => $guardian->reference_no, 'status' => $status], $actor->id, request()?->ip());

        return $link;
    }

    /** Approve or reject one child's guardianship (never a whole family). */
    public function decideGuardianship(OrphanGuardianship $link, string $decision, array $data, User $actor): void
    {
        $this->access->assertDecision($actor, 'portal');
        DB::transaction(function () use ($link, $decision, $data, $actor): void {
            $link = OrphanGuardianship::query()->lockForUpdate()->findOrFail($link->id);
            abort_unless(in_array($link->status, ['claimed', 'pending'], true), 409, 'تم البت في هذا الارتباط مسبقًا.');
            if ($decision === 'approve') {
                $starts = $data['starts_on'] ?? $link->starts_on?->toDateString();
                if (! $starts) {
                    throw ValidationException::withMessages(['starts_on' => 'حدد تاريخ بدء سريان الوصاية.']);
                }
                $link->forceFill(['status' => 'approved', 'starts_on' => $starts, 'ends_on' => $data['ends_on'] ?? $link->ends_on,
                    'authority_kind_value_id' => $data['authority_kind_value_id'] ?? $link->authority_kind_value_id, 'legal_basis' => $data['legal_basis'] ?? $link->legal_basis,
                    'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $data['note'] ?? null])->save();
            } else {
                if (blank($data['note'] ?? null)) {
                    throw ValidationException::withMessages(['note' => 'سبب الرفض مطلوب.']);
                }
                // Rejecting the only live link of an operational file is refused by the database: add the right guardian first.
                $link->forceFill(['status' => 'rejected', 'is_primary' => false, 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $data['note']])->save();
            }
            $this->audit->recordRow('orphans.guardianship.'.($decision === 'approve' ? 'approved' : 'rejected'), 'orphan_guardianships', $link->id, null, ['note' => $data['note'] ?? null], $actor->id, request()?->ip());
        });
    }

    /**
     * Replaces a guardian in one transaction: the new relationship is created (claimed, pending or approved), the old
     * one is ended with its reason and kept as history. Benefits already delivered keep their recipients.
     */
    public function replaceGuardian(OrphanGuardianship $old, OrphanGuardian $new, array $data, User $actor): OrphanGuardianship
    {
        if (blank($data['reason'] ?? null)) {
            throw ValidationException::withMessages(['reason' => 'سبب الاستبدال مطلوب.']);
        }

        return DB::transaction(function () use ($old, $new, $data, $actor): OrphanGuardianship {
            $old = OrphanGuardianship::query()->lockForUpdate()->findOrFail($old->id);
            abort_unless(in_array($old->status, OrphanGuardianship::LIVE, true), 409, 'الارتباط القديم غير قائم.');
            $orphan = Orphan::findOrFail($old->orphan_id);
            $wasPrimary = $old->is_primary;
            $old->forceFill(['is_primary' => false])->save();
            $link = $this->addGuardianship($orphan, $new, [...$data, 'is_primary' => $wasPrimary, 'replaces_id' => $old->id], $actor);
            $old->forceFill(['status' => 'ended', 'ended_at' => now(), 'ended_by' => $actor->id, 'ended_reason' => $data['reason'],
                'ends_on' => $old->ends_on ?? ($data['starts_on'] ?? today()->toDateString())])->save();
            $this->audit->recordRow('orphans.guardianship.replaced', 'orphan_guardianships', $old->id, null, ['new_link' => $link->id, 'reason' => $data['reason']], $actor->id, request()?->ip());

            return $link;
        });
    }

    public function endGuardianship(OrphanGuardianship $link, string $reason, User $actor): void
    {
        DB::transaction(function () use ($link, $reason, $actor): void {
            $link = OrphanGuardianship::query()->lockForUpdate()->findOrFail($link->id);
            abort_unless(in_array($link->status, OrphanGuardianship::LIVE, true), 409, 'الارتباط غير قائم.');
            $link->forceFill(['status' => 'ended', 'is_primary' => false, 'ended_at' => now(), 'ended_by' => $actor->id, 'ended_reason' => $reason,
                'ends_on' => $link->ends_on ?? today()->toDateString()])->save();
            $this->audit->recordRow('orphans.guardianship.ended', 'orphan_guardianships', $link->id, null, ['reason' => $reason], $actor->id, request()?->ip());
        });
    }

    /**
     * Links a father or mother; an existing person is reused so siblings share one parent record. Choosing the file's
     * own other parent (father and mother entered in the wrong columns) swaps the two links, on this file and on every
     * sibling file that carries the same pair, in this one step (DB-01, see swapParents). $expectedSwapFiles, from an
     * approved request, is the set of files the head saw; a different set now is refused as stale.
     *
     * @param  list<int>|null  $expectedSwapFiles
     */
    public function linkParent(Orphan $orphan, string $relation, array $data, User $actor, ?array $expectedSwapFiles = null): OrphanParentLink
    {
        return DB::transaction(function () use ($orphan, $relation, $data, $actor, $expectedSwapFiles): OrphanParentLink {
            $person = ! empty($data['person_id']) ? Person::findOrFail($data['person_id']) : $this->persons->save($data, null, $actor->id, $relation.'.id_number');
            if ($person->id === $orphan->person_id) {
                throw ValidationException::withMessages([$relation => 'لا يمكن ربط اليتيم بنفسه.']);
            }
            $swap = $this->parentSwap($orphan, $relation, (int) $person->id, true);
            if ($expectedSwapFiles !== null && self::swapFiles($swap) !== self::sortedIds($expectedSwapFiles)) {
                throw ValidationException::withMessages(['decision' => 'تغيّرت ملفات الأشقاء المشمولة بالتبديل بعد تقديم الطلب؛ ارفضه ليُقدَّم طلب جديد على القيم الحالية.']);
            }
            if ($swap) {
                $this->assertSwapAllowed($orphan, $swap, $relation, $actor);
            }
            if (! empty($data['status'])) {
                $this->persons->recordStatus($person, ['status' => $data['status'], 'event_date' => $data['event_date'] ?? null, 'evidence_note' => $data['evidence_note'] ?? null, 'verification' => 'reported'], $actor->id);
            }
            if ($swap) {
                return $this->swapParents($orphan, $relation, $swap, $data, $actor);
            }
            $this->assertOneRelation($orphan, $relation, (int) $person->id);
            try {
                // Own savepoint: a concurrent link refused by the one-relation trigger (000043) leaves the outer
                // transaction usable and is returned as a readable message.
                $link = DB::transaction(fn (): OrphanParentLink => OrphanParentLink::query()->updateOrCreate(['orphan_id' => $orphan->id, 'relation' => $relation],
                    ['person_id' => $person->id, 'basis' => $data['basis'] ?? 'declared', 'verification' => 'reported', 'verified_by' => null, 'verified_at' => null,
                        'evidence_note' => $data['link_note'] ?? null, 'created_by' => $actor->id]));
            } catch (QueryException $e) {
                if (! self::isOneRelationViolation($e)) {
                    throw $e;
                }
                $this->assertOneRelation($orphan, $relation, (int) $person->id);

                throw ValidationException::withMessages([$relation => 'هذا الشخص مربوط بالصفة الأخرى في ملف آخر؛ لا يُربط الشخص نفسه أبًا وأمًّا.']);
            }
            $this->deriveClass($orphan);
            $this->audit->recordRow('orphans.parent.linked', 'orphan_parent_links', $link->id, null,
                ['orphan' => $orphan->reference_no, 'relation' => $relation], $actor->id, request()?->ip());

            return $link;
        });
    }

    /**
     * When the person chosen for $relation is this file's other parent, the swap to make: one [other link, $relation
     * link] pair per file, this file first, then every sibling file that holds the same two persons in the same two
     * columns (siblings share parent persons, so a swap of one file alone would always break the one-relation rule on
     * the others). Null when it is not a swap. Refused when the file has no current $relation to move into the other
     * column (that would leave the other parent empty; the correct parent is linked there first). With $lock, all the
     * family's links are locked in one statement in id order, and the pairs are re-checked on the locked rows.
     *
     * @return list<array{0: OrphanParentLink, 1: OrphanParentLink}>|null
     */
    public function parentSwap(Orphan $orphan, string $relation, int $personId, bool $lock = false): ?array
    {
        $otherRelation = $relation === 'father' ? 'mother' : 'father';
        $links = OrphanParentLink::query()->where('orphan_id', $orphan->id)->get()->keyBy('relation');
        $other = $links->get($otherRelation);
        if (! $other || (int) $other->person_id !== $personId) {
            return null;
        }
        $current = $links->get($relation);
        if (! $current) {
            throw ValidationException::withMessages([$relation => 'هذا الشخص مربوط بهذا الملف '.($otherRelation === 'father' ? 'أبًا' : 'أمًّا')
                .'؛ اربط '.($otherRelation === 'father' ? 'الأب الصحيح' : 'الأم الصحيحة').' أولًا ثم اربط هذا الشخص '.($relation === 'father' ? 'أبًا' : 'أمًّا').'.']);
        }
        if ((int) $current->person_id === $personId) {
            return null;
        }
        $from = (int) $current->person_id;
        $files = DB::table('orphan_parent_links as a')->join('orphan_parent_links as b', 'b.orphan_id', '=', 'a.orphan_id')
            ->where('a.relation', $relation)->where('a.person_id', $from)->where('b.relation', $otherRelation)->where('b.person_id', $personId)
            ->pluck('a.orphan_id')->map(fn ($id): int => (int) $id)->push((int) $orphan->id)->unique()->values()->all();
        $rows = OrphanParentLink::query()->whereIn('orphan_id', $files)->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get()->groupBy('orphan_id');
        $pairs = [];
        foreach ($rows as $orphanId => $fileLinks) {
            $byRelation = $fileLinks->keyBy('relation');
            if ((int) $byRelation->get($relation)?->person_id === $from && (int) $byRelation->get($otherRelation)?->person_id === $personId) {
                $pair = [$byRelation->get($otherRelation), $byRelation->get($relation)];
                if ((int) $orphanId === (int) $orphan->id) {
                    array_unshift($pairs, $pair);
                } else {
                    $pairs[] = $pair;
                }
            }
        }
        if ($pairs === [] || (int) $pairs[0][0]->orphan_id !== (int) $orphan->id) {
            // This file's links changed between the read and the lock.
            throw ValidationException::withMessages([$relation => 'تغيّرت روابط الأب والأم لهذا الملف أثناء الحفظ؛ أعد فتح الملف وحاول مرة أخرى.']);
        }

        return $pairs;
    }

    /**
     * A person already linked under the other relation on another file is refused with the file references (the
     * rule of trigger 000043, checked first so the user reads why). Re-saving this file's link to the same person
     * is never refused, so the existing mixed links listed for the head stay editable.
     */
    private function assertOneRelation(Orphan $orphan, string $relation, int $personId): void
    {
        $current = DB::table('orphan_parent_links')->where('orphan_id', $orphan->id)->where('relation', $relation)->value('person_id');
        if ($current !== null && (int) $current === $personId) {
            return;
        }
        $otherRelation = $relation === 'father' ? 'mother' : 'father';
        $files = DB::table('orphan_parent_links as l')->join('orphans as o', 'o.id', '=', 'l.orphan_id')
            ->where('l.person_id', $personId)->where('l.relation', $otherRelation)->where('l.orphan_id', '<>', $orphan->id)
            ->orderBy('o.reference_no')->limit(5)->pluck('o.reference_no')->all();
        if ($files !== []) {
            throw ValidationException::withMessages([$relation => 'هذا الشخص مربوط '.($otherRelation === 'father' ? 'أبًا' : 'أمًّا')
                .' في ملفات أخرى ('.implode('، ', $files).')؛ لا يُربط الشخص نفسه أبًا وأمًّا. صحّح الربط في تلك الملفات أولًا.']);
        }
    }

    /** True when $e is the refusal of the one-relation trigger orphan_parent_links_one_relation (000043). */
    private static function isOneRelationViolation(QueryException $e): bool
    {
        return (string) ($e->errorInfo[0] ?? $e->getCode()) === '23514'
            && (str_contains($e->getMessage(), 'orphan_parent_links_one_relation') || str_contains($e->getMessage(), 'لا يُربط أبًا وأمًّا معًا'));
    }

    /**
     * The sorted ids of the files a swap changes (empty when it is not a swap).
     *
     * @param  list<array{0: OrphanParentLink, 1: OrphanParentLink}>|null  $swap
     * @return list<int>
     */
    public static function swapFiles(?array $swap): array
    {
        return self::sortedIds(array_map(fn (array $pair): int => (int) $pair[0]->orphan_id, $swap ?? []));
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return list<int>
     */
    private static function sortedIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return $ids;
    }

    /**
     * A sibling file past its final approval changes only by the head's decision (ORP-18): staff who swap from an
     * open file are sent to request it from the approved sibling file, where the head sees the whole family change.
     *
     * @param  list<array{0: OrphanParentLink, 1: OrphanParentLink}>  $swap
     */
    private function assertSwapAllowed(Orphan $orphan, array $swap, string $relation, User $actor): void
    {
        if ($this->access->canDecide($actor)) {
            return;
        }
        $protected = DB::table('orphans')->whereIn('id', array_diff(self::swapFiles($swap), [(int) $orphan->id]))
            ->whereNotIn('status', SensitiveChanges::OPEN_STATUSES)->orderBy('reference_no')->pluck('reference_no')->all();
        if ($protected !== []) {
            throw ValidationException::withMessages([$relation => 'التبديل يشمل ملفات أشقاء معتمدة ('.implode('، ', $protected)
                .')؛ قدّمه من أحد هذه الملفات ليعتمده رئيس القسم للعائلة كلها.']);
        }
    }

    /**
     * Swaps the father and mother on every file of the pair in one savepoint. The one-relation trigger (000043) is
     * deferred for the swap and made IMMEDIATE again before the savepoint ends, so a remaining conflict (a parent
     * linked under the other relation on a file that does not carry the same pair) is raised here, rolled back to the
     * savepoint and returned as a validation message naming those files, never at commit as a server error.
     *
     * @param  list<array{0: OrphanParentLink, 1: OrphanParentLink}>  $swap
     */
    private function swapParents(Orphan $orphan, string $relation, array $swap, array $data, User $actor): OrphanParentLink
    {
        $otherRelation = $swap[0][0]->relation;
        $chosen = (int) $swap[0][0]->person_id;
        $previous = (int) $swap[0][1]->person_id;
        $guarded = DB::getDriverName() === 'pgsql' && DB::table('pg_trigger')->where('tgname', 'orphan_parent_links_one_relation')->exists();
        $references = Orphan::query()->whereIn('id', self::swapFiles($swap))->pluck('reference_no', 'id');
        try {
            $link = DB::transaction(function () use ($orphan, $relation, $swap, $data, $actor, $otherRelation, $chosen, $previous, $guarded, $references): OrphanParentLink {
                if ($guarded) {
                    DB::statement('SET CONSTRAINTS orphan_parent_links_one_relation DEFERRED');
                }
                $files = count($swap);
                foreach ($swap as [$other, $current]) {
                    $isMain = (int) $current->orphan_id === (int) $orphan->id;
                    $reference = $references[$current->orphan_id] ?? null;
                    $other->forceFill(['person_id' => $previous, 'verification' => 'reported', 'verified_by' => null, 'verified_at' => null])->save();
                    $this->audit->recordRow('orphans.parent.linked', 'orphan_parent_links', $other->id, ['person_id' => $chosen],
                        ['orphan' => $reference, 'relation' => $otherRelation, 'person_id' => $previous, 'swapped_with' => $relation, 'swap_files' => $files], $actor->id, request()?->ip());
                    $current->forceFill(['person_id' => $chosen, 'verification' => 'reported', 'verified_by' => null, 'verified_at' => null,
                        ...($isMain ? ['basis' => $data['basis'] ?? 'declared', 'evidence_note' => $data['link_note'] ?? null] : [])])->save();
                    $this->audit->recordRow('orphans.parent.linked', 'orphan_parent_links', $current->id, ['person_id' => $previous],
                        ['orphan' => $reference, 'relation' => $relation, 'person_id' => $chosen, 'swapped_with' => $otherRelation, 'swap_files' => $files], $actor->id, request()?->ip());
                }
                if ($guarded) {
                    DB::statement('SET CONSTRAINTS orphan_parent_links_one_relation IMMEDIATE');
                }

                return $swap[0][1];
            });
        } catch (QueryException $e) {
            if (! self::isOneRelationViolation($e)) {
                throw $e;
            }
            $files = self::swapFiles($swap);
            $conflicts = DB::table('orphan_parent_links as l')->join('orphans as o', 'o.id', '=', 'l.orphan_id')->whereNotIn('l.orphan_id', $files)
                ->where(fn ($q) => $q->where(fn ($w) => $w->where('l.person_id', $chosen)->where('l.relation', $otherRelation))
                    ->orWhere(fn ($w) => $w->where('l.person_id', $previous)->where('l.relation', $relation)))
                ->orderBy('o.reference_no')->limit(5)->pluck('o.reference_no')->unique()->values()->all();
            throw ValidationException::withMessages([$relation => 'لا يكتمل التبديل: أحد الوالدين مربوط بالصفة الأخرى في ملفات لا تحمل الأبوين أنفسهم'
                .($conflicts !== [] ? ' ('.implode('، ', $conflicts).')' : '').'؛ صحّح ربط الوالدين في تلك الملفات أولًا. لم يتغير أي ملف.']);
        }
        // The swap moves a recorded death to the other relation, so each file's derived class is derived again.
        foreach (Orphan::query()->whereIn('id', array_diff(self::swapFiles($swap), [(int) $orphan->id]))->get() as $file) {
            $this->deriveClass($file);
        }
        $this->deriveClass($orphan);

        return $link;
    }

    /**
     * Orphanhood class derived from VERIFIED parental deaths only; anything else stays provisional and a manual class
     * set by staff is never overwritten.
     */
    public function deriveClass(Orphan $orphan): void
    {
        if ($orphan->class_basis === 'manual') {
            return;
        }
        $deaths = DB::table('orphan_parent_links as l')->join('persons as p', 'p.id', '=', 'l.person_id')->where('l.orphan_id', $orphan->id)
            ->where('p.vital_status', 'deceased')
            ->whereExists(fn ($q) => $q->from('person_status_events as e')->whereColumn('e.person_id', 'p.id')->where('e.status', 'deceased')->where('e.verification', 'verified'))
            ->pluck('l.relation')->all();
        $code = match (true) {
            in_array('father', $deaths, true) && in_array('mother', $deaths, true) => 'both_parents_deceased',
            in_array('father', $deaths, true) => 'father_deceased',
            in_array('mother', $deaths, true) => 'mother_deceased',
            default => null,
        };
        $value = $code ? DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')
            ->where('t.code', 'orphan_classifications')->where('v.code', $code)->where('v.is_active', true)->value('v.id') : null;
        $orphan->forceFill(['orphan_class_value_id' => $value ?? $orphan->orphan_class_value_id, 'class_basis' => $value ? 'derived' : 'provisional'])->save();
    }

    /** Opens a new address episode; the previous current address is closed, never overwritten. */
    public function saveAddress(array $owner, string $kind, array $data, int $actor): RegistryAddress
    {
        $geo = array_intersect_key($data, array_flip(['governorate_value_id', 'locality_value_id', 'area_value_id', 'subarea_value_id']));
        if ($kind === 'current') {
            RegistryAddress::query()->where($owner)->where('kind', 'current')->whereNull('ends_on')->update(['ends_on' => today()->toDateString(), 'updated_at' => now()]);
        }

        return RegistryAddress::create([...$owner, 'kind' => $kind, ...$geo, 'address_text' => $data['address_text'] ?? null, 'detail' => $data['detail'] ?? null,
            'shelter_type_value_id' => $data['shelter_type_value_id'] ?? null, 'tenure_value_id' => $data['tenure_value_id'] ?? null,
            'stability' => $data['stability'] ?? null, 'services_access' => $data['services_access'] ?? null,
            'starts_on' => $data['starts_on'] ?? ($kind === 'current' ? today()->toDateString() : null), 'ends_on' => $data['ends_on'] ?? null, 'created_by' => $actor]);
    }

    /**
     * Section completeness of the child file: each section is complete, partial or missing — nothing is invented.
     * Optional sections (health) never lower the score when not provided.
     *
     * @return array<string, string>
     */
    public function refreshCompleteness(Orphan $orphan): array
    {
        $person = Person::findOrFail($orphan->person_id);
        $parents = DB::table('orphan_parent_links')->where('orphan_id', $orphan->id)->pluck('relation')->all();
        $links = DB::table('orphan_guardianships')->where('orphan_id', $orphan->id)->whereIn('status', OrphanGuardianship::LIVE)->pluck('status')->all();
        $basicFields = [$person->first_name, $person->father_name, $person->family_name, $person->date_of_birth, $person->sex];
        $sections = [
            'basic' => in_array(null, $basicFields, true) || $person->id_format !== 'valid_format' ? (count(array_filter($basicFields)) >= 2 ? 'partial' : 'missing') : 'complete',
            'family' => count($parents) === 2 ? 'complete' : ($parents === [] ? 'missing' : 'partial'),
            'guardian' => in_array('approved', $links, true) ? 'complete' : ($links === [] ? 'missing' : 'partial'),
            // The child's own current address, or that of the household the child currently lives in.
            'residence' => DB::table('registry_addresses')->where('kind', 'current')->whereNull('ends_on')->where(fn ($q) => $q->where('person_id', $person->id)
                ->orWhereIn('household_id', DB::table('household_members')->where('person_id', $person->id)->whereNull('ends_on')->select('household_id')))->exists() ? 'complete' : 'missing',
            'education' => DB::table('education_records')->where('orphan_id', $orphan->id)->exists() ? 'complete' : 'missing',
            'assessment' => DB::table('social_assessments')->where('orphan_id', $orphan->id)->where('status', 'completed')->exists() ? 'complete' : 'missing',
        ];
        $score = (int) round(array_sum(array_map(fn (string $s): float => ['complete' => 1, 'partial' => 0.5, 'missing' => 0][$s], $sections)) / count($sections) * 100);
        $orphan->forceFill(['completeness' => $score, 'completeness_sections' => $sections])->saveQuietly();

        return $sections;
    }
}
