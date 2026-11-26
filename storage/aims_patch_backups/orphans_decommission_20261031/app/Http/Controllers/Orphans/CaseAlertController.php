<?php

namespace App\Http\Controllers\Orphans;

use App\Http\Controllers\Controller;
use App\Models\OrphanHousehold;
use App\Services\Orphans\OrphanEmergencies;
use App\Services\Orphans\OrphanReferrals;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Emergency flags (Q32) and manual referrals (Q33) on a family file; the services hold the rules. */
final class CaseAlertController extends Controller
{
    public function raiseEmergency(Request $request, string $publicId, OrphanEmergencies $emergencies): RedirectResponse
    {
        abort_unless($emergencies->available(), 409, 'يلزم تنفيذ ترحيل المرحلة الأولى أولًا.');
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000'], 'child_public_id' => ['nullable', 'string', 'max:40']]);
        $emergencies->raise($request->user(), OrphanHousehold::where('public_id', $publicId)->firstOrFail(), $data['child_public_id'] ?? null, $data['reason']);

        return back()->with('success', 'وُسم الملف بحالة طوارئ ونُبّه رئيس القسم. لا يبدأ الوسم أي صرف أو موافقة؛ المساعدة العاجلة تمر بمسارها المعتاد.');
    }

    public function clearEmergency(Request $request, int $flag, OrphanEmergencies $emergencies): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:2000']]);
        $emergencies->clear($request->user(), $flag, $data['note']);

        return back()->with('success', 'رُفع وسم الطوارئ وبقي في سجل الملف.');
    }

    public function storeReferral(Request $request, string $publicId, OrphanReferrals $referrals): RedirectResponse
    {
        abort_unless($referrals->available(), 409, 'يلزم تنفيذ ترحيل المرحلة الأولى أولًا.');
        $referrals->record($request->user(), OrphanHousehold::where('public_id', $publicId)->firstOrFail(), $request->only(['referred_to', 'reason', 'referred_on', 'responsible_user_id', 'child_public_id']));

        return back()->with('success', 'سُجلت الإحالة ونُبّه المسؤول عن متابعتها.');
    }

    public function closeReferral(Request $request, int $referral, OrphanReferrals $referrals): RedirectResponse
    {
        $data = $request->validate(['outcome' => ['required', 'string', 'max:2000']]);
        $referrals->close($request->user(), $referral, $data['outcome']);

        return back()->with('success', 'سُجلت نتيجة الإحالة وأُغلقت.');
    }
}
