-- =============================================================================
-- Migration 003 DOWN
-- =============================================================================
-- ACHTUNG: System-User kann NUR geloescht werden, wenn keine work_entries
-- mehr auf ihn als created_by_user_id verweisen (FK RESTRICT). In
-- Produktion typischerweise NICHT loeschbar - stattdessen is_active=0
-- belassen (Rollback ist de facto no-op).
-- =============================================================================

-- Versucht zu loeschen - wird bei Referenzen fehlschlagen (ist OK).
DELETE FROM users WHERE mitgliedsnummer = 'SYSTEM' AND id NOT IN (
    SELECT DISTINCT created_by_user_id FROM work_entries
);

SELECT 'Rollback 003: System-User geloescht (oder behalten bei FK-Referenzen)' AS status;
