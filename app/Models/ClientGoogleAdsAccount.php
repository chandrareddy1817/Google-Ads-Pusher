<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientGoogleAdsAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'refresh_token',
        'customer_id',
        'manager_customer_id',
    ];

    // Optional: Automatically encrypt the refresh token in the database
    protected $casts = [
        'refresh_token' => 'encrypted',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}