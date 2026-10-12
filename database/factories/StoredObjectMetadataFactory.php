<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Shared\Infrastructure\Persistence\Models\StoredObjectMetadata;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoredObjectMetadata>
 */
final class StoredObjectMetadataFactory extends Factory
{
    protected $model = StoredObjectMetadata::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $contents = fake()->sentence();

        return [
            'disk' => 's3',
            'path' => 'pending/'.fake()->uuid().'.txt',
            'original_name' => 'evidence.txt',
            'content_type' => 'text/plain',
            'byte_size' => strlen($contents),
            'checksum_algorithm' => 'sha256',
            'checksum' => hash('sha256', $contents),
            'classification' => 'confidential',
            'owner_type' => null,
            'owner_id' => null,
            'source_system' => 'test',
            'source_reference' => fake()->uuid(),
            'status' => 'pending',
            'metadata' => [],
            'lock_version' => 1,
            'created_by' => null,
        ];
    }
}
