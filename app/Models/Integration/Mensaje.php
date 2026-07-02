<?php

namespace App\Models\Integration;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\Integration\MensajeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'tenant_id',
    'instancia_id',
    'direction',
    'recipient',
    'body',
    'status',
    'green_message_id',
    'error',
    'payload_hash',
    'sent_at',
])]
class Mensaje extends Model
{
    /** @use HasFactory<MensajeFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'int_mensajes';

    protected static function booted(): void
    {
        static::creating(function (Mensaje $mensaje): void {
            if (empty($mensaje->public_id)) {
                $mensaje->public_id = (string) Str::ulid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function instancia(): BelongsTo
    {
        return $this->belongsTo(Instancia::class, 'instancia_id');
    }

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }
}
