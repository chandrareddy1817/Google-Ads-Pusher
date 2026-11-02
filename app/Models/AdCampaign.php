<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdCampaign extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'campaign_name',
        'platform',
        'target_url',
        'daily_budget',
        'currency',
        'status',
        'response_data',
    ];
    
    // Optional: Cast the JSON response data to an array
    protected $casts = [
        'response_data' => 'array',
    ];
}