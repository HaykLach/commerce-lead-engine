<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

abstract class BaseModel extends Model
{
    /**
     * Guard everything by default for safer API-first development.
     * Child models should explicitly define fillable fields.
     *
     * @var array<int, string>
     */
    protected $guarded = ['*'];
}
