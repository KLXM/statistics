<?php

use AndiLeni\Statistics\Ip2Geo;

$addon = rex_addon::get('statistics');


// post request which handles deletion of stats data
if (rex_request_method() == 'post') {
    $function = rex_post('func', 'string', '');

    if ($function == 'delete_hash') {
        $sql = rex_sql::factory();
        $sql->setQuery('delete from ' . rex::getTable('pagestats_hash'));
        echo rex_view::success($sql->getRows() . ' ' . $addon->i18n('statistics_deleted_hashes'));
    } elseif ($function == 'delete_dump') {
        $sql = rex_sql::factory();
        $count = 0;

        $sql->setQuery('delete from ' . rex::getTable('pagestats_data'));
        $count += $sql->getRows();

        $sql->setQuery('delete from ' . rex::getTable('pagestats_visits_per_day'));
        $count += $sql->getRows();

        $sql->setQuery('delete from ' . rex::getTable('pagestats_visitors_per_day'));
        $count += $sql->getRows();

        $sql->setQuery('delete from ' . rex::getTable('pagestats_visits_per_url'));
        $count += $sql->getRows();

        echo rex_view::success($count . ' ' . $addon->i18n('statistics_deleted_dump'));
    } elseif ($function == 'delete_media') {
        $sql = rex_sql::factory();
        $sql->setQuery('delete from ' . rex::getTable('pagestats_media'));
        echo rex_view::success($sql->getRows() . ' ' . $addon->i18n('statistics_deleted_bots'));
    } elseif ($function == 'delete_bot') {
        $sql = rex_sql::factory();
        $sql->setQuery('delete from ' . rex::getTable('pagestats_bot'));
        echo rex_view::success($sql->getRows() . ' ' . $addon->i18n('statistics_deleted_referer'));
    } elseif ($function == 'delete_referer') {
        $sql = rex_sql::factory();
        $sql->setQuery('delete from ' . rex::getTable('pagestats_referer'));
        echo rex_view::success($sql->getRows() . ' ' . $addon->i18n('statistics_deleted_media'));
    } elseif ($function == 'delete_media') {
        $sql = rex_sql::factory();
        $sql->setQuery('delete from ' . rex::getTable('pagestats_media'));
        echo rex_view::success('Es wurden ' . $sql->getRows() . ' Einträge aus der Tabelle media gelöscht.</div>');
    } elseif ($function == 'delete_campaigns') {
        $sql = rex_sql::factory();
        $sql->setQuery('delete from ' . rex::getTable('pagestats_api'));
        echo rex_view::success('Es wurden ' . $sql->getRows() . ' Einträge aus der Tabelle api gelöscht.');
    } elseif ($function == 'updateGeo2Ip') {
        $updated = Ip2Geo::updateDatabase();
        if ($updated) {
            echo rex_view::success("Geo Datenbank geupdated.");
        } else {
            echo rex_view::success("Geo Datenbank konnte nicht aktualisiert werden.");
        }
    }
}


$form = rex_config_form::factory("statistics");

$form->addFieldset("Allgemein");

// Cache-Einstellungen am Anfang hinzufügen
$form->addFieldset("Performance & Cache");

$field_cache = $form->addRadioField('statistics_use_cache');
$field_cache->setLabel('Statistik-Cache verwenden');
$field_cache->addOption($addon->i18n('statistics_yes'), 1);
$field_cache->addOption($addon->i18n('statistics_no'), 0);
$field_cache->setNotice('Beschleunigt das Laden der Statistiken erheblich, besonders bei großen Datenmengen.');

$field_cache_lifetime = $form->addTextField('statistics_cache_lifetime');
$field_cache_lifetime->setLabel('Cache-Lebensdauer (in Sekunden)');
$field_cache_lifetime->setAttribute('type', 'number');
$field_cache_lifetime->setAttribute('min', '300'); // Minimum 5 Minuten
$field_cache_lifetime->setNotice('Wie lange die Statistikdaten im Cache vorgehalten werden sollen (Standard: 3600 = 1 Stunde).');
$field_cache_lifetime->getValidator()->add('type', 'Bitte eine ganze Zahl eingeben.', 'int');

$field_cleanup = $form->addRadioField('statistics_auto_cleanup');
$field_cleanup->setLabel('Automatische Datensatzbereinigung');
$field_cleanup->addOption($addon->i18n('statistics_yes'), 1);
$field_cleanup->addOption($addon->i18n('statistics_no'), 0);
$field_cleanup->setNotice('Alte Detaildaten werden automatisch bereinigt, um die Datenbank klein zu halten.');

$field_cleanup_days = $form->addTextField('statistics_cleanup_days');
$field_cleanup_days->setLabel('Detaildaten aufbewahren (Tage)');
$field_cleanup_days->setAttribute('type', 'number');
$field_cleanup_days->setAttribute('min', '30'); // Minimum 30 Tage
$field_cleanup_days->setNotice('Detaildaten älter als diese Anzahl Tage werden aggregiert und die Originaldaten gelöscht.');
$field_cleanup_days->getValidator()->add('type', 'Bitte eine ganze Zahl eingeben.', 'int');

$form->addFieldset("Allgemein");

$field2 = $form->addTextField('statistics_visit_duration');
$field2->setLabel($addon->i18n('statistics_visit_duration'));
$field2->setNotice($addon->i18n('statistics_duration_note'));
$field2->getValidator()->add('type', $addon->i18n('statistics_duration_validate'), 'int');


$field = $form->addTextAreaField('statistics_ignored_paths');
$field->setLabel($addon->i18n('statistics_ignore_paths'));
$field->setNotice($addon->i18n('statistics_paths_note'));


$field3 = $form->addTextAreaField('statistics_ignored_ips');
$field3->setLabel($addon->i18n('statistics_ignore_ips'));
$field3->setNotice($addon->i18n('statistics_ips_note'));


$field3 = $form->addTextAreaField('pagestats_ignored_regex');
$field3->setLabel($addon->i18n('pagestats_ignored_regex'));
$field3->setNotice($addon->i18n('pagestats_ignored_regex_note'));


$field4 = $form->addRadioField('statistics_scroll_pagination');
$field4->setLabel($addon->i18n('statistics_scroll_pagination'));
$field4->addOption($addon->i18n('statistics_scroll_table'), 'table');
$field4->addOption($addon->i18n('statistics_scroll_panel'), 'panel');
$field4->addOption($addon->i18n('statistics_scroll_none'), 'none');


$field5 = $form->addRadioField('statistics_ignore_url_params');
$field5->setLabel($addon->i18n('statistics_statistics_ignore_url_params'));
$field5->addOption($addon->i18n('statistics_yes'), 1);
$field5->addOption($addon->i18n('statistics_no'), 0);
$field5->setNotice($addon->i18n('statistics_statistics_ignore_url_params_note'));


$field6 = $form->addRadioField('statistics_default_datefilter_range');
$field6->setLabel($addon->i18n('statistics_default_datefilter_range'));
$field6->addOption($addon->i18n('statistics_default_datefilter_last7days'), 'last7days');
$field6->addOption($addon->i18n('statistics_default_datefilter_last30days'), 'last30days');
$field6->addOption($addon->i18n('statistics_default_datefilter_thisYear'), 'thisYear');
$field6->addOption($addon->i18n('statistics_default_datefilter_wholeTime'), 'wholeTime');
$field6->setNotice($addon->i18n('statistics_default_datefilter_range_note'));


$field7 = $form->addRadioField('statistics_combine_all_domains');
$field7->setLabel('Fasse alle Domains zusammen');
$field7->addOption($addon->i18n('statistics_yes'), 1);
$field7->addOption($addon->i18n('statistics_no'), 0);
$field7->setNotice('Alle Domains werden zu einer "Gesamt" Anzahl zusammengefasst. Deaktivieren um Statistiken für alle Domains einzeln anzuzeigen.');

$field7 = $form->addRadioField('statistics_show_chart_toolbox');
$field7->setLabel('Zeige Toolbox an den Charts');
$field7->addOption($addon->i18n('statistics_yes'), 1);
$field7->addOption($addon->i18n('statistics_no'), 0);


$field8 = $form->addRadioField('statistics_ignore_backend_loggedin');
$field8->setLabel('Eigene Seitenaufrufe ignorieren');
$field8->addOption($addon->i18n('statistics_yes'), 1);
$field8->addOption($addon->i18n('statistics_no'), 0);
$field8->setNotice('Aktivieren, um Seitenaufrufe durch eingeloggte User zu verwerfen.');


$field = $form->addRadioField('statistics_rec_onlyok');
$field->setLabel('Nur 200er Aufrufe erfassen');
$field->addOption($addon->i18n('statistics_yes'), 1);
$field->addOption($addon->i18n('statistics_no'), 0);
$field->setNotice('Dadurch werden nur Aufrufe mit einem HTTP Status 200 OK erfasst. Die Statistik "Seitenaufrufe" loggt trotzdem auch Aufrufe ungleich 200.');


// media
$form->addFieldset("Media");

$fm1 = $form->addRadioField('statistics_media_log_all');
$fm1->setLabel($addon->i18n('statistics_media_log_all'));
$fm1->addOption($addon->i18n('statistics_media_yes'), 1);
$fm1->addOption($addon->i18n('statistics_media_no'), 0);
$fm1->setNotice($addon->i18n('statistics_media_log_all_note'));

$fm2 = $form->addRadioField('statistics_media_log_mm');
$fm2->setLabel($addon->i18n('statistics_media_log_mm'));
$fm2->addOption($addon->i18n('statistics_media_yes'), 1);
$fm2->addOption($addon->i18n('statistics_media_no'), 0);
$fm2->setNotice($addon->i18n('statistics_media_log_mm_note'));

$note = rex_view::warning("Nur eine dieser beiden Optionen aktivieren, sonst werden Aufrufe doppelt gezählt.");
$form->addRawField($note);


// api
$form->addFieldset("API");

$field = $form->addRadioField('statistics_api_enable');
$field->setLabel($addon->i18n('statistics_api_enable_campaigns'));
$field->addOption($addon->i18n('statistics_api_yes'), 1);
$field->addOption($addon->i18n('statistics_api_no'), 0);
$field->setNotice($addon->i18n('statistics_api_enable_campaigns_note'));


// parse fragment with setting form
$addon = rex_addon::get('statistics');
$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', $addon->i18n('statistics_settings'), false);
$fragment->setVar('body', $form->get(), false);
echo $fragment->parse('core/page/section.php');



// ip2geo section
$geoIpHtml = '
<p>Geo-Datenbank updaten mit der IP-Adressen zu Ländern zugeordnet werden.</p>
<form style="margin:5px" action="' . rex_url::currentBackendPage() . '" method="post">
<input type="hidden" name="func" value="updateGeo2Ip">
<button class="btn btn-primary" type="submit">Geo-Datenbank Updaten</button>
</form>
<p><a href="https://db-ip.com">IP Geolocation by DB-IP</a></p>
';

$fragment = new rex_fragment();
$fragment->setVar('class', 'info', false);
$fragment->setVar('title', "IP 2 Geo", false);
$fragment->setVar('body', $geoIpHtml, false);
echo $fragment->parse('core/page/section.php');




// forms which should make a post request to this page to trigger deletion of stats data
$content = '
<div style="display: flex; flex-wrap: wrap">

<form style="margin:5px" action="' . rex_url::currentBackendPage() . '" method="post">
<input type="hidden" name="func" value="delete_hash">
<button class="btn btn-danger" type="submit" data-confirm="' . $addon->i18n('statistics_confirm_delete_hashes') . '">' . $addon->i18n('statistics_delete_hashes') . '</button>
</form>

<form style="margin:5px" action="' . rex_url::currentBackendPage() . '" method="post">
<input type="hidden" name="func" value="delete_dump">
<button class="btn btn-danger" type="submit" data-confirm="' . $addon->i18n('statistics_confirm_delete_dump') . '">' . $addon->i18n('statistics_delete_visits') . '</button>
</form>

<form style="margin:5px" action="' . rex_url::currentBackendPage() . '" method="post">
<input type="hidden" name="func" value="delete_bot">
<button class="btn btn-danger" type="submit" data-confirm="' . $addon->i18n('statistics_confirm_delete_bots') . '">' . $addon->i18n('statistics_delete_bots') . '</button>
</form>

<form style="margin:5px" action="' . rex_url::currentBackendPage() . '" method="post">
<input type="hidden" name="func" value="delete_referer">
<button class="btn btn-danger" type="submit" data-confirm="' . $addon->i18n('statistics_confirm_delete_referer') . '">' . $addon->i18n('statistics_delete_referer') . '</button>
</form>

<form style="margin:5px" action="' . rex_url::currentBackendPage() . '" method="post">
<input type="hidden" name="func" value="delete_media">
<button class="btn btn-danger" type="submit" data-confirm="' . $addon->i18n('statistics_media_delete_media_confirm') . '">' . $addon->i18n('statistics_media_delete_media') . '</button>
</form>

<form style="margin:5px" action="' . rex_url::currentBackendPage() . '" method="post">
<input type="hidden" name="func" value="delete_campaigns">
<button class="btn btn-danger" type="submit" data-confirm="' . $addon->i18n('statistics_api_delete_api_confirm') . '">' . $addon->i18n('statistics_api_delete_api') . '</button>
</form>

</div>
';


$fragment = new rex_fragment();
$fragment->setVar('class', 'danger', false);
$fragment->setVar('title', $addon->i18n('statistics_delete_statistics'), false);
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');
