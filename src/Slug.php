<?php

declare(strict_types=1);

function slugify(string $text): string
{
    $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT', $text);
    $text = $transliterated !== false ? $transliterated : $text;
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';

    return trim($text, '-');
}

/**
 * Gera o slug do link individual do convidado titular, ex: "jose-14".
 * Usa apenas o primeiro nome para manter o link curto; o id garante
 * unicidade entre convidados com o mesmo primeiro nome.
 */
function generate_guest_slug(string $name, int $id): string
{
    $base = slugify($name);
    $firstName = $base !== '' ? explode('-', $base)[0] : 'convidado';

    return $firstName . '-' . $id;
}
