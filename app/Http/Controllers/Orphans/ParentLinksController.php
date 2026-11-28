<?php

namespace App\Http\Controllers\Orphans;

use App\Http\Controllers\Controller;
use App\Services\Orphans\OrphanAccess;
use App\Services\Orphans\ParentLinkAnomalies;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «روابط الأبوين المشكوك فيها» (DB-01): a read-only list for the head of the Orphans department, who corrects each
 * case from the orphan file through «التعديلات الحساسة». Every opening is written to the access log.
 */
final class ParentLinksController extends Controller
{
    public function __invoke(Request $request, OrphanAccess $access, ParentLinkAnomalies $anomalies): Response
    {
        $access->assertDecide($request->user(), 'هذه القائمة لرئيس قسم شؤون الأيتام والكفالات أو القائم بأعماله.');
        $kind = array_key_exists((string) $request->query('kind'), ParentLinkAnomalies::KINDS) ? (string) $request->query('kind') : 'both_relations';
        $access->logRead($request->user(), 'parent_link_anomalies', 0);

        return Inertia::render('Orphans/Reviews/ParentLinks', ['kind' => $kind, 'kinds' => ParentLinkAnomalies::KINDS, 'counts' => $anomalies->counts(), 'rows' => $anomalies->page($kind)]);
    }
}
