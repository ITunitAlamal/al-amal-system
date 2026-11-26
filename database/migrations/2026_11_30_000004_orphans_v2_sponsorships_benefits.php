<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Phase 5 — «الكفالات والمساعدات» and «سجل الاستفادة».
 *
 * Sponsorships are commitments (not money). Each due period can be referred to Accounts as a finance receivable
 * (FinanceSourceBridge, module "sponsorships"); collections are read back from Accounts' allocations — there is no
 * department ledger. benefit_records holds CONFIRMED deliveries only, append-only: a correction is a reversal row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sponsors', function (Blueprint $t): void {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->string('reference_no', 30)->unique();
            $t->string('kind', 12)->default('individual');
            $t->string('name', 300);
            $t->string('contact_name', 200)->nullable();
            $t->string('phone', 20)->nullable();
            $t->string('email', 150)->nullable();
            $t->string('country', 100)->nullable();
            $t->text('notes')->nullable();
            $t->string('status', 10)->default('active');
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        DB::statement("ALTER TABLE sponsors ADD CONSTRAINT sponsors_kind CHECK (kind IN ('individual','institution'))");
        DB::statement("ALTER TABLE sponsors ADD CONSTRAINT sponsors_status CHECK (status IN ('active','inactive'))");

        Schema::create('sponsorships', function (Blueprint $t): void {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->string('reference_no', 30)->unique();
            $t->foreignId('sponsor_id')->constrained('sponsors')->restrictOnDelete();
            $t->foreignId('program_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->string('coverage', 12)->default('individual');
            $t->decimal('amount', 14, 2);
            $t->string('currency', 3);
            $t->unsignedTinyInteger('period_months')->default(1);
            $t->foreignId('frequency_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->date('starts_on');
            $t->date('ends_on')->nullable();
            $t->string('status', 10)->default('draft');
            $t->string('status_reason', 300)->nullable();
            $t->foreignId('renewal_of_id')->nullable()->constrained('sponsorships')->restrictOnDelete();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('activated_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('activated_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'ends_on']);
        });
        DB::statement("ALTER TABLE sponsorships ADD CONSTRAINT sponsorships_coverage CHECK (coverage IN ('individual','multiple','household','pooled'))");
        DB::statement("ALTER TABLE sponsorships ADD CONSTRAINT sponsorships_status CHECK (status IN ('draft','active','partial','suspended','expired','ended'))");
        DB::statement('ALTER TABLE sponsorships ADD CONSTRAINT sponsorships_amount CHECK (amount > 0)');
        DB::statement('ALTER TABLE sponsorships ADD CONSTRAINT sponsorships_period CHECK (period_months BETWEEN 1 AND 12)');
        DB::statement('ALTER TABLE sponsorships ADD CONSTRAINT sponsorships_dates CHECK (ends_on IS NULL OR ends_on >= starts_on)');
        DB::statement("ALTER TABLE sponsorships ADD CONSTRAINT sponsorships_activation CHECK (status = 'draft' OR (activated_by IS NOT NULL AND activated_at IS NOT NULL))");

        Schema::create('sponsorship_beneficiaries', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('sponsorship_id')->constrained('sponsorships')->restrictOnDelete();
            $t->foreignId('orphan_id')->nullable()->constrained('orphans')->restrictOnDelete();
            $t->foreignId('household_id')->nullable()->constrained('households')->restrictOnDelete();
            $t->decimal('share_amount', 14, 2)->nullable();
            $t->date('starts_on');
            $t->date('ends_on')->nullable();
            $t->string('ended_reason', 300)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index('orphan_id');
            $t->index('household_id');
        });
        DB::statement('ALTER TABLE sponsorship_beneficiaries ADD CONSTRAINT sponsorship_beneficiaries_unit CHECK (num_nonnulls(orphan_id, household_id) = 1)');
        DB::statement('CREATE UNIQUE INDEX sponsorship_beneficiaries_live_orphan ON sponsorship_beneficiaries (sponsorship_id, orphan_id) WHERE ends_on IS NULL AND orphan_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX sponsorship_beneficiaries_live_household ON sponsorship_beneficiaries (sponsorship_id, household_id) WHERE ends_on IS NULL AND household_id IS NOT NULL');

        Schema::create('sponsorship_dues', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('sponsorship_id')->constrained('sponsorships')->restrictOnDelete();
            $t->date('period_start');
            $t->date('due_on');
            $t->decimal('amount', 14, 2);
            $t->string('currency', 3);
            $t->foreignId('finance_receivable_id')->nullable()->constrained('finance_receivables')->restrictOnDelete();
            $t->string('status', 10)->default('planned');
            $t->foreignId('referred_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('referred_at')->nullable();
            $t->timestamps();
            $t->unique(['sponsorship_id', 'period_start']);
        });
        DB::statement("ALTER TABLE sponsorship_dues ADD CONSTRAINT sponsorship_dues_status CHECK (status IN ('planned','referred','cancelled'))");
        DB::statement("ALTER TABLE sponsorship_dues ADD CONSTRAINT sponsorship_dues_referred CHECK (status <> 'referred' OR finance_receivable_id IS NOT NULL)");

        Schema::create('assistance_requests', function (Blueprint $t): void {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->string('reference_no', 30)->unique();
            $t->foreignId('orphan_id')->nullable()->constrained('orphans')->restrictOnDelete();
            $t->foreignId('household_id')->nullable()->constrained('households')->restrictOnDelete();
            $t->foreignId('type_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->text('description');
            $t->decimal('quantity', 12, 2)->nullable();
            $t->decimal('amount', 14, 2)->nullable();
            $t->string('currency', 3)->nullable();
            $t->string('status', 10)->default('requested');
            $t->foreignId('requested_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->text('decision_note')->nullable();
            $t->timestamps();
            $t->index(['status', 'id']);
        });
        DB::statement('ALTER TABLE assistance_requests ADD CONSTRAINT assistance_requests_unit CHECK (num_nonnulls(orphan_id, household_id) = 1)');
        DB::statement("ALTER TABLE assistance_requests ADD CONSTRAINT assistance_requests_status CHECK (status IN ('requested','approved','rejected','fulfilled','cancelled'))");

        Schema::create('benefit_records', function (Blueprint $t): void {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->string('kind', 10)->default('delivery');
            $t->foreignId('orphan_id')->nullable()->constrained('orphans')->restrictOnDelete();
            $t->foreignId('household_id')->nullable()->constrained('households')->restrictOnDelete();
            $t->string('source', 12);
            $t->unsignedBigInteger('campaign_id')->nullable();
            $t->unsignedBigInteger('campaign_delivery_id')->nullable();
            $t->foreignId('sponsorship_id')->nullable()->constrained('sponsorships')->restrictOnDelete();
            $t->foreignId('assistance_request_id')->nullable()->constrained('assistance_requests')->restrictOnDelete();
            $t->foreignId('type_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->string('description', 500);
            $t->decimal('quantity', 12, 2)->nullable();
            $t->string('unit', 30)->nullable();
            $t->decimal('amount', 14, 2)->nullable();
            $t->string('currency', 3)->nullable();
            $t->foreignId('recipient_person_id')->nullable()->constrained('persons')->restrictOnDelete();
            $t->string('recipient_name', 300)->nullable();
            $t->date('delivered_on');
            $t->string('fulfillment_ref', 140)->nullable();
            $t->foreignId('reverses_id')->nullable()->unique()->constrained('benefit_records')->restrictOnDelete();
            $t->string('reason', 500)->nullable();
            $t->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->index(['orphan_id', 'delivered_on']);
            $t->index(['household_id', 'delivered_on']);
            $t->index('campaign_id');
        });
        DB::statement('ALTER TABLE benefit_records ADD CONSTRAINT benefit_records_unit CHECK (num_nonnulls(orphan_id, household_id) = 1)');
        DB::statement("ALTER TABLE benefit_records ADD CONSTRAINT benefit_records_kind CHECK (kind IN ('delivery','reversal'))");
        DB::statement("ALTER TABLE benefit_records ADD CONSTRAINT benefit_records_source CHECK (source IN ('campaign','sponsorship','assistance','other'))");
        DB::statement("ALTER TABLE benefit_records ADD CONSTRAINT benefit_records_reversal CHECK ((kind = 'reversal') = (reverses_id IS NOT NULL) AND (kind <> 'reversal' OR reason IS NOT NULL))");
        // One confirmed benefit per field delivery: a repeated sync can never add a second one.
        DB::statement("CREATE UNIQUE INDEX benefit_records_one_per_delivery ON benefit_records (campaign_delivery_id) WHERE kind = 'delivery' AND campaign_delivery_id IS NOT NULL");
        DB::unprepared(<<<'SQL'
CREATE FUNCTION benefit_records_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'BENEFIT_APPEND_ONLY: confirmed benefits are corrected by a reversal record, never edited or deleted' USING ERRCODE = '23514';
END $$;
CREATE TRIGGER benefit_records_append_only BEFORE UPDATE OR DELETE ON benefit_records FOR EACH ROW EXECUTE FUNCTION benefit_records_append_only();
SQL);

        foreach (['sponsor_seq', 'sponsorship_seq', 'assistance_seq'] as $sequence) {
            DB::statement("CREATE SEQUENCE {$sequence} START 1");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only: sponsorships and confirmed benefits are records.');
    }
};
