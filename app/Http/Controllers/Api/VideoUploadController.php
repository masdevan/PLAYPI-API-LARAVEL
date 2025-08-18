<?php

namespace App\Http\Controllers\Api;

use App\Models\Video;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Http;

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
        $finalFileName = $uploadId . '.' . $ext;
        $finalPath = $this->videoStorage . '/' . $finalFileName;

        if ($request->index === 0 && file_exists($finalPath)) {
            unlink($finalPath);
        }

        if (!is_dir($this->videoStorage)) {
            mkdir($this->videoStorage, 0777, true);
        }

        $out = fopen($finalPath, 'ab');
        $in = fopen($request->file('chunk')->getPathname(), 'rb');
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);

        if ($request->index + 1 == $request->total) {
            $extension = strtolower(pathinfo($finalPath, PATHINFO_EXTENSION));
            $mimeTypes = [
                'mp4'  => 'video/mp4',
                'webm' => 'video/webm',
                'avi'  => 'video/x-msvideo',
                'mov'  => 'video/quicktime',
            ];

            $video = Video::create([
                'title' => $request->title ?? 'Untitled Video',
                'filename' => uniqid(),
                'path' => "videos/{$finalFileName}",
                'mime_type' => $mimeTypes[$extension] ?? 'application/octet-stream',
                'size' => filesize($finalPath),
                'status' => 1,
            ]);

            try {
                $safelinkResponse = Http::withHeaders([
                    'Authorization' => 'Bearer ' . config('services.safelink.token'),
                    'Content-Type'  => 'application/json',
                ])->post('https://safelinku.com/api/v1/links', [
                    'url' => "https://thinkthrift.co-id.id/api/download/{$finalFileName}",
                ]);

                if ($safelinkResponse->successful()) {
                    $safelinkData = $safelinkResponse->json();
                    $video->update([
                        'safelink' => $safelinkData['url'] ?? null,
                    ]);
                }
            } catch (\Exception $e) {
                \Log::error("Safelink gagal: " . $e->getMessage());
            }

            return response()->json([
                'message' => 'Upload completed',
                'video' => $video,
                'filename' => $video->filename,
                'download_url' => $video->safelink ?? null,
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
            'download_url' => $video->safelink ?? null,
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
}
