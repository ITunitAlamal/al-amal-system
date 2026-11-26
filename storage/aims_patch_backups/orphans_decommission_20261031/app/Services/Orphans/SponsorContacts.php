<?php

namespace App\Services\Orphans;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Notifications\NotificationCenter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * «سجل الاتصال بالكفيل» (requirement Q36, option A): staff note each call, message, visit or letter with a sponsor,
 * its outcome and an optional follow-up date. A log of manual contact only: AIMS sends nothing to sponsors.
 */
final class SponsorContacts
{
    public const CHANNELS = ['phone' => 'اتصال هاتفي', 'whatsapp' => 'واتساب', 'email' => 'بريد إلكتروني', 'visit' => 'زيارة', 'meeting' => 'اجتماع', 'letter' => 'رسالة رسمية', 'other' => 'أخرى'];

    public const DIRECTIONS = ['outgoing' => 'منّا إلى الكافل', 'incoming' => 'من الكافل'];

    public function __construct(private readonly OrphanAccess $access, private readonly NotificationCenter $notifications, private readonly AuditService $audit) {}

    public function available(): bool
    {
        return DB::getSchemaBuilder()->hasTable('sponsor_contacts');
    }

    /** @param array<string, mixed> $input */
    public function record(User $actor, int $sponsorId, array $input): int
    {
        $this->access->assert($actor, 'sponsorships');
        abort_unless(DB::table('sponsors')->where('id', $sponsorId)->exists(), 404);
        $today = CarbonImmutable::today(config('orphans.timezone'))->toDateString();
        $data = Validator::make($input, [
            'contacted_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today], 'channel' => ['required', Rule::in(array_keys(self::CHANNELS))],
            'direction' => ['required', Rule::in(array_keys(self::DIRECTIONS))], 'subject' => ['required', 'string', 'min:3', 'max:200'],
            'summary' => ['required', 'string', 'min:5', 'max:3000'], 'outcome' => ['nullable', 'string', 'max:2000'],
            'follow_up_on' => ['nullable', 'date_format:Y-m-d', 'after:'.$today],
        ], [], ['contacted_on' => 'تاريخ الاتصال', 'channel' => 'الوسيلة', 'direction' => 'الاتجاه', 'subject' => 'الموضوع', 'summary' => 'الملخص', 'outcome' => 'النتيجة',
            'follow_up_on' => 'موعد المتابعة'])->validate();

        return DB::transaction(function () use ($actor, $sponsorId, $data): int {
            $id = DB::table('sponsor_contacts')->insertGetId(['sponsor_id' => $sponsorId, 'contacted_on' => $data['contacted_on'], 'channel' => $data['channel'], 'direction' => $data['direction'],
                'subject' => trim($data['subject']), 'summary' => trim($data['summary']), 'outcome' => $data['outcome'] ?? null, 'follow_up_on' => $data['follow_up_on'] ?? null,
                'recorded_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->recordRow('sponsors.contact.recorded', 'sponsor_contacts', $id, null, ['sponsor' => $sponsorId, 'channel' => $data['channel']], $actor->id, request()?->ip());

            return $id;
        });
    }

    /** Whoever recorded the contact, or the head, marks its follow-up done. */
    public function completeFollowUp(User $actor, int $contactId): void
    {
        DB::transaction(function () use ($actor, $contactId): void {
            $row = DB::table('sponsor_contacts')->where('id', $contactId)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless((int) $row->recorded_by === (int) $actor->id || $this->access->isHead($actor), 403, 'يغلق المتابعة من سجّل الاتصال أو رئيس القسم.');
            if (! $row->follow_up_on || $row->follow_up_done_at) {
                throw ValidationException::withMessages(['contact' => 'لا توجد متابعة مفتوحة لهذا الاتصال.']);
            }
            DB::table('sponsor_contacts')->where('id', $contactId)->update(['follow_up_done_at' => now(), 'updated_at' => now()]);
            $this->audit->recordRow('sponsors.contact.follow_up_done', 'sponsor_contacts', $contactId, null, ['done' => true], $actor->id, request()?->ip());
        });
    }

    /** @return list<array<string, mixed>> */
    public function forSponsor(int $sponsorId, User $viewer): array
    {
        if (! $this->available()) {
            return [];
        }
        $today = CarbonImmutable::today(config('orphans.timezone'))->toDateString();

        return DB::table('sponsor_contacts as c')->leftJoin('users as u', 'u.id', '=', 'c.recorded_by')->where('c.sponsor_id', $sponsorId)->orderByDesc('c.contacted_on')->orderByDesc('c.id')
            ->limit(200)->get(['c.*', 'u.name as recorder'])
            ->map(fn (object $row): array => ['id' => (int) $row->id, 'contacted_on' => (string) $row->contacted_on, 'channel_label' => self::CHANNELS[$row->channel] ?? $row->channel,
                'direction_label' => self::DIRECTIONS[$row->direction] ?? $row->direction, 'subject' => $row->subject, 'summary' => $row->summary, 'outcome' => $row->outcome,
                'follow_up_on' => $row->follow_up_on ? (string) $row->follow_up_on : null, 'follow_up_done' => $row->follow_up_done_at !== null, 'recorder' => $row->recorder,
                'follow_up_late' => $row->follow_up_on && ! $row->follow_up_done_at && (string) $row->follow_up_on < $today,
                'can_close' => $row->follow_up_on && ! $row->follow_up_done_at && ((int) $row->recorded_by === (int) $viewer->id || $this->access->isHead($viewer))])->all();
    }

    /**
     * Daily: each open follow-up due today or earlier reminds whoever recorded the contact, once.
     *
     * @return array{due: int, notified: int}
     */
    public function sendFollowUpReminders(bool $dryRun = false): array
    {
        $counts = ['due' => 0, 'notified' => 0];
        if (! $this->available()) {
            return $counts;
        }
        $rows = DB::table('sponsor_contacts as c')->join('sponsors as s', 's.id', '=', 'c.sponsor_id')->whereNull('c.follow_up_done_at')->whereNotNull('c.follow_up_on')
            ->where('c.follow_up_on', '<=', CarbonImmutable::today(config('orphans.timezone'))->toDateString())->get(['c.id', 'c.subject', 'c.follow_up_on', 'c.recorded_by', 's.public_id', 's.donor_no']);
        foreach ($rows as $row) {
            $counts['due']++;
            if (! $dryRun) {
                $counts['notified'] += $this->notifications->notifyMany([(int) $row->recorded_by], 'sponsor_contact_followup', [
                    'title' => 'متابعة اتصال بالكافل '.$row->donor_no.' مستحقة ('.$row->follow_up_on.')', 'body' => $row->subject,
                    'url' => '/orphans/sponsors/'.$row->public_id, 'dedupe' => 'sponsor_contact:'.$row->id.':'.$row->follow_up_on]);
            }
        }

        return $counts;
    }
}
