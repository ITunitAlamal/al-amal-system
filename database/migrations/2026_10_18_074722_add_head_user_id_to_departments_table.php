
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->foreignId('head_user_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->index(
                'head_user_id',
                'departments_head_user_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropForeign(['head_user_id']);
            $table->dropIndex('departments_head_user_index');
            $table->dropColumn('head_user_id');
        });
    }
};
