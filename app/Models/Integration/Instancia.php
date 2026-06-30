<?php

namespace App\Models\Integration;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Core\Tenant;
use Database\Factories\Integration\InstanciaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable([
    'tenant_id',
    'id_instance',
    'api_token_instance',
    'provider',
    'label',
    'purpose',
    'status',
    'green_state',
    'external_ref',
    'qr_code',
    'qr_expires_at',
    'last_error',
    'meta',
    'authorized_at',
])]
class Instancia extends Model
{
    /** @use HasFactory<InstanciaFactory> */
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $table = 'int_instancias';

    protected static function booted(): void
    {
        static::creating(function (Instancia $instancia): void {
            if (empty($instancia->public_id)) {
                $instancia->public_id = (string) Str::ulid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    protected function casts(): array
    {
        return [
            'api_token_instance' => 'encrypted',
            'meta' => 'array',
            'qr_expires_at' => 'datetime',
            'authorized_at' => 'datetime',
        ];
    }
}
