<?php


rex_sql_table::get(rex::getTable('pagestats_data'))
    ->ensureColumn(new rex_sql_column('type', 'varchar(255)'))
    ->ensureColumn(new rex_sql_column('name', 'varchar(255)'))
    ->ensureColumn(new rex_sql_column('count', 'int'))
    ->setPrimaryKey(['type', 'name'])
    ->ensure();

rex_sql_table::get(rex::getTable('pagestats_visits_per_day'))
    ->ensureColumn(new rex_sql_column('date', 'date'))
    ->ensureColumn(new rex_sql_column('domain', 'varchar(255)'))
    ->ensureColumn(new rex_sql_column('count', 'int'))
    ->setPrimaryKey(['date', 'domain'])
    ->ensure();

rex_sql_table::get(rex::getTable('pagestats_visitors_per_day'))
    ->ensureColumn(new rex_sql_column('date', 'date'))
    ->ensureColumn(new rex_sql_column('domain', 'varchar(255)'))
    ->ensureColumn(new rex_sql_column('count', 'int'))
    ->setPrimaryKey(['date', 'domain'])
    ->ensure();

rex_sql_table::get(rex::getTable('pagestats_visits_per_url'))
    ->ensureColumn(new rex_sql_column('hash', 'varchar(255)'))
    ->ensureColumn(new rex_sql_column('date', 'date'))
    ->ensureColumn(new rex_sql_column('url', 'varchar(2048)'))
    ->ensureColumn(new rex_sql_column('count', 'int'))
    ->setPrimaryKey(['hash'])
    ->ensure();

rex_sql_table::get(rex::getTable('pagestats_urlstatus'))
    ->ensureColumn(new rex_sql_column('hash', 'varchar(255)'))
    ->ensureColumn(new rex_sql_column('url', 'varchar(2048)'))
    ->ensureColumn(new rex_sql_column('status', 'varchar(255)'))
    ->setPrimaryKey(['hash'])
    ->ensure();

rex_sql_table::get(rex::getTable('pagestats_bot'))
    ->ensureColumn(new rex_sql_column('name', 'varchar(255)'))
    ->ensureColumn(new rex_sql_column('category', 'varchar(255)'))
    ->ensureColumn(new rex_sql_column('producer', 'varchar(255)'))
    ->ensureColumn(new rex_sql_column('count', 'int'))
    ->setPrimaryKey(['name', 'category', 'producer'])
    ->ensure();

rex_sql_table::get(rex::getTable('pagestats_hash'))
    ->ensureColumn(new rex_sql_column('hash', 'varchar(255)'))
    ->ensureColumn(new rex_sql_column('datetime', 'datetime'))
    ->setPrimaryKey(['hash'])
    ->ensure();

rex_sql_table::get(rex::getTable('pagestats_referer'))
    ->removeColumn('id')
    ->ensureColumn(new rex_sql_column('hash', 'varchar(255)'))
    ->ensureColumn(new rex_sql_column('referer', 'varchar(2048)'))
    ->ensureColumn(new rex_sql_column('date', 'date'))
    ->ensureColumn(new rex_sql_column('count', 'int'))
    ->setPrimaryKey(['hash'])
    ->ensure();

rex_sql_table::get(rex::getTable('pagestats_sessionstats'))
    ->ensureColumn(new rex_sql_column('token', 'varchar(255)'))
    ->ensureColumn(new rex_sql_column('lastpage', 'varchar(255)'))
    ->ensureColumn(new rex_sql_column('lastvisit', 'datetime'))
    ->ensureColumn(new rex_sql_column('visitduration', 'int'))
    ->ensureColumn(new rex_sql_column('pagecount', 'int'))
    ->setPrimaryKey(['token'])
    ->ensure();

// media
rex_sql_table::get(rex::getTable('pagestats_media'))
    ->ensureColumn(new rex_sql_column('url', 'varchar(255)'))
    ->ensureColumn(new rex_sql_column('date', 'date'))
    ->ensureColumn(new rex_sql_column('count', 'int'))
    ->setPrimaryKey(['url', 'date'])
    ->ensure();


// api
rex_sql_table::get(rex::getTable('pagestats_api'))
    ->ensureColumn(new rex_sql_column('name', 'varchar(255)'))
    ->ensureColumn(new rex_sql_column('date', 'date'))
    ->ensureColumn(new rex_sql_column('count', 'int'))
    ->setPrimaryKey(['name', 'date'])
    ->ensure();

// Performance-Indexe hinzufügen für bessere Abfrage-Performance
$sql = rex_sql::factory();

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

// Cronjobs registrieren
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
        $id = $manager->add($cronjob);
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
        $id = $manager->add($cronjob);
    }
}

// Standard-Einstellungen für die neuen Cache-Optionen setzen
if (rex_addon::get('statistics')) {
    $addon = rex_addon::get('statistics');
    
    // Wenn die Einstellungen noch nicht existieren, setze sinnvolle Standardwerte
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
}

// ip 2 geo database installation
$today = new DateTimeImmutable();
$dbUrl = "https://download.db-ip.com/free/dbip-country-lite-{$today->format('Y-m')}.mmdb.gz";

try {
    $socket = rex_socket::factoryUrl($dbUrl);

    $response = $socket->doGet();
    if ($response->isOk()) {
        $body = $response->getBody();
        $body = gzdecode($body);
        rex_file::put(rex_path::addonData("statistics", "ip2geo.mmdb"), $body);
        return true;
    }

    return false;
} catch (rex_socket_exception $e) {
    rex_logger::logException($e);
    return false;
}
