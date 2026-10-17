<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FileManagerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $folder = $request->query('folder');

        $files = MediaFile::where('user_id', $request->user()->id)
            ->when($folder, fn ($q) => $q->where('folder', $folder))
            ->latest()
            ->get();

        return response()->json(['data' => $files]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240'], // 10MB max
            'folder' => ['sometimes', 'string', 'max:50'],
        ]);

        $file = $request->file('file');
        $folder = $data['folder'] ?? 'general';
        $path = $file->store("uploads/{$folder}", 'public');

        $media = MediaFile::create([
            'user_id' => $request->user()->id,
            'branch_id' => $request->user()->branch_id,
            'name' => $file->getClientOriginalName(),
            'path' => '/storage/'.$path,
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
            'folder' => $folder,
        ]);

        return response()->json(['data' => $media], 201);
    }

    public function destroy(Request $request, int $file): JsonResponse
    {
        $item = MediaFile::where('user_id', $request->user()->id)->findOrFail($file);
        $relativePath = str_replace('/storage/', '', $item->path);
        Storage::disk('public')->delete($relativePath);
        $item->delete();

        return response()->json(['message' => 'File deleted successfully.']);
    }
}
