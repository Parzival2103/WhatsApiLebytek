<?php

namespace App\Models\Cfg;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\Cfg\CatalogoAuxiliarFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'catalog', 'code', 'label', 'meta', 'sort_order', 'is_active'])]
class CatalogoAuxiliar extends Model
{
    /** @use HasFactory<CatalogoAuxiliarFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'cfg_catalogos_auxiliares';

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
