<?php

namespace App\Http\Controllers\Orphans;

use App\Http\Controllers\Controller;
use App\Models\OrphanHousehold;
use App\Services\Orphans\HouseholdNeeds;
use App\Services\Orphans\OrphanAccess;
use App\Services\Orphans\OrphanForms;
use App\Services\Orphans\SponsorAgreements;
use App\Services\Orphans\SponsorContacts;
use App\Services\Orphans\Sponsors;
use App\Services\Orphans\SponsorshipFunds;
use App\Services\Orphans\SponsorshipWaitlist;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 3 of the sponsorships department: waiting list (Q14), agreement templates and agreements (Q35, Q11),
 * pooled funds (Q11), the sponsor's contact log (Q36) and a family's assessed need (Q38). The services hold the rules.
 */
final class SponsorProgramController extends Controller
{
    public function __construct(private readonly OrphanAccess $access) {}

    // Waiting list
    public function addToWaitlist(Request $request, string $publicId, SponsorshipWaitlist $waitlist): RedirectResponse
    {
        $this->installed($waitlist->available());
        $waitlist->add($request->user(), OrphanHousehold::where('public_id', $publicId)->firstOrFail(), $request->only(['beneficiary_type', 'child_public_id', 'program_value_id', 'reason']));

        return back()->with('success', 'أُدرج المستفيد في قائمة انتظار الكفالة. يُرتب حسب التقييم الاجتماعي المعتمد ثم أقدمية الانتظار.');
    }

    public function removeFromWaitlist(Request $request, int $entry, SponsorshipWaitlist $waitlist): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $waitlist->remove($request->user(), $entry, $data['reason']);

        return back()->with('success', 'أُخرج المستفيد من قائمة الانتظار مع السبب.');
    }

    // Need assessment
    public function assessNeed(Request $request, string $publicId, HouseholdNeeds $needs): RedirectResponse
    {
        $this->installed($needs->available());
        $needs->assess($request->user(), OrphanHousehold::where('public_id', $publicId)->firstOrFail(), $request->only(['monthly_amount', 'currency', 'basis']));

        return back()->with('success', 'سُجل تقدير الاحتياج الشهري؛ يبقى التقدير السابق في السجل.');
    }

    // Templates
    public function storeTemplate(Request $request, SponsorAgreements $agreements): RedirectResponse
    {
        $this->installed($agreements->available());
        $agreements->saveTemplate($request->user(), $request->only(['code', 'title', 'kind', 'body', 'notes']));

        return back()->with('success', 'حُفظ القالب مسودة؛ يُستخدم بعد اعتماد المدير التنفيذي لنصه.');
    }

    public function updateTemplate(Request $request, int $template, SponsorAgreements $agreements): RedirectResponse
    {
        $agreements->saveTemplate($request->user(), $request->only(['code', 'title', 'kind', 'body', 'notes']), $template);

        return back()->with('success', 'حُفظت مسودة القالب.');
    }

    public function templateAction(Request $request, int $template, string $action, SponsorAgreements $agreements): RedirectResponse
    {
        $message = match ($action) {
            'version' => tap('فُتح إصدار جديد من القالب كمسودة.', fn () => $agreements->newTemplateVersion($request->user(), $template)),
            'activate' => tap('اعتُمد نص القالب؛ لا يُعدّل بعد اليوم إلا بإصدار جديد.', fn () => $agreements->activateTemplate($request->user(), $template)),
            'retire' => tap('أُوقف القالب؛ الاتفاقيات الموقعة تحتفظ بنصها.', fn () => $agreements->retireTemplate($request->user(), $template)),
            default => abort(404),
        };

        return back()->with('success', $message);
    }

    // Agreements
    public function storeAgreement(Request $request, SponsorAgreements $agreements): RedirectResponse
    {
        $this->installed($agreements->available());
        $publicId = $agreements->create($request->user(), $request->only(['kind', 'sponsor_id', 'fund_id', 'template_id', 'starts_on', 'ends_on', 'renewal_alert_days', 'notes']));

        return redirect()->route('orphans.agreements.show', $publicId)->with('success', 'أُنشئت الاتفاقية مسودة. أضف المستفيدين ثم ارفع كفالاتهم للاعتماد.');
    }

    public function showAgreement(Request $request, string $publicId, SponsorAgreements $agreements, Sponsors $sponsors, OrphanForms $forms): Response
    {
        $user = $request->user();
        $manage = $this->access->allows($user, 'sponsorships');
        abort_unless($manage || $this->access->isExecutive($user), 403);
        $agreement = $agreements->find($publicId);
        $identity = $sponsors->seesIdentity($user);
        $sponsor = DB::table('sponsors')->where('id', $agreement->sponsor_id)->first();
        $template = $agreement->template_id ? DB::table('sponsorship_agreement_templates')->where('id', $agreement->template_id)->first(['id', 'code', 'version', 'title', 'status']) : null;
        $link = fn (?int $id) => $id ? DB::table('sponsorship_agreements')->where('id', $id)->first(['public_id', 'agreement_no', 'status']) : null;
        $renewal = DB::table('sponsorship_agreements')->where('renews_id', $agreement->id)->whereIn('status', ['draft', 'signed'])->first(['public_id', 'agreement_no', 'status']);
        $draftLines = DB::table('sponsorships')->where('agreement_id', $agreement->id)->where('status', 'draft')->whereNull('workflow_instance_id')->count();

        return Inertia::render('Orphans/Agreement', [
            'agreement' => ['id' => (int) $agreement->id, 'public_id' => $agreement->public_id, 'agreement_no' => $agreement->agreement_no, 'kind' => $agreement->kind,
                'kind_label' => SponsorAgreements::KINDS[$agreement->kind] ?? $agreement->kind, 'status' => $agreement->status,
                'status_label' => SponsorAgreements::STATUS_LABELS[$agreement->status] ?? $agreement->status, 'starts_on' => (string) $agreement->starts_on,
                'ends_on' => $agreement->ends_on ? (string) $agreement->ends_on : null, 'renewal_alert_days' => (int) $agreement->renewal_alert_days, 'notes' => $agreement->notes,
                'signed_on' => $agreement->signed_on ? (string) $agreement->signed_on : null, 'rendered_hash' => $agreement->rendered_hash, 'status_reason' => $agreement->status_reason,
                'template_id' => $agreement->template_id ? (int) $agreement->template_id : null,
                'sponsor' => ['public_id' => $sponsor->public_id, 'name' => $sponsors->displayName($sponsor, $identity), 'donor_no' => $sponsor->donor_no],
                'fund' => $agreement->fund_id ? DB::table('sponsorship_funds')->where('id', $agreement->fund_id)->first(['fund_no', 'name']) : null,
                'template' => $template, 'renews' => $link($agreement->renews_id ? (int) $agreement->renews_id : null), 'renewal' => $renewal],
            'text' => $agreements->text($agreement),
            'lines' => app(SponsorshipController::class)->forAgreement($user, (int) $agreement->id),
            'templates' => DB::table('sponsorship_agreement_templates')->where('status', 'active')->where('kind', $agreement->kind)->orderBy('title')->get(['id', 'title', 'version']),
            'options' => $manage ? $forms->options(['sponsorship_types', 'sponsorship_frequencies', 'sponsorship_purposes'])
                + ['currencies' => DB::table('currencies')->where('is_active', true)->orderBy('code')->pluck('code')] : null,
            'can' => ['manage' => $manage, 'edit' => $manage && $agreement->status === 'draft', 'lines' => $manage && in_array($agreement->status, ['draft', 'signed'], true),
                'submit' => $manage && $draftLines > 0, 'sign' => $manage && $agreement->status === 'draft', 'renew' => $manage && $agreement->status === 'signed' && ! $renewal,
                'end' => $agreement->status === 'signed' && $this->access->isHead($user),
                'cancel' => $agreement->status === 'draft' && $manage && ((int) $agreement->created_by === (int) $user->id || $this->access->isHead($user))],
        ]);
    }

    public function updateAgreement(Request $request, string $publicId, SponsorAgreements $agreements): RedirectResponse
    {
        $agreements->update($request->user(), $agreements->find($publicId), $request->only(['template_id', 'starts_on', 'ends_on', 'renewal_alert_days', 'notes']));

        return back()->with('success', 'حُفظت بيانات الاتفاقية.');
    }

    public function agreementAction(Request $request, string $publicId, string $action, SponsorAgreements $agreements, WorkflowEngine $engine): RedirectResponse
    {
        $user = $request->user();
        $agreement = $agreements->find($publicId);
        if ($action === 'renew') {
            return redirect()->route('orphans.agreements.show', $agreements->renew($user, $agreement))
                ->with('success', 'فُتح تجديد الاتفاقية مسودة؛ راجع التواريخ ثم سجّل التوقيع. تنتقل الكفالات السارية إليه عند التوقيع.');
        }
        $message = match ($action) {
            'lines' => 'أُنشئت '.$agreements->addLines($user, $agreement, $request->all()).' مسودة كفالة في الاتفاقية؛ ارفعها للاعتماد.',
            'submit' => 'رُفعت '.$agreements->submitLines($user, $agreement, $engine).' كفالة لرئيس القسم ثم المدير التنفيذي.',
            'sign' => tap('سُجل توقيع الاتفاقية وحُفظ نصها كما وُقّع.', fn () => $agreements->recordSigning($user, $agreement, (string) $request->input('signed_on'))),
            'close' => tap($agreement->status === 'signed' ? 'أُنهيت الاتفاقية. الكفالات لا تنتهي إلا بقرار على كل كفالة.' : 'أُلغيت مسودة الاتفاقية.',
                fn () => $agreements->close($user, $agreement, (string) $request->input('reason'))),
            default => abort(404),
        };

        return back()->with('success', $message);
    }

    /** A printable page of the agreement text (the signed snapshot, or a draft preview marked as such). */
    public function printAgreement(Request $request, string $publicId, SponsorAgreements $agreements): HttpResponse
    {
        $user = $request->user();
        abort_unless($this->access->allows($user, 'sponsorships') || $this->access->isExecutive($user), 403);
        $agreement = $agreements->find($publicId);
        $text = $agreements->text($agreement);
        abort_unless($text !== null, 404, 'لا يوجد قالب للاتفاقية بعد.');
        $this->access->logRead($user, 'agreement', (int) $agreement->id, 'printed');

        return response()->view('orphans.agreement-print', ['agreement' => $agreement, 'text' => $text, 'draft' => $agreement->status === 'draft']);
    }

    // Funds
    public function storeFund(Request $request, SponsorshipFunds $funds): RedirectResponse
    {
        $this->installed($funds->available());
        $funds->create($request->user(), $request->only(['name', 'currency', 'description']));

        return back()->with('success', 'أُنشئ الصندوق. التبرعات تُخصص له برقمه، وكفالاته تُعد وتُعتمد كأي كفالة.');
    }

    public function closeFund(Request $request, int $fund, SponsorshipFunds $funds): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $funds->close($request->user(), $fund, $data['reason']);

        return back()->with('success', 'أُغلق الصندوق أمام التبرعات والكفالات الجديدة؛ الكفالات القائمة تستمر حتى تُنهى.');
    }

    // Sponsor profile and contact log
    public function showSponsor(Request $request, string $publicId, Sponsors $sponsors, SponsorContacts $contacts, SponsorAgreements $agreements): Response
    {
        $user = $request->user();
        $manage = $this->access->allows($user, 'sponsorships');
        abort_unless($manage || $this->access->isExecutive($user), 403);
        $sponsor = DB::table('sponsors')->where('public_id', $publicId)->first();
        abort_unless($sponsor, 404);
        $this->access->logRead($user, 'sponsor', (int) $sponsor->id);
        $identity = $sponsors->seesIdentity($user);

        return Inertia::render('Orphans/Sponsor', [
            'sponsor' => $sponsors->present($sponsor, $identity, $manage),
            'fund' => $agreements->available() ? DB::table('sponsorship_funds')->where('sponsor_id', $sponsor->id)->first(['fund_no', 'name', 'status']) : null,
            'sponsorships' => app(SponsorshipController::class)->forSponsor($user, (int) $sponsor->id),
            'agreements' => $agreements->index($identity, (int) $sponsor->id),
            'contacts' => $contacts->forSponsor((int) $sponsor->id, $user),
            'options' => ['channels' => SponsorContacts::CHANNELS, 'directions' => SponsorContacts::DIRECTIONS],
            'can' => ['manage' => $manage && $contacts->available()],
        ]);
    }

    public function storeContact(Request $request, string $publicId, SponsorContacts $contacts): RedirectResponse
    {
        $this->installed($contacts->available());
        $sponsorId = (int) DB::table('sponsors')->where('public_id', $publicId)->value('id');
        abort_unless($sponsorId > 0, 404);
        $contacts->record($request->user(), $sponsorId, $request->only(['contacted_on', 'channel', 'direction', 'subject', 'summary', 'outcome', 'follow_up_on']));

        return back()->with('success', 'سُجل الاتصال بالكافل.');
    }

    public function closeContactFollowUp(Request $request, int $contact, SponsorContacts $contacts): RedirectResponse
    {
        $contacts->completeFollowUp($request->user(), $contact);

        return back()->with('success', 'أُغلقت متابعة الاتصال.');
    }

    private function installed(bool $available): void
    {
        abort_unless($available, 409, 'يلزم تنفيذ ترحيل المرحلة الثالثة أولًا.');
    }
}
