<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Game extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'played_on', 'daily_number', 'player_count', 'status', 'created_by', 'revision', 'submission_hash'];

    protected function casts(): array
    {
        return ['played_on' => 'date:Y-m-d', 'daily_number' => 'integer', 'player_count' => 'integer', 'revision' => 'integer'];
    }

    public function participants(): HasMany
    {
        return $this->hasMany(GameParticipant::class)->orderBy('starting_order');
    }
}
