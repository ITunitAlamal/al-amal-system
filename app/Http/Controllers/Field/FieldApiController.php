<?php

namespace App\Http\Controllers\Field;

use App\Http\Controllers\Controller;
use App\Services\Orphans\Field\FieldBatches;
use App\Services\Orphans\Field\FieldDevices;
use App\Services\Orphans\Field\FieldKeys;
use App\Services\Orphans\Field\FieldSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** JSON API of the Windows field client (prefix /api/field/v1). Contract unchanged from field_client format 1. */
final class FieldApiController extends Controller
{
    public function server(FieldKeys $keys): JsonResponse
    {
        return response()->json(['key_id' => $keys->keyId(), 'public_key' => $keys->publicKeyBase64(), 'server_time' => now()->utc()->toIso8601ZuluString(),
            'max_offline_hours' => min(168, FieldDevices::setting('field_offline_max_hours', 72))]);
    }

    public function signIn(Request $request, FieldDevices $devices): JsonResponse
    {
        $data = $request->validate(['employee_number' => 'required|string|max:255', 'password' => 'required|string|max:255', 'public_key' => 'required|string|max:100',
            'device_name' => 'required|string|max:120', 'platform' => 'nullable|string|max:40', 'app_version' => 'nullable|string|max:20', 'signed_at' => 'required|string|max:40', 'proof' => 'required|string|max:200']);
        $result = $devices->signIn($data, $request->ip());

        return response()->json($result + ['server_time' => now()->utc()->toIso8601ZuluString()], $result['status'] === 'approved' ? 200 : 202);
    }

    public function signOut(Request $request, FieldDevices $devices): JsonResponse
    {
        $devices->signOut($request->attributes->get('field_session')['token']);

        return response()->json(['status' => 'signed_out']);
    }

    public function batches(Request $request): JsonResponse
    {
        ['user' => $user, 'device' => $device] = $request->attributes->get('field_session');
        $batches = DB::table('campaign_batches as b')->join('campaigns as c', 'c.id', '=', 'b.campaign_id')->where('b.distributor_user_id', $user->id)->where('b.device_id', $device->id)
            ->whereIn('b.status', ['assigned', 'downloaded', 'returned', 'revoked'])->orderByDesc('b.id')->limit(50)
            ->get(['b.public_id', 'b.reference_no', 'b.status', 'b.manifest_version', 'b.offline_expires_at', 'c.reference_no as campaign', 'c.title',
                DB::raw('(select count(*) from campaign_batch_entries e where e.batch_id = b.id and e.is_active) as allocations')]);

        return response()->json(['batches' => $batches, 'server_time' => now()->utc()->toIso8601ZuluString()]);
    }

    public function manifest(Request $request, string $batch, FieldBatches $batches): JsonResponse
    {
        ['user' => $user, 'device' => $device] = $request->attributes->get('field_session');

        return response()->json($batches->manifest($user, $device, $batch));
    }

    public function sync(Request $request, FieldSync $sync): JsonResponse
    {
        ['user' => $user, 'device' => $device] = $request->attributes->get('field_session');
        $data = $request->validate(['events' => 'required|array|min:1|max:100', 'events.*.payload' => 'required|string|max:20000', 'events.*.signature' => 'required|string|max:200',
            'events.*.signature_image' => 'nullable|string|max:700000']);
        $acks = [];
        foreach ($data['events'] as $event) {
            $acks[] = $sync->receive($user, $device, $event);
        }

        return response()->json(['acks' => $acks, 'server_time' => now()->utc()->toIso8601ZuluString()]);
    }

    public function status(Request $request): JsonResponse
    {
        ['device' => $device, 'token' => $token] = $request->attributes->get('field_session');

        return response()->json(['device' => ['id' => $device->public_id, 'status' => $device->status, 'last_sync_at' => $device->last_sync_at], 'session_expires_at' => $token->expires_at,
            'server_time' => now()->utc()->toIso8601ZuluString()]);
    }
}
