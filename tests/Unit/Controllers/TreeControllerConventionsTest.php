<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;

/**
 * Statische Konventions-Invariants fuer Tree-Controller, die die
 * Concerns-Traits aus I7e-B.0.1 nutzen.
 *
 * Die Traits setzen Controller-Properties per Konvention voraus
 * (anstatt abstrakter Methoden, aus G1-Runde-2 Entscheidung A3a
 * "Konvention via Invariants-Test erzwingen").
 *
 * Diese Tests erzwingen die Benennung zur Build-Zeit:
 *   - $this->taskRepo       fuer EventTreeActionHelpers::assertTaskBelongsToEvent.
 *   - $this->templateRepo   fuer TemplateTreeActionHelpers::assertTaskBelongsToTemplate.
 *   - $this->settingsService fuer TreeActionHelpers::treeEditorEnabled.
 *
 * Ausserdem: jeder Controller muss die passenden Traits via "use"
 * einbinden.
 */
final class TreeControllerConventionsTest extends TestCase
{
    private const EVENT_CONTROLLERS = [
        __DIR__ . '/../../../src/app/Controllers/OrganizerEventEditController.php',
        __DIR__ . '/../../../src/app/Controllers/EventAdminController.php',
    ];

    private const TEMPLATE_CONTROLLER =
        __DIR__ . '/../../../src/app/Controllers/EventTemplateController.php';

    private function read(string $path): string
    {
        return (string) file_get_contents($path);
    }

    // =========================================================================
    // Gruppe A — Promoted-Property-Konventionen
    // =========================================================================

    public function test_event_controllers_have_taskRepo_property(): void
    {
        foreach (self::EVENT_CONTROLLERS as $path) {
            $code = $this->read($path);
            self::assertMatchesRegularExpression(
                '/private\s+EventTaskRepository\s+\$taskRepo\b/',
                $code,
                basename($path) . ' muss $taskRepo als Promoted-Property haben '
                . '(Konvention fuer EventTreeActionHelpers::assertTaskBelongsToEvent).'
            );
        }
    }

    public function test_template_controller_has_templateRepo_property(): void
    {
        $code = $this->read(self::TEMPLATE_CONTROLLER);
        self::assertMatchesRegularExpression(
            '/private\s+EventTemplateRepository\s+\$templateRepo\b/',
            $code,
            'EventTemplateController muss $templateRepo als Promoted-Property haben '
            . '(Konvention fuer TemplateTreeActionHelpers::assertTaskBelongsToTemplate).'
        );
    }

    public function test_event_controllers_have_settingsService_property(): void
    {
        foreach (self::EVENT_CONTROLLERS as $path) {
            $code = $this->read($path);
            self::assertMatchesRegularExpression(
                '/private\s+\??SettingsService\s+\$settingsService\b/',
                $code,
                basename($path) . ' muss $settingsService als Promoted-Property haben '
                . '(Konvention fuer TreeActionHelpers::treeEditorEnabled). '
                . 'Nullable ist erlaubt (siehe Template-Controller), aber optional.'
            );
        }
    }

    public function test_template_controller_has_nullable_settingsService_property(): void
    {
        // Template-Controller hat nullable ?SettingsService per Konstruktor-
        // Design seit I7c. Der Trait-Code (treeEditorEnabled) ist nullable-
        // safe — aber der Controller muss weiterhin die Property fuehren.
        $code = $this->read(self::TEMPLATE_CONTROLLER);
        self::assertMatchesRegularExpression(
            '/private\s+\??SettingsService\s+\$settingsService\b/',
            $code,
            'EventTemplateController muss $settingsService als Promoted-Property '
            . 'haben (typischerweise nullable, weil der Controller seit I7c ohne '
            . 'Settings lauffaehig sein muss).'
        );
    }

    // =========================================================================
    // Gruppe B — Trait-Nutzung pro Controller-Typ
    // =========================================================================

    public function test_event_controllers_use_both_event_traits(): void
    {
        foreach (self::EVENT_CONTROLLERS as $path) {
            $code = $this->read($path);
            self::assertStringContainsString(
                'use TreeActionHelpers;',
                $code,
                basename($path) . ' muss den TreeActionHelpers-Trait per "use" einbinden.'
            );
            self::assertStringContainsString(
                'use EventTreeActionHelpers;',
                $code,
                basename($path) . ' muss den EventTreeActionHelpers-Trait per "use" einbinden.'
            );
        }
    }

    public function test_template_controller_uses_tree_and_template_traits(): void
    {
        $code = $this->read(self::TEMPLATE_CONTROLLER);
        self::assertStringContainsString(
            'use TreeActionHelpers;',
            $code,
            'EventTemplateController muss den TreeActionHelpers-Trait einbinden.'
        );
        self::assertStringContainsString(
            'use TemplateTreeActionHelpers;',
            $code,
            'EventTemplateController muss den TemplateTreeActionHelpers-Trait einbinden.'
        );
        self::assertStringNotContainsString(
            'use EventTreeActionHelpers;',
            $code,
            'EventTemplateController darf den EventTreeActionHelpers-Trait NICHT '
            . 'nutzen — Templates haben keine Belegungs-Summary (keine '
            . 'Capacity-/Zusage-Daten) und die Normalisierungs-/Serialisierungs-'
            . 'Feldnamen weichen ab.'
        );
    }

    // =========================================================================
    // Gruppe C — Trait-Imports im use-Block
    // =========================================================================

    public function test_event_controllers_import_both_event_traits(): void
    {
        foreach (self::EVENT_CONTROLLERS as $path) {
            $code = $this->read($path);
            self::assertStringContainsString(
                'use App\\Controllers\\Concerns\\TreeActionHelpers;',
                $code,
                basename($path) . ' muss TreeActionHelpers aus dem Concerns-Namespace importieren.'
            );
            self::assertStringContainsString(
                'use App\\Controllers\\Concerns\\EventTreeActionHelpers;',
                $code,
                basename($path) . ' muss EventTreeActionHelpers aus dem Concerns-Namespace importieren.'
            );
        }
    }

    public function test_template_controller_imports_tree_and_template_traits(): void
    {
        $code = $this->read(self::TEMPLATE_CONTROLLER);
        self::assertStringContainsString(
            'use App\\Controllers\\Concerns\\TreeActionHelpers;',
            $code,
            'EventTemplateController muss TreeActionHelpers aus Concerns importieren.'
        );
        self::assertStringContainsString(
            'use App\\Controllers\\Concerns\\TemplateTreeActionHelpers;',
            $code,
            'EventTemplateController muss TemplateTreeActionHelpers aus Concerns importieren.'
        );
    }

    // =========================================================================
    // Gruppe D — Trait-Dateien existieren und haben korrekten Namespace
    // =========================================================================

    public function test_three_traits_exist_in_concerns_namespace(): void
    {
        $dir = __DIR__ . '/../../../src/app/Controllers/Concerns/';
        foreach (
            ['TreeActionHelpers', 'EventTreeActionHelpers', 'TemplateTreeActionHelpers']
            as $name
        ) {
            $path = $dir . $name . '.php';
            self::assertFileExists($path, "Trait-Datei $name.php muss in Concerns/ existieren.");
            $code = (string) file_get_contents($path);
            self::assertStringContainsString(
                'namespace App\\Controllers\\Concerns;',
                $code,
                "$name muss im Namespace App\\Controllers\\Concerns liegen."
            );
            self::assertMatchesRegularExpression(
                '/trait\s+' . preg_quote($name, '/') . '\b/',
                $code,
                "$name.php muss den Trait \"$name\" deklarieren."
            );
        }
    }
}
