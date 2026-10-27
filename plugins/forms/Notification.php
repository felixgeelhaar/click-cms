<?php

declare(strict_types=1);

/** Operator-controlled alerts. Visitor input never reaches mail headers or the body. */
class FormsNotification
{
    public function __construct(private readonly string $configPath) {}

    public function send(string $reference): string
    {
        if (!is_file($this->configPath)) {
            return 'disabled';
        }
        try {
            $config = json_decode((string) file_get_contents($this->configPath), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($config)) {
                return 'failed';
            }
            if (($config['notifications']['enabled'] ?? false) !== true) {
                return 'disabled';
            }
            $settings = $config['notifications'];
            foreach (['to', 'from'] as $key) {
                if (!is_string($settings[$key] ?? null) || preg_match('/[\r\n]/', $settings[$key]) || filter_var($settings[$key], FILTER_VALIDATE_EMAIL) === false) {
                    return 'failed';
                }
            }
            $german = str_starts_with(strtolower((string) ($settings['locale'] ?? 'en')), 'de');
            $body = ($german
                ? "Eine neue Formularnachricht ist in Click gespeichert. Melden Sie sich im CMS an und öffnen Sie Formulareingänge.\nReferenz: "
                : "A new contact form submission is stored in Click. Sign in to the CMS and open Form submissions.\nReference: ") . $reference . "\n";
            $subject = $german ? 'Click CMS: neue Formularnachricht' : 'Click CMS: new form submission';
            return $this->deliver($settings['to'], $subject, $body, [
                'From' => $settings['from'], 'Content-Type' => 'text/plain; charset=UTF-8',
            ]) ? 'submitted' : 'failed';
        } catch (Throwable) {
            return 'failed';
        }
    }

    protected function deliver(string $to, string $subject, string $body, array $headers): bool
    {
        return @mail($to, $subject, $body, $headers);
    }
}
