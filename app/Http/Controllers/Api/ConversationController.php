<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\FriendRequest;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ConversationController extends Controller
{
    private const MAX_VOICE_SECONDS = 600;

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
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('user_blocks')
                ->whereColumn('user_blocks.blocker_id', 'conversations.low_user_id')
                ->whereColumn('user_blocks.blocked_id', 'conversations.high_user_id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('user_blocks')
                ->whereColumn('user_blocks.blocker_id', 'conversations.high_user_id')
                ->whereColumn('user_blocks.blocked_id', 'conversations.low_user_id'))
            ->with([
                'lowUser:id,name,phone,profile_picture_path',
                'highUser:id,name,phone,profile_picture_path',
                'participants:id,name,phone,profile_picture_path',
                'messages' => fn ($query) => $query
                    ->whereDoesntHave('hiddenFromUsers', fn ($hiddenQuery) => $hiddenQuery->where('users.id', $userId))
                    ->with([
                        'sender:id,name,profile_picture_path',
                        'replyTo.sender:id,name,profile_picture_path',
                        'replyTo.hiddenFromUsers' => fn ($hiddenQuery) => $hiddenQuery->where('users.id', $userId),
                    ])
                    ->latest('id')
                    ->limit(1),
            ])
            ->withCount([
                'messages as unread_count' => fn ($query) => $query
                    ->where('sender_id', '!=', $userId)
                    ->whereNull('read_at')
                    ->whereNull('deleted_for_everyone_at')
                    ->whereDoesntHave('hiddenFromUsers', fn ($hiddenQuery) => $hiddenQuery->where('users.id', $userId)),
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
        if ($this->usersHaveBlock($userId, $friendId)) {
            return response()->json(['message' => 'You cannot start a conversation while either user has blocked the other.'], 403);
        }
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
            'messages' => fn ($query) => $query
                ->whereDoesntHave('hiddenFromUsers', fn ($hiddenQuery) => $hiddenQuery->where('users.id', $userId))
                ->with([
                    'sender:id,name,profile_picture_path',
                    'replyTo.sender:id,name,profile_picture_path',
                    'replyTo.hiddenFromUsers' => fn ($hiddenQuery) => $hiddenQuery->where('users.id', $userId),
                ])
                ->latest('id')
                ->limit(1),
        ])->loadCount([
            'messages as unread_count' => fn ($query) => $query
                ->where('sender_id', '!=', $userId)
                ->whereNull('read_at')
                ->whereNull('deleted_for_everyone_at')
                ->whereDoesntHave('hiddenFromUsers', fn ($hiddenQuery) => $hiddenQuery->where('users.id', $userId)),
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
            'messages' => fn ($query) => $query
                ->whereDoesntHave('hiddenFromUsers', fn ($hiddenQuery) => $hiddenQuery->where('users.id', $userId))
                ->with([
                    'sender:id,name,profile_picture_path',
                    'replyTo.sender:id,name,profile_picture_path',
                    'replyTo.hiddenFromUsers' => fn ($hiddenQuery) => $hiddenQuery->where('users.id', $userId),
                ])
                ->latest('id')
                ->limit(1),
        ])->loadCount([
            'messages as unread_count' => fn ($query) => $query
                ->where('sender_id', '!=', $userId)
                ->whereNull('read_at')
                ->whereNull('deleted_for_everyone_at')
                ->whereDoesntHave('hiddenFromUsers', fn ($hiddenQuery) => $hiddenQuery->where('users.id', $userId)),
        ]);

        return response()->json($this->conversationData($conversation, $userId), 201);
    }

    public function addMember(Request $request, Conversation $conversation): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);
        $creatorId = (int) $request->user()->id;
        $memberId = (int) $validated['user_id'];

        if ($conversation->type !== 'group' || (int) $conversation->created_by_user_id !== $creatorId) {
            return response()->json(['message' => 'Only the group creator can add members.'], 403);
        }
        if (! $this->userIsParticipant($conversation, $creatorId)) {
            return response()->json(['message' => 'You are not a member of this group.'], 403);
        }
        if ($memberId === $creatorId) {
            throw ValidationException::withMessages([
                'user_id' => ['You are already a member of this group.'],
            ]);
        }
        if ($this->userIsParticipant($conversation, $memberId)) {
            return response()->json(['message' => 'This user is already a member of the group.'], 409);
        }

        $isAcceptedFriend = FriendRequest::query()
            ->where('status', 'accepted')
            ->where(function ($query) use ($creatorId, $memberId) {
                $query->where('low_user_id', min($creatorId, $memberId))
                    ->where('high_user_id', max($creatorId, $memberId));
            })
            ->exists();
        if (! $isAcceptedFriend) {
            return response()->json(['message' => 'Only accepted friends can be added to a group.'], 403);
        }

        $conversation->participants()->syncWithoutDetaching([$memberId]);
        $member = User::query()->select(['id', 'name', 'phone', 'profile_picture_path'])->findOrFail($memberId);

        return response()->json([
            'message' => 'Member added to group.',
            'conversation_id' => $conversation->id,
            'member' => $this->publicUser($member),
        ], 201);
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

        if ((int) $requestingUserId !== (int) $conversation->created_by_user_id) {
            return response()->json(['message' => 'Only the group creator can remove members.'], 403);
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
        if ($conversation->type !== 'group' &&
            $this->usersHaveBlock((int) $conversation->low_user_id, (int) $conversation->high_user_id)) {
            return response()->json(['message' => 'This direct conversation is unavailable.'], 404);
        }

        if ($conversation->type === 'group') {
            return response()->json([
                'conversation_id' => $conversation->id,
                'peer_id' => null,
                'participant_ids' => $conversation->participants()->pluck('users.id')->map(fn ($id) => (int) $id)->all(),
            ]);
        }

        $peerId = $userId === (int) $conversation->low_user_id
            ? (int) $conversation->high_user_id
            : (int) $conversation->low_user_id;

        return response()->json([
            'conversation_id' => $conversation->id,
            'peer_id' => $peerId,
            'participant_ids' => [(int) $conversation->low_user_id, (int) $conversation->high_user_id],
        ]);
    }

    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $userId = (int) $request->user()->id;

        if (! $this->userIsParticipant($conversation, $userId)) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }
        if ($conversation->type !== 'group' &&
            $this->usersHaveBlock((int) $conversation->low_user_id, (int) $conversation->high_user_id)) {
            return response()->json(['message' => 'This direct conversation is unavailable.'], 404);
        }

        $messages = $conversation->messages()
            ->whereDoesntHave('hiddenFromUsers', fn ($query) => $query->where('users.id', $userId))
            ->with([
                'sender:id,name,phone,profile_picture_path',
                'replyTo.sender:id,name,profile_picture_path',
                'replyTo.hiddenFromUsers' => fn ($query) => $query->where('users.id', $userId),
            ])
            ->orderByDesc('id')
            ->paginate(50);
        $messages->getCollection()->transform(fn (Message $message) => $this->messageData($message, $userId));

        return response()->json($messages);
    }

    public function callHistory(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $callHistory = Message::query()
            ->where('type', 'call')
            ->whereHas('conversation', fn ($query) => $query
                ->where(fn ($directQuery) => $directQuery
                    ->where('type', 'direct')
                    ->where(fn ($participantQuery) => $participantQuery
                        ->where('low_user_id', $userId)
                        ->orWhere('high_user_id', $userId)))
                ->orWhere(fn ($groupQuery) => $groupQuery
                    ->where('type', 'group')
                    ->whereHas('participants', fn ($memberQuery) => $memberQuery->whereKey($userId))))
            ->with([
                'conversation:id,type,name,low_user_id,high_user_id',
                'conversation.lowUser:id,name,phone,profile_picture_path',
                'conversation.highUser:id,name,phone,profile_picture_path',
            ])
            ->orderByDesc('id')
            ->paginate(100);

        $callHistory->getCollection()->transform(function (Message $message) use ($userId) {
            $conversation = $message->conversation;
            $isGroup = $conversation->type === 'group';
            $otherUser = $isGroup
                ? null
                : ((int) $conversation->low_user_id === $userId
                    ? $conversation->highUser
                    : $conversation->lowUser);

            return [
                'id' => $message->id,
                'conversation_id' => $message->conversation_id,
                'contact' => $this->publicUser($otherUser),
                'group' => $isGroup ? [
                    'id' => $conversation->id,
                    'name' => $conversation->name,
                ] : null,
                'direction' => (int) $message->sender_id === $userId ? 'outgoing' : 'incoming',
                'status' => $message->call_status,
                'media_type' => $message->call_media_type,
                'duration_seconds' => $message->call_duration_seconds,
                'created_at' => $message->created_at,
            ];
        });

        return response()->json($callHistory);
    }

    public function recordCall(Request $request, Conversation $conversation): JsonResponse
    {
        $userId = (int) $request->user()->id;
        if (! $this->userIsParticipant($conversation, $userId)) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }
        if ($conversation->type !== 'group' &&
            $this->usersHaveBlock((int) $conversation->low_user_id, (int) $conversation->high_user_id)) {
            return response()->json(['message' => 'This direct conversation is unavailable.'], 404);
        }

        $validated = $request->validate([
            'call_id' => ['required', 'uuid'],
            'caller_id' => ['required', 'integer'],
            'status' => ['required', 'in:missed,completed'],
            'media_type' => ['required', 'in:audio,video'],
            'duration_seconds' => ['required', 'integer', 'min:0', 'max:86400'],
        ]);
        if (! $this->userIsParticipant($conversation, (int) $validated['caller_id'])) {
            throw ValidationException::withMessages([
                'caller_id' => ['The caller must be a participant in this conversation.'],
            ]);
        }

        $existing = Message::query()->where('call_id', $validated['call_id'])->first();
        if ($existing) {
            if ((int) $existing->conversation_id !== (int) $conversation->id) {
                return response()->json(['message' => 'This call record already belongs to another conversation.'], 409);
            }

            $existing->load('sender:id,name,phone,profile_picture_path');
            return response()->json($this->messageData($existing, $userId));
        }

        try {
            $message = DB::transaction(function () use ($conversation, $validated) {
                $message = $conversation->messages()->create([
                    'sender_id' => (int) $validated['caller_id'],
                    'type' => 'call',
                    'call_id' => $validated['call_id'],
                    'call_status' => $validated['status'],
                    'call_media_type' => $validated['media_type'],
                    'call_duration_seconds' => $validated['status'] === 'completed'
                        ? (int) $validated['duration_seconds']
                        : null,
                ]);
                $conversation->touch();

                return $message;
            });
        } catch (QueryException $exception) {
            $message = Message::query()->where('call_id', $validated['call_id'])->first();
            if (! $message) {
                throw $exception;
            }
            if ((int) $message->conversation_id !== (int) $conversation->id) {
                return response()->json(['message' => 'This call record already belongs to another conversation.'], 409);
            }
        }

        $message->load('sender:id,name,phone,profile_picture_path');

        return response()->json($this->messageData($message, $userId), 201);
    }

    public function send(Request $request, Conversation $conversation): JsonResponse
    {
        $userId = (int) $request->user()->id;

        if (! $this->userIsParticipant($conversation, $userId)) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }
        if ($conversation->type !== 'group' &&
            $this->usersHaveBlock((int) $conversation->low_user_id, (int) $conversation->high_user_id)) {
            return response()->json(['message' => 'This direct conversation is unavailable.'], 404);
        }

        $validated = $request->validate([
            'body' => ['required_without_all:attachment,voice', 'nullable', 'string', 'max:10000'],
            // The allowed types and their content checks live in attachmentTypes().
            'attachment' => ['required_without_all:body,voice', 'nullable', 'file', 'max:5120'],
            'voice' => ['nullable', 'file', 'max:5120', 'prohibits:attachment,body'],
            'voice_duration_seconds' => ['required_with:voice', 'nullable', 'integer', 'min:1', 'max:'.self::MAX_VOICE_SECONDS],
            'type' => ['sometimes', 'in:text,protected'],
            'reply_to_message_id' => ['sometimes', 'nullable', 'integer', 'exists:messages,id'],
        ]);
        $replyToId = $validated['reply_to_message_id'] ?? null;
        if ($replyToId !== null && (int) Message::query()->whereKey($replyToId)->value('conversation_id') !== (int) $conversation->id) {
            throw ValidationException::withMessages([
                'reply_to_message_id' => ['The replied-to message must belong to this conversation.'],
            ]);
        }

        $voice = $request->file('voice');
        $file = $voice ?? $request->file('attachment');
        $attachment = $voice
            ? $this->validatedVoice($voice)
            : ($file ? $this->validatedAttachment($file) : null);
        $type = $voice ? 'voice' : ($file ? 'file' : 'text');
        if (($validated['type'] ?? 'text') === 'protected') {
            if ($file) {
                throw ValidationException::withMessages([
                    'type' => ['Protected messages cannot include attachments or voice notes.'],
                ]);
            }
            $this->validatedProtectedEnvelope($validated['body'] ?? null);
            $type = 'protected';
        }
        $storedPath = null;

        try {
            $message = DB::transaction(function () use ($conversation, $userId, $validated, $file, $attachment, $type, $replyToId, &$storedPath) {
                $message = $conversation->messages()->create([
                    'sender_id' => $userId,
                    'reply_to_message_id' => $replyToId,
                    'body' => $type === 'voice' ? null : ($validated['body'] ?? null),
                    'type' => $type,
                    'attachment_name' => $attachment['name'] ?? null,
                    'attachment_mime' => $attachment['mime'] ?? null,
                    'attachment_size' => $file?->getSize(),
                    'voice_duration_seconds' => $type === 'voice'
                        ? (int) $validated['voice_duration_seconds']
                        : null,
                ]);

                if ($file) {
                    // Name the stored file from the validated extension, never from a
                    // content-sniffed guess (which could turn a .txt into a .php).
                    $storedPath = $file->storeAs(
                        "messages/{$conversation->id}",
                        Str::uuid().'.'.pathinfo($attachment['name'], PATHINFO_EXTENSION),
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

        $message->load([
            'sender:id,name,phone,profile_picture_path',
            'replyTo.sender:id,name,profile_picture_path',
            'replyTo.hiddenFromUsers' => fn ($query) => $query->where('users.id', $userId),
        ]);

        return response()->json($this->messageData($message, $userId), 201);
    }

    public function forward(Request $request, Conversation $conversation, Message $message): JsonResponse
    {
        $userId = (int) $request->user()->id;
        if (! $this->userIsParticipant($conversation, $userId)
            || ($conversation->type !== 'group' &&
                $this->usersHaveBlock((int) $conversation->low_user_id, (int) $conversation->high_user_id))
            || (int) $message->conversation_id !== (int) $conversation->id
            || $message->deleted_for_everyone_at
            || $message->hiddenFromUsers()->where('users.id', $userId)->exists()) {
            return response()->json(['message' => 'Message not found.'], 404);
        }

        $validated = $request->validate([
            'target_conversation_id' => ['required', 'integer', 'exists:conversations,id'],
        ]);
        $targetConversation = Conversation::findOrFail($validated['target_conversation_id']);
        if (! $this->userIsParticipant($targetConversation, $userId)) {
            return response()->json(['message' => 'Target conversation not found.'], 404);
        }
        if ($targetConversation->type !== 'group' &&
            $this->usersHaveBlock((int) $targetConversation->low_user_id, (int) $targetConversation->high_user_id)) {
            return response()->json(['message' => 'Target conversation not found.'], 404);
        }
        if ((int) $targetConversation->id === (int) $conversation->id) {
            throw ValidationException::withMessages([
                'target_conversation_id' => ['Choose a different conversation to forward this message.'],
            ]);
        }

        $copiedPath = null;
        try {
            $forwardedMessage = DB::transaction(function () use ($message, $targetConversation, $userId, &$copiedPath) {
                if ($message->attachment_path) {
                    $extension = pathinfo($message->attachment_name, PATHINFO_EXTENSION);
                    $copiedPath = "messages/{$targetConversation->id}/forwarded-".Str::uuid()
                        .($extension ? ".{$extension}" : '');
                    if (! Storage::disk('private')->copy($message->attachment_path, $copiedPath)) {
                        throw new \RuntimeException('The original attachment could not be copied.');
                    }
                }

                $forwardedMessage = $targetConversation->messages()->create([
                    'sender_id' => $userId,
                    'body' => $message->body,
                    'type' => $message->type,
                    'attachment_path' => $copiedPath,
                    'attachment_name' => $message->attachment_name,
                    'attachment_mime' => $message->attachment_mime,
                    'attachment_size' => $message->attachment_size,
                    'voice_duration_seconds' => $message->voice_duration_seconds,
                    'is_forwarded' => true,
                ]);
                $targetConversation->touch();

                return $forwardedMessage;
            });
        } catch (\Throwable $exception) {
            if ($copiedPath) {
                Storage::disk('private')->delete($copiedPath);
            }

            throw $exception;
        }

        $forwardedMessage->load('sender:id,name,phone,profile_picture_path');

        return response()->json($this->messageData($forwardedMessage, $userId), 201);
    }

    public function deleteMessage(Request $request, Conversation $conversation, Message $message): JsonResponse
    {
        $userId = (int) $request->user()->id;
        if (! $this->userIsParticipant($conversation, $userId)
            || (int) $message->conversation_id !== (int) $conversation->id) {
            return response()->json(['message' => 'Message not found.'], 404);
        }

        $validated = $request->validate([
            'scope' => ['required', 'in:me,everyone'],
        ]);

        if ($validated['scope'] === 'me') {
            $message->hiddenFromUsers()->syncWithoutDetaching([$userId]);

            return response()->json(['message' => 'Message deleted for you.']);
        }

        if ((int) $message->sender_id !== $userId) {
            return response()->json(['message' => 'You can only delete your own messages for everyone.'], 403);
        }

        if ($message->deleted_for_everyone_at) {
            return response()->json(['message' => 'Message was already deleted for everyone.']);
        }

        $attachmentPath = $message->attachment_path;
        DB::transaction(function () use ($message) {
            $message->forceFill([
                'body' => null,
                'attachment_path' => null,
                'attachment_name' => null,
                'attachment_mime' => null,
                'attachment_size' => null,
                'voice_duration_seconds' => null,
                'deleted_for_everyone_at' => now(),
            ])->save();
        });

        if ($attachmentPath && ! Storage::disk('private')->delete($attachmentPath)) {
            return response()->json([
                'message' => 'The message was deleted, but its attachment could not be removed from storage.',
            ], 500);
        }

        return response()->json(['message' => 'Message deleted for everyone.']);
    }

    public function downloadAttachment(Request $request, Conversation $conversation, Message $message)
    {
        $userId = (int) $request->user()->id;

        if (! $this->userIsParticipant($conversation, $userId)
            || (int) $message->conversation_id !== (int) $conversation->id
            || ! $message->attachment_path
            || ($conversation->type !== 'group' &&
                $this->usersHaveBlock((int) $conversation->low_user_id, (int) $conversation->high_user_id))) {
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
        if ($conversation->type !== 'group' &&
            $this->usersHaveBlock((int) $conversation->low_user_id, (int) $conversation->high_user_id)) {
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
            'last_message' => $lastMessage ? $this->messageData($lastMessage, $userId) : null,
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
            $payload['created_by'] = $conversation->created_by_user_id;
        }

        return $payload;
    }

    private function messageData(Message $message, ?int $userId = null): array
    {
        $reply = $message->replyTo;
        $replyIsHidden = $reply
            && $userId !== null
            && $reply->relationLoaded('hiddenFromUsers')
            && $reply->hiddenFromUsers->isNotEmpty();

        return [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'sender' => [
                'id' => $message->sender->id,
                'name' => $message->sender->name,
                'avatar_url' => $message->sender->avatar_url,
            ],
            'body' => $message->type === 'protected' ? null : $message->body,
            'type' => $message->type,
            'protected' => $message->type === 'protected' && $message->body
                ? json_decode($message->body, true)
                : null,
            'reply_to' => $reply
                ? ($replyIsHidden ? [
                    'id' => $reply->id,
                    'unavailable' => true,
                ] : [
                    'id' => $reply->id,
                    'sender' => [
                        'id' => $reply->sender->id,
                        'name' => $reply->sender->name,
                    ],
                    'body' => $reply->type === 'protected' ? null : $reply->body,
                    'type' => $reply->type,
                    'attachment' => $reply->attachment_path ? ['name' => $reply->attachment_name] : null,
                    'deleted_for_everyone_at' => $reply->deleted_for_everyone_at,
                    'unavailable' => false,
                ])
                : null,
            'deleted_for_everyone_at' => $message->deleted_for_everyone_at,
            'attachment' => $message->attachment_path ? [
                'name' => $message->attachment_name,
                'mime' => $message->attachment_mime,
                'size' => $message->attachment_size,
            ] : null,
            'is_forwarded' => (bool) $message->is_forwarded,
            'voice' => $message->type === 'voice' && $message->attachment_path ? [
                'duration_seconds' => (int) $message->voice_duration_seconds,
            ] : null,
            'call' => $message->type === 'call' ? [
                'status' => $message->call_status,
                'media_type' => $message->call_media_type,
                'duration_seconds' => $message->call_duration_seconds,
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
        $types = $this->attachmentTypes();

        if (! isset($types[$extension])) {
            throw ValidationException::withMessages([
                'attachment' => ['This file type is not supported. Send an image, PDF, text, Office or OpenDocument file, CSV, or ZIP.'],
            ]);
        }

        $contents = file_get_contents($file->getRealPath());
        if ($contents === false) {
            throw ValidationException::withMessages([
                'attachment' => ['The attachment could not be read. Please try selecting it again.'],
            ]);
        }

        [$mime, $matchesContent] = $types[$extension];
        if (! $matchesContent($contents, $file)) {
            throw ValidationException::withMessages([
                'attachment' => ['The attachment content does not match an allowed file type.'],
            ]);
        }

        $originalName = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $stem = preg_replace('/[^A-Za-z0-9_-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
        $stem = trim(substr($stem, 0, 240), '_-');

        return [
            'name' => ($stem ?: 'attachment').'.'.$extension,
            'mime' => $mime,
        ];
    }

    /**
     * Allowed attachment types, matching the desktop app's file picker. Each
     * entry is [MIME type served on download, content check]. Files are checked
     * by their structure rather than libmagic, whose labels for Office, HEIC,
     * and CSV files vary between servers.
     *
     * @return array<string, array{0: string, 1: \Closure(string, \Illuminate\Http\UploadedFile): bool}>
     */
    private function attachmentTypes(): array
    {
        $jpeg = ['image/jpeg', fn (string $c, $f) => str_starts_with($c, "\xFF\xD8\xFF")
            && $this->hasImageType($f, IMAGETYPE_JPEG)];
        $bmp = ['image/bmp', fn (string $c, $f) => str_starts_with($c, 'BM')
            && $this->hasImageType($f, IMAGETYPE_BMP)];
        $tiff = ['image/tiff', fn (string $c, $f) => (str_starts_with($c, "II*\0") || str_starts_with($c, "MM\0*"))
            && ($this->hasImageType($f, IMAGETYPE_TIFF_II) || $this->hasImageType($f, IMAGETYPE_TIFF_MM))];
        $heif = fn (string $mime) => [$mime, fn (string $c) => $this->hasIsoBrand(
            $c, ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'hevm', 'hevs', 'mif1', 'msf1']
        )];
        // Legacy Office files (doc, xls, ppt and their templates) are OLE2 compound documents.
        $ole = fn (string $mime) => [$mime, fn (string $c) => str_starts_with($c, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")];
        // Office Open XML files are ZIP packages with a content-types manifest and a part folder.
        $ooxml = fn (string $mime, string $folder) => [$mime, fn (string $c, $f) => $this->isOfficeOpenXml($c, $f, $folder)];
        // OpenDocument files are ZIP packages whose "mimetype" entry names the document type.
        $odf = fn (string $mime) => [$mime, fn (string $c, $f) => $this->isOpenDocument($c, $f, $mime)];
        $text = fn (string $mime) => [$mime, fn (string $c) => ! str_contains($c, "\0") && preg_match('//u', $c) === 1];

        return [
            'jpg' => $jpeg,
            'jpeg' => $jpeg,
            'jpe' => $jpeg,
            'jfif' => $jpeg,
            'png' => ['image/png', fn (string $c, $f) => str_starts_with($c, "\x89PNG\r\n\x1A\n")
                && $this->hasImageType($f, IMAGETYPE_PNG)],
            'gif' => ['image/gif', fn (string $c, $f) => (str_starts_with($c, 'GIF87a') || str_starts_with($c, 'GIF89a'))
                && $this->hasImageType($f, IMAGETYPE_GIF)],
            'webp' => ['image/webp', fn (string $c, $f) => substr($c, 0, 4) === 'RIFF' && substr($c, 8, 4) === 'WEBP'
                && $this->hasImageType($f, IMAGETYPE_WEBP)],
            'bmp' => $bmp,
            'dib' => $bmp,
            'tif' => $tiff,
            'tiff' => $tiff,
            'ico' => ['image/vnd.microsoft.icon', fn (string $c, $f) => str_starts_with($c, "\0\0\1\0")
                && $this->hasImageType($f, IMAGETYPE_ICO)],
            'avif' => ['image/avif', fn (string $c) => $this->hasIsoBrand($c, ['avif', 'avis'])],
            'heic' => $heif('image/heic'),
            'heif' => $heif('image/heif'),

            'pdf' => ['application/pdf', fn (string $c) => str_starts_with($c, '%PDF-')],
            'txt' => $text('text/plain'),
            'csv' => $text('text/csv'),
            'rtf' => ['application/rtf', fn (string $c) => str_starts_with($c, '{\rtf')],

            'doc' => $ole('application/msword'),
            'dot' => $ole('application/msword'),
            'xls' => $ole('application/vnd.ms-excel'),
            'xlt' => $ole('application/vnd.ms-excel'),
            'ppt' => $ole('application/vnd.ms-powerpoint'),
            'pot' => $ole('application/vnd.ms-powerpoint'),
            'pps' => $ole('application/vnd.ms-powerpoint'),

            'docx' => $ooxml('application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'word/'),
            'docm' => $ooxml('application/vnd.ms-word.document.macroEnabled.12', 'word/'),
            'dotx' => $ooxml('application/vnd.openxmlformats-officedocument.wordprocessingml.template', 'word/'),
            'dotm' => $ooxml('application/vnd.ms-word.template.macroEnabled.12', 'word/'),
            'xlsx' => $ooxml('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xl/'),
            'xlsm' => $ooxml('application/vnd.ms-excel.sheet.macroEnabled.12', 'xl/'),
            'xlsb' => $ooxml('application/vnd.ms-excel.sheet.binary.macroEnabled.12', 'xl/'),
            'xltx' => $ooxml('application/vnd.openxmlformats-officedocument.spreadsheetml.template', 'xl/'),
            'xltm' => $ooxml('application/vnd.ms-excel.template.macroEnabled.12', 'xl/'),
            'pptx' => $ooxml('application/vnd.openxmlformats-officedocument.presentationml.presentation', 'ppt/'),
            'pptm' => $ooxml('application/vnd.ms-powerpoint.presentation.macroEnabled.12', 'ppt/'),
            'ppsx' => $ooxml('application/vnd.openxmlformats-officedocument.presentationml.slideshow', 'ppt/'),

            'odt' => $odf('application/vnd.oasis.opendocument.text'),
            'odp' => $odf('application/vnd.oasis.opendocument.presentation'),
            'ods' => $odf('application/vnd.oasis.opendocument.spreadsheet'),

            'zip' => ['application/zip', fn (string $c, $f) => $this->isZip($c, $f)],
        ];
    }

    /** Checks an ISO base media file (AVIF/HEIF) for one of the given major or compatible brands. */
    private function hasIsoBrand(string $contents, array $brands): bool
    {
        if (strlen($contents) < 16 || substr($contents, 4, 4) !== 'ftyp') {
            return false;
        }
        $boxSize = min(unpack('N', substr($contents, 0, 4))[1], 256, strlen($contents));
        $candidates = [substr($contents, 8, 4)];
        for ($offset = 16; $offset + 4 <= $boxSize; $offset += 4) {
            $candidates[] = substr($contents, $offset, 4);
        }

        return array_intersect($candidates, $brands) !== [];
    }

    private function isZip(string $contents, \Illuminate\Http\UploadedFile $file): bool
    {
        if (! str_starts_with($contents, "PK\x03\x04") && ! str_starts_with($contents, "PK\x05\x06")) {
            return false;
        }

        return $this->withZip($file, fn () => true) ?? true;
    }

    private function isOfficeOpenXml(string $contents, \Illuminate\Http\UploadedFile $file, string $folder): bool
    {
        if (! str_starts_with($contents, "PK\x03\x04")) {
            return false;
        }

        // Without the zip extension, fall back to the ZIP signature check above.
        return $this->withZip($file, function (\ZipArchive $zip) use ($folder) {
            if ($zip->locateName('[Content_Types].xml') === false) {
                return false;
            }
            for ($index = 0; $index < $zip->numFiles; $index++) {
                if (str_starts_with((string) $zip->getNameIndex($index), $folder)) {
                    return true;
                }
            }

            return false;
        }) ?? true;
    }

    private function isOpenDocument(string $contents, \Illuminate\Http\UploadedFile $file, string $mime): bool
    {
        if (! str_starts_with($contents, "PK\x03\x04")) {
            return false;
        }

        return $this->withZip($file, fn (\ZipArchive $zip) => $zip->getFromName('mimetype') === $mime)
            ?? str_contains(substr($contents, 0, 200), $mime);
    }

    /**
     * Runs a check against the uploaded file opened as a ZIP archive. Returns
     * false for an unreadable archive, or null when the zip extension is missing.
     */
    private function withZip(\Illuminate\Http\UploadedFile $file, \Closure $check): ?bool
    {
        if (! class_exists(\ZipArchive::class)) {
            return null;
        }
        $zip = new \ZipArchive();
        if ($zip->open($file->getRealPath(), \ZipArchive::RDONLY) !== true) {
            return false;
        }
        try {
            return (bool) $check($zip);
        } finally {
            $zip->close();
        }
    }

    /**
     * Protected messages are encrypted on the sender's device with a key derived
     * from a one-time password the server never sees. The server only checks that
     * the body is a well-formed envelope, and stores it (itself encrypted at rest).
     */
    private function validatedProtectedEnvelope(?string $body): void
    {
        $envelope = is_string($body) ? json_decode($body, true) : null;
        $decodedLength = fn ($value) => is_string($value) && preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $value)
            ? strlen((string) base64_decode($value, true))
            : -1;

        $valid = is_array($envelope)
            && array_keys($envelope) === ['v', 'alg', 'kdf', 'iter', 'salt', 'iv', 'ct']
            && $envelope['v'] === 1
            && $envelope['alg'] === 'AES-256-GCM'
            && $envelope['kdf'] === 'PBKDF2-SHA256'
            && is_int($envelope['iter']) && $envelope['iter'] >= 100000 && $envelope['iter'] <= 5000000
            && $decodedLength($envelope['salt']) === 16
            && $decodedLength($envelope['iv']) === 12
            && $decodedLength($envelope['ct']) > 16;

        if (! $valid) {
            throw ValidationException::withMessages([
                'body' => ['The protected message is not a valid encrypted envelope.'],
            ]);
        }
    }

    /**
     * Voice notes are identified by their container signature, not the client
     * filename, and are stored under a fixed name so they can never be confused
     * with an arbitrary uploaded file.
     */
    private function validatedVoice(\Illuminate\Http\UploadedFile $file): array
    {
        $header = file_get_contents($file->getRealPath(), false, null, 0, 64);

        if ($header === false) {
            throw ValidationException::withMessages([
                'voice' => ['The voice message could not be read. Please record it again.'],
            ]);
        }

        // Each format is checked by its container signature plus a structural
        // marker, rather than by libmagic, whose labels for audio-only WebM vary.
        $formats = [
            // EBML header whose DocType element is "webm".
            'webm' => ['audio/webm', str_starts_with($header, "\x1A\x45\xDF\xA3")
                && str_contains($header, "\x42\x82\x84webm")],
            // Ogg page carrying an Opus or Vorbis identification header.
            'ogg' => ['audio/ogg', str_starts_with($header, 'OggS')
                && (str_contains($header, 'OpusHead') || str_contains($header, "\x01vorbis"))],
            // ISO base media file with an audio-capable major brand.
            'm4a' => ['audio/mp4', substr($header, 4, 4) === 'ftyp'
                && in_array(substr($header, 8, 4), ['M4A ', 'mp42', 'isom', 'iso5', 'dash'], true)],
        ];

        foreach ($formats as $extension => [$mime, $matches]) {
            if ($matches) {
                return [
                    'name' => 'voice-message.'.$extension,
                    'mime' => $mime,
                ];
            }
        }

        throw ValidationException::withMessages([
            'voice' => ['The voice message must be a WebM, Ogg, or M4A audio recording.'],
        ]);
    }

    private function hasImageType(\Illuminate\Http\UploadedFile $file, int $expectedType): bool
    {
        $image = @getimagesize($file->getRealPath());

        return $image !== false && $image[2] === $expectedType;
    }
}
