<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

#[Fillable(['id', 'name'])]
class College extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return HasMany<Program, $this>
     */
    public function programs(): HasMany
    {
        return $this->hasMany(Program::class);
    }

    /**
     * Students and staff whose program belongs to this college.
     *
     * @return HasManyThrough<User, Program, $this>
     */
    public function users(): HasManyThrough
    {
        return $this->hasManyThrough(User::class, Program::class, 'college_id', 'program_code', 'id', 'code');
    }
}
