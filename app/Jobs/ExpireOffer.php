<?php

namespace App\Jobs;

use App\Models\Dispatch;
use App\Services\DispatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Runs when the 60-second window ends. If the crew has not answered, escalate to the next unit. */
class ExpireOffer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $dispatchId)
    {
    }

    public function handle(DispatchService $service): void
    {
        $dispatch = Dispatch::find($this->dispatchId);
        if ($dispatch && $dispatch->status === Dispatch::OFFERED && $dispatch->expires_at && $dispatch->expires_at->lte(now()->addSeconds(3))) {
            $service->expire($dispatch);
        }
    }
}
