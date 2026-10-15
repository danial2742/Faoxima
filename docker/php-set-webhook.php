<?php

function env_or($name, $default = '')
{
    $value = getenv($name);
    return ($value === false || $value === '') ? $default : $value;
}

$token  = env_or('TELEGRAM_BOT_TOKEN');
$domain = env_or('DOMAIN');
$path   = env_or('URL_PATH', 'faoxima');

if ($token === '' || $domain === '') {
    fwrite(STDERR, "[set-webhook] TELEGRAM_BOT_TOKEN or DOMAIN not set; skipping.\n");
    exit(0);
}

$payload = http_build_query([
    'url'          => "https://{$domain}/{$path}/index.php",
    'secret_token' => hash('sha256', $token . '_faoxima_webhook_secret'),
]);

$ctx = stream_context_create(['http' => [
    'method'  => 'POST',
    'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
    'content' => $payload,
    'timeout' => 15,
    'ignore_errors' => true,
]]);

$raw  = @file_get_contents("https://api.telegram.org/bot{$token}/setWebhook", false, $ctx);
$data = is_string($raw) ? json_decode($raw, true) : null;

if (is_array($data) && !empty($data['ok'])) {
    echo "[set-webhook] webhook set to https://{$domain}/{$path}/index.php\n";
    exit(0);
}

$reason = is_array($data) && isset($data['description']) ? $data['description'] : 'no response from Telegram';
fwrite(STDERR, "[set-webhook] setWebhook failed: {$reason}\n");
exit(0);
