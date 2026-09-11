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

/**
 * Gera o slug público de um evento a partir do(s) nome(s) do(s)
 * anfitrião(ões), ex: "pedro-e-maria" — sem sufixo na grande maioria dos
 * casos, já que é uma URL pública/compartilhada (diferente do link
 * individual do convidado). Só quando esse slug "limpo" já está em uso por
 * outro evento é que entra um sufixo curto e ALEATÓRIO (não o id
 * sequencial do banco, pra não expor quantos eventos já existem).
 */
function generate_event_slug(PDO $pdo, string $hostName, ?string $hostNameSecondary): string
{
    $base = $hostNameSecondary !== null && $hostNameSecondary !== ''
        ? slugify($hostName . ' e ' . $hostNameSecondary)
        : slugify($hostName);
    if ($base === '') {
        $base = 'evento';
    }

    $checkStmt = $pdo->prepare('SELECT 1 FROM events WHERE slug = :slug');

    $slug = $base;
    $checkStmt->execute(['slug' => $slug]);
    while ($checkStmt->fetch()) {
        $slug = $base . '-' . bin2hex(random_bytes(2));
        $checkStmt->execute(['slug' => $slug]);
    }

    return $slug;
}
