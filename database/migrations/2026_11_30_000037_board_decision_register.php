<?php

use App\Services\Configuration\NavigationRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «سجل قرارات مجلس الإدارة» (TRANSFORM §3.3 and §11; SRS 3.6.1 BoardDecision / DecisionAssignment / DecisionFollowUp):
 * one authoritative record per Board decision, fed by the existing cycle (a «تبليغ قرار مجلس الإدارة» letter on the
 * BOARD_CORRESPONDENCE workflow), not by a parallel path.
 *
 * - board_decisions: one row per decision letter: decision number, decision date, meeting number and date. The text
 *   and attachments stay the letter's issued original (correspondence_actions.is_original), never copied. The board
 *   secretary may still complete or correct the data when the Secretariat returns the decision to him; from the
 *   moment the Secretariat registers it (recorded_at) its number, date and meeting are fixed and the row cannot be
 *   deleted (trigger board_decisions_guard); a decision number is unique within its year.
 * - board_decision_assignments: the departments the executive director charged with executing the decision, written
 *   by the workflow moves (assign / report / accept / return), with the head, the deadline and the follow-up state.
 *
 * Who sees a decision is unchanged: CorrespondenceAccess on the letter (confidential rules of wave 3 apply).
 * Existing decision letters (none on 2026-11-27) get their row from board_reference and letter_date; a number that
 * repeats within a year is left unrecorded rather than rewritten. The register page gets a menu entry, hidden by
 * default (it opens from «المراسلات الرسمية»). Safe to run twice; down() keeps the records.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('board_decisions')) {
            Schema::create('board_decisions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('correspondence_id')->unique()->constrained('official_correspondences')->restrictOnDelete();
                $table->string('decision_number', 100)->nullable();
                $table->date('decision_date')->nullable();
                $table->string('meeting_number', 100)->nullable();
                $table->date('meeting_date')->nullable();
                $table->timestamp('recorded_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('board_decision_assignments')) {
            Schema::create('board_decision_assignments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('board_decision_id')->constrained('board_decisions')->restrictOnDelete();
                $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();
                $table->foreignId('head_user_id')->constrained('users')->restrictOnDelete();
                $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
                $table->foreignId('assigned_event_id')->nullable()->constrained('workflow_events')->restrictOnDelete();
                $table->foreignId('report_event_id')->nullable()->constrained('workflow_events')->restrictOnDelete();
                $table->timestamp('due_at')->nullable();
                $table->string('status', 20)->default('executing');
                $table->timestamp('reported_at')->nullable();
                $table->timestamp('accepted_at')->nullable();
                $table->timestamps();
                $table->unique(['board_decision_id', 'department_id']);
                $table->index(['department_id', 'status']);
            });
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE board_decision_assignments DROP CONSTRAINT IF EXISTS board_decision_assignments_status');
            DB::statement("ALTER TABLE board_decision_assignments ADD CONSTRAINT board_decision_assignments_status CHECK (status IN ('executing', 'reported', 'accepted', 'superseded'))");
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS board_decisions_number_per_year ON board_decisions ((EXTRACT(YEAR FROM decision_date)), lower(btrim(decision_number))) WHERE recorded_at IS NOT NULL');
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION aims_board_decision_guard() RETURNS trigger
                LANGUAGE plpgsql AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        IF OLD.recorded_at IS NOT NULL THEN
                            RAISE EXCEPTION 'board decision % is recorded and cannot be deleted', OLD.id USING ERRCODE = 'check_violation';
                        END IF;
                        RETURN OLD;
                    END IF;
                    IF OLD.recorded_at IS NOT NULL AND (
                        NEW.recorded_at IS DISTINCT FROM OLD.recorded_at
                        OR NEW.correspondence_id IS DISTINCT FROM OLD.correspondence_id
                        OR NEW.created_by IS DISTINCT FROM OLD.created_by
                        -- A value once set stays; a value still missing (an older letter) may be completed once.
                        OR (OLD.decision_number IS NOT NULL AND NEW.decision_number IS DISTINCT FROM OLD.decision_number)
                        OR (OLD.decision_date IS NOT NULL AND NEW.decision_date IS DISTINCT FROM OLD.decision_date)
                        OR (OLD.meeting_number IS NOT NULL AND NEW.meeting_number IS DISTINCT FROM OLD.meeting_number)
                        OR (OLD.meeting_date IS NOT NULL AND NEW.meeting_date IS DISTINCT FROM OLD.meeting_date)
                    ) THEN
                        RAISE EXCEPTION 'board decision % is recorded: its number, date and meeting cannot change', OLD.id
                            USING ERRCODE = 'check_violation', HINT = 'TRANSFORM 3.3: the original Board decision is preserved.';
                    END IF;
                    RETURN NEW;
                END
                $$;
                DROP TRIGGER IF EXISTS board_decisions_guard ON board_decisions;
                CREATE TRIGGER board_decisions_guard BEFORE UPDATE OR DELETE ON board_decisions
                    FOR EACH ROW EXECUTE FUNCTION aims_board_decision_guard();
                SQL);
        }

        DB::transaction(function (): void {
            $this->backfill();
            app(NavigationRegistry::class)->apply(null);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('The Board decision register is an official record and is kept.');
    }

    /** Rows for decision letters written before the register existed. */
    private function backfill(): void
    {
        $now = now();
        $letters = DB::table('official_correspondences as c')
            ->join('master_data_values as v', 'v.id', '=', 'c.type_value_id')
            ->where('v.code', 'board_transmission')
            ->whereNotExists(DB::table('board_decisions as d')->whereColumn('d.correspondence_id', 'c.id')->selectRaw('1'))
            ->orderBy('c.id')->get(['c.id', 'c.author_id', 'c.board_reference', 'c.letter_date', 'c.issued_at', 'c.status', 'c.closed_at', 'c.workflow_instance_id']);
        foreach ($letters as $letter) {
            $number = filled($letter->board_reference) ? mb_substr(trim($letter->board_reference), 0, 100) : null;
            // Recorded only once the Secretariat registered it (register → allocate); before that the normal path
            // completes it (the Secretariat returns it to the board secretary, BoardDecisions::correct).
            $registeredAt = $letter->workflow_instance_id ? DB::table('workflow_events as e')
                ->join('workflow_steps as f', 'f.id', '=', 'e.from_step_id')->join('workflow_steps as t', 't.id', '=', 'e.to_step_id')
                ->where('e.workflow_instance_id', $letter->workflow_instance_id)->where('f.code', 'register')->where('t.code', 'allocate')
                ->orderBy('e.id')->value('e.created_at') : null;
            $recorded = $registeredAt !== null && $number !== null && ! DB::table('board_decisions')->whereNotNull('recorded_at')
                ->whereRaw('lower(btrim(decision_number)) = lower(btrim(?))', [$number])
                ->whereYear('decision_date', substr((string) $letter->letter_date, 0, 4))->exists();
            $decision = (int) DB::table('board_decisions')->insertGetId([
                'correspondence_id' => $letter->id, 'decision_number' => $number, 'decision_date' => $letter->letter_date,
                'meeting_number' => null, 'meeting_date' => null,
                'recorded_at' => $recorded ? $registeredAt : null,
                'closed_at' => in_array($letter->status, ['closed', 'archived'], true) ? $letter->closed_at : null,
                'created_by' => $letter->author_id, 'created_at' => $now, 'updated_at' => $now,
            ]);
            if ($letter->workflow_instance_id) {
                $this->backfillAssignments($decision, (int) $letter->workflow_instance_id);
            }
            DB::table('audit_logs')->insert([
                'actor_id' => null, 'action' => 'board_decision.backfilled', 'subject_type' => 'board_decisions', 'subject_id' => (string) $decision,
                'metadata' => json_encode(['after' => ['correspondence_id' => (int) $letter->id, 'recorded' => $recorded], 'source' => 'ترحيل 2026_11_30_000037 (سجل قرارات مجلس الإدارة)'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'ip_address' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    /** The executing departments from the workflow's own events, in order, with the state of the last one. */
    private function backfillAssignments(int $decision, int $instance): void
    {
        $events = DB::table('workflow_events as e')
            ->join('workflow_steps as f', 'f.id', '=', 'e.from_step_id')->join('workflow_steps as t', 't.id', '=', 'e.to_step_id')
            ->leftJoin('correspondence_actions as a', fn ($join) => $join->whereRaw("(a.metadata->>'workflow_event_id')::bigint = e.id"))
            ->where('e.workflow_instance_id', $instance)->orderBy('e.id')
            ->get(['e.id', 'e.actor_id', 'e.created_at', 'f.code as from_code', 't.code as to_code', 'a.recipient_id', 'a.recipient_department_id']);
        $current = null;
        foreach ($events as $event) {
            if ($event->to_code === 'execute' && $event->actor_id && $event->recipient_id && $event->recipient_department_id) {
                DB::table('board_decision_assignments')->where('board_decision_id', $decision)->where('department_id', '<>', $event->recipient_department_id)
                    ->whereIn('status', ['executing', 'reported', 'accepted'])->update(['status' => 'superseded', 'updated_at' => $event->created_at]);
                DB::table('board_decision_assignments')->updateOrInsert(
                    ['board_decision_id' => $decision, 'department_id' => $event->recipient_department_id],
                    ['head_user_id' => $event->recipient_id, 'assigned_by' => $event->actor_id, 'assigned_event_id' => $event->id, 'status' => 'executing',
                        'reported_at' => null, 'report_event_id' => null, 'accepted_at' => null, 'created_at' => $event->created_at, 'updated_at' => $event->created_at]);
                $current = (int) $event->recipient_department_id;
            } elseif ($current !== null && $event->from_code === 'execute') {
                DB::table('board_decision_assignments')->where('board_decision_id', $decision)->where('department_id', $current)
                    ->update(['status' => 'reported', 'reported_at' => $event->created_at, 'report_event_id' => $event->id, 'updated_at' => $event->created_at]);
            } elseif ($current !== null && $event->from_code === 'review' && $event->to_code !== 'execute') {
                DB::table('board_decision_assignments')->where('board_decision_id', $decision)->where('department_id', $current)
                    ->update(['status' => 'accepted', 'accepted_at' => $event->created_at, 'updated_at' => $event->created_at]);
            } elseif ($current !== null && $event->from_code === 'authorized_close' && $event->to_code === 'review') {
                DB::table('board_decision_assignments')->where('board_decision_id', $decision)->where('department_id', $current)
                    ->update(['status' => 'reported', 'accepted_at' => null, 'updated_at' => $event->created_at]);
            }
        }
    }
};
