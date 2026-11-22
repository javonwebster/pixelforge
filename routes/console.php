<?php

use Illuminate\Support\Facades\Schedule;

// Run by pf-scheduler (`php artisan schedule:work`). Webhook delivery isn't
// guaranteed, so poll for what may have been missed. withoutOverlapping:
// a slow run never stacks up with the next one.
Schedule::command('pixelforge:reconcile')->everyMinute()->withoutOverlapping();
