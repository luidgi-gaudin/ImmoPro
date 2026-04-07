<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'iban',
        'bic',
        'country',
        'address',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
