<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Belegungsstatus eines Aufgaben-Knotens (Modul 6 I7b3).
 *
 * Drei Zustaende decken das Ampel-Schema ab (G1-Entscheidung B):
 *   - EMPTY   (rot)   : keine aktive Zusage.
 *   - PARTIAL (gelb)  : mindestens eine Zusage, aber capacity_target
 *                       noch nicht erreicht. Bei unbegrenzten Leaves
 *                       (capacity_target === null) gilt jede Zusage als
 *                       PARTIAL, FULL wird nie erreicht (G1-Entscheidung
 *                       A, a1).
 *   - FULL    (gruen) : capacity_target erreicht oder ueberschritten.
 *                       Nur fuer begrenzte Leaves bzw. Gruppen, bei denen
 *                       alle Kinder FULL sind.
 *
 * Gruppen-Rollup (G1-Entscheidung A): schlechtester Kinderstatus gewinnt.
 * Eine Gruppe mit einem EMPTY-Kind ist EMPTY, auch wenn andere Kinder voll
 * sind — operativer Fruehwarn-Indikator.
 *
 * Gruppen ohne Kinder bzw. Aggregator-Aufrufe ohne assignmentCounts
 * liefern null (kein Status) — die View rendert dann kein Badge und
 * keine Border-Klasse. Damit ist die Farbkodierung nicht verpflichtend,
 * sie erscheint nur, wenn genug Information vorliegt.
 *
 * severity() gibt die Ordnung fuer Rollups:
 *   EMPTY (0) < PARTIAL (1) < FULL (2)
 *
 * worst() berechnet den schlechtesten Status einer Kinderliste; das
 * Minimum der severity-Werte.
 *
 * cssClass(), badgeLabel() und ariaLabel() kapseln die View-Strings —
 * Views greifen ueber Enum-Methoden zu, nicht ueber eigene match()-
 * Expressions.
 */
enum TaskStatus: string
{
    case EMPTY   = 'empty';
    case PARTIAL = 'partial';
    case FULL    = 'full';

    /**
     * Leaf-Status aus capacity_target und aktueller Zusage-Anzahl.
     *
     * - capacity_target null (unbegrenzt): 0 Zusagen → EMPTY, sonst PARTIAL.
     *   FULL wird fuer unbegrenzte Leaves bewusst nie erreicht.
     * - capacity_target > 0: 0 → EMPTY, 0 < current < target → PARTIAL,
     *   current >= target → FULL.
     * - capacity_target === 0 (defensive): wie unbegrenzt behandelt.
     */
    public static function forLeaf(?int $capacityTarget, int $currentCount): self
    {
        if ($currentCount <= 0) {
            return self::EMPTY;
        }
        if ($capacityTarget === null || $capacityTarget <= 0) {
            // Unbegrenzt: jede Zusage macht "in Arbeit", FULL unerreichbar.
            return self::PARTIAL;
        }
        if ($currentCount >= $capacityTarget) {
            return self::FULL;
        }
        return self::PARTIAL;
    }

    /**
     * Gruppen-Status als schlechtester Status der Kinder.
     *
     * @param self[] $childStatuses
     * @return self|null null wenn keine Kinder (leere Gruppe).
     */
    public static function worst(array $childStatuses): ?self
    {
        if ($childStatuses === []) {
            return null;
        }
        $min = null;
        foreach ($childStatuses as $child) {
            if ($min === null || $child->severity() < $min->severity()) {
                $min = $child;
            }
        }
        return $min;
    }

    /**
     * Ordinalwert fuer Rollup-Vergleich. Niedriger = schlechter.
     */
    public function severity(): int
    {
        return match ($this) {
            self::EMPTY   => 0,
            self::PARTIAL => 1,
            self::FULL    => 2,
        };
    }

    /**
     * CSS-Klasse fuer Border + Hintergrund-Tinting (Bootstrap-5-subtle-
     * Varianten). Zu ergaenzen durch .task-status- im View, damit die
     * komplette Klasse z.B. "task-status-empty" lautet.
     */
    public function cssClass(): string
    {
        return 'task-status-' . $this->value;
    }

    /**
     * Kurzform fuer Badge-Text (G1-Entscheidung D). Moeglichst kurz, damit
     * das Badge neben Typ-Badge und Titel sauber ins Layout passt.
     */
    public function badgeLabel(): string
    {
        return match ($this) {
            self::EMPTY   => 'keine Zusage',
            self::PARTIAL => 'teilweise',
            self::FULL    => 'voll',
        };
    }

    /**
     * Screen-Reader-Formulierung. Ausfuehrlicher als badgeLabel(), dient
     * als aria-label am Wurzel-Element der Task-Zeile.
     */
    public function ariaLabel(): string
    {
        return match ($this) {
            self::EMPTY   => 'Status: keine Zusagen',
            self::PARTIAL => 'Status: teilweise besetzt',
            self::FULL    => 'Status: vollstaendig besetzt',
        };
    }
}
