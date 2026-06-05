<?php

namespace App\Domains\Contact\ManageTags\Services;

use App\Interfaces\ServiceInterface;
use App\Models\Contact;
use App\Services\BaseService;

class AttachTagsToContact extends BaseService implements ServiceInterface
{
    /**
     * Get the validation rules that apply to the service.
     */
    public function rules(): array
    {
        return [
            'account_id' => 'required|uuid|exists:accounts,id',
            'vault_id'   => 'required|uuid|exists:vaults,id',
            'author_id'  => 'required|uuid|exists:users,id',
            'contact_id' => 'required|uuid|exists:contacts,id',
            'tag_ids'    => 'required|array|min:1',
            'tag_ids.*'  => 'integer|exists:tags,id',
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
            'contact_must_belong_to_vault',
        ];
    }

    /**
     * @return Contact
     */
    public function execute(array $data): Contact
    {
        $this->validateRules($data);

        $validTagIds = $this->vault->tags()
            ->whereIn('id', $data['tag_ids'])
            ->pluck('id')
            ->all();

        $this->contact->tags()->syncWithoutDetaching(
            collect($validTagIds)->mapWithKeys(fn ($id) => [$id => ['taggable_type' => Contact::class]])
        );

        return $this->contact->load('tags');
    }
}
