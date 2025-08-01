<?php

namespace App\Jobs;

use App\Models\Trade;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessDailyProfitJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $today = now()->format('Y-m-d');
        Log::info("Processing daily profit for date: {$today}");

        // Get only active trades
        $trades = Trade::active()->get();

        foreach ($trades as $trade) {
            try {
                $this->processUserDailyProfit($trade, $today);
            } catch (\Exception $e) {
                Log::error("Error processing daily profit for user {$trade->user_id}: " . $e->getMessage());
            }
        }
    }

    /**
     * Process daily profit for a specific user
     */
    private function processUserDailyProfit(Trade $trade, string $date): void
    {
        // Check if trade file exists
        if (!Storage::disk('local')->exists($trade->file_path)) {
            Log::warning("Trade file not found for user {$trade->user_id}: {$trade->file_path}");
            return;
        }

        // Read and parse JSON file
        $jsonContent = Storage::disk('local')->get($trade->file_path);
        $tradeData = json_decode($jsonContent, true);

        if (!$tradeData || !isset($tradeData['data']['dailyReports'])) {
            Log::warning("Invalid trade data format for user {$trade->user_id}");
            return;
        }

        // Find today's daily report
        $todayReport = null;
        foreach ($tradeData['data']['dailyReports'] as $report) {
            if ($report['date'] === $date) {
                $todayReport = $report;
                break;
            }
        }

        if (!$todayReport) {
            Log::info("No daily report found for user {$trade->user_id} on date {$date}");
            return;
        }

        // Get user
        $user = User::where('user_id', $trade->user_id)->first();
        if (!$user) {
            Log::warning("User not found: {$trade->user_id}");
            return;
        }

        // Calculate daily profit
        $dailyProfitPercent = floatval($todayReport['dailyProfit']);
        $depositBalance = floatval($user->deposit_balance);
        $dailyProfitAmount = $depositBalance * ($dailyProfitPercent / 100);

        // Update user's capital_profit
        $user->capital_profit += $dailyProfitAmount;
        $user->save();

        // Update trade's last_processed_at
        $trade->last_processed_at = now();
        $trade->save();

        Log::info("Processed daily profit for user {$trade->user_id}", [
            'date' => $date,
            'daily_profit_percent' => $dailyProfitPercent,
            'deposit_balance' => $depositBalance,
            'daily_profit_amount' => $dailyProfitAmount,
            'new_capital_profit' => $user->capital_profit,
            'last_processed_at' => $trade->last_processed_at
        ]);
    }
} 