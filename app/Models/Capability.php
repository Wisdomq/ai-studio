<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Capability extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'category',
        'metadata',
        'is_active',
    ];

    protected $casts = [
        'metadata' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * Get the workflows that have this capability.
     */
    public function workflows(): BelongsToMany
    {
        return $this->belongsToMany(Workflow::class, 'capability_workflow')
            ->withTimestamps();
    }

    /**
     * Scope to only active capabilities.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to filter by category.
     */
    public function scopeCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Get capabilities grouped by category.
     */
    public static function groupedByCategory(): array
    {
        return static::active()
            ->get()
            ->groupBy('category')
            ->toArray();
    }
}
