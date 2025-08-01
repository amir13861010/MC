<?php

namespace App\Console\Commands;

use App\Models\Trade;
use Illuminate\Console\Command;

class DeactivateExpiredTradesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'trade:deactivate-expired';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deactivate expired trades';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Checking for expired trades...');
        
        $expiredTrades = Trade::where('is_active', true)
            ->where('expires_at', '<', now())
            ->get();
        
        $count = $expiredTrades->count();
        
        if ($count === 0) {
            $this->info('No expired trades found.');
            return Command::SUCCESS;
        }
        
        $this->info("Found {$count} expired trades. Deactivating...");
        
        foreach ($expiredTrades as $trade) {
            $trade->is_active = false;
            $trade->save();
            
            $this->line("Deactivated trade for user: {$trade->user_id}");
        }
        
        $this->info("Successfully deactivated {$count} expired trades.");
        
        return Command::SUCCESS;
    }
} 