<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CareerScaleController extends Controller
{
    private function authorizeAdmin(Request $request): void
    {
        abort_unless(
            $request->user()?->is_active && $request->user()->isSystemAdmin(),
            403,
            'إدارة ثوابت السلم الوظيفي متاحة لمدير النظام فقط.'
        );
    }

    private function navigation(): array
    {
        return [
            ['label' => 'لوحة التحكم', 'url' => route('ui.preview'), 'icon' => '▦', 'active' => false],
            ['label' => 'ثوابت النظام', 'url' => route('admin.master-data.index'), 'icon' => '⚙', 'active' => true],
        ];
    }

    private function audit(Request $request, string $action, string $table, int $id, ?array $before, array $after): void
    {
        DB::table('audit_logs')->insert([
            'actor_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => $table,
            'subject_id' => $id,
            'metadata' => json_encode(['before' => $before, 'after' => $after], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function scale(int $id): object
    {
        $scale = DB::table('career_scales')->where('id', $id)->first();
        abort_unless($scale, 404);

        return $scale;
    }

    private function grade(int $id): object
    {
        $grade = DB::table('job_grades')->where('id', $id)->first();
        abort_unless($grade, 404);

        return $grade;
    }

    private function scaleRules(?int $id = null): array
    {
        return [
            'code' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('career_scales', 'code')->ignore($id)],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    private function gradeRules(?int $id = null): array
    {
        return [
            'career_scale_id' => ['required', 'integer', Rule::exists('career_scales', 'id')],
            'code' => [
                'required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('job_grades', 'code')
                    ->where('career_scale_id', request()->integer('career_scale_id'))
                    ->ignore($id),
            ],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'grade_level' => ['nullable', 'integer', 'min:0'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'salary_min' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            // The range is checked in ensureValidSalaryRange(), so a maximum alone is accepted.
            'salary_max' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'currency_code' => [
                Rule::requiredIf(fn () => request()->filled('salary_min') || request()->filled('salary_max')),
                'nullable', 'string', 'size:3', Rule::exists('currencies', 'code'),
            ],
            'is_active' => ['required', 'boolean'],
        ];
    }

    private function ensureValidSalaryRange(array $data): void
    {
        if (isset($data['salary_min'], $data['salary_max']) && (float) $data['salary_max'] < (float) $data['salary_min']) {
            throw ValidationException::withMessages(['salary_max' => 'الحد الأعلى للراتب يجب أن يساوي أو يزيد عن الحد الأدنى.']);
        }
        if (($data['salary_min'] ?? null) !== null || ($data['salary_max'] ?? null) !== null) {
            $currencyActive = DB::table('currencies')
                ->where('code', $data['currency_code'] ?? '')
                ->where('is_active', true)
                ->exists();
            if (! $currencyActive) {
                throw ValidationException::withMessages(['currency_code' => 'اختر عملة نشطة من ثوابت العملات.']);
            }
        }
    }

    public function index(Request $request): Response
    {
        $this->authorizeAdmin($request);

        return Inertia::render('Admin/CareerScales/Index', [
            'username' => $request->user()->name,
            'navigation' => $this->navigation(),
            'scales' => DB::table('career_scales as s')
                ->select('s.id', 's.code', 's.name_ar', 's.name_en', 's.description', 's.sort_order', 's.is_active')
                ->selectSub(function ($query) {
                    $query->from('job_grades as g')->selectRaw('count(*)')->whereColumn('g.career_scale_id', 's.id');
                }, 'grades_count')
                ->orderBy('s.sort_order')->orderBy('s.name_ar')->get(),
            'grades' => DB::table('job_grades as g')
                ->join('career_scales as s', 's.id', '=', 'g.career_scale_id')
                ->select('g.id', 'g.career_scale_id', 's.name_ar as scale_name', 'g.code', 'g.name_ar',
                    'g.name_en', 'g.grade_level', 'g.sort_order', 'g.salary_min', 'g.salary_max',
                    'g.currency_code', 'g.is_active')
                ->orderBy('s.sort_order')->orderBy('g.sort_order')->orderBy('g.name_ar')->get(),
        ]);
    }

    public function createScale(Request $request): Response
    {
        $this->authorizeAdmin($request);

        return Inertia::render('Admin/CareerScales/ScaleForm', [
            'username' => $request->user()->name,
            'navigation' => $this->navigation(),
            'scale' => null,
        ]);
    }

    public function editScale(Request $request, int $scale): Response
    {
        $this->authorizeAdmin($request);

        return Inertia::render('Admin/CareerScales/ScaleForm', [
            'username' => $request->user()->name,
            'navigation' => $this->navigation(),
            'scale' => $this->scale($scale),
        ]);
    }

    public function storeScale(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate($this->scaleRules());
        DB::transaction(function () use ($request, $data): void {
            $id = DB::table('career_scales')->insertGetId([
                ...$data, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($request, 'career_scale.created', 'career_scales', $id, null, $data);
        }, 3);

        return redirect()->route('admin.career-scales.index')->with('success', 'تمت إضافة السلم الوظيفي.');
    }

    public function updateScale(Request $request, int $scale): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate($this->scaleRules($scale));
        DB::transaction(function () use ($request, $scale, $data): void {
            $before = DB::table('career_scales')->where('id', $scale)->lockForUpdate()->first();
            abort_unless($before, 404);
            if (! $data['is_active'] && DB::table('job_grades')->where('career_scale_id', $scale)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages([
                    'is_active' => 'لا يمكن تعطيل سلم وظيفي يحتوي على درجات نشطة. عطّل درجاته أولًا.',
                ]);
            }
            DB::table('career_scales')->where('id', $scale)->update([
                ...$data, 'updated_by' => $request->user()->id, 'updated_at' => now(),
            ]);
            $this->audit($request, 'career_scale.updated', 'career_scales', $scale, (array) $before, $data);
        }, 3);

        return redirect()->route('admin.career-scales.index')->with('success', 'تم تحديث السلم الوظيفي.');
    }

    private function scaleOptions(): object
    {
        return DB::table('career_scales')->orderBy('sort_order')->orderBy('name_ar')
            ->get(['id', 'code', 'name_ar', 'is_active']);
    }

    private function currencyOptions(): object
    {
        return DB::table('currencies')->orderBy('code')
            ->get(['code', 'name_ar', 'is_active']);
    }

    public function createGrade(Request $request): Response
    {
        $this->authorizeAdmin($request);

        return Inertia::render('Admin/CareerScales/GradeForm', [
            'username' => $request->user()->name,
            'navigation' => $this->navigation(),
            'grade' => null,
            'scales' => $this->scaleOptions(),
            'currencies' => $this->currencyOptions(),
        ]);
    }

    public function editGrade(Request $request, int $grade): Response
    {
        $this->authorizeAdmin($request);

        return Inertia::render('Admin/CareerScales/GradeForm', [
            'username' => $request->user()->name,
            'navigation' => $this->navigation(),
            'grade' => $this->grade($grade),
            'scales' => $this->scaleOptions(),
            'currencies' => $this->currencyOptions(),
        ]);
    }

    public function storeGrade(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate($this->gradeRules());
        $this->ensureValidSalaryRange($data);
        DB::transaction(function () use ($request, $data): void {
            $scale = DB::table('career_scales')->where('id', $data['career_scale_id'])->lockForUpdate()->first();
            if (! $scale || ! $scale->is_active) {
                throw ValidationException::withMessages(['career_scale_id' => 'يجب اختيار سلم وظيفي نشط.']);
            }
            $id = DB::table('job_grades')->insertGetId([
                ...$data, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($request, 'job_grade.created', 'job_grades', $id, null, $data);
        }, 3);

        return redirect()->route('admin.career-scales.index')->with('success', 'تمت إضافة الدرجة الوظيفية.');
    }

    public function updateGrade(Request $request, int $grade): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate($this->gradeRules($grade));
        $this->ensureValidSalaryRange($data);
        DB::transaction(function () use ($request, $grade, $data): void {
            $parent = DB::table('career_scales')->where('id', $data['career_scale_id'])->lockForUpdate()->first();
            abort_unless($parent, 404);
            $before = DB::table('job_grades')->where('id', $grade)->lockForUpdate()->first();
            abort_unless($before, 404);
            if (! $parent->is_active && ($data['is_active'] || (int) $before->career_scale_id !== (int) $parent->id)) {
                throw ValidationException::withMessages(['career_scale_id' => 'لا يمكن تنشيط الدرجة أو نقلها إلى سلم وظيفي غير نشط.']);
            }
            DB::table('job_grades')->where('id', $grade)->update([
                ...$data, 'updated_by' => $request->user()->id, 'updated_at' => now(),
            ]);
            $this->audit($request, 'job_grade.updated', 'job_grades', $grade, (array) $before, $data);
        }, 3);

        return redirect()->route('admin.career-scales.index')->with('success', 'تم تحديث الدرجة الوظيفية.');
    }
}
