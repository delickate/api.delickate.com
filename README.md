# Chat app API

Laravel 10 REST API for account authentication, friendships, and direct
messages. Authentication uses Laravel Sanctum bearer tokens.

## Setup

From this directory, configure `.env` with your database connection and run:

```powershell
php artisan migrate
php artisan serve
```

API routes are prefixed with `/api`. Registration and login return a Sanctum
token. Include it on protected requests as `Authorization: Bearer YOUR_SANCTUM_TOKEN`.

## API

### Authentication

- `POST /api/auth/register` — `name`, `phone` (E.164 format), `email`,
  `password` (at least 8 characters), `dob` (`YYYY-MM-DD`), and optional
  `device_name`.
- `POST /api/auth/login` — `phone`, `password`, and optional `device_name`.
- `POST /api/auth/logout` — revoke the current token.
- `GET /api/user` — current authenticated user.

### Friends

- `GET /api/friends` — accepted friends.
- `GET /api/blocks` — users you have blocked.
- `POST /api/users/{user}/block` and `DELETE /api/users/{user}/block` — block
  or unblock a user. Blocking hides direct chats and prevents direct messages
  and friend requests in either direction; existing friendship is restored
  when the blocker unblocks the user.
- `GET /api/friends/requests?direction=incoming|outgoing` — pending requests
  (defaults to incoming).
- `POST /api/friends/requests` — request body: `phone`.
  An accepted friend cannot be added again; the API responds that this user is
  already your friend.
- `POST /api/friends/requests/{id}/accept` or `/reject` — respond as recipient.

### Direct messages

- `GET /api/conversations` — paginated conversations, latest message, and
  unread count.
- `POST /api/conversations` — request body: `friend_id`; requires an accepted
  friendship.
- `GET /api/conversations/{id}/messages` — paginated message history.
- `POST /api/conversations/{id}/messages` — request body: `body` and optional
  `attachment` multipart upload. Send text, an attachment, or both. Attachments
  are limited to 5 MiB and allow JPEG, PNG, GIF, WebP, PDF, and UTF-8 plain
  text only; extension, detected MIME type, and file signature are checked.
- Include `reply_to_message_id` when sending to reply to a message in that
  conversation.
- `POST /api/conversations/{id}/messages/{message}/forward` — request body:
  `target_conversation_id`; copies a visible message and any private attachment
  into another conversation where the authenticated user is a participant.
  Forwarded messages are returned with `is_forwarded: true`.
- `DELETE /api/conversations/{id}/messages/{message}` — request body `scope` is
  `me` to hide the message only for the current participant, or `everyone` to
  delete the sender's own message for all participants.
- `GET /api/conversations/{id}/messages/{message}/attachment` — download an
  attachment as an authenticated conversation participant.
- `POST /api/conversations/{id}/delivered` — mark received messages delivered.
- `POST /api/conversations/{id}/read` — mark received messages read and
  delivered.
- `GET /api/auth/socket-identity` — authenticated identity lookup for the
  Socket.IO service.
- `GET /api/conversations/{id}/socket-access` — verifies the authenticated
  user belongs to a conversation and returns its `participant_ids` for
  authorized Socket.IO message notifications.

### Group management

- `POST /api/groups` — create a titled group with accepted friends.
- `POST /api/groups/{id}/members` — the creator can add an accepted friend
  using `user_id`.
- `DELETE /api/groups/{id}/members/{user}` — only the group creator can remove
  another participant; the creator cannot remove themselves.
- `POST /api/groups/{id}/leave` — a non-creator participant may leave; the
  creator cannot leave the group.

Every conversation route checks membership. Attachments are stored on the
private local filesystem disk and are never exposed as public URLs. Downloads
are sent as attachments with `nosniff`; Socket.IO events should carry message
metadata only, never file contents. Messages have `delivered_at` and `read_at`
timestamps.

For uploads up to the application limit, configure the PHP runtime used by
WAMP with `upload_max_filesize=6M` and `post_max_size=8M`, then restart Apache.
Laravel still enforces the 5 MiB per-file limit.

The identity and socket-access endpoints are authorization hooks for the
separate Socket.IO service; that service must call them before accepting an
authenticated connection or joining a conversation room. Typing is transient
and should be sent over Socket.IO as `conversation:typing` and
`conversation:stop-typing`, not saved as chat data. The REST receipt routes
persist delivery/read state; the socket service must relay message and receipt
events to the other participant. The current REST API does not itself publish
Socket.IO events.

## Important follow-up

Phone numbers are not verified by SMS yet. Add OTP verification before
production use. This API does not provide end-to-end encryption or offline
push notifications.

## Tests

The PHPUnit configuration uses an in-memory SQLite database for tests. Run:

```powershell
php artisan test
```
