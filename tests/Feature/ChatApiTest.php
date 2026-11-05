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
            ->assertJsonCount(3, 'participants');

        $conversationId = $groupResponse->json('id');

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

    public function test_only_creator_can_remove_members_and_other_members_can_leave(): void
    {
        $owner = User::factory()->create(['phone' => '+15550000011']);
        $memberOne = User::factory()->create(['phone' => '+15550000012']);
        $memberTwo = User::factory()->create(['phone' => '+15550000013']);

        foreach ([[$owner, $memberOne], [$owner, $memberTwo], [$memberOne, $memberTwo]] as [$user, $friend]) {
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

        $this->assertDatabaseMissing('conversation_users', [
            'conversation_id' => $group['id'],
            'user_id' => $memberOne->id,
        ]);

        Sanctum::actingAs($memberOne);
        $this->getJson("/api/conversations/{$group['id']}/messages")
            ->assertNotFound();
    }

    public function test_users_can_unfriend_each_other_and_clear_their_direct_conversation(): void
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
        $this->deleteJson("/api/friends/{$second->id}")
            ->assertOk()
            ->assertJsonPath('user_id', $second->id);

        $this->assertDatabaseMissing('friend_requests', [
            'low_user_id' => min($first->id, $second->id),
            'high_user_id' => max($first->id, $second->id),
        ]);
        $this->assertDatabaseMissing('conversations', ['id' => $conversation->id]);
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
            ->assertJsonPath('peer_id', $recipient->id);
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
