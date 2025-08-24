<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Video extends Model
{
    protected $fillable = [
        'title', 'filename', 'path', 'mime_type', 'size', 'status', 'safelink', 'image_path', 'video_signed_url', 'video_expires_at', 'image_signed_url', 'image_expires_at'
    ];

    protected $casts = [
        'image_expires_at' => 'datetime',
        'video_expires_at' => 'datetime',
        'status' => 'boolean',
        'size' => 'integer',
    ];
}
