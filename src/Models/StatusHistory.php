<?php

namespace LaraFlow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StatusHistory extends Model
{
    // Desativa os timestamps padrão porque só precisamos de created_at nesta tabela
    public $timestamps = false;

    protected $fillable = [
        'from_state',
        'to_state',
        'payload',
        'user_id',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function model(): MorphTo
    {
        return $this->morphTo();
    }
}