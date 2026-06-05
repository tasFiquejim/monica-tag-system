<?php

namespace Database\Factories;

use App\Models\Tag;
use App\Models\Vault;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Tag>
 */
class TagFactory extends Factory
{
    protected $model = Tag::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'vault_id'     => Vault::factory(),
            'name'         => $this->faker->unique()->word(),
            'slug'         => $this->faker->unique()->slug(2),
            'tag_category' => $this->faker->optional()->randomElement(['Work', 'Personal', 'Networking']),
            'color'        => $this->faker->optional()->hexColor(),
        ];
    }
}

