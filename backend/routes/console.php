<?php

use App\Actions\Campaigns\RecoverStalledEmailJobsAction;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('campaigns:recover-stalled', function (RecoverStalledEmailJobsAction $action) {
    $this->info("Re-dispatched {$action->execute()} stalled recipient(s).");
})->purpose('Re-dispatch recipients whose queued email job was lost');

Schedule::command('model:prune')->daily();
Schedule::command('campaigns:recover-stalled')->everyFiveMinutes()->withoutOverlapping();
