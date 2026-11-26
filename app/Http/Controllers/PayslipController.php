<?php

namespace App\Http\Controllers;

use App\Services\Finance\FinancePayslips;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/** «طلب قسيمة راتب» in «طلباتي»: the employee asks for one month and views only their own published slip. */
final class PayslipController extends Controller
{
    public function __construct(private readonly FinancePayslips $payslips) {}

    public function create(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user?->is_active, 403);
        $months = collect(range(0, 23))->map(fn (int $i): string => now()->startOfMonth()->subMonths($i)->format('Y-m'))->all();

        return Inertia::render('Requests/Payslip', [
            'months' => $months,
            'linked' => $this->payslips->employeeOf($user) !== null,
            'requests' => $this->payslips->requestsOf($user)->map(fn (object $r): array => ['reference' => $r->reference, 'period' => $r->period,
                'state' => $r->state, 'stateLabel' => FinancePayslips::STATES[$r->state][0] ?? $r->state, 'tone' => FinancePayslips::STATES[$r->state][1] ?? 'muted',
                'createdAt' => (string) $r->created_at, 'slip' => $r->slip]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $request->validate(['period' => 'required|date_format:Y-m', 'note' => 'nullable|string|max:1000'], [], ['period' => 'الشهر']);
        $result = $this->payslips->request($request->user(), $d['period'], $d['note'] ?? null);

        // The request's state is shown in the list below the form; the notice repeats it.
        return redirect()->route('payslips.create')->with('success',
            ($result->reference.': '.FinancePayslips::STATES[$result->state][0]).($result->state === 'awaiting_payroll' ? '. سنُشعرك فور نشر المسير.' : '.'));
    }

    /** Rendered on demand from the published run; no file is stored and the page is never cached. */
    public function show(Request $request, string $reference): HttpResponse
    {
        abort_unless($request->user()?->is_active, 403);
        $slip = $this->payslips->open($request->user(), $reference);

        return response()->view('finance.payslip', ['slip' => $slip])
            ->withHeaders(['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'no-referrer']);
    }
}
