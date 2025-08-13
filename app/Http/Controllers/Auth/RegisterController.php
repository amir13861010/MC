<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\WelcomeEmail; // Add this import
use App\Mail\PasswordResetEmail;
use App\Models\User;
use App\Models\Voucher;
use App\Models\UserHierarchyHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * @OA\Tag(
 *     name="Authentication",
 *     description="API Endpoints for user authentication"
 * )
 */
class RegisterController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/auth/sign-up",
     *     summary="Register a new user",
     *     description="Register a new user with optional voucher code and friend ID",
     *     operationId="register",
     *     tags={"Auth"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"first_name", "last_name", "email", "phone", "country"},
     *             @OA\Property(property="first_name", type="string", example="John"),
     *             @OA\Property(property="last_name", type="string", example="Doe"),
     *             @OA\Property(property="email", type="string", format="email", example="john@example.com"),
     *             @OA\Property(property="phone", type="string", example="+989123456789"),
     *             @OA\Property(property="country", type="string", example="Iran"),
     *             @OA\Property(property="voucher_id", type="string", example="ABC123", nullable=true),
     *             @OA\Property(property="friend_id", type="string", example="MC34234", nullable=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="User registered successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Registration successful"),
     *             @OA\Property(property="user", type="object",
     *                 @OA\Property(property="username", type="string", example="MC34234"),
     *                 @OA\Property(property="password", type="string", example="a1b2c3d4")
     *             ),
     *             @OA\Property(property="token", type="string", example="eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="The given data was invalid"),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     )
     * )
     */
    public function register(Request $request)
    {
        // Validate request
        $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'phone' => 'required|string|max:20',
            'country' => 'required|string|max:100',
            'voucher_id' => 'nullable|string|max:50',
            'friend_id' => 'required|string|regex:/^MC\d{6}$/'
        ]);

        // Check voucher if provided
        if ($request->voucher_id) {
            $voucher = Voucher::where('code', $request->voucher_id)->first();

            if (!$voucher) {
                throw ValidationException::withMessages([
                    'voucher_id' => ['Voucher not found']
                ]);
            }

            if ($voucher->status === 'inactive') {
                throw ValidationException::withMessages([
                    'voucher_id' => ['Voucher has expired']
                ]);
            }
        }

        // Check friend_id if provided
        if ($request->friend_id) {
            $friend = User::where('user_id', $request->friend_id)->first();
            
            if (!$friend) {
                throw ValidationException::withMessages([
                    'friend_id' => ['Referral user not found']
                ]);
            }

            // Check if friend_id already has 3 referrals
            $referralCount = User::where('friend_id', $request->friend_id)->count();
            if ($referralCount >= 3) {
                throw ValidationException::withMessages([
                    'friend_id' => ['This user already has 3 referrals.']
                ]);
            }
        }

        // Generate unique user_id (MCXXXXXX, starting from 1112)
        $userId = User::generateUniqueUserId();

        // Generate random password
        $password = Str::random(8);

        // Create user
        $user = User::create([
            'user_id' => $userId,
            'name' => $request->first_name . $request->last_name,
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'email' => $request->email,
            'mobile' => $request->phone,
            'country' => $request->country,
            'password' => Hash::make($password),
            'friend_id' => $request->friend_id,
            'role' => 'user',
            'suspended' => true,
        ]);

        // Create hierarchy history if friend_id is provided
        if ($request->friend_id) {
            UserHierarchyHistory::create([
                'user_id' => $userId,
                'parent_user_id' => $request->friend_id,
                'joined_at' => now(),
                'notes' => 'User registered with referral'
            ]);
        }

        // Deactivate voucher if used
        if ($request->voucher_id) {
            $voucher->update(['status' => 'inactive']);
        }

        // Send welcome email
        try {
            Mail::to($user->email)->send(new WelcomeEmail($userId, $password));
        } catch (\Exception $e) {
            // Log the error for debugging
            \Log::error('Failed to send welcome email: ' . $e->getMessage());
            // Continue with registration even if email fails
        }

        $token = JWTAuth::fromUser($user);

        return response()->json([
            'message' => 'Registration successful',
            'user' => [
                'username' => $userId,
                'password' => $password
            ],
            'token' => $token
        ]);
    }
/**
    * @OA\Post(
        *     path="/api/auth/password/reset",
        *     summary="Reset user password",
        *     description="Generate a new random password for the user and send it via email",
        *     operationId="resetPassword",
        *     tags={"Auth"},
        *     @OA\RequestBody(
        *         required=true,
        *         @OA\JsonContent(
        *             required={"user_id"},
        *             @OA\Property(property="user_id", type="string", example="MC123456")
        *         )
        *     ),
        *     @OA\Response(
        *         response=200,
        *         description="Password reset successful",
        *         @OA\JsonContent(
        *             @OA\Property(property="success", type="boolean", example=true),
        *             @OA\Property(property="message", type="string", example="Password reset email sent successfully"),
        *             @OA\Property(property="data", type="object",
        *                 @OA\Property(property="user_id", type="string", example="MC123456")
        *             )
        *         )
        *     ),
        *     @OA\Response(
        *         response=404,
        *         description="User not found",
        *         @OA\JsonContent(
        *             @OA\Property(property="success", type="boolean", example=false),
        *             @OA\Property(property="message", type="string", example="User not found")
        *         )
        *     ),
        *     @OA\Response(
        *         response=500,
        *         description="Email sending failed",
        *         @OA\JsonContent(
        *             @OA\Property(property="success", type="boolean", example=false),
        *             @OA\Property(property="message", type="string", example="Password reset but failed to send email"),
        *             @OA\Property(property="data", type="object",
        *                 @OA\Property(property="user_id", type="string", example="MC123456"),
        *                 @OA\Property(property="temporary_password", type="string", example="x9y8z7w6")
        *             )
        *         )
        *     )
        * )
        */
       public function resetPassword(Request $request)
       {
           $request->validate([
               'user_id' => 'required|string|regex:/^MC\d{6}$/'
           ]);
       
           $user = User::where('user_id', $request->user_id)->first();
       
           if (!$user) {
               return response()->json([
                   'success' => false,
                   'message' => 'User not found'
               ], 404);
           }
       
           // Generate new random password
           $newPassword = Str::random(8);
       
           // Update user password
           $user->update([
               'password' => Hash::make($newPassword)
           ]);
       
           try {
               Mail::to($user->email)->send(new PasswordResetEmail($user->user_id, $newPassword));
               
               return response()->json([
                   'success' => true,
                   'message' => 'Password reset email sent successfully',
                   'data' => [
                       'user_id' => $user->user_id
                   ]
               ]);
               
           } catch (\Exception $e) {
               \Log::error('Password reset email failed: ' . $e->getMessage());
               
               return response()->json([
                   'success' => false,
                   'message' => 'Password reset but failed to send email',
                   'data' => [
                       'user_id' => $user->user_id,
                       'temporary_password' => $newPassword
                   ]
               ], 500);
           }
       }
}