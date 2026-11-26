<?php

namespace App\Http\Controllers\Orphans;

use App\Http\Controllers\Controller;
use App\Services\Orphans\GuardianLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «الأيتام والأوصياء» on the official registry: lists, previews, the guardian-first «إضافة يتيم», «إضافة وصي», new
 * guardianship claims and per-link decisions. Every capability is checked again in GuardianLinks.
 */
final class GuardianRegistryController extends Controller
{
    public function __construct(private readonly GuardianLinks $links) {}

    public function children(Request $request): JsonResponse
    {
        return response()->json($this->links->children($request->user(), $this->filters($request)));
    }

    public function guardians(Request $request): JsonResponse
    {
        return response()->json($this->links->guardians($request->user(), $this->filters($request)));
    }

    public function guardian(Request $request, int $id): JsonResponse
    {
        return response()->json($this->links->guardian($request->user(), $id));
    }

    public function child(Request $request, int $id): JsonResponse
    {
        return response()->json($this->links->child($request->user(), $id));
    }

    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate(['national_id' => 'required|string|max:20']);

        return response()->json(['match' => $this->links->guardianByIdentity($request->user(), $data['national_id'])]);
    }

    public function storeGuardian(Request $request): JsonResponse
    {
        $guardian = $this->links->createGuardian($request->user(), $request->all());

        return response()->json(['id' => $guardian->id, 'reference_no' => $guardian->reference_no], 201);
    }

    public function storeChild(Request $request): JsonResponse
    {
        $child = $this->links->createChild($request->user(), $request->except('approve'), $request->boolean('approve'));

        return response()->json(['id' => $child->id, 'public_id' => $child->public_id], 201);
    }

    public function link(Request $request, int $id): JsonResponse
    {
        return response()->json(['link' => $this->links->linkExisting($request->user(), $id, $request->all())], 201);
    }

    public function decide(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['decision' => 'required|in:approve,reject', 'note' => 'required|string|min:10|max:2000', 'replace' => 'nullable|boolean'],
            [], ['note' => 'الدليل أو سبب القرار']);
        $this->links->decide($request->user(), $id, $data['decision'], $data['note'], (bool) ($data['replace'] ?? false));

        return response()->json(['ok' => true]);
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->validate([
            'q' => 'nullable|string|max:100', 'child_id' => 'nullable|string|max:20', 'guardian_id' => 'nullable|string|max:20', 'name_mode' => 'nullable|in:starts,exact',
            'cn1' => 'nullable|string|max:60', 'cn2' => 'nullable|string|max:60', 'cn3' => 'nullable|string|max:60', 'cn4' => 'nullable|string|max:80',
            'gn1' => 'nullable|string|max:60', 'gn2' => 'nullable|string|max:60', 'gn3' => 'nullable|string|max:60', 'gn4' => 'nullable|string|max:80',
            'sex' => 'nullable|in:male,female,__missing', 'status' => 'nullable|string|max:20', 'orphan_class' => 'nullable|string|max:20', 'source' => 'nullable|string|max:20',
            'verification' => 'nullable|string|max:20', 'link' => 'nullable|in:approved,pending,none', 'origin' => 'nullable|in:portal,staff',
            'age_min' => 'nullable|integer|between:0,40', 'age_max' => 'nullable|integer|between:0,40', 'age_unknown' => 'nullable|boolean', 'page' => 'nullable|integer|min:1',
        ]);
    }
}
