<?php

namespace App\Http\Controllers;

use App\Models\Lease;
use App\Models\LeasePhoto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class LeasePhotoController extends Controller
{
    /**
     * Téléverse une photo de l'état des lieux (entrée ou sortie) pour un bail.
     */
    public function store(Request $request, Lease $lease)
    {
        $this->authorize('update', $lease);

        $data = $request->validate([
            'type' => ['required', 'in:entree,sortie'],
            'photo' => ['required', 'image', 'max:8192'],
        ]);

        $file = $request->file('photo');
        $path = $file->store("lease-photos/{$lease->id}/{$data['type']}", 'public');

        $photo = $lease->photos()->create([
            'type' => $data['type'],
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
        ]);

        return response()->json($photo, 201);
    }

    /**
     * Supprime une photo de l'état des lieux (fichier + enregistrement).
     */
    public function destroy(Lease $lease, LeasePhoto $photo)
    {
        $this->authorize('update', $lease);

        abort_if($photo->lease_id !== $lease->id, 404);

        Storage::disk('public')->delete($photo->path);
        $photo->delete();

        return response()->json();
    }
}
