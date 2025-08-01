<?php

namespace App\Http\Controllers;

use App\Models\Trade;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TradeController extends Controller
{
    /**
     * Send trade request to external API and store the result.
     *
     * @OA\Post(
     *     path="/api/trade",
     *     summary="Send trade request to external API and store result",
     *     tags={"Trade"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"user_id","year","month","level"},
     *             @OA\Property(property="user_id", type="string", example="MC123456"),
     *             @OA\Property(property="year", type="integer", example=2024),
     *             @OA\Property(property="month", type="integer", example=7),
     *             @OA\Property(property="level", type="integer", example=1)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Trade result stored successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="result", type="object"),
     *             @OA\Property(property="message", type="string")
     *         )
     *     ),
     *     @OA\Response(response=400, description="Invalid input or failed to fetch result"),
     *     @OA\Response(response=404, description="User not found")
     * )
     */
    public function trade(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,user_id',
            'year' => 'required|integer',
            'month' => 'required|integer',
            'level' => 'required|integer',
        ]);

        $user = User::where('user_id', $request->user_id)->first();
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $url = 'https://omegafocus.com/api/trade';
        $token = 'a16b76fdca144deda73730b4be61739e747cbf355f8e054cefbd57f0acb5cfa9';

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->post($url, [
            'year' => $request->year,
            'month' => $request->month,
            'level' => $request->level,
        ]);

        if ($response->failed()) {
            Log::error('Trade API failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'headers' => $response->headers(),
            ]);
            return response()->json(['message' => 'Failed to fetch trade result'], 400);
        }

        $result = $response->json();

        // ذخیره به صورت فایل JSON
        $filename = $request->user_id . '.json';
        $filePath = 'trades/' . $filename;
        Storage::disk('local')->put($filePath, json_encode($result, JSON_PRETTY_PRINT));

        // ذخیره مسیر فایل در دیتابیس
        $trade = Trade::create([
            'user_id' => $request->user_id,
            'file_path' => $filePath,
            'expires_at' => now()->addDays(30), // یک ماه اعتبار
            'is_active' => true,
        ]);

        return response()->json([
            'result' => $result,
            'message' => 'Trade result stored successfully',
        ]);
    }

    /**
     * Get trade result for a specific user.
     *
     * @OA\Get(
     *     path="/api/trade/{user_id}",
     *     summary="Get trade result for a specific user",
     *     tags={"Trade"},
     *     @OA\Parameter(
     *         name="user_id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="string", example="MC123456")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Trade result retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="result", type="object")
     *         )
     *     ),
     *     @OA\Response(response=404, description="Trade result not found")
     * )
     */
    public function getTradeResult($user_id)
    {
        $trade = Trade::where('user_id', $user_id)->first();
        
        if (!$trade) {
            return response()->json(['message' => 'Trade result not found'], 404);
        }

        if (!Storage::disk('local')->exists($trade->file_path)) {
            return response()->json(['message' => 'Trade file not found'], 404);
        }

        $jsonContent = Storage::disk('local')->get($trade->file_path);
        $result = json_decode($jsonContent, true);

        return response()->json([
            'result' => $result,
        ]);
    }

    /**
     * Get all active trades
     *
     * @OA\Get(
     *     path="/api/trades/active",
     *     summary="Get all active trades",
     *     tags={"Trade"},
     *     @OA\Response(
     *         response=200,
     *         description="Active trades retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="trades", type="array", @OA\Items(type="object"))
     *         )
     *     )
     * )
     */
    public function getActiveTrades()
    {
        $trades = Trade::active()->with('user')->get();
        
        return response()->json([
            'trades' => $trades->map(function ($trade) {
                return [
                    'user_id' => $trade->user_id,
                    'user_name' => $trade->user->name ?? 'Unknown',
                    'file_path' => $trade->file_path,
                    'is_active' => $trade->is_active,
                    'remaining_days' => $trade->getRemainingDays(),
                    'created_at' => $trade->created_at,
                    'expires_at' => $trade->expires_at,
                    'last_processed_at' => $trade->last_processed_at,
                ];
            })
        ]);
    }

    /**
     * Manually renew a trade for a specific user
     *
     * @OA\Post(
     *     path="/api/trade/{user_id}/renew",
     *     summary="Manually renew a trade for a specific user",
     *     tags={"Trade"},
     *     @OA\Parameter(
     *         name="user_id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="string", example="MC123456")
     *     ),
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             @OA\Property(property="year", type="integer", example=2024),
     *             @OA\Property(property="month", type="integer", example=7),
     *             @OA\Property(property="level", type="integer", example=1)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Trade renewed successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="user_id", type="string"),
     *                 @OA\Property(property="new_file_path", type="string"),
     *                 @OA\Property(property="new_expires_at", type="string"),
     *                 @OA\Property(property="year", type="integer"),
     *                 @OA\Property(property="month", type="integer")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=404, description="User or trade not found"),
     *     @OA\Response(response=400, description="Failed to renew trade")
     * )
     */
    public function renewTrade(Request $request, $user_id)
    {
        $trade = Trade::where('user_id', $user_id)->first();
        if (!$trade) {
            return response()->json(['message' => 'Trade not found'], 404);
        }

        $user = User::where('user_id', $user_id)->first();
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        // Get parameters or use current date
        $year = $request->input('year', now()->year);
        $month = $request->input('month', now()->month);
        $level = $request->input('level', 1);

        // Call external API to get new trade data
        $url = 'https://omegafocus.com/api/trade';
        $token = 'a16b76fdca144deda73730b4be61739e747cbf355f8e054cefbd57f0acb5cfa9';

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->post($url, [
            'year' => $year,
            'month' => $month,
            'level' => $level,
        ]);

        if ($response->failed()) {
            Log::error('Trade renewal API failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return response()->json(['message' => 'Failed to renew trade'], 400);
        }

        $result = $response->json();

        // Save new JSON file
        $filename = $user_id . '_' . now()->format('Y-m') . '.json';
        $filePath = 'trades/' . $filename;
        Storage::disk('local')->put($filePath, json_encode($result, JSON_PRETTY_PRINT));

        // Update trade record
        $trade->file_path = $filePath;
        $trade->expires_at = now()->addDays(30);
        $trade->is_active = true;
        $trade->last_processed_at = null; // Reset processing timestamp
        $trade->save();

        return response()->json([
            'message' => 'Trade renewed successfully',
            'data' => [
                'user_id' => $user_id,
                'new_file_path' => $filePath,
                'new_expires_at' => $trade->expires_at,
                'year' => $year,
                'month' => $month
            ]
        ]);
    }

    /**
     * Check if trade file is expired
     */
    public function checkExpiration($user_id)
    {
        $trade = Trade::where('user_id', $user_id)->first();
        
        if (!$trade) {
            return response()->json(['message' => 'Trade not found'], 404);
        }

        return response()->json([
            'user_id' => $user_id,
            'is_active' => $trade->isActive(),
            'is_expired' => $trade->isExpired(),
            'remaining_days' => $trade->getRemainingDays(),
            'created_at' => $trade->created_at,
            'expires_at' => $trade->expires_at,
            'last_processed_at' => $trade->last_processed_at,
        ]);
    }

    /**
     * Process daily profit for a specific user
     *
     * @OA\Post(
     *     path="/api/trade/{user_id}/process-daily-profit",
     *     summary="Process daily profit for a specific user",
     *     tags={"Trade"},
     *     @OA\Parameter(
     *         name="user_id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="string", example="MC123456")
     *     ),
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             @OA\Property(property="date", type="string", format="date", example="2025-01-15", description="Specific date to process (optional, defaults to today)")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Daily profit processed successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="user_id", type="string"),
     *                 @OA\Property(property="date", type="string"),
     *                 @OA\Property(property="daily_profit_percent", type="number"),
     *                 @OA\Property(property="deposit_balance", type="number"),
     *                 @OA\Property(property="daily_profit_amount", type="number"),
     *                 @OA\Property(property="new_gain_profit", type="number")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=404, description="User or trade not found"),
     *     @OA\Response(response=400, description="No daily report found for the specified date")
     * )
     */
    public function processDailyProfit(Request $request, $user_id)
    {
        $date = $request->input('date', now()->format('Y-m-d'));
        
        // Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return response()->json(['message' => 'Invalid date format. Use Y-m-d format'], 400);
        }

        $trade = Trade::where('user_id', $user_id)->first();
        if (!$trade) {
            return response()->json(['message' => 'Trade not found'], 404);
        }

        $user = User::where('user_id', $user_id)->first();
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        // Check if trade file exists
        if (!Storage::disk('local')->exists($trade->file_path)) {
            return response()->json(['message' => 'Trade file not found'], 404);
        }

        // Read and parse JSON file
        $jsonContent = Storage::disk('local')->get($trade->file_path);
        $tradeData = json_decode($jsonContent, true);

        if (!$tradeData || !isset($tradeData['data']['dailyReports'])) {
            return response()->json(['message' => 'Invalid trade data format'], 400);
        }

        // Find the specified date's daily report
        $dailyReport = null;
        foreach ($tradeData['data']['dailyReports'] as $report) {
            if ($report['date'] === $date) {
                $dailyReport = $report;
                break;
            }
        }

        if (!$dailyReport) {
            return response()->json(['message' => "No daily report found for date {$date}"], 400);
        }

        // Calculate daily profit
        $dailyProfitPercent = floatval($dailyReport['dailyProfit']);
        $depositBalance = floatval($user->deposit_balance);
        $dailyProfitAmount = $depositBalance * ($dailyProfitPercent / 100);

        // Update user's gain_profit
        $user->gain_profit += $dailyProfitAmount;
        $user->save();

        return response()->json([
            'message' => 'Daily profit processed successfully',
            'data' => [
                'user_id' => $user_id,
                'date' => $date,
                'daily_profit_percent' => $dailyProfitPercent,
                'deposit_balance' => $depositBalance,
                'daily_profit_amount' => $dailyProfitAmount,
                'new_gain_profit' => $user->gain_profit
            ]
        ]);
    }
} 