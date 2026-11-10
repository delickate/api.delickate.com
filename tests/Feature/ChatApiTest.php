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

    public function test_blocking_hides_friendship_and_disables_direct_chat_until_unblocked(): void
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
        $this->getJson('/api/conversations')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        Sanctum::actingAs($blocked);
        $this->getJson('/api/friends')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson('/api/conversations')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson("/api/conversations/{$conversation->id}/messages")
            ->assertNotFound();
        Sanctum::actingAs($blocker);
        $this->postJson('/api/friends/requests', ['phone' => $blocked->phone])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');

        Sanctum::actingAs($blocked);
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
