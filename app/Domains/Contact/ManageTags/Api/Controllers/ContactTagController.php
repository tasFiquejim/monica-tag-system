<?php

namespace App\Domains\Contact\ManageTags\Api\Controllers;

use App\Domains\Contact\ManageTags\Services\AttachTagsToContact;
use App\Domains\Contact\ManageTags\Services\DetachTagFromContact;
use App\Http\Controllers\ApiController;
use App\Http\Resources\ContactResource;
use App\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * @group Contact Tag management
 *
 * Attach / detach tags from contacts and filter contacts by tags.
 */
class ContactTagController extends ApiController
{
    /**
     * Build the Redis cache key for the tag list of a vault.
     * Must match the key used in TagController.
     */
    private function tagsCacheKey(string $vaultId): string
    {
        return "tags.vault.{$vaultId}";
    }

    // ──────────────────────────────────────────────────────────────────────
    // GET /api/vaults/{vaultId}/contacts
    // ──────────────────────────────────────────────────────────────────────

    /**
     * List contacts, optionally filtered by tags (AND logic).
     *
     * Pass one or more `tags[]` query parameters to filter to contacts that
     * have **all** of the specified tags.
     *
     * Supports existing filters:
     *   - `sort` : field name to sort by (e.g. `name`, `first_name`)
     *   - `tags[]` : one or more tag IDs (AND logic)
     *
     * All filtering is done in a single SQL statement – no PHP loops.
     */
    public function index(Request $request, string $vaultId): JsonResponse
    {
        $vault = $request->user()->account->vaults()->findOrFail($vaultId);

        $query = $vault->contacts()->with('tags');

        // ── Tag AND-filter ────────────────────────────────────────────────
        if ($request->has('tags')) {
            $tagIds = array_filter((array) $request->input('tags'), 'is_numeric');
            if (! empty($tagIds)) {
                $query->withAllTags(array_map('intval', $tagIds));
            }
        }

        // ── Sorting ───────────────────────────────────────────────────────
        $allowedSorts = ['first_name', 'last_name', 'created_at', 'updated_at'];
        $sort = $request->input('sort', 'first_name');
        if (in_array($sort, $allowedSorts, true)) {
            $query->orderBy($sort);
        }

        $contacts = $query->paginate($this->getLimitPerPage());

        return $this->respond([
            'data'  => ContactResource::collection($contacts),
            'links' => [
                'first' => $contacts->url(1),
                'last'  => $contacts->url($contacts->lastPage()),
                'prev'  => $contacts->previousPageUrl(),
                'next'  => $contacts->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $contacts->currentPage(),
                'last_page'    => $contacts->lastPage(),
                'per_page'     => $contacts->perPage(),
                'total'        => $contacts->total(),
            ],
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // POST /api/vaults/{vaultId}/contacts/{contactId}/tags
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Attach tags to a contact.
     *
     * Body: `{ "tag_ids": [1, 2, 3] }`
     *
     * Idempotent – attaching an already-attached tag is a no-op.
     * Cache for the tag list is invalidated because usage counts change.
     */
    public function store(Request $request, string $vaultId, string $contactId): JsonResponse
    {
        $data = [
            'account_id' => $request->user()->account_id,
            'vault_id'   => $vaultId,
            'author_id'  => $request->user()->id,
            'contact_id' => $contactId,
            'tag_ids'    => $request->input('tag_ids', []),
        ];

        $contact = (new AttachTagsToContact)->execute($data);

        // Invalidate the cached tag list because usage_count values changed.
        Cache::forget($this->tagsCacheKey($vaultId));

        return $this->setHTTPStatusCode(201)->respond([
            'data' => new ContactResource($contact),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // DELETE /api/vaults/{vaultId}/contacts/{contactId}/tags/{tagId}
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Detach a tag from a contact.
     *
     * Cache for the tag list is invalidated because usage counts change.
     */
    public function destroy(Request $request, string $vaultId, string $contactId, int $tagId): JsonResponse
    {
        $data = [
            'account_id' => $request->user()->account_id,
            'vault_id'   => $vaultId,
            'author_id'  => $request->user()->id,
            'contact_id' => $contactId,
            'tag_id'     => $tagId,
        ];

        (new DetachTagFromContact)->execute($data);

        // Invalidate the cached tag list because usage_count values changed.
        Cache::forget($this->tagsCacheKey($vaultId));

        return $this->respondObjectDeleted((string) $tagId);
    }
}
