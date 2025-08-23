<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Video extends Model
{
    protected $fillable = [
        'title', 'filename', 'status',
        '360_path', '360_mime_type', '360_size', '360_safelink',
        '480_path', '480_mime_type', '480_size', '480_safelink',
        '720_path', '720_mime_type', '720_size', '720_safelink',
        '1080_path', '1080_mime_type', '1080_size', '1080_safelink',
    ];
}
