<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\FriendRequest;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ChatApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_and_log_in_with_phone(): void
    {
        $registration = $this->postJson('/api/auth/register', [
            'name' => 'Alex User',
            'phone' => '+15551234567',
            'email' => 'alex@example.com',
            'password' => 'secure-password',
            'dob' => '1995-04-12',
            'device_name' => 'test-device',
        ]);

        $registration
            ->assertCreated()
            ->assertJsonPath('user.phone', '+15551234567')
            ->assertJsonPath('user.dob', '1995-04-12')
            ->assertJsonStructure(['user' => ['id', 'name', 'phone', 'email', 'dob'], 'token']);

        $this->postJson('/api/auth/login', [
            'phone' => '+15551234567',
            'password' => 'secure-password',
        ])->assertOk()->assertJsonStructure(['user', 'token']);

        $this->postJson('/api/auth/login', [
            'phone' => '+15551234567',
            'password' => 'incorrect-password',
        ])->assertUnprocessable();
    }

    public function test_group_can_be_created_and_used_for_group_chat(): void
    {
        $owner = User::factory()->create(['phone' => '+15550000001']);
        $friendOne = User::factory()->create(['phone' => '+15550000002']);
        $friendTwo = User::factory()->create(['phone' => '+15550000003']);

        foreach ([[$owner, $friendOne], [$owner, $friendTwo]] as [$user, $friend]) {
            FriendRequest::create([
                'low_user_id' => min($user->id, $friend->id),
                'high_user_id' => max($user->id, $friend->id),
                'sender_id' => $user->id,
                'status' => 'accepted',
            ]);
        }

        Sanctum::actingAs($owner);
        $groupResponse = $this->postJson('/api/groups', [
            'name' => 'Launch team',
            'friend_ids' => [$friendOne->id, $friendTwo->id],
        ]);

        $groupResponse
            ->assertCreated()
            ->assertJsonPath('type', 'group')
            ->assertJsonPath('name', 'Launch team')
            ->assertJsonPath('created_by', $owner->id)
            ->assertJsonPath('created_by_user_id', $owner->id)
            ->assertJsonCount(3, 'participants');

        $conversationId = $groupResponse->json('id');

        $this->getJson("/api/conversations/{$conversationId}/socket-access")
            ->assertOk()
            ->assertJsonCount(3, 'participant_ids');

        $this->getJson('/api/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'group')
            ->assertJsonPath('data.0.name', 'Launch team');

        $messageResponse = $this->postJson("/api/conversations/{$conversationId}/messages", [
            'body' => 'Group hello everyone',
        ]);

        $messageResponse->assertCreated()->assertJsonPath('body', 'Group hello everyone');

        Sanctum::actingAs($friendTwo);
        $this->getJson("/api/conversations/{$conversationId}/messages")
            ->assertOk()
            ->assertJsonPath('data.0.body', 'Group hello everyone');
    }

    public function test_call_logs_are_saved_in_conversation_history_and_are_idempotent(): void
    {
        $caller = User::factory()->create(['phone' => '+15550000031']);
        $callee = User::factory()->create(['phone' => '+15550000032']);
        $conversation = Conversation::create([
            'low_user_id' => min($caller->id, $callee->id),
            'high_user_id' => max($caller->id, $callee->id),
        ]);
        $missedCall = [
            'call_id' => '11111111-1111-4111-8111-111111111111',
            'caller_id' => $caller->id,
            'status' => 'missed',
            'media_type' => 'audio',
            'duration_seconds' => 0,
        ];

        Sanctum::actingAs($caller);
        $missedResponse = $this->postJson("/api/conversations/{$conversation->id}/calls", $missedCall)
            ->assertCreated()
            ->assertJsonPath('type', 'call')
            ->assertJsonPath('call.status', 'missed')
            ->assertJsonPath('call.media_type', 'audio');
        $missedMessageId = $missedResponse->json('id');

        $completedCall = [
            'call_id' => '22222222-2222-4222-8222-222222222222',
            'caller_id' => $caller->id,
            'status' => 'completed',
            'media_type' => 'video',
            'duration_seconds' => 125,
        ];
        $this->postJson("/api/conversations/{$conversation->id}/calls", $completedCall)
            ->assertCreated()
            ->assertJsonPath('call.status', 'completed')
            ->assertJsonPath('call.duration_seconds', 125);

        Sanctum::actingAs($callee);
        $this->postJson("/api/conversations/{$conversation->id}/calls", $missedCall)
            ->assertOk()
            ->assertJsonPath('id', $missedMessageId);
        $this->getJson("/api/conversations/{$conversation->id}/messages")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.call.status', 'completed')
            ->assertJsonPath('data.0.call.duration_seconds', 125)
            ->assertJsonPath('data.1.call.status', 'missed');
        $this->getJson('/api/call-history')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.contact.id', $caller->id)
            ->assertJsonPath('data.0.direction', 'incoming')
            ->assertJsonPath('data.0.status', 'completed')
            ->assertJsonPath('data.0.media_type', 'video')
            ->assertJsonPath('data.0.duration_seconds', 125)
            ->assertJsonPath('data.1.status', 'missed')
            ->assertJsonPath('data.1.media_type', 'audio');

        Sanctum::actingAs($caller);
        $this->getJson('/api/call-history')
            ->assertOk()
            ->assertJsonPath('data.0.contact.id', $callee->id)
            ->assertJsonPath('data.0.direction', 'outgoing');
    }

    public function test_authenticated_user_can_upload_a_profile_picture(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->post('/api/user/profile-picture', [
            'profile_picture' => UploadedFile::fake()->image('profile.png'),
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Profile picture updated.')
            ->assertJsonPath('user.id', $user->id);

        $user->refresh();
        $this->assertNotEmpty($user->profile_picture_path);
        Storage::disk('public')->assertExists($user->profile_picture_path);
        $response->assertJsonPath('user.avatar_url', $user->avatar_url);
        $previousAvatarUrl = $user->avatar_url;

        $replacementResponse = $this->post('/api/user/profile-picture', [
            'profile_picture' => UploadedFile::fake()->image('replacement.png'),
        ]);
        $replacementResponse->assertOk();
        $user->refresh();
        $this->assertNotSame($previousAvatarUrl, $user->avatar_url);
        $replacementResponse->assertJsonPath('user.avatar_url', $user->avatar_url);
        Storage::disk('public')->assertExists($user->profile_picture_path);

        $this->get($user->avatar_url)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_only_creator_can_remove_members_and_other_members_can_leave(): void
    {
        $owner = User::factory()->create(['phone' => '+15550000011']);
        $memberOne = User::factory()->create(['phone' => '+15550000012']);
        $memberTwo = User::factory()->create(['phone' => '+15550000013']);
        $memberThree = User::factory()->create(['phone' => '+15550000014']);

        foreach ([[$owner, $memberOne], [$owner, $memberTwo], [$owner, $memberThree], [$memberOne, $memberTwo]] as [$user, $friend]) {
            FriendRequest::create([
                'low_user_id' => min($user->id, $friend->id),
                'high_user_id' => max($user->id, $friend->id),
                'sender_id' => $user->id,
                'status' => 'accepted',
            ]);
        }

        Sanctum::actingAs($owner);
        $group = $this->postJson('/api/groups', [
            'name' => 'Support group',
            'friend_ids' => [$memberOne->id, $memberTwo->id],
        ])->assertCreated()->json();

        Sanctum::actingAs($memberTwo);
        $this->deleteJson("/api/groups/{$group['id']}/members/{$memberOne->id}")
            ->assertForbidden();
        $this->deleteJson("/api/groups/{$group['id']}/members/{$memberTwo->id}")
            ->assertForbidden();
        $this->postJson("/api/groups/{$group['id']}/members", ['user_id' => $memberThree->id])
            ->assertForbidden();
        $this->postJson("/api/groups/{$group['id']}/leave")
            ->assertOk()
            ->assertJsonPath('message', 'You left the group.');

        $this->assertDatabaseMissing('conversation_users', [
            'conversation_id' => $group['id'],
            'user_id' => $memberTwo->id,
        ]);

        Sanctum::actingAs($owner);
        $this->postJson("/api/groups/{$group['id']}/leave")
            ->assertForbidden();
        $this->deleteJson("/api/groups/{$group['id']}/members/{$owner->id}")
            ->assertForbidden();
        $this->deleteJson("/api/groups/{$group['id']}/members/{$memberOne->id}")
            ->assertOk()
            ->assertJsonPath('removed_user_id', $memberOne->id);
        $this->postJson("/api/groups/{$group['id']}/members", ['user_id' => $memberThree->id])
            ->assertCreated()
            ->assertJsonPath('member.id', $memberThree->id);
        $this->postJson("/api/groups/{$group['id']}/members", ['user_id' => $memberThree->id])
            ->assertConflict();

        $this->assertDatabaseMissing('conversation_users', [
            'conversation_id' => $group['id'],
            'user_id' => $memberOne->id,
        ]);
        $this->assertDatabaseHas('conversation_users', [
            'conversation_id' => $group['id'],
            'user_id' => $memberThree->id,
        ]);

        Sanctum::actingAs($memberOne);
        $this->getJson("/api/conversations/{$group['id']}/messages")
            ->assertNotFound();
    }

    public function test_unfriending_preserves_chat_history_for_when_they_add_each_other_again(): void
    {
        $first = User::factory()->create(['phone' => '+15550000021']);
        $second = User::factory()->create(['phone' => '+15550000022']);

        FriendRequest::create([
            'low_user_id' => min($first->id, $second->id),
            'high_user_id' => max($first->id, $second->id),
            'sender_id' => $first->id,
            'status' => 'accepted',
        ]);

        $conversation = Conversation::create([
            'low_user_id' => min($first->id, $second->id),
            'high_user_id' => max($first->id, $second->id),
            'type' => 'direct',
        ]);

        Sanctum::actingAs($first);
        $messageId = $this->postJson("/api/conversations/{$conversation->id}/messages", [
            'body' => 'Keep this conversation history.',
        ])->assertCreated()->json('id');

        $this->deleteJson("/api/friends/{$second->id}")
            ->assertOk()
            ->assertJsonPath('user_id', $second->id);

        $this->assertDatabaseMissing('friend_requests', [
            'low_user_id' => min($first->id, $second->id),
            'high_user_id' => max($first->id, $second->id),
        ]);
        $this->assertDatabaseHas('conversations', ['id' => $conversation->id]);
        $this->assertDatabaseHas('messages', [
            'id' => $messageId,
            'conversation_id' => $conversation->id,
        ]);

        $requestId = $this->postJson('/api/friends/requests', [
            'phone' => $second->phone,
        ])->assertCreated()->json('id');
        Sanctum::actingAs($second);
        $this->postJson("/api/friends/requests/{$requestId}/accept")
            ->assertOk()
            ->assertJsonPath('status', 'accepted');

        Sanctum::actingAs($first);
        $restoredConversation = $this->postJson('/api/conversations', [
            'friend_id' => $second->id,
        ])->assertCreated();
        $this->assertSame($conversation->id, $restoredConversation->json('id'));
        $this->getJson("/api/conversations/{$conversation->id}/messages")
            ->assertOk()
            ->assertJsonPath('data.0.id', $messageId)
            ->assertJsonPath('data.0.body', 'Keep this conversation history.');
    }

    public function test_friends_can_accept_requests_and_send_messages_with_receipts(): void
    {
        $sender = User::factory()->create(['phone' => '+15551234567']);
        $recipient = User::factory()->create(['phone' => '+15557654321']);

        Sanctum::actingAs($sender);
        $requestResponse = $this->postJson('/api/friends/requests', [
            'phone' => $recipient->phone,
        ])->assertCreated()->assertJsonPath('status', 'pending');
        $requestId = $requestResponse->json('id');

        Sanctum::actingAs($recipient);
        $this->getJson('/api/friends/requests')
            ->assertOk()
            ->assertJsonPath('data.0.user.id', $sender->id);
        $this->postJson("/api/friends/requests/{$requestId}/accept")
            ->assertOk()
            ->assertJsonPath('status', 'accepted');

        $conversationId = $this->postJson('/api/conversations', [
            'friend_id' => $sender->id,
        ])->assertCreated()->json('id');

        Sanctum::actingAs($sender);
        $messageId = $this->postJson("/api/conversations/{$conversationId}/messages", [
            'body' => 'Hello there',
        ])->assertCreated()->assertJsonPath('body', 'Hello there')->json('id');

        Sanctum::actingAs($recipient);
        $this->getJson('/api/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.unread_count', 1);
        $this->postJson("/api/conversations/{$conversationId}/delivered")
            ->assertOk()
            ->assertJsonPath('updated_count', 1);

        Sanctum::actingAs($sender);
        $secondMessageId = $this->postJson("/api/conversations/{$conversationId}/messages", [
            'body' => 'One more message',
        ])->assertCreated()->json('id');

        Sanctum::actingAs($recipient);
        $this->postJson("/api/conversations/{$conversationId}/read")
            ->assertOk()
            ->assertJsonPath('updated_count', 2);

        foreach ([$messageId, $secondMessageId] as $id) {
            $message = Message::findOrFail($id);
            $this->assertNotNull($message->delivered_at);
            $this->assertNotNull($message->read_at);
        }
    }

    public function test_adding_an_existing_friend_returns_an_already_added_message(): void
    {
        [$sender, $recipient] = $this->makeConversation();
        FriendRequest::create([
            'low_user_id' => min($sender->id, $recipient->id),
            'high_user_id' => max($sender->id, $recipient->id),
            'sender_id' => $sender->id,
            'status' => 'accepted',
        ]);

        Sanctum::actingAs($sender);
        $this->postJson('/api/friends/requests', ['phone' => $recipient->phone])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone')
            ->assertJsonPath('errors.phone.0', 'This user is already your friend.');
    }

    public function test_blocking_hides_friendship_and_makes_direct_chat_read_only_until_unblocked(): void
    {
        [$blocker, $blocked, $conversation] = $this->makeConversation();
        FriendRequest::create([
            'low_user_id' => min($blocker->id, $blocked->id),
            'high_user_id' => max($blocker->id, $blocked->id),
            'sender_id' => $blocker->id,
            'status' => 'accepted',
        ]);

        Sanctum::actingAs($blocker);
        $this->postJson("/api/users/{$blocked->id}/block")
            ->assertOk()
            ->assertJsonPath('message', 'User blocked.');
        $this->getJson('/api/blocks')
            ->assertOk()
            ->assertJsonPath('data.0.id', $blocked->id);
        $this->getJson('/api/friends')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        // Both people can still read the old chat, which is marked as blocked.
        $this->getJson('/api/conversations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.blocked', 'by_me');
        Sanctum::actingAs($blocked);
        $this->getJson('/api/friends')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson('/api/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.blocked', 'by_them');
        $this->getJson("/api/conversations/{$conversation->id}/messages")
            ->assertOk();
        Sanctum::actingAs($blocker);
        $this->postJson('/api/friends/requests', ['phone' => $blocked->phone])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');

        // ...but nobody can send, edit or pin while blocked.
        Sanctum::actingAs($blocked);
        $this->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'Blocked message'])
            ->assertNotFound();
        Sanctum::actingAs($blocker);
        $this->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'Blocked message'])
            ->assertNotFound();

        Sanctum::actingAs($blocker);
        $this->deleteJson("/api/users/{$blocked->id}/block")
            ->assertOk()
            ->assertJsonPath('message', 'User unblocked.');
        $this->getJson('/api/friends')
            ->assertOk()
            ->assertJsonPath('data.0.id', $blocked->id);
        $this->getJson("/api/conversations/{$conversation->id}/messages")
            ->assertOk();
    }

    public function test_message_body_is_encrypt_at_rest_and_decrypt_on_response(): void
    {
        [$sender, $recipient, $conversation] = $this->makeConversation();

        Sanctum::actingAs($sender);
        $response = $this->postJson("/api/conversations/{$conversation->id}/messages", [
            'body' => 'Top secret chat message',
        ])->assertCreated()->assertJsonPath('body', 'Top secret chat message');

        $message = Message::findOrFail($response->json('id'));

        $this->assertNotSame('Top secret chat message', $message->getRawOriginal('body'));
        $this->assertSame('Top secret chat message', $message->body);

        Sanctum::actingAs($recipient);
        $this->getJson("/api/conversations/{$conversation->id}/messages")
            ->assertOk()
            ->assertJsonPath('data.0.body', 'Top secret chat message');
    }

    public function test_messages_can_reply_only_to_messages_in_the_same_conversation(): void
    {
        [$sender, $recipient, $conversation] = $this->makeConversation();
        $otherUser = User::factory()->create();
        $otherConversation = Conversation::create([
            'low_user_id' => min($sender->id, $otherUser->id),
            'high_user_id' => max($sender->id, $otherUser->id),
        ]);
        Sanctum::actingAs($sender);

        $originalId = $this->postJson("/api/conversations/{$conversation->id}/messages", [
            'body' => 'Original message',
        ])->assertCreated()->json('id');

        $this->postJson("/api/conversations/{$conversation->id}/messages", [
            'body' => 'A reply',
            'reply_to_message_id' => $originalId,
        ])->assertCreated()
            ->assertJsonPath('body', 'A reply')
            ->assertJsonPath('reply_to.id', $originalId)
            ->assertJsonPath('reply_to.body', 'Original message')
            ->assertJsonPath('reply_to.sender.id', $sender->id);

        $this->postJson("/api/conversations/{$otherConversation->id}/messages", [
            'body' => 'Invalid cross-conversation reply',
            'reply_to_message_id' => $originalId,
        ])->assertUnprocessable()->assertJsonValidationErrors('reply_to_message_id');
    }

    public function test_user_can_forward_a_message_to_another_conversation(): void
    {
        [$sender, , $sourceConversation] = $this->makeConversation();
        $targetFriend = User::factory()->create();
        $targetConversation = Conversation::create([
            'low_user_id' => min($sender->id, $targetFriend->id),
            'high_user_id' => max($sender->id, $targetFriend->id),
        ]);
        $outsider = User::factory()->create();
        $unauthorizedConversation = Conversation::create([
            'low_user_id' => min($outsider->id, $targetFriend->id),
            'high_user_id' => max($outsider->id, $targetFriend->id),
        ]);

        Sanctum::actingAs($sender);
        $messageId = $this->postJson("/api/conversations/{$sourceConversation->id}/messages", [
            'body' => 'Please review this update.',
        ])->assertCreated()->json('id');

        $this->postJson("/api/conversations/{$sourceConversation->id}/messages/{$messageId}/forward", [
            'target_conversation_id' => $targetConversation->id,
        ])->assertCreated()
            ->assertJsonPath('body', 'Please review this update.')
            ->assertJsonPath('is_forwarded', true)
            ->assertJsonPath('sender.id', $sender->id);

        $this->postJson("/api/conversations/{$sourceConversation->id}/messages/{$messageId}/forward", [
            'target_conversation_id' => $unauthorizedConversation->id,
        ])->assertNotFound();
    }

    public function test_forwarding_copies_private_attachments(): void
    {
        Storage::fake('private');
        [$sender, , $sourceConversation] = $this->makeConversation();
        $targetFriend = User::factory()->create();
        $targetConversation = Conversation::create([
            'low_user_id' => min($sender->id, $targetFriend->id),
            'high_user_id' => max($sender->id, $targetFriend->id),
        ]);
        $sourcePath = "messages/{$sourceConversation->id}/private-file.pdf";
        Storage::disk('private')->put($sourcePath, "%PDF-1.4\nprivate\n%%EOF");
        $message = $sourceConversation->messages()->create([
            'sender_id' => $sender->id,
            'body' => null,
            'type' => 'file',
            'attachment_path' => $sourcePath,
            'attachment_name' => 'private.pdf',
            'attachment_mime' => 'application/pdf',
            'attachment_size' => 24,
        ]);

        Sanctum::actingAs($sender);
        $forwarded = $this->postJson(
            "/api/conversations/{$sourceConversation->id}/messages/{$message->id}/forward",
            ['target_conversation_id' => $targetConversation->id],
        )->assertCreated()
            ->assertJsonPath('is_forwarded', true)
            ->assertJsonPath('attachment.name', 'private.pdf')
            ->json();

        $forwardedMessage = Message::findOrFail($forwarded['id']);
        $this->assertNotSame($sourcePath, $forwardedMessage->attachment_path);
        Storage::disk('private')->assertExists($forwardedMessage->attachment_path);
        $this->assertSame(
            Storage::disk('private')->get($sourcePath),
            Storage::disk('private')->get($forwardedMessage->attachment_path),
        );
    }

    public function test_messages_can_be_deleted_for_me_or_everyone_with_sender_authorization(): void
    {
        Storage::fake('private');
        [$sender, $recipient, $conversation] = $this->makeConversation();
        Sanctum::actingAs($sender);
        $messageId = $this->postJson("/api/conversations/{$conversation->id}/messages", [
            'body' => 'Keep this private',
        ])->assertCreated()->json('id');

        Sanctum::actingAs($recipient);
        $this->deleteJson("/api/conversations/{$conversation->id}/messages/{$messageId}", [
            'scope' => 'everyone',
        ])->assertForbidden();
        $this->deleteJson("/api/conversations/{$conversation->id}/messages/{$messageId}", [
            'scope' => 'me',
        ])->assertOk();
        $this->getJson("/api/conversations/{$conversation->id}/messages")
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson('/api/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.unread_count', 0);

        Sanctum::actingAs($sender);
        $this->getJson("/api/conversations/{$conversation->id}/messages")
            ->assertOk()
            ->assertJsonPath('data.0.id', $messageId)
            ->assertJsonPath('data.0.body', 'Keep this private');
        $this->deleteJson("/api/conversations/{$conversation->id}/messages/{$messageId}", [
            'scope' => 'everyone',
        ])->assertOk();

        Sanctum::actingAs($recipient);
        $this->getJson("/api/conversations/{$conversation->id}/messages")
            ->assertOk()
            ->assertJsonPath('data', []);

        Sanctum::actingAs($sender);
        $secondMessageId = $this->postJson("/api/conversations/{$conversation->id}/messages", [
            'body' => 'Visible to everyone before deletion',
        ])->assertCreated()->json('id');
        $this->deleteJson("/api/conversations/{$conversation->id}/messages/{$secondMessageId}", [
            'scope' => 'everyone',
        ])->assertOk();

        Sanctum::actingAs($recipient);
        $deletedResponse = $this->getJson("/api/conversations/{$conversation->id}/messages")
            ->assertOk()
            ->assertJsonPath('data.0.id', $secondMessageId)
            ->assertJsonPath('data.0.body', null);
        $this->assertNotEmpty($deletedResponse->json('data.0.deleted_for_everyone_at'));
    }

    public function test_deleting_a_message_for_everyone_removes_its_private_attachment(): void
    {
        Storage::fake('private');
        [$sender, , $conversation] = $this->makeConversation();
        $path = "messages/{$conversation->id}/attachment.pdf";
        Storage::disk('private')->put($path, "%PDF-1.4\nAttachment\n%%EOF");
        $message = $conversation->messages()->create([
            'sender_id' => $sender->id,
            'body' => null,
            'type' => 'file',
            'attachment_path' => $path,
            'attachment_name' => 'attachment.pdf',
            'attachment_mime' => 'application/pdf',
            'attachment_size' => 28,
        ]);
        Sanctum::actingAs($sender);

        $this->deleteJson("/api/conversations/{$conversation->id}/messages/{$message->id}", [
            'scope' => 'everyone',
        ])->assertOk();

        Storage::disk('private')->assertMissing($path);
        $this->assertNotNull($message->fresh()->deleted_for_everyone_at);
        $this->assertNull($message->fresh()->attachment_path);
    }

    public function test_unrelated_user_cannot_read_a_conversation(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $outsider = User::factory()->create();
        $conversation = Conversation::create([
            'low_user_id' => min($first->id, $second->id),
            'high_user_id' => max($first->id, $second->id),
        ]);

        Sanctum::actingAs($outsider);
        $this->getJson("/api/conversations/{$conversation->id}/messages")
            ->assertNotFound();
        $this->getJson("/api/conversations/{$conversation->id}/socket-access")
            ->assertNotFound();
    }

    public function test_socket_access_returns_the_other_conversation_participant(): void
    {
        [$sender, $recipient, $conversation] = $this->makeConversation();

        Sanctum::actingAs($sender);
        $this->getJson("/api/conversations/{$conversation->id}/socket-access")
            ->assertOk()
            ->assertJsonPath('conversation_id', $conversation->id)
            ->assertJsonPath('peer_id', $recipient->id)
            ->assertJsonPath('participant_ids', [$sender->id, $recipient->id]);
    }

    public function test_friend_can_send_and_download_a_private_attachment(): void
    {
        Storage::fake('private');
        [$sender, $recipient, $conversation] = $this->makeConversation();
        Sanctum::actingAs($sender);

        $response = $this->post("/api/conversations/{$conversation->id}/messages", [
            'body' => 'Here is the document',
            'attachment' => UploadedFile::fake()->createWithContent(
                'report.pdf',
                "%PDF-1.4\nAttachment test\n%%EOF"
            ),
        ]);

        $response->assertCreated()
            ->assertJsonPath('body', 'Here is the document')
            ->assertJsonPath('type', 'file')
            ->assertJsonPath('attachment.name', 'report.pdf')
            ->assertJsonMissingPath('attachment.path');

        $message = Message::findOrFail($response->json('id'));
        Storage::disk('private')->assertExists($message->attachment_path);

        Sanctum::actingAs($recipient);
        $this->get("/api/conversations/{$conversation->id}/messages/{$message->id}/attachment")
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Disposition', 'attachment; filename=attachment-'.$message->id.'.pdf');
    }

    public function test_attachment_size_and_content_are_validated(): void
    {
        [$sender, , $conversation] = $this->makeConversation();
        Sanctum::actingAs($sender);

        $this->postJson("/api/conversations/{$conversation->id}/messages", [
            'attachment' => UploadedFile::fake()->create('oversized.pdf', 5121, 'application/pdf'),
        ])->assertUnprocessable()->assertJsonValidationErrors('attachment');

        $this->postJson("/api/conversations/{$conversation->id}/messages", [
            'attachment' => UploadedFile::fake()->createWithContent('fake.jpg', 'not a real jpeg'),
        ])->assertUnprocessable()->assertJsonValidationErrors('attachment');
    }

    public function test_office_documents_spreadsheets_and_archives_can_be_attached(): void
    {
        Storage::fake('private');
        [$sender, , $conversation] = $this->makeConversation();
        Sanctum::actingAs($sender);
        $ole = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0", 504);
        $files = [
            'report.docx' => [$this->zipBytes(['[Content_Types].xml' => '<Types/>', 'word/document.xml' => '<w:document/>']),
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'budget.xlsx' => [$this->zipBytes(['[Content_Types].xml' => '<Types/>', 'xl/workbook.xml' => '<workbook/>']),
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'slides.pptx' => [$this->zipBytes(['[Content_Types].xml' => '<Types/>', 'ppt/presentation.xml' => '<p/>']),
                'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
            'notes.odt' => [$this->zipBytes(['mimetype' => 'application/vnd.oasis.opendocument.text', 'content.xml' => '<x/>']),
                'application/vnd.oasis.opendocument.text'],
            'bundle.zip' => [$this->zipBytes(['readme.txt' => 'hello']), 'application/zip'],
            'legacy.doc' => [$ole, 'application/msword'],
            'legacy.xls' => [$ole, 'application/vnd.ms-excel'],
            'letter.rtf' => ["{\\rtf1\\ansi Hello}", 'application/rtf'],
            'contacts.csv' => ["name,phone\nAli,+923001234567\n", 'text/csv'],
        ];

        foreach ($files as $name => [$contents, $mime]) {
            $response = $this->postJson("/api/conversations/{$conversation->id}/messages", [
                'attachment' => UploadedFile::fake()->createWithContent($name, $contents),
            ]);
            $response->assertCreated()
                ->assertJsonPath('attachment.name', $name)
                ->assertJsonPath('attachment.mime', $mime);

            $stored = Message::findOrFail($response->json('id'))->attachment_path;
            $this->assertSame(pathinfo($name, PATHINFO_EXTENSION), pathinfo($stored, PATHINFO_EXTENSION));
        }

        $this->postJson("/api/conversations/{$conversation->id}/messages", [
            'attachment' => UploadedFile::fake()->image('photo.bmp'),
        ])->assertCreated()->assertJsonPath('attachment.mime', 'image/bmp');
    }

    public function test_disguised_and_unsupported_attachments_are_rejected(): void
    {
        Storage::fake('private');
        [$sender, , $conversation] = $this->makeConversation();
        Sanctum::actingAs($sender);

        foreach ([
            // A plain ZIP is not a Word document.
            'fake.docx' => $this->zipBytes(['readme.txt' => 'hello']),
            // A Word package is not a spreadsheet.
            'fake.xlsx' => $this->zipBytes(['[Content_Types].xml' => '<Types/>', 'word/document.xml' => '<w/>']),
            'fake.odt' => $this->zipBytes(['mimetype' => 'application/vnd.oasis.opendocument.spreadsheet']),
            'program.doc' => "MZ\x90\0".str_repeat("\0", 60),
            'broken.zip' => 'PK not really a zip',
            'binary.csv' => "a,b\n\0\0\0",
            'tool.exe' => "MZ\x90\0".str_repeat("\0", 60),
        ] as $name => $contents) {
            $this->postJson("/api/conversations/{$conversation->id}/messages", [
                'attachment' => UploadedFile::fake()->createWithContent($name, $contents),
            ])->assertUnprocessable()->assertJsonValidationErrors('attachment');
        }

        $this->assertSame(0, Message::count());
    }

    private function zipBytes(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
            if ($name === 'mimetype') {
                $zip->setCompressionName($name, \ZipArchive::CM_STORE);
            }
        }
        $zip->close();
        $bytes = file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    public function test_non_participant_cannot_download_an_attachment(): void
    {
        Storage::fake('private');
        [$sender, , $conversation] = $this->makeConversation();
        $path = 'messages/'.$conversation->id.'/private-file.pdf';
        Storage::disk('private')->put($path, "%PDF-1.4\nprivate\n%%EOF");
        $message = $conversation->messages()->create([
            'sender_id' => $sender->id,
            'body' => null,
            'type' => 'file',
            'attachment_path' => $path,
            'attachment_name' => 'private.pdf',
            'attachment_mime' => 'application/pdf',
            'attachment_size' => 24,
        ]);

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/conversations/{$conversation->id}/messages/{$message->id}/attachment")
            ->assertNotFound();
    }

    public function test_voice_message_can_be_sent_downloaded_and_forwarded(): void
    {
        Storage::fake('private');
        [$sender, $recipient, $conversation] = $this->makeConversation();
        $thirdUser = User::factory()->create();
        $otherConversation = Conversation::create([
            'low_user_id' => min($sender->id, $thirdUser->id),
            'high_user_id' => max($sender->id, $thirdUser->id),
        ]);
        Sanctum::actingAs($sender);

        $response = $this->postJson("/api/conversations/{$conversation->id}/messages", [
            'voice' => UploadedFile::fake()->createWithContent('anything.bin', $this->webmVoiceBytes()),
            'voice_duration_seconds' => 7,
        ]);

        $response->assertCreated()
            ->assertJsonPath('type', 'voice')
            ->assertJsonPath('body', null)
            ->assertJsonPath('voice.duration_seconds', 7)
            ->assertJsonPath('attachment.name', 'voice-message.webm')
            ->assertJsonPath('attachment.mime', 'audio/webm');

        $message = Message::findOrFail($response->json('id'));
        Storage::disk('private')->assertExists($message->attachment_path);

        Sanctum::actingAs($recipient);
        $this->get("/api/conversations/{$conversation->id}/messages/{$message->id}/attachment")
            ->assertOk()
            ->assertHeader('Content-Type', 'audio/webm');

        Sanctum::actingAs($sender);
        $this->postJson("/api/conversations/{$conversation->id}/messages/{$message->id}/forward", [
            'target_conversation_id' => $otherConversation->id,
        ])->assertCreated()
            ->assertJsonPath('type', 'voice')
            ->assertJsonPath('voice.duration_seconds', 7)
            ->assertJsonPath('is_forwarded', true);
    }

    public function test_protected_messages_store_only_the_encrypted_envelope(): void
    {
        [$sender, $recipient, $conversation] = $this->makeConversation();
        $envelope = [
            'v' => 1,
            'alg' => 'AES-256-GCM',
            'kdf' => 'PBKDF2-SHA256',
            'iter' => 600000,
            'salt' => base64_encode(random_bytes(16)),
            'iv' => base64_encode(random_bytes(12)),
            'ct' => base64_encode(random_bytes(48)),
        ];
        Sanctum::actingAs($sender);

        $sent = $this->postJson("/api/conversations/{$conversation->id}/messages", [
            'type' => 'protected',
            'body' => json_encode($envelope),
        ])->assertCreated()
            ->assertJsonPath('type', 'protected')
            ->assertJsonPath('body', null)
            ->assertJsonPath('protected', $envelope);

        $this->postJson("/api/conversations/{$conversation->id}/messages", [
            'type' => 'text',
            'body' => 'Re: credentials',
            'reply_to_message_id' => $sent->json('id'),
        ])->assertCreated()
            ->assertJsonPath('reply_to.type', 'protected')
            ->assertJsonPath('reply_to.body', null);

        Sanctum::actingAs($recipient);
        $this->getJson("/api/conversations/{$conversation->id}/messages")
            ->assertOk()
            ->assertJsonPath('data.1.protected', $envelope);

        foreach ([
            'plain text password',
            json_encode([...$envelope, 'iv' => base64_encode(random_bytes(8))]),
            json_encode([...$envelope, 'iter' => 10]),
            json_encode([...$envelope, 'extra' => 'field']),
        ] as $invalidBody) {
            $this->postJson("/api/conversations/{$conversation->id}/messages", [
                'type' => 'protected',
                'body' => $invalidBody,
            ])->assertUnprocessable()->assertJsonValidationErrors('body');
        }
    }

    public function test_sender_can_edit_a_text_message_within_their_edit_window(): void
    {
        [$sender, $recipient, $conversation] = $this->makeConversation();
        Sanctum::actingAs($sender);
        $messageId = $this->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'Meet at 5'])
            ->assertCreated()
            ->assertJsonPath('edited_at', null)
            ->json('id');

        $this->patchJson("/api/conversations/{$conversation->id}/messages/{$messageId}", ['body' => 'Meet at 6'])
            ->assertOk()
            ->assertJsonPath('body', 'Meet at 6')
            ->assertJsonPath('edited_at', fn ($value) => $value !== null);
        $this->assertSame('Meet at 6', Message::findOrFail($messageId)->body);

        Sanctum::actingAs($recipient);
        $this->patchJson("/api/conversations/{$conversation->id}/messages/{$messageId}", ['body' => 'Hijacked'])
            ->assertForbidden();

        // The default window is 5 minutes.
        Sanctum::actingAs($sender);
        $this->travel(6)->minutes();
        $this->patchJson("/api/conversations/{$conversation->id}/messages/{$messageId}", ['body' => 'Too late'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Messages can only be edited within 5 minutes of sending.');
        $this->assertSame('Meet at 6', Message::findOrFail($messageId)->body);
    }

    public function test_edit_window_follows_the_senders_setting(): void
    {
        [$sender, , $conversation] = $this->makeConversation();
        Sanctum::actingAs($sender);

        $this->patchJson('/api/user/settings', ['message_edit_minutes' => 7])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('message_edit_minutes');
        $this->patchJson('/api/user/settings', ['message_edit_minutes' => 15])
            ->assertOk()
            ->assertJsonPath('user.message_edit_minutes', 15);

        $messageId = $this->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'Draft'])->json('id');
        $this->travel(10)->minutes();
        $this->patchJson("/api/conversations/{$conversation->id}/messages/{$messageId}", ['body' => 'Final'])
            ->assertOk()
            ->assertJsonPath('body', 'Final');

        $this->patchJson('/api/user/settings', ['message_edit_minutes' => 0])->assertOk();
        $this->patchJson("/api/conversations/{$conversation->id}/messages/{$messageId}", ['body' => 'Again'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Message editing is turned off in your settings.');
    }

    public function test_only_live_text_messages_can_be_edited(): void
    {
        Storage::fake('private');
        [$sender, , $conversation] = $this->makeConversation();
        Sanctum::actingAs($sender);

        $fileMessageId = $this->postJson("/api/conversations/{$conversation->id}/messages", [
            'attachment' => UploadedFile::fake()->createWithContent('notes.txt', 'hello'),
        ])->json('id');
        $this->patchJson("/api/conversations/{$conversation->id}/messages/{$fileMessageId}", ['body' => 'x'])
            ->assertUnprocessable();

        $textId = $this->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'Oops'])->json('id');
        $this->patchJson("/api/conversations/{$conversation->id}/messages/{$textId}", ['body' => '   '])
            ->assertUnprocessable();
        $this->deleteJson("/api/conversations/{$conversation->id}/messages/{$textId}", ['scope' => 'everyone'])->assertOk();
        $this->patchJson("/api/conversations/{$conversation->id}/messages/{$textId}", ['body' => 'Back'])
            ->assertUnprocessable();
    }

    public function test_members_receive_the_same_conversation_key_and_it_is_not_stored_in_plain_form(): void
    {
        [$first, $second, $conversation] = $this->makeConversation();

        Sanctum::actingAs($first);
        $firstKeys = $this->getJson('/api/conversation-keys')->assertOk()->json('data');
        $this->assertCount(1, $firstKeys);
        $this->assertSame($conversation->id, $firstKeys[0]['conversation_id']);
        $this->assertSame(1, $firstKeys[0]['version']);
        $this->assertSame(32, strlen(base64_decode($firstKeys[0]['key'], true)));

        // A new sign-in (any device) gets the same key automatically.
        Sanctum::actingAs($second);
        $this->getJson("/api/conversations/{$conversation->id}/keys")
            ->assertOk()
            ->assertJsonPath('data.0.key', $firstKeys[0]['key']);

        $stored = \DB::table('conversation_keys')->value('key_material');
        $this->assertStringNotContainsString($firstKeys[0]['key'], $stored);

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/conversations/{$conversation->id}/keys")->assertNotFound();
    }

    public function test_encrypted_messages_files_and_voice_notes_are_stored_as_ciphertext(): void
    {
        Storage::fake('private');
        [$sender, $recipient, $conversation] = $this->makeConversation();
        Sanctum::actingAs($sender);
        $this->getJson("/api/conversations/{$conversation->id}/keys")->assertOk();
        $envelope = $this->encryptedEnvelope();

        $text = $this->postJson("/api/conversations/{$conversation->id}/messages", [
            'body' => $envelope, 'encrypted' => true, 'key_version' => 1,
        ])->assertCreated()
            ->assertJsonPath('encrypted', true)
            ->assertJsonPath('key_version', 1)
            ->assertJsonPath('body', $envelope);

        // Encrypted uploads are random-looking bytes the server cannot inspect.
        $file = $this->post("/api/conversations/{$conversation->id}/messages", [
            'body' => $this->encryptedEnvelope(), 'encrypted' => '1', 'key_version' => 1,
            'attachment' => UploadedFile::fake()->createWithContent('blob', random_bytes(300)),
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('type', 'file')
            ->assertJsonPath('attachment.encrypted', true)
            ->assertJsonPath('attachment.name', 'encrypted.bin');
        $this->assertStringEndsWith('.bin', Message::findOrFail($file->json('id'))->attachment_path);

        $this->post("/api/conversations/{$conversation->id}/messages", [
            'body' => $this->encryptedEnvelope(), 'encrypted' => '1', 'key_version' => 1,
            'voice' => UploadedFile::fake()->createWithContent('blob', random_bytes(300)),
            'voice_duration_seconds' => 4,
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('type', 'voice')
            ->assertJsonPath('voice.duration_seconds', 4);

        $this->postJson("/api/conversations/{$conversation->id}/messages", [
            'body' => 'plain text pretending', 'encrypted' => true, 'key_version' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->postJson("/api/conversations/{$conversation->id}/messages", [
            'body' => $this->encryptedEnvelope(), 'encrypted' => true, 'key_version' => 9,
        ])->assertUnprocessable()->assertJsonValidationErrors('key_version');

        Sanctum::actingAs($recipient);
        $this->getJson("/api/conversations/{$conversation->id}/messages")
            ->assertOk()
            ->assertJsonPath('data.2.body', $envelope)
            ->assertJsonPath('data.2.encrypted', true);

        // Edits and forwards of encrypted messages must stay encrypted.
        Sanctum::actingAs($sender);
        $this->patchJson("/api/conversations/{$conversation->id}/messages/{$text->json('id')}", ['body' => 'plain edit'])
            ->assertUnprocessable();
        $this->patchJson("/api/conversations/{$conversation->id}/messages/{$text->json('id')}", [
            'body' => $this->encryptedEnvelope(), 'encrypted' => true, 'key_version' => 1,
        ])->assertOk()->assertJsonPath('encrypted', true);

        $other = Conversation::create([
            'low_user_id' => min($sender->id, $third = User::factory()->create()->id),
            'high_user_id' => max($sender->id, $third),
        ]);
        $this->getJson("/api/conversations/{$other->id}/keys")->assertOk();
        $this->postJson("/api/conversations/{$conversation->id}/messages/{$text->json('id')}/forward", [
            'target_conversation_id' => $other->id,
        ])->assertUnprocessable();
        $this->postJson("/api/conversations/{$conversation->id}/messages/{$text->json('id')}/forward", [
            'target_conversation_id' => $other->id,
            'encrypted' => true, 'key_version' => 1, 'body' => $this->encryptedEnvelope(),
        ])->assertCreated()->assertJsonPath('encrypted', true)->assertJsonPath('conversation_id', $other->id);
    }

    public function test_leaving_or_removal_from_a_group_rotates_its_key(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $leaver = User::factory()->create();
        $group = Conversation::create(['type' => 'group', 'name' => 'Team', 'created_by_user_id' => $owner->id]);
        $group->participants()->attach([$owner->id, $member->id, $leaver->id]);

        Sanctum::actingAs($leaver);
        $this->getJson("/api/conversations/{$group->id}/keys")->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/groups/{$group->id}/leave")->assertOk();
        $this->getJson("/api/conversations/{$group->id}/keys")->assertNotFound();

        Sanctum::actingAs($owner);
        $this->deleteJson("/api/groups/{$group->id}/members/{$member->id}")->assertOk();
        $this->getJson("/api/conversations/{$group->id}/keys")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.2.version', 3);

        // Old keys are refused for new messages; the latest one works.
        $this->postJson("/api/conversations/{$group->id}/messages", [
            'body' => $this->encryptedEnvelope(), 'encrypted' => true, 'key_version' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('key_version');
        $this->postJson("/api/conversations/{$group->id}/messages", [
            'body' => $this->encryptedEnvelope(), 'encrypted' => true, 'key_version' => 3,
        ])->assertCreated();
    }

    private function encryptedEnvelope(): string
    {
        return json_encode([
            'v' => 1,
            'iv' => base64_encode(random_bytes(12)),
            'ct' => base64_encode(random_bytes(40)),
        ]);
    }

    public function test_messages_report_who_has_read_how_far_in_a_group(): void
    {
        $owner = User::factory()->create();
        $reader = User::factory()->create();
        $other = User::factory()->create();
        $group = Conversation::create(['type' => 'group', 'name' => 'Team', 'created_by_user_id' => $owner->id]);
        $group->participants()->attach([$owner->id, $reader->id, $other->id]);

        Sanctum::actingAs($owner);
        $first = $this->postJson("/api/conversations/{$group->id}/messages", ['body' => 'one'])->json('id');
        $this->getJson("/api/conversations/{$group->id}/messages")->assertOk()->assertJsonCount(0, 'read_cursors');

        Sanctum::actingAs($reader);
        $this->postJson("/api/conversations/{$group->id}/read")->assertOk();

        Sanctum::actingAs($owner);
        $second = $this->postJson("/api/conversations/{$group->id}/messages", ['body' => 'two'])->json('id');
        $response = $this->getJson("/api/conversations/{$group->id}/messages")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonCount(1, 'read_cursors')
            ->assertJsonPath('read_cursors.0.user.id', $reader->id)
            ->assertJsonPath('read_cursors.0.user.name', $reader->name)
            ->assertJsonPath('read_cursors.0.last_read_message_id', $first);
        $this->assertNotNull($response->json('read_cursors.0.read_at'));

        // Reading again moves the position forward; a third member's read is listed too.
        Sanctum::actingAs($reader);
        $this->postJson("/api/conversations/{$group->id}/read")->assertOk();
        Sanctum::actingAs($other);
        $this->postJson("/api/conversations/{$group->id}/read")->assertOk();
        $cursors = collect($this->getJson("/api/conversations/{$group->id}/messages")->json('read_cursors'))
            ->pluck('last_read_message_id', 'user.id');
        $this->assertSame([$reader->id => $second, $other->id => $second], $cursors->sortKeys()->all());
    }

    public function test_group_unread_counts_are_per_member(): void
    {
        $owner = User::factory()->create();
        $fastReader = User::factory()->create();
        $slowReader = User::factory()->create();
        $group = Conversation::create(['type' => 'group', 'name' => 'Team', 'created_by_user_id' => $owner->id]);
        $group->participants()->attach([$owner->id, $fastReader->id, $slowReader->id]);
        $unreadFor = function (User $user) use ($group) {
            Sanctum::actingAs($user);

            return collect($this->getJson('/api/conversations')->json('data'))
                ->firstWhere('id', $group->id)['unread_count'];
        };

        Sanctum::actingAs($owner);
        $this->postJson("/api/conversations/{$group->id}/messages", ['body' => 'one'])->assertCreated();
        $this->postJson("/api/conversations/{$group->id}/messages", ['body' => 'two'])->assertCreated();

        Sanctum::actingAs($fastReader);
        $this->postJson("/api/conversations/{$group->id}/read")->assertOk();

        // One member reading must not clear the message for everyone else.
        $this->assertSame(0, $unreadFor($fastReader));
        $this->assertSame(2, $unreadFor($slowReader));
        $this->assertSame(0, $unreadFor($owner));

        // Someone added later does not see the old history as unread.
        $friend = User::factory()->create();
        FriendRequest::create([
            'low_user_id' => min($owner->id, $friend->id),
            'high_user_id' => max($owner->id, $friend->id),
            'sender_id' => $owner->id,
            'status' => 'accepted',
        ]);
        Sanctum::actingAs($owner);
        $this->postJson("/api/groups/{$group->id}/members", ['user_id' => $friend->id])->assertSuccessful();
        $this->assertSame(0, $unreadFor($friend));
        Sanctum::actingAs($owner);
        $this->postJson("/api/conversations/{$group->id}/messages", ['body' => 'welcome'])->assertCreated();
        $this->assertSame(1, $unreadFor($friend));
    }

    public function test_older_messages_load_in_pages_before_a_cursor(): void
    {
        [$sender, $recipient, $conversation] = $this->makeConversation();
        Sanctum::actingAs($sender);
        $ids = [];
        for ($index = 1; $index <= 7; $index++) {
            $ids[] = $this->postJson("/api/conversations/{$conversation->id}/messages", ['body' => "m{$index}"])->json('id');
        }

        Sanctum::actingAs($recipient);
        $newest = $this->getJson("/api/conversations/{$conversation->id}/messages?limit=3")
            ->assertOk()
            ->assertJsonPath('has_more', true)
            ->assertJsonStructure(['data', 'current_page', 'read_cursors']);
        $this->assertSame([$ids[6], $ids[5], $ids[4]], array_column($newest->json('data'), 'id'));

        $older = $this->getJson("/api/conversations/{$conversation->id}/messages?before_id={$ids[4]}&limit=3")
            ->assertOk()
            ->assertJsonPath('has_more', true);
        $this->assertSame([$ids[3], $ids[2], $ids[1]], array_column($older->json('data'), 'id'));

        // A message arriving meanwhile does not shift the cursor's pages.
        Sanctum::actingAs($sender);
        $this->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'new'])->assertCreated();
        Sanctum::actingAs($recipient);
        $oldest = $this->getJson("/api/conversations/{$conversation->id}/messages?before_id={$ids[1]}&limit=3")
            ->assertOk()
            ->assertJsonPath('has_more', false);
        $this->assertSame([$ids[0]], array_column($oldest->json('data'), 'id'));

        $this->getJson("/api/conversations/{$conversation->id}/messages?limit=500")->assertUnprocessable();
    }

    public function test_non_friends_can_start_a_chat_as_a_message_request(): void
    {
        $sender = User::factory()->create(['phone' => '+15550000091']);
        $recipient = User::factory()->create(['phone' => '+15550000092']);

        Sanctum::actingAs($sender);
        $conversation = $this->postJson('/api/conversations', ['phone' => $recipient->phone])
            ->assertCreated()
            ->assertJsonPath('request.status', 'pending')
            ->assertJsonPath('request.incoming', false)
            ->json();
        $this->postJson("/api/conversations/{$conversation['id']}/messages", ['body' => 'Hello, can we talk?'])->assertCreated();
        $this->postJson('/api/conversations', ['phone' => '+19999999999'])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');

        Sanctum::actingAs($recipient);
        $this->getJson('/api/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.request.incoming', true)
            ->assertJsonPath('data.0.unread_count', 1);
        $this->postJson("/api/conversations/{$conversation['id']}/request/accept")->assertOk();
        $this->getJson('/api/conversations')->assertOk()->assertJsonPath('data.0.request', null);
    }

    public function test_declined_message_requests_stop_the_sender_and_hide_the_chat(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();

        Sanctum::actingAs($sender);
        $conversationId = $this->postJson('/api/conversations', ['friend_id' => $recipient->id])->json('id');
        $this->postJson("/api/conversations/{$conversationId}/request/decline")->assertForbidden();

        Sanctum::actingAs($recipient);
        $this->postJson("/api/conversations/{$conversationId}/request/decline")->assertOk();
        $this->getJson('/api/conversations')->assertOk()->assertJsonCount(0, 'data');

        Sanctum::actingAs($sender);
        $this->postJson("/api/conversations/{$conversationId}/messages", ['body' => 'Hello?'])
            ->assertForbidden()
            ->assertJsonPath('message', 'This person declined your message request.');
    }

    public function test_replying_to_a_message_request_accepts_it(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        Sanctum::actingAs($sender);
        $conversationId = $this->postJson('/api/conversations', ['friend_id' => $recipient->id])->json('id');

        Sanctum::actingAs($recipient);
        $this->postJson("/api/conversations/{$conversationId}/messages", ['body' => 'Hi!'])->assertCreated();
        $this->assertNull(Conversation::findOrFail($conversationId)->request_status);
    }

    public function test_members_can_pin_up_to_three_messages_shared_with_the_chat(): void
    {
        [$first, $second, $conversation] = $this->makeConversation();
        Sanctum::actingAs($first);
        $ids = collect(range(1, 4))->map(fn ($index) => $this->postJson(
            "/api/conversations/{$conversation->id}/messages", ['body' => "note {$index}"]
        )->json('id'));

        foreach ($ids as $index => $id) {
            $this->travel(1)->seconds();
            Sanctum::actingAs($index % 2 ? $second : $first);
            $this->postJson("/api/conversations/{$conversation->id}/messages/{$id}/pin")->assertOk();
        }

        // The oldest pin made way for the fourth; everyone sees the same three.
        Sanctum::actingAs($second);
        $pinned = $this->getJson("/api/conversations/{$conversation->id}/messages")->assertOk()->json('pinned');
        $this->assertSame([$ids[3], $ids[2], $ids[1]], array_column($pinned, 'id'));

        $this->deleteJson("/api/conversations/{$conversation->id}/messages/{$ids[2]}/pin")->assertOk();
        $this->assertSame(
            [$ids[3], $ids[1]],
            array_column($this->getJson("/api/conversations/{$conversation->id}/messages")->json('pinned'), 'id'),
        );

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/conversations/{$conversation->id}/messages/{$ids[0]}/pin")->assertNotFound();
    }

    public function test_call_history_can_show_only_missed_calls(): void
    {
        [$caller, $callee, $conversation] = $this->makeConversation();
        Sanctum::actingAs($caller);
        foreach ([['55555555-5555-4555-8555-555555555555', 'missed'], ['66666666-6666-4666-8666-666666666666', 'completed']] as [$callId, $status]) {
            $this->postJson("/api/conversations/{$conversation->id}/calls", [
                'call_id' => $callId, 'caller_id' => $caller->id, 'status' => $status,
                'media_type' => 'audio', 'duration_seconds' => $status === 'missed' ? 0 : 30,
            ])->assertCreated();
        }

        Sanctum::actingAs($callee);
        $this->getJson('/api/call-history?filter=missed')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'missed');
        $this->getJson('/api/call-history')->assertOk()->assertJsonCount(2, 'data');

        // The caller did not miss their own unanswered call.
        Sanctum::actingAs($caller);
        $this->getJson('/api/call-history?filter=missed')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_changes_are_pushed_to_the_socket_server_for_every_member(): void
    {
        config([
            'services.socket.internal_url' => 'http://socket.internal',
            'services.socket.internal_secret' => 'push-secret',
        ]);
        \Illuminate\Support\Facades\Http::fake(['socket.internal/*' => \Illuminate\Support\Facades\Http::response(['delivered' => 2], 202)]);
        [$sender, $recipient, $conversation] = $this->makeConversation();

        Sanctum::actingAs($sender);
        $messageId = $this->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'Instant'])->json('id');
        Sanctum::actingAs($recipient);
        $this->postJson("/api/conversations/{$conversation->id}/read")->assertOk();

        $events = collect();
        \Illuminate\Support\Facades\Http::assertSent(function ($request) use ($events) {
            $this->assertSame('http://socket.internal/internal/events', $request->url());
            $this->assertSame(['Bearer push-secret'], $request->header('Authorization'));
            $events->push(...$request['events']);

            return true;
        });
        $created = $events->where('event', 'message.created')->values();
        $this->assertEqualsCanonicalizing([$sender->id, $recipient->id], $created->pluck('user_ids.0')->all());
        $this->assertSame($messageId, $created[0]['payload']['message']['id']);
        $this->assertSame('Instant', $created[0]['payload']['message']['body']);
        $read = $events->firstWhere('event', 'conversation.read');
        $this->assertSame($recipient->id, $read['payload']['user_id']);
        $this->assertSame($messageId, $read['payload']['last_read_message_id']);
    }

    public function test_clearing_a_chat_hides_it_only_for_that_person(): void
    {
        [$clearer, $other, $conversation] = $this->makeConversation();
        Sanctum::actingAs($other);
        $first = $this->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'one'])->json('id');
        $this->postJson("/api/conversations/{$conversation->id}/messages/{$first}/pin")->assertOk();
        $this->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'two'])->assertCreated();

        Sanctum::actingAs($clearer);
        $this->postJson("/api/conversations/{$conversation->id}/clear")
            ->assertOk()
            ->assertJsonPath('message', 'Chat cleared for you.');
        $this->getJson("/api/conversations/{$conversation->id}/messages")
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonCount(0, 'pinned');
        $this->getJson('/api/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.last_message', null)
            ->assertJsonPath('data.0.unread_count', 0);

        // The other person still sees everything.
        Sanctum::actingAs($other);
        $this->getJson("/api/conversations/{$conversation->id}/messages")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonCount(1, 'pinned');

        // New messages after clearing show up as normal.
        $third = $this->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'three'])->json('id');
        Sanctum::actingAs($clearer);
        $this->getJson("/api/conversations/{$conversation->id}/messages")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $third);
        $this->getJson('/api/conversations')->assertOk()->assertJsonPath('data.0.unread_count', 1);

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/conversations/{$conversation->id}/clear")->assertNotFound();
    }

    public function test_voice_messages_are_validated(): void
    {
        Storage::fake('private');
        [$sender, , $conversation] = $this->makeConversation();
        Sanctum::actingAs($sender);

        $this->postJson("/api/conversations/{$conversation->id}/messages", [
            'voice' => UploadedFile::fake()->createWithContent('voice.webm', 'not audio at all'),
            'voice_duration_seconds' => 3,
        ])->assertUnprocessable()->assertJsonValidationErrors('voice');

        $this->postJson("/api/conversations/{$conversation->id}/messages", [
            'voice' => UploadedFile::fake()->createWithContent('voice.webm', $this->webmVoiceBytes()),
        ])->assertUnprocessable()->assertJsonValidationErrors('voice_duration_seconds');

        $this->postJson("/api/conversations/{$conversation->id}/messages", [
            'voice' => UploadedFile::fake()->createWithContent('voice.webm', $this->webmVoiceBytes()),
            'voice_duration_seconds' => 3,
            'body' => 'Voice notes cannot carry captions',
        ])->assertUnprocessable()->assertJsonValidationErrors('voice');

        $this->assertSame(0, Message::count());
    }

    public function test_group_calls_are_logged_and_appear_in_member_call_history(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $outsider = User::factory()->create();
        $group = Conversation::create([
            'type' => 'group',
            'name' => 'Launch team',
            'created_by_user_id' => $owner->id,
        ]);
        $group->participants()->attach([$owner->id, $member->id]);
        $call = [
            'call_id' => '33333333-3333-4333-8333-333333333333',
            'caller_id' => $owner->id,
            'status' => 'completed',
            'media_type' => 'video',
            'duration_seconds' => 42,
        ];

        Sanctum::actingAs($member);
        $this->postJson("/api/conversations/{$group->id}/calls", $call)
            ->assertCreated()
            ->assertJsonPath('call.status', 'completed')
            ->assertJsonPath('sender.id', $owner->id);
        $this->postJson("/api/conversations/{$group->id}/calls", [...$call, 'call_id' => '44444444-4444-4444-8444-444444444444', 'caller_id' => $outsider->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('caller_id');
        $this->getJson('/api/call-history')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.contact', null)
            ->assertJsonPath('data.0.group.id', $group->id)
            ->assertJsonPath('data.0.group.name', 'Launch team')
            ->assertJsonPath('data.0.direction', 'incoming')
            ->assertJsonPath('data.0.duration_seconds', 42);

        Sanctum::actingAs($outsider);
        $this->postJson("/api/conversations/{$group->id}/calls", $call)->assertNotFound();
        $this->getJson('/api/call-history')->assertOk()->assertJsonCount(0, 'data');
    }

    /**
     * A minimal WebM (EBML) header as produced by MediaRecorder for audio/webm;codecs=opus.
     */
    private function webmVoiceBytes(): string
    {
        $ebmlBody = "\x42\x86\x81\x01"   // EBMLVersion 1
            ."\x42\xF7\x81\x01"          // EBMLReadVersion 1
            ."\x42\xF2\x81\x04"          // EBMLMaxIDLength 4
            ."\x42\xF3\x81\x08"          // EBMLMaxSizeLength 8
            ."\x42\x82\x84webm"          // DocType "webm"
            ."\x42\x87\x81\x04"          // DocTypeVersion 4
            ."\x42\x85\x81\x02";         // DocTypeReadVersion 2

        return "\x1A\x45\xDF\xA3".chr(0x80 | strlen($ebmlBody)).$ebmlBody
            ."\x18\x53\x80\x67\x01\xFF\xFF\xFF\xFF\xFF\xFF\xFF"
            .str_repeat("\0", 256);
    }

    private function makeConversation(): array
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $conversation = Conversation::create([
            'low_user_id' => min($first->id, $second->id),
            'high_user_id' => max($first->id, $second->id),
        ]);

        return [$first, $second, $conversation];
    }
}
