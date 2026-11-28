<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\Hr\EmployeeAccess;
use App\Services\Hr\EmployeeFiles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Profile photo and personnel documents of one employee. Every action checks the viewer against this employee
 * (EmployeeAccess); file paths never reach the browser.
 */
class EmployeeFileController extends Controller
{
    public function __construct(private readonly EmployeeFiles $files, private readonly EmployeeAccess $access) {}

    /**
     * A missing employee and one the viewer may not see get the same 403 (AC-07), so ids cannot be probed for
     * existence; the refusal is still recorded in «سجل النظام».
     */
    private function employee(Request $request, int $employee): Employee
    {
        $record = Employee::query()->find($employee);
        abort_unless($record && $request->user() && $this->access->canView($request->user(), $record), 403);

        return $record;
    }

    public function photo(Request $request, int $employee, int $photo, string $variant): StreamedResponse
    {
        return $this->files->photoResponse($request->user(), $this->employee($request, $employee), $photo, $variant);
    }

    public function storePhoto(Request $request, int $employee): RedirectResponse
    {
        $record = $this->employee($request, $employee);
        $request->validate(['photo' => ['required', 'file', 'extensions:jpg,jpeg,png,webp']], [], ['photo' => 'الصورة']);

        return back(303)->with('success', $this->files->storePhoto($request->user(), $record, $request->file('photo'), $request->ip()));
    }

    public function reviewPhoto(Request $request, int $employee, int $photo): RedirectResponse
    {
        $record = $this->employee($request, $employee);
        $data = $request->validate(['approve' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:1000']]);
        $this->files->reviewPhoto($request->user(), $record, $photo, (bool) $data['approve'], $data['note'] ?? null, $request->ip());

        return back(303)->with('success', $data['approve'] ? 'اعتُمدت الصورة.' : 'رُفضت الصورة.');
    }

    public function removePhoto(Request $request, int $employee): RedirectResponse
    {
        $this->files->removePhoto($request->user(), $this->employee($request, $employee), $request->ip());

        return back(303)->with('success', 'أُزيلت الصورة من الملف وبقيت في السجل.');
    }

    public function storeDocument(Request $request, int $employee): RedirectResponse
    {
        $record = $this->employee($request, $employee);
        $data = $request->validate([
            'file' => ['required', 'file', 'extensions:pdf,jpg,jpeg,png,webp,docx'],
            'document_type_value_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'qualification_id' => ['nullable', 'integer'],
            'issuer' => ['nullable', 'string', 'max:255'],
            'issued_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'expires_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:issued_on'],
            'supersedes_id' => ['nullable', 'integer'],
        ], [], ['file' => 'الملف', 'document_type_value_id' => 'نوع الوثيقة', 'title' => 'عنوان الوثيقة', 'expires_on' => 'تاريخ الانتهاء']);
        $this->files->storeDocument($request->user(), $record, $request->file('file'), $data, isset($data['supersedes_id']) ? (int) $data['supersedes_id'] : null, $request->ip());

        return back(303)->with('success', 'رُفعت الوثيقة بانتظار التوثيق.');
    }

    public function verify(Request $request, int $employee, int $document): RedirectResponse
    {
        $record = $this->employee($request, $employee);
        $data = $request->validate(['status' => ['required', Rule::in(['verified', 'rejected'])], 'note' => ['nullable', 'string', 'max:1000']]);
        $this->files->verify($request->user(), $record, $document, $data['status'], $data['note'] ?? null, $request->ip());

        return back(303)->with('success', $data['status'] === 'verified' ? 'وُثّقت الوثيقة.' : 'سُجل رفض الوثيقة مع السبب.');
    }

    public function archive(Request $request, int $employee, int $document): RedirectResponse
    {
        $record = $this->employee($request, $employee);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']], [], ['reason' => 'سبب الأرشفة']);
        $this->files->archive($request->user(), $record, $document, $data['reason'], $request->ip());

        return back(303)->with('success', 'أُرشفت الوثيقة وبقيت محفوظة في السجل.');
    }

    public function download(Request $request, int $employee, int $document): StreamedResponse
    {
        return $this->files->download($request->user(), $this->employee($request, $employee), $document, $request->ip());
    }
}
