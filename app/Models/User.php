<?php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class User extends Authenticatable
{
    use HasFactory, Notifiable;
    protected $fillable = ['name','email','password','department_id','employee_number','is_active'];
    protected $hidden = ['password','remember_token'];
    protected function casts(): array { return ['email_verified_at'=>'datetime','password'=>'hashed','is_active'=>'boolean']; }

    public function hasPermissionInAnyScope(string $permissionCode): bool
    {
        if (!$this->is_active) return false;
        return $this->roleAssignments()->whereHas('role.permissions',
            fn($query) => $query->where('code', $permissionCode))->exists();
    }

    public function department() { return $this->belongsTo(Department::class); }
    public function roleAssignments() { return $this->hasMany(DepartmentUserRole::class); }

    // Explicit grant + department scope. A system administrator receives no implicit access.
    public function hasPermission(string $permissionCode, ?int $departmentId = null): bool
    {
        if (!$this->is_active) return false;
        return $this->roleAssignments()
            ->where(function ($query) use ($departmentId) {
                // Null department assignment is an explicitly granted institution-wide role.
                if ($departmentId === null) {
                    $query->whereNull('department_id');
                } else {
                    $query->where(function ($q) use ($departmentId) {
                        $q->where('department_id', $departmentId)->orWhereNull('department_id');
                    });
                }
            })
            ->whereHas('role.permissions', fn($query) => $query->where('code', $permissionCode))
            ->exists();
    }
}
