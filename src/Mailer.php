<?php

declare(strict_types=1);

/**
 * Envia um email transacional via HTTP direto à API da Resend
 * (https://resend.com) — sem Composer/PHPMailer, mantendo a filosofia
 * zero-dependência do resto do backend. Trocar de provedor depois é editar
 * só este arquivo (a chamada HTTP e o formato do payload).
 *
 * Sem config('mail.api_key') (ex: dev local), a função não falha: só loga
 * e retorna false — permite testar o fluxo de token direto no banco sem
 * precisar de um provedor de email configurado.
 */
function send_email(array $config, string $to, string $subject, string $html): bool
{
    $mail = $config['mail'] ?? null;
    if (!$mail || empty($mail['api_key'])) {
        error_log('Mailer: config de email ausente, envio pulado.');
        return false;
    }

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(
            [
                'from' => $mail['from'],
                'to' => [$to],
                'subject' => $subject,
                'html' => $html,
            ],
            JSON_UNESCAPED_UNICODE
        ),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $mail['api_key'],
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $status >= 300) {
        error_log("Mailer: falha ({$status}): " . (is_string($response) ? $response : 'sem resposta'));
        return false;
    }

    return true;
}

/**
 * Monta o assunto/corpo do email de definição de senha, reaproveitado
 * tanto para "esqueci minha senha" quanto para o convite inicial de um
 * novo admin de evento (mesma operação: provar controle do email e
 * definir password_hash).
 *
 * $eventLabel (ex: "Casamento de Fulano e Beltrano") é opcional — quando
 * informado, aparece tanto no assunto quanto no corpo, pra quem recebe o
 * convite conseguir situar de cara se é o evento certo antes de clicar.
 * Só faz sentido pra purpose "invite" (quem já tem conta sabe qual é o
 * próprio evento); passe null pros outros casos.
 *
 * @return array{subject: string, html: string}
 */
function build_password_set_email(string $name, string $link, string $purpose, ?string $eventLabel = null): array
{
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeLink = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
    $safeEventLabel = $eventLabel !== null ? htmlspecialchars($eventLabel, ENT_QUOTES, 'UTF-8') : null;

    if ($purpose === 'invite') {
        $subject = $safeEventLabel !== null
            ? "Convite para administrar: {$safeEventLabel}"
            : 'Você foi convidado como administrador';
        $intro = $safeEventLabel !== null
            ? "Olá, {$safeName}! Você foi convidado para administrar o evento <strong>{$safeEventLabel}</strong>."
            : "Olá, {$safeName}! Você foi convidado para administrar um evento.";
    } else {
        $subject = 'Redefinição de senha';
        $intro = "Olá, {$safeName}. Recebemos um pedido para redefinir sua senha.";
    }

    $html = <<<HTML
        <p>{$intro}</p>
        <p><a href="{$safeLink}">Clique aqui para definir sua senha</a></p>
        <p>Se você não esperava este email, pode ignorá-lo com segurança.</p>
        HTML;

    return ['subject' => $subject, 'html' => $html];
}

/**
 * Email para quem já tem conta e passou a administrar mais um evento: não
 * há senha a definir, então o link leva ao login, e no painel a pessoa
 * troca de evento.
 *
 * @return array{subject: string, html: string}
 */
function build_event_access_email(string $name, string $loginLink, string $eventLabel): array
{
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeLink = htmlspecialchars($loginLink, ENT_QUOTES, 'UTF-8');
    $safeEventLabel = htmlspecialchars($eventLabel, ENT_QUOTES, 'UTF-8');

    $html = <<<HTML
        <p>Olá, {$safeName}! Você agora também administra o evento <strong>{$safeEventLabel}</strong>.</p>
        <p>Entre com o mesmo email e a mesma senha de sempre: o evento novo aparece na lista dos seus eventos.</p>
        <p><a href="{$safeLink}">Entrar no painel</a></p>
        <p>Se você não esperava este email, pode ignorá-lo com segurança.</p>
        HTML;

    return ['subject' => "Novo evento no seu painel: {$safeEventLabel}", 'html' => $html];
}

/**
 * "Casamento de Fulano e Beltrano" / "Aniversário de Fulano" — usado no
 * assunto/corpo do email de convite (ver build_password_set_email()) pra
 * quem recebe o convite conseguir confirmar de cara se é o evento certo.
 * No tipo 'other' o subtítulo faz as vezes do nome do evento
 * ("Chá de bebê do Theo — Fulano e Beltrano").
 */
function format_event_label(
    string $eventType,
    string $hostName,
    ?string $hostNameSecondary,
    ?string $subtitle = null
): string {
    $names = $hostNameSecondary !== null && $hostNameSecondary !== ''
        ? "{$hostName} e {$hostNameSecondary}"
        : $hostName;

    $labels = [
        'wedding' => "Casamento de {$names}",
        'birthday' => "Aniversário de {$names}",
        'graduation' => "Formatura de {$names}",
    ];
    if (isset($labels[$eventType])) {
        return $labels[$eventType];
    }

    return $subtitle !== null && $subtitle !== '' ? "{$subtitle} — {$names}" : "Evento de {$names}";
}
