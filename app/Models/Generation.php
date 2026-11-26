<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Generation extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'recover_at' => 'datetime', 'completed_at' => 'datetime', 'image_expires_at' => 'datetime'];
    }
}
