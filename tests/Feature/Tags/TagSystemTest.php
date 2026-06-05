<?php

namespace Tests\Feature\Tags;

use App\Models\Contact;
use App\Models\Tag;
use App\Models\Vault;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TagSystemTest extends TestCase
{
    use DatabaseTransactions;

    private function setupUserWithVault(): array
    {
        $user  = $this->createUser();
        $vault = $this->createVaultUser($user, Vault::PERMISSION_EDIT);

        return compact('user', 'vault');
    }

    private function vaultUrl(string $vaultId, string $path = ''): string
    {
        return "/api/vaults/{$vaultId}{$path}";
    }

    /** @test */
    public function it_creates_a_tag_and_it_appears_in_the_tag_list(): void
    {
        ['vault' => $vault] = $this->setupUserWithVault();

        $this->postJson($this->vaultUrl($vault->id, '/tags'), [
            'name'         => 'Colleague',
            'tag_category' => 'Work',
            'color'        => '#3b82f6',
        ])->assertStatus(201)
          ->assertJsonFragment(['name' => 'Colleague'])
          ->assertJsonFragment(['tag_category' => 'Work']);

        $listResponse = $this->getJson($this->vaultUrl($vault->id, '/tags'))
            ->assertStatus(200)
            ->assertJsonFragment(['name' => 'Colleague']);

        $tagData = collect($listResponse->json('data'))->firstWhere('name', 'Colleague');

        $this->assertNotNull($tagData);
        $this->assertEquals(0, $tagData['usage_count']);
    }

    /** @test */
    public function it_filters_contacts_by_all_specified_tags_using_and_logic(): void
    {
        ['vault' => $vault] = $this->setupUserWithVault();

        $tagA = Tag::factory()->create(['vault_id' => $vault->id, 'name' => 'Family']);
        $tagB = Tag::factory()->create(['vault_id' => $vault->id, 'name' => 'VIP']);

        $contactBoth  = Contact::factory()->create(['vault_id' => $vault->id]);
        $contactOnlyA = Contact::factory()->create(['vault_id' => $vault->id]);
        $contactNone  = Contact::factory()->create(['vault_id' => $vault->id]);

        $this->postJson(
            $this->vaultUrl($vault->id, "/contacts/{$contactBoth->id}/tags"),
            ['tag_ids' => [$tagA->id, $tagB->id]]
        )->assertStatus(201);

        $this->postJson(
            $this->vaultUrl($vault->id, "/contacts/{$contactOnlyA->id}/tags"),
            ['tag_ids' => [$tagA->id]]
        )->assertStatus(201);

        $ids = collect(
            $this->getJson($this->vaultUrl($vault->id, "/contacts?tags[]={$tagA->id}&tags[]={$tagB->id}"))
                 ->assertStatus(200)
                 ->json('data')
        )->pluck('id')->all();

        $this->assertContains($contactBoth->id, $ids);
        $this->assertNotContains($contactOnlyA->id, $ids);
        $this->assertNotContains($contactNone->id, $ids);
    }

    /** @test */
    public function it_detaches_tag_from_all_contacts_when_deleted(): void
    {
        ['vault' => $vault] = $this->setupUserWithVault();

        $tag     = Tag::factory()->create(['vault_id' => $vault->id, 'name' => 'Client']);
        $contact = Contact::factory()->create(['vault_id' => $vault->id]);

        $this->postJson(
            $this->vaultUrl($vault->id, "/contacts/{$contact->id}/tags"),
            ['tag_ids' => [$tag->id]]
        )->assertStatus(201);

        $this->assertDatabaseHas('taggables', [
            'tag_id'        => $tag->id,
            'taggable_id'   => $contact->id,
            'taggable_type' => Contact::class,
        ]);

        $this->deleteJson($this->vaultUrl($vault->id, "/tags/{$tag->id}"))
             ->assertStatus(200)
             ->assertJson(['deleted' => true]);

        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);

        $this->assertDatabaseMissing('taggables', [
            'tag_id'        => $tag->id,
            'taggable_id'   => $contact->id,
            'taggable_type' => Contact::class,
        ]);
    }

    /** @test */
    public function it_invalidates_the_redis_cache_when_a_tag_is_created(): void
    {
        ['vault' => $vault] = $this->setupUserWithVault();

        $cacheKey = "tags.vault.{$vault->id}";
        Cache::put($cacheKey, ['stale' => true], 600);
        $this->assertTrue(Cache::has($cacheKey));

        $this->postJson($this->vaultUrl($vault->id, '/tags'), ['name' => 'Networking'])
             ->assertStatus(201);

        $this->assertFalse(Cache::has($cacheKey));
    }
}
