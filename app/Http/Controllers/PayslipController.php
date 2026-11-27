<?php

namespace App\Http\Controllers;

use App\Services\Finance\FinancePayslips;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/** «طلب قسيمة راتب» in «طلباتي»: the employee asks for one month and views only their own published slip. */
final class PayslipController extends Controller
{
    public function __construct(private readonly FinancePayslips $payslips) {}

    public function create(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user?->is_active, 403);

        return Inertia::render('Requests/Payslip', [
            'months' => $this->payslips->availablePeriods($user),
            'linked' => $this->payslips->employeeOf($user) !== null,
            'requests' => $this->payslips->requestsOf($user)->map(fn (object $r): array => ['reference' => $r->reference, 'period' => $r->period,
                'state' => $r->state, 'stateLabel' => FinancePayslips::STATES[$r->state][0] ?? $r->state, 'tone' => FinancePayslips::STATES[$r->state][1] ?? 'muted',
                'createdAt' => (string) $r->created_at, 'slip' => $r->slip]),
        ]);
    }

    public function store(Request $request): SymfonyResponse
    {
        $d = $request->validate(['period' => 'required|date_format:Y-m', 'note' => 'nullable|string|max:1000'], [], ['period' => 'الشهر']);
        $result = $this->payslips->request($request->user(), $d['period'], $d['note'] ?? null);

        // The print view is plain HTML, so Inertia must perform a full browser navigation.
        return Inertia::location(route('payslips.show', $result->slip_reference, false));
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
