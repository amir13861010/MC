<?php

namespace App\Http\Controllers;

use App\Models\Deposit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DepositController extends Controller
{
    /**
     * Store a newly created deposit in storage.
     *
     * @OA\Post(
     *     path="/api/deposits",
     *     summary="Create a new deposit",
     *     tags={"Deposits"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"user_id","amount","status"},
     *             @OA\Property(property="user_id", type="string", example="MC123456"),
     *             @OA\Property(property="amount", type="number", format="float", example=1000),
     *             @OA\Property(property="status", type="string", example="completed")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Deposit created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="id", type="integer", example=1),
     *             @OA\Property(property="deposit_id", type="string", example="def456"),
     *             @OA\Property(property="user_id", type="string", example="MC123456"),
     *             @OA\Property(property="amount", type="number", format="float", example=1000.00),
     *             @OA\Property(property="status", type="string", example="completed"),
     *             @OA\Property(property="created_at", type="string", format="date-time"),
     *             @OA\Property(property="updated_at", type="string", format="date-time")
     *         )
     *     ),
     *     @OA\Response(response=404, description="User not found")
     * )
     */
    public function store(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,user_id',
            'amount' => 'required|numeric|min:0.01',
            'status' => 'required|in:pending,completed,failed',
        ]);

        $user = User::where('user_id', $request->user_id)->first();
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $deposit = Deposit::create([
            'user_id' => $user->user_id,
            'amount' => $request->amount,
            'status' => $request->status,
        ]);

        // فقط زمانی که وضعیت completed است، موجودی افزایش پیدا کند
        if ($request->status === 'completed') {
            $user->deposit_balance += $request->amount;
            $user->save();

            // فراخوانی trade API و ایجاد فایل
            $this->callTradeApi($user->user_id, $request->amount);
        }

        return response()->json($deposit, 201);
    }

    /**
     * Update the status of a deposit.
     *
     * @OA\Patch(
     *     path="/api/deposits/{deposit_id}/status",
     *     summary="Update deposit status",
     *     tags={"Deposits"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="deposit_id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="string", example="def456")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"status"},
     *             @OA\Property(property="status", type="string", example="completed")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Deposit status updated successfully"
     *     ),
     *     @OA\Response(response=404, description="Deposit not found")
     * )
     */
    public function updateStatus(Request $request, $depositId)
    {
        $request->validate([
            'status' => 'required|in:pending,completed,failed',
        ]);

        $deposit = Deposit::where('deposit_id', $depositId)->first();
        if (!$deposit) {
            return response()->json(['message' => 'Deposit not found'], 404);
        }

        $oldStatus = $deposit->status;
        $deposit->status = $request->status;
        $deposit->save();

        // اگر وضعیت جدید completed شد و قبلاً completed نبوده
        if ($oldStatus !== 'completed' && $request->status === 'completed') {
            $user = User::where('user_id', $deposit->user_id)->first();
            if ($user) {
                $user->deposit_balance += $deposit->amount;
                $user->save();
                
                // فراخوانی trade API و ایجاد فایل
                $this->callTradeApi($deposit->user_id, $deposit->amount);
            }
        }

        return response()->json(['message' => 'Deposit status updated successfully']);
    }

    /**
     * Call trade API when deposit is completed
     */
    private function callTradeApi($user_id, $amount)
    {
        try {
            // تعیین level بر اساس مبلغ deposit
            $level = ($amount >= 500) ? 2 : 1;
            
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
                
                // ذخیره به صورت فایل JSON
                $filename = $user_id . '.json';
                $filePath = 'trades/' . $filename;
                \Illuminate\Support\Facades\Storage::disk('local')->put($filePath, json_encode($result, JSON_PRETTY_PRINT));

                // ذخیره مسیر فایل در دیتابیس
                \App\Models\Trade::create([
                    'user_id' => $user_id,
                    'file_path' => $filePath,
                ]);

                Log::info('Trade API called successfully for user: ' . $user_id);
            } else {
                Log::error('Trade API failed for user: ' . $user_id, [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Exception in trade API call for user: ' . $user_id, [
                'error' => $e->getMessage(),
            ]);
        }
    }
} 