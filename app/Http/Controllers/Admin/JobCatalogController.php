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

class JobCatalogController extends Controller
{
    private function authorizeAdmin(Request $request): void
    {
        abort_unless(
            $request->user()?->is_active && $request->user()->isSystemAdmin(),
            403,
            'إدارة الثوابت متاحة لمدير النظام فقط.'
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

    private function category(int $id): object
    {
        $category = DB::table('job_categories')->where('id', $id)->first();
        abort_unless($category, 404);

        return $category;
    }

    private function title(int $id): object
    {
        $title = DB::table('job_titles')->where('id', $id)->first();
        abort_unless($title, 404);

        return $title;
    }

    private function categoryRules(?int $id = null): array
    {
        return [
            'code' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('job_categories', 'code')->ignore($id)],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    private function titleRules(?int $id = null): array
    {
        return [
            'job_category_id' => ['required', 'integer', Rule::exists('job_categories', 'id')],
            'code' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('job_titles', 'code')->ignore($id)],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function index(Request $request): Response
    {
        $this->authorizeAdmin($request);

        return Inertia::render('Admin/JobCatalog/Index', [
            'username' => $request->user()->name,
            'navigation' => $this->navigation(),
            'categories' => DB::table('job_categories')
                ->orderBy('sort_order')->orderBy('name_ar')
                ->get(['id', 'code', 'name_ar', 'name_en', 'description', 'sort_order', 'is_active']),
            'titles' => DB::table('job_titles as t')
                ->join('job_categories as c', 'c.id', '=', 't.job_category_id')
                ->orderBy('c.sort_order')->orderBy('t.sort_order')->orderBy('t.name_ar')
                ->get(['t.id', 't.job_category_id', 'c.name_ar as category_name', 't.code', 't.name_ar', 't.name_en', 't.description', 't.sort_order', 't.is_active']),
        ]);
    }

    public function createCategory(Request $request): Response
    {
        $this->authorizeAdmin($request);

        return Inertia::render('Admin/JobCatalog/CategoryForm', [
            'username' => $request->user()->name,
            'navigation' => $this->navigation(),
            'category' => null,
        ]);
    }

    public function editCategory(Request $request, int $category): Response
    {
        $this->authorizeAdmin($request);

        return Inertia::render('Admin/JobCatalog/CategoryForm', [
            'username' => $request->user()->name,
            'navigation' => $this->navigation(),
            'category' => $this->category($category),
        ]);
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate($this->categoryRules());

        DB::transaction(function () use ($request, $data): void {
            $id = DB::table('job_categories')->insertGetId([
                ...$data,
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit($request, 'job_category.created', 'job_categories', $id, null, $data);
        }, 3);

        return redirect()->route('admin.job-catalog.index')->with('success', 'تمت إضافة الفئة الوظيفية.');
    }

    public function updateCategory(Request $request, int $category): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate($this->categoryRules($category));

        DB::transaction(function () use ($request, $category, $data): void {
            $before = DB::table('job_categories')->where('id', $category)->lockForUpdate()->first();
            abort_unless($before, 404);

            if (! $data['is_active']) {
                $activeTitles = DB::table('job_titles')
                    ->where('job_category_id', $category)
                    ->where('is_active', true)
                    ->exists();
                if ($activeTitles) {
                    throw ValidationException::withMessages([
                        'is_active' => 'لا يمكن تعطيل الفئة طالما تتبعها مسميات وظيفية نشطة. عطّل المسميات أولًا.',
                    ]);
                }
            }

            DB::table('job_categories')->where('id', $category)->update([
                ...$data,
                'updated_by' => $request->user()->id,
                'updated_at' => now(),
            ]);
            $this->audit($request, 'job_category.updated', 'job_categories', $category, (array) $before, $data);
        }, 3);

        return redirect()->route('admin.job-catalog.index')->with('success', 'تم تعديل الفئة الوظيفية.');
    }

    public function createTitle(Request $request): Response
    {
        $this->authorizeAdmin($request);

        return Inertia::render('Admin/JobCatalog/TitleForm', [
            'username' => $request->user()->name,
            'navigation' => $this->navigation(),
            'title' => null,
            'categories' => DB::table('job_categories')->where('is_active', true)
                ->orderBy('sort_order')->orderBy('name_ar')->get(['id', 'name_ar']),
        ]);
    }

    public function editTitle(Request $request, int $title): Response
    {
        $this->authorizeAdmin($request);
        $record = $this->title($title);

        return Inertia::render('Admin/JobCatalog/TitleForm', [
            'username' => $request->user()->name,
            'navigation' => $this->navigation(),
            'title' => $record,
            'categories' => DB::table('job_categories')
                ->where(function ($query) use ($record): void {
                    $query->where('is_active', true)->orWhere('id', $record->job_category_id);
                })
                ->orderBy('sort_order')->orderBy('name_ar')->get(['id', 'name_ar', 'is_active']),
        ]);
    }

    private function checkTitleCategory(array $data): void
    {
        if (! (bool) $data['is_active']) {
            return;
        }

        if (! DB::table('job_categories')->where('id', $data['job_category_id'])->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([
                'job_category_id' => 'لا يمكن تفعيل مسمى وظيفي داخل فئة وظيفية غير نشطة.',
            ]);
        }
    }

    public function storeTitle(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate($this->titleRules());

        DB::transaction(function () use ($request, $data): void {
            DB::table('job_categories')->where('id', $data['job_category_id'])->lockForUpdate()->first();
            $this->checkTitleCategory($data);

            $id = DB::table('job_titles')->insertGetId([
                ...$data,
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit($request, 'job_title.created', 'job_titles', $id, null, $data);
        }, 3);

        return redirect()->route('admin.job-catalog.index')->with('success', 'تمت إضافة المسمى الوظيفي.');
    }

    public function updateTitle(Request $request, int $title): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate($this->titleRules($title));

        DB::transaction(function () use ($request, $title, $data): void {
            $before = DB::table('job_titles')->where('id', $title)->lockForUpdate()->first();
            abort_unless($before, 404);
            if ((int) $before->job_category_id !== (int) $data['job_category_id']
                && DB::table('employees')->where('job_title_id', $title)->exists()) {
                throw ValidationException::withMessages([
                    'job_category_id' => 'لا يمكن نقل مسمى مرتبط بملفات موظفين إلى فئة أخرى. عدّل تكليف الموظفين أولًا.',
                ]);
            }
            DB::table('job_categories')->where('id', $data['job_category_id'])->lockForUpdate()->first();
            $this->checkTitleCategory($data);

            DB::table('job_titles')->where('id', $title)->update([
                ...$data,
                'updated_by' => $request->user()->id,
                'updated_at' => now(),
            ]);
            $this->audit($request, 'job_title.updated', 'job_titles', $title, (array) $before, $data);
        }, 3);

        return redirect()->route('admin.job-catalog.index')->with('success', 'تم تعديل المسمى الوظيفي.');
    }
}
