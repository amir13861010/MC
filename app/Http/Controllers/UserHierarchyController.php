<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * @OA\Tag(
 *     name="User Hierarchy",
 *     description="API Endpoints for user hierarchy management"
 * )
 */
class UserHierarchyController extends Controller
{
    /**
     * بازگشت سلسله مراتب کاربران به صورت بازگشتی
     */
    private function getUserHierarchy($user)
    {
        $subUsers = User::where('friend_id', $user->user_id)->get();
        return [
            'user_id' => $user->user_id,
            'sub_users' => $subUsers->map(function($subUser) {
                return $this->getUserHierarchy($subUser);
            })->toArray()
        ];
    }

    /**
     * @OA\Get(
     *     path="/api/users/hierarchy",
     *     summary="Get all users with their hierarchy",
     *     tags={"User Hierarchy"},
     *     @OA\Response(
     *         response=200,
     *         description="List of users with their hierarchy",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(
     *                 type="object",
     *                 @OA\Property(property="user_id", type="string", example="MC34234"),
     *                 @OA\Property(
     *                     property="sub_users",
     *                     type="array",
     *                     @OA\Items(
     *                         type="object",
     *                         @OA\Property(property="user_id", type="string", example="MC34235"),
     *                         @OA\Property(
     *                             property="sub_users",
     *                             type="array",
     *                             @OA\Items(
     *                                 type="object",
     *                                 @OA\Property(property="user_id", type="string", example="MC34236")
     *                             )
     *                         )
     *                     )
     *                 )
     *             )
     *         )
     *     )
     * )
     */
    public function index()
    {
        $mainUsers = User::whereNull('friend_id')->get();
        $hierarchy = $mainUsers->map(function($user) {
            return $this->getUserHierarchy($user);
        });
        return response()->json($hierarchy);
    }

    /**
     * @OA\Get(
     *     path="/api/users/{user_id}/sub-users",
     *     summary="Get sub-users of a specific user",
     *     tags={"User Hierarchy"},
     *     @OA\Parameter(
     *         name="user_id",
     *         in="path",
     *         required=true,
     *         description="User ID to get sub-users for",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="List of sub-users",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(
     *                 type="object",
     *                 @OA\Property(property="user_id", type="string", example="MC34234"),
     *                 @OA\Property(
     *                     property="sub_users",
     *                     type="array",
     *                     @OA\Items(
     *                         type="object",
     *                         @OA\Property(property="user_id", type="string", example="MC34235")
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="User not found"
     *     )
     * )
     */
    public function getSubUsers($userId)
    {
        $user = User::where('user_id', $userId)->firstOrFail();
        $subUsers = User::where('friend_id', $userId)
            ->with(['subUsers' => function($query) {
                $query->select('id', 'user_id', 'friend_id');
            }])
            ->select('id', 'user_id')
            ->get()
            ->map(function($user) {
                return [
                    'user_id' => $user->user_id,
                    'sub_users' => $user->subUsers->map(function($subUser) {
                        return [
                            'user_id' => $subUser->user_id
                        ];
                    })
                ];
            });
        
        return response()->json($subUsers);
    }
} 