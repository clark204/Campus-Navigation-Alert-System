<?php

namespace App\Http\Requests\Admin;

use App\Enums\PhotoKind;
use App\Services\PanoramaImages;
use App\Services\PlacePhotos;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Uploading photos to a building or room. The kind is one of the two photo kinds for the whole upload: a normal photo
 * (JPEG, PNG or WebP, up to 8 MB) or a 360° photo (the walkway 360° rules). A place keeps at most
 * PlacePhotos::MAX_PER_PLACE photos, so the number that still fits is checked here.
 */
class StorePlacePhotosRequest extends FormRequest
{
    public const MAX_KILOBYTES = 8 * 1024;

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $place = $this->route('building') ?? $this->route('room');
        $fits = max(0, PlacePhotos::MAX_PER_PLACE - $place->photos()->count());
        $panorama = $this->isPanorama();

        return [
            'kind' => ['required', Rule::enum(PhotoKind::class)],
            'photos' => ['required', 'array', 'max:'.$fits],
            'photos.*' => $panorama
                ? ['bail', 'required', 'file', 'mimes:jpg,jpeg', 'max:'.intdiv(StorePanoramaRequest::maxBytes(), 1024), 'dimensions:ratio=2/1,min_width='.PanoramaImages::MIN_WIDTH]
                : ['bail', 'required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MAX_KILOBYTES],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $panorama = $this->isPanorama();

        return [
            'kind.required' => 'Choose a normal photo or a 360 photo.',
            'photos.required' => 'Choose at least one photo to upload.',
            'photos.max' => 'A place can have up to '.PlacePhotos::MAX_PER_PLACE.' photos. Delete one before adding more.',
            'photos.*.required' => 'Choose a photo to upload.',
            'photos.*.file' => 'That photo could not be uploaded. Try again.',
            'photos.*.mimes' => $panorama ? 'Choose a JPEG photo (.jpg) for a 360 photo.' : 'Choose a JPEG, PNG or WebP photo.',
            'photos.*.max' => $panorama
                ? StorePanoramaRequest::tooBigMessage()
                : 'Each photo must be 8 MB or smaller.',
            'photos.*.dimensions' => 'Choose a full 360° photo: twice as wide as it is tall, at least '.PanoramaImages::MIN_WIDTH.' pixels wide.',
        ];
    }

    private function isPanorama(): bool
    {
        return $this->input('kind') === PhotoKind::Panorama->value;
    }
}
