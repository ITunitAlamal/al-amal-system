<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Where a household's entitlements are paid. Encrypted, shown masked, and usable only after Accounts approves it. */
class OrphanPaymentInstruction extends Model
{
    public const STATUS_LABELS = ['pending_review' => 'بانتظار اعتماد الحسابات', 'approved' => 'معتمدة', 'rejected' => 'مرفوضة', 'superseded' => 'مستبدلة'];

    protected $guarded = ['id'];

    protected $hidden = ['account_identifier', 'account_hash', 'account_holder'];

    protected function casts(): array
    {
        return ['account_holder' => 'encrypted', 'account_identifier' => 'encrypted', 'reviewed_at' => 'datetime'];
    }
}
