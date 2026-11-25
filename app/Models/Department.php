<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Department extends Model
{
    protected $fillable = [
        'source_id',
        'name',
    ];

    public function managers(): BelongsToMany
    {
        return $this->belongsToMany(Manager::class);
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->name ?? __('ui.unnamed_department', ['id' => $this->source_id]);
    }
}
