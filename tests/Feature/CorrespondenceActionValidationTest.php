<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Correspondence\CorrespondenceRegistry;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CorrespondenceActionValidationTest extends TestCase
{
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
        $app['config']->set('database.connections.sqlite.url', null);

        return $app;
    }

    public function test_normal_actions_do_not_validate_unused_workflow_fields(): void
    {
        foreach (['reply', 'forward', 'issue', 'complete', 'close'] as $action) {
            foreach ([null, '', 'unused'] as $value) {
                self::assertSame(['revision'], $this->validationErrors([
                    'action' => $action, 'transition_id' => $value, 'workflow_revision' => $value,
                ]));
            }
        }
    }

    public function test_workflow_actions_require_both_integer_fields(): void
    {
        foreach ([[], ['transition_id' => null, 'workflow_revision' => null],
            ['transition_id' => '', 'workflow_revision' => ''],
            ['transition_id' => 'reply', 'workflow_revision' => 'invalid'],
        ] as $fields) {
            self::assertSame(['revision', 'transition_id', 'workflow_revision'], $this->validationErrors(['action' => 'workflow', ...$fields]));
        }
    }

    public function test_workflow_actions_accept_integer_strings_from_form_data(): void
    {
        self::assertSame(['revision'], $this->validationErrors([
            'action' => 'workflow', 'transition_id' => '12', 'workflow_revision' => '3',
        ]));
    }

    /**
     * An invalid correspondence revision keeps validation tests before any persistence or routing operations.
     *
     * @param  array<string, mixed>  $fields
     * @return list<string>
     */
    private function validationErrors(array $fields): array
    {
        try {
            app(CorrespondenceRegistry::class)->act(User::factory()->make(), (string) Str::uuid(), [
                'idempotency_key' => (string) Str::uuid(), 'revision' => 0, 'note' => 'Reply text', ...$fields,
            ]);
            self::fail('The invalid correspondence revision must fail validation.');
        } catch (ValidationException $exception) {
            return array_keys($exception->errors());
        }
    }
}
