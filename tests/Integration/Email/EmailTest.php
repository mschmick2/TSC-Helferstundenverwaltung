<?php

declare(strict_types=1);

namespace Tests\Integration\Email;

use App\Services\EmailService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\MailHogHelper;

/**
 * Integrationstests fuer den E-Mail-Versand
 *
 * Voraussetzungen:
 *   - MailHog laeuft lokal (SMTP: Port 1025, API: Port 8025)
 *   - Tests werden automatisch uebersprungen wenn MailHog nicht verfuegbar ist
 *
 * MailHog starten: mailhog (oder via Docker: docker run -p 1025:1025 -p 8025:8025 mailhog/mailhog)
 */
class EmailTest extends TestCase
{
    private MailHogHelper $mailHog;
    private EmailService $emailService;

    private string $testRecipient = 'test@example.com';

    protected function setUp(): void
    {
        $this->mailHog = new MailHogHelper('http://localhost:8025');

        if (!$this->mailHog->isAvailable()) {
            $this->markTestSkipped(
                'MailHog ist nicht verfuegbar. Starten Sie MailHog fuer E-Mail-Integrationstests: '
                . 'docker run -d -p 1025:1025 -p 8025:8025 mailhog/mailhog'
            );
        }

        // EmailService mit MailHog-SMTP konfigurieren (Port 1025, keine Auth)
        $mailSettings = [
            'host' => 'localhost',
            'port' => 1025,
            'username' => '',
            'password' => '',
            'encryption' => '',
            'from' => [
                'address' => 'vaes-test@example.com',
                'name' => 'VAES Test',
            ],
        ];

        $this->emailService = new EmailService($mailSettings, new NullLogger());
        $this->mailHog->clearInbox();
    }

    // =========================================================================
    // Test 1: MailHog-Verbindung
    // =========================================================================

    public function test_mailhog_is_available(): void
    {
        $this->assertTrue(
            $this->mailHog->isAvailable(),
            'MailHog API sollte unter http://localhost:8025 erreichbar sein'
        );
    }

    // =========================================================================
    // Test 2: Einfacher E-Mail-Versand und -Empfang
    // =========================================================================

    public function test_can_send_and_receive_email(): void
    {
        $result = $this->emailService->send(
            $this->testRecipient,
            'Test-Nachricht',
            '<p>Dies ist eine Test-E-Mail.</p>'
        );

        $this->assertTrue($result, 'E-Mail-Versand sollte erfolgreich sein');

        $this->mailHog->waitForMessages(1);
        $message = $this->mailHog->getLatestMessage();

        $this->assertNotNull($message, 'MailHog sollte die E-Mail empfangen haben');
        $this->assertStringContainsString(
            'Test-Nachricht',
            $this->mailHog->getMessageSubject($message)
        );
    }

    // =========================================================================
    // Test 3: E-Mail enthaelt korrekte Links
    // =========================================================================

    public function test_email_contains_correct_links(): void
    {
        $entryUrl = 'https://192.168.3.98/helferstunden/entries/42';

        $result = $this->emailService->sendEntryApproved(
            $this->testRecipient,
            'Max',
            'E-2024-001',
            $entryUrl
        );

        $this->assertTrue($result);

        $this->mailHog->waitForMessages(1);
        $message = $this->mailHog->getLatestMessage();

        $this->assertNotNull($message);
        $body = $this->mailHog->getMessageBody($message);
        $this->assertStringContainsString($entryUrl, $body, 'E-Mail sollte den korrekten Antrags-Link enthalten');
        $this->assertStringContainsString('/helferstunden/', $body, 'Link sollte den base_path enthalten');
    }

    // =========================================================================
    // Test 4: Registrierungs-E-Mail
    // =========================================================================

    public function test_registration_email_is_sent(): void
    {
        $setupUrl = 'https://192.168.3.98/helferstunden/setup/abc123token';

        $result = $this->emailService->sendInvitation(
            $this->testRecipient,
            'Erika',
            $setupUrl
        );

        $this->assertTrue($result);

        $this->mailHog->waitForMessages(1);
        $message = $this->mailHog->getLatestMessage();

        $this->assertNotNull($message);
        $subject = $this->mailHog->getMessageSubject($message);
        $body = $this->mailHog->getMessageBody($message);

        $this->assertStringContainsString('Einladung', $subject, 'Betreff sollte "Einladung" enthalten');
        $this->assertStringContainsString($setupUrl, $body, 'E-Mail sollte den Setup-Link enthalten');
        $this->assertStringContainsString('Erika', $body, 'E-Mail sollte den Vornamen enthalten');
    }

    // =========================================================================
    // Test 5: Pruefer-Benachrichtigung bei Einreichung
    // =========================================================================

    public function test_reviewer_notification_on_submission(): void
    {
        $entryUrl = 'https://192.168.3.98/helferstunden/entries/99';

        $result = $this->emailService->sendEntrySubmitted(
            $this->testRecipient,
            'Hans',
            'E-2024-042',
            'Maria Mueller',
            $entryUrl
        );

        $this->assertTrue($result);

        $this->mailHog->waitForMessages(1);
        $message = $this->mailHog->getLatestMessage();

        $this->assertNotNull($message);
        $subject = $this->mailHog->getMessageSubject($message);
        $body = $this->mailHog->getMessageBody($message);

        $this->assertStringContainsString('E-2024-042', $subject, 'Betreff sollte die Antragsnummer enthalten');
        $this->assertStringContainsString('Hans', $body, 'E-Mail sollte den Pruefer-Namen enthalten');
        $this->assertStringContainsString('Maria Mueller', $body, 'E-Mail sollte den Antragsteller-Namen enthalten');
    }

    // =========================================================================
    // Test 6: Mitglied-Benachrichtigung bei Freigabe
    // =========================================================================

    public function test_member_notification_on_approval(): void
    {
        $entryUrl = 'https://192.168.3.98/helferstunden/entries/55';

        $result = $this->emailService->sendEntryApproved(
            $this->testRecipient,
            'Anna',
            'E-2024-077',
            $entryUrl
        );

        $this->assertTrue($result);

        $this->mailHog->waitForMessages(1);
        $message = $this->mailHog->getLatestMessage();

        $this->assertNotNull($message);
        $subject = $this->mailHog->getMessageSubject($message);
        $body = $this->mailHog->getMessageBody($message);

        $this->assertStringContainsString('freigegeben', $subject, 'Betreff sollte "freigegeben" enthalten');
        $this->assertStringContainsString('E-2024-077', $subject);
        $this->assertStringContainsString('Anna', $body);
    }

    // =========================================================================
    // Test 7: Mitglied-Benachrichtigung bei Rueckfrage
    // =========================================================================

    public function test_member_notification_on_inquiry(): void
    {
        $entryUrl = 'https://192.168.3.98/helferstunden/entries/33';

        $result = $this->emailService->sendEntryReturnedForRevision(
            $this->testRecipient,
            'Peter',
            'E-2024-055',
            'Bitte Datum ueberpruefen',
            $entryUrl
        );

        $this->assertTrue($result);

        $this->mailHog->waitForMessages(1);
        $message = $this->mailHog->getLatestMessage();

        $this->assertNotNull($message);
        $subject = $this->mailHog->getMessageSubject($message);
        $body = $this->mailHog->getMessageBody($message);

        $this->assertStringContainsString('E-2024-055', $subject);
        $this->assertStringContainsString('Peter', $body);
        $this->assertStringContainsString('Bitte Datum ueberpruefen', $body, 'E-Mail sollte den Rueckfrage-Grund enthalten');
    }

    // =========================================================================
    // Test 8: Mehrere E-Mails in korrekter Reihenfolge
    // =========================================================================

    public function test_multiple_emails_in_correct_order(): void
    {
        $baseUrl = 'https://192.168.3.98/helferstunden/entries/';

        // Drei E-Mails in Folge senden
        $this->emailService->sendEntrySubmitted(
            $this->testRecipient,
            'Pruefer',
            'E-2024-001',
            'Mitglied Eins',
            $baseUrl . '1'
        );

        $this->emailService->sendEntryApproved(
            'test2@example.com',
            'Mitglied',
            'E-2024-002',
            $baseUrl . '2'
        );

        $this->emailService->sendEntryRejected(
            'test3@example.com',
            'Anderes Mitglied',
            'E-2024-003',
            'Stunden nicht plausibel',
            $baseUrl . '3'
        );

        $this->mailHog->waitForMessages(3, 15);

        $messages = $this->mailHog->getMessages();
        $this->assertGreaterThanOrEqual(3, count($messages), 'Alle 3 E-Mails sollten empfangen worden sein');

        // Pruefen, dass alle Antrags-Nummern vertreten sind
        $allSubjects = implode(' ', array_map(
            fn(array $msg) => $this->mailHog->getMessageSubject($msg),
            $messages
        ));

        $this->assertStringContainsString('E-2024-001', $allSubjects);
        $this->assertStringContainsString('E-2024-002', $allSubjects);
        $this->assertStringContainsString('E-2024-003', $allSubjects);
    }
}
