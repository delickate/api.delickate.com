<?php

use App\Http\Controllers\Api\ApiCollectionController;
use App\Http\Controllers\Api\ApiFolderController;
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
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:6,1');
Route::get('/profile-pictures/{user}', [AuthController::class, 'profilePicture'])
    ->name('profile-pictures.show');
// Group icons are only reachable through the signed URL handed to members.
Route::get('/group-icons/{conversation}', [ConversationController::class, 'groupIcon'])
    ->middleware('signed:relative')
    ->name('group-icons.show');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/socket-identity', [AuthController::class, 'socketIdentity']);
    Route::get('/user', fn (Request $request) => $request->user());
    Route::post('/user/profile-picture', [AuthController::class, 'updateProfilePicture']);
    Route::delete('/user/profile-picture', [AuthController::class, 'removeProfilePicture']);
    Route::patch('/user/profile', [AuthController::class, 'updateProfile']);
    Route::patch('/user/password', [AuthController::class, 'updatePassword']);
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
    Route::post('/groups/{conversation}/icon', [ConversationController::class, 'updateGroupIcon']);
    Route::delete('/groups/{conversation}/icon', [ConversationController::class, 'removeGroupIcon']);
    Route::post('/groups/{conversation}/leave',[ConversationController::class, 'leaveGroup']);
    Route::delete('/groups/{conversation}/members/{user}', [ConversationController::class, 'removeMember']);
    Route::post('/conversations', [ConversationController::class, 'store']);
    Route::post('/conversations/{conversation}/clear', [ConversationController::class, 'clear']);
    Route::patch('/conversations/{conversation}/disappearing', [ConversationController::class, 'updateDisappearing']);
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

    // API Collections desktop app: nested folders and exportable collections.
    Route::get('/workspace', [ApiFolderController::class, 'workspace']);
    Route::post('/folders', [ApiFolderController::class, 'store']);
    Route::patch('/folders/{folder}', [ApiFolderController::class, 'update']);
    Route::delete('/folders/{folder}', [ApiFolderController::class, 'destroy']);
    Route::post('/collections', [ApiCollectionController::class, 'store']);
    Route::post('/collections/import', [ApiCollectionController::class, 'import'])->middleware('throttle:30,1');
    Route::get('/collections/{collection}', [ApiCollectionController::class, 'show']);
    Route::patch('/collections/{collection}', [ApiCollectionController::class, 'update']);
    Route::delete('/collections/{collection}', [ApiCollectionController::class, 'destroy']);
    Route::post('/collections/{collection}/duplicate', [ApiCollectionController::class, 'duplicate']);
    Route::get('/collections/{collection}/export', [ApiCollectionController::class, 'export']);
});
