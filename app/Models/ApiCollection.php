<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiCollection extends Model
{
    protected $fillable = ['user_id', 'folder_id', 'name', 'description', 'items', 'variables', 'auth', 'sort_order'];

    protected $casts = [
        'user_id' => 'integer',
        'folder_id' => 'integer',
        'sort_order' => 'integer',
        'items' => 'array',
        'variables' => 'array',
        'auth' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(ApiFolder::class, 'folder_id');
    }
}
