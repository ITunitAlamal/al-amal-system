<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class DepartmentUserRole extends Model {
    protected $fillable = ['user_id','role_id','department_id'];
    public function role() { return $this->belongsTo(Role::class); }
}
