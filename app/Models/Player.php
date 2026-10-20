<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Player extends Model
{
    protected $fillable = ['first_name', 'last_name', 'nickname', 'use_nickname', 'is_active'];

    protected function casts(): array
    {
        return ['use_nickname' => 'boolean', 'is_active' => 'boolean'];
    }

    public function displayName(): string
    {
        return $this->use_nickname && $this->nickname
            ? $this->nickname
            : $this->first_name.' '.mb_substr($this->last_name, 0, 1).'.';
    }
}
