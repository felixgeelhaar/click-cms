<?php

declare(strict_types=1);

namespace Click\Cms\Application\Forms;

final class FormMessages
{
    private const TEXT = [
        'received' => ['Thank you. Your message has been received.', 'Vielen Dank. Ihre Nachricht ist eingegangen.'],
        'sending' => ['Sending…', 'Wird gesendet…'],
        'failed' => ['Could not send. Please try again.', 'Senden nicht möglich. Bitte versuchen Sie es erneut.'],
        'required_name' => ['Name is required.', 'Bitte geben Sie Ihren Namen ein.'],
        'required_email' => ['Email is required.', 'Bitte geben Sie Ihre E-Mail-Adresse ein.'],
        'required_message' => ['Message is required.', 'Bitte geben Sie eine Nachricht ein.'],
        'email' => ['That email address does not look valid.', 'Bitte geben Sie eine gültige E-Mail-Adresse ein.'],
        'size' => ['Your message is too long. Please shorten it and try again.', 'Ihre Nachricht ist zu lang. Bitte kürzen Sie sie und versuchen Sie es erneut.'],
        'rate' => ['Too many messages. Please try again later.', 'Zu viele Nachrichten. Bitte versuchen Sie es später erneut.'],
        'unavailable' => ['The form is temporarily unavailable. Please try again later.', 'Das Formular ist vorübergehend nicht verfügbar. Bitte versuchen Sie es später erneut.'],
        'name' => ['Your name', 'Ihr Name'],
        'email_label' => ['Email address', 'E-Mail-Adresse'],
        'message' => ['Message', 'Nachricht'],
        'submit' => ['Send message', 'Nachricht senden'],
        'honeypot' => ['Leave this empty', 'Dieses Feld bitte leer lassen'],
    ];

    public static function text(string $key, string $locale): string
    {
        return self::TEXT[$key][strtolower(substr($locale, 0, 2)) === 'de' ? 1 : 0];
    }
}
