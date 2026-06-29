<?php

namespace App\Models\Integration;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\Integration\CredencialFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'provider', 'credentials'])]
class Credencial extends Model
{
    /** @use HasFactory<CredencialFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'int_credenciales';

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
        ];
    }
}
