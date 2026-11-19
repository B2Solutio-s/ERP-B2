<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Ajuste general clave → valor. */
class Ajuste extends Model
{
    protected $table = 'ajustes';

    protected $primaryKey = 'clave';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['clave', 'valor'];

    public static function obtener(string $clave, ?string $porDefecto = null): ?string
    {
        $valor = static::query()->whereKey($clave)->value('valor');

        return $valor === null ? $porDefecto : $valor;
    }

    public static function guardar(string $clave, ?string $valor): void
    {
        static::query()->updateOrCreate(['clave' => $clave], ['valor' => $valor]);
    }
}
