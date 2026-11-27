<?php
namespace App\Models;
use App\Services\Organization\EffectiveAssignments;
use Illuminate\Database\Eloquent\Model;
class DepartmentUserRole extends Model {
    protected $fillable = ['user_id','role_id','department_id'];
    public function role() { return $this->belongsTo(Role::class); }
    /** Only assignments within their appointment period (starts_on/ends_on). */
    public function scopeEffective($q) { return EffectiveAssignments::apply($q, $this->getTable()); }
}
