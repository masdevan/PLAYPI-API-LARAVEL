<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Video extends Model
{
    protected $fillable = [
        'title', 'filename', 'path', 'mime_type', 'size', 'status', 'safelink', 'image_path'
    ];
}
