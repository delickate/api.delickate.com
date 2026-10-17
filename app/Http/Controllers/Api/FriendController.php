<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

        $friendRequest->update(['status' => $action === 'accept' ? 'accepted' : 'rejected']);

        return response()->json([
            'id' => $friendRequest->id,
            'status' => $friendRequest->status,
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
        ];
    }
}
