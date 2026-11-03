<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $fillable = [
        'low_user_id',
        'high_user_id',
        'type',
        'name',
        'created_by_user_id',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_users')
            ->withTimestamps();
    }

    public function lowUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'low_user_id');
    }

    public function highUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'high_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
