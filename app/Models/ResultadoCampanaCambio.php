<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResultadoCampanaCambio extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'resultado_campana_cambios';

    protected $fillable = [
        'resultado_campana_id',
        'user_id',
        'lote',
        'accion',
        'campo',
        'valor_anterior',
        'valor_nuevo',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
