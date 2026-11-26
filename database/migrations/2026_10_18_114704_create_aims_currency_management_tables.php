<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->char('code', 3)->primary();
            $table->string('name_ar', 100);
            $table->string('name_en', 100);
            $table->string('symbol', 10);
            $table->unsignedTinyInteger('decimal_places')->default(2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('aims_currency_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();

            $table->char('base_currency_code', 3);

            $table->foreign('base_currency_code')
                ->references('code')
                ->on('currencies')
                ->restrictOnDelete();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();
        });

        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();

            $table->char('from_currency_code', 3);
            $table->char('to_currency_code', 3);

            $table->foreign('from_currency_code')
                ->references('code')
                ->on('currencies')
                ->restrictOnDelete();

            $table->foreign('to_currency_code')
                ->references('code')
                ->on('currencies')
                ->restrictOnDelete();

            $table->decimal('rate', 24, 10);
            $table->timestamp('effective_at');
            $table->string('source_reference', 255);

            $table->foreignId('recorded_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();

            $table->index([
                'from_currency_code',
                'to_currency_code',
                'effective_at',
            ], 'exchange_rates_lookup_index');
        });

        $now = now();

        DB::table('currencies')->insert([
            [
                'code' => 'ILS',
                'name_ar' => 'الشيكل',
                'name_en' => 'Israeli New Shekel',
                'symbol' => '₪',
                'decimal_places' => 2,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'USD',
                'name_ar' => 'الدولار الأمريكي',
                'name_en' => 'US Dollar',
                'symbol' => '$',
                'decimal_places' => 2,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'EUR',
                'name_ar' => 'اليورو',
                'name_en' => 'Euro',
                'symbol' => '€',
                'decimal_places' => 2,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'GBP',
                'name_ar' => 'الجنيه الإسترليني',
                'name_en' => 'Pound Sterling',
                'symbol' => '£',
                'decimal_places' => 2,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        DB::table('aims_currency_settings')->insert([
            'id' => 1,
            'base_currency_code' => 'ILS',
            'updated_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Schema::table('financial_authority_limits', function (Blueprint $table) {
            $table->renameColumn(
                'limit_agorot',
                'limit_minor_units'
            );
        });

        Schema::table('financial_authority_limits', function (Blueprint $table) {
            $table->foreign('currency')
                ->references('code')
                ->on('currencies')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('financial_authority_limits', function (Blueprint $table) {
            $table->dropForeign(['currency']);
        });

        Schema::table('financial_authority_limits', function (Blueprint $table) {
            $table->renameColumn(
                'limit_minor_units',
                'limit_agorot'
            );
        });

        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('aims_currency_settings');
        Schema::dropIfExists('currencies');
    }
};
