<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Repositories\SettingsRepository;
use App\Services\AuditService;
use App\Services\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests für SettingsService
 */
class SettingsServiceTest extends TestCase
{
    private SettingsService $service;
    private SettingsRepository&MockObject $settingsRepo;
    private AuditService&MockObject $auditService;

    protected function setUp(): void
    {
        $this->settingsRepo = $this->createMock(SettingsRepository::class);
        $this->auditService = $this->createMock(AuditService::class);

        $this->service = new SettingsService($this->settingsRepo, $this->auditService);
    }

    // =========================================================================
    // get() / getString()
    // =========================================================================

    /** @test */
    public function get_gibt_wert_zurueck(): void
    {
        $this->settingsRepo->method('findAll')->willReturn([
            'app_name' => ['setting_value' => 'VAES'],
        ]);

        $this->assertSame('VAES', $this->service->get('app_name'));
    }

    /** @test */
    public function get_default_bei_unbekanntem_key(): void
    {
        $this->settingsRepo->method('findAll')->willReturn([]);

        $this->assertSame('Default', $this->service->get('unknown', 'Default'));
        $this->assertNull($this->service->get('unknown'));
    }

    /** @test */
    public function get_string_mit_default(): void
    {
        $this->settingsRepo->method('findAll')->willReturn([]);

        $this->assertSame('fallback', $this->service->getString('missing', 'fallback'));
    }

    // =========================================================================
    // getInt()
    // =========================================================================

    /** @test */
    public function get_int_konvertiert_korrekt(): void
    {
        $this->settingsRepo->method('findAll')->willReturn([
            'max_hours' => ['setting_value' => '40'],
        ]);

        $this->assertSame(40, $this->service->getInt('max_hours'));
    }

    /** @test */
    public function get_int_default_bei_fehlendem_key(): void
    {
        $this->settingsRepo->method('findAll')->willReturn([]);

        $this->assertSame(20, $this->service->getInt('missing', 20));
    }

    // =========================================================================
    // getBool()
    // =========================================================================

    /**
     * @test
     * @dataProvider bool_true_values_provider
     */
    public function get_bool_true_werte(string $value): void
    {
        $this->settingsRepo->method('findAll')->willReturn([
            'feature' => ['setting_value' => $value],
        ]);

        $this->assertTrue($this->service->getBool('feature'));
    }

    public static function bool_true_values_provider(): array
    {
        return [
            ['true'],
            ['1'],
            ['yes'],
            ['on'],
            ['TRUE'],
            ['True'],
            ['YES'],
            ['ON'],
        ];
    }

    /**
     * @test
     * @dataProvider bool_false_values_provider
     */
    public function get_bool_false_werte(string $value): void
    {
        $this->settingsRepo->method('findAll')->willReturn([
            'feature' => ['setting_value' => $value],
        ]);

        $this->assertFalse($this->service->getBool('feature'));
    }

    public static function bool_false_values_provider(): array
    {
        return [
            ['false'],
            ['0'],
            ['no'],
            ['off'],
            ['anything_else'],
        ];
    }

    /** @test */
    public function get_bool_default_bei_fehlendem_key(): void
    {
        $this->settingsRepo->method('findAll')->willReturn([]);

        $this->assertFalse($this->service->getBool('missing'));
        $this->assertTrue($this->service->getBool('missing', true));
    }

    // =========================================================================
    // set()
    // =========================================================================

    /** @test */
    public function set_speichert_und_loggt(): void
    {
        $this->settingsRepo->method('findAll')->willReturn([]);

        $this->settingsRepo->expects($this->once())
            ->method('update')
            ->with('app_name', 'Neuer Name', 1);

        $this->auditService->expects($this->once())
            ->method('logConfigChange')
            ->with('app_name', null, 'Neuer Name');

        $this->service->set('app_name', 'Neuer Name', 1);
    }

    /** @test */
    public function set_ueberspringt_bei_gleichem_wert(): void
    {
        $this->settingsRepo->method('findAll')->willReturn([
            'app_name' => ['setting_value' => 'VAES'],
        ]);

        $this->settingsRepo->expects($this->never())->method('update');
        $this->auditService->expects($this->never())->method('logConfigChange');

        $this->service->set('app_name', 'VAES', 1);
    }

    /** @test */
    public function set_aktualisiert_cache(): void
    {
        $this->settingsRepo->method('findAll')->willReturn([
            'key1' => ['setting_value' => 'old'],
        ]);

        // Cache laden
        $this->assertSame('old', $this->service->get('key1'));

        // Wert setzen
        $this->settingsRepo->method('update');
        $this->service->set('key1', 'new', 1);

        // Cache ist aktualisiert
        $this->assertSame('new', $this->service->get('key1'));
    }

    // =========================================================================
    // Caching
    // =========================================================================

    /** @test */
    public function cache_laedt_nur_einmal(): void
    {
        $this->settingsRepo->expects($this->once())
            ->method('findAll')
            ->willReturn([
                'key1' => ['setting_value' => 'val1'],
                'key2' => ['setting_value' => 'val2'],
            ]);

        // Mehrere Aufrufe -> Repository wird nur einmal aufgerufen
        $this->service->get('key1');
        $this->service->get('key2');
        $this->service->get('key1');
    }

    /** @test */
    public function clear_cache_erzwingt_neuladen(): void
    {
        $this->settingsRepo->expects($this->exactly(2))
            ->method('findAll')
            ->willReturn([
                'key1' => ['setting_value' => 'val1'],
            ]);

        $this->service->get('key1');
        $this->service->clearCache();
        $this->service->get('key1');
    }

    // =========================================================================
    // Business-Methoden
    // =========================================================================

    /** @test */
    public function is_target_hours_enabled(): void
    {
        $this->settingsRepo->method('findAll')->willReturn([
            'target_hours_enabled' => ['setting_value' => 'true'],
        ]);

        $this->assertTrue($this->service->isTargetHoursEnabled());
    }

    /** @test */
    public function get_default_target_hours(): void
    {
        $this->settingsRepo->method('findAll')->willReturn([]);

        $this->assertSame(20, $this->service->getDefaultTargetHours());
    }

    /** @test */
    public function get_invitation_expiry_days(): void
    {
        $this->settingsRepo->method('findAll')->willReturn([
            'invitation_expiry_days' => ['setting_value' => '14'],
        ]);

        $this->assertSame(14, $this->service->getInvitationExpiryDays());
    }

    /** @test */
    public function get_invitation_expiry_days_default(): void
    {
        $this->settingsRepo->method('findAll')->willReturn([]);

        $this->assertSame(7, $this->service->getInvitationExpiryDays());
    }

    // =========================================================================
    // getFieldConfig()
    // =========================================================================

    /** @test */
    public function get_field_config_defaults(): void
    {
        $this->settingsRepo->method('findAll')->willReturn([]);

        $config = $this->service->getFieldConfig();

        $this->assertSame('required', $config['work_date']);
        $this->assertSame('optional', $config['time_from']);
        $this->assertSame('optional', $config['time_to']);
        $this->assertSame('required', $config['hours']);
        $this->assertSame('required', $config['category_id']);
        $this->assertSame('optional', $config['project']);
        $this->assertSame('optional', $config['description']);
    }

    /** @test */
    public function get_field_config_mit_custom_settings(): void
    {
        $this->settingsRepo->method('findAll')->willReturn([
            'field_beschreibung_required' => ['setting_value' => 'required'],
            'field_projekt_required' => ['setting_value' => 'hidden'],
        ]);

        $config = $this->service->getFieldConfig();

        $this->assertSame('required', $config['description']);
        $this->assertSame('hidden', $config['project']);
    }
}
