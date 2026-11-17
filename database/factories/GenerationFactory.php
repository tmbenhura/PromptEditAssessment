<?php

namespace Database\Factories;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    protected $model = Generation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'pixelforge_id' => 'gen_'.Str::random(24),
            'idempotency_key' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'prompt' => $this->faker->sentence(10),
            'size' => GenerationSize::Square,
            'quality' => GenerationQuality::Medium,
            'reference_images' => [],
            'status' => GenerationStatus::Queued,
            'image_url' => null,
            'image_url_expires_at' => null,
            'usage' => null,
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'estimated_provider_cost' => '0.050000000',
            'estimated_user_cost' => '0.062500000',
            'actual_provider_cost' => '0.000000000',
            'actual_user_cost' => '0.000000000',
            'error_code' => null,
            'error_message' => null,
            'pixelforge_created_at' => now(),
            'pixelforge_started_at' => null,
            'pixelforge_completed_at' => null,
        ];
    }

    public function succeeded(): static
    {
        return $this->state(fn () => [
            'status' => GenerationStatus::Succeeded,
            'image_url' => 'https://pixelforge.promptedit.dev/images/fake.png',
            'image_url_expires_at' => now()->addDay(),
            'usage' => [
                'input_text_tokens' => 15,
                'input_image_tokens' => 0,
                'output_tokens' => 1100,
                'reasoning_tokens' => 500,
                'total_tokens' => 1615,
            ],
            'actual_provider_cost' => '0.050325000',
            'actual_user_cost' => '0.062906250',
            'pixelforge_started_at' => now()->subSeconds(20),
            'pixelforge_completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => GenerationStatus::Failed,
            'error_code' => 'generation_failed',
            'error_message' => 'The model could not produce an image for the prompt.',
            'actual_provider_cost' => '0.000000000',
            'actual_user_cost' => '0.000000000',
            'pixelforge_started_at' => now()->subSeconds(10),
            'pixelforge_completed_at' => now(),
        ]);
    }
}
