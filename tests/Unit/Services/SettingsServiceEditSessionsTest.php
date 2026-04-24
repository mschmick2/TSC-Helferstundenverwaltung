<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Repositories\SettingsRepository;
use App\Services\AuditService;
use App\Services\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * Deckt die beiden neuen Flag-Helper von SettingsService ab
 * (Modul 6 I7e-C.1 Phase 1, Architect-Korrektur C2).
 *
 *   - treeEditorEnabled() liefert den Bool-Wert von
 *     events.tree_editor_enabled.
 *   - editSessionsEnabled() ist hart gekoppelt: BEIDE Flags muessen
 *     gesetzt sein. Das verhindert die unsinnige Kombination
 *     "Sessions aktiv, Editor aus".
 */
final class SettingsServiceEditSessionsTest extends TestCase
{
    /**
     * @param array<string, ?string> $values key -> setting_value
     */
    private function service(array $values): SettingsService
    {
        $findAllResult = [];
        foreach ($values as $key => $value) {
            $findAllResult[$key] = ['setting_value' => $value];
        }

        $repo = $this->createMock(SettingsRepository::class);
        $repo->method('findAll')->willReturn($findAllResult);

        $audit = $this->createMock(AuditService::class);

        return new SettingsService($repo, $audit);
    }

    public function test_treeEditorEnabled_true_when_flag_is_1(): void
    {
        $service = $this->service(['events.tree_editor_enabled' => '1']);
        self::assertTrue($service->treeEditorEnabled());
    }

    public function test_treeEditorEnabled_false_when_flag_is_0(): void
    {
        $service = $this->service(['events.tree_editor_enabled' => '0']);
        self::assertFalse($service->treeEditorEnabled());
    }

    public function test_treeEditorEnabled_false_when_flag_missing(): void
    {
        $service = $this->service([]);
        self::assertFalse($service->treeEditorEnabled());
    }

    public function test_editSessionsEnabled_true_when_both_flags_true(): void
    {
        $service = $this->service([
            'events.tree_editor_enabled'   => '1',
            'events.edit_sessions_enabled' => '1',
        ]);
        self::assertTrue($service->editSessionsEnabled());
    }

    public function test_editSessionsEnabled_false_when_tree_editor_disabled(): void
    {
        // Architect-C2: selbst wenn edit_sessions_enabled=1 ist, zaehlt
        // nichts, wenn der Editor selbst aus ist.
        $service = $this->service([
            'events.tree_editor_enabled'   => '0',
            'events.edit_sessions_enabled' => '1',
        ]);
        self::assertFalse($service->editSessionsEnabled());
    }

    public function test_editSessionsEnabled_false_when_edit_sessions_flag_false(): void
    {
        $service = $this->service([
            'events.tree_editor_enabled'   => '1',
            'events.edit_sessions_enabled' => '0',
        ]);
        self::assertFalse($service->editSessionsEnabled());
    }

    public function test_editSessionsEnabled_false_when_edit_sessions_flag_missing(): void
    {
        // Default ist false, wenn der Key noch nicht migriert ist (z.B.
        // alte DB ohne Migration 010). Kein Feature, keine Polling-Surface.
        $service = $this->service([
            'events.tree_editor_enabled' => '1',
        ]);
        self::assertFalse($service->editSessionsEnabled());
    }
}
