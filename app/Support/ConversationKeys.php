<?php

namespace App\Support;

use App\Models\Conversation;
use App\Models\ConversationKey;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * Manages the per-conversation keys that clients use to encrypt message
 * content before it is uploaded. The server only creates, stores (encrypted)
 * and hands out keys to conversation members; it never encrypts or decrypts
 * message content itself.
 */
class ConversationKeys
{
    /**
     * All key versions for a conversation, creating the first one if needed.
     *
     * @return Collection<int, ConversationKey>
     */
    public static function ensure(Conversation $conversation): Collection
    {
        $keys = $conversation->keys()->orderBy('version')->get();
        if ($keys->isNotEmpty()) {
            return $keys;
        }

        try {
            self::create($conversation, 1);
        } catch (QueryException) {
            // Another request created version 1 at the same moment.
        }

        return $conversation->keys()->orderBy('version')->get();
    }

    /**
     * Starts a new key version, so people who just left a group cannot read
     * anything sent afterwards even if they kept a copy of the old key.
     */
    public static function rotate(Conversation $conversation): ConversationKey
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $next = ((int) $conversation->keys()->max('version')) + 1;
            try {
                return self::create($conversation, $next);
            } catch (QueryException $exception) {
                if ($attempt === 2) {
                    throw $exception;
                }
            }
        }

        throw new \LogicException('Unreachable');
    }

    public static function latestVersion(Conversation $conversation): int
    {
        return (int) $conversation->keys()->max('version');
    }

    public static function payload(ConversationKey $key): array
    {
        return [
            'conversation_id' => (int) $key->conversation_id,
            'version' => $key->version,
            'key' => $key->key_material,
        ];
    }

    private static function create(Conversation $conversation, int $version): ConversationKey
    {
        return $conversation->keys()->create([
            'version' => $version,
            'key_material' => base64_encode(random_bytes(32)),
        ]);
    }
}
