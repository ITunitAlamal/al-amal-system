<?php

namespace App\Console\Commands;

use App\Services\Dashboard\PersonalTasks;
use Illuminate\Console\Command;

final class PersonalTasksRemind extends Command
{
    protected $signature = 'personal-tasks:remind';

    protected $description = 'Send private in-app reminders for personal To-Do items whose reminder time has come (once per reminder)';

    public function handle(PersonalTasks $tasks): int
    {
        $this->info('Sent '.$tasks->sendDueReminders().' personal reminder(s)');

        return self::SUCCESS;
    }
}
