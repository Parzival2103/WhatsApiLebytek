<?php

namespace App\Models\Cfg;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\Cfg\ConfiguracionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'key', 'value'])]
class Configuracion extends Model
{
    /** @use HasFactory<ConfiguracionFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'cfg_configuraciones';

    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Core\Tenant::class, 'tenant_id');
    }
}
