<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\Department;
class DashboardController extends Controller {
    public function index(Request $request) {
        abort_unless($request->user()->hasPermissionInAnyScope('dashboard.view'),403);
        $departments=Department::where('is_active',true)->orderBy('id')->get();
        return view('dashboard.index',compact('departments'));
    }
}
