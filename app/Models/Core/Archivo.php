<?php

namespace App\Models\Core;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\Core\ArchivoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable(['tenant_id', 'disk', 'path', 'original_name', 'mime_type', 'size', 'hash', 'purpose'])]
class Archivo extends Model
{
    /** @use HasFactory<ArchivoFactory> */
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $table = 'core_archivos';

    protected static function booted(): void
    {
        static::creating(function (Archivo $archivo): void {
            if (empty($archivo->public_id)) {
                $archivo->public_id = (string) Str::ulid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
