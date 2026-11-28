<?php

namespace App\Services\Audit;

use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Refused requests: a 403 (authorization) or a throttled sign-in (429), recorded while the error page is rendered
 * (after DB::transaction already rolled back the refused work, so the refusal itself is kept). One row per account (or address) and route per 10 minutes, to keep
 * enumeration and retry storms from flooding the log. A refused campaign-list decision is marked critical: only the
 * executive director decides beneficiary lists and their amendments.
 */
final class AuditDenials
{
    private const LOGIN_ROUTES = ['login.submit' => 'auth.lockout', 'field.session' => 'auth.lockout', 'guardian.login.store' => 'portal.auth.lockout', 'guardian.password.send' => 'portal.auth.lockout'];

    private const CRITICAL_ROUTES = ['orphans.campaigns.list.action', 'orphans.campaigns.list.edit', 'finance.approvals', 'admin.employees.password.update'];

    public static function capture(Throwable $e, Request $request): void
    {
        if (! $e instanceof HttpExceptionInterface || ! in_array($e->getStatusCode(), [403, 429], true)) {
            return;
        }
        $route = $request->route()?->getName();
        $status = $e->getStatusCode();
        if ($status === 429 && ! isset(self::LOGIN_ROUTES[$route])) {
            return;
        }
        try {
            $userId = Auth::guard('web')->id();
            $guardianId = $userId ? null : Auth::guard('guardian')->id();
            $who = $userId ? 'u'.$userId : ($guardianId ? 'g'.$guardianId : 'ip'.$request->ip());
            if (! Cache::add('audit-denied:'.$who.':'.$route.':'.$status, 1, 600)) {
                return;
            }
            $op = $request->input('action');
            $op = is_string($op) && preg_match('/^[a-z_]{1,30}$/', $op) ? $op : null;
            $action = $status === 429 ? self::LOGIN_ROUTES[$route]
                : ($route === 'orphans.campaigns.list.action' && in_array($op, ['approve', 'reject'], true) ? 'orphans.campaign.list.decision_denied' : 'access.denied');
            app(AuditService::class)->event($action, [
                'actor_id' => $userId, 'actor_guardian_id' => $guardianId, 'outcome' => 'denied', 'reason_code' => $status === 429 ? 'throttled' : 'forbidden',
                'severity' => in_array($route, self::CRITICAL_ROUTES, true) && $status === 403 ? 'critical' : 'warning',
                'sensitivity' => 'normal', 'metadata' => array_filter(['route' => $route, 'method' => $request->method(), 'operation' => $op]),
                'ip' => $request->ip(),
            ]);
        } catch (Throwable) {
            // Recording a refusal never changes the refusal itself.
        }
    }
}
