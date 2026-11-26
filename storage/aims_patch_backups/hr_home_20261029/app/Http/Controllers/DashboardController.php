<?php

namespace App\Http\Controllers;

use App\Services\Dashboard\DashboardLayoutResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Post-login landing. The home page is personal, so every signed-in user may open it; a dashboard template may
     * set another landing page, used only when the user may open that page.
     */
    public function index(Request $request, DashboardLayoutResolver $layouts): RedirectResponse
    {
        $landing = $request->user() ? $layouts->landingUrl($request->user()) : null;

        return $landing !== null ? redirect($landing) : redirect()->route('ui.preview');
    }
}
