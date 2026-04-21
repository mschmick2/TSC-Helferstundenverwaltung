<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Event;
use App\Models\EventTask;
use DateTimeImmutable;

/**
 * Verschickt die Modul-6-I6-Benachrichtigungen als HTML-E-Mail.
 *
 * Dient als Fassade fuer EmailService: Jede Notifikations-Art hat genau eine
 * Methode, die den Betreff, die URL und den HTML-Body zusammenbaut und an
 * EmailService->send() delegiert.
 */
class NotificationService
{
    public function __construct(
        private readonly EmailService $emailService,
        private readonly string $baseUrl,
    ) {
    }

    // =========================================================================
    // Event-Reminder (24h + 7d - gleicher Text, nur Vorlauf unterscheidet)
    // =========================================================================

    public function sendEventReminder(
        string $email,
        string $vorname,
        Event $event,
        int $daysBefore,
    ): bool {
        $eventUrl  = $this->eventUrl($event);
        $when      = $this->formatDateTime($event->getStartAt());
        $horizon   = $daysBefore === 1 ? 'morgen' : "in {$daysBefore} Tagen";
        $subject   = "VAES - Erinnerung: " . $event->getTitle() . " ({$horizon})";
        $location  = $event->getLocation();

        $html = $this->wrapInTemplate("
            <h2>Erinnerung an eine Veranstaltung</h2>
            <p>Hallo {$this->e($vorname)},</p>
            <p>die Veranstaltung <strong>{$this->e($event->getTitle())}</strong> findet
               {$this->e($horizon)} statt.</p>
            <table style='width: 100%; border-collapse: collapse; margin: 15px 0;'>
                <tr>
                    <td style='padding: 8px; border: 1px solid #dee2e6; width: 40%;'><strong>Beginn:</strong></td>
                    <td style='padding: 8px; border: 1px solid #dee2e6;'>{$this->e($when)}</td>
                </tr>
                " . ($location !== null && $location !== '' ? "
                <tr>
                    <td style='padding: 8px; border: 1px solid #dee2e6;'><strong>Ort:</strong></td>
                    <td style='padding: 8px; border: 1px solid #dee2e6;'>{$this->e($location)}</td>
                </tr>" : '') . "
            </table>
            <p style='text-align: center; margin: 30px 0;'>
                <a href='{$this->e($eventUrl)}'
                   style='background: #0d6efd; color: white; padding: 12px 30px;
                          text-decoration: none; border-radius: 6px; font-weight: bold;'>
                    Veranstaltung ansehen
                </a>
            </p>
        ");

        return $this->emailService->send($email, $subject, $html);
    }

    // =========================================================================
    // Assignment-Invite (sofort, wenn zugewiesen)
    // =========================================================================

    public function sendAssignmentInvite(
        string $email,
        string $vorname,
        Event $event,
        EventTask $task,
    ): bool {
        $eventUrl = $this->eventUrl($event);
        $when     = $this->formatDateTime($event->getStartAt());
        $subject  = "VAES - Neue Aufgabe: " . $task->getTitle();

        $html = $this->wrapInTemplate("
            <h2>Sie wurden einer Aufgabe zugeteilt</h2>
            <p>Hallo {$this->e($vorname)},</p>
            <p>Sie wurden fuer die folgende Aufgabe vorgeschlagen und koennen sie im
               Veranstaltungsportal bestaetigen oder ablehnen:</p>
            <table style='width: 100%; border-collapse: collapse; margin: 15px 0;'>
                <tr>
                    <td style='padding: 8px; border: 1px solid #dee2e6; width: 40%;'><strong>Veranstaltung:</strong></td>
                    <td style='padding: 8px; border: 1px solid #dee2e6;'>{$this->e($event->getTitle())}</td>
                </tr>
                <tr>
                    <td style='padding: 8px; border: 1px solid #dee2e6;'><strong>Aufgabe:</strong></td>
                    <td style='padding: 8px; border: 1px solid #dee2e6;'>{$this->e($task->getTitle())}</td>
                </tr>
                <tr>
                    <td style='padding: 8px; border: 1px solid #dee2e6;'><strong>Beginn:</strong></td>
                    <td style='padding: 8px; border: 1px solid #dee2e6;'>{$this->e($when)}</td>
                </tr>
            </table>
            <p style='text-align: center; margin: 30px 0;'>
                <a href='{$this->e($eventUrl)}'
                   style='background: #0d6efd; color: white; padding: 12px 30px;
                          text-decoration: none; border-radius: 6px; font-weight: bold;'>
                    Aufgabe oeffnen
                </a>
            </p>
        ");

        return $this->emailService->send($email, $subject, $html);
    }

    // =========================================================================
    // Assignment-Reminder (noch nicht bestaetigte Zusage, X Stunden vor Start)
    // =========================================================================

    public function sendAssignmentReminder(
        string $email,
        string $vorname,
        Event $event,
        EventTask $task,
    ): bool {
        $eventUrl = $this->eventUrl($event);
        $when     = $this->formatDateTime($event->getStartAt());
        $subject  = "VAES - Bitte Zusage bestaetigen: " . $task->getTitle();

        $html = $this->wrapInTemplate("
            <h2>Bitte bestaetigen Sie Ihre Zusage</h2>
            <p>Hallo {$this->e($vorname)},</p>
            <p>Ihre Zusage fuer die folgende Aufgabe ist noch nicht bestaetigt:</p>
            <table style='width: 100%; border-collapse: collapse; margin: 15px 0;'>
                <tr>
                    <td style='padding: 8px; border: 1px solid #dee2e6; width: 40%;'><strong>Veranstaltung:</strong></td>
                    <td style='padding: 8px; border: 1px solid #dee2e6;'>{$this->e($event->getTitle())}</td>
                </tr>
                <tr>
                    <td style='padding: 8px; border: 1px solid #dee2e6;'><strong>Aufgabe:</strong></td>
                    <td style='padding: 8px; border: 1px solid #dee2e6;'>{$this->e($task->getTitle())}</td>
                </tr>
                <tr>
                    <td style='padding: 8px; border: 1px solid #dee2e6;'><strong>Beginn:</strong></td>
                    <td style='padding: 8px; border: 1px solid #dee2e6;'>{$this->e($when)}</td>
                </tr>
            </table>
            <p style='background: #fff3cd; padding: 15px; border-radius: 6px; border-left: 4px solid #ffc107;'>
                Bitte bestaetigen Sie Ihre Teilnahme rechtzeitig - andernfalls kann die
                Zusage durch den Organisator zurueckgezogen werden.
            </p>
            <p style='text-align: center; margin: 30px 0;'>
                <a href='{$this->e($eventUrl)}'
                   style='background: #ffc107; color: #212529; padding: 12px 30px;
                          text-decoration: none; border-radius: 6px; font-weight: bold;'>
                    Jetzt bestaetigen
                </a>
            </p>
        ");

        return $this->emailService->send($email, $subject, $html);
    }

    // =========================================================================
    // Dialog-Reminder (unbeantwortete Rueckfrage nach X Tagen)
    // =========================================================================

    public function sendDialogReminder(
        string $email,
        string $vorname,
        string $entryNumber,
        int $entryId,
        int $daysOpen,
    ): bool {
        $entryUrl = rtrim($this->baseUrl, '/') . '/entries/' . $entryId;
        $subject  = "VAES - Unbeantwortete Rueckfrage zu Antrag {$entryNumber}";

        $html = $this->wrapInTemplate("
            <h2>Unbeantwortete Rueckfrage</h2>
            <p>Hallo {$this->e($vorname)},</p>
            <p>Zu Ihrem Antrag <strong>{$this->e($entryNumber)}</strong> liegt seit
               {$daysOpen} Tag" . ($daysOpen === 1 ? '' : 'en') . " eine unbeantwortete
               Rueckfrage vor.</p>
            <p>Bitte beantworten Sie die Nachricht im Dialog, damit der Antrag weiter
               bearbeitet werden kann.</p>
            <p style='text-align: center; margin: 30px 0;'>
                <a href='{$this->e($entryUrl)}'
                   style='background: #0d6efd; color: white; padding: 12px 30px;
                          text-decoration: none; border-radius: 6px; font-weight: bold;'>
                    Zum Dialog
                </a>
            </p>
        ");

        return $this->emailService->send($email, $subject, $html);
    }

    // =========================================================================
    // Event-Completion-Reminder (Organisator: Event vorbei, bitte abschliessen)
    // =========================================================================

    public function sendEventCompletionReminder(
        string $email,
        string $vorname,
        Event $event,
    ): bool {
        $eventUrl = $this->eventUrl($event);
        $when     = $this->formatDateTime($event->getEndAt());
        $subject  = "VAES - Bitte Veranstaltung abschliessen: " . $event->getTitle();

        $html = $this->wrapInTemplate("
            <h2>Veranstaltung beendet - bitte abschliessen</h2>
            <p>Hallo {$this->e($vorname)},</p>
            <p>Die Veranstaltung <strong>{$this->e($event->getTitle())}</strong> ist am
               {$this->e($when)} beendet worden.</p>
            <p>Als Organisator werden Sie gebeten, das Event jetzt abzuschliessen,
               damit fuer alle bestaetigten Helfer automatisch die Helferstunden-Antraege
               erzeugt werden.</p>
            <p style='text-align: center; margin: 30px 0;'>
                <a href='{$this->e($eventUrl)}'
                   style='background: #198754; color: white; padding: 12px 30px;
                          text-decoration: none; border-radius: 6px; font-weight: bold;'>
                    Veranstaltung abschliessen
                </a>
            </p>
        ");

        return $this->emailService->send($email, $subject, $html);
    }

    // =========================================================================
    // Hilfsmethoden
    // =========================================================================

    private function eventUrl(Event $event): string
    {
        return rtrim($this->baseUrl, '/') . '/events/' . (int) $event->getId();
    }

    private function formatDateTime(string $dateTime): string
    {
        if ($dateTime === '') {
            return '';
        }
        try {
            return (new DateTimeImmutable($dateTime))->format('d.m.Y H:i') . ' Uhr';
        } catch (\Throwable) {
            return $dateTime;
        }
    }

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

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
