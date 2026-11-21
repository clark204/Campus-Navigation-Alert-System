<?php

namespace App\Http\Requests\Admin;

use App\Services\PanoramaImages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Number;

/**
 * Uploading one 360° photo: a JPEG twice as wide as it is tall, up to MAX_KILOBYTES (or less if the server's
 * php.ini allows less), with an optional name.
 */
class StorePanoramaRequest extends FormRequest
{
    public const MAX_KILOBYTES = 40 * 1024;

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * The biggest photo this server accepts, in bytes: the 40 MB limit or php.ini's upload_max_filesize and
     * post_max_size, whichever is smallest.
     */
    public static function maxBytes(): int
    {
        $server = (int) UploadedFile::getMaxFilesize();

        return $server > 0 ? min(self::MAX_KILOBYTES * 1024, $server) : self::MAX_KILOBYTES * 1024;
    }

    /**
     * The message shown when a photo is too big for the server.
     */
    public static function tooBigMessage(): string
    {
        return 'This photo is too big. The server accepts photos up to '.Number::fileSize(self::maxBytes(), maxPrecision: 0).'.';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:100'],
            'photo' => [
                'bail', 'required', 'file', 'max:'.intdiv(self::maxBytes(), 1024), 'mimes:jpg,jpeg',
                'dimensions:ratio=2/1,min_width='.PanoramaImages::MIN_WIDTH,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.max' => 'Keep the name to 100 characters or fewer.',
            'photo.required' => 'Choose a 360° photo to upload.',
            'photo.uploaded' => self::tooBigMessage(),
            'photo.max' => self::tooBigMessage(),
            'photo.file' => 'Choose a 360° photo to upload.',
            'photo.mimes' => 'Choose a JPEG photo (.jpg).',
            'photo.dimensions' => 'Choose a full 360° photo: twice as wide as it is tall, at least '.PanoramaImages::MIN_WIDTH.' pixels wide.',
        ];
    }
}
