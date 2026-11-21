<?php

namespace App\Models;

use App\Enums\DeviceType;
use App\Enums\LoginChannel;
use App\Services\DeviceDetector;
use Database\Factories\LoginActivityFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One successful sign-in (web or mobile app), shown to admins as sign-in history. Written by
 * App\Services\LoginActivityLogger only, so nothing is mass assignable. Kept for RETENTION_DAYS.
 */
class LoginActivity extends Model
{
    /** @use HasFactory<LoginActivityFactory> */
    use HasFactory, Prunable;

    /** Also stated in the privacy notice on the register page. */
    public const RETENTION_DAYS = 90;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => LoginChannel::class,
            'logged_in_at' => 'datetime',
            'logged_out_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Phone, tablet or desktop, read again from the stored user agent.
     *
     * @return Attribute<DeviceType, never>
     */
    protected function deviceType(): Attribute
    {
        return Attribute::get(fn (): DeviceType => app(DeviceDetector::class)->type($this->user_agent));
    }

    /**
     * Sign-ins older than RETENTION_DAYS, removed by the scheduled `model:prune` (see routes/console.php).
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::where('logged_in_at', '<', now()->subDays(self::RETENTION_DAYS));
    }
}
