<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Transfer;
use App\Models\CapitalHistory;
use App\Models\UserHierarchyHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/sub-users-capital-daily/{userId}",
     *     summary="Get daily capital report for sub-users",
     *     description="Returns a daily report of capital, bonus, sub-users count, and new users in the last 24 hours for a user's referrals.",
     *     operationId="getSubUsersCapitalDaily",
     *     tags={"Users"},
     *     @OA\Parameter(
     *         name="userId",
     *         in="path",
     *         description="ID of the user to get the sub-users capital report for",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful response with daily capital report",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(
     *                 @OA\Property(property="date", type="string", format="date", example="2025-01-01"),
     *                 @OA\Property(property="total_sub_users_capital", type="number", format="float", example=50.00),
     *                 @OA\Property(property="bonus_5_percent", type="number", format="float", example=2.50),
     *                 @OA\Property(property="sub_users_count", type="integer", example=5),
     *                 @OA\Property(property="new_users_24h", type="integer", example=0)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="User not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="User not found")
     *         )
     *     )
     * )
     */
    public function getSubUsersCapitalDaily($userId)
    {
        $user = User::where('user_id', $userId)->first();
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        // Recursive function to get all sub-users
        $getAllSubUsers = function($user) use (&$getAllSubUsers) {
            $subs = [];
            foreach ($user->referrals as $ref) {
                $subs[] = $ref;
                $subs = array_merge($subs, $getAllSubUsers($ref));
            }
            return $subs;
        };

        $subUsers = $getAllSubUsers($user);
        $subUserIds = array_column($subUsers, 'user_id');

        // Get capital history for sub-users, grouped by date
        $capitalHistory = CapitalHistory::whereIn('user_id', $subUserIds)
            ->select('date', 'user_id', 'capital_profit')
            ->get()
            ->groupBy('date');

        // Prepare response
        $response = [];
        foreach ($capitalHistory as $date => $records) {
            $totalCapital = 0;
            $subUsersOnDate = [];
            $newUsers24h = 0;

            foreach ($records as $record) {
                $totalCapital += $record->capital_profit;
                $subUsersOnDate[] = $record->user_id;

                // Check if user is new on this date
                $subUser = collect($subUsers)->firstWhere('user_id', $record->user_id);
                if ($subUser && $subUser->created_at->startOfDay()->format('Y-m-d') === $date) {
                    $newUsers24h++;
                }
            }

            $response[] = [
                'date' => $date,
                'total_sub_users_capital' => $totalCapital,
                'bonus_5_percent' => $totalCapital * 0.05,
                'sub_users_count' => count(array_unique($subUsersOnDate)),
                'new_users_24h' => $newUsers24h,
            ];
        }

        // Sort by date
        usort($response, function ($a, $b) {
            return strtotime($a['date']) - strtotime($b['date']);
        });

        return response()->json($response);
    }
    /**
     * @OA\Post(
     *     path="/api/users/transfer-capital-to-gain",
     *     summary="Transfer from capital_profit to gain_profit",
     *     tags={"User"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"user_id","amount"},
     *             @OA\Property(property="user_id", type="string", example="MC123456"),
     *             @OA\Property(property="amount", type="number", format="float", example=100)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Transfer successful",
     *         @OA\JsonContent(
     *             @OA\Property(property="capital_profit", type="number"),
     *             @OA\Property(property="gain_profit", type="number"),
     *             @OA\Property(property="message", type="string", example="Transfer successful"),
     *             @OA\Property(
     *                 property="transfer",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="transfer_id", type="string", example="xyz789"),
     *                 @OA\Property(property="from_user_id", type="string", example="MC123456"),
     *                 @OA\Property(property="to_user_id", type="string", nullable=true),
     *                 @OA\Property(property="amount", type="number", example=100.00),
     *                 @OA\Property(property="from_account", type="string", example="capital_profit"),
     *                 @OA\Property(property="to_account", type="string", example="gain_profit"),
     *                 @OA\Property(property="transfer_type", type="string", example="internal"),
     *                 @OA\Property(property="description", type="string", example="Transfer from capital_profit to gain_profit"),
     *                 @OA\Property(property="created_at", type="string", format="date-time")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=400, description="Insufficient capital_profit or invalid input"),
     *     @OA\Response(response=404, description="User not found")
     * )
     */
    public function transferCapitalToGain(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,user_id',
            'amount' => 'required|numeric|min:1',
        ]);

        $user = User::where('user_id', $request->user_id)->first();
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        if ($user->capital_profit < $request->amount) {
            return response()->json(['message' => 'Insufficient capital_profit'], 400);
        }

        // Start database transaction
        DB::beginTransaction();
        
        try {
            // Update user balances
            $user->capital_profit -= $request->amount;
            $user->gain_profit += $request->amount;
            $user->save();

            // Save transfer record
            $transfer = Transfer::create([
                'from_user_id' => $user->user_id,
                'to_user_id' => null, // Internal transfer
                'amount' => $request->amount,
                'from_account' => 'capital_profit',
                'to_account' => 'gain_profit',
                'transfer_type' => 'internal',
                'description' => 'Transfer from capital_profit to gain_profit'
            ]);

            DB::commit();

            return response()->json([
                'capital_profit' => $user->capital_profit,
                'gain_profit' => $user->gain_profit,
                'message' => 'Transfer successful',
                'transfer' => $transfer
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['message' => 'Transfer failed'], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/users/transfer-to-user",
     *     summary="Transfer from your capital_profit or gain_profit to another user's same account",
     *     tags={"User"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"from_user_id","to_user_id","amount","from_account"},
     *             @OA\Property(property="from_user_id", type="string", example="MC123456"),
     *             @OA\Property(property="to_user_id", type="string", example="MC654321"),
     *             @OA\Property(property="amount", type="number", format="float", example=100),
     *             @OA\Property(property="from_account", type="string", enum={"capital_profit","gain_profit"}, example="capital_profit")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Transfer successful",
     *         @OA\JsonContent(
     *             @OA\Property(property="from_user", type="object",
     *                 @OA\Property(property="user_id", type="string"),
     *                 @OA\Property(property="capital_profit", type="number"),
     *                 @OA\Property(property="gain_profit", type="number")
     *             ),
     *             @OA\Property(property="to_user", type="object",
     *                 @OA\Property(property="user_id", type="string"),
     *                 @OA\Property(property="capital_profit", type="number"),
     *                 @OA\Property(property="gain_profit", type="number")
     *             ),
     *             @OA\Property(property="message", type="string")
     *         )
     *     ),
     *     @OA\Response(response=400, description="Insufficient balance or invalid input"),
     *     @OA\Response(response=404, description="User not found")
     * )
     */
    public function transferToUser(Request $request)
    {
        $request->validate([
            'from_user_id' => 'required|exists:users,user_id',
            'to_user_id' => 'required|exists:users,user_id|different:from_user_id',
            'amount' => 'required|numeric|min:1',
            'from_account' => 'required|in:capital_profit,gain_profit',
        ]);

        $fromUser = User::where('user_id', $request->from_user_id)->first();
        $toUser = User::where('user_id', $request->to_user_id)->first();

        if (!$fromUser || !$toUser) {
            return response()->json(['message' => 'User not found'], 404);
        }

        if ($fromUser->{$request->from_account} < $request->amount) {
            return response()->json(['message' => 'Insufficient balance'], 400);
        }

        // Start database transaction
        DB::beginTransaction();
        
        try {
            // انتقال مبلغ
            $fromUser->{$request->from_account} -= $request->amount;
            $toUser->{$request->from_account} += $request->amount;

            $fromUser->save();
            $toUser->save();

            // Save transfer record
            $transfer = Transfer::create([
                'from_user_id' => $fromUser->user_id,
                'to_user_id' => $toUser->user_id,
                'amount' => $request->amount,
                'from_account' => $request->from_account,
                'to_account' => $request->from_account, // Same account type
                'transfer_type' => 'external',
                'description' => "Transfer from {$request->from_account} of user {$fromUser->user_id} to user {$toUser->user_id}"
            ]);

            DB::commit();

            return response()->json([
                'from_user' => [
                    'user_id' => $fromUser->user_id,
                    'capital_profit' => $fromUser->capital_profit,
                    'gain_profit' => $fromUser->gain_profit,
                ],
                'to_user' => [
                    'user_id' => $toUser->user_id,
                    'capital_profit' => $toUser->capital_profit,
                    'gain_profit' => $toUser->gain_profit,
                ],
                'message' => 'Transfer successful',
                'transfer' => $transfer
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['message' => 'Transfer failed'], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/users/profile",
     *     summary="Get user profile information",
     *     tags={"User"},
     *     @OA\Parameter(
     *         name="user_id",
     *         in="query",
     *         required=true,
     *         description="User ID to get profile for",
     *         @OA\Schema(type="string", example="MC123456")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="User profile retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="user_id", type="string"),
     *             @OA\Property(property="email", type="string"),
     *             @OA\Property(property="registration_date", type="string", format="date-time"),
     *             @OA\Property(property="gain_profit", type="number", format="float"),
     *             @OA\Property(property="capital_profit", type="number", format="float"),
     *             @OA\Property(property="deposit_balance", type="number", format="float"),
     *             @OA\Property(property="level", type="integer", example=0)
     *         )
     *     ),
     *     @OA\Response(response=404, description="User not found")
     * )
     */
    public function profile(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,user_id',
        ]);

        $user = User::where('user_id', $request->user_id)->first();
        
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $btcWallet = \App\Models\UserBtcWallet::where('user_id', $user->user_id)->first();
        $tronWallet = \App\Models\UserTronWallet::where('user_id', $user->user_id)->first();

        return response()->json([
            'user_id' => $user->user_id,
            'email' => $user->email,
            'registration_date' => $user->created_at,
            'gain_profit' => $user->gain_profit,
            'capital_profit' => $user->capital_profit,
            'deposit_balance' => $user->deposit_balance,
            'level' => 0, // Currently fixed at 0 for all users
            'authenticator_active' => (bool) $user->two_factor_enabled,
            'two_factor_secret' => $user->two_factor_secret,
            'suspend' => (bool) $user->suspend,
            'btc_wallet' => $btcWallet ? $btcWallet->btc_wallet : null,
            'tron_wallet' => $tronWallet ? [
                'address' => $tronWallet->address,
                'private_key' => $tronWallet->private_key,
                'hex_address' => $tronWallet->hex_address
            ] : null
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/users/{user_id}/sub-users-capital",
     *     summary="Get total active capital of all sub-users recursively, the 5% bonus value, and sub-users list",
     *     tags={"User"},
     *     @OA\Parameter(
     *         name="user_id",
     *         in="path",
     *         required=true,
     *         description="User ID to calculate sub-users' capital for",
     *         @OA\Schema(type="string", example="MC123456")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Total sub-users capital, 5% bonus value, and sub-users list",
     *         @OA\JsonContent(
     *             @OA\Property(property="user_id", type="string"),
     *             @OA\Property(property="total_sub_users_capital", type="number", format="float"),
     *             @OA\Property(property="bonus_5_percent", type="number", format="float"),
     *             @OA\Property(property="sub_users_count", type="integer"),
     *             @OA\Property(property="new_users_24h", type="integer"),
     *             @OA\Property(property="sub_users", type="array", @OA\Items(
     *                 @OA\Property(property="user_id", type="string"),
     *                 @OA\Property(property="name", type="string"),
     *                 @OA\Property(property="email", type="string"),
     *                 @OA\Property(property="registration_date", type="string", format="date-time"),
     *                 @OA\Property(property="active_capital", type="number", format="float"),
     *                 @OA\Property(property="is_new_24h", type="boolean")
     *             ))
     *         )
     *     ),
     *     @OA\Response(response=404, description="User not found")
     * )
     */
    public function getSubUsersCapital($userId)
    {
        $user = User::where('user_id', $userId)->first();
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        // Recursive function to get all sub-users
        $getAllSubUsers = function($user) use (&$getAllSubUsers) {
            $subs = [];
            foreach ($user->referrals as $ref) {
                $subs[] = $ref;
                $subs = array_merge($subs, $getAllSubUsers($ref));
            }
            return $subs;
        };

        $subUsers = $getAllSubUsers($user);
        $twentyFourHoursAgo = now()->subHours(24);

        // Prepare sub-users data with capital and new user status
        $subUsersData = [];
        $totalCapital = 0;
        $newUsers24h = 0;

        foreach ($subUsers as $sub) {
            $capital = $sub->capital_profit;
            $totalCapital += $capital;

            $isNew24h = $sub->created_at >= $twentyFourHoursAgo;
            if ($isNew24h) {
                $newUsers24h++;
            }

            $subUsersData[] = [
                'user_id' => $sub->user_id,
                'name' => $sub->first_name . ' ' . $sub->last_name,
                'email' => $sub->email,
                'registration_date' => $sub->created_at,
                'active_capital' => $capital,
                'is_new_24h' => $isNew24h
            ];
        }

        $bonus = $totalCapital * 0.05;

        return response()->json([
            'user_id' => $userId,
            'total_sub_users_capital' => $totalCapital,
            'bonus_5_percent' => $bonus,
            'sub_users_count' => count($subUsers),
            'new_users_24h' => $newUsers24h,
            'sub_users' => $subUsersData
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/users/btc-wallet",
     *     summary="Add or update user's BTC wallet address",
     *     tags={"User"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"user_id","btc_wallet"},
     *             @OA\Property(property="user_id", type="string", example="MC123456"),
     *             @OA\Property(property="btc_wallet", type="string", example="bc1q...abc")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="BTC wallet saved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="user_id", type="string"),
     *             @OA\Property(property="btc_wallet", type="string"),
     *             @OA\Property(property="message", type="string")
     *         )
     *     ),
     *     @OA\Response(response=404, description="User not found")
     * )
     */
    public function saveBtcWallet(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,user_id',
            'btc_wallet' => 'required|string',
        ]);

        $user = User::where('user_id', $request->user_id)->first();
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $btcWallet = \App\Models\UserBtcWallet::updateOrCreate(
            ['user_id' => $request->user_id],
            ['btc_wallet' => $request->btc_wallet]
        );

        return response()->json([
            'user_id' => $btcWallet->user_id,
            'btc_wallet' => $btcWallet->btc_wallet,
            'message' => 'BTC wallet saved successfully'
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/users/transfers",
     *     summary="Get user transfer history",
     *     tags={"User"},
     *     @OA\Parameter(
     *         name="user_id",
     *         in="query",
     *         required=true,
     *         description="User ID to get transfers for",
     *         @OA\Schema(type="string", example="MC123456")
     *     ),
     *     @OA\Parameter(
     *         name="type",
     *         in="query",
     *         required=false,
     *         description="Filter by transfer type (internal/external)",
     *         @OA\Schema(type="string", enum={"internal", "external"})
     *     ),
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         required=false,
     *         description="Number of records to return",
     *         @OA\Schema(type="integer", default=20)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Transfer history retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="transfers", type="array", @OA\Items(
     *                 @OA\Property(property="id", type="integer"),
     *                 @OA\Property(property="from_user_id", type="string"),
     *                 @OA\Property(property="to_user_id", type="string", nullable=true),
     *                 @OA\Property(property="amount", type="number"),
     *                 @OA\Property(property="from_account", type="string"),
     *                 @OA\Property(property="to_account", type="string"),
     *                 @OA\Property(property="transfer_type", type="string"),
     *                 @OA\Property(property="description", type="string"),
     *                 @OA\Property(property="created_at", type="string", format="date-time")
     *             )),
     *             @OA\Property(property="total", type="integer")
     *         )
     *     ),
     *     @OA\Response(response=404, description="User not found")
     * )
     */
    public function getTransfers(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,user_id',
            'type' => 'nullable|in:internal,external',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $user = User::where('user_id', $request->user_id)->first();
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $query = Transfer::where(function($q) use ($request) {
            $q->where('from_user_id', $request->user_id)
              ->orWhere('to_user_id', $request->user_id);
        })->with(['fromUser', 'toUser']);

        // Filter by type if provided
        if ($request->type) {
            $query->where('transfer_type', $request->type);
        }

        $limit = $request->limit ?? 20;
        $transfers = $query->orderBy('created_at', 'desc')
                          ->limit($limit)
                          ->get();

        return response()->json([
            'transfers' => $transfers,
            'total' => $transfers->count()
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/users/tron-wallet",
     *     summary="Add or update user's TRON wallet",
     *     tags={"User"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"user_id","address","hex_address","private_key"},
     *             @OA\Property(property="user_id", type="string", example="MC123456"),
     *             @OA\Property(property="address", type="string", example="T..."),
     *             @OA\Property(property="hex_address", type="string", example="41..."),
     *             @OA\Property(property="private_key", type="string", example="...")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="TRON wallet saved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="user_id", type="string"),
     *             @OA\Property(property="address", type="string"),
     *             @OA\Property(property="hex_address", type="string"),
     *             @OA\Property(property="message", type="string")
     *         )
     *     ),
     *     @OA\Response(response=404, description="User not found")
     * )
     */
    public function saveTronWallet(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,user_id',
            'address' => 'required|string',
            'hex_address' => 'required|string',
            'private_key' => 'required|string',
        ]);

        $user = User::where('user_id', $request->user_id)->first();
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $tronWallet = \App\Models\UserTronWallet::updateOrCreate(
            ['user_id' => $request->user_id],
            [
                'address' => $request->address,
                'hex_address' => $request->hex_address,
                'private_key' => $request->private_key,
            ]
        );

        return response()->json([
            'user_id' => $tronWallet->user_id,
            'address' => $tronWallet->address,
            'hex_address' => $tronWallet->hex_address,
            'message' => 'TRON wallet saved successfully'
        ]);
    }

  /**
 * @OA\Get(
 *     path="/api/users/daily-registrations",
 *     summary="Get daily user registration statistics for system growth tracking",
 *     tags={"User"},
 *     @OA\Parameter(
 *         name="start_date",
 *         in="query",
 *         required=false,
 *         description="Start date for statistics (YYYY-MM-DD format). If not provided, uses the first user's registration date.",
 *         @OA\Schema(type="string", format="date", example="2024-01-01")
 *     ),
 *     @OA\Parameter(
 *         name="end_date",
 *         in="query",
 *         required=false,
 *         description="End date for statistics (YYYY-MM-DD format). If not provided, uses current date.",
 *         @OA\Schema(type="string", format="date", example="2024-12-31")
 *     ),
 *     @OA\Parameter(
 *         name="limit",
 *         in="query",
 *         required=false,
 *         description="Number of days to return (default: 30, ignored if start_date is provided)",
 *         @OA\Schema(type="integer", default=30)
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Daily registration statistics retrieved successfully",
 *         @OA\JsonContent(
 *             @OA\Property(property="statistics", type="array", @OA\Items(
 *                 @OA\Property(property="date", type="string", format="date", example="2024-01-01"),
 *                 @OA\Property(property="registrations", type="integer", example=15),
 *                 @OA\Property(property="cumulative_total", type="integer", example=1250)
 *             )),
 *             @OA\Property(property="summary", type="object",
 *                 @OA\Property(property="total_registrations", type="integer", example=450),
 *                 @OA\Property(property="average_daily", type="number", format="float", example=15.0),
 *                 @OA\Property(property="peak_day", type="object",
 *                     @OA\Property(property="date", type="string", format="date"),
 *                     @OA\Property(property="registrations", type="integer")
 *                 ),
 *                 @OA\Property(property="growth_rate", type="number", format="float", example=12.5)
 *             ),
 *             @OA\Property(property="period", type="object",
 *                 @OA\Property(property="start_date", type="string", format="date"),
 *                 @OA\Property(property="end_date", type="string", format="date"),
 *                 @OA\Property(property="days_count", type="integer")
 *             )
 *         )
 *     ),
 *     @OA\Response(response=400, description="Invalid date format")
 * )
 */
public function getDailyRegistrations(Request $request)
{
    $request->validate([
        'start_date' => 'nullable|date_format:Y-m-d',
        'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
        'limit' => 'nullable|integer|min:1|max:365',
    ]);

    $limit = $request->limit ?? 30;

    // تعیین تاریخ شروع و پایان
    if (!$request->start_date && !$request->end_date) {
        // اگر هیچ تاریخی وارد نشده، از اولین کاربر تا امروز
        $firstUserDate = User::min('created_at'); // تاریخ اولین کاربر
        $startDate = $firstUserDate ? date('Y-m-d', strtotime($firstUserDate)) : now()->format('Y-m-d');
        $endDate = now()->format('Y-m-d');
    } elseif (!$request->start_date) {
        // اگر فقط start_date وارد نشده، از اولین کاربر تا end_date
        $firstUserDate = User::min('created_at');
        $startDate = $firstUserDate ? date('Y-m-d', strtotime($firstUserDate)) : now()->subDays($limit - 1)->format('Y-m-d');
        $endDate = $request->end_date ?? now()->format('Y-m-d');
    } else {
        // اگر start_date وارد شده، از آن استفاده کن
        $startDate = $request->start_date;
        $endDate = $request->end_date ?? now()->format('Y-m-d');
    }

    // دریافت آمار روزانه
    $dailyStats = User::selectRaw('
            DATE(created_at) as date,
            COUNT(*) as registrations
        ')
        ->whereBetween(DB::raw('DATE(created_at)'), [$startDate, $endDate])
        ->groupBy(DB::raw('DATE(created_at)'))
        ->orderBy('date')
        ->get()
        ->keyBy('date');

    // پر کردن تاریخ‌های بدون ثبت‌نام با صفر
    $statistics = [];
    $currentDate = $startDate;
    $cumulativeTotal = User::where('created_at', '<', $startDate)->count();
    $peakDay = ['date' => null, 'registrations' => 0];
    $totalRegistrations = 0;

    while ($currentDate <= $endDate) {
        $dailyCount = $dailyStats->get($currentDate);
        $registrations = $dailyCount ? $dailyCount->registrations : 0;
        $cumulativeTotal += $registrations;
        $totalRegistrations += $registrations;

        // ردیابی روز اوج
        if ($registrations > $peakDay['registrations']) {
            $peakDay = ['date' => $currentDate, 'registrations' => $registrations];
        }

        $statistics[] = [
            'date' => $currentDate,
            'registrations' => $registrations,
            'cumulative_total' => $cumulativeTotal
        ];

        $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
    }

    // محاسبه نرخ رشد (مقایسه هفته اول و آخر)
    $growthRate = 0;
    if (count($statistics) >= 14) {
        $firstWeek = array_slice($statistics, 0, 7);
        $lastWeek = array_slice($statistics, -7);
        
        $firstWeekTotal = array_sum(array_column($firstWeek, 'registrations'));
        $lastWeekTotal = array_sum(array_column($lastWeek, 'registrations'));
        
        if ($firstWeekTotal > 0) {
            $growthRate = (($lastWeekTotal - $firstWeekTotal) / $firstWeekTotal) * 100;
        }
    }

    $daysCount = count($statistics);
    $averageDaily = $daysCount > 0 ? round($totalRegistrations / $daysCount, 2) : 0;

    return response()->json([
        'statistics' => $statistics,
        'summary' => [
            'total_registrations' => $totalRegistrations,
            'average_daily' => $averageDaily,
            'peak_day' => $peakDay,
            'growth_rate' => round($growthRate, 2)
        ],
        'period' => [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'days_count' => $daysCount
        ]
    ]);
}

    /**
     * @OA\Get(
     *     path="/api/users/{user_id}/hierarchy-history",
     *     summary="Get user's hierarchy history (when they became subordinates)",
     *     tags={"User"},
     *     @OA\Parameter(
     *         name="user_id",
     *         in="path",
     *         required=true,
     *         description="User ID to get hierarchy history for",
     *         @OA\Schema(type="string", example="MC123456")
     *     ),
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         required=false,
     *         description="Filter by status (active/inactive/all)",
     *         @OA\Schema(type="string", enum={"active", "inactive", "all"}, default="all")
     *     ),
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         required=false,
     *         description="Number of records to return",
     *         @OA\Schema(type="integer", default=20)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="User hierarchy history retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="user_id", type="string"),
     *             @OA\Property(property="history", type="array", @OA\Items(
     *                 @OA\Property(property="id", type="integer"),
     *                 @OA\Property(property="parent_user_id", type="string"),
     *                 @OA\Property(property="parent_name", type="string"),
     *                 @OA\Property(property="joined_at", type="string", format="date-time"),
     *                 @OA\Property(property="left_at", type="string", format="date-time", nullable=true),
     *                 @OA\Property(property="notes", type="string", nullable=true),
     *                 @OA\Property(property="duration_days", type="integer", nullable=true)
     *             )),
     *             @OA\Property(property="total", type="integer")
     *         )
     *     ),
     *     @OA\Response(response=404, description="User not found")
     * )
     */
    public function getHierarchyHistory(Request $request, $userId)
    {
        $request->validate([
            'status' => 'nullable|in:active,inactive,all',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $user = User::where('user_id', $userId)->first();
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $query = UserHierarchyHistory::where('user_id', $userId)
            ->with('parentUser:id,user_id,name,first_name,last_name');

        // Filter by status
        switch ($request->status) {
            case 'active':
                $query->active();
                break;
            case 'inactive':
                $query->inactive();
                break;
            // 'all' is default, no filter needed
        }

        $limit = $request->limit ?? 20;
        $history = $query->orderBy('joined_at', 'desc')
                        ->limit($limit)
                        ->get()
                        ->map(function($record) {
                            $durationDays = null;
                            if ($record->left_at) {
                                $durationDays = $record->joined_at->diffInDays($record->left_at);
                            } elseif ($record->joined_at) {
                                $durationDays = $record->joined_at->diffInDays(now());
                            }

                            return [
                                'id' => $record->id,
                                'parent_user_id' => $record->parent_user_id,
                                'parent_name' => $record->parentUser ? 
                                    ($record->parentUser->first_name . ' ' . $record->parentUser->last_name) : 
                                    'Unknown User',
                                'joined_at' => $record->joined_at,
                                'left_at' => $record->left_at,
                                'notes' => $record->notes,
                                'duration_days' => $durationDays
                            ];
                        });

        return response()->json([
            'user_id' => $userId,
            'history' => $history,
            'total' => $history->count()
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/users/{user_id}/subordinates-history",
     *     summary="Get user's subordinates history (when users became their subordinates)",
     *     tags={"User"},
     *     @OA\Parameter(
     *         name="user_id",
     *         in="path",
     *         required=true,
     *         description="User ID to get subordinates history for",
     *         @OA\Schema(type="string", example="MC123456")
     *     ),
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         required=false,
     *         description="Filter by status (active/inactive/all)",
     *         @OA\Schema(type="string", enum={"active", "inactive", "all"}, default="all")
     *     ),
     *     @OA\Parameter(
     *         name="start_date",
     *         in="query",
     *         required=false,
     *         description="Start date for filtering (YYYY-MM-DD format)",
     *         @OA\Schema(type="string", format="date", example="2024-01-01")
     *     ),
     *     @OA\Parameter(
     *         name="end_date",
     *         in="query",
     *         required=false,
     *         description="End date for filtering (YYYY-MM-DD format)",
     *         @OA\Schema(type="string", format="date", example="2024-12-31")
     *     ),
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         required=false,
     *         description="Number of records to return",
     *         @OA\Schema(type="integer", default=20)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="User subordinates history retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="user_id", type="string"),
     *             @OA\Property(property="subordinates", type="array", @OA\Items(
     *                 @OA\Property(property="id", type="integer"),
     *                 @OA\Property(property="subordinate_user_id", type="string"),
     *                 @OA\Property(property="subordinate_name", type="string"),
     *                 @OA\Property(property="subordinate_email", type="string"),
     *                 @OA\Property(property="joined_at", type="string", format="date-time"),
     *                 @OA\Property(property="left_at", type="string", format="date-time", nullable=true),
     *                 @OA\Property(property="notes", type="string", nullable=true),
     *                 @OA\Property(property="duration_days", type="integer", nullable=true),
     *                 @OA\Property(property="status", type="string", enum={"active", "inactive"})
     *             )),
     *             @OA\Property(property="summary", type="object",
     *                 @OA\Property(property="total_subordinates", type="integer"),
     *                 @OA\Property(property="active_subordinates", type="integer"),
     *                 @OA\Property(property="inactive_subordinates", type="integer")
     *             ),
     *             @OA\Property(property="total", type="integer")
     *         )
     *     ),
     *     @OA\Response(response=404, description="User not found")
     * )
     */
    public function getSubordinatesHistory(Request $request, $userId)
    {
        $request->validate([
            'status' => 'nullable|in:active,inactive,all',
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $user = User::where('user_id', $userId)->first();
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $query = UserHierarchyHistory::where('parent_user_id', $userId)
            ->with('user:id,user_id,name,first_name,last_name,email');

        // Filter by status
        switch ($request->status) {
            case 'active':
                $query->active();
                break;
            case 'inactive':
                $query->inactive();
                break;
            // 'all' is default, no filter needed
        }

        // Filter by date range
        if ($request->start_date && $request->end_date) {
            $query->inDateRange($request->start_date, $request->end_date);
        }

        $limit = $request->limit ?? 20;
        $subordinates = $query->orderBy('joined_at', 'desc')
                             ->limit($limit)
                             ->get()
                             ->map(function($record) {
                                 $durationDays = null;
                                 if ($record->left_at) {
                                     $durationDays = $record->joined_at->diffInDays($record->left_at);
                                 } elseif ($record->joined_at) {
                                     $durationDays = $record->joined_at->diffInDays(now());
                                 }

                                 return [
                                     'id' => $record->id,
                                     'subordinate_user_id' => $record->user_id,
                                     'subordinate_name' => $record->user ? 
                                         ($record->user->first_name . ' ' . $record->user->last_name) : 
                                         'Unknown User',
                                     'subordinate_email' => $record->user ? $record->user->email : 'N/A',
                                     'joined_at' => $record->joined_at,
                                     'left_at' => $record->left_at,
                                     'notes' => $record->notes,
                                     'duration_days' => $durationDays,
                                     'status' => $record->left_at ? 'inactive' : 'active'
                                 ];
                             });

        // Calculate summary statistics
        $totalSubordinates = UserHierarchyHistory::where('parent_user_id', $userId)->count();
        $activeSubordinates = UserHierarchyHistory::where('parent_user_id', $userId)->active()->count();
        $inactiveSubordinates = $totalSubordinates - $activeSubordinates;

        return response()->json([
            'user_id' => $userId,
            'subordinates' => $subordinates,
            'summary' => [
                'total_subordinates' => $totalSubordinates,
                'active_subordinates' => $activeSubordinates,
                'inactive_subordinates' => $inactiveSubordinates
            ],
            'total' => $subordinates->count()
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/users/{user_id}/change-parent",
     *     summary="Change user's parent (move to different hierarchy)",
     *     tags={"User"},
     *     @OA\Parameter(
     *         name="user_id",
     *         in="path",
     *         required=true,
     *         description="User ID to change parent for",
     *         @OA\Schema(type="string", example="MC123456")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"new_parent_user_id"},
     *             @OA\Property(property="new_parent_user_id", type="string", example="MC654321"),
     *             @OA\Property(property="notes", type="string", example="User transferred to new parent")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="User parent changed successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="old_parent_user_id", type="string", nullable=true),
     *             @OA\Property(property="new_parent_user_id", type="string"),
     *             @OA\Property(property="changed_at", type="string", format="date-time")
     *         )
     *     ),
     *     @OA\Response(response=400, description="Invalid parent or user not found"),
     *     @OA\Response(response=404, description="User not found")
     * )
     */
    public function changeParent(Request $request, $userId)
    {
        $request->validate([
            'new_parent_user_id' => 'required|exists:users,user_id|different:user_id',
            'notes' => 'nullable|string|max:500',
        ]);

        $user = User::where('user_id', $userId)->first();
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $newParent = User::where('user_id', $request->new_parent_user_id)->first();
        if (!$newParent) {
            return response()->json(['message' => 'New parent user not found'], 404);
        }

        // Check if new parent already has 3 active subordinates
        $activeSubordinatesCount = UserHierarchyHistory::where('parent_user_id', $request->new_parent_user_id)
            ->active()
            ->count();
        
        if ($activeSubordinatesCount >= 3) {
            return response()->json(['message' => 'New parent already has maximum number of subordinates (3)'], 400);
        }

        // Start database transaction
        DB::beginTransaction();
        
        try {
            $oldParentUserId = $user->friend_id;

            // Close current active hierarchy relationship
            if ($oldParentUserId) {
                UserHierarchyHistory::where('user_id', $userId)
                    ->where('parent_user_id', $oldParentUserId)
                    ->whereNull('left_at')
                    ->update([
                        'left_at' => now(),
                        'notes' => $request->notes ? $request->notes . ' (Previous relationship closed)' : 'Relationship closed due to parent change'
                    ]);
            }

            // Update user's friend_id
            $user->friend_id = $request->new_parent_user_id;
            $user->save();

            // Create new hierarchy relationship
            UserHierarchyHistory::create([
                'user_id' => $userId,
                'parent_user_id' => $request->new_parent_user_id,
                'joined_at' => now(),
                'notes' => $request->notes ?: 'User transferred to new parent'
            ]);

            DB::commit();

            return response()->json([
                'message' => 'User parent changed successfully',
                'old_parent_user_id' => $oldParentUserId,
                'new_parent_user_id' => $request->new_parent_user_id,
                'changed_at' => now()
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['message' => 'Failed to change user parent'], 500);
        }
    }

}