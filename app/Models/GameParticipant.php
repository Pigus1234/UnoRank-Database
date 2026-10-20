<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameParticipant extends Model
{
    public $timestamps = false;

    protected $fillable = ['player_id', 'starting_order', 'place'];

    protected function casts(): array
    {
        return ['player_id' => 'integer', 'starting_order' => 'integer', 'place' => 'integer'];
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
