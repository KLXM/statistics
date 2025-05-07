<?php

namespace AndiLeni\Statistics;

use DateInterval;
use DatePeriod;
use DateTime;
use DateTimeImmutable;
use InvalidArgumentException;
use rex;
use rex_addon;
use rex_sql;
use rex_sql_exception;
use rex_config; // Für die Cache-Konfiguration
use rex_path; // Für Cache-Dateipfade

class chartData
{

    private DateFilter $filter_date_helper;
    private rex_addon $addon;
    private static $cache = [];
    private $cache_lifetime = 3600; // 1 Stunde Cache-Lebensdauer
    private $use_cache = true;

    /**
     * 
     * 
     * @param DateFilter $filter_date_helper 
     * @return void 
     * @throws InvalidArgumentException 
     */
    public function __construct(DateFilter $filter_date_helper)
    {
        $this->filter_date_helper = $filter_date_helper;
        $this->addon = rex_addon::get('statistics');
        
        // Cache-Lebensdauer kann in den Einstellungen konfiguriert werden
        $this->cache_lifetime = $this->addon->getConfig('statistics_cache_lifetime', $this->cache_lifetime);
        $this->use_cache = $this->addon->getConfig('statistics_use_cache', true);
    }
    
    /**
     * Versucht Daten aus dem Cache zu lesen
     * 
     * @param string $key Cache-Schlüssel
     * @return mixed|null Cached data oder null wenn nicht gefunden/abgelaufen
     */
    private function getFromCache($key) 
    {
        if (!$this->use_cache) {
            return null;
        }
        
        $cache_file = $this->getCacheFilePath($key);
        
        if (file_exists($cache_file)) {
            $cache_data = unserialize(file_get_contents($cache_file));
            
            // Prüfe, ob Cache noch gültig ist
            if ($cache_data['timestamp'] > time() - $this->cache_lifetime) {
                return $cache_data['data'];
            }
        }
        
        return null;
    }
    
    /**
     * Speichert Daten im Cache
     * 
     * @param string $key Cache-Schlüssel
     * @param mixed $data zu speichernde Daten
     */
    private function saveToCache($key, $data) 
    {
        if (!$this->use_cache) {
            return;
        }
        
        $cache_file = $this->getCacheFilePath($key);
        
        // Stelle sicher, dass das Cache-Verzeichnis existiert
        $cache_dir = dirname($cache_file);
        if (!is_dir($cache_dir)) {
            mkdir($cache_dir, 0755, true);
        }
        
        $cache_data = [
            'timestamp' => time(),
            'data' => $data
        ];
        
        file_put_contents($cache_file, serialize($cache_data));
    }
    
    /**
     * Generiert den Cache-Dateipfad für einen Schlüssel
     */
    private function getCacheFilePath($key)
    {
        $cache_dir = rex_path::addonCache('statistics');
        return $cache_dir . md5($key) . '.cache';
    }
    
    /**
     * Generiert einen eindeutigen Cache-Schlüssel basierend auf Methode und Parametern
     */
    private function generateCacheKey($method, $params = [])
    {
        return 'chartdata_' . $method . '_' . md5(json_encode($params) . '_' . 
               $this->filter_date_helper->date_start->format('Y-m-d') . '_' . 
               $this->filter_date_helper->date_end->format('Y-m-d'));
    }

    /**
     * 
     * 
     * @return array 
     */
    private function getLabels(): array
    {
        // modify end date, because sql includes start and end, php ommits end
        $period = new DatePeriod(
            $this->filter_date_helper->date_start,
            new DateInterval('P1D'),
            $this->filter_date_helper->date_end->modify('+1 day')
        );

        $labels = [];
        foreach ($period as $value) {
            $labels[] = $value->format("d.m.Y");
        }

        return $labels;
    }


    /**
     * 
     * 
     * @return array 
     * @throws InvalidArgumentException 
     * @throws rex_sql_exception 
     */
    public function getMainChartData(): array
    {
        $cache_key = $this->generateCacheKey('getMainChartData');
        $cached_data = $this->getFromCache($cache_key);
        
        if ($cached_data !== null) {
            return $cached_data;
        }
        
        $data_visits = $this->getVisitsPerDay();
        $data_visitors = $this->getVisitorsPerDay();
        $data_chart = array_merge($data_visits, $data_visitors);
        $xaxis_values = $this->getLabels();
        $legend_values = array_column($data_chart, 'name');

        $result = [
            'series' => $data_chart,
            'legend' => $legend_values,
            'xaxis' => $xaxis_values,
        ];
        
        $this->saveToCache($cache_key, $result);
        
        return $result;
    }


    /**
     * 
     * 
     * @return array 
     * @throws InvalidArgumentException 
     * @throws rex_sql_exception 
     */
    private function getVisitsPerDay(): array
    {
        $cache_key = $this->generateCacheKey('getVisitsPerDay');
        $cached_data = $this->getFromCache($cache_key);
        
        if ($cached_data !== null) {
            return $cached_data;
        }
        
        // DATA COLLECTION FOR MAIN CHART, "VIEWS PER DAY"

        // modify end date, because sql includes start and end, php ommits end
        $period = new DatePeriod(
            $this->filter_date_helper->date_start,
            new DateInterval('P1D'),
            $this->filter_date_helper->date_end->modify('+1 day')
        );

        $sql = rex_sql::factory();
        $domains = $sql->getArray('select distinct domain from ' . rex::getTable('pagestats_visits_per_day'));

        $data_chart_visits = [];

        // "total"
        $sql_data = $sql->setQuery('SELECT date, ifnull(sum(count),0) as "count" from ' . rex::getTable('pagestats_visits_per_day') . ' where date between :start and :end group by date ORDER BY date ASC', ['start' => $this->filter_date_helper->date_start->format('Y-m-d'), ':end' => $this->filter_date_helper->date_end->format('Y-m-d')]);

        $dates_array = [];
        foreach ($period as $value) {
            $dates_array[$value->format("d.m.Y")] = "0";
        }

        $complete_dates_counts = [];
        $date_counts = [];

        if ($sql_data->getRows() != 0) {
            foreach ($sql_data as $row) {
                $date = DateTime::createFromFormat('Y-m-d', $row->getValue('date'))->format('d.m.Y');
                $date_counts[$date] = $row->getValue('count');
            }

            $complete_dates_counts = array_merge($dates_array, $date_counts);
        }

        $values = array_values($complete_dates_counts);

        $data_chart_visits[] = [
            'data' => $values,
            'name' => $this->addon->i18n('statistics_visits_total'),
            'type' => 'line',
        ];

        // include stats for each domain if "combine_all_domains" is disabled
        if ($this->addon->getConfig('statistics_combine_all_domains') == false) {
            foreach ($domains as $domain) {
                $sql_data = $sql->setQuery('SELECT date, ifnull(count,0) as "count" from ' . rex::getTable('pagestats_visits_per_day') . ' where date between :start and :end and domain = :domain ORDER BY date ASC', ['start' => $this->filter_date_helper->date_start->format('Y-m-d'), ':end' => $this->filter_date_helper->date_end->format('Y-m-d'), 'domain' => $domain['domain']]);

                $visits_per_day = [];
                foreach ($period as $value) {
                    $visits_per_day[$value->format("d.m.Y")] = "0";
                }

                $date_counts = [];

                if ($sql_data->getRows() != 0) {
                    foreach ($sql_data as $row) {
                        $date = DateTime::createFromFormat('Y-m-d', $row->getValue('date'))->format('d.m.Y');
                        $visits_per_day[$date] = $row->getValue('count');
                    }
                }

                $values = array_values($visits_per_day);

                $data_chart_visits[] = [
                    'data' => $values,
                    'name' => $this->addon->i18n('statistics_views_domain', $domain['domain']),
                    'type' => 'line',
                ];
            }
        }
        
        $this->saveToCache($cache_key, $data_chart_visits);
        
        return $data_chart_visits;
    }


    /**
     * 
     * 
     * @return array 
     * @throws InvalidArgumentException 
     * @throws rex_sql_exception 
     */
    private function getVisitorsPerDay(): array
    {
        $cache_key = $this->generateCacheKey('getVisitorsPerDay');
        $cached_data = $this->getFromCache($cache_key);
        
        if ($cached_data !== null) {
            return $cached_data;
        }
        
        // DATA COLLECTION FOR MAIN CHART, "VISITORS PER DAY"

        // modify end date, because sql includes start and end, php ommits end
        $period = new DatePeriod(
            $this->filter_date_helper->date_start,
            new DateInterval('P1D'),
            $this->filter_date_helper->date_end->modify('+1 day')
        );


        $sql = rex_sql::factory();
        $domains = $sql->getArray('select distinct domain from ' . rex::getTable('pagestats_visitors_per_day'));

        $data_chart_visitors = [];


        // "total"
        $sql_data = $sql->setQuery('SELECT date, ifnull(sum(count),0) as "count" from ' . rex::getTable('pagestats_visitors_per_day') . ' where date between :start and :end group by date ORDER BY date ASC', ['start' => $this->filter_date_helper->date_start->format('Y-m-d'), ':end' => $this->filter_date_helper->date_end->format('Y-m-d')]);

        $dates_array = [];
        foreach ($period as $value) {
            $dates_array[$value->format("d.m.Y")] = "0";
        }

        $date_counts = [];

        if ($sql_data->getRows() != 0) {
            foreach ($sql_data as $row) {
                $date = DateTime::createFromFormat('Y-m-d', $row->getValue('date'))->format('d.m.Y');
                $date_counts[$date] = $row->getValue('count');
            }
        }
        
        $complete_dates_counts = array_merge($dates_array, $date_counts);

        $values = array_values($complete_dates_counts);

        $data_chart_visitors[] = [
            'data' => $values,
            'name' => $this->addon->i18n('statistics_visitors_total'),
            'type' => 'line',
        ];

        // include stats for each domain if "combine_all_domains" is disabled
        if ($this->addon->getConfig('statistics_combine_all_domains') == false) {
            foreach ($domains as $domain) {
                $sql_data = $sql->setQuery('SELECT date, ifnull(count,0) as "count" from ' . rex::getTable('pagestats_visitors_per_day') . ' where date between :start and :end and domain = :domain ORDER BY date ASC', ['start' => $this->filter_date_helper->date_start->format('Y-m-d'), ':end' => $this->filter_date_helper->date_end->format('Y-m-d'), 'domain' => $domain['domain']]);

                $visitors_per_day = [];
                foreach ($period as $value) {
                    $visitors_per_day[$value->format("d.m.Y")] = "0";
                }

                if ($sql_data->getRows() != 0) {
                    foreach ($sql_data as $row) {
                        $date = DateTime::createFromFormat('Y-m-d', $row->getValue('date'))->format('d.m.Y');
                        $visitors_per_day[$date] = $row->getValue('count');
                    }
                }

                $values = array_values($visitors_per_day);

                $data_chart_visitors[] = [
                    'data' => $values,
                    'name' => $this->addon->i18n('statistics_visitors_domain', $domain['domain']),
                    'type' => 'line',
                ];
            }
        }
        
        $this->saveToCache($cache_key, $data_chart_visitors);
        return $data_chart_visitors;
    }


    /**
     * 
     * 
     * @return array 
     * @throws InvalidArgumentException 
     * @throws rex_sql_exception 
     */
    public function getHeatmapVisits(): array
    {
        $cache_key = $this->generateCacheKey('getHeatmapVisits');
        $cached_data = $this->getFromCache($cache_key);
        
        if ($cached_data !== null) {
            return $cached_data;
        }
        
        // data for heatmap chart

        $sql = rex_sql::factory();

        $jan_first = new DateTimeImmutable('first day of january this year');
        $dec_last = new DateTimeImmutable('first day of january next year');
        $visits_per_day = $sql->getArray('SELECT date, ifnull(sum(count),0) as "count" from ' . rex::getTable('pagestats_visits_per_day') . ' where date between :start and :end group by date ORDER BY date ASC', ['start' => $jan_first->format('Y-m-d'), ':end' => $dec_last->format('Y-m-d')]);

        $heatmap_calendar = [];
        foreach ($visits_per_day as $row) {
            $heatmap_calendar[$row['date']] = $row['count'];
        }

        $period = new DatePeriod(
            $jan_first,
            new DateInterval('P1D'),
            $dec_last
        );

        $data_visits_heatmap_values = [];
        foreach ($period as $value) {
            if (in_array($value->format("Y-m-d"), array_keys($heatmap_calendar))) {
                $data_visits_heatmap_values[] = [$value->format("Y-m-d"), $heatmap_calendar[$value->format("Y-m-d")]];
            } else {
                $data_visits_heatmap_values[] = [$value->format("Y-m-d"), 0];
            }
        }

        if (count($heatmap_calendar) == 0) {
            $max_value = 0;
        } else {
            $max_value = max(array_values($heatmap_calendar));
        }

        $result = [
            'data' => $data_visits_heatmap_values,
            'max' => $max_value,
        ];
        
        $this->saveToCache($cache_key, $result);
        return $result;
    }


    /**
     * 
     * 
     * @return array 
     * @throws InvalidArgumentException 
     * @throws rex_sql_exception 
     */
    public function getChartDataMonthly(): array
    {
        $cache_key = $this->generateCacheKey('getChartDataMonthly');
        $cached_data = $this->getFromCache($cache_key);
        
        if ($cached_data !== null) {
            return $cached_data;
        }
        
        $legend = [];
        $xaxis = [];
        $series = [];

        // VISITS

        $sql = rex_sql::factory();
        $domains = $sql->getArray('select distinct domain from ' . rex::getTable('pagestats_visitors_per_day'));

        $min_max_date = $sql->getArray('SELECT MIN(date) AS "min_date", MAX(date) AS "max_date" FROM ' . rex::getTable('pagestats_visits_per_day'));


        if ($min_max_date[0]['min_date'] == null) {
            $min_date = new DateTimeImmutable();
            $max_date = new DateTimeImmutable();
        } else {
            $min_date = DateTimeImmutable::createFromFormat('Y-m-d', $min_max_date[0]['min_date']);
            $max_date = DateTimeImmutable::createFromFormat('Y-m-d', $min_max_date[0]['max_date']);
        }


        $period = new DatePeriod(
            $min_date,
            new DateInterval('P1M'),
            $max_date->modify("+1 month")
        );

        $serie_data = [];
        foreach ($period as $value) {
            $xaxis[] = $value->format("M Y"); // generate xaxis values once
            $serie_data[$value->format("M Y")] = 0; // initialize each month with 0
        }

        // get total visits - das war:
        // $result_total = $sql->getArray('SELECT DATE_FORMAT(date,"%b %Y") AS "month", IFNULL(SUM(count),0) AS "count" FROM ' . rex::getTable('pagestats_visits_per_day') . ' GROUP BY month ORDER BY date ASC');
        
        // Optimiert: Fügen Sie speziell den Monat hinzu, um die Gruppierung zu verbessern
        $result_total = $sql->getArray('SELECT DATE_FORMAT(date,"%b %Y") AS "month", DATE_FORMAT(date,"%Y-%m") AS month_sort, IFNULL(SUM(count),0) AS "count" FROM ' . rex::getTable('pagestats_visits_per_day') . ' GROUP BY month_sort, month ORDER BY month_sort ASC');

        // set count to each month
        foreach ($result_total as $row) {
            $serie_data[$row['month']] = $row['count'];
        }

        // combine data to series array for chart
        $serie = [
            'data' => array_values($serie_data),
            'name' => $this->addon->i18n('statistics_views_total'),
            'type' => 'line',
        ];

        // append to legend
        $legend[] = $this->addon->i18n('statistics_views_total');

        // add serie to series
        $series[] = $serie;

        // do this procedure for each domain
        if ($this->addon->getConfig('statistics_combine_all_domains') == false) {
            foreach ($domains as $domain) {
                $result_domain = $sql->getArray('SELECT DATE_FORMAT(date,"%b %Y") AS "month", DATE_FORMAT(date,"%Y-%m") AS month_sort, IFNULL(SUM(count),0) AS "count" FROM ' . rex::getTable('pagestats_visits_per_day') . ' WHERE domain = :domain GROUP BY month_sort, month ORDER BY month_sort ASC', ['domain' => $domain['domain']]);

                $serie_data = [];
                foreach ($period as $value) {
                    $serie_data[$value->format("M Y")] = "0";
                }

                foreach ($result_domain as $row) {
                    $serie_data[$row['month']] = $row['count'];
                }

                $serie = [
                    'data' => array_values($serie_data),
                    'name' => $this->addon->i18n('statistics_views_domain', $domain['domain']),
                    'type' => 'line',
                ];

                $legend[] = $this->addon->i18n('statistics_views_domain', $domain['domain']);
                $series[] = $serie;
            }
        }

        // VISITORS

        // get total visits
        $result_total = $sql->getArray('SELECT DATE_FORMAT(date,"%b %Y") AS "month", DATE_FORMAT(date,"%Y-%m") AS month_sort, IFNULL(SUM(count),0) AS "count" FROM ' . rex::getTable('pagestats_visitors_per_day') . ' GROUP BY month_sort, month ORDER BY month_sort ASC');

        $serie_data = [];
        foreach ($period as $value) {
            $serie_data[$value->format("M Y")] = 0; // initialize each month with 0
        }

        // set count to each month
        foreach ($result_total as $row) {
            $serie_data[$row['month']] = $row['count'];
        }

        // combine data to series array for chart
        $serie = [
            'data' => array_values($serie_data),
            'name' => $this->addon->i18n('statistics_visitors_total'),
            'type' => 'line',
        ];

        // append to legend
        $legend[] = $this->addon->i18n('statistics_visitors_total');

        // add serie to series
        $series[] = $serie;

        // do this procedure for each domain
        if ($this->addon->getConfig('statistics_combine_all_domains') == false) {
            foreach ($domains as $domain) {
                $result_domain = $sql->getArray('SELECT DATE_FORMAT(date,"%b %Y") AS "month", DATE_FORMAT(date,"%Y-%m") AS month_sort, IFNULL(SUM(count),0) AS "count" FROM ' . rex::getTable('pagestats_visitors_per_day') . ' WHERE domain = :domain GROUP BY month_sort, month ORDER BY month_sort ASC', ['domain' => $domain['domain']]);

                $serie_data = [];
                foreach ($period as $value) {
                    $serie_data[$value->format("M Y")] = "0";
                }

                foreach ($result_domain as $row) {
                    $serie_data[$row['month']] = $row['count'];
                }

                $serie = [
                    'data' => array_values($serie_data),
                    'name' => $this->addon->i18n('statistics_visitors_domain', $domain['domain']),
                    'type' => 'line',
                ];

                $legend[] = $this->addon->i18n('statistics_visitors_domain', $domain['domain']);
                $series[] = $serie;
            }
        }

        $result = [
            'series' => $series,
            'legend' => $legend,
            'xaxis' => $xaxis,
        ];
        
        $this->saveToCache($cache_key, $result);
        return $result;
    }


    /**
     * 
     * 
     * @return array 
     * @throws InvalidArgumentException 
     * @throws rex_sql_exception 
     */
    public function getChartDataYearly(): array
    {
        $cache_key = $this->generateCacheKey('getChartDataYearly');
        $cached_data = $this->getFromCache($cache_key);
        
        if ($cached_data !== null) {
            return $cached_data;
        }
        
        $legend = [];
        $xaxis = [];
        $series = [];

        // VISITS

        $sql = rex_sql::factory();
        $domains = $sql->getArray('select distinct domain from ' . rex::getTable('pagestats_visitors_per_day'));

        $min_max_date = $sql->getArray('SELECT MIN(date) AS "min_date", MAX(date) AS "max_date" FROM ' . rex::getTable('pagestats_visits_per_day') . '');

        if ($min_max_date[0]['min_date'] == null) {
            $min_date = new DateTimeImmutable();
            $max_date = new DateTimeImmutable();
        } else {
            $min_date = DateTimeImmutable::createFromFormat('Y-m-d', $min_max_date[0]['min_date']);
            $max_date = DateTimeImmutable::createFromFormat('Y-m-d', $min_max_date[0]['max_date']);
        }

        $period = new DatePeriod(
            $min_date,
            new DateInterval('P1Y'),
            $max_date->modify('+1 year')
        );

        $serie_data = [];
        foreach ($period as $value) {
            $xaxis[] = $value->format("Y"); // generate xaxis values once
            $serie_data[$value->format("Y")] = 0; // initialize each year with 0
        }

        // get total visits
        $result_total = $sql->getArray('SELECT DATE_FORMAT(date,"%Y") AS "year", IFNULL(SUM(count),0) AS "count" FROM ' . rex::getTable('pagestats_visits_per_day') . ' GROUP BY year ORDER BY date ASC');

        // set count to each year
        foreach ($result_total as $row) {
            $serie_data[$row['year']] = $row['count'];
        }

        // combine data to series array for chart
        $serie = [
            'data' => array_values($serie_data),
            'name' => $this->addon->i18n('statistics_views_total'),
            'type' => 'line',
        ];

        // append to legend
        $legend[] = $this->addon->i18n('statistics_views_total');

        // add serie to series
        $series[] = $serie;

        // do this procedure for each domain
        if ($this->addon->getConfig('statistics_combine_all_domains') == false) {
            foreach ($domains as $domain) {
                $result_domain = $sql->getArray('SELECT DATE_FORMAT(date,"%Y") AS "year", IFNULL(SUM(count),0) AS "count" FROM ' . rex::getTable('pagestats_visits_per_day') . ' WHERE domain = :domain GROUP BY year ORDER BY date ASC', ['domain' => $domain['domain']]);

                $serie_data = [];
                foreach ($period as $value) {
                    $serie_data[$value->format("Y")] = "0";
                }

                foreach ($result_domain as $row) {
                    $serie_data[$row['year']] = $row['count'];
                }

                $serie = [
                    'data' => array_values($serie_data),
                    'name' => $this->addon->i18n('statistics_views_domain', $domain['domain']),
                    'type' => 'line',
                ];

                $legend[] = $this->addon->i18n('statistics_views_domain', $domain['domain']);
                $series[] = $serie;
            }
        }

        // VISITORS

        // get total visits
        $result_total = $sql->getArray('SELECT DATE_FORMAT(date,"%Y") AS "year", IFNULL(SUM(count),0) AS "count" FROM ' . rex::getTable('pagestats_visitors_per_day') . ' GROUP BY year ORDER BY date ASC');

        $serie_data = [];
        foreach ($period as $value) {
            $serie_data[$value->format("Y")] = 0; // initialize each year with 0
        }

        // set count to each year
        foreach ($result_total as $row) {
            $serie_data[$row['year']] = $row['count'];
        }

        // combine data to series array for chart
        $serie = [
            'data' => array_values($serie_data),
            'name' => $this->addon->i18n('statistics_visitors_total'),
            'type' => 'line',
        ];

        // append to legend
        $legend[] = $this->addon->i18n('statistics_visitors_total');

        // add serie to series
        $series[] = $serie;

        // do this procedure for each domain
        if ($this->addon->getConfig('statistics_combine_all_domains') == false) {
            foreach ($domains as $domain) {
                $result_domain = $sql->getArray('SELECT DATE_FORMAT(date,"%Y") AS "year", IFNULL(SUM(count),0) AS "count" FROM ' . rex::getTable('pagestats_visitors_per_day') . ' WHERE domain = :domain GROUP BY year ORDER BY date ASC', ['domain' => $domain['domain']]);

                $serie_data = [];
                foreach ($period as $value) {
                    $serie_data[$value->format("Y")] = "0";
                }

                foreach ($result_domain as $row) {
                    $serie_data[$row['year']] = $row['count'];
                }

                $serie = [
                    'data' => array_values($serie_data),
                    'name' => $this->addon->i18n('statistics_visitors_domain', $domain['domain']),
                    'type' => 'line',
                ];

                $legend[] = $this->addon->i18n('statistics_visitors_domain', $domain['domain']);
                $series[] = $serie;
            }
        }

        $result = [
            'series' => $series,
            'legend' => $legend,
            'xaxis' => $xaxis,
        ];
        
        $this->saveToCache($cache_key, $result);
        return $result;
    }
    
    /**
     * Löscht den gesamten Cache
     */
    public static function clearCache()
    {
        $cache_dir = rex_path::addonCache('statistics');
        if (is_dir($cache_dir)) {
            $files = glob($cache_dir . '*.cache');
            if ($files) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
        }
    }
}
