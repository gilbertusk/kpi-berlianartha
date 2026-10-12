<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Persistence\Models;

use App\Modules\Shared\Infrastructure\Persistence\Concerns\HasPublicUlid;
use Database\Factories\StoredObjectMetadataFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property string $disk
 * @property string $path
 * @property string $status
 * @property int $lock_version
 * @property array<string, mixed> $metadata
 */
final class StoredObjectMetadata extends Model
{
    /** @use HasFactory<StoredObjectMetadataFactory> */
    use HasFactory, HasPublicUlid;

    protected $table = 'stored_object_metadata';

    protected $guarded = [];

    protected static function newFactory(): StoredObjectMetadataFactory
    {
        return StoredObjectMetadataFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'byte_size' => 'integer',
            'metadata' => 'array',
            'lock_version' => 'integer',
        ];
    }
}
