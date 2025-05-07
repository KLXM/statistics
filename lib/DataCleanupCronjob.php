<?php

class rex_statistics_datacleanup_cronjob extends rex_cronjob
{
    public function execute()
    {
        $addon = rex_addon::get('statistics');
        
        // Prüfen ob die Bereinigung aktiviert ist
        if ($addon->getConfig('statistics_auto_cleanup', 0) != 1) {
            return true;
        }
        
        // Anzahl Tage, die detaillierte Daten behalten werden sollen
        $days_to_keep = (int)$addon->getConfig('statistics_cleanup_days', 90);
        if ($days_to_keep < 30) {
            $days_to_keep = 30; // Minimum 30 Tage
        }
        
        $cutoff_date = new DateTime();
        $cutoff_date->modify("-{$days_to_keep} days");
        $cutoff_date_str = $cutoff_date->format('Y-m-d');
        
        $sql = rex_sql::factory();
        
        try {
            // Cache leeren bevor wir Änderungen vornehmen
            \AndiLeni\Statistics\chartData::clearCache();
            
            // 1. Alte Hashes bereinigen - die meisten Datenbankeinträge befinden sich hier
            \AndiLeni\Statistics\Visit::cleanupHashTable();
            
            // 2. URL-spezifische Besuche für Besuche pro URL aggregieren
            $sql->setQuery("
                INSERT INTO " . rex::getTable('pagestats_data') . " (type, name, count)
                SELECT 
                    'url_monthly', 
                    CONCAT(DATE_FORMAT(date, '%Y-%m'), ':', SUBSTRING_INDEX(url, '?', 1)) as url_key,
                    SUM(count) as total
                FROM " . rex::getTable('pagestats_visits_per_url') . " 
                WHERE date < :cutoff_date
                GROUP BY DATE_FORMAT(date, '%Y-%m'), SUBSTRING_INDEX(url, '?', 1)
                ON DUPLICATE KEY UPDATE count = count + VALUES(count)
            ", ['cutoff_date' => $cutoff_date_str]);
            
            // 3. Besuche pro Tag für ältere Daten zu monatlichen Daten aggregieren
            $sql->setQuery("
                INSERT INTO " . rex::getTable('pagestats_data') . " (type, name, count)
                SELECT 
                    'visits_monthly', 
                    CONCAT(DATE_FORMAT(date, '%Y-%m'), ':', domain) as domain_month_key,
                    SUM(count) as total
                FROM " . rex::getTable('pagestats_visits_per_day') . "
                WHERE date < :cutoff_date
                GROUP BY DATE_FORMAT(date, '%Y-%m'), domain
                ON DUPLICATE KEY UPDATE count = count + VALUES(count)
            ", ['cutoff_date' => $cutoff_date_str]);
            
            // 4. Besucher pro Tag für ältere Daten zu monatlichen Daten aggregieren
            $sql->setQuery("
                INSERT INTO " . rex::getTable('pagestats_data') . " (type, name, count)
                SELECT 
                    'visitors_monthly', 
                    CONCAT(DATE_FORMAT(date, '%Y-%m'), ':', domain) as domain_month_key,
                    SUM(count) as total
                FROM " . rex::getTable('pagestats_visitors_per_day') . "
                WHERE date < :cutoff_date
                GROUP BY DATE_FORMAT(date, '%Y-%m'), domain
                ON DUPLICATE KEY UPDATE count = count + VALUES(count)
            ", ['cutoff_date' => $cutoff_date_str]);
            
            // 5. Referer-Daten älter als Grenzwert bereinigen 
            $sql->setQuery("
                DELETE FROM " . rex::getTable('pagestats_referer') . "
                WHERE date < :cutoff_date
            ", ['cutoff_date' => $cutoff_date_str]);
            
            // 6. Session-Statistiken älter als Grenzwert entfernen
            $cutoff_datetime = $cutoff_date->format('Y-m-d H:i:s');
            $sql->setQuery("
                DELETE FROM " . rex::getTable('pagestats_sessionstats') . "
                WHERE lastvisit < :cutoff_date
            ", ['cutoff_date' => $cutoff_datetime]);
            
            // 7. URL-spezifische Besuchsdetails älter als Grenzwert entfernen (nachdem sie aggregiert wurden)
            $sql->setQuery("
                DELETE FROM " . rex::getTable('pagestats_visits_per_url') . "
                WHERE date < :cutoff_date
            ", ['cutoff_date' => $cutoff_date_str]);
            
            // 8. URL-Status Einträge optimieren - nur Fehler behalten
            $sql->setQuery("
                DELETE FROM " . rex::getTable('pagestats_urlstatus') . "
                WHERE status = '200'
            ");
            
            // 9. Sehr alte URL-Status Einträge bereinigen
            $sql->setQuery("
                DELETE FROM " . rex::getTable('pagestats_urlstatus') . "
                WHERE hash IN (
                    SELECT hash FROM " . rex::getTable('pagestats_visits_per_url') . "
                    WHERE date < :cutoff_date
                )
            ", ['cutoff_date' => $cutoff_date_str]);
            
            // 10. Zusammenfassen der Medien-Statistiken auf monatlicher Basis für alte Daten
            $sql->setQuery("
                INSERT INTO " . rex::getTable('pagestats_data') . " (type, name, count)
                SELECT 
                    'media_monthly', 
                    CONCAT(DATE_FORMAT(date, '%Y-%m'), ':', url) as media_monthly_key, 
                    SUM(count) as total_count
                FROM " . rex::getTable('pagestats_media') . "
                WHERE date < :cutoff_date
                GROUP BY DATE_FORMAT(date, '%Y-%m'), url
                ON DUPLICATE KEY UPDATE count = VALUES(count)
            ", ['cutoff_date' => $cutoff_date_str]);
            
            // 11. Alte Medien-Statistiken entfernen
            $sql->setQuery("
                DELETE FROM " . rex::getTable('pagestats_media') . "
                WHERE date < :cutoff_date
            ", ['cutoff_date' => $cutoff_date_str]);
            
            // 12. Zusammenfassen der API/Event-Aufrufe
            $sql->setQuery("
                INSERT INTO " . rex::getTable('pagestats_data') . " (type, name, count)
                SELECT 
                    'api_monthly', 
                    CONCAT(DATE_FORMAT(date, '%Y-%m'), ':', name) as api_monthly_key, 
                    SUM(count) as total_count
                FROM " . rex::getTable('pagestats_api') . "
                WHERE date < :cutoff_date
                GROUP BY DATE_FORMAT(date, '%Y-%m'), name
                ON DUPLICATE KEY UPDATE count = VALUES(count)
            ", ['cutoff_date' => $cutoff_date_str]);
            
            // 13. Alte API-Aufrufe entfernen
            $sql->setQuery("
                DELETE FROM " . rex::getTable('pagestats_api') . "
                WHERE date < :cutoff_date
            ", ['cutoff_date' => $cutoff_date_str]);
            
            // 14. Optimieren der wichtigsten Tabellen
            $sql->setQuery("OPTIMIZE TABLE " . rex::getTable('pagestats_data'));
            $sql->setQuery("OPTIMIZE TABLE " . rex::getTable('pagestats_visits_per_day'));
            $sql->setQuery("OPTIMIZE TABLE " . rex::getTable('pagestats_visitors_per_day'));
            $sql->setQuery("OPTIMIZE TABLE " . rex::getTable('pagestats_hash'));
            
            // 15. Cache leeren, damit beim nächsten Aufrufen neue Daten geladen werden
            \AndiLeni\Statistics\chartData::clearCache();
            
        } catch (rex_sql_exception $e) {
            rex_logger::logException($e);
            return false;
        }

        return true;
    }

    public function getTypeName()
    {
        return "Statistics Addon: Optimierte Datenbereinigung für höhere Performance";
    }
}