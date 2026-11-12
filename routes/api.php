<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\FriendController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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

Route::get('/', function () {
    return response()->json([
        'success' => true,
        'message' => 'PITBConnect API is running.',
        'api_base_url' => url('/api'),
        'version' => '1.0.1',
    ]);
});

Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::get('/profile-pictures/{user}', [AuthController::class, 'profilePicture'])
    ->name('profile-pictures.show');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/socket-identity', [AuthController::class, 'socketIdentity']);
    Route::get('/user', fn (Request $request) => $request->user());
    Route::post('/user/profile-picture', [AuthController::class, 'updateProfilePicture']);
    Route::patch('/user/settings', [AuthController::class, 'updateSettings']);

    Route::get('/friends', [FriendController::class, 'index']);
    Route::get('/blocks', [FriendController::class, 'blockedUsers']);
    Route::post('/users/{user}/block', [FriendController::class, 'block']);
    Route::delete('/users/{user}/block', [FriendController::class, 'unblock']);
    Route::delete('/friends/{user}', [FriendController::class, 'destroy']);
    Route::get('/friends/requests', [FriendController::class, 'requests']);
    Route::post('/friends/requests', [FriendController::class, 'storeRequest']);
    Route::post('/friends/requests/{friendRequest}/{action}', [FriendController::class, 'respond'])
        ->whereIn('action', ['accept', 'reject']);

    Route::get('/conversations', [ConversationController::class, 'index']);
    Route::get('/conversation-keys', [ConversationController::class, 'allKeys']);
    Route::get('/conversations/{conversation}/keys', [ConversationController::class, 'keys']);
    Route::get('/call-history', [ConversationController::class, 'callHistory']);
    Route::post('/groups', [ConversationController::class, 'createGroup']);
    Route::post('/groups/{conversation}/members', [ConversationController::class, 'addMember']);
    Route::post('/groups/{conversation}/leave', [ConversationController::class, 'leaveGroup']);
    Route::delete('/groups/{conversation}/members/{user}', [ConversationController::class, 'removeMember']);
    Route::post('/conversations', [ConversationController::class, 'store']);
    Route::post('/conversations/{conversation}/request/{action}', [ConversationController::class, 'respondToRequest'])
        ->whereIn('action', ['accept', 'decline']);
    Route::post('/conversations/{conversation}/messages/{message}/pin', [ConversationController::class, 'pinMessage']);
    Route::delete('/conversations/{conversation}/messages/{message}/pin', [ConversationController::class, 'unpinMessage']);
    Route::get('/conversations/{conversation}/socket-access', [ConversationController::class, 'socketAccess']);
    Route::get('/conversations/{conversation}/messages', [ConversationController::class, 'messages']);
    Route::post('/conversations/{conversation}/messages', [ConversationController::class, 'send']);
    Route::post('/conversations/{conversation}/calls', [ConversationController::class, 'recordCall']);
    Route::post('/conversations/{conversation}/messages/{message}/forward', [ConversationController::class, 'forward']);
    Route::patch('/conversations/{conversation}/messages/{message}', [ConversationController::class, 'editMessage']);
    Route::delete('/conversations/{conversation}/messages/{message}', [ConversationController::class, 'deleteMessage']);
    Route::get('/conversations/{conversation}/messages/{message}/attachment', [ConversationController::class, 'downloadAttachment']);
    Route::post('/conversations/{conversation}/delivered', [ConversationController::class, 'markDelivered']);
    Route::post('/conversations/{conversation}/read', [ConversationController::class, 'markRead']);
});
