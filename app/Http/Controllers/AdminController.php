<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * @OA\Tag(
 *     name="Admin",
 *     description="API Endpoints for admin management"
 * )
 */
class AdminController extends Controller
{
    /**
     * @OA\Put(
     *     path="/api/admin/profile",
     *     summary="Update admin profile",
     *     description="Update admin username and/or password",
     *     operationId="updateAdminProfile",
     *     tags={"Admin"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="username", type="string", example="new_admin"),
     *             @OA\Property(property="current_password", type="string", example="Admin@123"),
     *             @OA\Property(property="password", type="string", example="NewPass@123"),
     *             @OA\Property(property="password_confirmation", type="string", example="NewPass@123")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Admin updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Admin updated successfully"),
     *             @OA\Property(
     *                 property="admin",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="username", type="string", example="new_admin")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="The given data was invalid."),
     *             @OA\Property(
     *                 property="errors",
     *                 type="object",
     *                 @OA\Property(
     *                     property="username",
     *                     type="array",
     *                     @OA\Items(type="string", example="The username has already been taken.")
     *                 ),
     *                 @OA\Property(
     *                     property="current_password",
     *                     type="array",
     *                     @OA\Items(type="string", example="The current password is incorrect.")
     *                 ),
     *                 @OA\Property(
     *                     property="password",
     *                     type="array",
     *                     @OA\Items(type="string", example="The password must contain at least one uppercase letter, one lowercase letter, one number and one special character.")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthenticated",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Unauthenticated.")
     *         )
     *     )
     * )
     */
    public function update(Request $request)
    {
        $admin = auth()->user();

        $validated = $request->validate([
            'username' => ['sometimes', 'string', 'max:255', Rule::unique('admins')->ignore($admin->id)],
            'current_password' => ['required_with:password', 'current_password'],
            'password' => ['sometimes', 'string', 'min:8', 'confirmed', 'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/'],
        ], [
            'password.regex' => 'The password must contain at least one uppercase letter, one lowercase letter, one number and one special character.'
        ]);

        if (isset($validated['username'])) {
            $admin->username = $validated['username'];
        }

        if (isset($validated['password'])) {
            // Check if the new password is not the same as the current one
            if (Hash::check($validated['password'], $admin->password)) {
                throw ValidationException::withMessages([
                    'password' => ['The new password must be different from the current password.']
                ]);
            }
            $admin->password = $validated['password'];
        }

        $admin->save();

        return response()->json([
            'message' => 'Admin updated successfully',
            'admin' => $admin->only(['id', 'username'])
        ]);
    }

    /**
     * Add a new admin user (only accessible by admins)
     */
    public function addAdmin(Request $request)
    {
        $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'phone' => 'required|string|max:20',
            'country' => 'required|string|max:100',
        ]);

        // Generate unique user_id (MC-XXXXXX)
        do {
            $userId = 'MC-' . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
        } while (User::where('user_id', $userId)->exists());

        // Generate random password
        $password = Str::random(8);

        $user = User::create([
            'user_id' => $userId,
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'email' => $request->email,
            'phone' => $request->phone,
            'country' => $request->country,
            'password' => Hash::make($password),
            'role' => 'admin',
        ]);

        return response()->json([
            'message' => 'Admin created successfully',
            'user' => [
                'username' => $userId,
                'password' => $password
            ]
        ]);
    }

    /**
     * Activate a suspended user by user_id (only accessible by admins)
     */
    public function activateUser(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,user_id',
        ]);

        $user = User::where('user_id', $request->user_id)->first();
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }
        $user->suspended = false;
        $user->save();

        return response()->json([
            'message' => 'User activated successfully',
            'user_id' => $user->user_id
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/user/enable-2fa",
     *     summary="Enable two-factor authentication for a user",
     *     description="Set two factor secret and enable 2FA for a user",
     *     operationId="enableTwoFactor",
     *     tags={"Admin"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="user_id", type="string", example="MC-000123"),
     *             @OA\Property(property="two_factor_secret", type="string", example="SECRET123456")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Two factor authentication enabled",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Two factor authentication enabled"),
     *             @OA\Property(property="user_id", type="string", example="MC-000123")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="User not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="User not found")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="The given data was invalid."),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     )
     * )
     */
    public function enableTwoFactor(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,user_id',
            'two_factor_secret' => 'required|string',
        ]);

        $user = User::where('user_id', $request->user_id)->first();
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }
        $user->two_factor_secret = $request->two_factor_secret;
        $user->two_factor_enabled = true;
        $user->save();

        return response()->json([
            'message' => 'Two factor authentication enabled',
            'user_id' => $user->user_id
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/user/disable-2fa",
     *     summary="Disable two-factor authentication for a user",
     *     description="Disable 2FA for a user by clearing the secret and disabling the flag",
     *     operationId="disableTwoFactor",
     *     tags={"Admin"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="user_id", type="string", example="MC-000123")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Two factor authentication disabled",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Two factor authentication disabled"),
     *             @OA\Property(property="user_id", type="string", example="MC-000123")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="User not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="User not found")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="The given data was invalid."),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     )
     * )
     */
    public function disableTwoFactor(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,user_id',
        ]);

        $user = \App\Models\User::where('user_id', $request->user_id)->first();
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }
        $user->two_factor_secret = null;
        $user->two_factor_enabled = false;
        $user->save();

        return response()->json([
            'message' => 'Two factor authentication disabled',
            'user_id' => $user->user_id
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/user/change-password",
     *     summary="Change user password by verifying old password",
     *     description="Change user password by verifying old password",
     *     operationId="changePassword",
     *     tags={"Admin"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="user_id", type="string", example="MC-000123"),
     *             @OA\Property(property="old_password", type="string", example="OldPass123"),
     *             @OA\Property(property="new_password", type="string", example="NewPass456")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Password changed successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Password changed successfully"),
     *             @OA\Property(property="user_id", type="string", example="MC-000123")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="User not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="User not found")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Old password is incorrect")
     *         )
     *     )
     * )
     */
    public function changePassword(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,user_id',
            'old_password' => 'required|string',
            'new_password' => 'required|string|min:6',
        ]);

        $user = User::where('user_id', $request->user_id)->first();
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }
        if (!\Hash::check($request->old_password, $user->password)) {
            return response()->json(['message' => 'Old password is incorrect'], 422);
        }
        $user->password = \Hash::make($request->new_password);
        $user->save();

        return response()->json([
            'message' => 'Password changed successfully',
            'user_id' => $user->user_id
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/admin/user-stats",
     *     summary="Get user statistics",
     *     description="Returns total user count and sum of gain_profit, capital_profit, and deposit_balance for all users.",
     *     operationId="getUserStats",
     *     tags={"Admin"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="User statistics",
     *         @OA\JsonContent(
     *             @OA\Property(property="total_users", type="integer", example=100),
     *             @OA\Property(property="total_gain_profit", type="number", format="float", example=12345.67),
     *             @OA\Property(property="total_capital_profit", type="number", format="float", example=23456.78),
     *             @OA\Property(property="total_deposit_balance", type="number", format="float", example=34567.89)
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthenticated",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Unauthenticated.")
     *         )
     *     )
     * )
     */
    public function userStats()
    {
        $totalUsers = \App\Models\User::count();
        $totalGainProfit = \App\Models\User::sum('gain_profit');
        $totalCapitalProfit = \App\Models\User::sum('capital_profit');
        $totalDepositBalance = \App\Models\User::sum('deposit_balance');

        return response()->json([
            'total_users' => $totalUsers,
            'total_gain_profit' => $totalGainProfit,
            'total_capital_profit' => $totalCapitalProfit,
            'total_deposit_balance' => $totalDepositBalance,
        ]);
    }
}