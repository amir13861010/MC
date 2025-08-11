<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Jobs\CalculateDailyBonusJob;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * These jobs run in a default schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule): void
    {
        // Process leg rewards daily at midnight
        $schedule->command('rewards:process-leg-rewards')
                ->daily()
                ->at('00:00')
                ->withoutOverlapping()
                ->appendOutputTo(storage_path('logs/rewards.log'));

        // Process daily profit for all users daily at 01:00 AM
        $schedule->command('trade:process-daily-profit')
                ->daily()
                ->at('01:00')
                ->withoutOverlapping()
                ->appendOutputTo(storage_path('logs/daily-profit.log'));

        // Deactivate expired trades daily at 00:30 AM
        $schedule->command('trade:deactivate-expired')
                ->daily()
                ->at('00:30')
                ->withoutOverlapping()
                ->appendOutputTo(storage_path('logs/deactivate-expired.log'));

        // Auto-renew expired trades daily at 00:45 AM
        $schedule->command('trade:check-expired')
                ->daily()
                ->at('00:45')
                ->withoutOverlapping()
                ->appendOutputTo(storage_path('logs/check-expired.log'));

        $schedule->command('bonus:calculate-daily')
                ->daily()
                ->at('02:00')
                ->withoutOverlapping()
                ->appendOutputTo(storage_path('logs/daily-bonus.log'));

    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
} 