<?php

namespace App\Services\Orphans;

use App\Models\Guardian;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Notifications\NotificationCenter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Documents of the orphans files. Each file is checked by real content type and size, encrypted before it touches
 * the disk, and served only through a short-lived signed link to a user who may read that owner; medical reports
 * also need the sensitive-data capability. A re-upload is a new version: earlier versions and decisions stay.
 */
final class OrphanDocuments
{
    public const MAX_KB = 10240;

    public const MIMES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public const STATUS_LABELS = ['pending' => 'بانتظار المراجعة', 'accepted' => 'مقبول', 'rejected' => 'مرفوض', 'expired' => 'منتهي الصلاحية'];

    /** Document types whose content is health data. */
    private const SENSITIVE_TYPES = ['medical_report'];

    private ?int $alertDays = null;

    public function __construct(private readonly OrphanAccess $access, private readonly AuditService $audit) {}

    public function store(User|Guardian $uploader, string $ownerType, int $ownerId, int $typeId, UploadedFile $file, array $meta = []): int
    {
        if ($uploader instanceof User) {
            $this->access->assert($uploader, 'records');
        }
        if (! $file->isValid() || $file->getSize() > self::MAX_KB * 1024 || $file->getSize() === 0) {
            throw ValidationException::withMessages(['file' => 'الملف غير صالح أو يتجاوز 10 ميجابايت.']);
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        if (! isset(self::MIMES[$mime])) {
            throw ValidationException::withMessages(['file' => 'يُقبل PDF أو صورة JPG/PNG/WEBP فقط (يُفحص المحتوى الفعلي لا الامتداد).']);
        }
        $typeCode = DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')
            ->where('t.code', 'orphan_document_types')->where('v.id', $typeId)->where('v.is_active', true)->value('v.code');
        if (! $typeCode) {
            throw ValidationException::withMessages(['document_type_value_id' => 'نوع المستند غير متاح.']);
        }
        $content = (string) file_get_contents($file->getRealPath());
        $path = 'orphans/'.$ownerType.'/'.Str::ulid().'.bin';

        return DB::transaction(function () use ($uploader, $ownerType, $ownerId, $typeId, $file, $meta, $mime, $content, $path, $typeCode): int {
            $previous = DB::table('orphan_documents')->where('owner_type', $ownerType)->where('owner_id', $ownerId)
                ->where('document_type_value_id', $typeId)->where('is_current', true)->lockForUpdate()->first();
            Storage::disk('local')->put($path, Crypt::encryptString(base64_encode($content)));
            if ($previous) {
                DB::table('orphan_documents')->where('id', $previous->id)->update(['is_current' => false, 'updated_at' => now()]);
            }
            $id = DB::table('orphan_documents')->insertGetId([
                'public_id' => (string) Str::ulid(), 'owner_type' => $ownerType, 'owner_id' => $ownerId, 'document_type_value_id' => $typeId,
                'document_no' => ! empty($meta['document_no']) ? Crypt::encryptString((string) $meta['document_no']) : null,
                'issued_on' => $meta['issued_on'] ?? null, 'expires_on' => $meta['expires_on'] ?? null,
                'version' => $previous ? $previous->version + 1 : 1, 'supersedes_id' => $previous?->id, 'path' => $path,
                'original_name' => Crypt::encryptString(mb_substr($file->getClientOriginalName(), 0, 200)), 'mime' => $mime,
                'size_bytes' => strlen($content), 'sha256' => hash('sha256', $content), 'status' => 'pending', 'is_current' => true,
                'uploaded_by' => $uploader instanceof User ? $uploader->id : null, 'uploaded_by_guardian' => $uploader instanceof Guardian ? $uploader->id : null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->recordRow('orphans.document.uploaded', 'orphan_documents', $id, null, ['owner' => $ownerType, 'type' => $typeCode, 'version' => $previous ? $previous->version + 1 : 1],
                $uploader instanceof User ? $uploader->id : null, request()?->ip());

            return $id;
        });
    }

    public function review(User $actor, int $documentId, string $decision, ?string $note): void
    {
        $this->access->assert($actor, 'verify');
        if (! in_array($decision, ['accepted', 'rejected'], true)) {
            throw ValidationException::withMessages(['decision' => 'قرار غير معروف.']);
        }
        if ($decision === 'rejected' && mb_strlen(trim((string) $note)) < 5) {
            throw ValidationException::withMessages(['note' => 'اذكر سبب رفض المستند ليصل لمقدمه.']);
        }
        DB::transaction(function () use ($actor, $documentId, $decision, $note): void {
            $document = DB::table('orphan_documents')->where('id', $documentId)->lockForUpdate()->first();
            abort_unless($document, 404);
            if (! $document->is_current) {
                throw ValidationException::withMessages(['document' => 'هذه نسخة سابقة؛ القرار يكون على النسخة الحالية.']);
            }
            abort_if((int) $document->uploaded_by === (int) $actor->id && ! $this->access->isHead($actor), 403, 'لا يراجع رافع المستند مستنده.');
            DB::table('orphan_documents')->where('id', $documentId)->update(['status' => $decision, 'rejection_reason' => $decision === 'rejected' ? $note : null,
                'reviewer_note' => $decision === 'accepted' ? $note : null, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'updated_at' => now()]);
            $this->audit->recordRow('orphans.document.'.$decision, 'orphan_documents', $documentId, ['status' => $document->status], ['status' => $decision], $actor->id, request()?->ip());
        });
    }

    /** Whether the user may open this document. */
    public function canRead(User $user, object $document): bool
    {
        if (! $this->access->allows($user, 'view')) {
            return false;
        }
        $code = DB::table('master_data_values')->where('id', $document->document_type_value_id)->value('code');

        return ! in_array($code, self::SENSITIVE_TYPES, true) || $this->access->allows($user, 'sensitive');
    }

    /** @return array{content: string, mime: string, name: string} */
    public function read(object $document): array
    {
        $content = base64_decode(Crypt::decryptString((string) Storage::disk('local')->get($document->path)), true);
        if ($content === false || ! hash_equals($document->sha256, hash('sha256', $content))) {
            abort(409, 'تعذر التحقق من سلامة الملف المخزن.');
        }

        return ['content' => $content, 'mime' => $document->mime, 'name' => 'document-'.$document->public_id.'.'.(self::MIMES[$document->mime] ?? 'bin')];
    }

    /**
     * Required/optional documents of a family file and the state of each, per owner (household, guardian, each parent
     * and child). Conditions: a deceased parent needs a death certificate, a claimed legal authority needs its order,
     * a chronic illness or disability needs a medical report.
     *
     * @return list<array{owner_type: string, owner_id: int, owner: string, type_id: int, type: string, requirement: string, status: string, document: ?array}>
     */
    public function checklist(int $householdId): array
    {
        $household = DB::table('orphan_households')->where('id', $householdId)->first();
        $requirements = DB::table('orphan_document_requirements as r')->join('master_data_values as v', 'v.id', '=', 'r.document_type_value_id')
            ->where('r.is_active', true)->get(['r.*', 'v.name_ar as type_name']);
        $owners = [['household', $householdId, 'الأسرة', []]];
        if ($household->primary_guardian_id) {
            $authority = $household->guardian_authority_value_id ? DB::table('master_data_values')->where('id', $household->guardian_authority_value_id)->value('code') : null;
            $owners[] = ['guardian', (int) $household->primary_guardian_id, 'الوصي', ['authority' => $authority]];
        }
        $parents = DB::table('orphan_household_parents as l')->join('orphan_parents as p', 'p.id', '=', 'l.parent_id')
            ->leftJoin('master_data_values as vs', 'vs.id', '=', 'p.vital_status_value_id')->where('l.household_id', $householdId)->get(['p.id', 'p.role', 'vs.metadata']);
        foreach ($parents as $parent) {
            $owners[] = [$parent->role, (int) $parent->id, $parent->role === 'father' ? 'الأب' : 'الأم', ['vital' => ! empty(json_decode((string) $parent->metadata, true)['deceased']) ? 'deceased' : 'alive']];
        }
        $children = DB::table('orphan_children as c')->leftJoin('orphan_health_records as h', fn ($j) => $j->on('h.orphan_child_id', '=', 'c.id')->where('h.is_current', true))
            ->where('c.household_id', $householdId)->get(['c.id', 'c.full_name', 'h.has_chronic', 'h.has_disability']);
        foreach ($children as $child) {
            $owners[] = ['child', (int) $child->id, $this->childName($child->full_name), ['health_claim' => (bool) ($child->has_chronic || $child->has_disability)]];
        }
        $documents = DB::table('orphan_documents')->where('is_current', true)
            ->where(function ($q) use ($owners): void {
                foreach ($owners as [$type, $id]) {
                    $q->orWhere(fn ($w) => $w->where('owner_type', $type)->where('owner_id', $id));
                }
            })->get()->keyBy(fn ($d) => $d->owner_type.':'.$d->owner_id.':'.$d->document_type_value_id);
        $today = now(config('orphans.timezone'))->toDateString();
        $soon = now(config('orphans.timezone'))->addDays($this->expiryAlertDays())->toDateString();
        $list = [];
        foreach ($owners as [$type, $id, $label, $facts]) {
            foreach ($requirements->where('applies_to', $type) as $requirement) {
                if (! $this->conditionMet(json_decode((string) $requirement->condition, true) ?: [], $facts)) {
                    continue;
                }
                $document = $documents->get($type.':'.$id.':'.$requirement->document_type_value_id);
                $expiresOn = $document?->expires_on ? substr((string) $document->expires_on, 0, 10) : null;
                // An expired document stops counting as accepted proof; it stays in the history and a new version replaces it.
                $expired = $expiresOn !== null && $expiresOn < $today && in_array($document->status, ['pending', 'accepted'], true);
                $list[] = ['owner_type' => $type, 'owner_id' => $id, 'owner' => $label, 'type_id' => (int) $requirement->document_type_value_id,
                    'type' => $requirement->type_name, 'requirement' => $requirement->requirement, 'note' => $requirement->note,
                    'status' => $document ? ($expired ? 'expired' : $document->status) : 'missing',
                    'document' => $document ? ['id' => (int) $document->id, 'version' => (int) $document->version, 'mime' => $document->mime,
                        'uploaded_at' => (string) $document->created_at, 'rejection_reason' => $document->rejection_reason, 'expires_on' => $expiresOn,
                        'expiring' => ! $expired && $expiresOn !== null && $expiresOn <= $soon] : null];
            }
        }

        return $list;
    }

    /** Days before expiry when staff are alerted (Q29); the setting exists once the phase 1 migration has run. */
    public function expiryAlertDays(): int
    {
        return $this->alertDays ??= DB::getSchemaBuilder()->hasColumn('orphans_module_settings', 'document_expiry_alert_days')
            ? (int) (DB::table('orphans_module_settings')->where('id', 1)->value('document_expiry_alert_days') ?? 30) : 30;
    }

    /**
     * Alerts the head and staff holding the verify capability about current documents that expire within the alert
     * window, once before expiry and once when expired (per document and per recipient). Alerts only: no document,
     * file or payment changes here.
     *
     * @return array{soon: int, expired: int, notified: int, recipients: int}
     */
    public function sendExpiryAlerts(NotificationCenter $notifications, bool $dryRun = false): array
    {
        $recipients = $this->access->departmentUsersWith('verify');
        $counts = ['soon' => 0, 'expired' => 0, 'notified' => 0, 'recipients' => count($recipients)];
        foreach ($this->dueForRenewal() as $document) {
            $counts[$document['stage']]++;
            if ($dryRun || $recipients === [] || $document['household'] === null) {
                continue;
            }
            $what = $document['type'].' ('.$document['owner'].') — ملف '.$document['household'];
            $counts['notified'] += $notifications->notifyMany($recipients, 'orphans_document_expiring', [
                'title' => $document['stage'] === 'expired' ? 'انتهت صلاحية مستند: '.$what : 'مستند ينتهي في '.$document['expires_on'].': '.$what,
                'body' => $document['stage'] === 'expired' ? 'لم يعد مقبولًا إثباتًا؛ اطلب نسخة سارية.' : 'اطلب نسخة سارية قبل الانتهاء.',
                'url' => '/orphans/households/'.$document['household_public_id'], 'dedupe' => 'orphan_doc_expiry:'.$document['id'].':'.$document['stage']]);
        }

        return $counts;
    }

    /**
     * Current documents (pending or accepted) expiring within the alert window or already expired, earliest first.
     *
     * @return list<array{id: int, stage: string, type: string, owner: string, expires_on: string, household: ?string, household_public_id: ?string}>
     */
    public function dueForRenewal(int $limit = 500): array
    {
        $today = now(config('orphans.timezone'))->toDateString();
        $until = now(config('orphans.timezone'))->addDays($this->expiryAlertDays())->toDateString();
        $owners = ['household' => 'الأسرة', 'guardian' => 'الوصي', 'father' => 'الأب', 'mother' => 'الأم', 'child' => 'أحد الأطفال'];

        return DB::table('orphan_documents as d')->join('master_data_values as v', 'v.id', '=', 'd.document_type_value_id')
            ->where('d.is_current', true)->whereIn('d.status', ['pending', 'accepted'])->whereNotNull('d.expires_on')->where('d.expires_on', '<=', $until)
            ->orderBy('d.expires_on')->limit($limit)->get(['d.id', 'd.owner_type', 'd.owner_id', 'd.expires_on', 'v.name_ar as type'])
            ->map(function (object $document) use ($today, $owners): array {
                $expiresOn = substr((string) $document->expires_on, 0, 10);
                $household = $this->householdOf((string) $document->owner_type, (int) $document->owner_id);

                return ['id' => (int) $document->id, 'stage' => $expiresOn < $today ? 'expired' : 'soon', 'type' => $document->type, 'owner' => $owners[$document->owner_type] ?? '',
                    'expires_on' => $expiresOn, 'household' => $household?->reference_no, 'household_public_id' => $household?->public_id];
            })->all();
    }

    /** The family file a document belongs to (a guardian or parent shared by several families: the latest one). */
    private function householdOf(string $ownerType, int $ownerId): ?object
    {
        $query = DB::table('orphan_households as h');
        match ($ownerType) {
            'household' => $query->where('h.id', $ownerId),
            'guardian' => $query->where('h.primary_guardian_id', $ownerId),
            'child' => $query->whereIn('h.id', DB::table('orphan_children')->where('id', $ownerId)->select('household_id')),
            default => $query->whereIn('h.id', DB::table('orphan_household_parents')->where('parent_id', $ownerId)->select('household_id')),
        };

        return $query->orderByDesc('h.id')->first(['h.reference_no', 'h.public_id']);
    }

    /** @param array<string, mixed> $condition @param array<string, mixed> $facts */
    private function conditionMet(array $condition, array $facts): bool
    {
        foreach ($condition as $key => $expected) {
            $actual = $facts[$key] ?? null;
            $ok = is_array($expected) ? in_array($actual, $expected, true) : $actual === $expected;
            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    private function childName(?string $cipher): string
    {
        try {
            return $cipher ? Crypt::decryptString($cipher) : 'طفل';
        } catch (\Throwable) {
            return 'طفل';
        }
    }
}
