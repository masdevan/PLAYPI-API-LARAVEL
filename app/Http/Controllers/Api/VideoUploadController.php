<?php

namespace App\Http\Controllers\Api;

use App\Models\Video;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class VideoUploadController extends Controller
{
    private array $allowedExtensions = ['mp4', 'webm', 'avi', 'mov'];
    private string $videoStorage;

    public function __construct()
    {
        $this->videoStorage = storage_path('app/videos');
    }

    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 10);
        $page = $request->get('page', 1);

        $videos = Video::orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        $videosWithPreview = $videos->getCollection()->map(function ($video) {
            $video->preview_url = url("api/stream/{$video->filename}");
            $video->thumbnail_url = url("api/thumbnail/{$video->filename}");
            return $video;
        });

        return response()->json([
            'videos' => $videosWithPreview,
            'pagination' => [
                'current_page' => $videos->currentPage(),
                'last_page' => $videos->lastPage(),
                'per_page' => $videos->perPage(),
                'total' => $videos->total(),
                'has_more_pages' => $videos->hasMorePages(),
                'next_page_url' => $videos->nextPageUrl(),
                'prev_page_url' => $videos->previousPageUrl()
            ]
        ]);
    }

    public function VideoUpload(Request $request)
    {
        $request->validate([
            'chunk' => 'required|file',
            'index' => 'required|integer',
            'total' => 'required|integer',
            'filename' => 'required|string',
            'title' => 'nullable|string'
        ]);

        $ext = strtolower(pathinfo($request->filename, PATHINFO_EXTENSION));

        if (!in_array($ext, $this->allowedExtensions)) {
            return response()->json(['error' => 'Format tidak didukung'], 415);
        }

        $uploadId = md5($request->filename);
        $chunkDir = storage_path("app/chunks/{$uploadId}");
        if (!is_dir($chunkDir)) {
            mkdir($chunkDir, 0777, true);
        }

        $chunkPath = $chunkDir . '/' . $request->index;
        $request->file('chunk')->move($chunkDir, $request->index);

        if ($request->index + 1 == $request->total) {
            $finalFileName = uniqid() . '.' . $ext;
            $finalPath = $this->videoStorage . '/' . $finalFileName;

            if (!is_dir($this->videoStorage)) {
                mkdir($this->videoStorage, 0777, true);
            }

            $output = fopen($finalPath, 'ab');
            for ($i = 0; $i < $request->total; $i++) {
                $chunkFile = $chunkDir . '/' . $i;
                $input = fopen($chunkFile, 'rb');
                stream_copy_to_stream($input, $output);
                fclose($input);
                unlink($chunkFile);
            }
            fclose($output);
            rmdir($chunkDir);

            $video = Video::create([
                'title' => $request->title ?? 'Untitled Video',
                'filename' => uniqid(),
                'path' => "videos/{$finalFileName}",
                'mime_type' => mime_content_type($finalPath),
                'size' => filesize($finalPath),
                'status' => 1,
            ]);

            return response()->json([
                'message' => 'Upload completed',
                'video' => $video,
                'filename' => $video->filename
            ]);
        }

        return response()->json([
            'message' => "Chunk {$request->index} uploaded"
        ]);
    }

    public function show($id)
    {
        $video = Video::findOrFail($id);

        return response()->json([
            'video' => $video,
            'stream_url' => url("api/videos/{$video->id}/stream"),
            'download_url' => url("api/download/{$video->filename}")
        ]);
    }

    public function downloadByFilename($filename)
    {
        $filenameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);

        $video = Video::where('filename', $filenameWithoutExt)->first();

        if (!$video) {
            return response()->json(['error' => 'Video tidak ditemukan'], 404);
        }

        $filePath = storage_path("app/{$video->path}");

        if (!file_exists($filePath)) {
            return response()->json(['error' => 'File tidak ditemukan'], 404);
        }

        $fileExtension = pathinfo($filePath, PATHINFO_EXTENSION);
        $downloadFilename = $video->filename . '.' . $fileExtension;

        return response()->download($filePath, $downloadFilename, [
            'Content-Type' => $video->mime_type,
            'Content-Disposition' => 'attachment; filename="' . $downloadFilename . '"'
        ]);
    }

    public function streamByFilename($filename, Request $request)
    {
        // Hapus extension jika ada
        $filenameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);

        $video = Video::where('filename', $filenameWithoutExt)->first();

        if (!$video) {
            return response()->json(['error' => 'Video tidak ditemukan'], 404);
        }

        $filePath = storage_path("app/{$video->path}");

        if (!file_exists($filePath)) {
            return response()->json(['error' => 'File tidak ditemukan'], 404);
        }

        $fileSize = filesize($filePath);
        $range = $request->header('Range');

        if ($range) {
            $ranges = array_map('trim', explode('=', $range));
            $ranges = array_map('trim', explode('-', $ranges[1]));

            $start = $ranges[0];
            $end = $ranges[1] ?: $fileSize - 1;

            $length = $end - $start + 1;

            $file = fopen($filePath, 'rb');
            fseek($file, $start);

            $buffer = 1024 * 8;
            $sent = 0;

            header('HTTP/1.1 206 Partial Content');
            header("Accept-Ranges: bytes");
            header("Content-Range: bytes {$start}-{$end}/{$fileSize}");
            header("Content-Length: {$length}");
            header("Content-Type: {$video->mime_type}");
            header("Cache-Control: public, max-age=31536000");

            while (!feof($file) && $sent < $length) {
                $remaining = $length - $sent;
                $chunkSize = min($buffer, $remaining);
                $chunk = fread($file, $chunkSize);

                if ($chunk === false) {
                    break;
                }

                echo $chunk;
                $sent += strlen($chunk);

                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
            }

            fclose($file);
            exit;
        } else {
            $file = fopen($filePath, 'rb');

            header("Content-Type: {$video->mime_type}");
            header("Content-Length: {$fileSize}");
            header("Accept-Ranges: bytes");
            header("Cache-Control: public, max-age=31536000");

            $buffer = 1024 * 8;

            while (!feof($file)) {
                $chunk = fread($file, $buffer);

                if ($chunk === false) {
                    break;
                }

                echo $chunk;

                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
            }

            fclose($file);
            exit;
        }
    }

    public function thumbnailByFilename($filename)
    {
        $filenameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);

        $video = Video::where('filename', $filenameWithoutExt)->first();

        if (!$video) {
            return response()->json(['error' => 'Video tidak ditemukan'], 404);
        }

        $filePath = storage_path("app/{$video->path}");

        if (!file_exists($filePath)) {
            return response()->json(['error' => 'File tidak ditemukan'], 404);
        }

        $thumbnailPath = storage_path("app/thumbnails/{$video->id}.jpg");
        $thumbnailDir = dirname($thumbnailPath);

        if (!is_dir($thumbnailDir)) {
            mkdir($thumbnailDir, 0777, true);
        }

        if (!file_exists($thumbnailPath)) {
            if (extension_loaded('gd')) {
                $width = 320;
                $height = 180;

                $image = imagecreatetruecolor($width, $height);
                $bgColor = imagecolorallocate($image, 25, 25, 112);
                imagefill($image, 0, 0, $bgColor);
                $textColor = imagecolorallocate($image, 255, 255, 255);

                $title = substr($video->title, 0, 20) . (strlen($video->title) > 20 ? '...' : '');
                $fontSize = 3;
                $titleWidth = strlen($title) * imagefontwidth($fontSize);
                $titleX = ($width - $titleWidth) / 2;
                $titleY = ($height / 2) - 20;

                imagestring($image, $fontSize, $titleX, $titleY, $title, $textColor);
                $playText = "▶ PLAY";
                $playWidth = strlen($playText) * imagefontwidth($fontSize);
                $playX = ($width - $playWidth) / 2;
                $playY = ($height / 2) + 20;

                imagestring($image, $fontSize, $playX, $playY, $playText, $textColor);

                imagejpeg($image, $thumbnailPath, 80);
                imagedestroy($image);
            } else {
                return response()->json([
                    'message' => 'GD library tidak tersedia untuk generate thumbnail',
                    'video_info' => [
                        'title' => $video->title,
                        'filename' => $video->filename,
                        'size' => $video->size
                    ]
                ]);
            }
        }

        if (file_exists($thumbnailPath)) {
            $mimeType = 'image/jpeg';
            $fileSize = filesize($thumbnailPath);

            header("Content-Type: {$mimeType}");
            header("Content-Length: {$fileSize}");
            header("Cache-Control: public, max-age=31536000");

            readfile($thumbnailPath);
            exit;
        }

        return response()->json(['error' => 'Thumbnail tidak ditemukan'], 404);
    }
}
