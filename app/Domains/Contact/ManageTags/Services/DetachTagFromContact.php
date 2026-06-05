<?php

namespace App\Domains\Contact\ManageTags\Services;

use App\Interfaces\ServiceInterface;
use App\Models\Contact;
use App\Services\BaseService;

class DetachTagFromContact extends BaseService implements ServiceInterface
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
            'tag_id'     => 'required|integer|exists:tags,id',
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
     * Detach a single tag from the given contact.
     */
    public function execute(array $data): Contact
    {
        $this->validateRules($data);

        $this->contact->tags()->detach($data['tag_id']);

        return $this->contact;
    }
}
