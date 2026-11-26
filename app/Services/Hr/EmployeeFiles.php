<?php

namespace App\Services\Hr;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

/**
 * Profile photos and personnel documents on the private disk. Files are stored under random names (never the
 * uploaded name), their type is checked from the content, and they are served only through authorised, audited
 * routes. Earlier versions are kept; nothing is deleted. No antivirus engine is installed on this server, so every
 * file is recorded as «not scanned» and the screens say so.
 */
final class EmployeeFiles
{
    /** extension => accepted content types (the content is also checked by its signature). */
    public const DOCUMENT_TYPES = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
    ];

    public const PHOTO_TYPES = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

    private const DISPLAY_SIZE = 512;

    public function __construct(private readonly EmployeeAccess $access) {}

    public function settings(): object
    {
        return DB::table('hr_employee_file_settings')->where('id', 1)->first();
    }

    // ---------------------------------------------------------------- photos

    /**
     * HR replaces the photo at once; an employee's own upload waits for HR review (owner policy setting).
     */
    public function storePhoto(User $actor, Employee $employee, UploadedFile $file, ?string $ip): string
    {
        abort_unless($this->access->canUploadPhoto($actor, $employee), 403, 'رفع الصورة غير متاح لك لهذا الملف.');
        $settings = $this->settings();
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file((string) $file->getRealPath());
        if (! isset(self::PHOTO_TYPES[$extension]) || self::PHOTO_TYPES[$extension] !== $mime) {
            throw ValidationException::withMessages(['photo' => 'الصورة يجب أن تكون JPG أو PNG أو WEBP، ومحتواها مطابق لامتدادها.']);
        }
        if ($file->getSize() > $settings->photo_max_kb * 1024) {
            throw ValidationException::withMessages(['photo' => 'حجم الصورة أكبر من الحد المسموح ('.$settings->photo_max_kb.' ك.ب).']);
        }
        $info = @getimagesize((string) $file->getRealPath());
        if (! $info || $info[0] < $settings->photo_min_px || $info[1] < $settings->photo_min_px || $info[0] > $settings->photo_max_px || $info[1] > $settings->photo_max_px) {
            throw ValidationException::withMessages(['photo' => "أبعاد الصورة يجب أن تكون بين {$settings->photo_min_px} و{$settings->photo_max_px} بكسل."]);
        }
        $this->checkQuota($employee, (int) $file->getSize());
        $display = $this->displayImage((string) $file->getRealPath(), $mime);

        $manage = $this->access->canManage($actor);
        $uuid = (string) Str::uuid();
        $directory = 'hr/employees/'.$employee->id.'/photos';
        $original = Storage::disk('local')->putFileAs($directory, $file, $uuid.'.'.$extension);
        $displayPath = $directory.'/'.$uuid.'-display.jpg';
        Storage::disk('local')->put($displayPath, $display);

        try {
            DB::transaction(function () use ($actor, $employee, $file, $mime, $info, $original, $displayPath, $manage, $ip): void {
                Employee::query()->whereKey($employee->id)->lockForUpdate()->first();
                if ($manage) {
                    DB::table('employee_photos')->where('employee_id', $employee->id)->where('status', 'current')->update(['status' => 'replaced', 'updated_at' => now()]);
                } else {
                    DB::table('employee_photos')->where('employee_id', $employee->id)->where('status', 'pending_review')->update(['status' => 'replaced', 'updated_at' => now()]);
                }
                $id = (int) DB::table('employee_photos')->insertGetId([
                    'employee_id' => $employee->id, 'original_path' => $original, 'display_path' => $displayPath, 'mime' => $mime, 'size_bytes' => (int) $file->getSize(),
                    'width' => (int) $info[0], 'height' => (int) $info[1], 'sha256' => hash_file('sha256', (string) $file->getRealPath()),
                    'status' => $manage ? 'current' : 'pending_review', 'uploaded_by' => $actor->id,
                    'reviewed_by' => $manage ? $actor->id : null, 'reviewed_at' => $manage ? now() : null, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->log($actor, $manage ? 'employee_photo.replaced' : 'employee_photo.submitted', 'employee_photos', $id, ['employee_id' => $employee->id], $ip);
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete([$original, $displayPath]);

            throw $exception;
        }

        return $manage ? 'حُدّثت الصورة الشخصية.' : 'أُرسلت الصورة إلى الموارد البشرية للمراجعة قبل ظهورها.';
    }

    /**
     * Re-encodes the image as a JPEG of at most 512 px: strips embedded metadata (location, camera) and anything
     * that is not image data. The original stays private.
     */
    private function displayImage(string $path, string $mime): string
    {
        $source = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };
        if (! $source) {
            throw ValidationException::withMessages(['photo' => 'تعذرت قراءة الصورة؛ قد تكون تالفة.']);
        }
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, self::DISPLAY_SIZE / max($width, $height));
        $canvas = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, imagesx($canvas), imagesy($canvas), $width, $height);
        ob_start();
        imagejpeg($canvas, null, 85);
        $jpeg = (string) ob_get_clean();
        imagedestroy($source);
        imagedestroy($canvas);

        return $jpeg;
    }

    public function reviewPhoto(User $actor, Employee $employee, int $photoId, bool $approve, ?string $note, ?string $ip): void
    {
        abort_unless($this->access->canVerify($actor), 403, 'مراجعة الصور لرئيس الموارد البشرية.');
        DB::transaction(function () use ($actor, $employee, $photoId, $approve, $note, $ip): void {
            $photo = DB::table('employee_photos')->where('id', $photoId)->where('employee_id', $employee->id)->lockForUpdate()->first();
            abort_unless($photo && $photo->status === 'pending_review', 404);
            if (! $approve && trim((string) $note) === '') {
                throw ValidationException::withMessages(['note' => 'اكتب سبب رفض الصورة.']);
            }
            if ($approve) {
                DB::table('employee_photos')->where('employee_id', $employee->id)->where('status', 'current')->update(['status' => 'replaced', 'updated_at' => now()]);
            }
            DB::table('employee_photos')->where('id', $photoId)->update(['status' => $approve ? 'current' : 'rejected', 'reviewed_by' => $actor->id,
                'reviewed_at' => now(), 'review_note' => $note, 'updated_at' => now()]);
            $this->log($actor, $approve ? 'employee_photo.approved' : 'employee_photo.rejected', 'employee_photos', $photoId, ['employee_id' => $employee->id], $ip);
        });
    }

    public function removePhoto(User $actor, Employee $employee, ?string $ip): void
    {
        abort_unless($this->access->canManage($actor), 403);
        DB::transaction(function () use ($actor, $employee, $ip): void {
            $photo = DB::table('employee_photos')->where('employee_id', $employee->id)->where('status', 'current')->lockForUpdate()->first();
            abort_unless($photo, 404);
            DB::table('employee_photos')->where('id', $photo->id)->update(['status' => 'removed', 'updated_at' => now()]);
            $this->log($actor, 'employee_photo.removed', 'employee_photos', (int) $photo->id, ['employee_id' => $employee->id], $ip);
        });
    }

    /**
     * The display image (or, for HR, the original) of one of the employee's photos, never another employee's.
     */
    public function photoResponse(User $viewer, Employee $employee, int $photoId, string $variant): StreamedResponse
    {
        abort_unless($this->access->canView($viewer, $employee), 403);
        $photo = DB::table('employee_photos')->where('id', $photoId)->where('employee_id', $employee->id)->first();
        abort_unless($photo, 404);
        $reviewer = $this->access->canManage($viewer) || $this->access->canVerify($viewer);
        abort_unless($photo->status === 'current' || $reviewer || ($this->access->isSelf($viewer, $employee) && $photo->status === 'pending_review'), 404);
        abort_if($variant === 'original' && ! $this->access->canManage($viewer), 403);
        $path = $variant === 'original' ? $photo->original_path : $photo->display_path;
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, 'photo-'.$photo->id.($variant === 'original' ? '.'.pathinfo($path, PATHINFO_EXTENSION) : '.jpg'), [
            'Content-Type' => $variant === 'original' ? $photo->mime : 'image/jpeg',
            'Cache-Control' => 'private, max-age=600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    /**
     * @return array{current: ?array<string, mixed>, pending: ?array<string, mixed>, history: list<array<string, mixed>>}
     */
    public function photos(User $viewer, Employee $employee): array
    {
        $rows = DB::table('employee_photos as p')->join('users as u', 'u.id', '=', 'p.uploaded_by')
            ->where('p.employee_id', $employee->id)->orderByDesc('p.id')
            ->get(['p.id', 'p.status', 'p.width', 'p.height', 'p.size_bytes', 'p.created_at', 'p.review_note', 'u.name as uploaded_by']);
        $url = fn (object $photo): string => route('admin.employee-files.photo', ['employee' => $employee->id, 'photo' => $photo->id, 'variant' => 'display'], false);
        $current = $rows->firstWhere('status', 'current');
        $pending = $rows->firstWhere('status', 'pending_review');
        $reviewer = $this->access->canManage($viewer) || $this->access->canVerify($viewer);

        return [
            'current' => $current ? [...(array) $current, 'url' => $url($current)] : null,
            'pending' => $pending && ($reviewer || $this->access->isSelf($viewer, $employee)) ? [...(array) $pending, 'url' => $url($pending)] : null,
            'history' => $reviewer ? $rows->map(fn (object $photo): array => (array) $photo)->values()->all() : [],
        ];
    }

    // ---------------------------------------------------------------- documents

    /**
     * @param  array{document_type_value_id: int, title: string, description?: ?string, qualification_id?: ?int, issuer?: ?string, issued_on?: ?string, expires_on?: ?string}  $meta
     */
    public function storeDocument(User $actor, Employee $employee, UploadedFile $file, array $meta, ?int $supersedesId, ?string $ip): int
    {
        $type = $this->documentType((int) $meta['document_type_value_id']);
        $classification = $type['classification'];
        abort_unless($this->access->canUploadDocument($actor, $employee, $classification), 403, 'رفع هذا النوع من الوثائق للموارد البشرية.');
        [$extension, $mime] = $this->checkDocument($file);
        $this->checkQuota($employee, (int) $file->getSize());
        if (! empty($meta['qualification_id'])) {
            abort_unless(DB::table('employee_qualifications')->where('id', $meta['qualification_id'])->where('employee_id', $employee->id)->exists(), 422, 'المؤهل لا يخص هذا الموظف.');
        }

        $path = Storage::disk('local')->putFileAs('hr/employees/'.$employee->id.'/documents', $file, Str::uuid().'.'.$extension);
        try {
            return DB::transaction(function () use ($actor, $employee, $file, $meta, $supersedesId, $classification, $mime, $path, $ip): int {
                $version = 1;
                if ($supersedesId !== null) {
                    $previous = DB::table('employee_documents')->where('id', $supersedesId)->where('employee_id', $employee->id)->lockForUpdate()->first();
                    abort_unless($previous && $previous->is_current && $previous->archived_at === null, 404);
                    abort_unless($this->access->canManage($actor), 403, 'إصدار جديد لوثيقة قائمة للموارد البشرية.');
                    DB::table('employee_documents')->where('id', $supersedesId)->update(['is_current' => false, 'updated_at' => now()]);
                    $version = (int) $previous->version_no + 1;
                }
                $id = (int) DB::table('employee_documents')->insertGetId([
                    'employee_id' => $employee->id, 'document_type_value_id' => $meta['document_type_value_id'], 'title' => mb_substr(trim($meta['title']), 0, 255),
                    'description' => $meta['description'] ?? null, 'qualification_id' => $meta['qualification_id'] ?? null, 'issuer' => $meta['issuer'] ?? null,
                    'issued_on' => $meta['issued_on'] ?? null, 'expires_on' => $meta['expires_on'] ?? null, 'classification' => $classification,
                    'verification_status' => 'pending', 'version_no' => $version, 'supersedes_id' => $supersedesId, 'is_current' => true, 'scan_status' => 'not_scanned',
                    'path' => $path, 'original_name' => $this->safeName($file->getClientOriginalName(), (string) pathinfo($path, PATHINFO_EXTENSION)), 'mime' => $mime,
                    'size_bytes' => (int) $file->getSize(), 'sha256' => hash_file('sha256', (string) $file->getRealPath()), 'uploaded_by' => $actor->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->log($actor, $supersedesId ? 'employee_document.versioned' : 'employee_document.uploaded', 'employee_documents', $id,
                    ['employee_id' => $employee->id, 'type' => $meta['document_type_value_id'], 'classification' => $classification, 'version' => $version, 'supersedes' => $supersedesId], $ip);

                return $id;
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }
    }

    /**
     * @return array{0: string, 1: string} extension and detected content type
     */
    private function checkDocument(UploadedFile $file): array
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $real = (string) $file->getRealPath();
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($real);
        $head = (string) file_get_contents($real, false, null, 0, 16);
        $signature = match ($extension) {
            'pdf' => str_starts_with($head, '%PDF-'),
            'jpg', 'jpeg' => str_starts_with($head, "\xFF\xD8\xFF"),
            'png' => str_starts_with($head, "\x89PNG\r\n\x1A\n"),
            'webp' => str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP',
            'docx' => str_starts_with($head, "PK\x03\x04") && $this->isDocx($real),
            default => false,
        };
        if (! isset(self::DOCUMENT_TYPES[$extension]) || ! $signature || ! in_array($mime, self::DOCUMENT_TYPES[$extension], true)) {
            throw ValidationException::withMessages(['file' => 'نوع الملف غير مقبول أو محتواه لا يطابق امتداده. المقبول: PDF وJPG وPNG وWEBP وDOCX.']);
        }
        $max = (int) $this->settings()->document_max_kb;
        if ($file->getSize() > $max * 1024) {
            throw ValidationException::withMessages(['file' => "حجم الملف أكبر من الحد المسموح ({$max} ك.ب)."]);
        }

        return [$extension === 'jpeg' ? 'jpg' : $extension, $mime === 'application/zip' || $mime === 'application/octet-stream' ? self::DOCUMENT_TYPES['docx'][0] : $mime];
    }

    private function isDocx(string $path): bool
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }
        $ok = $zip->locateName('word/document.xml') !== false && $zip->locateName('[Content_Types].xml') !== false;
        $zip->close();

        return $ok;
    }

    private function checkQuota(Employee $employee, int $incoming): void
    {
        $used = (int) DB::table('employee_documents')->where('employee_id', $employee->id)->sum('size_bytes')
            + (int) DB::table('employee_photos')->where('employee_id', $employee->id)->sum('size_bytes');
        $quota = (int) $this->settings()->employee_quota_mb * 1024 * 1024;
        if ($used + $incoming > $quota) {
            throw ValidationException::withMessages(['file' => 'تجاوز ملف الموظف المساحة المخصصة لوثائقه؛ راجع مدير النظام.']);
        }
    }

    private function safeName(string $name, string $extension): string
    {
        $base = (string) preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', '', pathinfo($name, PATHINFO_FILENAME));

        return mb_substr(trim($base) !== '' ? trim($base) : 'document', 0, 200).'.'.$extension;
    }

    /**
     * @return array{id: int, name: string, classification: string, qualification: bool, mandatory: bool}
     */
    public function documentType(int $id): array
    {
        $type = collect($this->documentTypes(true))->firstWhere('id', $id);
        if (! $type) {
            throw ValidationException::withMessages(['document_type_value_id' => 'اختر نوع وثيقة معتمدًا.']);
        }

        return $type;
    }

    /**
     * Document categories from «ثوابت النظام» with their confidentiality class. A missing class counts as confidential.
     *
     * @return list<array{id: int, name: string, classification: string, qualification: bool, mandatory: bool}>
     */
    public function documentTypes(bool $activeOnly = false): array
    {
        return DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')
            ->where('t.code', 'employee_document_types')->when($activeOnly, fn ($query) => $query->where('v.is_active', true)->where('t.is_active', true))
            ->orderBy('v.sort_order')->get(['v.id', 'v.name_ar', 'v.metadata', 'v.is_active'])
            ->map(function (object $type): array {
                $meta = json_decode((string) $type->metadata, true) ?: [];

                return ['id' => (int) $type->id, 'name' => $type->name_ar, 'active' => (bool) $type->is_active,
                    'classification' => in_array($meta['classification'] ?? null, ['normal', 'confidential', 'identity'], true) ? $meta['classification'] : 'confidential',
                    'qualification' => (bool) ($meta['qualification'] ?? false), 'mandatory' => (bool) ($meta['mandatory'] ?? false)];
            })->all();
    }

    /**
     * Documents the viewer may see, newest version first with its earlier versions.
     *
     * @return array{items: list<array<string, mixed>>, missing: list<string>, scanNotice: string}
     */
    public function documents(User $viewer, Employee $employee): array
    {
        $classes = $this->access->documentClasses($viewer, $employee);
        $rows = DB::table('employee_documents as d')
            ->join('master_data_values as v', 'v.id', '=', 'd.document_type_value_id')
            ->join('users as u', 'u.id', '=', 'd.uploaded_by')
            ->leftJoin('users as r', 'r.id', '=', 'd.verified_by')
            ->leftJoin('employee_qualifications as q', 'q.id', '=', 'd.qualification_id')
            ->where('d.employee_id', $employee->id)->whereIn('d.classification', $classes ?: ['-'])
            ->orderByDesc('d.id')
            ->get(['d.id', 'd.title', 'd.description', 'd.classification', 'd.verification_status', 'd.verification_note', 'd.version_no', 'd.supersedes_id', 'd.is_current',
                'd.scan_status', 'd.original_name', 'd.mime', 'd.size_bytes', 'd.issuer', 'd.issued_on', 'd.expires_on', 'd.created_at', 'd.archived_at', 'd.archive_reason',
                'd.verified_at', 'v.name_ar as type', 'd.document_type_value_id', 'u.name as uploaded_by', 'r.name as verified_by', 'q.qualification_name as qualification']);
        $missing = [];
        $held = $rows->where('is_current', true)->whereNull('archived_at')->pluck('document_type_value_id')->map(fn ($id): int => (int) $id)->all();
        foreach ($this->documentTypes(true) as $type) {
            if ($type['mandatory'] && in_array($type['classification'], $classes, true) && ! in_array($type['id'], $held, true)) {
                $missing[] = $type['name'];
            }
        }

        return [
            'items' => $rows->map(fn (object $row): array => [...(array) $row,
                'download' => route('admin.employee-files.documents.download', ['employee' => $employee->id, 'document' => $row->id], false)])->values()->all(),
            'missing' => $missing,
            'scanNotice' => 'لا يتوفر فحص مضاد للفيروسات على الخادم؛ تُسجل الملفات «غير مفحوصة» ويُتحقق من نوعها ومحتواها فقط.',
        ];
    }

    public function verify(User $actor, Employee $employee, int $documentId, string $status, ?string $note, ?string $ip): void
    {
        abort_unless($this->access->canVerify($actor), 403, 'توثيق الوثائق لرئيس الموارد البشرية.');
        if ($status === 'rejected' && trim((string) $note) === '') {
            throw ValidationException::withMessages(['note' => 'اكتب سبب رفض الوثيقة.']);
        }
        DB::transaction(function () use ($actor, $employee, $documentId, $status, $note, $ip): void {
            $document = DB::table('employee_documents')->where('id', $documentId)->where('employee_id', $employee->id)->lockForUpdate()->first();
            abort_unless($document && $document->is_current && $document->archived_at === null, 404);
            DB::table('employee_documents')->where('id', $documentId)->update(['verification_status' => $status, 'verified_by' => $actor->id, 'verified_at' => now(),
                'verification_note' => $note, 'updated_at' => now()]);
            $this->log($actor, 'employee_document.'.$status, 'employee_documents', $documentId, ['employee_id' => $employee->id, 'before' => $document->verification_status], $ip);
        });
    }

    public function archive(User $actor, Employee $employee, int $documentId, string $reason, ?string $ip): void
    {
        abort_unless($this->access->canManage($actor), 403);
        DB::transaction(function () use ($actor, $employee, $documentId, $reason, $ip): void {
            $document = DB::table('employee_documents')->where('id', $documentId)->where('employee_id', $employee->id)->lockForUpdate()->first();
            abort_unless($document && $document->archived_at === null, 404);
            DB::table('employee_documents')->where('id', $documentId)->update(['archived_at' => now(), 'archived_by' => $actor->id, 'archive_reason' => $reason, 'updated_at' => now()]);
            $this->log($actor, 'employee_document.archived', 'employee_documents', $documentId, ['employee_id' => $employee->id], $ip);
        });
    }

    /**
     * Streams a document to an authorised viewer and records the download.
     */
    public function download(User $viewer, Employee $employee, int $documentId, ?string $ip): StreamedResponse
    {
        abort_unless($this->access->canView($viewer, $employee), 403);
        $document = DB::table('employee_documents')->where('id', $documentId)->where('employee_id', $employee->id)->first();
        abort_unless($document && in_array($document->classification, $this->access->documentClasses($viewer, $employee), true), 404);
        abort_unless(Storage::disk('local')->exists($document->path), 404, 'الملف غير موجود على الخادم.');
        $this->log($viewer, 'employee_document.downloaded', 'employee_documents', $documentId, ['employee_id' => $employee->id, 'classification' => $document->classification], $ip);

        return Storage::disk('local')->download($document->path, $document->original_name, [
            'Content-Type' => $document->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata  never file contents or personal values
     */
    private function log(User $actor, string $action, string $table, int $id, array $metadata, ?string $ip): void
    {
        DB::table('audit_logs')->insert([
            'actor_id' => $actor->id, 'action' => $action, 'subject_type' => $table, 'subject_id' => (string) $id,
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE), 'ip_address' => $ip, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
