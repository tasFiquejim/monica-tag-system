<?php

namespace App\Domains\Contact\ManageTags\Api\Controllers;

use App\Domains\Contact\ManageTags\Services\CreateTag;
use App\Domains\Contact\ManageTags\Services\DestroyTag;
use App\Domains\Contact\ManageTags\Services\UpdateTag;
use App\Http\Controllers\ApiController;
use App\Http\Resources\TagResource;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class TagController extends ApiController
{
    /**
     * Cache TTL in seconds (10 minutes).
     */
    private const CACHE_TTL = 600;

    /**
     * Build the Redis cache key for a given vault.
     *
     * Keeping the key format in one place makes invalidation trivial.
     */
    private function cacheKey(string $vaultId): string
    {
        return "tags.vault.{$vaultId}";
    }

    // ──────────────────────────────────────────────────────────────────────
    // GET /api/vaults/{vaultId}/tags
    // ──────────────────────────────────────────────────────────────────────

    /**
     * List all tags.
     *
     * Returns all tags that belong to the given vault, each with a
     * `usage_count` indicating how many contacts currently carry that tag.
     *
     * The result is cached in Redis for 10 minutes.
     */
    public function index(Request $request, string $vaultId): JsonResponse
    {
        // Verify the vault belongs to the authenticated user.
        $vault = $request->user()->account->vaults()->findOrFail($vaultId);

        // Remember() returns the cached value or executes the closure once
        // and stores the result.  TTL is 10 minutes (600 s).
        $tags = Cache::remember(
            $this->cacheKey($vault->id),
            self::CACHE_TTL,
            fn () => $vault->tags()
                ->withCount([
                    // Count only pivot rows where taggable_type is Contact.
                    'contacts as usage_count',
                ])
                ->orderBy('name')
                ->get()
        );

        return $this->respond([
            'data' => TagResource::collection($tags),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // POST /api/vaults/{vaultId}/tags
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Create a tag.
     *
     * Creates a new tag inside the vault.
     * Accepted fields: `name` (required), `tag_category` (optional), `color` (optional).
     */
    public function store(Request $request, string $vaultId): JsonResponse
    {
        $data = [
            'account_id'   => $request->user()->account_id,
            'vault_id'     => $vaultId,
            'author_id'    => $request->user()->id,
            'name'         => $request->input('name'),
            'tag_category' => $request->input('tag_category'),
            'color'        => $request->input('color'),
        ];

        $tag = (new CreateTag)->execute($data);

        // ── Cache invalidation ────────────────────────────────────────────
        Cache::forget($this->cacheKey($vaultId));

        return $this->setHTTPStatusCode(201)->respond([
            'data' => new TagResource($tag),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // PUT /api/vaults/{vaultId}/tags/{tagId}
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Update a tag.
     *
     * Updates an existing tag.
     * Accepted fields: `name` (required), `tag_category` (optional), `color` (optional).
     */
    public function update(Request $request, string $vaultId, int $tagId): JsonResponse
    {
        $data = [
            'account_id'   => $request->user()->account_id,
            'vault_id'     => $vaultId,
            'author_id'    => $request->user()->id,
            'tag_id'       => $tagId,
            'name'         => $request->input('name'),
            'tag_category' => $request->input('tag_category'),
            'color'        => $request->input('color'),
        ];

        $tag = (new UpdateTag)->execute($data);

        // ── Cache invalidation ────────────────────────────────────────────
        Cache::forget($this->cacheKey($vaultId));

        return $this->respond([
            'data' => new TagResource($tag),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // DELETE /api/vaults/{vaultId}/tags/{tagId}
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Delete a tag.
     *
     * Deletes the tag and removes it from all contacts.
     * Optional body parameter `reassign_tag_id` (int): contacts that carried
     * the deleted tag will instead receive this replacement tag.
     */
    public function destroy(Request $request, string $vaultId, int $tagId): JsonResponse
    {
        $data = [
            'account_id'      => $request->user()->account_id,
            'vault_id'        => $vaultId,
            'author_id'       => $request->user()->id,
            'tag_id'          => $tagId,
            'reassign_tag_id' => $request->input('reassign_tag_id'),
        ];

        (new DestroyTag)->execute($data);

        // ── Cache invalidation ────────────────────────────────────────────
        Cache::forget($this->cacheKey($vaultId));

        return $this->respondObjectDeleted((string) $tagId);
    }
}
