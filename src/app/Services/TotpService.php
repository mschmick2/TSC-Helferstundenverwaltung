<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\SecurityHelper;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use OTPHP\TOTP;

/**
 * Service für Zwei-Faktor-Authentifizierung (TOTP + E-Mail-Code)
 */
class TotpService
{
    public function __construct(
        private \PDO $pdo,
        private array $settings = []
    ) {
    }

    // =========================================================================
    // TOTP (Authenticator-App)
    // =========================================================================

    /**
     * Neues TOTP-Secret generieren
     */
    public function generateSecret(): string
    {
        $totp = TOTP::generate();
        return $totp->getSecret();
    }

    /**
     * Provisioning-URI für QR-Code generieren
     */
    public function getProvisioningUri(string $secret, string $email): string
    {
        $issuer = $this->settings['totp']['issuer'] ?? 'VAES';
        $digits = $this->settings['totp']['digits'] ?? 6;
        $period = $this->settings['totp']['period'] ?? 30;

        $totp = TOTP::createFromSecret($secret);
        $totp->setLabel($email);
        $totp->setIssuer($issuer);
        $totp->setDigits($digits);
        $totp->setPeriod($period);

        return $totp->getProvisioningUri();
    }

    /**
     * QR-Code als data:image/png URI generieren (serverseitig)
     */
    public function getQrCodeDataUri(string $provisioningUri): string
    {
        $options = new QROptions([
            'outputType'   => QRCode::OUTPUT_IMAGE_PNG,
            'scale'        => 5,
            'imageBase64'  => false,
        ]);

        $qrData = (new QRCode($options))->render($provisioningUri);

        return 'data:image/png;base64,' . base64_encode($qrData);
    }

    /**
     * TOTP-Code verifizieren
     */
    public function verifyTotp(string $secret, string $code): bool
    {
        $period = $this->settings['totp']['period'] ?? 30;

        $totp = TOTP::createFromSecret($secret);
        $totp->setPeriod($period);

        // Toleranz: ein Zeitfenster vor und nach dem aktuellen
        return $totp->verify($code, null, 1);
    }

    // =========================================================================
    // E-Mail-Code
    // =========================================================================

    /**
     * E-Mail-Verifizierungscode generieren und speichern
     */
    public function generateEmailCode(int $userId): string
    {
        $codeLength = $this->settings['email']['code_length'] ?? 6;
        $expiryMinutes = $this->settings['email']['expiry_minutes'] ?? 10;

        $code = SecurityHelper::generateNumericCode($codeLength);

        // Alte unbenutzte Codes invalidieren
        $stmt = $this->pdo->prepare(
            "UPDATE email_verification_codes
             SET used_at = NOW()
             WHERE user_id = :user_id AND purpose = 'login' AND used_at IS NULL"
        );
        $stmt->execute(['user_id' => $userId]);

        // Neuen Code speichern
        $stmt = $this->pdo->prepare(
            "INSERT INTO email_verification_codes (user_id, code, purpose, expires_at)
             VALUES (:user_id, :code, 'login', DATE_ADD(NOW(), INTERVAL :expiry MINUTE))"
        );
        $stmt->execute([
            'user_id' => $userId,
            'code' => $code,
            'expiry' => $expiryMinutes,
        ]);

        return $code;
    }

    /**
     * E-Mail-Code verifizieren
     */
    public function verifyEmailCode(int $userId, string $code): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT id FROM email_verification_codes
             WHERE user_id = :user_id
             AND code = :code
             AND purpose = 'login'
             AND used_at IS NULL
             AND expires_at > NOW()
             ORDER BY created_at DESC
             LIMIT 1"
        );
        $stmt->execute(['user_id' => $userId, 'code' => $code]);
        $result = $stmt->fetch();

        if ($result === false) {
            return false;
        }

        // Code als verwendet markieren
        $stmt = $this->pdo->prepare(
            "UPDATE email_verification_codes SET used_at = NOW() WHERE id = :id"
        );
        $stmt->execute(['id' => $result['id']]);

        return true;
    }
}
