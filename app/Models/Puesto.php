<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Puesto extends Model
{
    protected $fillable = [
        'nombre',
    ];

    public function campanas()
    {
        return $this->hasMany(Campana::class);
    }
}
