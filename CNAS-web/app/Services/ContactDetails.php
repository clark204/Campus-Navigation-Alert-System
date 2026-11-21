<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The CNAS administrator's contact details (site footer, portal Help & contact page), edited on /admin/settings and stored in
 * the settings table as contact.email, contact.phone, contact.facebook and contact.address. A value that was never
 * saved falls back to config/cnas.php ('contact'). Cached until the admin saves.
 */
class ContactDetails
{
    /** The fields, in the order the footer lists them. */
    public const FIELDS = ['email', 'phone', 'facebook', 'address'];

    public const CACHE_KEY = 'settings.contact';

    /**
     * @return array{email: string, phone: string, facebook: string, address: string}
     */
    public function all(): array
    {
        /** @var array<string, string|null> $saved */
        $saved = Cache::rememberForever(self::CACHE_KEY, fn () => Setting::query()
            ->whereIn('key', array_map($this->settingKey(...), self::FIELDS))
            ->pluck('value', 'key')
            ->all());

        $details = [];
        foreach (self::FIELDS as $field) {
            $value = $saved[$this->settingKey($field)] ?? null;
            $details[$field] = filled($value) ? $value : (string) config("cnas.contact.{$field}");
        }

        return $details;
    }

    /**
     * @param  array{email: string, phone: string, facebook: string, address: string}  $details
     */
    public function save(array $details): void
    {
        DB::transaction(function () use ($details): void {
            foreach (self::FIELDS as $field) {
                Setting::updateOrCreate(['key' => $this->settingKey($field)], ['value' => $details[$field]]);
            }
        });

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * The tel: link for a phone written for people ("+63 912-345-678" → "tel:+63912345678").
     */
    public static function telHref(string $phone): string
    {
        return 'tel:'.preg_replace('/[^\d+]/', '', $phone);
    }

    private function settingKey(string $field): string
    {
        return "contact.{$field}";
    }
}
