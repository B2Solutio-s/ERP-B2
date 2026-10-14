<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Puesto extends Model
{
    protected $fillable = [
        'nombre',
        'area_id',
        'tipo_contrato',
        'usa_campana',
    ];

    protected function casts(): array
    {
        return ['usa_campana' => 'boolean'];
    }

    public function area()
    {
        return $this->belongsTo(Area::class);
    }
}
