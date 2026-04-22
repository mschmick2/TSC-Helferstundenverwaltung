<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Konstanten fuer die Tree-Walk-Notbremsen im Aufgabenbaum (Modul 6 I7a).
 *
 * Schichtung der Tiefen-/Iterations-Grenzen:
 *
 *   1. Regulaere Validierung (User-sichtbar):
 *      TaskTreeService::assertWithinMaxDepth() liest events.tree_max_depth
 *      aus den Settings (Default 4). Greift bei jedem createNode/move im
 *      Service-Layer und liefert eine BusinessRuleException mit Klartext.
 *
 *   2. Fail-closed-Grenze in der DB-Schicht (SAFETY_DEPTH_CAP = 32):
 *      Schuetzt Tree-Walk-Methoden im Repository (getDepth, getAncestorPath,
 *      isDescendantOf, maxSubtreeDepth) gegen Hierarchien, die durch
 *      Fremdeingriff entstanden sind und die Service-Validierung umgangen
 *      haben (z. B. direkter SQL-UPDATE auf parent_task_id durch einen
 *      DBA). Der Walk bricht bei dieser Tiefe definiert ab und liefert
 *      einen hohen, vom Service-Limit garantiert blockierten Wert zurueck.
 *
 *   3. BFS-Iterations-Notbremse (BFS_ITERATIONS_CAP = 1000):
 *      Harte aeussere Schleifengrenze fuer maxSubtreeDepth(), damit ein
 *      zirkulaerer parent_task_id-Bezug nicht in eine Endlosschleife
 *      laeuft. Greift erst, wenn die Tiefen-Notbremse aus 2. nicht
 *      ausgereicht hat (was praktisch nicht passieren sollte).
 *
 * Werte sind bewusst grosszuegig gewaehlt, damit sie regulaerem Wachstum
 * (User-Limit auf 4 ist jederzeit per Settings auf z. B. 8 anhebbar) viel
 * Luft lassen — sie sind Notbremse, nicht Geschaeftsregel.
 */
final class TreeWalkLimits
{
    public const SAFETY_DEPTH_CAP   = 32;
    public const BFS_ITERATIONS_CAP = 1000;
}
