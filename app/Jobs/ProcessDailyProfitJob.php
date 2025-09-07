<?php

namespace App\Jobs;

use App\Models\Trade;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessDailyProfitJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $date;

    /**
     * Create a new job instance.
     */
    public function __construct($date = null)
    {
        $this->date = $date ?: now()->format('Y-m-d');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info("Processing daily profit for date: {$this->date}");

        // Get all active users
        $users = User::where('status', 'active')->get();

        foreach ($users as $user) {
            try {
                $this->processUserDailyProfit($user, $this->date);
            } catch (\Exception $e) {
                Log::error("Error processing daily profit for user {$user->id}: " . $e->getMessage());
            }
        }
    }

    /**
     * Process daily profit for a specific user
     */
    private function processUserDailyProfit(User $user, string $date): void
    {
        // Check if user has a trade file
        $trade = Trade::where('user_id', $user->id)->first();

        // If user doesn't have trade file, create one
        if (!$trade) {
            Log::info("No trade file found for user {$user->id}, creating new one...");
            $trade = $this->createTradeFile($user);
            
            if (!$trade) {
                Log::warning("Failed to create trade file for user {$user->id}");
                return;
            }
        }

        // Check if trade file exists
        if (!Storage::disk('local')->exists($trade->file_path)) {
            Log::warning("Trade file not found for user {$user->id}: {$trade->file_path}");
            
            // Recreate the trade file
            $trade = $this->createTradeFile($user);
            if (!$trade) {
                Log::warning("Failed to recreate trade file for user {$user->id}");
                return;
            }
        }

        // Read and parse JSON file
        $jsonContent = Storage::disk('local')->get($trade->file_path);
        $tradeData = json_decode($jsonContent, true);

        if (!$tradeData || !isset($tradeData['data']['dailyReports'])) {
            Log::warning("Invalid trade data format for user {$user->id}");
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
            Log::info("No daily report found for user {$user->id} on date {$date}");
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

        Log::info("Processed daily profit for user {$user->id}", [
            'date' => $date,
            'daily_profit_percent' => $dailyProfitPercent,
            'deposit_balance' => $depositBalance,
            'daily_profit_amount' => $dailyProfitAmount,
            'new_capital_profit' => $user->capital_profit,
            'last_processed_at' => $trade->last_processed_at
        ]);
    }

    /**
     * Create trade file for user
     */
    private function createTradeFile(User $user): ?Trade
    {
        try {
            $level = ($user->deposit_amount >= 500) ? 2 : 1;
            
            $url = 'https://omegafocus.com/api/trade';
            $token = 'a16b76fdca144deda73730b4be61739e747cbf355f8e054cefbd57f0acb5cfa9';

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])->post($url, [
                'year' => date('Y'),
                'month' => date('n'),
                'level' => $level,
            ]);

            if ($response->successful()) {
                $result = $response->json();
                
                // Create trades directory if it doesn't exist
                if (!Storage::disk('local')->exists('trades')) {
                    Storage::disk('local')->makeDirectory('trades');
                }

                // Save new file
                $filename = $user->id . '.json';
                $filePath = 'trades/' . $filename;
                Storage::disk('local')->put($filePath, json_encode($result, JSON_PRETTY_PRINT));

                // Create or update trade record
                $trade = Trade::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'file_path' => $filePath,
                        'level' => $level,
                        'amount' => $user->deposit_amount ?? 0,
                        'last_refreshed' => now(),
                        'last_processed_at' => null
                    ]
                );

                Log::info('Trade file created for user: ' . $user->id);
                return $trade;
            } else {
                Log::error('Trade API failed for user: ' . $user->id, [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }
        } catch (\Exception $e) {
            Log::error('Error creating trade file for user: ' . $user->id, [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}