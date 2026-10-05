<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * An end user, identified by the WhatsApp number Meta authenticated. The number is stored
 * encrypted (`wa_id_enc`) with an HMAC blind index (`wa_id_bidx`) for lookups.
 */
class User extends Model
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUlids;

    protected $guarded = [];

    protected $hidden = ['wa_id_enc', 'wa_id_bidx'];

    /** @var array<string, string> */
    protected $casts = ['last_inbound_at' => 'datetime'];

    public static function normalizeWaId(string $waId): string
    {
        return preg_replace('/\D+/', '', $waId) ?? '';
    }

    public static function blindIndex(string $waId): string
    {
        $key = config('moneytalks.blind_index_key') ?: (app()->environment('testing') ? 'testing-only-key' : null);
        if (! $key) {
            throw new RuntimeException('PII_BLIND_INDEX_KEY is not set.');
        }

        return hash_hmac('sha256', self::normalizeWaId($waId), $key);
    }

    public static function findByWaId(string $waId): ?self
    {
        return self::where('wa_id_bidx', self::blindIndex($waId))->first();
    }

    public function setWaId(string $waId): void
    {
        $normalized = self::normalizeWaId($waId);
        $this->wa_id_enc = Crypt::encryptString($normalized);
        $this->wa_id_bidx = self::blindIndex($normalized);
    }

    public function waId(): string
    {
        return Crypt::decryptString($this->wa_id_enc);
    }

    /** @return HasOne<UserSetting, $this> */
    public function settings(): HasOne
    {
        return $this->hasOne(UserSetting::class);
    }

    public function recurringRules(): HasMany
    {
        return $this->hasMany(RecurringRule::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(UserAlias::class);
    }
}
