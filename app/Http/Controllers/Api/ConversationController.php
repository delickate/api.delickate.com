<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\FriendRequest;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $conversations = Conversation::query()
            ->where(function ($query) use ($userId) {
                $query->where(function ($innerQuery) use ($userId) {
                    $innerQuery->where('low_user_id', $userId)
                        ->orWhere('high_user_id', $userId);
                })->orWhereHas('participants', fn ($participantQuery) => $participantQuery->where('users.id', $userId));
            })
            ->with([
                'lowUser:id,name,phone,profile_picture_path',
                'highUser:id,name,phone,profile_picture_path',
                'participants:id,name,phone,profile_picture_path',
                'messages' => fn ($query) => $query->with('sender:id,name,profile_picture_path')->latest('id')->limit(1),
            ])
            ->withCount([
                'messages as unread_count' => fn ($query) => $query
                    ->where('sender_id', '!=', $userId)
                    ->whereNull('read_at'),
            ])
            ->orderByDesc('updated_at')
            ->paginate(50);

        $conversations->getCollection()->transform(
            fn (Conversation $conversation) => $this->conversationData($conversation, $userId)
        );

        return response()->json($conversations);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'friend_id' => ['required', 'integer', 'exists:users,id'],
        ]);
        $userId = (int) $request->user()->id;
        $friendId = (int) $validated['friend_id'];

        if ($userId === $friendId) {
            throw ValidationException::withMessages(['friend_id' => ['You cannot start a conversation with yourself.']]);
        }

        $lowId = min($userId, $friendId);
        $highId = max($userId, $friendId);
        $isFriend = FriendRequest::where('low_user_id', $lowId)
            ->where('high_user_id', $highId)
            ->where('status', 'accepted')
            ->exists();

        if (! $isFriend) {
            return response()->json(['message' => 'You can only message accepted friends.'], 403);
        }

        $conversation = Conversation::firstOrCreate([
            'low_user_id' => $lowId,
            'high_user_id' => $highId,
        ], [
            'type' => 'direct',
        ]);

        $conversation->load([
            'lowUser:id,name,phone,profile_picture_path',
            'highUser:id,name,phone,profile_picture_path',
            'participants:id,name,phone,profile_picture_path',
            'messages' => fn ($query) => $query->with('sender:id,name,profile_picture_path')->latest('id')->limit(1),
        ])->loadCount([
            'messages as unread_count' => fn ($query) => $query
                ->where('sender_id', '!=', $userId)
                ->whereNull('read_at'),
        ]);

        return response()->json($this->conversationData($conversation, $userId), 201);
    }

    public function createGroup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:100'],
            'friend_ids' => ['required', 'array', 'min:1'],
            'friend_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $userId = (int) $request->user()->id;
        $friendIds = array_values(array_unique(array_map('intval', $validated['friend_ids'])));
        $friendIds = array_values(array_filter($friendIds, fn (int $friendId) => $friendId !== $userId));

        if ($friendIds === []) {
            throw ValidationException::withMessages([
                'friend_ids' => ['Choose at least one friend to add to the group.'],
            ]);
        }

        $acceptedFriendIds = FriendRequest::query()
            ->where('status', 'accepted')
            ->where(function ($query) use ($userId) {
                $query->where('low_user_id', $userId)
                    ->orWhere('high_user_id', $userId);
            })
            ->get()
            ->flatMap(fn (FriendRequest $friendRequest) => [
                (int) $friendRequest->low_user_id,
                (int) $friendRequest->high_user_id,
            ])
            ->unique()
            ->reject(fn (int $memberId) => $memberId === $userId)
            ->values()
            ->all();

        $invalidFriendIds = array_values(array_diff($friendIds, $acceptedFriendIds));
        if ($invalidFriendIds !== []) {
            return response()->json([
                'message' => 'Only accepted friends can be added to a group.',
            ], 403);
        }

        $conversation = DB::transaction(function () use ($userId, $friendIds, $validated) {
            $conversation = Conversation::create([
                'type' => 'group',
                'name' => trim($validated['name']),
                'created_by_user_id' => $userId,
            ]);

            $conversation->participants()->syncWithoutDetaching(array_values(array_unique(array_merge([$userId], $friendIds))));

            return $conversation->fresh(['participants:id,name,phone,profile_picture_path']);
        });

        $conversation->load([
            'participants:id,name,phone,profile_picture_path',
            'messages' => fn ($query) => $query->with('sender:id,name,profile_picture_path')->latest('id')->limit(1),
        ])->loadCount([
            'messages as unread_count' => fn ($query) => $query
                ->where('sender_id', '!=', $userId)
                ->whereNull('read_at'),
        ]);

        return response()->json($this->conversationData($conversation, $userId), 201);
    }

    public function leaveGroup(Request $request, Conversation $conversation): JsonResponse
    {
        $userId = (int) $request->user()->id;

        if ($conversation->type !== 'group' || ! $this->userIsParticipant($conversation, $userId)) {
            return response()->json(['message' => 'Group not found.'], 404);
        }

        if ((int) $conversation->created_by_user_id === $userId) {
            return response()->json(['message' => 'The group creator cannot leave the group. Transfer ownership or delete the group first.'], 403);
        }

        $conversation->participants()->detach($userId);

        return response()->json([
            'message' => 'You left the group.',
            'conversation_id' => $conversation->id,
            'removed_user_id' => $userId,
        ]);
    }

    public function removeMember(Request $request, Conversation $conversation, User $user): JsonResponse
    {
        $requestingUserId = (int) $request->user()->id;

        if ($conversation->type !== 'group') {
            return response()->json(['message' => 'Group not found.'], 404);
        }

        if (! $this->userIsParticipant($conversation, $requestingUserId)) {
            return response()->json(['message' => 'You are not a member of this group.'], 403);
        }

        if ((int) $requestingUserId !== (int) $conversation->created_by_user_id && (int) $requestingUserId !== (int) $user->id) {
            return response()->json(['message' => 'Only the group creator or the member themselves can remove a member.'], 403);
        }

        if ((int) $user->id === (int) $conversation->created_by_user_id) {
            return response()->json(['message' => 'The group creator cannot be removed.'], 403);
        }

        if (! $this->userIsParticipant($conversation, (int) $user->id)) {
            return response()->json(['message' => 'This member is not in the group.'], 404);
        }

        $conversation->participants()->detach($user->id);

        return response()->json([
            'message' => 'Member removed from group.',
            'conversation_id' => $conversation->id,
            'removed_user_id' => $user->id,
        ]);
    }

    public function socketAccess(Request $request, Conversation $conversation): JsonResponse
    {
        $userId = (int) $request->user()->id;

        if (! $this->userIsParticipant($conversation, $userId)) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        if ($conversation->type === 'group') {
            return response()->json([
                'conversation_id' => $conversation->id,
                'peer_id' => null,
            ]);
        }

        $peerId = $userId === (int) $conversation->low_user_id
            ? (int) $conversation->high_user_id
            : (int) $conversation->low_user_id;

        return response()->json([
            'conversation_id' => $conversation->id,
            'peer_id' => $peerId,
        ]);
    }

    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $userId = (int) $request->user()->id;

        if (! $this->userIsParticipant($conversation, $userId)) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        $messages = $conversation->messages()
            ->with('sender:id,name,phone,profile_picture_path')
            ->orderByDesc('id')
            ->paginate(50);
        $messages->getCollection()->transform(fn (Message $message) => $this->messageData($message));

        return response()->json($messages);
    }

    public function send(Request $request, Conversation $conversation): JsonResponse
    {
        $userId = (int) $request->user()->id;

        if (! $this->userIsParticipant($conversation, $userId)) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        $validated = $request->validate([
            'body' => ['required_without:attachment', 'nullable', 'string', 'max:10000'],
            'attachment' => ['required_without:body', 'nullable', 'file', 'max:5120', 'mimes:jpg,jpeg,png,gif,webp,pdf,txt'],
            'type' => ['sometimes', 'in:text'],
        ]);

        $file = $request->file('attachment');
        $attachment = $file ? $this->validatedAttachment($file) : null;
        $storedPath = null;

        try {
            $message = DB::transaction(function () use ($conversation, $userId, $validated, $file, $attachment, &$storedPath) {
                $message = $conversation->messages()->create([
                    'sender_id' => $userId,
                    'body' => $validated['body'] ?? null,
                    'type' => $file ? 'file' : 'text',
                    'attachment_name' => $attachment['name'] ?? null,
                    'attachment_mime' => $attachment['mime'] ?? null,
                    'attachment_size' => $file?->getSize(),
                ]);

                if ($file) {
                    $storedPath = $file->store(
                        "messages/{$conversation->id}",
                        ['disk' => 'private']
                    );
                    $message->attachment_path = $storedPath;
                    $message->save();
                }

                $conversation->touch();

                return $message;
            });
        } catch (\Throwable $exception) {
            if ($storedPath) {
                Storage::disk('private')->delete($storedPath);
            }

            throw $exception;
        }

        $message->load('sender:id,name,phone,profile_picture_path');

        return response()->json($this->messageData($message), 201);
    }

    public function downloadAttachment(Request $request, Conversation $conversation, Message $message)
    {
        $userId = (int) $request->user()->id;

        if (! $this->userIsParticipant($conversation, $userId)
            || (int) $message->conversation_id !== (int) $conversation->id
            || ! $message->attachment_path) {
            return response()->json(['message' => 'Attachment not found.'], 404);
        }

        $extension = pathinfo($message->attachment_name, PATHINFO_EXTENSION);
        $filename = 'attachment-'.$message->id.($extension ? '.'.$extension : '');

        return Storage::disk('private')->download($message->attachment_path, $filename, [
            'Content-Type' => $message->attachment_mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function markDelivered(Request $request, Conversation $conversation): JsonResponse
    {
        return $this->updateReceipt($request, $conversation, 'delivered_at');
    }

    public function markRead(Request $request, Conversation $conversation): JsonResponse
    {
        return $this->updateReceipt($request, $conversation, 'read_at');
    }

    private function updateReceipt(Request $request, Conversation $conversation, string $column): JsonResponse
    {
        $userId = (int) $request->user()->id;

        if (! $this->userIsParticipant($conversation, $userId)) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        if ($column === 'read_at') {
            [$updated, $now] = DB::transaction(function () use ($conversation, $userId) {
                $now = now();
                $incoming = $conversation->messages()->where('sender_id', '!=', $userId);
                $incoming->whereNull('read_at')->whereNull('delivered_at')->update([
                    'delivered_at' => $now,
                    'updated_at' => $now,
                ]);

                $updated = $conversation->messages()
                    ->where('sender_id', '!=', $userId)
                    ->whereNull('read_at')
                    ->update(['read_at' => $now, 'updated_at' => $now]);

                return [$updated, $now];
            });
        } else {
            $now = now();
            $updated = $conversation->messages()
                ->where('sender_id', '!=', $userId)
                ->whereNull($column)
                ->update([
                    $column => $now,
                    'updated_at' => $now,
                ]);
        }

        return response()->json([
            'updated_count' => $updated,
            $column => $now->toISOString(),
        ]);
    }

    private function conversationData(Conversation $conversation, int $userId): array
    {
        $conversationType = $conversation->type ?? 'direct';
        $otherUser = $conversationType === 'group'
            ? null
            : ((int) $conversation->low_user_id === $userId ? $conversation->highUser : $conversation->lowUser);
        $lastMessage = $conversation->messages->first();

        $payload = [
            'id' => $conversation->id,
            'type' => $conversationType,
            'friend' => $otherUser ? $this->publicUser($otherUser) : null,
            'last_message' => $lastMessage ? $this->messageData($lastMessage) : null,
            'unread_count' => $conversation->unread_count ?? 0,
            'created_at' => $conversation->created_at,
            'updated_at' => $conversation->updated_at,
        ];

        if ($conversationType === 'group') {
            $payload['name'] = $conversation->name;
            $payload['participants'] = $conversation->participants
                ->map(fn (User $user) => $this->publicUser($user))
                ->values()
                ->all();
            $payload['created_by_user_id'] = $conversation->created_by_user_id;
        }

        return $payload;
    }

    private function messageData(Message $message): array
    {
        return [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'sender' => [
                'id' => $message->sender->id,
                'name' => $message->sender->name,
                'avatar_url' => $message->sender->avatar_url,
            ],
            'body' => $message->body,
            'type' => $message->type,
            'attachment' => $message->attachment_path ? [
                'name' => $message->attachment_name,
                'mime' => $message->attachment_mime,
                'size' => $message->attachment_size,
            ] : null,
            'created_at' => $message->created_at,
            'delivered_at' => $message->delivered_at,
            'read_at' => $message->read_at,
        ];
    }

    private function userIsParticipant(Conversation $conversation, int $userId): bool
    {
        if (in_array($userId, [(int) $conversation->low_user_id, (int) $conversation->high_user_id], true)) {
            return true;
        }

        return $conversation->participants()->whereKey($userId)->exists();
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

    private function validatedAttachment(\Illuminate\Http\UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $mime = $file->getMimeType();
        $allowed = [
            'jpg' => ['image/jpeg', "\xFF\xD8\xFF"],
            'jpeg' => ['image/jpeg', "\xFF\xD8\xFF"],
            'png' => ['image/png', "\x89PNG\r\n\x1A\n"],
            'gif' => ['image/gif', null],
            'webp' => ['image/webp', null],
            'pdf' => ['application/pdf', '%PDF-'],
            'txt' => ['text/plain', null],
        ];

        $valid = isset($allowed[$extension]) && $mime === $allowed[$extension][0];
        $contents = file_get_contents($file->getRealPath());

        if ($contents === false) {
            throw ValidationException::withMessages([
                'attachment' => ['The attachment could not be read. Please try selecting it again.'],
            ]);
        }

        if ($valid) {
            if (in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
                $valid = str_starts_with($contents, $allowed[$extension][1]);
            } elseif ($extension === 'gif') {
                $valid = (str_starts_with($contents, 'GIF87a') || str_starts_with($contents, 'GIF89a'))
                    && $this->hasImageType($file, IMAGETYPE_GIF);
            } elseif ($extension === 'webp') {
                $valid = strlen($contents) >= 12
                    && substr($contents, 0, 4) === 'RIFF'
                    && substr($contents, 8, 4) === 'WEBP'
                    && $this->hasImageType($file, IMAGETYPE_WEBP);
            } elseif ($extension === 'pdf') {
                $valid = str_starts_with($contents, $allowed[$extension][1]);
            } elseif ($extension === 'txt') {
                $valid = ! str_contains($contents, "\0") && preg_match('//u', $contents) === 1;
            }

            if (in_array($extension, ['jpg', 'jpeg'], true)) {
                $valid = $valid && $this->hasImageType($file, IMAGETYPE_JPEG);
            } elseif ($extension === 'png') {
                $valid = $valid && $this->hasImageType($file, IMAGETYPE_PNG);
            }
        }

        if (! $valid) {
            throw ValidationException::withMessages([
                'attachment' => ['The attachment content does not match an allowed file type.'],
            ]);
        }

        $originalName = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $stem = preg_replace('/[^A-Za-z0-9_-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
        $stem = trim(substr($stem, 0, 240), '_-');

        return [
            'name' => ($stem ?: 'attachment').'.'.$extension,
            'mime' => $allowed[$extension][0],
        ];
    }

    private function hasImageType(\Illuminate\Http\UploadedFile $file, int $expectedType): bool
    {
        $image = @getimagesize($file->getRealPath());

        return $image !== false && $image[2] === $expectedType;
    }
}
