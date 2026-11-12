<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationKey extends Model
{
    protected $fillable = [
        'conversation_id',
        'version',
        'key_material',
    ];

    protected $casts = [
        'version' => 'integer',
        // Base64 of 32 random bytes, stored encrypted with the application key.
        'key_material' => 'encrypted',
    ];

    protected $hidden = [
        'key_material',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
