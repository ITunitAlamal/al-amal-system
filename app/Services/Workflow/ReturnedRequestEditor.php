<?php

namespace App\Services\Workflow;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Configuration\CustomFieldEntities;
use App\Services\Configuration\CustomFieldService;
use App\Services\WorkflowVisibilityService;
use Illuminate\Support\Facades\DB;

/**
 * Editing a generic request that was returned to its requester.
 *
 * Only the requester, and only while the request waits at its start step: the title, the description and the
 * fields of the submission form. The changes are saved together with the resend action, in its transaction, and
 * written to the audit trail with what changed. At any other step, and for anyone else, nothing is editable.
 */
final class ReturnedRequestEditor
{
    public function __construct(
        private readonly CustomFieldService $fields,
        private readonly AuditService $audit,
    ) {}

    /**
     * Whether the user may edit the request's own data now.
     */
    public function allows(object $instance, User $user): bool
    {
        if ($instance->subject_type !== WorkflowEngine::SUBJECT_TYPE || $instance->status !== 'in_progress'
            || (int) $instance->requested_by !== (int) $user->id) {
            return false;
        }
        $step = DB::table('workflow_steps')->where('id', $instance->current_step_id)->first(['step_type', 'assignment_type']);

        return $step !== null && $step->step_type === 'start' && $step->assignment_type === 'requester';
    }

    /**
     * What the edit form starts with: the stored title, description and submission fields with their values.
     * Null when the request cannot be edited by this user now.
     *
     * @return array{title: string, description: ?string, fields: list<array<string, mixed>>, values: array<string, mixed>}|null
     */
    public function form(object $instance, User $user): ?array
    {
        $request = $this->allows($instance, $user) ? DB::table('aims_requests')->where('id', $instance->subject_id)->first() : null;
        if (! $request) {
            return null;
        }
        $definitions = $this->fields->definitions(CustomFieldEntities::AIMS_REQUEST, (string) $request->request_type);

        return [
            'title' => (string) $request->title,
            'description' => $request->description,
            'fields' => $this->fields->forForm($definitions),
            // The requester entered these values, sensitive ones included.
            'values' => $this->fields->formValues(CustomFieldEntities::AIMS_REQUEST, (int) $request->id, $definitions, true),
        ];
    }

    /**
     * Save the requester's changes before the resend. Runs inside the caller's transaction, followed by the
     * engine's act(), which checks the turn and the revision: a refused action undoes the edit with it.
     * Does nothing when the request is not editable by this user now, or when nothing was sent to change.
     *
     * @param  array{title?: string, description?: ?string, fields?: array<string, mixed>}  $input  validated by the controller
     */
    public function apply(object $instance, User $actor, array $input, ?string $ipAddress = null): void
    {
        $locked = DB::table('workflow_instances')->where('id', $instance->id)->lockForUpdate()->first();
        if (! $locked || ! $this->allows($locked, $actor)) {
            return;
        }
        $request = DB::table('aims_requests')->where('id', $locked->subject_id)->lockForUpdate()->first();
        if (! $request) {
            return;
        }

        $definitions = $this->fields->definitions(CustomFieldEntities::AIMS_REQUEST, (string) $request->request_type);
        $stored = $this->fields->context(CustomFieldEntities::AIMS_REQUEST, (int) $request->id);
        // A field that was not submitted keeps its stored value.
        $fieldInput = ($input['fields'] ?? [])
            + array_filter(array_intersect_key($stored, $definitions->pluck('code')->flip()->all()), fn ($value): bool => $value !== null);
        $values = $this->fields->validate($definitions, $fieldInput, $stored);

        $title = array_key_exists('title', $input) ? trim((string) $input['title']) : (string) $request->title;
        $description = array_key_exists('description', $input)
            ? (trim((string) $input['description']) !== '' ? trim((string) $input['description']) : null)
            : $request->description;

        $before = [];
        $after = [];
        if ($title !== (string) $request->title) {
            $before['title'] = $request->title;
            $after['title'] = $title;
        }
        if (! $this->same($request->description, $description)) {
            $before['description'] = $request->description;
            $after['description'] = $description;
        }
        $sensitive = $definitions->filter(fn (object $definition): bool => (bool) $definition->is_sensitive)->pluck('code')->flip();
        $changed = [];
        foreach ($values as $code => $value) {
            if ($this->same($stored[$code] ?? null, $value)) {
                continue;
            }
            $changed[$code] = $value;
            // A sensitive value is recorded as changed, never written to the audit trail.
            $before['fields'][$code] = isset($sensitive[$code]) ? '[محجوب]' : ($stored[$code] ?? null);
            $after['fields'][$code] = isset($sensitive[$code]) ? '[تغيّر]' : $value;
        }
        if ($after === []) {
            return;
        }

        $now = now();
        DB::table('aims_requests')->where('id', $request->id)->update([
            'title' => $title, 'description' => $description, 'revision' => (int) $request->revision + 1, 'updated_at' => $now,
        ]);
        $this->fields->save(CustomFieldEntities::AIMS_REQUEST, (int) $request->id, $definitions, $changed, (int) $actor->id);

        if (WorkflowVisibilityService::confidentialVersions()->where('cv.id', $locked->workflow_version_id)->exists()) {
            // A confidential request (a complaint) is read only by the people on it, and the audit trail has other
            // readers: it records that the request was edited and which parts, never the text, as a restricted row.
            $this->audit->event('workflow.request.edited', [
                'subject_type' => 'aims_requests', 'subject_id' => (string) $request->id, 'before' => null,
                'after' => ['changed' => [...array_keys(array_diff_key($after, ['fields' => true])), ...array_keys($after['fields'] ?? [])]],
                'actor_id' => (int) $actor->id, 'ip' => $ipAddress, 'sensitivity' => 'restricted',
            ]);

            return;
        }
        $this->audit->recordRow('workflow.request.edited', 'aims_requests', (int) $request->id,
            $before, [...$after, 'reference' => $locked->reference_no], (int) $actor->id, $ipAddress);
    }

    /** Whether two field values are the same once their storage form is set aside (a number as text, an empty text). */
    private function same(mixed $old, mixed $new): bool
    {
        $blank = fn (mixed $value): bool => $value === null || $value === '' || $value === false;
        if ($blank($old) || $blank($new)) {
            return $blank($old) && $blank($new);
        }

        return is_numeric($old) && is_numeric($new) ? (float) $old === (float) $new : (string) $old === (string) $new;
    }
}
