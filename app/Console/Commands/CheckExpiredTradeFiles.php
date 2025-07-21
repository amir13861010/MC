<?php

namespace App\Console\Commands;

use App\Models\Trade;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CheckExpiredTradeFiles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'trade:check-expired';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check and refresh expired trade files';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Checking for expired trade files...');

        // Get expired trades (older than 30 days or expired based on expires_at)
        $expiredTrades = Trade::where(function ($query) {
            $query->where('created_at', '<', now()->subDays(30))
                  ->orWhere('expires_at', '<', now());
        })->get();

        $refreshedCount = 0;

        foreach ($expiredTrades as $trade) {
            $fileAge = now()->diffInDays($trade->created_at);
            $this->info("Refreshing trade file for user: {$trade->user_id} (age: {$fileAge} days)");
            
            if ($this->refreshTradeFile($trade)) {
                $refreshedCount++;
            }
        }

        $this->info("Refreshed {$refreshedCount} trade files.");
        
        return 0;
    }

    /**
     * Refresh trade file for a specific user
     */
    private function refreshTradeFile($trade)
    {
        try {
            // تعیین level (می‌توانید از دیتابیس بگیرید یا ثابت بگذارید)
            $level = 1; // یا از جدول deposits بگیرید
            
            $url = 'https://mc-next-ten.vercel.app/api/trade';
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
                
                // حذف فایل قدیمی
                if (Storage::disk('local')->exists($trade->file_path)) {
                    Storage::disk('local')->delete($trade->file_path);
                }

                // ذخیره فایل جدید
                Storage::disk('local')->put($trade->file_path, json_encode($result, JSON_PRETTY_PRINT));

                // به‌روزرسانی فیلدهای trade
                $trade->expires_at = now()->addDays(30); // تمدید 30 روز
                $trade->is_active = true; // فعال کردن
                $trade->last_processed_at = null; // ریست کردن پردازش
                $trade->save();

                Log::info("Trade file refreshed successfully for user: {$trade->user_id}");
                return true;
            } else {
                Log::error("Trade API failed for user: {$trade->user_id}", [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return false;
            }
        } catch (\Exception $e) {
            Log::error("Exception in refresh trade file for user: {$trade->user_id}", [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
} 