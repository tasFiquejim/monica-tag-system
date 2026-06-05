<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Tag extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'vault_id',
        'name',
        'slug',
        'tag_category',
        'color',
    ];

    // ──────────────────────────────────────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Get the vault that owns the tag.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Vault, $this>
     */
    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }

    /**
     * Get the posts associated with this tag (original relationship kept).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<\App\Models\Post, $this>
     */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class);
    }

    /**
     * Polymorphic relationship – all taggable records attached to this tag.
     *
     * Using MorphMany on the pivot lets us query any taggable type:
     *   $tag->taggables             → all pivot rows
     *   $tag->contacts              → only contact pivot rows (see below)
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany<\App\Models\Taggable, $this>
     */
    public function taggables(): MorphMany
    {
        return $this->morphMany(Taggable::class, 'taggable');
    }

    /**
     * Contacts attached to this tag via the polymorphic pivot.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<\App\Models\Contact, $this>
     */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'taggables', 'tag_id', 'taggable_id')
            ->wherePivot('taggable_type', Contact::class)
            ->withTimestamps();
    }

    /**
     * Get the journal tag's feed item.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphOne<\App\Models\ContactFeedItem, $this>
     */
    public function feedItem(): MorphOne
    {
        return $this->morphOne(ContactFeedItem::class, 'feedable');
    }
}
