<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aims_properties', function (Blueprint $t) {
            $t->id();
            $t->string('code', 80)->unique();
            $t->string('name');
            $t->foreignId('kind_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->foreignId('ownership_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->string('location')->nullable();
            $t->text('description')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('aims_property_floors', function (Blueprint $t) {
            $t->id();
            $t->foreignId('property_id')->constrained('aims_properties')->restrictOnDelete();
            $t->string('name');
            $t->integer('sort_order')->default(0);
            $t->timestamps();
            $t->unique(['property_id', 'name']);
        });
        Schema::create('aims_property_units', function (Blueprint $t) {
            $t->id();
            $t->foreignId('property_id')->constrained('aims_properties')->restrictOnDelete();
            $t->foreignId('floor_id')->nullable()->constrained('aims_property_floors')->restrictOnDelete();
            $t->string('code', 80);
            $t->string('name');
            $t->foreignId('type_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->foreignId('usage_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->decimal('area_m2', 12, 2)->nullable();
            $t->boolean('has_mezzanine')->default(false);
            $t->decimal('mezzanine_area_m2', 12, 2)->nullable();
            $t->string('availability', 32)->default('vacant');
            $t->boolean('rentable')->default(true);
            $t->text('description')->nullable();
            $t->timestamps();
            $t->unique(['property_id', 'code']);
        });
        Schema::create('aims_rental_tenants', function (Blueprint $t) {
            $t->id();
            $t->string('type', 32);
            $t->string('name');
            $t->string('identity_number')->nullable();
            $t->string('representative')->nullable();
            $t->string('phone')->nullable();
            $t->string('email')->nullable();
            $t->text('address')->nullable();
            $t->text('activity')->nullable();
            $t->timestamps();
        });
        Schema::create('aims_rental_applications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('aims_rental_tenants')->restrictOnDelete();
            $t->foreignId('unit_id')->nullable()->constrained('aims_property_units')->restrictOnDelete();
            $t->text('purpose')->nullable();
            $t->text('study')->nullable();
            $t->string('status', 32)->default('draft');
            $t->foreignId('requested_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
        });
        Schema::create('aims_rental_contracts', function (Blueprint $t) {
            $t->id();
            $t->string('number', 100)->unique();
            $t->foreignId('tenant_id')->constrained('aims_rental_tenants')->restrictOnDelete();
            $t->foreignId('application_id')->nullable()->constrained('aims_rental_applications')->restrictOnDelete();
            $t->foreignId('parent_contract_id')->nullable()->constrained('aims_rental_contracts')->restrictOnDelete();
            $t->string('status', 32)->default('draft');
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('currency_code', 10);
            $t->string('frequency', 20)->default('monthly');
            $t->decimal('total_rent', 18, 2);
            $t->boolean('auto_renew')->default(false);
            $t->text('renewal_clause')->nullable();
            $t->text('terms')->nullable();
            $t->string('signed_document_path')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('activated_at')->nullable();
            $t->timestamps();
        });
        Schema::create('aims_rental_contract_units', function (Blueprint $t) {
            $t->id();
            $t->foreignId('contract_id')->constrained('aims_rental_contracts')->restrictOnDelete();
            $t->foreignId('unit_id')->constrained('aims_property_units')->restrictOnDelete();
            $t->decimal('rent_share', 18, 2);
            $t->timestamps();
            $t->unique(['contract_id', 'unit_id']);
        });
        Schema::create('aims_rental_dues', function (Blueprint $t) {
            $t->id();
            $t->foreignId('contract_id')->constrained('aims_rental_contracts')->restrictOnDelete();
            $t->date('due_on');
            $t->string('currency_code', 10);
            $t->decimal('amount', 18, 2);
            $t->decimal('adjustment', 18, 2)->default(0);
            $t->string('status', 25)->default('open');
            $t->timestamps();
            $t->unique(['contract_id', 'due_on']);
        });
        Schema::create('aims_rental_receipt_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('due_id')->constrained('aims_rental_dues')->restrictOnDelete();
            $t->string('accounting_reference', 150);
            $t->decimal('amount', 18, 2);
            $t->string('currency_code', 10);
            $t->timestamp('accounting_posted_at');
            $t->timestamps();
            $t->unique(['accounting_reference', 'due_id']);
        });
        Schema::create('aims_rental_cases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('contract_id')->nullable()->constrained('aims_rental_contracts')->restrictOnDelete();
            $t->foreignId('unit_id')->nullable()->constrained('aims_property_units')->restrictOnDelete();
            $t->string('kind', 32);
            $t->string('status', 32)->default('draft');
            $t->text('reason')->nullable();
            $t->text('details')->nullable();
            $t->date('effective_on')->nullable();
            $t->foreignId('requested_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
        });
        Schema::create('aims_rental_meter_readings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('unit_id')->constrained('aims_property_units')->restrictOnDelete();
            $t->foreignId('contract_id')->nullable()->constrained('aims_rental_contracts')->restrictOnDelete();
            $t->string('meter_kind', 50);
            $t->decimal('reading', 16, 3);
            $t->date('read_on');
            $t->string('photo_path')->nullable();
            $t->timestamps();
        });
        Schema::create('aims_rental_notices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('contract_id')->constrained('aims_rental_contracts')->restrictOnDelete();
            $t->string('kind', 40);
            $t->text('body');
            $t->string('status', 30)->default('draft');
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['aims_rental_notices', 'aims_rental_meter_readings', 'aims_rental_cases', 'aims_rental_receipt_allocations', 'aims_rental_dues', 'aims_rental_contract_units', 'aims_rental_contracts', 'aims_rental_applications', 'aims_rental_tenants', 'aims_property_units', 'aims_property_floors', 'aims_properties'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
