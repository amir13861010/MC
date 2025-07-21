<?php

namespace App\Console\Commands;

use App\Jobs\ProcessDailyProfitJob;
use Illuminate\Console\Command;

class ProcessDailyProfitCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'trade:process-daily-profit {--date= : Specific date to process (Y-m-d format)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process daily profit for all users based on their trade files';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $date = $this->option('date') ?: now()->format('Y-m-d');
        
        $this->info("Processing daily profit for date: {$date}");
        
        // Dispatch the job
        ProcessDailyProfitJob::dispatch();
        
        $this->info('Daily profit processing job has been queued successfully.');
        
        return Command::SUCCESS;
    }
} 