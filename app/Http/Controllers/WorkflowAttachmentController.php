<?php

namespace App\Http\Controllers;

use App\Services\AuditService;
use App\Services\WorkflowVisibilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Opens a file attached to a workflow request. There are no public links: every download is checked here against
 * the viewer's access to the request and to that file (WorkflowVisibilityService::mayOpenAttachment), and recorded.
 */
class WorkflowAttachmentController extends Controller
{
    public function download(Request $request, string $publicId, string $attachment, WorkflowVisibilityService $visibility, AuditService $audit): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user?->is_active, 403);
        $instance = DB::table('workflow_instances')->where('public_id', $publicId)->first();
        abort_unless($instance, 404);
        $level = $visibility->accessLevel($user, (int) $instance->id);
        abort_unless($level !== null, 403, 'غير مصرح لك بعرض هذه المعاملة.');

        $file = $visibility->attachment($instance, $attachment);
        abort_unless($file, 404);
        abort_unless($visibility->mayOpenAttachment($user, $instance, $level, $file), 403, 'لا تملك صلاحية فتح هذا المرفق.');
        abort_unless(Storage::disk($file->storage_disk)->exists($file->storage_path), 404, 'ملف المرفق غير موجود في التخزين.');

        $audit->recordRow('workflow.attachment.download', 'workflow_event_attachments', $file->id, null,
            ['reference' => $instance->reference_no, 'event_id' => $file->workflow_event_id], (int) $user->id, $request->ip());

        // Always a download, never rendered in the page's origin.
        return Storage::disk($file->storage_disk)->download($file->storage_path, $file->original_filename, [
            'Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ]);
    }
}
