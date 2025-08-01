<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ProcessTradeQueue extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'trade:process-queue';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process trade queue jobs';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting trade queue processor...');
        
        // این command برای اجرای queue worker استفاده می‌شود
        // در production باید queue worker را به صورت background اجرا کنید
        $this->info('Use: php artisan queue:work');
        
        return 0;
    }
} 