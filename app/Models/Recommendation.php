<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Recommendation extends Model
{
    use HasFactory;

    protected $fillable = [
        'resource_name',
        'type',
        'campaign',
        'title',
        'details',
        'impact_estimate',
        'status'
    ];
}