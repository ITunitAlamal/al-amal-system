<?php

namespace App\Http\Controllers\Orphans;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\RoleBoards;
use App\Services\Orphans\Dashboard;
use App\Services\Orphans\OrphanAccess;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** «الرئيسية»: one dashboard, rendered by the viewer's role in the department. */
final class HomeController extends Controller
{
    public function __invoke(Request $request, OrphanAccess $access, Dashboard $dashboard): Response
    {
        $access->assertView($request->user());

        return Inertia::render('Orphans/Home', [...$dashboard->forUser($request->user()), 'abilities' => $access->abilities($request->user()), 'name' => $request->user()->name,
            'boards' => Inertia::defer(fn (): array => app(RoleBoards::class)->orphans($request->user()), 'boards')]);
    }
}
