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

            $resolutions = ['1080', '720', '480', '360'];
            $previewResolution = null;

            foreach ($resolutions as $res) {
                if ($video->{"{$res}_path"} && $video->{"{$res}_size"}) {
                    $previewResolution = $res;
                    break;
                }
            }

            if ($previewResolution) {
                $video->preview_url = url("api/videos/{$video->id}/stream/{$previewResolution}");
            } else {
                $video->preview_url = null;
            }

            $video->thumbnail_url = null;
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
            'title' => 'nullable|string',
            'resolution' => 'required|string|in:360,480,720,1080'
        ]);

        $ext = strtolower(pathinfo($request->filename, PATHINFO_EXTENSION));
        if (!in_array($ext, $this->allowedExtensions)) {
            return response()->json(['error' => 'Format tidak didukung'], 415);
        }

        $resolution = $request->resolution;
        $uploadId = md5($request->filename . '_' . $resolution);
        $finalFileName = $uploadId . '.' . $ext;


        $videoId = md5($request->filename);
        $resolutionPath = $this->videoStorage . '/' . $videoId . '/' . $resolution;
        $finalPath = $resolutionPath . '/' . $finalFileName;

        if ($request->index === 0 && file_exists($finalPath)) {
            unlink($finalPath);
        }

        if (!is_dir($resolutionPath)) {
            mkdir($resolutionPath, 0777, true);
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


            $video = Video::where('filename', $videoId)->first();

            if (!$video) {
                $video = Video::create([
                    'title' => $request->title ?? 'Untitled Video',
                    'filename' => $videoId,
                    'status' => 0,
                ]);
            }


            $video->update([
                "{$resolution}_path" => "videos/{$videoId}/{$resolution}/{$finalFileName}",
                "{$resolution}_mime_type" => $mimeTypes[$extension] ?? 'application/octet-stream',
                "{$resolution}_size" => filesize($finalPath),
            ]);


            try {
                $safelinkResponse = Http::withHeaders([
                    'Authorization' => 'Bearer ' . config('services.safelink.token'),
                    'Content-Type'  => 'application/json',
                ])->post('https://safelinku.com/api/v1/links', [
                    'url' => "https://thinkthrift.co-id.id/api/download/{$videoId}/{$resolution}/{$finalFileName}",
                ]);

                if ($safelinkResponse->successful()) {
                    $safelinkData = $safelinkResponse->json();
                    $video->update([
                        "{$resolution}_safelink" => $safelinkData['url'] ?? null,
                    ]);
                }
            } catch (\Exception $e) {
                \Log::error("Safelink gagal untuk resolusi {$resolution}: " . $e->getMessage());
            }


            $this->checkAndUpdateVideoStatus($video);

            return response()->json([
                'share_url' => rtrim(config('app.frontend_url'), '/') . "/video/{$video->id}",
                'message' => "Upload completed for {$resolution}p resolution",
                'video' => $video,
                'title' => $video->title,
                'filename' => $video->filename,
                'resolution' => $resolution,
                'download_url' => $video->{"{$resolution}_safelink"} ?? null,
            ]);
        }

        return response()->json([
            'message' => "Chunk {$request->index} uploaded for {$resolution}p resolution"
        ]);
    }

    private function checkAndUpdateVideoStatus(Video $video)
    {
        $resolutions = ['360', '480', '720', '1080'];
        $hasAnyResolution = false;

        foreach ($resolutions as $res) {
            if ($video->{"{$res}_path"} && $video->{"{$res}_size"}) {
                $hasAnyResolution = true;
                break;
            }
        }

        if ($hasAnyResolution) {
            $video->update(['status' => 1]);
        }
    }

    public function show($id)
    {
        $video = Video::findOrFail($id);


        $availableResolutions = $this->getAvailableResolutions($video);

        return response()->json([
            'share_url' => rtrim(config('app.frontend_url'), '/') . "/video/{$video->id}",
            'video' => $video,
            'title' => $video->title,
            'available_resolutions' => $availableResolutions,
            'stream_urls' => $this->getStreamUrls($video),
            'download_urls' => $this->getDownloadUrls($video),
        ]);
    }

    private function getAvailableResolutions(Video $video)
    {
        $resolutions = ['360', '480', '720', '1080'];
        $available = [];

        foreach ($resolutions as $res) {
            if ($video->{"{$res}_path"} && $video->{"{$res}_size"}) {
                $available[] = [
                    'resolution' => $res,
                    'path' => $video->{"{$res}_path"},
                    'size' => $video->{"{$res}_size"},
                    'mime_type' => $video->{"{$res}_mime_type"},
                    'safelink' => $video->{"{$res}_safelink"},
                    'stream_url' => url("api/videos/{$video->id}/stream/{$res}"),
                    'download_url' => url("api/videos/{$video->id}/download/{$res}"),
                ];
            }
        }

        return $available;
    }

    private function getStreamUrls(Video $video)
    {
        $resolutions = ['360', '480', '720', '1080'];
        $urls = [];

        foreach ($resolutions as $res) {
            if ($video->{"{$res}_path"} && $video->{"{$res}_size"}) {
                $urls["{$res}p"] = url("api/videos/{$video->id}/stream/{$res}");
            }
        }

        return $urls;
    }

    private function getDownloadUrls(Video $video)
    {
        $resolutions = ['360', '480', '720', '1080'];
        $urls = [];

        foreach ($resolutions as $res) {
            if ($video->{"{$res}_path"} && $video->{"{$res}_size"}) {
                $urls["{$res}p"] = url("api/videos/{$video->id}/download/{$res}");
            }
        }

        return $urls;
    }

    public function downloadByResolution($videoId, $resolution)
    {
        $video = Video::findOrFail($videoId);

        if (!$video->{"{$resolution}_path"} || !$video->{"{$resolution}_size"}) {
            return response()->json(['error' => "Resolusi {$resolution}p tidak tersedia"], 404);
        }

        $filePath = storage_path("app/{$video->{"{$resolution}_path"}}");

        if (!file_exists($filePath)) {
            return response()->json(['error' => 'File tidak ditemukan'], 404);
        }

        $fileExtension = pathinfo($filePath, PATHINFO_EXTENSION);
        $downloadFilename = $video->filename . "_{$resolution}p." . $fileExtension;

        return response()->download($filePath, $downloadFilename, [
            'Content-Type' => $video->{"{$resolution}_mime_type"},
            'Content-Disposition' => 'attachment; filename="' . $downloadFilename . '"'
        ]);
    }

    public function streamByResolution($videoId, $resolution, Request $request)
    {
        $video = Video::findOrFail($videoId);

        if (!$video->{"{$resolution}_path"} || !$video->{"{$resolution}_size"}) {
            return response()->json(['error' => "Resolusi {$resolution}p tidak tersedia"], 404);
        }

        $filePath = storage_path("app/{$video->{"{$resolution}_path"}}");

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
            header("Content-Type: {$video->{"{$resolution}_mime_type"}}");
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

            header("Content-Type: {$video->{"{$resolution}_mime_type"}}");
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
