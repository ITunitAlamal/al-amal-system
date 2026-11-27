<?php

namespace App\Http\Controllers;

use App\Services\Maintenance\MaintenanceAddenda;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * «قرار فصل» on a maintenance request: the holder of its closing stage lets it close while a required addendum is
 * still open, with the reason and the person who follows the addendum up (SRS 3.5.3.3). Addenda themselves are opened
 * from the «طلب صيانة» form (WorkflowRequestController, «parent»).
 */
class MaintenanceAddendumController extends Controller
{
    public function separate(Request $request, string $publicId, string $childPublicId, MaintenanceAddenda $addenda): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user?->is_active, 403);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'follow_up_user_id' => ['required', 'integer'],
        ], [], ['reason' => 'سبب الإغلاق دون الملحق', 'follow_up_user_id' => 'مسؤول متابعة الملحق']);

        $addenda->separate($publicId, $childPublicId, $user, trim($data['reason']), (int) $data['follow_up_user_id']);

        return back(303)->with('success', 'سُجّل قرار الفصل؛ يمكن الآن اعتماد إغلاق الطلب الأصلي، ويتابع الملحق المسؤول المحدد.');
    }
}
