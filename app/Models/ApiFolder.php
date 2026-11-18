<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApiFolder extends Model
{
    protected $fillable = ['user_id', 'parent_id', 'name', 'sort_order'];

    protected $casts = [
        'user_id' => 'integer',
        'parent_id' => 'integer',
        'sort_order' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function collections(): HasMany
    {
        return $this->hasMany(ApiCollection::class, 'folder_id');
    }
}
