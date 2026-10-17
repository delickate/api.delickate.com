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
- `GET /api/friends/requests?direction=incoming|outgoing` — pending requests
  (defaults to incoming).
- `POST /api/friends/requests` — request body: `phone`.
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
- `GET /api/conversations/{id}/messages/{message}/attachment` — download an
  attachment as an authenticated conversation participant.
- `POST /api/conversations/{id}/delivered` — mark received messages delivered.
- `POST /api/conversations/{id}/read` — mark received messages read and
  delivered.
- `GET /api/auth/socket-identity` — authenticated identity lookup for the
  Socket.IO service.
- `GET /api/conversations/{id}/socket-access` — verifies the authenticated
  user belongs to a conversation before the socket service joins its room.

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
production use. This API does not provide end-to-end encryption, group
conversations, or offline push notifications.

## Tests

The PHPUnit configuration uses an in-memory SQLite database for tests. Run:

```powershell
php artisan test
```
