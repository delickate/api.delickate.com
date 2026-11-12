<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Message extends Model
{
    protected $fillable = [
        'conversation_id',
        'sender_id',
        'reply_to_message_id',
        'body',
        'type',
        'attachment_path',
        'attachment_name',
        'attachment_mime',
        'attachment_size',
        'is_forwarded',
        'call_id',
        'call_status',
        'call_media_type',
        'call_duration_seconds',
        'voice_duration_seconds',
        'delivered_at',
        'read_at',
        'deleted_for_everyone_at',
        'edited_at',
        'key_version',
    ];

    protected $casts = [
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
        'is_forwarded' => 'boolean',
        'call_duration_seconds' => 'integer',
        'voice_duration_seconds' => 'integer',
        'deleted_for_everyone_at' => 'datetime',
        'edited_at' => 'datetime',
        'key_version' => 'integer',
    ];

    public function setBodyAttribute($value): void
    {
        $this->attributes['body'] = $value === null ? null : encrypt($value);
    }

    public function getBodyAttribute($value): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        try {
            return decrypt($value);
        } catch (\Illuminate\Contracts\Encryption\DecryptException $exception) {
            return $value;
        }
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_message_id');
    }

    public function hiddenFromUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'message_user_deletions');
    }
}
