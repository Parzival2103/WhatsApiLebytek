<?php

namespace App\Models\Sys;

use Database\Factories\Sys\KvFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'value'])]
class Kv extends Model
{
    /** @use HasFactory<KvFactory> */
    use HasFactory;

    protected $table = 'sys_kv';

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }
}
