<?php

declare(strict_types=1);

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;
use Psr\Log\LoggerInterface;

/**
 * E-Mail-Versand-Service
 */
class EmailService
{
    public function __construct(
        private array $mailSettings,
        private LoggerInterface $logger
    ) {
    }

    /**
     * E-Mail senden
     */
    public function send(string $to, string $subject, string $htmlBody, ?string $textBody = null): bool
    {
        $mail = new PHPMailer(true);

        try {
            // SMTP-Konfiguration
            $mail->isSMTP();
            $mail->Host = $this->mailSettings['host'] ?? '';
            $mail->Port = (int) ($this->mailSettings['port'] ?? 587);
            $mail->SMTPAuth = true;
            $mail->Username = $this->mailSettings['username'] ?? '';
            $mail->Password = $this->mailSettings['password'] ?? '';
            $mail->SMTPSecure = $this->mailSettings['encryption'] ?? 'tls';
            $mail->CharSet = 'UTF-8';

            // Absender
            $mail->setFrom(
                $this->mailSettings['from']['address'] ?? 'noreply@example.com',
                $this->mailSettings['from']['name'] ?? 'VAES System'
            );

            // Empfänger
            $mail->addAddress($to);

            // Inhalt
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = $textBody ?? strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));

            $mail->send();
            $this->logger->info("E-Mail gesendet an: {$to}, Betreff: {$subject}");
            return true;
        } catch (\Exception $e) {
            $this->logger->error("E-Mail-Fehler an {$to}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 2FA-Code per E-Mail senden
     */
    public function send2faCode(string $email, string $vorname, string $code): bool
    {
        $subject = 'VAES - Ihr Anmeldecode';
        $html = $this->wrapInTemplate("
            <h2>Anmeldecode</h2>
            <p>Hallo {$this->e($vorname)},</p>
            <p>Ihr Anmeldecode lautet:</p>
            <p style='font-size: 32px; font-weight: bold; letter-spacing: 8px; text-align: center;
                       background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                {$this->e($code)}
            </p>
            <p>Der Code ist 10 Minuten gültig.</p>
            <p><small>Wenn Sie diese Anmeldung nicht angefordert haben, ignorieren Sie diese E-Mail.</small></p>
        ");

        return $this->send($email, $subject, $html);
    }

    /**
     * Passwort-Reset-Link senden
     */
    public function sendPasswordResetLink(string $email, string $vorname, string $resetUrl): bool
    {
        $subject = 'VAES - Passwort zurücksetzen';
        $html = $this->wrapInTemplate("
            <h2>Passwort zurücksetzen</h2>
            <p>Hallo {$this->e($vorname)},</p>
            <p>Sie haben angefordert, Ihr Passwort zurückzusetzen.</p>
            <p style='text-align: center; margin: 30px 0;'>
                <a href='{$this->e($resetUrl)}'
                   style='background: #0d6efd; color: white; padding: 12px 30px;
                          text-decoration: none; border-radius: 6px; font-weight: bold;'>
                    Passwort zurücksetzen
                </a>
            </p>
            <p>Der Link ist 1 Stunde gültig.</p>
            <p><small>Wenn Sie dies nicht angefordert haben, ignorieren Sie diese E-Mail.</small></p>
        ");

        return $this->send($email, $subject, $html);
    }

    /**
     * Einladungslink senden
     */
    public function sendInvitation(string $email, string $vorname, string $setupUrl): bool
    {
        $subject = 'VAES - Einladung zur Registrierung';
        $html = $this->wrapInTemplate("
            <h2>Willkommen!</h2>
            <p>Hallo {$this->e($vorname)},</p>
            <p>Sie wurden zum Vereins-Arbeitsstunden-Erfassungssystem eingeladen.</p>
            <p>Bitte klicken Sie auf den folgenden Link, um Ihr Passwort zu setzen und die
               Zwei-Faktor-Authentifizierung einzurichten:</p>
            <p style='text-align: center; margin: 30px 0;'>
                <a href='{$this->e($setupUrl)}'
                   style='background: #198754; color: white; padding: 12px 30px;
                          text-decoration: none; border-radius: 6px; font-weight: bold;'>
                    Registrierung abschließen
                </a>
            </p>
            <p>Der Link ist 7 Tage gültig.</p>
        ");

        return $this->send($email, $subject, $html);
    }

    // =========================================================================
    // Workflow-Benachrichtigungen (Abschnitt 9 Requirements)
    // =========================================================================

    /**
     * Benachrichtigung an Prüfer: Neuer Antrag eingereicht
     */
    public function sendEntrySubmitted(
        string $email,
        string $prueferVorname,
        string $entryNumber,
        string $memberName,
        string $entryUrl
    ): bool {
        $subject = "VAES - Neuer Antrag {$entryNumber} eingereicht";
        $html = $this->wrapInTemplate("
            <h2>Neuer Antrag zur Prüfung</h2>
            <p>Hallo {$this->e($prueferVorname)},</p>
            <p>{$this->e($memberName)} hat den Antrag <strong>{$this->e($entryNumber)}</strong> eingereicht
               und wartet auf Ihre Prüfung.</p>
            <p style='text-align: center; margin: 30px 0;'>
                <a href='{$this->e($entryUrl)}'
                   style='background: #0d6efd; color: white; padding: 12px 30px;
                          text-decoration: none; border-radius: 6px; font-weight: bold;'>
                    Antrag ansehen
                </a>
            </p>
        ");

        return $this->send($email, $subject, $html);
    }

    /**
     * Benachrichtigung an Mitglied: Antrag freigegeben
     */
    public function sendEntryApproved(string $email, string $vorname, string $entryNumber, string $entryUrl): bool
    {
        $subject = "VAES - Antrag {$entryNumber} freigegeben";
        $html = $this->wrapInTemplate("
            <h2>Antrag freigegeben</h2>
            <p>Hallo {$this->e($vorname)},</p>
            <p>Ihr Antrag <strong>{$this->e($entryNumber)}</strong> wurde freigegeben.</p>
            <p style='text-align: center; margin: 30px 0;'>
                <a href='{$this->e($entryUrl)}'
                   style='background: #198754; color: white; padding: 12px 30px;
                          text-decoration: none; border-radius: 6px; font-weight: bold;'>
                    Antrag ansehen
                </a>
            </p>
        ");

        return $this->send($email, $subject, $html);
    }

    /**
     * Benachrichtigung an Mitglied: Antrag abgelehnt
     */
    public function sendEntryRejected(
        string $email,
        string $vorname,
        string $entryNumber,
        string $reason,
        string $entryUrl
    ): bool {
        $subject = "VAES - Antrag {$entryNumber} abgelehnt";
        $html = $this->wrapInTemplate("
            <h2>Antrag abgelehnt</h2>
            <p>Hallo {$this->e($vorname)},</p>
            <p>Ihr Antrag <strong>{$this->e($entryNumber)}</strong> wurde leider abgelehnt.</p>
            <p><strong>Begründung:</strong></p>
            <p style='background: #f8f9fa; padding: 15px; border-radius: 6px; border-left: 4px solid #dc3545;'>
                {$this->e($reason)}
            </p>
            <p style='text-align: center; margin: 30px 0;'>
                <a href='{$this->e($entryUrl)}'
                   style='background: #0d6efd; color: white; padding: 12px 30px;
                          text-decoration: none; border-radius: 6px; font-weight: bold;'>
                    Antrag ansehen
                </a>
            </p>
        ");

        return $this->send($email, $subject, $html);
    }

    /**
     * Benachrichtigung an Mitglied: Antrag zur Klärung zurückgegeben
     */
    public function sendEntryReturnedForRevision(
        string $email,
        string $vorname,
        string $entryNumber,
        string $reason,
        string $entryUrl
    ): bool {
        $subject = "VAES - Rückfrage zu Antrag {$entryNumber}";
        $html = $this->wrapInTemplate("
            <h2>Rückfrage zu Ihrem Antrag</h2>
            <p>Hallo {$this->e($vorname)},</p>
            <p>Zu Ihrem Antrag <strong>{$this->e($entryNumber)}</strong> gibt es eine Rückfrage:</p>
            <p style='background: #f8f9fa; padding: 15px; border-radius: 6px; border-left: 4px solid #ffc107;'>
                {$this->e($reason)}
            </p>
            <p>Bitte beantworten Sie die Rückfrage im Dialog.</p>
            <p style='text-align: center; margin: 30px 0;'>
                <a href='{$this->e($entryUrl)}'
                   style='background: #0d6efd; color: white; padding: 12px 30px;
                          text-decoration: none; border-radius: 6px; font-weight: bold;'>
                    Antrag ansehen
                </a>
            </p>
        ");

        return $this->send($email, $subject, $html);
    }

    /**
     * Benachrichtigung an Mitglied: Korrektur an freigegebenem Antrag
     */
    public function sendEntryCorrected(
        string $email,
        string $vorname,
        string $entryNumber,
        float $oldHours,
        float $newHours,
        string $reason,
        string $entryUrl
    ): bool {
        $oldFormatted = number_format($oldHours, 2, ',', '.');
        $newFormatted = number_format($newHours, 2, ',', '.');

        $subject = "VAES - Korrektur an Antrag {$entryNumber}";
        $html = $this->wrapInTemplate("
            <h2>Korrektur an Ihrem Antrag</h2>
            <p>Hallo {$this->e($vorname)},</p>
            <p>An Ihrem Antrag <strong>{$this->e($entryNumber)}</strong> wurde eine Korrektur vorgenommen:</p>
            <table style='width: 100%; border-collapse: collapse; margin: 15px 0;'>
                <tr>
                    <td style='padding: 8px; border: 1px solid #dee2e6;'><strong>Stunden vorher:</strong></td>
                    <td style='padding: 8px; border: 1px solid #dee2e6;'>{$oldFormatted} h</td>
                </tr>
                <tr>
                    <td style='padding: 8px; border: 1px solid #dee2e6;'><strong>Stunden nachher:</strong></td>
                    <td style='padding: 8px; border: 1px solid #dee2e6;'>{$newFormatted} h</td>
                </tr>
            </table>
            <p><strong>Begründung:</strong></p>
            <p style='background: #f8f9fa; padding: 15px; border-radius: 6px; border-left: 4px solid #0dcaf0;'>
                {$this->e($reason)}
            </p>
            <p style='text-align: center; margin: 30px 0;'>
                <a href='{$this->e($entryUrl)}'
                   style='background: #0d6efd; color: white; padding: 12px 30px;
                          text-decoration: none; border-radius: 6px; font-weight: bold;'>
                    Antrag ansehen
                </a>
            </p>
        ");

        return $this->send($email, $subject, $html);
    }

    /**
     * Benachrichtigung bei neuer Dialog-Nachricht
     */
    public function sendDialogMessage(
        string $email,
        string $vorname,
        string $entryNumber,
        string $senderName,
        string $entryUrl
    ): bool {
        $subject = "VAES - Neue Nachricht zu Antrag {$entryNumber}";
        $html = $this->wrapInTemplate("
            <h2>Neue Dialog-Nachricht</h2>
            <p>Hallo {$this->e($vorname)},</p>
            <p>{$this->e($senderName)} hat eine neue Nachricht zu Antrag
               <strong>{$this->e($entryNumber)}</strong> geschrieben.</p>
            <p style='text-align: center; margin: 30px 0;'>
                <a href='{$this->e($entryUrl)}'
                   style='background: #0d6efd; color: white; padding: 12px 30px;
                          text-decoration: none; border-radius: 6px; font-weight: bold;'>
                    Nachricht lesen
                </a>
            </p>
        ");

        return $this->send($email, $subject, $html);
    }

    // =========================================================================
    // Template-Hilfsmethoden
    // =========================================================================

    /**
     * HTML in E-Mail-Template einbetten
     */
    private function wrapInTemplate(string $content): string
    {
        return "
        <!DOCTYPE html>
        <html lang='de'>
        <head><meta charset='UTF-8'></head>
        <body style='font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, sans-serif;
                     max-width: 600px; margin: 0 auto; padding: 20px; color: #333;'>
            <div style='border: 1px solid #dee2e6; border-radius: 8px; padding: 30px;'>
                {$content}
                <hr style='border: none; border-top: 1px solid #dee2e6; margin: 30px 0;'>
                <p style='font-size: 12px; color: #6c757d; text-align: center;'>
                    VAES - Vereins-Arbeitsstunden-Erfassungssystem
                </p>
            </div>
        </body>
        </html>";
    }

    /**
     * HTML-Escaping für E-Mail-Inhalte
     */
    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
