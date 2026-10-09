<?php
/**
 * GRANTED — Gmail API mailer (Phase 4).
 *
 * Needs these constants (defined in config/app.php): GMAIL_CREDENTIALS_PATH, GMAIL_TOKEN_PATH,
 * GMAIL_REDIRECT_URI, MAIL_FROM_EMAIL, MAIL_FROM_NAME.
 * Needs the Google library: run `composer require google/apiclient` in the project root.
 */

/** Build a base64url-encoded RFC 2822 message, which is what the Gmail API's "raw" field expects. */
function gmailBuildRawMessage(string $fromEmail, string $fromName, string $toEmail, string $subject, string $bodyText, ?string $bodyHtml = null): string
{
    foreach ([$fromEmail, $fromName, $toEmail, $subject] as $value) {
        if (preg_match('/[\r\n]/', $value)) {
            throw new InvalidArgumentException('Line breaks are not allowed in email header values.');
        }
    }
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Invalid recipient email address.');
    }

    $headers = [
        'From: ' . mb_encode_mimeheader($fromName, 'UTF-8', 'B', "\r\n") . ' <' . $fromEmail . '>',
        'To: ' . $toEmail,
        'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n"),
        'MIME-Version: 1.0',
    ];

    if ($bodyHtml === null) {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: base64';
        $body = chunk_split(base64_encode($bodyText), 76, "\r\n");
    } else {
        $boundary = '=_GRANTED_' . bin2hex(random_bytes(12));
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $body = '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($bodyText), 76, "\r\n")
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($bodyHtml), 76, "\r\n")
            . '--' . $boundary . "--\r\n";
    }
    $mime = implode("\r\n", $headers) . "\r\n\r\n" . $body;

    return rtrim(strtr(base64_encode($mime), '+/', '-_'), '=');
}

/** A Google client configured for sending only (the gmail.send scope). */
function gmailClient(): Google\Client
{
    if (!class_exists('Google\\Client') || !class_exists('Google\\Service\\Gmail')) {
        throw new RuntimeException('Google API Client Library is not installed. Run `composer install` in the project folder.');
    }
    $client = new Google\Client();
    $client->setApplicationName('GRANTED Notifications');
    $client->setScopes([Google\Service\Gmail::GMAIL_SEND]);
    $client->setAuthConfig(GMAIL_CREDENTIALS_PATH);
    $client->setAccessType('offline');   // ask for a refresh token
    $client->setPrompt('consent');       // make Google return it every time we authorize
    return $client;
}

/** A client with a valid access token. Refreshes it from the saved refresh token when it has expired. */
function gmailAuthorizedClient(): Google\Client
{
    if (!is_file(GMAIL_TOKEN_PATH)) {
        throw new RuntimeException('Gmail is not authorized yet. Open /GRANTED/tools/gmail_authorize.php as admin.');
    }
    $token = json_decode((string)file_get_contents(GMAIL_TOKEN_PATH), true);
    if (!is_array($token)) {
        throw new RuntimeException('config/gmail_token.json is not valid. Run tools/gmail_authorize.php again.');
    }

    $client = gmailClient();
    $client->setAccessToken($token);

    if ($client->isAccessTokenExpired()) {
        $refreshToken = $client->getRefreshToken() ?: ($token['refresh_token'] ?? null);
        if (!$refreshToken) {
            throw new RuntimeException('No refresh token saved. Run tools/gmail_authorize.php again.');
        }
        $new = $client->fetchAccessTokenWithRefreshToken($refreshToken);
        if (isset($new['error'])) {
            throw new RuntimeException('Gmail token refresh failed: ' . ($new['error_description'] ?? $new['error']));
        }
        $new['refresh_token'] = $new['refresh_token'] ?? $refreshToken;
        file_put_contents(GMAIL_TOKEN_PATH, json_encode($new, JSON_PRETTY_PRINT), LOCK_EX);
        $client->setAccessToken($new);
    }
    return $client;
}

/** Send an email through the Gmail API. Returns the Gmail message id. Throws on any failure. */
function sendGmail(string $toEmail, string $subject, string $bodyText, ?string $bodyHtml = null): string
{
    $client  = gmailAuthorizedClient();
    $service = new Google\Service\Gmail($client);
    $message = new Google\Service\Gmail\Message();
    $message->setRaw(gmailBuildRawMessage(MAIL_FROM_EMAIL, MAIL_FROM_NAME, $toEmail, $subject, $bodyText, $bodyHtml));
    $sent = $service->users_messages->send('me', $message);
    return (string)$sent->getId();
}
