<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule): void
    {
        // Deactivate expired trades - 00:00 UTC (03:30 Iran time)
        $schedule->command('trade:deactivate-expired')
                ->dailyAt('00:00')
                ->withoutOverlapping()
                ->appendOutputTo(storage_path('logs/deactivate-expired.log'));

        // Check and auto-renew expired trades - 00:05 UTC (03:35 Iran time)
        $schedule->command('trade:check-expired')
                ->dailyAt('00:05')
                ->withoutOverlapping()
                ->appendOutputTo(storage_path('logs/check-expired.log'));

        // Process leg rewards - 00:10 UTC (03:40 Iran time)
        $schedule->command('rewards:process-leg-rewards')
                ->dailyAt('00:10')
                ->withoutOverlapping()
                ->appendOutputTo(storage_path('logs/rewards.log'));

        // Process daily profit for YESTERDAY - 00:15 UTC (03:45 Iran time)
        // پردازش سود دیروز - امروز صبح
        $schedule->command('trade:process-daily-profit')
                ->dailyAt('00:15')
                ->withoutOverlapping()
                ->appendOutputTo(storage_path('logs/daily-profit.log'));

        // Calculate daily bonus - 00:20 UTC (03:50 Iran time)
        $schedule->command('bonus:calculate-daily')
                ->dailyAt('00:20')
                ->withoutOverlapping()
                ->appendOutputTo(storage_path('logs/daily-bonus.log'));

        // Run queue worker every minute
        $schedule->command('queue:work --stop-when-empty')
                ->everyMinute()
                ->withoutOverlapping()
                ->appendOutputTo(storage_path('logs/queue.log'));

        // Run scheduler every minute
        $schedule->command('schedule:run')
                ->everyMinute()
                ->withoutOverlapping()
                ->appendOutputTo(storage_path('logs/scheduler.log'));
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