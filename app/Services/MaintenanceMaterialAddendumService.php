<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MaintenanceMaterialAddendumService
{
    private const ALLOWED_CAPACITIES = [
        'supervisor',
        'hr_head',
    ];

    public function createDraft(
        User $user,
        string $requestPublicId,
        array $data
    ): string {
        $this->ensureActiveUser($user);

        return DB::transaction(function () use (
            $user,
            $requestPublicId,
            $data
        ) {
            $request = DB::table('aims_requests')
                ->where('public_id', $requestPublicId)
                ->where('request_type', 'maintenance')
                ->lockForUpdate()
                ->first();

            abort_unless(
                $request,
                404,
                'طلب الصيانة غير موجود.'
            );

            $this->ensureEligibleRequest($request);

            $capacity = $this->resolveCapacity(
                $user,
                (int) $request->id
            );

            if ($capacity === null) {
                abort(
                    403,
                    'غير مخوّل لك بإنشاء ملحق مواد لهذا الطلب.'
                );
            }

            $this->validatePayload($data);

            $nextNumber = (
                (int) DB::table('maintenance_material_addenda')
                    ->where('aims_request_id', $request->id)
                    ->max('addendum_number')
            ) + 1;

            $publicId = (string) Str::uuid();

            $addendumId = DB::table(
                'maintenance_material_addenda'
            )->insertGetId([
                'public_id' => $publicId,
                'aims_request_id' => $request->id,
                'addendum_number' => $nextNumber,
                'requested_by' => $user->id,
                'requester_capacity' => $capacity,
                'justification' => trim($data['justification']),
                'status' => 'draft',
                'revision' => 1,
                'submitted_at' => null,
                'approved_at' => null,
                'completed_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->insertItems(
                $addendumId,
                $data['items']
            );

            return $publicId;
        }, 3);
    }

    public function updateDraft(
        User $user,
        string $addendumPublicId,
        array $data
    ): void {
        $this->ensureActiveUser($user);

        DB::transaction(function () use (
            $user,
            $addendumPublicId,
            $data
        ) {
            $addendum = DB::table(
                'maintenance_material_addenda'
            )
                ->where('public_id', $addendumPublicId)
                ->where('requested_by', $user->id)
                ->lockForUpdate()
                ->first();

            abort_unless(
                $addendum,
                404,
                'مسودة الملحق غير موجودة.'
            );

            if ($addendum->status !== 'draft') {
                throw ValidationException::withMessages([
                    'status' => 'لا يمكن تعديل الملحق بعد إرساله.',
                ]);
            }

            $request = DB::table('aims_requests')
                ->where('id', $addendum->aims_request_id)
                ->where('request_type', 'maintenance')
                ->first();

            abort_unless($request, 404);

            $this->ensureEligibleRequest($request);

            $capacity = $this->resolveCapacity(
                $user,
                (int) $request->id
            );

            abort_unless(
                $capacity !== null,
                403,
                'لم تعد مخوّلًا بتعديل هذا الملحق.'
            );

            $this->validatePayload($data);

            DB::table('maintenance_material_addenda')
                ->where('id', $addendum->id)
                ->update([
                    'justification' => trim($data['justification']),
                    'revision' => DB::raw('revision + 1'),
                    'updated_at' => now(),
                ]);

            DB::table('maintenance_material_addendum_items')
                ->where(
                    'maintenance_material_addendum_id',
                    $addendum->id
                )
                ->delete();

            $this->insertItems(
                (int) $addendum->id,
                $data['items']
            );
        }, 3);
    }

    public function getDraft(
        User $user,
        string $addendumPublicId
    ): array {
        $this->ensureActiveUser($user);

        $addendum = DB::table(
            'maintenance_material_addenda as a'
        )
            ->join(
                'aims_requests as r',
                'r.id',
                '=',
                'a.aims_request_id'
            )
            ->where('a.public_id', $addendumPublicId)
            ->where('a.requested_by', $user->id)
            ->where('a.status', 'draft')
            ->where('r.request_type', 'maintenance')
            ->first([
                'a.id',
                'a.public_id',
                'a.addendum_number',
                'a.justification',
                'a.revision',
                'r.public_id as request_public_id',
                'r.status as request_status',
            ]);

        abort_unless(
            $addendum,
            404,
            'مسودة الملحق غير موجودة.'
        );

        if (! in_array(
            $addendum->request_status,
            ['submitted', 'in_progress'],
            true
        )) {
            abort(403, 'طلب الصيانة ليس قيد التنفيذ.');
        }

        $capacity = $this->resolveCapacity(
            $user,
            (int) DB::table('maintenance_material_addenda')
                ->where('id', $addendum->id)
                ->value('aims_request_id')
        );

        abort_unless($capacity !== null, 403);

        $items = DB::table(
            'maintenance_material_addendum_items'
        )
            ->where(
                'maintenance_material_addendum_id',
                $addendum->id
            )
            ->orderBy('id')
            ->get([
                'material_name',
                'specification',
                'unit',
                'requested_quantity',
                'notes',
            ])
            ->map(fn ($item) => (array) $item)
            ->all();

        return [
            'public_id' => $addendum->public_id,
            'request_public_id' => $addendum->request_public_id,
            'addendum_number' => $addendum->addendum_number,
            'justification' => $addendum->justification,
            'revision' => $addendum->revision,
            'items' => $items,
        ];
    }

    private function ensureEligibleRequest(object $request): void
    {
        if (! in_array(
            $request->status,
            ['submitted', 'in_progress'],
            true
        )) {
            throw ValidationException::withMessages([
                'request' => 'يمكن إنشاء ملحق مواد فقط لطلب صيانة مرسل وقيد المعالجة.',
            ]);
        }

        $instanceExists = DB::table('workflow_instances')
            ->where('subject_type', 'aims_request')
            ->where('subject_id', $request->id)
            ->whereIn('status', ['pending', 'in_progress'])
            ->whereNull('completed_at')
            ->exists();

        if (! $instanceExists) {
            throw ValidationException::withMessages([
                'request' => 'لا توجد معاملة صيانة نشطة مرتبطة بهذا الطلب.',
            ]);
        }
    }

    private function resolveCapacity(
        User $user,
        int $requestId
    ): ?string {
        $request = DB::table('aims_requests')
            ->where('id', $requestId)
            ->first(['id']);

        if (! $request) {
            return null;
        }

        /*
         * رئيس قسم الموارد البشرية والخدمات العامة
         * والصيانة، وفق التعيين الفعلي.
         */
        $isHrHead = DB::table('departments as d')
            ->join(
                'department_user_roles as dur',
                function ($join) {
                    $join->on(
                        'dur.department_id',
                        '=',
                        'd.id'
                    );
                }
            )
            ->join(
                'roles as role',
                'role.id',
                '=',
                'dur.role_id'
            )
            ->where('d.head_user_id', $user->id)
            ->where('dur.user_id', $user->id)
            ->where('d.is_active', true)
            ->whereNull('d.deleted_at')
            ->where('role.code', 'department_head')
            ->where(function ($query) {
                $query->where('d.code', 'HR')
                    ->orWhere('d.code', 'HR_01');
            })
            ->exists();

        if ($isHrHead) {
            return 'hr_head';
        }

        /*
         * مشرف مقاول معين رسميًا.
         * لا نفترض أن مجرد التكليف الحالي
         * في workflow_instances يمنح صفة المشرف.
         *
         * سيُربط بالجدول المعتمد لتكليف
         * مشرفي الصيانة الخارجية عند إنشائه.
         */

        return null;
    }

    private function validatePayload(array $data): void
    {
        if (
            ! isset($data['justification']) ||
            ! is_string($data['justification']) ||
            trim($data['justification']) === ''
        ) {
            throw ValidationException::withMessages([
                'justification' => 'مبررات الملحق مطلوبة.',
            ]);
        }

        if (
            ! isset($data['items']) ||
            ! is_array($data['items']) ||
            count($data['items']) < 1 ||
            count($data['items']) > 100
        ) {
            throw ValidationException::withMessages([
                'items' => 'يجب إدخال مادة واحدة على الأقل وبحد أقصى 100 مادة.',
            ]);
        }

        foreach ($data['items'] as $index => $item) {
            if (
                ! is_array($item) ||
                empty(trim((string) ($item['material_name'] ?? ''))) ||
                empty(trim((string) ($item['unit'] ?? ''))) ||
                ! isset($item['requested_quantity']) ||
                ! is_numeric($item['requested_quantity']) ||
                (float) $item['requested_quantity'] <= 0
            ) {
                throw ValidationException::withMessages([
                    "items.$index" => 'بيانات المادة أو الكمية غير صحيحة.',
                ]);
            }
        }
    }

    private function insertItems(
        int $addendumId,
        array $items
    ): void {
        $now = now();

        $rows = [];

        foreach ($items as $item) {
            $rows[] = [
                'maintenance_material_addendum_id' => $addendumId,
                'material_name' => trim((string) $item['material_name']),
                'specification' => $item['specification'] ?? null,
                'unit' => trim((string) $item['unit']),
                'requested_quantity' => $item['requested_quantity'],
                'notes' => $item['notes'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('maintenance_material_addendum_items')
            ->insert($rows);
    }

    private function ensureActiveUser(User $user): void
    {
        abort_unless(
            $user->is_active,
            403,
            'حساب المستخدم غير نشط.'
        );
    }
}
