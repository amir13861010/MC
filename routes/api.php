<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\VoucherController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\UserHierarchyController;
use App\Http\Controllers\LegBalanceController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\WithdrawalRequestController;
use App\Http\Controllers\RewardController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\DepositController;
use App\Http\Controllers\TradeController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Test route
Route::get('test', function () {
    return response()->json(['message' => 'API routes are working!']);
});

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_middleware'),
    'verified'
])->group(function () {
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');
});
Route::put('/admin/profile', [AdminController::class, 'update']);
// Voucher routes
Route::apiResource('vouchers', VoucherController::class);
Route::get('vouchers/user/{user_id}', [VoucherController::class, 'getUserVouchers']);
Route::get('vouchers/code/{code}', [VoucherController::class, 'getVoucherByCode']);
Route::post('vouchers/redeem', [VoucherController::class, 'redeemVoucher']);

// Auth routes
Route::post('auth/sign-up', [RegisterController::class, 'register']);
Route::post('auth/login', [LoginController::class, 'login']);
Route::post('auth/decode-token', [LoginController::class, 'decodeToken']);
Route::post('register', [RegisterController::class, 'register']);
Route::post('auth/unsuspend', [LoginController::class, 'unsuspendUser']);

// User Hierarchy Routes
Route::get('users/hierarchy', [UserHierarchyController::class, 'index']);
Route::get('users/{user_id}/sub-users', [UserHierarchyController::class, 'getSubUsers']);
Route::get('/users/{user_id}/sub-users-capital', [\App\Http\Controllers\UserController::class, 'getSubUsersCapital']);


    Route::get('/leg-balances/{user_id}', [LegBalanceController::class, 'getLegBalances']);

// Ticket routes
Route::post('/tickets', [TicketController::class, 'store']);
Route::get('/tickets/user/{user_id}', [TicketController::class, 'getUserTickets']);
Route::get('/tickets/{ticket_id}', [TicketController::class, 'show']);
Route::get('/tickets/by-id/{ticket_id}', [TicketController::class, 'getByTicketId']);

// Admin ticket routes
Route::get('/admin/tickets', [TicketController::class, 'adminIndex']);
Route::post('/admin/tickets/{ticket_id}/reply', [TicketController::class, 'adminReply']);
Route::delete('/admin/tickets/{ticket_id}/reply', [TicketController::class, 'deleteAdminReply']);

// Withdrawal Request routes
Route::post('/withdrawal-requests', [WithdrawalRequestController::class, 'store']);
Route::get('/withdrawal-requests/{user_id}', [WithdrawalRequestController::class, 'getUserRequests']);
Route::post('/withdrawal-requests/{withdrawal_id}/cancel', [WithdrawalRequestController::class, 'cancel']);

// Admin withdrawal request routes
Route::get('/admin/withdrawal-requests', [WithdrawalRequestController::class, 'adminIndex']);
Route::post('/admin/withdrawal-requests/{withdrawal_id}/update-status', [WithdrawalRequestController::class, 'updateStatus']);
Route::post('/admin/withdrawal-requests/{withdrawal_id}/add-transaction-hash', [WithdrawalRequestController::class, 'addTransactionHash']);
Route::post('/admin/withdrawal-requests/{withdrawal_id}/change-status', [WithdrawalRequestController::class, 'adminChangeStatus']);

// Reward routes
Route::get('/rewards/{user_id}', [RewardController::class, 'getUserRewards']);
Route::get('/rewards/{user_id}/summary', [RewardController::class, 'getUserRewardsSummary']);

// Legacy Reward routes
Route::get('/legacy-rewards/{user_id}', [\App\Http\Controllers\LegacyRewardController::class, 'getUserLegacyRewards']);
Route::get('/legacy-rewards/{user_id}/summary', [\App\Http\Controllers\LegacyRewardController::class, 'getUserLegacyRewardsSummary']);

// Admin management route
Route::post('/admin/add', [\App\Http\Controllers\AdminController::class, 'addAdmin']);
// Admin activate user route
Route::post('/admin/activate-user', [\App\Http\Controllers\AdminController::class, 'activateUser']);
Route::get('/admin/user-stats', [AdminController::class, 'userStats']);
// Enable two factor authentication for user
Route::post('/user/enable-2fa', [\App\Http\Controllers\AdminController::class, 'enableTwoFactor']);

// Disable two factor authentication for user (admin)
Route::post('/user/disable-2fa', [\App\Http\Controllers\AdminController::class, 'disableTwoFactor']);

// Change user password
Route::post('/user/change-password', [\App\Http\Controllers\AdminController::class, 'changePassword']);

// Deposit routes
Route::post('/deposits', [\App\Http\Controllers\DepositController::class, 'store']);
Route::patch('/deposits/{deposit_id}/status', [DepositController::class, 'updateStatus']);
Route::post('/users/transfer-capital-to-gain', [\App\Http\Controllers\UserController::class, 'transferCapitalToGain']);
Route::post('/users/transfer-to-user', [\App\Http\Controllers\UserController::class, 'transferToUser']);
Route::get('/users/transfers', [\App\Http\Controllers\UserController::class, 'getTransfers']);
Route::get('/users/profile', [\App\Http\Controllers\UserController::class, 'profile']);
Route::post('/users/btc-wallet', [\App\Http\Controllers\UserController::class, 'saveBtcWallet']);
Route::post('/users/tron-wallet', [\App\Http\Controllers\UserController::class, 'saveTronWallet']);
Route::get('/users/daily-registrations', [\App\Http\Controllers\UserController::class, 'getDailyRegistrations']);
Route::get('/sub-users-capital-daily/{userId}', [UserController::class, 'getSubUsersCapitalDaily']);
// User Hierarchy History Routes
Route::get('/users/{user_id}/hierarchy-history', [\App\Http\Controllers\UserController::class, 'getHierarchyHistory']);
Route::get('/users/{user_id}/subordinates-history', [\App\Http\Controllers\UserController::class, 'getSubordinatesHistory']);
Route::post('/users/{user_id}/change-parent', [\App\Http\Controllers\UserController::class, 'changeParent']);

Route::post('/trade', [TradeController::class, 'trade']);
Route::get('/trade/{user_id}', [TradeController::class, 'getTradeResult']);
Route::get('/trade/{user_id}/expiration', [TradeController::class, 'checkExpiration']);
Route::post('/trade/{user_id}/process-daily-profit', [TradeController::class, 'processDailyProfit']);
Route::post('/trade/{user_id}/renew', [TradeController::class, 'renewTrade']);
Route::get('/trades/active', [TradeController::class, 'getActiveTrades']);
Route::get('/trades', [TradeController::class, 'getAllTrades']);
Route::get('/trades/stats', [TradeController::class, 'getTradeStats']);
Route::get('/trades/test-connection', [TradeController::class, 'testConnection']);
Route::post('/trades/sync-files', [TradeController::class, 'syncTradeFiles']);
