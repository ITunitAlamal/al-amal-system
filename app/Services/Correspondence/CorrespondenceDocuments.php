<?php

namespace App\Services\Correspondence;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CorrespondenceDocuments
{
    public const MIME = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'txt' => 'text/plain'];

    public function __construct(private readonly CorrespondenceAccess $access) {}

    public function validate(array $files): array
    {
        $policy = $this->access->settings();
        if (count($files) > $policy['file_count']) {
            throw ValidationException::withMessages(['attachments' => 'عدد الملفات يتجاوز الحد المسموح.']);
        }
        $total = 0;
        $fingerprints = [];
        foreach ($files as $index => $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                throw ValidationException::withMessages(["attachments.{$index}" => 'لم يكتمل رفع الملف.']);
            }
            $extension = strtolower($file->getClientOriginalExtension());
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
            if (! isset(self::MIME[$extension]) || ! in_array($extension, $policy['allowed_extensions'], true)
                || $mime !== self::MIME[$extension] || ! in_array($file->getClientMimeType(), [$mime, 'application/octet-stream'], true)) {
                throw ValidationException::withMessages(["attachments.{$index}" => 'نوع الملف أو محتواه غير مسموح.']);
            }
            $size = $file->getSize();
            $total += $size;
            if ($size <= 0 || $size > $policy['file_kb'] * 1024 || $total > $policy['total_kb'] * 1024) {
                throw ValidationException::withMessages(['attachments' => 'حجم الملف أو مجموع أحجام الملفات يتجاوز الحد المسموح.']);
            }
            $fingerprints[] = ['name' => $file->getClientOriginalName(), 'bytes' => $size, 'sha256' => hash_file('sha256', $file->getRealPath())];
        }

        return $fingerprints;
    }

    /** @param list<string> $written Files to remove if any part of the database transaction fails. */
    public function store(User $actor, object $letter, int $action, array $files, array $replacements, array &$written): void
    {
        foreach ($files as $index => $file) {
            $previous = null;
            if (! empty($replacements[$index])) {
                $previous = DB::table('workflow_event_attachments as f')->join('correspondence_actions as a', 'a.id', '=', 'f.correspondence_action_id')
                    ->where('f.public_id', $replacements[$index])->where('a.correspondence_id', $letter->id)->first(['f.*']);
                abort_unless($previous && $this->access->actionVisible($actor, (int) $previous->correspondence_action_id), 404);
                abort_if(DB::table('workflow_event_attachments')->where('replaces_attachment_id', $previous->id)->exists(), 409, 'يوجد إصدار أحدث لهذا المرفق؛ أعد تحميل المراسلة.');
            }
            $uuid = (string) Str::uuid();
            $path = Storage::disk('local')->putFileAs('correspondence/'.$letter->public_id, $file, $uuid);
            if (! $path) {
                throw new \RuntimeException('Unable to store the complete attachment set.');
            }
            $written[] = $path;
            $filename = mb_substr(preg_replace('/[\x00-\x1f\x7f\\\\\/]/u', '_', $file->getClientOriginalName()), 0, 200);
            DB::table('workflow_event_attachments')->insert([
                'workflow_event_id' => null, 'correspondence_action_id' => $action, 'uploaded_by' => $actor->id,
                'public_id' => $uuid, 'original_filename' => $filename, 'storage_disk' => 'local', 'storage_path' => $path,
                'mime_type' => $file->getMimeType(), 'file_size' => $file->getSize(), 'sha256_checksum' => hash_file('sha256', $file->getRealPath()),
                'visibility_class' => DB::table('master_data_values')->where('id', $letter->classification_value_id)->value('code'),
                'document_type' => 'supporting_document', 'document_version' => $previous ? (int) $previous->document_version + 1 : 1,
                'replaces_attachment_id' => $previous?->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function cleanup(array $paths): void
    {
        foreach ($paths as $path) {
            Storage::disk('local')->delete($path);
        }
    }
}
