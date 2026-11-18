<?php

namespace App\Console\Commands;

use App\Models\Dispatch;
use App\Services\DispatchService;
use Illuminate\Console\Command;

class ExpireOffers extends Command
{
    protected $signature = 'dispatch:expire-offers';

    protected $description = 'Escalate offers that were not accepted within the time limit (safety net for the queue).';

    public function handle(DispatchService $service): int
    {
        $count = 0;
        Dispatch::where('status', Dispatch::OFFERED)->where('expires_at', '<=', now())->get()->each(function (Dispatch $d) use ($service, &$count) {
            $service->expire($d);
            $count++;
        });
        $this->info("Expired {$count} offer(s).");

        return self::SUCCESS;
    }
}
