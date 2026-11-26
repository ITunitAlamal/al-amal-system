<?php

namespace App\Http\Controllers\Orphans;

use App\Http\Controllers\Controller;
use App\Models\Orphan;
use App\Models\OrphanGuardian;
use App\Models\OrphanGuardianship;
use App\Models\Person;
use App\Services\AuditService;
use App\Services\Configuration\GazaAddress;
use App\Services\Orphans\Identity;
use App\Services\Orphans\OrphanAccess;
use App\Services\Orphans\Persons;
use App\Services\Orphans\Profiles;
use App\Services\Orphans\Registry;
use App\Services\Orphans\RegistryQueries;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** «إدارة الأيتام والأوصياء»: the unified registry, the files and their sections. */
final class RegistryController extends Controller
{
    public function __construct(
        private readonly OrphanAccess $access,
        private readonly Registry $registry,
        private readonly Persons $persons,
        private readonly Profiles $profiles,
        private readonly RegistryQueries $queries,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): Response
    {
        $this->access->assertView($request->user());
        $filters = RegistryQueries::filters($request->query());
        $view = ($filters['view'] ?? 'orphans') === 'guardians' ? 'guardians' : 'orphans';
        $rows = $view === 'guardians' ? $this->queries->guardians($filters) : $this->queries->orphans($filters);
        $rows->through(fn ($row) => [...(array) $row, 'id_masked' => Identity::mask($row->id_last4), 'id_last4' => null,
            'age' => $row->date_of_birth ?? null ? Carbon::parse($row->date_of_birth)->age : null]);
        $base = ['view' => $view] + array_intersect_key($filters, array_flip(['q']));

        return Inertia::render('Orphans/Registry/Index', [
            'view' => $view, 'filters' => $filters, 'rows' => $rows, 'options' => $this->queries->options(),
            'counts' => ['orphans' => $this->queries->orphanQuery(array_intersect_key($filters, array_flip(['q'])))->count(),
                'guardians' => $this->queries->guardianQuery(array_intersect_key($filters, array_flip(['q'])))->count()],
            'abilities' => $this->access->abilities($request->user()),
            'importUrl' => Route::has('orphans.import.index') ? route('orphans.import.index', [], false) : null,
        ]);
    }

    public function preview(Request $request, string $type, string $publicId): JsonResponse
    {
        $this->access->assertView($request->user());
        abort_unless(in_array($type, ['orphan', 'guardian'], true), 404);

        return response()->json($this->profiles->preview($type, $publicId, $request->user()));
    }

    /** Exact identity and Arabic-name collisions, shown before saving; nothing is merged. */
    public function duplicates(Request $request): JsonResponse
    {
        $this->access->assertView($request->user());
        $data = $request->validate(['id_number' => 'nullable|string|max:40', 'first_name' => 'nullable|string|max:100', 'father_name' => 'nullable|string|max:100',
            'grandfather_name' => 'nullable|string|max:100', 'family_name' => 'nullable|string|max:100', 'date_of_birth' => 'nullable|date', 'except' => 'nullable|string|max:40']);
        $except = ! empty($data['except']) ? Person::query()->where('public_id', $data['except'])->value('id') : null;

        return response()->json(['matches' => $this->persons->duplicates($data, $except)]);
    }

    /** Guardian lookup for the create flow: exact ID or name, masked. */
    public function findGuardians(Request $request): JsonResponse
    {
        $this->access->assertView($request->user());
        $term = trim((string) $request->query('q', ''));
        if (mb_strlen($term) < 2) {
            return response()->json(['data' => []]);
        }
        $rows = $this->queries->guardianQuery(['q' => $term])->orderBy('p.full_name')->limit(15)
            ->get(['gg.public_id', 'gg.reference_no', 'p.full_name', 'p.id_last4', 'p.id_format']);

        return response()->json(['data' => $rows->map(fn ($r): array => ['public_id' => $r->public_id, 'reference_no' => $r->reference_no, 'full_name' => $r->full_name,
            'id_masked' => Identity::mask($r->id_last4)])->all()]);
    }

    /** Orphan lookup for pickers (sponsorships, assistance, appeals): exact ID, reference or name; masked. */
    public function findOrphans(Request $request): JsonResponse
    {
        $this->access->assertView($request->user());
        $term = trim((string) $request->query('q', ''));
        if (mb_strlen($term) < 2) {
            return response()->json(['data' => []]);
        }
        $rows = $this->queries->orphanQuery(['q' => $term])->orderBy('p.full_name')->limit(15)->get(['o.public_id', 'o.reference_no', 'p.full_name', 'p.id_last4', 'p.date_of_birth']);

        return response()->json(['data' => $rows->map(fn ($r): array => ['public_id' => $r->public_id, 'reference_no' => $r->reference_no, 'full_name' => $r->full_name,
            'id_masked' => Identity::mask($r->id_last4), 'date_of_birth' => $r->date_of_birth])->all()]);
    }

    public function createGuardian(Request $request): Response
    {
        $this->access->assertEdit($request->user());

        return Inertia::render('Orphans/Registry/CreateGuardian', ['options' => $this->queries->options()]);
    }

    public function storeGuardian(Request $request, GazaAddress $address): RedirectResponse
    {
        $this->access->assertEdit($request->user());
        $data = $request->validate([...Persons::rules('person.'), 'person_public_id' => 'nullable|string|exists:persons,public_id', 'notes' => 'nullable|string|max:2000',
            'address' => 'nullable|array', ...GazaAddress::rules('address.')], [], Persons::attributes('person.'));
        $personId = ! empty($data['person_public_id']) ? Person::query()->where('public_id', $data['person_public_id'])->value('id') : null;
        $guardian = $this->registry->createGuardian($data['person'] ?? [], $personId, ['notes' => $data['notes'] ?? null,
            'address' => $this->address($address, $data['address'] ?? null, 'address.')], $request->user());

        return redirect()->route('orphans.registry.guardian', $guardian->public_id)->with('success', 'تم تسجيل الوصي '.$guardian->reference_no.'.');
    }

    public function createOrphan(Request $request): Response
    {
        $this->access->assertEdit($request->user());
        $guardian = $request->query('guardian') ? OrphanGuardian::query()->with('person')->where('public_id', $request->query('guardian'))->first() : null;

        return Inertia::render('Orphans/Registry/CreateOrphan', [
            'options' => $this->queries->options(), 'canApprove' => $this->access->allows($request->user(), 'portal'), 'staff' => $this->access->staff(),
            'guardian' => $guardian ? ['public_id' => $guardian->public_id, 'reference_no' => $guardian->reference_no, 'full_name' => $guardian->person->full_name, 'id_masked' => $guardian->person->maskedId()] : null,
        ]);
    }

    public function storeOrphan(Request $request, GazaAddress $address): RedirectResponse
    {
        $this->access->assertEdit($request->user());
        $data = $request->validate([
            ...Persons::rules('child.'), ...Persons::rules('guardian.', false),
            'guardian_mode' => ['required', Rule::in(['existing', 'new', 'person'])],
            'guardian_public_id' => 'required_if:guardian_mode,existing|nullable|string|exists:orphan_guardians,public_id',
            'guardian_person_public_id' => 'required_if:guardian_mode,person|nullable|string|exists:persons,public_id',
            'link' => 'required|array', 'link.link_status' => ['required', Rule::in(['claimed', 'pending', 'approved'])],
            'link.kinship_value_id' => 'nullable|integer|exists:master_data_values,id', 'link.authority_kind_value_id' => 'nullable|integer|exists:master_data_values,id',
            'link.legal_basis' => 'nullable|string|max:300', 'link.starts_on' => 'nullable|date', 'link.ends_on' => 'nullable|date|after_or_equal:link.starts_on', 'link.evidence_note' => 'nullable|string|max:2000',
            'orphan' => 'nullable|array', 'orphan.orphan_class_value_id' => 'nullable|integer|exists:master_data_values,id', 'orphan.assigned_to' => 'nullable|integer|exists:users,id',
            'orphan.notes' => 'nullable|string|max:3000',
            'father' => 'nullable|array', ...Persons::rules('father.', false), 'father.status' => ['nullable', Rule::in(array_keys(Persons::VITAL_LABELS))], 'father.event_date' => 'nullable|date|before_or_equal:today', 'father.person_public_id' => 'nullable|string|exists:persons,public_id',
            'mother' => 'nullable|array', ...Persons::rules('mother.', false), 'mother.status' => ['nullable', Rule::in(array_keys(Persons::VITAL_LABELS))], 'mother.event_date' => 'nullable|date|before_or_equal:today', 'mother.person_public_id' => 'nullable|string|exists:persons,public_id',
            'address' => 'nullable|array', ...GazaAddress::rules('address.'),
        ], [], [...Persons::attributes('child.', 'اليتيم'), ...Persons::attributes('guardian.', 'الوصي'), ...Persons::attributes('father.', 'الأب'), ...Persons::attributes('mother.', 'الأم')]);
        if ($data['guardian_mode'] === 'new') {
            $request->validate(['guardian.full_name' => 'required_without_all:guardian.first_name,guardian.family_name'], [], ['guardian.full_name' => 'اسم الوصي']);
        }
        $link = $data['link'];
        match ($data['guardian_mode']) {
            'existing' => $link['guardian_id'] = OrphanGuardian::query()->where('public_id', $data['guardian_public_id'])->value('id'),
            'person' => $link['guardian_person_id'] = Person::query()->where('public_id', $data['guardian_person_public_id'])->value('id'),
            default => $link['guardian'] = $data['guardian'] ?? [],
        };
        $parents = [];
        foreach (['father', 'mother'] as $relation) {
            $parent = $data[$relation] ?? [];
            if (! empty($parent['person_public_id'])) {
                $parents[$relation] = ['person_id' => Person::query()->where('public_id', $parent['person_public_id'])->value('id'), 'status' => $parent['status'] ?? null, 'event_date' => $parent['event_date'] ?? null];
            } elseif (filled($parent['full_name'] ?? null) || filled($parent['first_name'] ?? null)) {
                $parents[$relation] = $parent;
            }
        }
        $orphan = $this->registry->createOrphan($data['child'], [...($data['orphan'] ?? []), 'address' => $this->address($address, $data['address'] ?? null, 'address.')],
            $link, $parents, $request->user());

        return redirect()->route('orphans.registry.orphan', $orphan->public_id)->with('success', 'تم إنشاء ملف اليتيم '.$orphan->reference_no.' وربطه بالوصي.');
    }

    public function orphan(Request $request, string $publicId): Response
    {
        $this->access->assertView($request->user());
        $orphan = Orphan::query()->with('person')->where('public_id', $publicId)->firstOrFail();

        return Inertia::render('Orphans/Registry/OrphanProfile', [
            ...$this->profiles->orphan($orphan, $request->user()), 'options' => $this->queries->options(), 'abilities' => $this->access->abilities($request->user()),
            'staff' => $this->access->staff(), 'tab' => $request->query('tab', 'basic'),
        ]);
    }

    public function guardian(Request $request, string $publicId): Response
    {
        $this->access->assertView($request->user());
        $guardian = OrphanGuardian::query()->with('person')->where('public_id', $publicId)->firstOrFail();

        return Inertia::render('Orphans/Registry/GuardianProfile', [
            ...$this->profiles->guardian($guardian, $request->user()), 'options' => $this->queries->options(), 'abilities' => $this->access->abilities($request->user()),
            'tab' => $request->query('tab', 'basic'),
        ]);
    }

    /** Basic data of any registry person (child, guardian, parent). */
    public function updatePerson(Request $request, string $publicId): RedirectResponse
    {
        $this->access->assertEdit($request->user());
        $person = $this->registryPerson($publicId);
        $data = $request->validate(Persons::rules(), [], Persons::attributes());
        $before = $person->only(['full_name', 'date_of_birth', 'sex', 'id_format']);
        DB::transaction(function () use ($person, $data, $request): void {
            $this->persons->save($data, $person, $request->user()->id);
            if ($orphan = Orphan::query()->where('person_id', $person->id)->first()) {
                $this->registry->refreshCompleteness($orphan);
            }
        });
        $this->audit->recordRow('orphans.person.updated', 'persons', $person->id, $before, $person->fresh()->only(array_keys($before)), $request->user()->id, $request->ip());

        return back()->with('success', 'تم حفظ البيانات الأساسية.');
    }

    /** Full identity number on explicit request, for holders of «identity»; every reveal is logged. */
    public function revealId(Request $request, string $publicId): JsonResponse
    {
        $this->access->assert($request->user(), 'identity');
        $person = $this->registryPerson($publicId);
        $this->access->logRead($request->user(), 'person_identity', $person->id, 'id_revealed');

        return response()->json(['id_number' => $person->id_number]);
    }

    public function verifyId(Request $request, string $publicId): RedirectResponse
    {
        $this->access->assert($request->user(), 'portal');
        $data = $request->validate(['source' => 'required|string|max:200'], [], ['source' => 'مصدر التحقق']);
        $person = $this->registryPerson($publicId);
        $this->persons->verifyIdentity($person, $data['source'], $request->user()->id);
        $this->audit->recordRow('orphans.person.identity_verified', 'persons', $person->id, null, ['source' => $data['source']], $request->user()->id, $request->ip());

        return back()->with('success', 'تم تسجيل التحقق اليدوي من الهوية.');
    }

    public function updateFile(Request $request, string $publicId): RedirectResponse
    {
        $this->access->assertEdit($request->user());
        $orphan = Orphan::query()->where('public_id', $publicId)->firstOrFail();
        $data = $request->validate([
            // «معتمد مبدئيًا» comes only from the smart import (it may be kept, never chosen).
            'status' => ['required', Rule::in($orphan->status === 'provisional' ? array_keys(RegistryQueries::ORPHAN_STATUS) : array_diff(array_keys(RegistryQueries::ORPHAN_STATUS), ['provisional']))], 'status_reason' => 'nullable|string|max:300',
            'orphan_class_value_id' => 'nullable|integer|exists:master_data_values,id', 'review_due_on' => 'nullable|date', 'notes' => 'nullable|string|max:3000',
            'assigned_to' => 'nullable|integer|exists:users,id', 'restricted_notes' => 'nullable|string|max:3000',
        ]);
        if (($data['assigned_to'] ?? null) != $orphan->assigned_to) {
            abort_unless($this->access->canAssign($request->user()), 403, 'الإسناد وإعادة الإسناد لرئيس القسم.');
        }
        if (in_array($data['status'], ['suspended', 'archived', 'ended'], true) && blank($data['status_reason'] ?? null)) {
            return back()->withErrors(['status_reason' => 'اذكر سبب تغيير الحالة.']);
        }
        if ($data['status'] === 'active' && $orphan->status !== 'active' && ! DB::table('orphan_guardianships')->where('orphan_id', $orphan->id)->where('status', 'approved')->exists()) {
            return back()->withErrors(['status' => 'اعتماد الملف يحتاج وصاية معتمدة واحدة على الأقل.']);
        }
        $before = $orphan->only(['status', 'orphan_class_value_id', 'assigned_to']);
        $fill = collect($data)->except('restricted_notes')->all();
        if ($this->access->allows($request->user(), 'health') && array_key_exists('restricted_notes', $data)) {
            $fill['restricted_notes'] = $data['restricted_notes'];
        }
        if (($data['orphan_class_value_id'] ?? null) != $orphan->orphan_class_value_id) {
            $fill['class_basis'] = $data['orphan_class_value_id'] ? 'manual' : 'provisional';
        }
        $orphan->forceFill([...$fill, 'updated_by' => $request->user()->id])->save();
        if (($fill['class_basis'] ?? null) === 'provisional') {
            $this->registry->deriveClass($orphan);
        }
        $this->audit->recordRow('orphans.orphan.updated', 'orphans', $orphan->id, $before, $orphan->only(array_keys($before)), $request->user()->id, $request->ip());

        return back()->with('success', 'تم تحديث بيانات الملف.');
    }

    public function updateGuardianRecord(Request $request, string $publicId): RedirectResponse
    {
        $this->access->assertEdit($request->user());
        $guardian = OrphanGuardian::query()->where('public_id', $publicId)->firstOrFail();
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'inactive'])], 'notes' => 'nullable|string|max:2000']);
        $guardian->forceFill([...$data, 'updated_by' => $request->user()->id])->save();
        $this->audit->recordRow('orphans.guardian.updated', 'orphan_guardians', $guardian->id, null, $data, $request->user()->id, $request->ip());

        return back()->with('success', 'تم تحديث بيانات الوصي.');
    }

    public function addGuardianship(Request $request, string $publicId): RedirectResponse
    {
        $this->access->assertEdit($request->user());
        $orphan = Orphan::query()->where('public_id', $publicId)->firstOrFail();
        $data = $request->validate([...$this->linkRules(), 'guardian_public_id' => 'required|string|exists:orphan_guardians,public_id', 'is_primary' => 'boolean']);
        $guardian = OrphanGuardian::query()->where('public_id', $data['guardian_public_id'])->firstOrFail();
        DB::transaction(function () use ($orphan, $guardian, $data, $request): void {
            $this->registry->addGuardianship($orphan, $guardian, $data, $request->user());
            $this->registry->refreshCompleteness($orphan);
        });

        return back()->with('success', 'تم ربط الوصي باليتيم. الارتباط لا يمنح أي وصول للبوابة قبل اعتماده.');
    }

    public function decideGuardianship(Request $request, OrphanGuardianship $guardianship): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'note' => 'nullable|string|max:2000', 'starts_on' => 'nullable|date',
            'ends_on' => 'nullable|date|after_or_equal:starts_on', 'authority_kind_value_id' => 'nullable|integer|exists:master_data_values,id', 'legal_basis' => 'nullable|string|max:300']);
        $this->registry->decideGuardianship($guardianship, $data['decision'], $data, $request->user());
        $this->registry->refreshCompleteness($guardianship->orphan);

        return back()->with('success', $data['decision'] === 'approve' ? 'تم اعتماد الوصاية لهذا الطفل.' : 'تم رفض الارتباط وتسجيل السبب.');
    }

    public function replaceGuardianship(Request $request, OrphanGuardianship $guardianship): RedirectResponse
    {
        $this->access->assertEdit($request->user());
        $data = $request->validate([...$this->linkRules(), 'guardian_public_id' => 'required|string|exists:orphan_guardians,public_id', 'reason' => 'required|string|max:300']);
        $new = OrphanGuardian::query()->where('public_id', $data['guardian_public_id'])->firstOrFail();
        abort_if($new->id === $guardianship->guardian_id, 422, 'اختر وصيًا مختلفًا.');
        $this->registry->replaceGuardian($guardianship, $new, $data, $request->user());
        $this->registry->refreshCompleteness($guardianship->orphan);

        return back()->with('success', 'تم استبدال الوصي مع حفظ الارتباط السابق في السجل.');
    }

    public function endGuardianship(Request $request, OrphanGuardianship $guardianship): RedirectResponse
    {
        $this->access->assertEdit($request->user());
        $data = $request->validate(['reason' => 'required|string|max:300'], [], ['reason' => 'سبب الإنهاء']);
        try {
            $this->registry->endGuardianship($guardianship, $data['reason'], $request->user());
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'ORPHAN_WITHOUT_GUARDIAN')) {
                return back()->withErrors(['reason' => 'هذا هو الارتباط الوحيد القائم للملف. استخدم «استبدال الوصي» أو أضف وصيًا آخر أولًا.']);
            }
            throw $e;
        }
        $this->registry->refreshCompleteness($guardianship->orphan);

        return back()->with('success', 'تم إنهاء الارتباط مع حفظه في السجل.');
    }

    public function linkParent(Request $request, string $publicId, string $relation): RedirectResponse
    {
        $this->access->assertEdit($request->user());
        abort_unless(in_array($relation, ['father', 'mother'], true), 404);
        $orphan = Orphan::query()->where('public_id', $publicId)->firstOrFail();
        $data = $request->validate([...Persons::rules('', false), 'person_public_id' => 'nullable|string|exists:persons,public_id',
            'status' => ['nullable', Rule::in(array_keys(Persons::VITAL_LABELS))], 'event_date' => 'nullable|date|before_or_equal:today', 'evidence_note' => 'nullable|string|max:1000',
            'basis' => ['nullable', Rule::in(['declared', 'document'])]], [], Persons::attributes());
        if (! empty($data['person_public_id'])) {
            $data['person_id'] = Person::query()->where('public_id', $data['person_public_id'])->value('id');
        } elseif (blank($data['full_name'] ?? null) && blank($data['first_name'] ?? null)) {
            return back()->withErrors(['full_name' => 'اكتب الاسم أو اختر شخصًا مسجلًا.']);
        }
        DB::transaction(function () use ($orphan, $relation, $data, $request): void {
            $this->registry->linkParent($orphan, $relation, $data, $request->user());
            $this->registry->refreshCompleteness($orphan);
        });

        return back()->with('success', $relation === 'father' ? 'تم ربط بيانات الأب.' : 'تم ربط بيانات الأم.');
    }

    public function recordStatus(Request $request, string $publicId): RedirectResponse
    {
        $this->access->assertEdit($request->user());
        $person = $this->registryPerson($publicId);
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(Persons::VITAL_LABELS))], 'event_date' => 'nullable|date|before_or_equal:today',
            'place' => 'nullable|string|max:200', 'cause' => 'nullable|string|max:500', 'evidence_note' => 'nullable|string|max:1000', 'verification' => ['required', Rule::in(['reported', 'verified'])]]);
        if (filled($data['cause'] ?? null)) {
            $this->access->assert($request->user(), 'health');
        }
        if ($data['verification'] === 'verified') {
            $this->access->assert($request->user(), 'portal');
        }
        DB::transaction(function () use ($person, $data, $request): void {
            $this->persons->recordStatus($person, $data, $request->user()->id);
            foreach (Orphan::query()->whereIn('id', DB::table('orphan_parent_links')->where('person_id', $person->id)->pluck('orphan_id'))->get() as $orphan) {
                $this->registry->deriveClass($orphan);
            }
        });
        $this->audit->recordRow('orphans.person.status_recorded', 'persons', $person->id, null, ['status' => $data['status'], 'verification' => $data['verification']], $request->user()->id, $request->ip());

        return back()->with('success', 'تم تسجيل الحالة مع تاريخها؛ السجل السابق محفوظ.');
    }

    public function addAddress(Request $request, string $publicId, GazaAddress $address): RedirectResponse
    {
        $this->access->assertEdit($request->user());
        $person = $this->registryPerson($publicId);
        $data = $request->validate(['kind' => ['required', Rule::in(['original', 'current', 'displacement'])], ...GazaAddress::rules(), 'address_text' => 'nullable|string|max:500',
            'shelter_type_value_id' => 'nullable|integer|exists:master_data_values,id', 'tenure_value_id' => 'nullable|integer|exists:master_data_values,id',
            'stability' => 'nullable|string|max:100', 'services_access' => 'nullable|string|max:300', 'starts_on' => 'nullable|date', 'ends_on' => 'nullable|date|after_or_equal:starts_on',
            'household_public_id' => 'nullable|string|exists:households,public_id']);
        $owner = ! empty($data['household_public_id']) ? ['household_id' => DB::table('households')->where('public_id', $data['household_public_id'])->value('id')] : ['person_id' => $person->id];
        DB::transaction(function () use ($owner, $data, $request, $address, $person): void {
            $this->registry->saveAddress($owner, $data['kind'], [...$data, ...($this->address($address, $data, '', true) ?? [])], $request->user()->id);
            if ($orphan = Orphan::query()->where('person_id', $person->id)->first()) {
                $this->registry->refreshCompleteness($orphan);
            }
        });
        RegistryQueries::forgetOptions();

        return back()->with('success', 'تمت إضافة العنوان؛ العنوان السابق محفوظ في السجل.');
    }

    public function closeAddress(Request $request, int $address): RedirectResponse
    {
        $this->access->assertEdit($request->user());
        $data = $request->validate(['ends_on' => 'required|date']);
        DB::table('registry_addresses')->where('id', $address)->whereNull('ends_on')->update(['ends_on' => $data['ends_on'], 'updated_at' => now()]);

        return back()->with('success', 'تم إغلاق فترة العنوان.');
    }

    public function addEconomic(Request $request, string $publicId): RedirectResponse
    {
        $this->access->assertEdit($request->user());
        $person = $this->registryPerson($publicId);
        $data = $request->validate([
            'period' => ['required', Rule::in(['current', 'pre_death'])], 'reference_date' => 'nullable|date', 'period_from' => 'nullable|date', 'period_to' => 'nullable|date|after_or_equal:period_from',
            'employment_status_value_id' => 'nullable|integer|exists:master_data_values,id', 'occupation' => 'nullable|string|max:200', 'sector' => 'nullable|string|max:150',
            'income_band_value_id' => 'nullable|integer|exists:master_data_values,id', 'income_source_value_id' => 'nullable|integer|exists:master_data_values,id',
            'economic_status_value_id' => 'nullable|integer|exists:master_data_values,id', 'dependents_count' => 'nullable|integer|min:0|max:60', 'earners_count' => 'nullable|integer|min:0|max:60',
            'obligations' => 'nullable|string|max:1000', 'notes' => 'nullable|string|max:2000',
            'source' => ['required', Rule::in(['self_reported', 'caregiver', 'document', 'field_visit'])], 'verification' => ['required', Rule::in(['reported', 'verified'])],
            'household_public_id' => 'nullable|string|exists:households,public_id']);
        if ($data['period'] === 'pre_death' && $person->vital_status !== 'deceased') {
            return back()->withErrors(['period' => 'الوضع الاقتصادي قبل الوفاة يُسجل لشخص حالته «متوفى».']);
        }
        $owner = ! empty($data['household_public_id']) ? ['household_id' => DB::table('households')->where('public_id', $data['household_public_id'])->value('id')] : ['person_id' => $person->id];
        $id = DB::table('economic_assessments')->insertGetId([...$owner, ...collect($data)->except('household_public_id')->all(), 'assessed_by' => $request->user()->id, 'created_at' => now()]);
        $this->audit->recordRow('orphans.economic.recorded', 'economic_assessments', $id, null, ['period' => $data['period']], $request->user()->id, $request->ip());

        return back()->with('success', 'تم حفظ التقييم الاقتصادي بتاريخه؛ التقييمات السابقة محفوظة.');
    }

    public function addCare(Request $request, string $publicId): RedirectResponse
    {
        $this->access->assertEdit($request->user());
        $person = $this->registryPerson($publicId);
        $data = $request->validate([
            'category' => ['required', Rule::in(['health', 'disability', 'chronic', 'medication', 'assistive_device', 'education_support', 'other'])],
            'need_value_id' => 'nullable|integer|exists:master_data_values,id', 'summary' => 'nullable|string|max:300', 'clinical_details' => 'nullable|string|max:5000',
            'source' => ['required', Rule::in(['self', 'caregiver', 'clinician', 'document'])], 'verification' => ['required', Rule::in(['reported', 'verified'])],
            'follow_up_on' => 'nullable|date', 'access_barrier' => 'nullable|string|max:300']);
        if (filled($data['clinical_details'] ?? null)) {
            $this->access->assert($request->user(), 'health');
            $data['clinical_details'] = encrypt($data['clinical_details']);
        }
        $id = DB::table('care_records')->insertGetId([...$data, 'person_id' => $person->id, 'is_active' => true, 'recorded_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->recordRow('orphans.care.recorded', 'care_records', $id, null, ['category' => $data['category']], $request->user()->id, $request->ip());

        return back()->with('success', 'تم تسجيل الاحتياج.');
    }

    public function closeCare(Request $request, int $record): RedirectResponse
    {
        $this->access->assertEdit($request->user());
        DB::table('care_records')->where('id', $record)->update(['is_active' => false, 'updated_at' => now()]);

        return back()->with('success', 'تم إغلاق الاحتياج مع بقائه في السجل.');
    }

    public function addEducation(Request $request, string $publicId): RedirectResponse
    {
        $this->access->assertEdit($request->user());
        $orphan = Orphan::query()->where('public_id', $publicId)->firstOrFail();
        $data = $request->validate([
            'academic_year' => ['nullable', 'regex:/^\d{4}(\/\d{4})?$/'], 'stage_value_id' => 'nullable|integer|exists:master_data_values,id', 'grade' => 'nullable|string|max:60',
            'school_name' => 'nullable|string|max:200', 'school_type' => 'nullable|string|max:60', 'status_value_id' => 'nullable|integer|exists:master_data_values,id',
            'performance' => 'nullable|string|max:100', 'attendance_note' => 'nullable|string|max:300', 'support_needs' => 'nullable|string|max:2000',
            'starts_on' => 'nullable|date', 'ends_on' => 'nullable|date|after_or_equal:starts_on'], [], ['academic_year' => 'العام الدراسي']);
        DB::table('education_records')->insert([...$data, 'orphan_id' => $orphan->id, 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->registry->refreshCompleteness($orphan);

        return back()->with('success', 'تمت إضافة السجل التعليمي؛ السجلات السابقة محفوظة.');
    }

    public function addAssessment(Request $request, string $publicId): RedirectResponse
    {
        $this->access->assertEdit($request->user());
        $orphan = Orphan::query()->where('public_id', $publicId)->firstOrFail();
        $data = $request->validate([
            'kind' => ['required', Rule::in(['visit', 'assessment', 'follow_up'])], 'status' => ['required', Rule::in(['scheduled', 'completed'])],
            'scheduled_on' => 'nullable|date', 'completed_on' => 'nullable|date|before_or_equal:today', 'assigned_to' => 'nullable|integer|exists:users,id',
            'needs_level_value_id' => 'nullable|integer|exists:master_data_values,id', 'summary' => 'nullable|string|max:5000', 'valid_until' => 'nullable|date']);
        if (! empty($data['assigned_to']) && (int) $data['assigned_to'] !== (int) $request->user()->id) {
            abort_unless($this->access->canAssign($request->user()), 403, 'إسناد الزيارات لغيرك لرئيس القسم.');
        }
        if ($data['status'] === 'completed') {
            $data['completed_on'] ??= today()->toDateString();
            $data['completed_by'] = $request->user()->id;
        }
        DB::table('social_assessments')->insert([...$data, 'orphan_id' => $orphan->id, 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->registry->refreshCompleteness($orphan);

        return back()->with('success', $data['status'] === 'completed' ? 'تم تسجيل التقييم.' : 'تمت جدولة الزيارة.');
    }

    public function completeAssessment(Request $request, int $assessment): RedirectResponse
    {
        $this->access->assertEdit($request->user());
        $row = DB::table('social_assessments')->where('id', $assessment)->where('status', 'scheduled')->first();
        abort_unless($row, 404);
        $data = $request->validate(['status' => ['required', Rule::in(['completed', 'cancelled'])], 'summary' => 'nullable|string|max:5000',
            'needs_level_value_id' => 'nullable|integer|exists:master_data_values,id', 'valid_until' => 'nullable|date']);
        DB::table('social_assessments')->where('id', $assessment)->update([...$data, ...($data['status'] === 'completed' ? ['completed_on' => today()->toDateString(), 'completed_by' => $request->user()->id] : []),
            'updated_at' => now()]);
        if ($row->orphan_id) {
            $this->registry->refreshCompleteness(Orphan::findOrFail($row->orphan_id));
        }

        return back()->with('success', 'تم تحديث الزيارة.');
    }

    /** Household context: create one around this person, or join an existing one by reference. */
    public function household(Request $request, string $publicId): RedirectResponse
    {
        $this->access->assertEdit($request->user());
        $person = $this->registryPerson($publicId);
        $data = $request->validate(['action' => ['required', Rule::in(['create', 'join', 'leave', 'update'])], 'reference_no' => 'nullable|string|max:30',
            'relation_value_id' => 'nullable|integer|exists:master_data_values,id', 'membership_id' => 'nullable|integer',
            'members_count' => 'nullable|integer|min:1|max:60', 'dependents_count' => 'nullable|integer|min:0|max:60', 'earners_count' => 'nullable|integer|min:0|max:60',
            'living_conditions' => 'nullable|string|max:2000', 'household_public_id' => 'nullable|string|exists:households,public_id']);
        DB::transaction(function () use ($person, $data, $request): void {
            $actor = $request->user()->id;
            if ($data['action'] === 'leave') {
                DB::table('household_members')->where('id', $data['membership_id'] ?? 0)->where('person_id', $person->id)->whereNull('ends_on')
                    ->update(['ends_on' => today()->toDateString(), 'updated_at' => now()]);

                return;
            }
            if ($data['action'] === 'update') {
                DB::table('households')->where('public_id', $data['household_public_id'] ?? '')->update([...collect($data)->only(['members_count', 'dependents_count', 'earners_count', 'living_conditions'])->all(),
                    'updated_by' => $actor, 'updated_at' => now()]);

                return;
            }
            $household = $data['action'] === 'create'
                ? DB::table('households')->insertGetId(['public_id' => (string) Str::ulid(), 'reference_no' => Registry::reference('HH', 'household_reference_seq'),
                    'contact_person_id' => $person->id, 'status' => 'active', ...collect($data)->only(['members_count', 'dependents_count', 'earners_count', 'living_conditions'])->all(),
                    'created_by' => $actor, 'updated_by' => $actor, 'created_at' => now(), 'updated_at' => now()])
                : DB::table('households')->where('reference_no', trim((string) ($data['reference_no'] ?? '')))->value('id');
            if (! $household) {
                throw ValidationException::withMessages(['reference_no' => 'رقم الأسرة غير موجود.']);
            }
            if (! DB::table('household_members')->where('household_id', $household)->where('person_id', $person->id)->whereNull('ends_on')->exists()) {
                DB::table('household_members')->insert(['household_id' => $household, 'person_id' => $person->id, 'relation_value_id' => $data['relation_value_id'] ?? null,
                    'starts_on' => today()->toDateString(), 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        return back()->with('success', 'تم تحديث بيانات الأسرة. عضوية الأسرة لا تمنح أي وصاية.');
    }

    public function uploadDocument(Request $request, string $publicId): RedirectResponse
    {
        $this->access->assertEdit($request->user());
        $person = $this->registryPerson($publicId);
        $data = $request->validate(['file' => 'required|file|max:10240|mimes:pdf,jpg,jpeg,png,webp', 'document_type_value_id' => 'nullable|integer|exists:master_data_values,id',
            'title' => 'nullable|string|max:200', 'issued_on' => 'nullable|date', 'expires_on' => 'nullable|date|after_or_equal:issued_on', 'is_restricted' => 'boolean',
            'guardianship_id' => 'nullable|integer|exists:orphan_guardianships,id']);
        if (! empty($data['is_restricted'])) {
            $this->access->assert($request->user(), 'health');
        }
        $file = $data['file'];
        $path = $file->store('registry/'.now()->format('Y/m'), 'local');
        $id = DB::table('registry_documents')->insertGetId(['public_id' => (string) Str::ulid(), 'person_id' => $person->id, 'guardianship_id' => $data['guardianship_id'] ?? null,
            'document_type_value_id' => $data['document_type_value_id'] ?? null, 'title' => $data['title'] ?? null, 'path' => $path, 'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime' => $file->getMimeType() ?: 'application/octet-stream', 'size' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath()),
            'issued_on' => $data['issued_on'] ?? null, 'expires_on' => $data['expires_on'] ?? null, 'is_restricted' => (bool) ($data['is_restricted'] ?? false),
            'uploaded_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->recordRow('orphans.document.uploaded', 'registry_documents', $id, null, ['restricted' => (bool) ($data['is_restricted'] ?? false)], $request->user()->id, $request->ip());

        return back()->with('success', 'تم رفع المستند.');
    }

    public function document(Request $request, string $publicId): StreamedResponse
    {
        $this->access->assertView($request->user());
        $doc = DB::table('registry_documents')->where('public_id', $publicId)->firstOrFail();
        if ($doc->is_restricted && ! $this->access->allows($request->user(), 'health')) {
            // Refused reads of medical documents are recorded too.
            $this->access->logRead($request->user(), 'document', (int) $doc->id, 'download_denied');
            abort(403, 'هذا المستند مقيد؛ يحتاج قدرة «البيانات الصحية».');
        }
        $this->access->logRead($request->user(), 'document', (int) $doc->id, 'downloaded');

        return Storage::disk('local')->download($doc->path, $doc->original_name, ['Cache-Control' => 'no-store, private']);
    }

    public function reviewDocument(Request $request, string $publicId): RedirectResponse
    {
        $this->access->assert($request->user(), 'portal');
        $data = $request->validate(['review_status' => ['required', Rule::in(['accepted', 'rejected'])]]);
        DB::table('registry_documents')->where('public_id', $publicId)->update([...$data, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'updated_at' => now()]);

        return back()->with('success', 'تم تسجيل مراجعة المستند.');
    }

    /** A person reachable from the registry (child, guardian, parent, household member); others are not editable here. */
    private function registryPerson(string $publicId): Person
    {
        $person = Person::query()->where('public_id', $publicId)->firstOrFail();
        abort_unless(DB::table('orphans')->where('person_id', $person->id)->exists() || DB::table('orphan_guardians')->where('person_id', $person->id)->exists()
            || DB::table('orphan_parent_links')->where('person_id', $person->id)->exists() || DB::table('household_members')->where('person_id', $person->id)->exists(), 404);

        return $person;
    }

    private function address(GazaAddress $address, ?array $input, string $prefix, bool $loose = false): ?array
    {
        if (! $input || (! array_filter(array_intersect_key($input, array_flip(['governorate_value_id', 'locality_value_id', 'area_value_id', 'subarea_value_id']))) && blank($input['address_details'] ?? null))) {
            return $loose ? [] : null;
        }
        $normalized = $address->normalize($input, null, $prefix, false);

        return ['governorate_value_id' => $normalized['governorate_value_id'] ?? null, 'locality_value_id' => $normalized['locality_value_id'] ?? null,
            'area_value_id' => $normalized['area_value_id'] ?? null, 'subarea_value_id' => $normalized['subarea_value_id'] ?? null, 'detail' => $normalized['address_details'] ?? null];
    }

    private function linkRules(): array
    {
        return ['link_status' => ['required', Rule::in(['claimed', 'pending', 'approved'])], 'kinship_value_id' => 'nullable|integer|exists:master_data_values,id',
            'authority_kind_value_id' => 'nullable|integer|exists:master_data_values,id', 'legal_basis' => 'nullable|string|max:300', 'authority_scope' => 'nullable|string|max:300',
            'starts_on' => 'nullable|date', 'ends_on' => 'nullable|date|after_or_equal:starts_on', 'evidence_note' => 'nullable|string|max:2000'];
    }
}
