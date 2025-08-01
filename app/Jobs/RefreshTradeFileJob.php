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

class RefreshTradeFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $user_id;
    protected $amount;

    /**
     * Create a new job instance.
     */
    public function __construct($user_id, $amount)
    {
        $this->user_id = $user_id;
        $this->amount = $amount;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            // تعیین level بر اساس مبلغ deposit
            $level = ($this->amount >= 500) ? 2 : 1;
            
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
                
                // حذف فایل قدیمی
                $oldTrade = Trade::where('user_id', $this->user_id)->first();
                if ($oldTrade && Storage::disk('local')->exists($oldTrade->file_path)) {
                    Storage::disk('local')->delete($oldTrade->file_path);
                }

                // ذخیره فایل جدید
                $filename = $this->user_id . '.json';
                $filePath = 'trades/' . $filename;
                Storage::disk('local')->put($filePath, json_encode($result, JSON_PRETTY_PRINT));

                // به‌روزرسانی یا ایجاد رکورد جدید در دیتابیس
                Trade::updateOrCreate(
                    ['user_id' => $this->user_id],
                    ['file_path' => $filePath]
                );

                // برنامه‌ریزی برای 30 روز بعد
                RefreshTradeFileJob::dispatch($this->user_id, $this->amount)
                    ->delay(now()->addDays(30));

                Log::info('Trade file refreshed successfully for user: ' . $this->user_id);
            } else {
                Log::error('Trade API failed for user: ' . $this->user_id, [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Exception in refresh trade file job for user: ' . $this->user_id, [
                'error' => $e->getMessage(),
            ]);
        }
    }
} 