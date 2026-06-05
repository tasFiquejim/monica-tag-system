<?php

namespace App\Domains\Contact\ManageTags\Services;

use App\Interfaces\ServiceInterface;
use App\Models\Tag;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;

class DestroyTag extends BaseService implements ServiceInterface
{
    private Tag $tag;

    /**
     * Get the validation rules that apply to the service.
     */
    public function rules(): array
    {
        return [
            'account_id'       => 'required|uuid|exists:accounts,id',
            'vault_id'         => 'required|uuid|exists:vaults,id',
            'author_id'        => 'required|uuid|exists:users,id',
            'tag_id'           => 'required|integer|exists:tags,id',
            'reassign_tag_id'  => 'nullable|integer|exists:tags,id',
        ];
    }

    /**
     * Get the permissions that apply to the user calling the service.
     */
    public function permissions(): array
    {
        return [
            'author_must_belong_to_account',
            'vault_must_belong_to_account',
            'author_must_be_vault_editor',
        ];
    }

    public function execute(array $data): void
    {
        $this->validateRules($data);

        $this->tag = $this->vault->tags()->findOrFail($data['tag_id']);

        if (! empty($data['reassign_tag_id'])) {
            $this->reassignContacts((int) $data['reassign_tag_id']);
        }

        DB::table('taggables')
            ->where('tag_id', $this->tag->id)
            ->delete();

        $this->tag->delete();
    }

    /**
     * Copy pivot rows from the old tag to the replacement tag in one query,
     * ignoring duplicates (IGNORE handles the unique constraint).
     */
    private function reassignContacts(int $newTagId): void
    {
        // Validate replacement tag also belongs to this vault.
        $this->vault->tags()->findOrFail($newTagId);

        DB::statement(
            'INSERT IGNORE INTO taggables (tag_id, taggable_id, taggable_type, created_at, updated_at)
             SELECT ?, taggable_id, taggable_type, NOW(), NOW()
             FROM taggables
             WHERE tag_id = ?',
            [$newTagId, $this->tag->id]
        );
    }
}
