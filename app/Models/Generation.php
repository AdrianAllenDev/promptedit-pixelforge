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
        return ['submission_uncertain' => 'boolean', 'payload' => 'array', 'recover_at' => 'datetime', 'review_retry_after' => 'datetime', 'completed_at' => 'datetime', 'image_expires_at' => 'datetime'];
    }
}
