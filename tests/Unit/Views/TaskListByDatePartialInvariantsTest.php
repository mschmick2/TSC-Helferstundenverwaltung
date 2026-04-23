<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use PHPUnit\Framework\TestCase;

/**
 * Statische Invarianten fuer die Partials
 * events/_task_list_by_date.php und events/_task_list_item.php (I7b4).
 *
 * Beide Partials rendern vom Aggregator gelieferte User-Freitext-Felder
 * (Task-Titel, Event-Titel, ancestor_path). Die Tests sichern ab:
 *   - XSS-Schutz durch ViewHelper::e() auf jedem Freitext.
 *   - Datums-Sektionierung als <section> pro YYYY-MM-DD.
 *   - Separate Sektion fuer Variable-Slot-Leaves.
 *   - Conditional Title-Link abhaengig von $linkTaskTitles.
 *   - I7b3-Farbkodierung (Status-CSS-Klasse + Badge) integriert.
 *   - Intl-Datumsformatierung mit Fallback-Pfad.
 */
final class TaskListByDatePartialInvariantsTest extends TestCase
{
    private const PARTIAL_DATE = __DIR__ . '/../../../src/app/Views/events/_task_list_by_date.php';
    private const PARTIAL_ITEM = __DIR__ . '/../../../src/app/Views/events/_task_list_item.php';

    private function read(string $path): string
    {
        return (string) file_get_contents($path);
    }

    // =========================================================================
    // _task_list_by_date.php — Sektionierung + Intl-Fallback
    // =========================================================================

    public function test_partial_groups_into_date_sections(): void
    {
        $partial = $this->read(self::PARTIAL_DATE);

        self::assertStringContainsString(
            '<section class="task-list-date-section"',
            $partial,
            'Partial muss pro Datum eine <section class="task-list-date-section"> '
            . 'rendern.'
        );
        self::assertStringContainsString(
            '<h3 class="task-list-date-header"',
            $partial,
            'Jede Sektion braucht einen <h3>-Header mit dem formatierten Datum.'
        );
    }

    public function test_partial_renders_variable_slot_section_separately(): void
    {
        $partial = $this->read(self::PARTIAL_DATE);

        self::assertStringContainsString(
            'task-list-no-time',
            $partial,
            'Variable-Slot-Leaves landen in einer eigenen Sektion '
            . '(.task-list-no-time), getrennt vom chronologischen Hauptteil.'
        );
        self::assertStringContainsString(
            'Ohne feste Zeitvorgabe',
            $partial,
            'Sektion "Ohne feste Zeitvorgabe" hat sprechenden Header fuer '
            . 'User und Screen-Reader.'
        );
    }

    public function test_partial_splits_leaves_by_start_at_null_check(): void
    {
        $partial = $this->read(self::PARTIAL_DATE);

        // Das Partial muss ein Split in fix- und variable-Slot haben, der
        // auf getStartAt() !== null beruht.
        self::assertMatchesRegularExpression(
            '/getStartAt\(\)\s*!==\s*null/',
            $partial,
            'Partial muss getStartAt() !== null pruefen, um fix- und '
            . 'variable-Slot-Leaves zu trennen.'
        );
    }

    public function test_partial_groups_by_date_via_substr(): void
    {
        $partial = $this->read(self::PARTIAL_DATE);

        // Gruppierung per substr("YYYY-MM-DD HH:MM:SS", 0, 10). Regressions-
        // Schutz: falls jemand das auf DateTimeImmutable-format('Y-m-d')
        // umbaut, ist der TaskTreeAggregatorFlattenTest-Format-Test die
        // zweite Verteidigungslinie.
        self::assertMatchesRegularExpression(
            '/substr\b.*getStartAt\(\).*\b0\s*,\s*10\s*\)/',
            $partial,
            'Partial muss Datum via substr(start_at, 0, 10) extrahieren '
            . '(MySQL-DATETIME-Format "YYYY-MM-DD HH:MM:SS").'
        );
    }

    public function test_partial_uses_intl_with_fallback(): void
    {
        $partial = $this->read(self::PARTIAL_DATE);

        // Phase-2-Anforderung: IntlDateFormatter als Primary, manueller
        // Fallback ohne intl-Extension (defensive fuer Strato).
        self::assertStringContainsString(
            'IntlDateFormatter',
            $partial,
            'Partial muss IntlDateFormatter nutzen, wenn verfuegbar.'
        );
        self::assertStringContainsString(
            "extension_loaded('intl')",
            $partial,
            'Partial muss vor IntlDateFormatter-Nutzung extension_loaded("intl") '
            . 'pruefen — Fallback fuer Strato-Umgebung.'
        );
        self::assertMatchesRegularExpression(
            '/\$wochentage\s*=/',
            $partial,
            'Fallback-Pfad muss eine manuelle Wochentag-Tabelle haben.'
        );
        self::assertMatchesRegularExpression(
            '/\$monate\s*=/',
            $partial,
            'Fallback-Pfad muss eine manuelle Monats-Tabelle haben.'
        );
    }

    public function test_partial_includes_item_sub_partial_per_leaf(): void
    {
        $partial = $this->read(self::PARTIAL_DATE);

        self::assertStringContainsString(
            "include __DIR__ . '/_task_list_item.php'",
            $partial,
            'Jeder Leaf wird via include _task_list_item.php gerendert '
            . '(DRY zwischen Haupt- und Variable-Sektion).'
        );
    }

    // =========================================================================
    // _task_list_item.php — XSS, Status-Integration, Link-Conditional
    // =========================================================================

    public function test_item_escapes_task_title(): void
    {
        $partial = $this->read(self::PARTIAL_ITEM);

        self::assertMatchesRegularExpression(
            '/ViewHelper::e\s*\(\s*\$task->getTitle\(\)\s*\)/',
            $partial,
            'Task-Titel (User-Freitext) muss durch ViewHelper::e() escaped werden.'
        );
    }

    public function test_item_escapes_ancestor_path(): void
    {
        $partial = $this->read(self::PARTIAL_ITEM);

        // ancestor_path ist list<string>; darf nicht als Array an htmlspecialchars
        // gegeben werden (TypeError in PHP 8). Statt dessen: foreach + escape
        // pro Eintrag.
        self::assertMatchesRegularExpression(
            '/foreach\s*\(\s*\$ancestorPath\s+as/',
            $partial,
            'ancestor_path muss per foreach iteriert und jeder Eintrag einzeln '
            . 'escaped werden — nicht als Array an ViewHelper::e().'
        );
        self::assertMatchesRegularExpression(
            '/ViewHelper::e\s*\(\s*\$ancestorTitle\s*\)/',
            $partial,
            'Jeder ancestor_path-Eintrag muss einzeln mit ViewHelper::e() '
            . 'escaped werden.'
        );
    }

    public function test_item_renders_link_only_when_linkTaskTitles_true(): void
    {
        $partial = $this->read(self::PARTIAL_ITEM);

        // Konditionaler Anchor-Block abhaengig von $linkTaskTitles.
        self::assertMatchesRegularExpression(
            '/if\s*\(\s*\$linkTaskTitles\s*\)/',
            $partial,
            'Titel-Link muss hinter if ($linkTaskTitles)-Guard stehen.'
        );
        self::assertMatchesRegularExpression(
            '/<a\s+href="\/admin\/events\//',
            $partial,
            'Der Link verweist auf /admin/events/{id} (dort ist die volle '
            . 'Bearbeitungs-UI fuer event_admin + Organizer via '
            . 'assertEventEditPermission).'
        );
    }

    public function test_item_uses_status_css_class_and_badge(): void
    {
        $partial = $this->read(self::PARTIAL_ITEM);

        // I7b3-Integration: task-status-*-Klasse am <li> + Badge, beide nur
        // wenn $status !== null.
        self::assertStringContainsString(
            '$status->cssClass()',
            $partial,
            'Item muss $status->cssClass() rendern (I7b3-Farbkodierung).'
        );
        self::assertStringContainsString(
            '$status->badgeLabel()',
            $partial,
            'Item muss $status->badgeLabel() rendern (I7b3-Badge).'
        );
        self::assertMatchesRegularExpression(
            '/if\s*\(\s*\$status\s*!==\s*null\s*\)/',
            $partial,
            'Status-Rendering nur bei ($status !== null) — sonst leere '
            . 'Klassen/Badges.'
        );
    }

    public function test_item_renders_aria_label_from_status(): void
    {
        $partial = $this->read(self::PARTIAL_ITEM);

        self::assertStringContainsString(
            '$status->ariaLabel()',
            $partial,
            'Item muss $status->ariaLabel() am <li> rendern (Screen-Reader-'
            . 'Zugaenglichkeit, konsistent zu I7b3-Tree-Partials).'
        );
    }

    public function test_item_computes_taken_from_helpers_and_open_slots(): void
    {
        $partial = $this->read(self::PARTIAL_ITEM);

        // flattenToList liefert `helpers` und `open_slots` — taken-count
        // wird als helpers - open_slots berechnet. Regressions-Schutz
        // gegen einen Refactor, der ein separates taken-Feld einfuehrt.
        self::assertMatchesRegularExpression(
            '/\$helpers\s*-\s*\(?int\)?\s*\$openSlots/',
            $partial,
            'Item muss taken als (helpers - open_slots) berechnen — kein '
            . 'Phantom-Feld taken_count annehmen.'
        );
    }

    public function test_item_converts_start_at_via_createFromFormat(): void
    {
        $partial = $this->read(self::PARTIAL_ITEM);

        // getStartAt() liefert ?string. Fuer H:i-Formatierung wird
        // DateTimeImmutable::createFromFormat('Y-m-d H:i:s', ...) genutzt.
        self::assertStringContainsString(
            "DateTimeImmutable::createFromFormat('Y-m-d H:i:s'",
            $partial,
            'Item muss getStartAt() via DateTimeImmutable::createFromFormat '
            . 'konvertieren, um H:i zu rendern.'
        );
    }
}
