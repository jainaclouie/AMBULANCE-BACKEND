<?php

use Illuminate\Support\Facades\Schedule;

// Safety net: if the queue worker is not running, expired offers are still escalated.
Schedule::command('dispatch:expire-offers')->everyMinute();
