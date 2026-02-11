<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use App\Helpers\ViewHelper;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests für ViewHelper
 */
class ViewHelperTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    // =========================================================================
    // HTML-Escaping
    // =========================================================================

    /** @test */
    public function e_escapet_html_sonderzeichen(): void
    {
        $this->assertSame(
            '&lt;script&gt;alert(&quot;XSS&quot;)&lt;/script&gt;',
            ViewHelper::e('<script>alert("XSS")</script>')
        );
    }

    /** @test */
    public function e_escapet_einfache_anfuehrungszeichen(): void
    {
        $this->assertSame(
            'O&#039;Brien',
            ViewHelper::e("O'Brien")
        );
    }

    /** @test */
    public function e_escapet_ampersand(): void
    {
        $this->assertSame('A &amp; B', ViewHelper::e('A & B'));
    }

    /** @test */
    public function e_null_wird_leerer_string(): void
    {
        $this->assertSame('', ViewHelper::e(null));
    }

    /** @test */
    public function e_leerer_string_bleibt_leer(): void
    {
        $this->assertSame('', ViewHelper::e(''));
    }

    /** @test */
    public function e_normaler_text_unveraendert(): void
    {
        $this->assertSame('Normaler Text 123', ViewHelper::e('Normaler Text 123'));
    }

    /** @test */
    public function e_umlaute_bleiben_erhalten(): void
    {
        $this->assertSame('Ärger über Öffnung', ViewHelper::e('Ärger über Öffnung'));
    }

    // =========================================================================
    // CSRF-Feld
    // =========================================================================

    /** @test */
    public function csrf_field_generiert_hidden_input(): void
    {
        $_SESSION['csrf_token'] = 'test_token_123';

        $result = ViewHelper::csrfField();

        $this->assertStringContainsString('type="hidden"', $result);
        $this->assertStringContainsString('name="csrf_token"', $result);
        $this->assertStringContainsString('value="test_token_123"', $result);
    }

    /** @test */
    public function csrf_field_escapet_token_value(): void
    {
        $_SESSION['csrf_token'] = '<script>"xss"</script>';

        $result = ViewHelper::csrfField();

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
    }

    /** @test */
    public function csrf_field_ohne_session_token(): void
    {
        $result = ViewHelper::csrfField();

        $this->assertStringContainsString('value=""', $result);
    }

    // =========================================================================
    // Flash-Messages
    // =========================================================================

    /** @test */
    public function flash_speichert_nachricht(): void
    {
        ViewHelper::flash('success', 'Gespeichert!');

        $this->assertSame(['success' => ['Gespeichert!']], $_SESSION['_flash']);
    }

    /** @test */
    public function flash_mehrere_nachrichten_gleichen_typs(): void
    {
        ViewHelper::flash('danger', 'Fehler 1');
        ViewHelper::flash('danger', 'Fehler 2');

        $this->assertSame(['danger' => ['Fehler 1', 'Fehler 2']], $_SESSION['_flash']);
    }

    /** @test */
    public function flash_verschiedene_typen(): void
    {
        ViewHelper::flash('success', 'OK');
        ViewHelper::flash('danger', 'Fehler');
        ViewHelper::flash('info', 'Info');

        $this->assertCount(3, $_SESSION['_flash']);
    }

    /** @test */
    public function get_flash_messages_gibt_nachrichten_zurueck_und_loescht(): void
    {
        ViewHelper::flash('success', 'Gespeichert!');

        $messages = ViewHelper::getFlashMessages();

        $this->assertSame(['success' => ['Gespeichert!']], $messages);
        $this->assertArrayNotHasKey('_flash', $_SESSION);
    }

    /** @test */
    public function get_flash_messages_leer_wenn_keine(): void
    {
        $messages = ViewHelper::getFlashMessages();

        $this->assertSame([], $messages);
    }

    // =========================================================================
    // Old Input
    // =========================================================================

    /** @test */
    public function flash_old_input_speichert_daten(): void
    {
        ViewHelper::flashOldInput(['email' => 'test@test.de', 'name' => 'Max']);

        $this->assertSame(['email' => 'test@test.de', 'name' => 'Max'], $_SESSION['_old_input']);
    }

    /** @test */
    public function old_gibt_gespeicherten_wert_escaped_zurueck(): void
    {
        $_SESSION['_old_input'] = ['email' => 'test@test.de'];

        $this->assertSame('test@test.de', ViewHelper::old('email'));
    }

    /** @test */
    public function old_escaped_xss(): void
    {
        $_SESSION['_old_input'] = ['name' => '<script>xss</script>'];

        $this->assertSame('&lt;script&gt;xss&lt;/script&gt;', ViewHelper::old('name'));
    }

    /** @test */
    public function old_default_wenn_nicht_vorhanden(): void
    {
        $this->assertSame('default_value', ViewHelper::old('missing', 'default_value'));
    }

    /** @test */
    public function old_leerer_string_als_default(): void
    {
        $this->assertSame('', ViewHelper::old('missing'));
    }

    /** @test */
    public function clear_old_input_entfernt_daten(): void
    {
        $_SESSION['_old_input'] = ['email' => 'test@test.de'];

        ViewHelper::clearOldInput();

        $this->assertArrayNotHasKey('_old_input', $_SESSION);
    }

    // =========================================================================
    // Datumsformatierung
    // =========================================================================

    /** @test */
    public function format_date_deutsches_format(): void
    {
        $this->assertSame('01.02.2025', ViewHelper::formatDate('2025-02-01'));
    }

    /** @test */
    public function format_date_null(): void
    {
        $this->assertSame('', ViewHelper::formatDate(null));
    }

    /** @test */
    public function format_date_leerer_string(): void
    {
        $this->assertSame('', ViewHelper::formatDate(''));
    }

    /** @test */
    public function format_date_time_deutsches_format(): void
    {
        $this->assertSame('01.02.2025 14:30', ViewHelper::formatDateTime('2025-02-01 14:30:00'));
    }

    /** @test */
    public function format_date_time_null(): void
    {
        $this->assertSame('', ViewHelper::formatDateTime(null));
    }

    /** @test */
    public function format_date_time_leerer_string(): void
    {
        $this->assertSame('', ViewHelper::formatDateTime(''));
    }

    // =========================================================================
    // Stunden-Formatierung
    // =========================================================================

    /** @test */
    public function format_hours_dezimal_mit_komma(): void
    {
        $this->assertSame('4,50', ViewHelper::formatHours(4.5));
    }

    /** @test */
    public function format_hours_ganzzahl(): void
    {
        $this->assertSame('8,00', ViewHelper::formatHours(8.0));
    }

    /** @test */
    public function format_hours_null(): void
    {
        $this->assertSame('0,00', ViewHelper::formatHours(null));
    }

    /** @test */
    public function format_hours_string_input(): void
    {
        $this->assertSame('3,75', ViewHelper::formatHours('3.75'));
    }

    /** @test */
    public function format_hours_grosse_zahl_mit_tausendertrenner(): void
    {
        $this->assertSame('1.234,50', ViewHelper::formatHours(1234.5));
    }

    /** @test */
    public function format_hours_null_stunden(): void
    {
        $this->assertSame('0,00', ViewHelper::formatHours(0.0));
    }
}
