<?php

namespace App\Models\Core;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\Core\ModuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'module_key', 'is_enabled'])]
class Module extends Model
{
    /** @use HasFactory<ModuleFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'core_modules';

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }
}
