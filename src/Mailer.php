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

/** 1234.5 → "R$ 1.234,50"; -22.05 → "-R$ 22,05" (como o Intl do front) */
function format_brl(float $value): string
{
    return ($value < 0 ? '-' : '') . 'R$ ' . number_format(abs($value), 2, ',', '.');
}

/**
 * Email para o cliente quando o pagamento do pedido feito pelo site é
 * confirmado: o resumo do que foi contratado e o próximo passo (o acesso ao
 * painel chega em outro email, quando o evento for criado).
 *
 * @param array $items itens do price_breakdown do pedido ({label, amount})
 * @return array{subject: string, html: string}
 */
function build_order_paid_email(string $name, string $eventLabel, array $items, float $total, string $orderLink): array
{
    $esc = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $firstName = $esc(explode(' ', trim($name))[0]);
    $safeEventLabel = $esc($eventLabel);
    $safeLink = $esc($orderLink);
    $safeTotal = format_brl($total);

    $rows = '';
    foreach ($items as $item) {
        $rows .= '<tr><td style="padding:4px 24px 4px 0">' . $esc((string) $item['label']) . '</td>'
            . '<td style="padding:4px 0;text-align:right">' . format_brl((float) $item['amount']) . '</td></tr>';
    }

    $html = <<<HTML
        <p>Olá, {$firstName}! Recebemos o pagamento do seu convite: <strong>{$safeEventLabel}</strong>.</p>
        <p>Agora a gente prepara tudo. Assim que estiver pronto, você recebe outro email com o acesso ao painel,
        onde vai personalizar o convite, cadastrar os convidados e acompanhar as confirmações.</p>
        <table style="border-collapse:collapse">
          {$rows}
          <tr><td style="padding:8px 24px 0 0"><strong>Total pago</strong></td>
          <td style="padding:8px 0 0;text-align:right"><strong>{$safeTotal}</strong></td></tr>
        </table>
        <p><a href="{$safeLink}">Ver o seu pedido</a></p>
        <p>Qualquer dúvida, é só responder este email.</p>
        HTML;

    // O assunto é texto puro (a Resend não interpreta HTML nele).
    return ['subject' => "Pagamento confirmado: {$eventLabel}", 'html' => $html];
}

/**
 * Aviso para o Presenzo (app.notify_email) sobre um pedido do site: pago
 * (hora de criar o evento), estornado, ou um pagamento que precisa de olho
 * humano. $details são linhas "rótulo: valor" em texto puro.
 *
 * @param array<string, string> $details
 * @return array{subject: string, html: string}
 */
function build_order_notice_email(string $subject, string $headline, array $details, string $superLink): array
{
    $esc = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

    $lines = '';
    foreach ($details as $label => $value) {
        $lines .= '<li><strong>' . $esc($label) . ':</strong> ' . $esc($value) . '</li>';
    }
    $safeHeadline = $esc($headline);
    $safeLink = $esc($superLink);

    $html = <<<HTML
        <p>{$safeHeadline}</p>
        <ul>{$lines}</ul>
        <p><a href="{$safeLink}">Abrir o pedido no super</a></p>
        HTML;

    return ['subject' => $subject, 'html' => $html];
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
