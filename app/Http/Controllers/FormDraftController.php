<?php

namespace App\Http\Controllers;

use App\Models\FormDraft;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FormDraftController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | AIMS - Form Draft Controller
    |--------------------------------------------------------------------------
    | حفظ واسترجاع المسودات التجريبية
    | المرحلة الحالية: نموذج صيانة تجريبي
    | الوصول: مدير النظام فقط
    |--------------------------------------------------------------------------
    */

    private const FORM_TYPE = 'maintenance_demo';

    // =================================================
    // 1. استرجاع المسودة
    // =================================================

    public function show(
        Request $request,
        string $draftKey
    ): JsonResponse {

        // حماية الوصول - مدير النظام فقط
        abort_unless(
            $request->user()?->isSystemAdmin(),
            403,
            'هذه الخدمة التجريبية متاحة لمدير النظام فقط.'
        );

        // البحث عن المسودة الخاصة بالمستخدم الحالي فقط
        $draft = FormDraft::query()
            ->where('user_id', $request->user()->id)
            ->where('draft_key', $draftKey)
            ->first();

        // إذا لم توجد مسودة، نعيد نموذجًا فارغًا
        if (! $draft) {
            return response()
                ->json([
                    'exists' => false,
                    'version' => 0,
                    'payload' => [
                        'title' => '',
                        'details' => '',
                    ],
                    'last_saved_at' => null,
                ])
                ->header('Cache-Control', 'no-store');
        }

        // التحقق من نوع النموذج
        abort_unless(
            $draft->form_type === self::FORM_TYPE,
            404
        );

        // إعادة المسودة المحفوظة
        return response()
            ->json([
                'exists' => true,
                'version' => $draft->version,
                'payload' => $draft->payload,
                'last_saved_at' => $draft->last_saved_at?->toIso8601String(),
            ])
            ->header('Cache-Control', 'no-store');
    }

    // =================================================
    // 2. حفظ المسودة أو تحديثها
    // =================================================

    public function save(
        Request $request,
        string $draftKey,
        AuditService $audit
    ): JsonResponse {

        // حماية الوصول - مدير النظام فقط
        abort_unless(
            $request->user()?->isSystemAdmin(),
            403,
            'هذه الخدمة التجريبية متاحة لمدير النظام فقط.'
        );

        // التحقق من البيانات والحقول المسموحة
        $data = $request->validate([
            'version' => [
                'required',
                'integer',
                'min:0',
            ],

            'payload' => [
                'required',
                'array:title,details',
            ],

            'payload.title' => [
                'required',
                'string',
                'max:150',
            ],

            'payload.details' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ]);

        $userId = $request->user()->id;

        // تنفيذ الحفظ والتدقيق في معاملة واحدة
        $result = DB::transaction(function () use (
            $request,
            $draftKey,
            $data,
            $userId,
            $audit
        ) {

            $now = now();

            // إنشاء صف المسودة إذا لم يكن موجودًا
            // القيد الفريد يمنع تكرار المفتاح لنفس المستخدم
            DB::table('form_drafts')->insertOrIgnore([
                'user_id' => $userId,
                'draft_key' => $draftKey,
                'form_type' => self::FORM_TYPE,
                'payload' => '{}',
                'version' => 0,
                'last_saved_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // قفل الصف لمنع تعديل نسختين بالتزامن
            $draft = FormDraft::query()
                ->where('user_id', $userId)
                ->where('draft_key', $draftKey)
                ->lockForUpdate()
                ->firstOrFail();

            // السماح بنوع النموذج التجريبي فقط
            abort_unless(
                $draft->form_type === self::FORM_TYPE,
                404
            );

            // منع الكتابة فوق نسخة أحدث
            if ($draft->version !== (int) $data['version']) {
                return [
                    'conflict' => true,
                    'version' => $draft->version,
                ];
            }

            $previousVersion = $draft->version;

            // تحديث المسودة
            $draft->update([
                'payload' => [
                    'title' => $data['payload']['title'],
                    'details' => $data['payload']['details'] ?? '',
                ],
                'version' => $previousVersion + 1,
                'last_saved_at' => now(),
            ]);

            // تسجيل عملية الحفظ دون نسخ نص المسودة
            // إلى سجل النشاط المركزي
            $audit->record(
                $request,
                'form_draft.saved',
                $draft,
                [
                    'form_type' => self::FORM_TYPE,
                    'version' => $previousVersion,
                ],
                [
                    'form_type' => self::FORM_TYPE,
                    'version' => $draft->version,
                ]
            );

            return [
                'conflict' => false,
                'version' => $draft->version,
                'last_saved_at' => $draft->last_saved_at->toIso8601String(),
            ];

        }, 3);

        // حالة وجود تعارض مع نسخة أحدث
        if ($result['conflict']) {
            return response()
                ->json([
                    'message' => 'توجد نسخة أحدث من هذه المسودة. يرجى مراجعتها قبل المتابعة.',
                    'current_version' => $result['version'],
                ], 409)
                ->header('Cache-Control', 'no-store');
        }

        // تأكيد نجاح الحفظ
        return response()
            ->json([
                'message' => 'تم حفظ المسودة على الخادم.',
                'version' => $result['version'],
                'last_saved_at' => $result['last_saved_at'],
            ])
            ->header('Cache-Control', 'no-store');
    }
}
