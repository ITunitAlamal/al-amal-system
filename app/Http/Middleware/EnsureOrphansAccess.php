<?php

namespace App\Http\Middleware;

use App\Services\Orphans\OrphanAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate of the whole «شؤون الأيتام والكفالات» area (AC-07): a staff member with no access to the department is refused
 * before a controller looks up a record or runs a validation rule that checks one exists, so the answer never tells
 * whether a household, orphan or sponsor exists. It is never wider than the routes' own checks (department
 * readers, or campaign staff and assigned campaign members); each action still authorizes itself in OrphanAccess.
 */
final class EnsureOrphansAccess
{
    public function __construct(private readonly OrphanAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');
        abort_unless($this->access->canView($user) || $this->access->canOpenCampaigns($user), 403, 'هذه الصفحة لموظفي قسم شؤون الأيتام والكفالات ومن يملك صلاحية عليه.');

        return $next($request);
    }
}
