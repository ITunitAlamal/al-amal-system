<?php

namespace App\Http\Controllers;

use App\Services\WorkflowVisibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkflowDetailsController extends Controller
{
    public function show(
        Request $request,
        string $publicId,
        WorkflowVisibilityService $visibility
    ): JsonResponse {
        $user = $request->user();

        abort_unless(
            $user && $user->is_active,
            403,
            'الحساب غير مخوّل بالوصول.'
        );

        $instance = DB::table('workflow_instances')
            ->where('public_id', $publicId)
            ->first(['id']);

        abort_unless($instance, 404, 'المعاملة غير موجودة.');

        $details = $visibility->detail(
            $user,
            (int) $instance->id
        );

        return response()
            ->json([
                'data' => $details,
            ])
            ->header('Cache-Control', 'no-store, private');
    }
}
