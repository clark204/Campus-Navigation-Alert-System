<?php

namespace App\Models;

use Database\Factories\ConcernReplyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The admin's reply to a concern (docs/portal.md, "Concerns"). Emailed to the concern's email; shown on the sender's
 * Help & contact page and in their notification bell.
 *
 * @property int $concern_id
 * @property int|null $user_id The admin who wrote it.
 * @property string $message
 */
#[Fillable(['message'])]
class ConcernReply extends Model
{
    /** @use HasFactory<ConcernReplyFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Concern, $this>
     */
    public function concern(): BelongsTo
    {
        return $this->belongsTo(Concern::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
