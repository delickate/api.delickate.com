<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    public const DEFAULT_MESSAGE_EDIT_MINUTES = 5;

    /** Edit windows a user may choose, in minutes; 0 turns editing off. */
    public const MESSAGE_EDIT_MINUTE_OPTIONS = [0, 1, 2, 5, 10, 15, 30, 60];

    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'dob',
        'password',
        'profile_picture_path',
        'profile_images',
        'message_edit_minutes',
    ];

    protected $appends = ['avatar_url'];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'dob' => 'date',
        'password' => 'hashed',
        'message_edit_minutes' => 'integer',
    ];

    public function blocksMade(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'user_blocks', 'blocker_id', 'blocked_id')
            ->withTimestamps();
    }

    public function blocksReceived(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'user_blocks', 'blocked_id', 'blocker_id')
            ->withTimestamps();
    }

    public function getAvatarUrlAttribute(): ?string
    {
        if (! $this->profile_picture_path) {
            return null;
        }

        return route('profile-pictures.show', [
            'user' => $this->getKey(),
            'v' => hash('sha256', $this->profile_picture_path),
        ]);
    }
}
