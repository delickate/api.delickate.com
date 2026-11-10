<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\FriendRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FriendController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $friends = FriendRequest::query()
            ->where('status', 'accepted')
            ->where(fn ($query) => $query->where('low_user_id', $userId)->orWhere('high_user_id', $userId))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('user_blocks')
                ->whereColumn('user_blocks.blocker_id', 'friend_requests.low_user_id')
                ->whereColumn('user_blocks.blocked_id', 'friend_requests.high_user_id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('user_blocks')
                ->whereColumn('user_blocks.blocker_id', 'friend_requests.high_user_id')
                ->whereColumn('user_blocks.blocked_id', 'friend_requests.low_user_id'))
            ->with(['lowUser:id,name,phone', 'highUser:id,name,phone'])
            ->orderByDesc('updated_at')
            ->paginate(50);

        $friends->getCollection()->transform(function (FriendRequest $friendRequest) use ($userId) {
            return $this->publicUser(
                (int) $friendRequest->low_user_id === $userId
                    ? $friendRequest->highUser
                    : $friendRequest->lowUser
            );
        });
        return response()->json($friends);
    }

    public function blockedUsers(Request $request): JsonResponse
    {
        $blockedUsers = $request->user()->blocksMade()
            ->select('users.id', 'users.name', 'users.phone', 'users.profile_picture_path')
            ->orderByDesc('user_blocks.created_at')
            ->paginate(50);
        $blockedUsers->getCollection()->transform(fn (User $user) => $this->publicUser($user));

        return response()->json($blockedUsers);
    }

    public function block(Request $request, User $user): JsonResponse
    {
        $blockerId = (int) $request->user()->id;
        if ($blockerId === (int) $user->id) {
            throw ValidationException::withMessages(['user' => ['You cannot block yourself.']]);
        }

        DB::transaction(function () use ($request, $user): void {
            $request->user()->blocksMade()->syncWithoutDetaching([$user->id]);
            FriendRequest::query()
                ->where('status', 'pending')
                ->where('low_user_id', min((int) $request->user()->id, (int) $user->id))
                ->where('high_user_id', max((int) $request->user()->id, (int) $user->id))
                ->delete();
        });

        return response()->json([
            'message' => 'User blocked.',
            'user_id' => $user->id,
        ]);
    }

    public function unblock(Request $request, User $user): JsonResponse
    {
        $deleted = $request->user()->blocksMade()->detach($user->id);
        if ($deleted === 0) {
            return response()->json(['message' => 'This user is not in your blocked list.'], 404);
        }

        return response()->json([
            'message' => 'User unblocked.',
            'user_id' => $user->id,
        ]);
    }

    public function requests(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'direction' => ['sometimes', 'in:incoming,outgoing'],
        ]);
        $userId = (int) $request->user()->id;
        $direction = $validated['direction'] ?? 'incoming';
        $query = FriendRequest::query()->where('status', 'pending');

        if ($direction === 'outgoing') {
            $query->where('sender_id', $userId);
        } else {
            $query->where(fn ($builder) => $builder
                ->where('low_user_id', $userId)
                ->orWhere('high_user_id', $userId))
                ->where('sender_id', '!=', $userId);
        }
        $query->whereNotExists(fn ($builder) => $builder->selectRaw('1')
            ->from('user_blocks')
            ->whereColumn('user_blocks.blocker_id', 'friend_requests.low_user_id')
            ->whereColumn('user_blocks.blocked_id', 'friend_requests.high_user_id'))
            ->whereNotExists(fn ($builder) => $builder->selectRaw('1')
                ->from('user_blocks')
                ->whereColumn('user_blocks.blocker_id', 'friend_requests.high_user_id')
                ->whereColumn('user_blocks.blocked_id', 'friend_requests.low_user_id'));

        $requests = $query
            ->with(['sender:id,name,phone', 'lowUser:id,name,phone', 'highUser:id,name,phone'])
            ->orderByDesc('created_at')
            ->paginate(50);

        $requests->getCollection()->transform(function (FriendRequest $friendRequest) use ($direction) {
            return [
                'id' => $friendRequest->id,
                'status' => $friendRequest->status,
                'created_at' => $friendRequest->created_at,
                'user' => $this->publicUser(
                    $direction === 'outgoing'
                        ? ((int) $friendRequest->low_user_id === (int) $friendRequest->sender_id
                            ? $friendRequest->highUser
                            : $friendRequest->lowUser)
                        : $friendRequest->sender
                ),
            ];
        });

        return response()->json($requests);
    }

    public function storeRequest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
        ]);
        $user = $request->user();
        $friend = User::where('phone', $validated['phone'])->firstOrFail();

        if ($friend->is($user)) {
            throw ValidationException::withMessages(['phone' => ['You cannot add yourself.']]);
        }

        $userId = (int) $user->id;
        $friendId = (int) $friend->id;
        if ($this->usersHaveBlock($userId, $friendId)) {
            throw ValidationException::withMessages(['phone' => ['This user cannot be added while either of you has blocked the other.']]);
        }
        $lowId = min($userId, $friendId);
        $highId = max($userId, $friendId);

        $friendRequest = DB::transaction(function () use ($lowId, $highId, $userId) {
            $existing = FriendRequest::where('low_user_id', $lowId)
                ->where('high_user_id', $highId)
                ->lockForUpdate()
                ->first();

            if ($existing && $existing->status === 'accepted') {
                throw ValidationException::withMessages(['phone' => ['This user is already your friend.']]);
            }

            if ($existing && $existing->status === 'pending') {
                if ((int) $existing->sender_id === $userId) {
                    return $existing;
                }

                throw ValidationException::withMessages(['phone' => ['This user has already sent you a request.']]);
            }

            if ($existing) {
                $existing->update(['sender_id' => $userId, 'status' => 'pending']);

                return $existing->fresh();
            }

            return FriendRequest::create([
                'low_user_id' => $lowId,
                'high_user_id' => $highId,
                'sender_id' => $userId,
                'status' => 'pending',
            ]);
        });

        return response()->json([
            'id' => $friendRequest->id,
            'status' => $friendRequest->status,
            'created_at' => $friendRequest->created_at,
        ], 201);
    }

    public function respond(Request $request, FriendRequest $friendRequest, string $action): JsonResponse
    {
        $userId = (int) $request->user()->id;

        if ((int) $friendRequest->sender_id === $userId) {
            return response()->json(['message' => 'Only the recipient can respond to this request.'], 403);
        }

        if (! in_array($userId, [(int) $friendRequest->low_user_id, (int) $friendRequest->high_user_id], true)) {
            return response()->json(['message' => 'Friend request not found.'], 404);
        }

        if ($friendRequest->status !== 'pending') {
            return response()->json(['message' => 'This request has already been handled.'], 409);
        }
        if ($action === 'accept' &&
            $this->usersHaveBlock((int) $friendRequest->low_user_id, (int) $friendRequest->high_user_id)) {
            return response()->json(['message' => 'This friend request cannot be accepted while either user has blocked the other.'], 409);
        }

        $friendRequest->update(['status' => $action === 'accept' ? 'accepted' : 'rejected']);

        return response()->json([
            'id' => $friendRequest->id,
            'status' => $friendRequest->status,
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $requestingUserId = (int) $request->user()->id;

        if ($requestingUserId === (int) $user->id) {
            return response()->json(['message' => 'You cannot unfriend yourself.'], 403);
        }

        $lowId = min($requestingUserId, (int) $user->id);
        $highId = max($requestingUserId, (int) $user->id);

        $friendRequest = FriendRequest::query()
            ->where('low_user_id', $lowId)
            ->where('high_user_id', $highId)
            ->where('status', 'accepted')
            ->first();

        if (! $friendRequest) {
            return response()->json(['message' => 'This user is not in your friends list.'], 404);
        }

        DB::transaction(function () use ($lowId, $highId, $friendRequest) {
            $friendRequest->delete();

            Conversation::query()
                ->where('type', 'direct')
                ->where('low_user_id', $lowId)
                ->where('high_user_id', $highId)
                ->delete();
        });

        return response()->json([
            'message' => 'Friend removed.',
            'user_id' => $user->id,
        ]);
    }

    private function publicUser(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'avatar_url' => $user->avatar_url,
        ];
    }

    private function usersHaveBlock(int $firstUserId, int $secondUserId): bool
    {
        return DB::table('user_blocks')
            ->where(fn ($query) => $query
                ->where('blocker_id', $firstUserId)
                ->where('blocked_id', $secondUserId))
            ->orWhere(fn ($query) => $query
                ->where('blocker_id', $secondUserId)
                ->where('blocked_id', $firstUserId))
            ->exists();
    }
}
