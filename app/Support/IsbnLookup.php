<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Fills in a book's details from its ISBN using free public catalogues (Open Library, then Google Books).
 * Only the ISBN is sent. Results are cached so the same book is never looked up twice.
 */
class IsbnLookup
{
    protected const LANGUAGES = ['en' => 'English', 'hi' => 'Hindi', 'mr' => 'Marathi', 'gu' => 'Gujarati', 'bn' => 'Bengali', 'ta' => 'Tamil', 'te' => 'Telugu', 'kn' => 'Kannada',
        'ml' => 'Malayalam', 'pa' => 'Punjabi', 'ur' => 'Urdu', 'sa' => 'Sanskrit', 'fr' => 'French', 'de' => 'German', 'es' => 'Spanish', 'ar' => 'Arabic'];

    /**
     * @return array{title: string, subtitle: ?string, author: ?string, publisher: ?string, publication_year: ?int, language: ?string, description: ?string, source: string}|null
     */
    public static function find(string $isbn): ?array
    {
        $isbn = Isbn::normalize($isbn);
        if ($isbn === null || ! Isbn::valid($isbn)) {
            return null;
        }

        $hit = Cache::get("isbn-lookup:{$isbn}");
        if ($hit !== null) {
            return $hit ?: null;      // false = looked up before, nothing found
        }

        $found = self::openLibrary($isbn) ?? self::googleBooks($isbn);
        Cache::put("isbn-lookup:{$isbn}", $found ?: false, $found ? now()->addDays(30) : now()->addDay());

        return $found;
    }

    protected static function openLibrary(string $isbn): ?array
    {
        try {
            $res = Http::timeout(6)->acceptJson()->get('https://openlibrary.org/api/books', ['bibkeys' => "ISBN:{$isbn}", 'format' => 'json', 'jscmd' => 'data']);
            $d = $res->successful() ? ($res->json("ISBN:{$isbn}") ?: null) : null;
        } catch (\Throwable) {
            return null;
        }

        if (! $d || blank($d['title'] ?? null)) {
            return null;
        }

        return self::clean([
            'title' => $d['title'], 'subtitle' => $d['subtitle'] ?? null,
            'author' => collect($d['authors'] ?? [])->pluck('name')->filter()->implode(', '),
            'publisher' => collect($d['publishers'] ?? [])->pluck('name')->filter()->first(),
            'publication_year' => self::year($d['publish_date'] ?? null),
            'language' => null, 'description' => null, 'source' => 'Open Library',
        ]);
    }

    protected static function googleBooks(string $isbn): ?array
    {
        try {
            $res = Http::timeout(6)->acceptJson()->get('https://www.googleapis.com/books/v1/volumes', ['q' => "isbn:{$isbn}", 'maxResults' => 1]);
            $v = $res->successful() ? ($res->json('items.0.volumeInfo') ?: null) : null;
        } catch (\Throwable) {
            return null;
        }

        if (! $v || blank($v['title'] ?? null)) {
            return null;
        }

        return self::clean([
            'title' => $v['title'], 'subtitle' => $v['subtitle'] ?? null, 'author' => implode(', ', (array) ($v['authors'] ?? [])),
            'publisher' => $v['publisher'] ?? null, 'publication_year' => self::year($v['publishedDate'] ?? null),
            'language' => self::LANGUAGES[$v['language'] ?? ''] ?? null, 'description' => $v['description'] ?? null, 'source' => 'Google Books',
        ]);
    }

    protected static function year(mixed $date): ?int
    {
        if (is_string($date) && preg_match('/(1[0-9]{3}|20[0-9]{2})/', $date, $m)) {
            $year = (int) $m[1];

            return $year <= (int) today()->year + 1 ? $year : null;
        }

        return null;
    }

    protected static function clean(array $d): array
    {
        $text = fn ($v, $max) => is_string($v) && trim(strip_tags($v)) !== '' ? mb_substr(trim(strip_tags($v)), 0, $max) : null;

        return [
            'title' => $text($d['title'], 255), 'subtitle' => $text($d['subtitle'], 255), 'author' => $text($d['author'], 255), 'publisher' => $text($d['publisher'], 255),
            'publication_year' => $d['publication_year'], 'language' => $d['language'], 'description' => $text($d['description'], 5000), 'source' => $d['source'],
        ];
    }
}
