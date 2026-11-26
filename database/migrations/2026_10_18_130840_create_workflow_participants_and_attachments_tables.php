<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_participants', function (Blueprint $table) {
            $table->id();

            $table->foreignId('workflow_instance_id')
                ->constrained('workflow_instances')
                ->restrictOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            // requester / origin_head / assignee /
            // supervisor / reviewer / observer
            $table->string('participation_type', 40);

            // public_status / operational / full_authorized
            $table->string('visibility_scope', 40)
                ->default('public_status');

            $table->boolean('is_active')->default(true);

            $table->foreignId('added_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamp('revoked_at')->nullable();

            $table->foreignId('revoked_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();

            $table->unique(
                [
                    'workflow_instance_id',
                    'user_id',
                    'participation_type',
                ],
                'workflow_participant_unique'
            );

            $table->index(
                ['user_id', 'is_active'],
                'workflow_participant_user_index'
            );
        });

        Schema::create('workflow_event_attachments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('workflow_event_id')
                ->constrained('workflow_events')
                ->restrictOnDelete();

            $table->foreignId('uploaded_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->uuid('public_id')->unique();

            $table->string('original_filename', 255);

            $table->string('storage_disk', 40)
                ->default('local');

            $table->string('storage_path', 1024);

            $table->string('mime_type', 150);

            $table->unsignedBigInteger('file_size');

            $table->char('sha256_checksum', 64);

            // general / internal / financial / confidential
            $table->string('visibility_class', 40)
                ->default('internal');

            // supporting_document / signed_document /
            // technical_report / contract / invoice /
            // receipt / other
            $table->string('document_type', 60)
                ->default('supporting_document');

            $table->string('description', 500)->nullable();

            $table->timestamps();

            $table->index(
                ['workflow_event_id', 'visibility_class'],
                'workflow_attachment_visibility_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_event_attachments');
        Schema::dropIfExists('workflow_participants');
    }
};
