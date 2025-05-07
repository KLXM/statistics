<?php

$addon = rex_addon::get('statistics');

// create tables
$addon->includeFile(__DIR__ . '/install.php');

// Version spezifische Migrationen

// version 3 migrations
// copy old config settings
if (rex_config::has("statistics/api", "statistics_api_enable")) {
    rex_config::set("statistics", "statistics_api_enable", rex_config::get("statistics/api", "statistics_api_enable"));
}

if (rex_config::has("statistics/media", "statistics_media_log_all")) {
    rex_config::set("statistics", "statistics_media_log_all", rex_config::get("statistics/media", "statistics_media_log_all"));
}

if (rex_config::has("statistics/media", "statistics_media_log_mm")) {
    rex_config::set("statistics", "statistics_media_log_mm", rex_config::get("statistics/media", "statistics_media_log_mm"));
}

// remove plugins
rex_dir::delete(rex_path::addon('statistics', 'plugins'));
rex_package_manager::synchronizeWithFileSystem();

// Version 4 Performance-Optimierungen
if (rex_string::versionCompare($addon->getVersion(), '4.0', '>=')) {
    $sql = rex_sql::factory();
    
    // Performance-Indexe für schnellere Datenbankabfragen hinzufügen
    try {
        // Index für pagestats_visits_per_day.date hinzufügen
        $sql->setQuery("SHOW INDEX FROM " . rex::getTable('pagestats_visits_per_day') . " WHERE Key_name = 'date_idx'");
        if ($sql->getRows() === 0) {
            $sql->setQuery("CREATE INDEX date_idx ON " . rex::getTable('pagestats_visits_per_day') . " (date)");
        }
        
        // Index für pagestats_visitors_per_day.date hinzufügen
        $sql->setQuery("SHOW INDEX FROM " . rex::getTable('pagestats_visitors_per_day') . " WHERE Key_name = 'date_idx'");
        if ($sql->getRows() === 0) {
            $sql->setQuery("CREATE INDEX date_idx ON " . rex::getTable('pagestats_visitors_per_day') . " (date)");
        }
        
        // Index für pagestats_visits_per_url.date hinzufügen
        $sql->setQuery("SHOW INDEX FROM " . rex::getTable('pagestats_visits_per_url') . " WHERE Key_name = 'date_idx'");
        if ($sql->getRows() === 0) {
            $sql->setQuery("CREATE INDEX date_idx ON " . rex::getTable('pagestats_visits_per_url') . " (date)");
        }
        
        // Index für pagestats_hash.datetime hinzufügen
        $sql->setQuery("SHOW INDEX FROM " . rex::getTable('pagestats_hash') . " WHERE Key_name = 'datetime_idx'");
        if ($sql->getRows() === 0) {
            $sql->setQuery("CREATE INDEX datetime_idx ON " . rex::getTable('pagestats_hash') . " (datetime)");
        }
        
        // Optimieren der wichtigsten Tabellen
        $sql->setQuery("OPTIMIZE TABLE " . rex::getTable('pagestats_data'));
        $sql->setQuery("OPTIMIZE TABLE " . rex::getTable('pagestats_visits_per_day'));
        $sql->setQuery("OPTIMIZE TABLE " . rex::getTable('pagestats_visitors_per_day'));
        $sql->setQuery("OPTIMIZE TABLE " . rex::getTable('pagestats_hash'));
        
    } catch (rex_sql_exception $e) {
        // Indexerstellung fehlgeschlagen - nicht kritisch, weiter mit Update
        rex_logger::logException($e);
    }
    
    // Standard-Einstellungen für die neuen Cache-Optionen setzen, wenn noch nicht vorhanden
    if ($addon->getConfig('statistics_use_cache') === null) {
        $addon->setConfig('statistics_use_cache', 1);
    }
    
    if ($addon->getConfig('statistics_cache_lifetime') === null) {
        $addon->setConfig('statistics_cache_lifetime', 3600); // 1 Stunde
    }
    
    if ($addon->getConfig('statistics_auto_cleanup') === null) {
        $addon->setConfig('statistics_auto_cleanup', 1);
    }
    
    if ($addon->getConfig('statistics_cleanup_days') === null) {
        $addon->setConfig('statistics_cleanup_days', 90); // 90 Tage
    }
    
    // Cache-Verzeichnis erstellen oder leeren
    $cache_dir = rex_path::addonCache('statistics');
    if (!is_dir($cache_dir)) {
        rex_dir::create($cache_dir);
    } else {
        // Leere Cache-Verzeichnis
        $files = glob($cache_dir . '*.cache');
        if ($files) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
    }
    
    // Cronjobs registrieren, wenn das Cronjob-Addon verfügbar ist
    if (rex_addon::get('cronjob')->isAvailable()) {
        
        // HashRemoveCronjob - Alte Einträge in der Hash-Tabelle entfernen
        $sql->setQuery("SELECT id FROM " . rex::getTable('cronjob') . " WHERE type = 'rex_statistics_hashremove_cronjob' LIMIT 1");
        if ($sql->getRows() === 0) {
            $cronjob = rex_cronjob::factory('rex_statistics_hashremove_cronjob');
            $cronjob->setName('REDAXO Statistics - Hash Cleanup');
            $cronjob->setDescription('Entfernt alte Hash-Einträge aus der Datenbank');
            $cronjob->setEnvironments([rex_cronjob::BACKEND, rex_cronjob::FRONTEND]);
            $cronjob->setInterval('{\"minutes\":\"all\",\"hours\":\"1\",\"days\":\"all\",\"weekdays\":\"all\",\"months\":\"all\"}');
            $cronjob->setExecution(1); // Aktiv
            
            $manager = rex_cronjob_manager::factory();
            $manager->add($cronjob);
        }
        
        // DataCleanupCronjob - Alte Statistik-Daten zusammenfassen und bereinigen
        $sql->setQuery("SELECT id FROM " . rex::getTable('cronjob') . " WHERE type = 'rex_statistics_datacleanup_cronjob' LIMIT 1");
        if ($sql->getRows() === 0) {
            $cronjob = rex_cronjob::factory('rex_statistics_datacleanup_cronjob');
            $cronjob->setName('REDAXO Statistics - Datenbank-Optimierung');
            $cronjob->setDescription('Optimiert die Datenbank durch Aggregation alter Statistikdaten');
            $cronjob->setEnvironments([rex_cronjob::BACKEND, rex_cronjob::FRONTEND]);
            $cronjob->setInterval('{\"minutes\":\"0\",\"hours\":\"3\",\"days\":\"all\",\"weekdays\":\"all\",\"months\":\"all\"}');
            $cronjob->setExecution(1); // Aktiv
            
            $manager = rex_cronjob_manager::factory();
            $manager->add($cronjob);
        }
    }
    
    // Erstmalige Bereinigung der HTTP-Status-Einträge - entferne alle 200er Status
    try {
        $sql->setQuery("DELETE FROM " . rex::getTable('pagestats_urlstatus') . " WHERE status = '200'");
    } catch (rex_sql_exception $e) {
        // Fehler beim Löschen - nicht kritisch
        rex_logger::logException($e);
    }
}
