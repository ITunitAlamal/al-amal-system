<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MaintenanceDraftService
{
    public function create(User $user, array $data): string
    {
        $this->ensureActiveUser($user);

        return DB::transaction(function () use ($user, $data) {
            $publicId = (string) Str::uuid();

            $requestId = DB::table('aims_requests')->insertGetId([
                'public_id' => $publicId,
                'request_type' => 'maintenance',
                'title' => $data['title'],
                'description' => $data['description'],
                'requested_by' => $user->id,
                'department_id' => $user->department_id,
                'status' => 'draft',
                'revision' => 1,
                'submitted_at' => null,
                'completed_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('maintenance_request_details')->insert([
                'aims_request_id' => $requestId,
                'location' => $data['location'],
                'fault_type' => $data['fault_type'],
                'priority' => $data['priority'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $publicId;
        });
    }

    public function update(
        User $user,
        string $publicId,
        array $data
    ): void {
        $this->ensureActiveUser($user);

        DB::transaction(function () use ($user, $publicId, $data) {
            $request = DB::table('aims_requests')
                ->where('public_id', $publicId)
                ->where('request_type', 'maintenance')
                ->where('requested_by', $user->id)
                ->lockForUpdate()
                ->first();

            abort_unless($request, 404, 'مسودة الخدمة غير موجودة.');

            if ($request->status !== 'draft') {
                throw ValidationException::withMessages([
                    'status' => 'لا يمكن تعديل الطلب بعد إرساله.',
                ]);
            }

            DB::table('aims_requests')
                ->where('id', $request->id)
                ->update([
                    'title' => $data['title'],
                    'description' => $data['description'],
                    'revision' => DB::raw('revision + 1'),
                    'updated_at' => now(),
                ]);

            DB::table('maintenance_request_details')
                ->where('aims_request_id', $request->id)
                ->update([
                    'location' => $data['location'],
                    'fault_type' => $data['fault_type'],
                    'priority' => $data['priority'],
                    'updated_at' => now(),
                ]);
        });
    }

    public function getDraft(
        User $user,
        string $publicId
    ): array {
        $this->ensureActiveUser($user);

        $draft = DB::table('aims_requests as ar')
            ->join(
                'maintenance_request_details as md',
                'md.aims_request_id',
                '=',
                'ar.id'
            )
            ->where('ar.public_id', $publicId)
            ->where('ar.request_type', 'maintenance')
            ->where('ar.requested_by', $user->id)
            ->where('ar.status', 'draft')
            ->first([
                'ar.public_id',
                'ar.title',
                'ar.description',
                'ar.revision',
                'md.location',
                'md.fault_type',
                'md.priority',
            ]);

        abort_unless($draft, 404, 'مسودة الصيانة غير موجودة.');

        return (array) $draft;
    }

    private function ensureActiveUser(User $user): void
    {
        abort_unless(
            $user->is_active,
            403,
            'حساب المستخدم غير نشط.'
        );

        if (! $user->department_id) {
            throw ValidationException::withMessages([
                'department_id' => 'يجب ربط حسابك بقسم إداري قبل إنشاء طلب الصيانة.',
            ]);
        }

        $validDepartment = DB::table('departments')
            ->where('id', $user->department_id)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->exists();

        if (! $validDepartment) {
            throw ValidationException::withMessages([
                'department_id' => 'القسم الإداري المرتبط بحسابك غير نشط.',
            ]);
        }
    }
}
