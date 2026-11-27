<?php

namespace App\Services\Workflow;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Correspondence\CorrespondenceAccess;
use App\Services\WorkflowVisibilityService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Photos and documents on generic requests (maintenance, service, advance, purchase). Confidential workflows
 * (complaints) take none: see offeredOn().
 *
 * Each file belongs to the timeline event it was sent with: the submission, or an action on a step. Files use the
 * shared workflow_event_attachments table and the storage conventions of the correspondence registry: the private
 * disk, a random stored name, the original name sanitized, a SHA-256 checksum. There are no public links; a file
 * is opened only through WorkflowAttachmentController, which asks WorkflowVisibilityService on every request.
 *
 * The type is decided by the file's content, never by its name alone.
 */
final class RequestAttachments
{
    public const MAX_FILES = 5;

    public const MAX_FILE_KB = 10240;

    /** Allowed extensions and the type their content must have. */
    public const TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    /** A Word or Excel file is a zip archive: the part that makes it one. */
    private const OFFICE_PARTS = ['docx' => 'word/document.xml', 'xlsx' => 'xl/workbook.xml'];

    private const DIRECTORY = 'workflow-requests';

    private const ALLOWED_LABEL = 'صور JPEG وPNG وWebP، وملفات PDF وWord (docx) وExcel (xlsx)';

    private const NOT_OFFERED = 'هذا النوع من الطلبات لا يقبل مرفقات.';

    public function __construct(private readonly AuditService $audit) {}

    /**
     * Whether requests on this workflow version take attachments. A confidential workflow (complaints) does not:
     * its files would open to the full view only, a view nobody holds on such a request, so they could be uploaded
     * and never read. None are accepted until it is decided who reads them.
     */
    public function offeredOn(int $versionId): bool
    {
        return ! WorkflowVisibilityService::confidentialVersions()->where('cv.id', $versionId)->exists();
    }

    /**
     * Limits for one action: at most 5 files of 10 MB each, or the correspondence policy in force where it is
     * stricter. Shaped for the upload control too.
     *
     * @return array{files: int, file_kb: int, total_kb: int, accept: string, allowed: string}
     */
    public function limits(): array
    {
        $policy = app(CorrespondenceAccess::class)->policies('settings')->first();
        $config = $policy ? (json_decode((string) $policy->configuration, true) ?: []) : [];
        $stricter = fn (int $limit, string $key): int => (int) ($config[$key] ?? 0) > 0 ? min($limit, (int) $config[$key]) : $limit;
        $files = $stricter(self::MAX_FILES, 'file_count');
        $fileKb = $stricter(self::MAX_FILE_KB, 'file_kb');

        return [
            'files' => $files,
            'file_kb' => $fileKb,
            'total_kb' => $stricter($files * $fileKb, 'total_kb'),
            'accept' => implode(',', array_map(fn (string $extension): string => '.'.$extension, array_keys(self::TYPES))),
            'allowed' => self::ALLOWED_LABEL,
        ];
    }

    /**
     * Check the whole set before anything is saved: count, size, and the type read from each file's content.
     * Errors are reported under "attachments" (the set) or "attachments.N" (one file).
     *
     * @param  list<mixed>  $files
     * @param  bool  $offered  whether this workflow takes attachments at all (offeredOn())
     */
    public function validate(array $files, bool $offered = true): void
    {
        if ($files === []) {
            return;
        }
        if (! $offered) {
            throw ValidationException::withMessages(['attachments' => self::NOT_OFFERED]);
        }
        $limits = $this->limits();
        if (count($files) > $limits['files']) {
            throw ValidationException::withMessages(['attachments' => 'يمكن إرفاق '.$limits['files'].' ملفات على الأكثر مع كل إجراء.']);
        }
        $total = 0;
        foreach (array_values($files) as $index => $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                throw ValidationException::withMessages(["attachments.{$index}" => 'لم يكتمل رفع الملف؛ تحقق من حجمه ثم أعد المحاولة.']);
            }
            $name = $this->safeName($file);
            $size = (int) $file->getSize();
            $total += $size;
            if ($size <= 0 || $size > $limits['file_kb'] * 1024) {
                throw ValidationException::withMessages(["attachments.{$index}" => "حجم الملف «{$name}» يتجاوز الحد المسموح (".$this->megabytes($limits['file_kb']).' م.ب للملف).']);
            }
            if ($total > $limits['total_kb'] * 1024) {
                throw ValidationException::withMessages(['attachments' => 'مجموع أحجام الملفات يتجاوز الحد المسموح ('.$this->megabytes($limits['total_kb']).' م.ب).']);
            }
            if ($this->contentType($file) === null) {
                throw ValidationException::withMessages(["attachments.{$index}" => "نوع الملف «{$name}» أو محتواه غير مسموح. المسموح: ".self::ALLOWED_LABEL.'.']);
            }
        }
    }

    /**
     * Save validated files against the event they were sent with. Must run inside the caller's transaction, after
     * the move that created the event; a failure there undoes the rows, and the caller removes $written.
     *
     * @param  list<UploadedFile>  $files  already checked by validate()
     * @param  list<string>  $written  stored paths, to remove if any part of the transaction fails
     */
    public function store(object $instance, int $eventId, User $actor, array $files, array &$written, ?string $ipAddress = null): void
    {
        if ($files === []) {
            return;
        }
        // Nothing is written on a confidential request, whatever the caller checked before.
        if (! $this->offeredOn((int) $instance->workflow_version_id)) {
            throw ValidationException::withMessages(['attachments' => self::NOT_OFFERED]);
        }
        $class = 'internal';

        foreach ($files as $file) {
            $uuid = (string) Str::uuid();
            $path = Storage::disk('local')->putFileAs(self::DIRECTORY.'/'.$instance->public_id, $file, $uuid);
            if (! $path) {
                throw new \RuntimeException('Unable to store the complete attachment set.');
            }
            $written[] = $path;
            $filename = $this->safeName($file);
            $checksum = hash_file('sha256', $file->getRealPath());
            $now = now();
            $id = DB::table('workflow_event_attachments')->insertGetId([
                'workflow_event_id' => $eventId, 'correspondence_action_id' => null, 'uploaded_by' => $actor->id,
                'public_id' => $uuid, 'original_filename' => $filename, 'storage_disk' => 'local', 'storage_path' => $path,
                'mime_type' => $this->contentType($file) ?? 'application/octet-stream', 'file_size' => $file->getSize(), 'sha256_checksum' => $checksum,
                'visibility_class' => $class, 'document_type' => 'supporting_document', 'document_version' => 1,
                'replaces_attachment_id' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->audit->recordRow('workflow.attachment.uploaded', 'workflow_event_attachments', $id, null, [
                'reference' => $instance->reference_no, 'event_id' => $eventId, 'filename' => $filename,
                'bytes' => (int) $file->getSize(), 'sha256' => $checksum, 'class' => $class,
            ], (int) $actor->id, $ipAddress);
        }
    }

    /**
     * The move the actor has just made on the request, inside the same transaction: the event its files belong to.
     * The request row is still locked by that move, so no other move can come between.
     */
    public function eventOf(int $instanceId, int $actorId): int
    {
        $eventId = DB::table('workflow_events')->where('workflow_instance_id', $instanceId)->where('actor_id', $actorId)
            ->whereNotNull('workflow_transition_id')->orderByDesc('id')->value('id');
        if ($eventId === null) {
            throw new \RuntimeException('No workflow event to attach the files to.');
        }

        return (int) $eventId;
    }

    /**
     * @param  list<string>  $paths
     */
    public function cleanup(array $paths): void
    {
        foreach ($paths as $path) {
            Storage::disk('local')->delete($path);
        }
    }

    /**
     * The file's type as its content shows it, or null when it is not an allowed type or does not match its name.
     */
    private function contentType(UploadedFile $file): ?string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $expected = self::TYPES[$extension] ?? null;
        $path = $file->getRealPath();
        if ($expected === null || ! is_string($path) || $path === '') {
            return null;
        }
        $detected = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        if (isset(self::OFFICE_PARTS[$extension])) {
            // Detection of Office files varies (their own type, or a plain zip): the archive's parts decide.
            if (! class_exists(\ZipArchive::class)) {
                return $detected === $expected ? $expected : null;
            }

            return in_array($detected, [$expected, 'application/zip', 'application/octet-stream'], true)
                && $this->zipHolds($path, ['[Content_Types].xml', self::OFFICE_PARTS[$extension]]) ? $expected : null;
        }
        if ($detected !== $expected) {
            return null;
        }

        return ! str_starts_with($expected, 'image/') || @getimagesize($path) !== false ? $expected : null;
    }

    /**
     * @param  list<string>  $parts
     */
    private function zipHolds(string $path, array $parts): bool
    {
        $zip = new \ZipArchive;
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            return false;
        }
        try {
            foreach ($parts as $part) {
                if ($zip->locateName($part) === false) {
                    return false;
                }
            }

            return true;
        } finally {
            $zip->close();
        }
    }

    private function safeName(UploadedFile $file): string
    {
        $name = mb_substr((string) preg_replace('/[\x00-\x1f\x7f\\\\\/]/u', '_', $file->getClientOriginalName()), 0, 200);

        // A name that is not valid text is not kept; the extension was already matched against the content.
        return $name !== '' ? $name : 'attachment.'.strtolower($file->getClientOriginalExtension());
    }

    private function megabytes(int $kilobytes): string
    {
        return rtrim(rtrim(number_format($kilobytes / 1024, 1, '.', ''), '0'), '.');
    }
}
