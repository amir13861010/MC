<?php

namespace App\Console\Commands;

use App\Models\CapitalHistory;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class StoreDailyCapital extends Command
{
    protected $signature = 'capital:store-daily';
    protected $description = 'Store daily capital profit for all users';

    public function handle()
    {
        $today = Carbon::today()->format('Y-m-d');
        
        User::chunk(100, function ($users) use ($today) {
            foreach ($users as $user) {
                CapitalHistory::updateOrCreate(
                    [
                        'user_id' => $user->user_id,
                        'date' => $today,
                    ],
                    [
                        'capital_profit' => $user->capital_profit,
                    ]
                );
            }
        });

        $this->info("Daily capital stored for $today");
    }
}