<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_DOCTOR = 'doctor';
    public const ROLE_PATIENT = 'patient';

    /**
     * The attributes that are mass assignable.
     *
     * `role` is deliberately NOT fillable — mass-assigning it would let a
     * self-registration request promote itself to admin.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'locale',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_guest' => 'boolean',
            'claimed_at' => 'datetime',
        ];
    }

    /**
     * An anonymous participant identified only by their device.
     *
     * Guests are real users for every downstream purpose — they own a patient
     * record and sync like anyone else — they simply have no email and no
     * personal details attached.
     */
    public function isGuest(): bool
    {
        return (bool) $this->is_guest;
    }

    public function patient(): HasOne
    {
        return $this->hasOne(Patient::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isDoctor(): bool
    {
        return $this->role === self::ROLE_DOCTOR;
    }

    /** Admins and doctors may read any patient's records; patients may not. */
    public function isClinician(): bool
    {
        return $this->isAdmin() || $this->isDoctor();
    }
}
