<?php

namespace App\Services\Orphans;

use App\Models\Guardian;
use App\Models\GuardianApplication;
use App\Models\GuardianApplicationChild;
use App\Models\OrphanChild;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

final class GuardianRegistrationService
{
    public function __construct(
        private readonly GuardianRegistryClient $registry,
        private readonly OrphanAccess $access,
        private readonly ChildVerificationEvaluator $evaluator,
    ) {}

    public function createApplication(Guardian $guardian, string $deceasedId, string $relationship): GuardianApplication
    {
        return DB::transaction(function () use ($guardian, $deceasedId, $relationship): GuardianApplication {
            $guardian = Guardian::whereKey($guardian->id)->lockForUpdate()->firstOrFail();
            abort_unless($guardian->is_active, 403);
            $hash = RegistryIdentity::hash($deceasedId);
            if (hash_equals($guardian->national_id_hash, $hash)) {
                throw ValidationException::withMessages(['deceased_national_id' => 'هوية المتوفى يجب أن تختلف عن هوية مقدم الطلب.']);
            }
            if ($guardian->applications()->where('deceased_id_hash', $hash)->exists()) {
                throw ValidationException::withMessages(['deceased_national_id' => 'يوجد طلب سابق مرتبط بهذا المتوفى؛ تابع الطلب الموجود.']);
            }
            $application = $guardian->applications()->create([
                'public_id' => (string) Str::ulid(), 'deceased_national_id' => trim($deceasedId),
                'deceased_id_hash' => $hash, 'relationship' => $relationship,
                'status' => 'awaiting_api', 'verification_status' => 'unavailable',
            ]);
            $this->event($application, 'submitted');

            return $application;
        });
    }

    public function verify(GuardianApplication $application, ?User $staff = null): void
    {
        if ($staff) {
            $this->access->assert($staff, 'verify');
        }
        $application = DB::transaction(function () use ($application, $staff): GuardianApplication {
            $current = GuardianApplication::whereKey($application->id)->lockForUpdate()->firstOrFail();
            if (! in_array($current->status, ['awaiting_api', 'needs_changes', 'verification_failed', 'pending_review'], true)) {
                throw ValidationException::withMessages(['application' => 'الطلب غير متاح للتحقق في حالته الحالية.']);
            }
            abort_unless($current->guardian->is_active, 403);
            $this->event($current, 'verification_requested', $staff, ['previous_verification' => $current->verification_payload]);
            $current->forceFill([
                'status' => 'verifying', 'verification_status' => 'pending', 'verification_payload' => null,
                'verified_at' => null, 'verification_revision' => $current->verification_revision + 1,
            ])->save();

            return $current;
        });

        $snapshot = null;
        $childRows = [];
        $started = hrtime(true);
        $outcome = 'error';
        try {
            $result = $this->registry->verify($application->guardian, $application);
            $outcome = $result->available ? 'available' : 'unavailable';
            if ($result->available) {
                $snapshot = $this->validatedSnapshot($application, [
                    'guardian_eligible' => $result->guardianEligible,
                    'guardian_national_id' => $result->guardianNationalId,
                    'deceased_national_id' => $result->deceasedNationalId,
                    'guardian_father_national_id' => $result->guardianFatherNationalId,
                    'guardian_mother_national_id' => $result->guardianMotherNationalId,
                    'source' => $result->source, 'reference' => $result->reference, 'children' => $result->children,
                ]);
                $childRows = $snapshot['guardian_eligible'] ? $this->evaluator->evaluate($application, $snapshot) : [];
            }
        } catch (Throwable) {
            // Provider errors and malformed responses never become successful eligibility checks.
            $snapshot = null;
            $childRows = [];
        }
        RegistryRequests::record(class_basename($this->registry), 'guardian_claim', 'guardian', null, $outcome, $staff?->id,
            $staff ? null : $application->guardian_id, $application->id, (int) ((hrtime(true) - $started) / 1_000_000));

        DB::transaction(function () use ($application, $snapshot, $childRows, $staff): void {
            $current = GuardianApplication::whereKey($application->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== 'verifying' || $current->verification_revision !== $application->verification_revision) {
                return;
            }
            $current->forceFill([
                'status' => $snapshot === null ? 'awaiting_api' : ($snapshot['guardian_eligible'] ? 'pending_review' : 'verification_failed'),
                'verification_status' => $snapshot === null ? 'unavailable' : ($snapshot['guardian_eligible'] ? 'verified' : 'ineligible'),
                'verification_payload' => $snapshot, 'verified_at' => $snapshot === null ? null : now(),
            ])->save();
            foreach ($childRows as $row) {
                GuardianApplicationChild::create($row + [
                    'guardian_application_id' => $current->id,
                    'verification_revision' => $current->verification_revision,
                ]);
            }
            $this->event($current, $snapshot === null ? 'verification_unavailable' : 'verification_completed', $staff, [
                'verification_status' => $current->verification_status,
                'children_results' => array_count_values(array_column($childRows, 'result')),
            ]);
        });
    }

    public function review(GuardianApplication $application, User $staff, string $decision, ?string $note): int
    {
        if (! in_array($decision, ['approve', 'reject', 'needs_changes'], true)) {
            throw ValidationException::withMessages(['decision' => 'قرار غير معروف.']);
        }
        $this->access->assert($staff, match ($decision) {
            'approve' => 'approve',
            'reject' => 'reject',
            default => 'review',
        });
        try {
            return DB::transaction(function () use ($application, $staff, $decision, $note): int {
                $current = GuardianApplication::whereKey($application->id)->lockForUpdate()->firstOrFail();
                $guardian = Guardian::whereKey($current->guardian_id)->lockForUpdate()->firstOrFail();
                abort_unless($guardian->is_active, 403);
                $staffIdentity = DB::table('employees')->where('user_id', $staff->id)->whereNull('deleted_at')->value('national_id');
                if ($staffIdentity && hash_equals($guardian->national_id_hash, RegistryIdentity::hash($staffIdentity))) {
                    abort(403, 'لا يجوز مراجعة طلبك الشخصي.');
                }
                if ($current->status === 'approved' && $decision === 'approve') {
                    return $current->imported_children_count;
                }
                if (in_array($current->status, ['approved', 'rejected', 'verifying'], true)) {
                    throw ValidationException::withMessages(['application' => 'الطلب ليس متاحًا لهذا القرار.']);
                }
                $count = 0;
                if ($decision === 'approve') {
                    if ($current->status !== 'pending_review' || $current->verification_status !== 'verified' || ! $current->verified_at || ! $current->verification_payload) {
                        throw ValidationException::withMessages(['application' => 'يلزم تحقق ناجح من أهلية الوصي قبل الاعتماد واستيراد الأطفال.']);
                    }
                    $snapshot = $this->validatedSnapshot($current, $current->verification_payload);
                    if (! $snapshot['guardian_eligible']) {
                        throw ValidationException::withMessages(['application' => 'نتيجة التحقق لا تثبت أهلية الوصي.']);
                    }
                    // Only children whose own result is "verified" go forward; the others keep their reasons (AT-05).
                    // Staff approval no longer links them: it opens a file application that the executive director
                    // decides per child (centralization decision), and the guardianship starts with that decision.
                    $verifiedRows = GuardianApplicationChild::where('guardian_application_id', $current->id)
                        ->where('verification_revision', $current->verification_revision)
                        ->where('result', 'verified')->where('linked', false)
                        ->lockForUpdate()->get();
                    $children = [];
                    foreach ($verifiedRows as $row) {
                        $child = $this->findOrCreateChild($row->source, [
                            'source_child_id' => $row->source_child_id, 'full_name' => $row->full_name,
                            'date_of_birth' => $row->date_of_birth->format('Y-m-d'), 'national_id' => $row->national_id,
                        ]);
                        $row->forceFill(['orphan_child_id' => $child->id, 'linked' => true, 'decided_by' => $staff->id, 'decided_at' => now()])->save();
                        // Every child keeps a direct guardian link from creation: this claimant's link stays
                        // «بانتظار التحقق» until it is approved for this child alone (no portal access before that).
                        app(GuardianLinks::class)->claimFromWorkflow($child->id, $guardian->id, $staff->id, 'portal', ['guardian_application_id' => $current->id], $row->kinship, $row->authority_kind);
                        $children[] = $child;
                        $count++;
                    }
                    if ($children !== []) {
                        app(OrphanApplications::class)->fromRegistryClaim($staff, $current, $guardian, $children);
                    }
                }
                if ($decision !== 'approve' && mb_strlen(trim((string) $note)) < 5) {
                    throw ValidationException::withMessages(['note' => 'حدد سبب الرفض أو بنود الاستكمال.']);
                }
                $current->forceFill([
                    'status' => $decision === 'approve' ? 'approved' : ($decision === 'reject' ? 'rejected' : 'needs_changes'),
                    'review_note' => $note, 'reviewed_by' => $staff->id, 'reviewed_at' => now(),
                    'imported_children_count' => $count,
                ])->save();
                $this->event($current, $current->status, $staff, ['children_count' => $count, 'note' => $note]);

                return $count;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['application' => 'تغير سجل طفل أثناء الاعتماد؛ أعد المحاولة لمطابقة الملف الموجود.']);
        }
    }

    private function findOrCreateChild(string $source, array $data): OrphanChild
    {
        $hash = empty($data['national_id']) ? null : RegistryIdentity::hash($data['national_id']);
        $bySource = OrphanChild::where('source', $source)->where('source_child_id', $data['source_child_id'])->lockForUpdate()->first();
        $byIdentity = $hash ? OrphanChild::where('national_id_hash', $hash)->lockForUpdate()->first() : null;
        if ($bySource && $byIdentity && $bySource->id !== $byIdentity->id) {
            throw ValidationException::withMessages(['application' => 'يوجد تعارض بين هوية الطفل ومرجع الخدمة؛ يلزم التدقيق دون دمج آلي.']);
        }
        $existing = $bySource ?? $byIdentity;
        if ($existing) {
            if ($existing->date_of_birth->format('Y-m-d') !== $data['date_of_birth']
                || $existing->full_name !== $data['full_name']
                || ($existing->national_id_hash && $hash && ! hash_equals($existing->national_id_hash, $hash))) {
                throw ValidationException::withMessages(['application' => 'بيانات الطفل تختلف عن ملفه الموجود؛ يلزم التدقيق دون الكتابة فوقه.']);
            }

            return $existing;
        }

        return OrphanChild::create([
            'public_id' => (string) Str::ulid(), 'source' => $source, 'source_child_id' => $data['source_child_id'],
            'national_id' => $data['national_id'] ?? null, 'national_id_hash' => $hash,
            'full_name' => $data['full_name'], 'date_of_birth' => $data['date_of_birth'], 'status' => 'applicant',
            'verification_status' => 'api_verified', 'registered_at' => now(),
        ]);
    }

    private function validatedSnapshot(GuardianApplication $application, array $snapshot): array
    {
        $snapshot = Validator::make($snapshot, [
            'guardian_eligible' => ['required', 'boolean'], 'guardian_national_id' => ['required', 'string', 'max:40'],
            'deceased_national_id' => ['required', 'string', 'max:40'],
            'source' => ['required', 'string', 'max:80'], 'reference' => ['required', 'string', 'max:200'],
            'guardian_father_national_id' => ['nullable', 'string', 'max:40'],
            'guardian_mother_national_id' => ['nullable', 'string', 'max:40'],
            'children' => ['present', 'array', 'max:500'],
            'children.*' => ['array:source_child_id,full_name,date_of_birth,national_id,father_national_id,mother_national_id,father_death_confirmed,father_father_national_id,father_mother_national_id,mother_father_national_id,mother_mother_national_id,authority'],
            'children.*.source_child_id' => ['required', 'string', 'max:120', 'distinct'],
            'children.*.full_name' => ['required', 'string', 'max:200'],
            'children.*.date_of_birth' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.CarbonImmutable::today(config('orphans.timezone'))->toDateString()],
            'children.*.national_id' => ['nullable', 'string', 'max:40', 'distinct'],
            'children.*.father_national_id' => ['nullable', 'string', 'max:40'],
            'children.*.mother_national_id' => ['nullable', 'string', 'max:40'],
            'children.*.father_death_confirmed' => ['nullable', 'boolean'],
            'children.*.father_father_national_id' => ['nullable', 'string', 'max:40'],
            'children.*.father_mother_national_id' => ['nullable', 'string', 'max:40'],
            'children.*.mother_father_national_id' => ['nullable', 'string', 'max:40'],
            'children.*.mother_mother_national_id' => ['nullable', 'string', 'max:40'],
            'children.*.authority' => ['nullable', 'array:kind,reference,valid_until'],
            'children.*.authority.kind' => ['nullable', Rule::in(array_keys(array_diff_key(ChildVerificationEvaluator::authorityKinds(), ['none' => true])))],
            'children.*.authority.reference' => ['nullable', 'string', 'max:200'],
            'children.*.authority.valid_until' => ['nullable', 'date_format:Y-m-d'],
        ])->validate();
        if (! hash_equals($application->guardian->national_id_hash, RegistryIdentity::hash($snapshot['guardian_national_id']))
            || ! hash_equals($application->deceased_id_hash, RegistryIdentity::hash($snapshot['deceased_national_id']))) {
            throw ValidationException::withMessages(['application' => 'نتيجة الخدمة لا تطابق الوصي والمتوفى في الطلب.']);
        }

        return $snapshot;
    }

    public function event(?GuardianApplication $application, string $action, ?User $staff = null, array $details = []): void
    {
        DB::table('orphan_registration_events')->insert([
            'guardian_application_id' => $application?->id, 'guardian_id' => $staff ? null : $application?->guardian_id,
            'staff_user_id' => $staff?->id, 'action' => $action,
            'details_ciphertext' => $details ? Crypt::encryptString(json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) : null,
            'created_at' => now(),
        ]);
    }
}
