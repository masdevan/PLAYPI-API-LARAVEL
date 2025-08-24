<?php

namespace App\Http\Controllers\Api;

use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class VideoUploadController extends Controller
{
    private array $allowedExtensions = ['mp4', 'webm', 'avi', 'mov'];
    private array $allowedImageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    private string $videoStorage;
    private string $imageStorage;

    public function __construct()
    {
        $this->videoStorage = storage_path('app/videos');
        $this->imageStorage = storage_path('app/images');
    }

    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 10);
        $page = $request->get('page', 1);

        $videos = Video::orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        if ($videos->isEmpty()) {
            return response()->json([
                'videos' => [],
                'message' => 'Tidak ada video yang ditemukan',
                'pagination' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => $perPage,
                    'total' => 0,
                    'has_more_pages' => false,
                    'next_page_url' => null,
                    'prev_page_url' => null
                ]
            ]);
        }

        $videosWithPreview = $videos->getCollection()->map(function ($video) {

            $videoArray = $video->toArray();
            $videoArray['preview_url'] = $this->getVideoSignedUrl($video);
            $videoArray['image_url'] = $this->getImageSignedUrl($video);
            return $videoArray;
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
            'image' => 'nullable|file|mimes:jpg,jpeg,png,gif,webp|max:2048'
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


            $videoPath = "videos/{$finalFileName}";
            $videoContent = file_get_contents($finalPath);
            Storage::disk('wasabi')->put($videoPath, $videoContent);


            $videoSignedUrlData = $this->generateVideoSignedUrl($videoPath);
            $videoSignedUrl = $videoSignedUrlData['url'];
            $videoExpiresAt = $videoSignedUrlData['expires_at'];

            $imagePath = null;
            $imageSignedUrl = null;
            $imageExpiresAt = null;

            if ($request->hasFile('image')) {
                $image = $request->file('image');
                $imageExt = strtolower($image->getClientOriginalExtension());

                if (in_array($imageExt, $this->allowedImageExtensions)) {
                    $imageFileName = uniqid() . '.' . $imageExt;
                    $imagePath = "images/{$imageFileName}";


                    $imageContent = file_get_contents($image->getPathname());
                    Storage::disk('wasabi')->put($imagePath, $imageContent);


                    $signedUrlData = $this->generateImageSignedUrl($imagePath);
                    $imageSignedUrl = $signedUrlData['url'];
                    $imageExpiresAt = $signedUrlData['expires_at'];
                }
            }

            $video = Video::create([
                'title' => $request->title ?? 'Untitled Video',
                'filename' => uniqid(),
                'path' => $videoPath,
                'mime_type' => $mimeTypes[$extension] ?? 'application/octet-stream',
                'size' => filesize($finalPath),
                'status' => 1,
                'image_path' => $imagePath,
                'image_signed_url' => $imageSignedUrl,
                'image_expires_at' => $imageExpiresAt,
                'video_signed_url' => $videoSignedUrl,
                'video_expires_at' => $videoExpiresAt,
            ]);

            try {
                $safelinkResponse = Http::withHeaders([
                    'Authorization' => 'Bearer ' . config('services.safelink.token'),
                    'Content-Type'  => 'application/json',
                ])->post('https://safelinku.com/api/v1/links', [
                    'url' => "https://api.playpi.space/api/download/{$finalFileName}",
                ]);

                if ($safelinkResponse->successful()) {
                    $safelinkData = $safelinkResponse->json();
                    $video->update([
                        'safelink' => $safelinkData['url'] ?? null,
                    ]);
                }
            } catch (\Exception $e) {
                Log::error("Safelink gagal: " . $e->getMessage());
            }


            if (file_exists($finalPath)) {
                unlink($finalPath);
            }

            return response()->json([
                'share_url' => rtrim(config('app.frontend_url'), '/') . "/video/{$video->id}",
                'message' => 'Upload completed',
                'video' => $video,
                'title' => $video->title,
                'filename' => $video->filename,
                'download_url' => $video->safelink ?? null,
                'thumbnail_url' => $this->getImageSignedUrl($video),
                'video_url' => $this->getVideoSignedUrl($video),
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
            'share_url' => rtrim(config('app.frontend_url'), '/') . "/video/{$video->id}",
            'video' => $video,
            'title' => $video->title,
            'stream_url' => $this->getVideoSignedUrl($video),
            'download_url' => $video->safelink ?? null,
            'thumbnail_url' => $this->getImageSignedUrl($video),
        ]);
    }

    public function downloadByFilename($filename)
    {
        $filenameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);
        $video = Video::where('filename', $filenameWithoutExt)->first();

        if (!$video) {
            return response()->json(['error' => 'Video tidak ditemukan'], 404);
        }


        if (!Storage::disk('wasabi')->exists($video->path)) {
            return response()->json(['error' => 'File tidak ditemukan'], 404);
        }


        try {
            $signedUrl = Storage::disk('wasabi')->temporaryUrl(
                $video->path,
                now()->addMinutes(5),
                [
                    'ResponseContentType' => $video->mime_type,
                    'ResponseContentDisposition' => 'attachment; filename="' . $video->filename . '.' . pathinfo($video->path, PATHINFO_EXTENSION) . '"'
                ]
            );

            return response()->json([
                'download_url' => $signedUrl,
                'expires_in' => '5 minutes'
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to generate download URL: " . $e->getMessage());
            return response()->json(['error' => 'Gagal membuat URL download'], 500);
        }
    }

    public function streamByFilename($filename, Request $request)
    {
        $filenameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);
        $video = Video::where('filename', $filenameWithoutExt)->first();

        if (!$video) {
            return response()->json(['error' => 'Video tidak ditemukan'], 404);
        }


        if (!Storage::disk('wasabi')->exists($video->path)) {
            return response()->json(['error' => 'File tidak ditemukan'], 404);
        }


        try {
            $signedUrl = Storage::disk('wasabi')->temporaryUrl(
                $video->path,
                now()->addHour(),
                [
                    'ResponseContentType' => $video->mime_type,
                    'ResponseContentDisposition' => 'inline'
                ]
            );

            return response()->json([
                'stream_url' => $signedUrl,
                'expires_in' => '1 hour'
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to generate stream URL: " . $e->getMessage());
            return response()->json(['error' => 'Gagal membuat URL streaming'], 500);
        }
    }

    private function generateImageSignedUrl($imagePath)
    {
        try {

            $signedUrl = Storage::disk('wasabi')->temporaryUrl(
                $imagePath,
                now()->addHour(),
                ['ResponseContentType' => 'image/*']
            );

            return [
                'url' => $signedUrl,
                'expires_at' => now()->addHour()
            ];
        } catch (\Exception $e) {
            Log::error("Failed to generate signed URL for image: " . $e->getMessage());
            return [
                'url' => null,
                'expires_at' => null
            ];
        }
    }

    private function generateVideoSignedUrl($videoPath)
    {
        try {

            $signedUrl = Storage::disk('wasabi')->temporaryUrl(
                $videoPath,
                now()->addHour(),
                ['ResponseContentType' => 'video/*']
            );

            return [
                'url' => $signedUrl,
                'expires_at' => now()->addHour()
            ];
        } catch (\Exception $e) {
            Log::error("Failed to generate signed URL for video: " . $e->getMessage());
            return [
                'url' => null,
                'expires_at' => null
            ];
        }
    }

    private function getImageSignedUrl($video)
    {
        if (!$video->image_path) {
            return null;
        }


        if ($video->image_signed_url && $video->image_expires_at) {

            $expiresAt = $video->image_expires_at instanceof \Carbon\Carbon
                ? $video->image_expires_at
                : \Carbon\Carbon::parse($video->image_expires_at);

            if ($expiresAt->isFuture()) {
                return $video->image_signed_url;
            }
        }


        $signedUrlData = $this->generateImageSignedUrl($video->image_path);

        if ($signedUrlData['url']) {

            $video->update([
                'image_signed_url' => $signedUrlData['url'],
                'image_expires_at' => $signedUrlData['expires_at']
            ]);

            return $signedUrlData['url'];
        }

        return null;
    }

    private function getVideoSignedUrl($video)
    {
        if (!$video->path) {
            return null;
        }


        if ($video->video_signed_url && $video->video_expires_at) {

            $expiresAt = $video->video_expires_at instanceof \Carbon\Carbon
                ? $video->video_expires_at
                : \Carbon\Carbon::parse($video->video_expires_at);

            if ($expiresAt->isFuture()) {
                return $video->video_signed_url;
            }
        }


        $signedUrlData = $this->generateVideoSignedUrl($video->path);

        if ($signedUrlData['url']) {

            $video->update([
                'video_signed_url' => $signedUrlData['url'],
                'video_expires_at' => $signedUrlData['expires_at']
            ]);

            return $signedUrlData['url'];
        }

        return null;
    }

    public function serveImage($filename)
    {

        $localFilePath = $this->imageStorage . '/' . $filename;

        if (file_exists($localFilePath)) {
            $extension = strtolower(pathinfo($localFilePath, PATHINFO_EXTENSION));
            $mimeTypes = [
                'jpg'  => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png'  => 'image/png',
                'gif'  => 'image/gif',
                'webp' => 'image/webp',
            ];

            $mimeType = $mimeTypes[$extension] ?? 'application/octet-stream';

            return response()->file($localFilePath, [
                'Content-Type' => $mimeType,
                'Cache-Control' => 'public, max-age=31536000'
            ]);
        }


        $wasabiPath = "images/{$filename}";

        if (Storage::disk('wasabi')->exists($wasabiPath)) {
            try {
                $signedUrl = Storage::disk('wasabi')->temporaryUrl(
                    $wasabiPath,
                    now()->addHour(),
                    ['ResponseContentType' => 'image/*']
                );

                return response()->json([
                    'image_url' => $signedUrl
                ]);
            } catch (\Exception $e) {
                Log::error("Failed to generate signed URL for image: " . $e->getMessage());
                return response()->json(['error' => 'Image tidak ditemukan'], 404);
            }
        }

        return response()->json(['error' => 'Image tidak ditemukan'], 404);
    }
}
