<?php

namespace App\Domains\Contact\ManageTags\Services;

use App\Interfaces\ServiceInterface;
use App\Models\Tag;
use App\Services\BaseService;
use Illuminate\Support\Str;

class UpdateTag extends BaseService implements ServiceInterface
{
    private Tag $tag;

    /**
     * Get the validation rules that apply to the service.
     */
    public function rules(): array
    {
        return [
            'account_id'   => 'required|uuid|exists:accounts,id',
            'vault_id'     => 'required|uuid|exists:vaults,id',
            'author_id'    => 'required|uuid|exists:users,id',
            'tag_id'       => 'required|integer|exists:tags,id',
            'name'         => 'required|string|max:255',
            'tag_category' => 'nullable|string|max:255',
            'color'        => 'nullable|string|max:50',
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

    /**
     * Update the given tag.
     */
    public function execute(array $data): Tag
    {
        $this->validateRules($data);

        $this->tag = $this->vault->tags()->findOrFail($data['tag_id']);

        $this->tag->update([
            'name'         => $data['name'],
            'slug'         => Str::slug($data['name']),
            'tag_category' => $this->valueOrNull($data, 'tag_category'),
            'color'        => $this->valueOrNull($data, 'color'),
        ]);

        return $this->tag->fresh();
    }
}
