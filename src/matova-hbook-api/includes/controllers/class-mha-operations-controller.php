<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Operations / reporting controller (all read scope).
 *   GET /day/{date}                     arrivals + departures + in-house
 *   GET /operations/occupancy?from&to
 *   GET /operations/outstanding
 *   GET /stats/monthly?year
 */
class MHA_Operations_Controller extends MHA_Controller {

    public function register() {
        $ns = $this->ns();

        register_rest_route($ns, '/day/(?P<date>\d{4}-\d{2}-\d{2})', [
            'methods'             => 'GET',
            'callback'            => [$this, 'day'],
            'permission_callback' => MHA_Auth::require_scope('read'),
        ]);

        register_rest_route($ns, '/operations/occupancy', [
            'methods'             => 'GET',
            'callback'            => [$this, 'occupancy'],
            'permission_callback' => MHA_Auth::require_scope('read'),
        ]);

        register_rest_route($ns, '/operations/outstanding', [
            'methods'             => 'GET',
            'callback'            => [$this, 'outstanding'],
            'permission_callback' => MHA_Auth::require_scope('read'),
        ]);

        register_rest_route($ns, '/stats/monthly', [
            'methods'             => 'GET',
            'callback'            => [$this, 'stats_monthly'],
            'permission_callback' => MHA_Auth::require_scope('read'),
        ]);
    }

    /** Active statuses considered "real" occupancy for operations. */
    private function active_statuses() {
        return MHA_HBook::ACTIVE_STATUSES;
    }

    public function day(WP_REST_Request $req) {
        return $this->wrap(function () use ($req) {
            $date = MHA_Request::date_param($req, 'date', true);
            if (is_wp_error($date)) { return $date; }

            $next = gmdate('Y-m-d', strtotime($date . ' +1 day'));
            // Pull a window spanning the date; filter in PHP by role.
            $rows = MHA_HBook::reservations_in_window($date, $next, null, null, 200, 0);
            $active = $this->active_statuses();

            $arrivals = [];
            $departures = [];
            $in_house = [];
            foreach ($rows as $r) {
                if (!in_array($r['status'], $active, true)) { continue; }
                if ($r['check_in'] === $date)  { $arrivals[] = $r; }
                if ($r['check_out'] === $date) { $departures[] = $r; }
                if ($r['check_in'] <= $date && $r['check_out'] > $date) { $in_house[] = $r; }
            }
            // Departures leave on their check_out day, which may fall outside the
            // [date, next) window used above; fetch them explicitly.
            $dep_rows = MHA_HBook::reservations_in_window(gmdate('Y-m-d', strtotime($date . ' -1 day')), $next, null, null, 200, 0);
            $departures = [];
            foreach ($dep_rows as $r) {
                if (!in_array($r['status'], $active, true)) { continue; }
                if ($r['check_out'] === $date) { $departures[] = $r; }
            }

            return [
                'date'       => $date,
                'arrivals'   => $arrivals,
                'departures' => $departures,
                'in_house'   => $in_house,
                'counts'     => [
                    'arrivals'   => count($arrivals),
                    'departures' => count($departures),
                    'in_house'   => count($in_house),
                ],
            ];
        });
    }

    public function occupancy(WP_REST_Request $req) {
        return $this->wrap(function () use ($req) {
            $from = MHA_Request::date_param($req, 'from', true);
            if (is_wp_error($from)) { return $from; }
            $to = MHA_Request::date_param($req, 'to', true);
            if (is_wp_error($to)) { return $to; }
            if ($to <= $from) {
                return new WP_Error('bad_request', '"to" must be after "from".', ['status' => 400]);
            }

            $units = MHA_HBook::accom_units();
            $units_total = count($units);

            $avail = MHA_HBook::availability($from, $to, null);
            $occupied = $avail['occupied'];

            // Occupied unit-nights across the window.
            $from_ts = strtotime($from);
            $to_ts = strtotime($to);
            $total_nights = max(0, (int) round(($to_ts - $from_ts) / 86400));
            $capacity_unit_nights = $units_total * $total_nights;

            $occupied_unit_nights = 0;
            foreach ($occupied as $o) {
                $o_from = max($from_ts, strtotime($o['from']));
                $o_to = min($to_ts, strtotime($o['to']));
                if ($o_to > $o_from) {
                    $occupied_unit_nights += (int) round(($o_to - $o_from) / 86400);
                }
            }

            $rate = $capacity_unit_nights > 0
                ? round($occupied_unit_nights / $capacity_unit_nights, 4)
                : null;

            return [
                'from'                 => $from,
                'to'                   => $to,
                'nights'               => $total_nights,
                'units_total'          => $units_total,
                'capacity_unit_nights' => $capacity_unit_nights,
                'occupied_unit_nights' => $occupied_unit_nights,
                'occupancy_rate'       => $rate,
            ];
        });
    }

    public function outstanding(WP_REST_Request $req) {
        return $this->wrap(function () use ($req) {
            $from = MHA_Request::date_param($req, 'from', false);
            if (is_wp_error($from)) { return $from; }
            $to = MHA_Request::date_param($req, 'to', false);
            if (is_wp_error($to)) { return $to; }

            $active = $this->active_statuses();
            $rows = MHA_HBook::reservations_in_window($from, $to, null, null, 200, 0);

            $items = [];
            $total_outstanding = 0.0;
            foreach ($rows as $r) {
                if (!in_array($r['status'], $active, true)) { continue; }
                $price = (float) ($r['price'] ?? 0);
                $paid = MHA_HBook::payments_sum($r['payments_logs']);
                $balance = round($price - $paid, 2);
                if ($balance > 0.0) {
                    $items[] = [
                        'id'          => $r['id'],
                        'check_in'    => $r['check_in'],
                        'check_out'   => $r['check_out'],
                        'status'      => $r['status'],
                        'price'       => $price,
                        'paid'        => round($paid, 2),
                        'outstanding' => $balance,
                        'customer_id' => $r['customer_id'],
                    ];
                    $total_outstanding += $balance;
                }
            }

            return [
                'items'             => $items,
                'count'             => count($items),
                'total_outstanding' => round($total_outstanding, 2),
            ];
        });
    }

    public function stats_monthly(WP_REST_Request $req) {
        return $this->wrap(function () use ($req) {
            $year = MHA_Request::int_param($req, 'year', false, 2000, 2100);
            if (is_wp_error($year)) { return $year; }
            if ($year === null) { $year = (int) gmdate('Y'); }

            $from = sprintf('%04d-01-01', $year);
            $to = sprintf('%04d-01-01', $year + 1);
            $active = $this->active_statuses();

            $rows = MHA_HBook::reservations_in_window($from, $to, null, null, 200, 0);
            // Page through if needed.
            $offset = 200;
            while (count($rows) >= $offset) {
                $more = MHA_HBook::reservations_in_window($from, $to, null, null, 200, $offset);
                if (empty($more)) { break; }
                $rows = array_merge($rows, $more);
                $offset += 200;
                if ($offset > 10000) { break; } // safety cap
            }

            $months = [];
            for ($m = 1; $m <= 12; $m++) {
                $months[$m] = ['reservations' => 0, 'nights' => 0, 'revenue' => 0.0, 'paid' => 0.0];
            }

            foreach ($rows as $r) {
                if (!in_array($r['status'], $active, true)) { continue; }
                $ci = $r['check_in'];
                if (!$ci) { continue; }
                $m = (int) substr($ci, 5, 2);
                $y = (int) substr($ci, 0, 4);
                if ($y !== (int) $year || $m < 1 || $m > 12) { continue; }
                $nights = 0;
                if ($r['check_out']) {
                    $nights = max(0, (int) round((strtotime($r['check_out']) - strtotime($ci)) / 86400));
                }
                $months[$m]['reservations']++;
                $months[$m]['nights'] += $nights;
                $months[$m]['revenue'] += (float) ($r['price'] ?? 0);
                $months[$m]['paid'] += MHA_HBook::payments_sum($r['payments_logs']);
            }

            foreach ($months as $m => &$row) {
                $row['revenue'] = round($row['revenue'], 2);
                $row['paid'] = round($row['paid'], 2);
            }
            unset($row);

            return ['year' => (int) $year, 'months' => $months];
        });
    }
}
