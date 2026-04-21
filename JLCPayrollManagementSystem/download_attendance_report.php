<?php
session_start();

if (!isset($_SESSION['Admin_User'])) {
    header("Location: login.php");
    exit;
}

include_once("connection.php");

// ============================================
// PAYROLL CALCULATION FUNCTIONS (from payrollList.php)
// ============================================

function getWorkdayMultipliersForReport($workday_type) {
    if (empty($workday_type)) {
        $workday_type = 'Ordinary Working Day';
    }
    
    $multipliers = [
        'Ordinary Working Day' => [
            'basic' => 1.0,
            'overtime' => 1.25,
            'night' => 1.10,
            'paid_when_absent' => false
        ],
        'Rest Day / Sunday' => [
            'basic' => 1.3,
            'overtime' => 1.69,
            'night' => 1.43,
            'paid_when_absent' => false
        ],
        'Special (Non-Working) Day' => [
            'basic' => 1.3,
            'overtime' => 1.69,
            'night' => 1.43,
            'paid_when_absent' => false
        ],
        'Special Day that falls on Rest Day' => [
            'basic' => 1.5,
            'overtime' => 1.95,
            'night' => 1.65,
            'paid_when_absent' => false
        ],
        'Regular Holiday' => [
            'basic' => 2.0,
            'overtime' => 2.6,
            'night' => 2.2,
            'paid_when_absent' => true
        ],
        'Regular Holiday on the Rest Day' => [
            'basic' => 2.6,
            'overtime' => 3.38,
            'night' => 2.86,
            'paid_when_absent' => true
        ],
        'Double Holiday' => [
            'basic' => 3.0,
            'overtime' => 3.9,
            'night' => 3.3,
            'paid_when_absent' => true
        ],
        'Double Holiday on the Rest Day' => [
            'basic' => 3.9,
            'overtime' => 5.07,
            'night' => 4.29,
            'paid_when_absent' => true
        ]
    ];
    
    if (isset($multipliers[$workday_type])) {
        return $multipliers[$workday_type];
    } else {
        return $multipliers['Ordinary Working Day'];
    }
}

function calculateHoursForReport($time_in, $time_out) {
    if (empty($time_in) || empty($time_out)) {
        return 0;
    }
    
    $is_midnight_out = ($time_out == '00:00:00');
    $time_in_ts = strtotime($time_in);
    
    if ($time_in_ts === false) {
        return 0;
    }
    
    if ($is_midnight_out) {
        $date_of_time_in = date('Y-m-d', $time_in_ts);
        $next_day = strtotime('+1 day', strtotime($date_of_time_in));
        $time_out_ts = strtotime(date('Y-m-d', $next_day) . ' 00:00:00');
        $hours = round(($time_out_ts - $time_in_ts) / 3600, 2);
        return max(0, $hours);
    }
    
    $time_out_ts = strtotime($time_out);
    if ($time_out_ts === false) {
        return 0;
    }
    
    if ($time_out_ts < $time_in_ts) {
        $time_out_ts += 86400;
    }
    
    $hours = round(($time_out_ts - $time_in_ts) / 3600, 2);
    return max(0, $hours);
}

// Calculate payroll for combined day hours (AM + PM together)
function calculateDayPayrollWithBreakdown($am_hours, $pm_hours, $hourly_rate, $multipliers, $is_paid_holiday_for_regular = false) {
    $total_hours = $am_hours + $pm_hours;
    
    if ($total_hours == 0) {
        return [
            'hours' => 0,
            'payroll' => 0,
            'regular_hours' => 0,
            'overtime_hours' => 0,
            'type' => 'none'
        ];
    }
    
    $payroll = 0;
    $regular_hours = 0;
    $overtime_hours = 0;
    
    if ($is_paid_holiday_for_regular) {
        if ($total_hours <= 8) {
            $regular_hours = $total_hours;
            $payroll = $total_hours * $hourly_rate * 1.0;
        } else {
            $regular_hours = 8;
            $overtime_hours = $total_hours - 8;
            $payroll = (8 * $hourly_rate * 1.0) + ($overtime_hours * $hourly_rate * $multipliers['overtime']);
        }
    } else {
        if ($total_hours <= 8) {
            $regular_hours = $total_hours;
            $payroll = $total_hours * $hourly_rate * $multipliers['basic'];
        } else {
            $regular_hours = 8;
            $overtime_hours = $total_hours - 8;
            $payroll = (8 * $hourly_rate * $multipliers['basic']) + ($overtime_hours * $hourly_rate * $multipliers['overtime']);
        }
    }
    
    return [
        'hours' => $total_hours,
        'payroll' => $payroll,
        'regular_hours' => $regular_hours,
        'overtime_hours' => $overtime_hours,
        'type' => $overtime_hours > 0 ? 'overtime' : 'regular'
    ];
}

// Calculate payroll for night session (independent, has its own 8-hour quota)
function calculateNightPayrollWithBreakdown($night_hours, $hourly_rate, $multipliers) {
    if ($night_hours == 0) {
        return [
            'hours' => 0,
            'payroll' => 0,
            'regular_hours' => 0,
            'overtime_hours' => 0,
            'type' => 'none'
        ];
    }
    
    $payroll = 0;
    $regular_hours = 0;
    $overtime_hours = 0;
    
    if ($night_hours <= 8) {
        $regular_hours = $night_hours;
        $payroll = $night_hours * $hourly_rate * $multipliers['night'];
    } else {
        $regular_hours = 8;
        $overtime_hours = $night_hours - 8;
        $payroll = (8 * $hourly_rate * $multipliers['night']) + ($overtime_hours * $hourly_rate * $multipliers['overtime']);
    }
    
    return [
        'hours' => $night_hours,
        'payroll' => $payroll,
        'regular_hours' => $regular_hours,
        'overtime_hours' => $overtime_hours,
        'type' => $overtime_hours > 0 ? 'night_overtime' : 'night_regular'
    ];
}

// Get parameters
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$employee_id = isset($_GET['employee_id']) ? intval($_GET['employee_id']) : 0;
$site_id = isset($_GET['site_id']) ? intval($_GET['site_id']) : 0;

if (empty($date_from) || empty($date_to)) {
    header("Location: attendance.php?error=missing_dates");
    exit;
}

// Get employee name if filtered
$employee_name = "All Employees";
if ($employee_id > 0) {
    $name_query = "SELECT CONCAT(first_name, ' ', last_name) as full_name, daily_salary, employment_type FROM employees WHERE id = ?";
    $name_stmt = $conn->prepare($name_query);
    $name_stmt->bind_param("i", $employee_id);
    $name_stmt->execute();
    $name_result = $name_stmt->get_result();
    if ($name_row = $name_result->fetch_assoc()) {
        $employee_name = $name_row['full_name'];
    }
    $name_stmt->close();
}

// Get site name if filtered
$selected_site_name = '';
if ($site_id > 0) {
    $site_query = "SELECT site_name FROM site_monitoring WHERE id = ?";
    $site_stmt = $conn->prepare($site_query);
    $site_stmt->bind_param("i", $site_id);
    $site_stmt->execute();
    $site_result = $site_stmt->get_result();
    if ($site_row = $site_result->fetch_assoc()) {
        $selected_site_name = $site_row['site_name'];
    }
    $site_stmt->close();
}

// Determine which sites to include
if ($site_id > 0 && !empty($selected_site_name)) {
    $sites = [$selected_site_name];
} else {
    // Get all distinct sites that have attendance records within the date range
    $sites_query = "SELECT DISTINCT site_name 
                    FROM (
                        SELECT site_assignment_am as site_name FROM attendance 
                        WHERE date BETWEEN ? AND ? AND site_assignment_am IS NOT NULL AND site_assignment_am != ''
                        UNION
                        SELECT site_assignment_pm as site_name FROM attendance 
                        WHERE date BETWEEN ? AND ? AND site_assignment_pm IS NOT NULL AND site_assignment_pm != ''
                        UNION
                        SELECT site_assignment_night as site_name FROM attendance 
                        WHERE date BETWEEN ? AND ? AND site_assignment_night IS NOT NULL AND site_assignment_night != ''
                    ) AS sites
                    ORDER BY site_name";

    $sites_stmt = $conn->prepare($sites_query);
    $sites_stmt->bind_param("ssssss", $date_from, $date_to, $date_from, $date_to, $date_from, $date_to);
    $sites_stmt->execute();
    $sites_result = $sites_stmt->get_result();

    $sites = [];
    while ($site_row = $sites_result->fetch_assoc()) {
        $sites[] = $site_row['site_name'];
    }
    $sites_stmt->close();
}

// Format time function - displays 12:00 AM correctly
$formatTime = function($time) {
    if (empty($time) || $time === null) {
        return '--:--';
    }
    if ($time == '00:00:00') {
        return '12:00 AM';
    }
    return date('h:i A', strtotime($time));
};

// Get employee daily salaries for payroll calculation
$employee_salaries = [];
$salary_query = "SELECT id, daily_salary, employment_type FROM employees WHERE status = 'active'";
$salary_result = $conn->query($salary_query);
while ($sal_row = $salary_result->fetch_assoc()) {
    $employee_salaries[$sal_row['id']] = [
        'daily_salary' => $sal_row['daily_salary'],
        'employment_type' => $sal_row['employment_type']
    ];
}

// Track guaranteed pay per employee per day (to avoid duplication)
$guaranteed_pay_tracker = [];

// ============================================================
// Fetch all attendance records, group by employee+date,
// calculate totals, then distribute to sites.
// ============================================================

// Build base query to fetch all relevant attendance records (across all sites)
$base_sql = "SELECT 
                e.id AS employee_id,
                e.first_name,
                e.middle_name,
                e.last_name,
                e.position,
                e.daily_salary,
                e.employment_type,
                a.id as attendance_id,
                a.date,
                a.status,
                a.pm_status,
                a.night_status,
                a.time_in_am,
                a.time_out_am,
                a.time_in_pm,
                a.time_out_pm,
                a.time_in_night,
                a.time_out_night,
                a.remarks,
                a.leave_type,
                a.workday_type,
                a.site_assignment_am,
                a.site_assignment_pm,
                a.site_assignment_night
            FROM employees e
            INNER JOIN attendance a ON a.employee_id = e.id 
            WHERE a.date BETWEEN ? AND ?";

$params = [$date_from, $date_to];
$param_types = "ss";

if ($employee_id > 0) {
    $base_sql .= " AND e.id = ?";
    $params[] = $employee_id;
    $param_types .= "i";
}

// If a specific site is selected, we still need to fetch only records where that site appears
if ($site_id > 0 && !empty($selected_site_name)) {
    $base_sql .= " AND (a.site_assignment_am = ? OR a.site_assignment_pm = ? OR a.site_assignment_night = ?)";
    $params[] = $selected_site_name;
    $params[] = $selected_site_name;
    $params[] = $selected_site_name;
    $param_types .= "sss";
}

$base_sql .= " ORDER BY e.last_name ASC, a.date ASC";

$stmt = $conn->prepare($base_sql);
if ($stmt === false) {
    die("Prepare failed: " . $conn->error);
}
$stmt->bind_param($param_types, ...$params);
$stmt->execute();
$all_records_result = $stmt->get_result();

// Group all records by employee_id and date
$grouped_by_emp_date = [];
while ($row = $all_records_result->fetch_assoc()) {
    $key = $row['employee_id'] . '_' . $row['date'];
    if (!isset($grouped_by_emp_date[$key])) {
        $grouped_by_emp_date[$key] = [
            'employee_id' => $row['employee_id'],
            'first_name' => $row['first_name'],
            'middle_name' => $row['middle_name'],
            'last_name' => $row['last_name'],
            'position' => $row['position'],
            'daily_salary' => $row['daily_salary'],
            'employment_type' => $row['employment_type'],
            'date' => $row['date'],
            'workday_type' => $row['workday_type'],
            'leave_type' => $row['leave_type'],
            'remarks' => $row['remarks'],
            'sessions' => []  // will hold AM, PM, night sessions from all sites
        ];
    }
    
    // Add session entries for each non-empty site assignment.
    if (!empty($row['site_assignment_am'])) {
        $grouped_by_emp_date[$key]['sessions'][] = [
            'type' => 'am',
            'site' => $row['site_assignment_am'],
            'time_in' => $row['time_in_am'],
            'time_out' => $row['time_out_am'],
            'status' => $row['status'] ?? 'Present'
        ];
    }
    if (!empty($row['site_assignment_pm'])) {
        $grouped_by_emp_date[$key]['sessions'][] = [
            'type' => 'pm',
            'site' => $row['site_assignment_pm'],
            'time_in' => $row['time_in_pm'],
            'time_out' => $row['time_out_pm'],
            'status' => $row['pm_status'] ?? 'Present'
        ];
    }
    if (!empty($row['site_assignment_night'])) {
        $grouped_by_emp_date[$key]['sessions'][] = [
            'type' => 'night',
            'site' => $row['site_assignment_night'],
            'time_in' => $row['time_in_night'],
            'time_out' => $row['time_out_night'],
            'status' => $row['night_status'] ?? 'Present'
        ];
    }
}

// Process each employee+date group to calculate totals and distribute hours
$processed_sessions = []; // store each session with allocated regular/overtime hours

foreach ($grouped_by_emp_date as $group_key => $group) {
    $employee_id = $group['employee_id'];
    $daily_salary = $group['daily_salary'] ?? ($employee_salaries[$employee_id]['daily_salary'] ?? 0);
    $hourly_rate = $daily_salary / 8;
    $employment_type = $group['employment_type'] ?? ($employee_salaries[$employee_id]['employment_type'] ?? 'regular');
    
    // Check if on leave
    $is_on_leave = (!empty($group['leave_type']) && $group['leave_type'] != 'None' && $group['leave_type'] != '');
    
    // Workday type and multipliers
    $workday_type = $group['workday_type'] ?? 'Ordinary Working Day';
    $multipliers = getWorkdayMultipliersForReport($workday_type);
    $is_paid_holiday_for_regular = ($employment_type === 'regular' && $multipliers['paid_when_absent']);
    
    // ============================================================
    // HANDLE LEAVE DAYS: Employee on leave gets full daily salary as GUARANTEED PAY ONLY ONCE
    // ============================================================
    if ($is_on_leave) {
        $leave_paid_for_this_day = false;  // Flag to ensure we add guaranteed pay only once
        
        foreach ($group['sessions'] as $sess) {
            if (!$leave_paid_for_this_day) {
                // First session encountered gets the guaranteed pay
                $processed_sessions[] = [
                    'employee_id' => $group['employee_id'],
                    'first_name' => $group['first_name'],
                    'middle_name' => $group['middle_name'],
                    'last_name' => $group['last_name'],
                    'position' => $group['position'],
                    'date' => $group['date'],
                    'site' => $sess['site'],
                    'type' => $sess['type'],
                    'time_in' => $sess['time_in'],
                    'time_out' => $sess['time_out'],
                    'status' => 'On Leave',
                    'workday_type' => $workday_type,
                    'leave_type' => $group['leave_type'],
                    'remarks' => $group['remarks'],
                    'regular_hours' => 0,
                    'overtime_hours' => 0,
                    'regular_pay' => 0,
                    'overtime_pay' => 0,
                    'guaranteed_pay' => $daily_salary,
                    'is_paid_holiday' => false
                ];
                $leave_paid_for_this_day = true;
            } else {
                // All subsequent sessions get zero pay (for display only)
                $processed_sessions[] = [
                    'employee_id' => $group['employee_id'],
                    'first_name' => $group['first_name'],
                    'middle_name' => $group['middle_name'],
                    'last_name' => $group['last_name'],
                    'position' => $group['position'],
                    'date' => $group['date'],
                    'site' => $sess['site'],
                    'type' => $sess['type'],
                    'time_in' => $sess['time_in'],
                    'time_out' => $sess['time_out'],
                    'status' => 'On Leave',
                    'workday_type' => $workday_type,
                    'leave_type' => $group['leave_type'],
                    'remarks' => $group['remarks'],
                    'regular_hours' => 0,
                    'overtime_hours' => 0,
                    'regular_pay' => 0,
                    'overtime_pay' => 0,
                    'guaranteed_pay' => 0,
                    'is_paid_holiday' => false
                ];
            }
        }
        // Skip normal attendance processing for this group
        continue;
    }
    
    // ============================================================
    // NORMAL ATTENDANCE PROCESSING (not on leave)
    // ============================================================
    
    // Separate sessions by type
    $am_sessions = [];
    $pm_sessions = [];
    $night_sessions = [];
    foreach ($group['sessions'] as $idx => $sess) {
        if ($sess['type'] == 'am') $am_sessions[] = ['index' => $idx, 'data' => $sess];
        elseif ($sess['type'] == 'pm') $pm_sessions[] = ['index' => $idx, 'data' => $sess];
        elseif ($sess['type'] == 'night') $night_sessions[] = ['index' => $idx, 'data' => $sess];
    }
    
    // Calculate total day hours (AM+PM) across all sites
    $total_am_hours = 0;
    foreach ($am_sessions as $s) {
        $total_am_hours += calculateHoursForReport($s['data']['time_in'], $s['data']['time_out']);
    }
    $total_pm_hours = 0;
    foreach ($pm_sessions as $s) {
        $total_pm_hours += calculateHoursForReport($s['data']['time_in'], $s['data']['time_out']);
    }
    $total_day_hours = $total_am_hours + $total_pm_hours;
    
    // Calculate total night hours
    $total_night_hours = 0;
    foreach ($night_sessions as $s) {
        $total_night_hours += calculateHoursForReport($s['data']['time_in'], $s['data']['time_out']);
    }
    
    // Get payroll breakdown for day (combined)
    $day_result = calculateDayPayrollWithBreakdown($total_am_hours, $total_pm_hours, $hourly_rate, $multipliers, $is_paid_holiday_for_regular);
    $night_result = calculateNightPayrollWithBreakdown($total_night_hours, $hourly_rate, $multipliers);
    
    // Distribute regular and overtime day hours to individual AM/PM sessions (chronological order)
    $remaining_regular = $day_result['regular_hours'];
    $remaining_overtime = $day_result['overtime_hours'];
    
    usort($am_sessions, function($a, $b) {
        return strtotime($a['data']['time_in']) - strtotime($b['data']['time_in']);
    });
    usort($pm_sessions, function($a, $b) {
        return strtotime($a['data']['time_in']) - strtotime($b['data']['time_in']);
    });
    $ordered_sessions = array_merge($am_sessions, $pm_sessions);
    
    foreach ($ordered_sessions as $s) {
        $sess_hours = calculateHoursForReport($s['data']['time_in'], $s['data']['time_out']);
        $reg_alloc = 0;
        $ot_alloc = 0;
        
        if ($remaining_regular > 0) {
            $reg_alloc = min($sess_hours, $remaining_regular);
            $remaining_regular -= $reg_alloc;
        }
        if ($reg_alloc < $sess_hours && $remaining_overtime > 0) {
            $ot_alloc = min($sess_hours - $reg_alloc, $remaining_overtime);
            $remaining_overtime -= $ot_alloc;
        }
        
        $processed_sessions[] = [
            'employee_id' => $group['employee_id'],
            'first_name' => $group['first_name'],
            'middle_name' => $group['middle_name'],
            'last_name' => $group['last_name'],
            'position' => $group['position'],
            'date' => $group['date'],
            'site' => $s['data']['site'],
            'type' => $s['data']['type'],
            'time_in' => $s['data']['time_in'],
            'time_out' => $s['data']['time_out'],
            'status' => $s['data']['status'],
            'workday_type' => $workday_type,
            'leave_type' => $group['leave_type'],
            'remarks' => $group['remarks'],
            'regular_hours' => $reg_alloc,
            'overtime_hours' => $ot_alloc,
            'regular_pay' => $reg_alloc * $hourly_rate * ($is_paid_holiday_for_regular ? 1.0 : $multipliers['basic']),
            'overtime_pay' => $ot_alloc * $hourly_rate * $multipliers['overtime'],
            'is_paid_holiday' => false
        ];
    }
    
    // Distribute night hours
    $remaining_night_regular = $night_result['regular_hours'];
    $remaining_night_overtime = $night_result['overtime_hours'];
    
    usort($night_sessions, function($a, $b) {
        return strtotime($a['data']['time_in']) - strtotime($b['data']['time_in']);
    });
    
    foreach ($night_sessions as $s) {
        $sess_hours = calculateHoursForReport($s['data']['time_in'], $s['data']['time_out']);
        $reg_alloc = 0;
        $ot_alloc = 0;
        
        if ($remaining_night_regular > 0) {
            $reg_alloc = min($sess_hours, $remaining_night_regular);
            $remaining_night_regular -= $reg_alloc;
        }
        if ($reg_alloc < $sess_hours && $remaining_night_overtime > 0) {
            $ot_alloc = min($sess_hours - $reg_alloc, $remaining_night_overtime);
            $remaining_night_overtime -= $ot_alloc;
        }
        
        $processed_sessions[] = [
            'employee_id' => $group['employee_id'],
            'first_name' => $group['first_name'],
            'middle_name' => $group['middle_name'],
            'last_name' => $group['last_name'],
            'position' => $group['position'],
            'date' => $group['date'],
            'site' => $s['data']['site'],
            'type' => $s['data']['type'],
            'time_in' => $s['data']['time_in'],
            'time_out' => $s['data']['time_out'],
            'status' => $s['data']['status'],
            'workday_type' => $workday_type,
            'leave_type' => $group['leave_type'],
            'remarks' => $group['remarks'],
            'regular_hours' => $reg_alloc,
            'overtime_hours' => $ot_alloc,
            'regular_pay' => $reg_alloc * $hourly_rate * $multipliers['night'],
            'overtime_pay' => $ot_alloc * $hourly_rate * $multipliers['overtime'],
            'is_paid_holiday' => false
        ];
    }
    
    // Handle guaranteed pay for regular employees on paid holidays
    $date_key = $group['date'] . '_' . $group['employee_id'];
    $apply_guaranteed_pay = false;
    $guaranteed_pay_amount = 0;
    if ($is_paid_holiday_for_regular && count($group['sessions']) > 0) {
        if (!isset($guaranteed_pay_tracker[$date_key])) {
            $guaranteed_pay_tracker[$date_key] = ['applied' => false, 'site' => null, 'amount' => $daily_salary];
        }
        if (!$guaranteed_pay_tracker[$date_key]['applied']) {
            $guaranteed_pay_tracker[$date_key]['applied'] = true;
            if (!empty($ordered_sessions)) {
                $first_site = $ordered_sessions[0]['data']['site'];
                $guaranteed_pay_tracker[$date_key]['site'] = $first_site;
            } else {
                $guaranteed_pay_tracker[$date_key]['site'] = 'Unknown';
            }
            $apply_guaranteed_pay = true;
            $guaranteed_pay_amount = $daily_salary;
        }
    }
    if ($apply_guaranteed_pay) {
        $added = false;
        foreach ($processed_sessions as &$ps) {
            if ($ps['employee_id'] == $group['employee_id'] && $ps['date'] == $group['date']) {
                $ps['guaranteed_pay'] = $guaranteed_pay_amount;
                $added = true;
                break;
            }
        }
        if (!$added) {
            $processed_sessions[] = [
                'employee_id' => $group['employee_id'],
                'first_name' => $group['first_name'],
                'middle_name' => $group['middle_name'],
                'last_name' => $group['last_name'],
                'position' => $group['position'],
                'date' => $group['date'],
                'site' => $guaranteed_pay_tracker[$date_key]['site'],
                'type' => 'guaranteed',
                'time_in' => null,
                'time_out' => null,
                'status' => 'Guaranteed Pay',
                'workday_type' => $workday_type,
                'leave_type' => $group['leave_type'],
                'remarks' => $group['remarks'],
                'regular_hours' => 0,
                'overtime_hours' => 0,
                'regular_pay' => 0,
                'overtime_pay' => 0,
                'guaranteed_pay' => $guaranteed_pay_amount,
                'is_paid_holiday' => true
            ];
        }
    }
}

// ============================================================
// Now group the processed sessions by site and build rows
// ============================================================
$site_data = [];
$grand_total_records = 0;
$grand_total_hours = 0;
$grand_total_payroll = 0;
$grand_total_guaranteed_pay = 0;
$grand_regular_hours = 0;
$grand_overtime_hours = 0;
$grand_regular_night_hours = 0;
$grand_night_overtime_hours = 0;

foreach ($sites as $site_name) {
    $site_rows = [];
    $site_present_count = 0;
    $site_absent_count = 0;
    $site_leave_count = 0;
    $site_total_hours = 0;
    $site_total_payroll = 0;
    $site_guaranteed_pay = 0;
    $site_regular_hours = 0;
    $site_overtime_hours = 0;
    $site_regular_night_hours = 0;
    $site_night_overtime_hours = 0;
    
    $employee_totals = [];
    
    // Filter sessions belonging to this site
    $site_sessions = array_filter($processed_sessions, function($sess) use ($site_name) {
        return $sess['site'] == $site_name;
    });
    
    // Group by employee+date for display (one row per employee per date per site)
    $grouped_by_emp_date_site = [];
    foreach ($site_sessions as $sess) {
        $key = $sess['employee_id'] . '_' . $sess['date'];
        if (!isset($grouped_by_emp_date_site[$key])) {
            $grouped_by_emp_date_site[$key] = [
                'employee_id' => $sess['employee_id'],
                'first_name' => $sess['first_name'],
                'middle_name' => $sess['middle_name'],
                'last_name' => $sess['last_name'],
                'position' => $sess['position'],
                'date' => $sess['date'],
                'workday_type' => $sess['workday_type'],
                'leave_type' => $sess['leave_type'],
                'remarks' => $sess['remarks'],
                'am' => null,
                'pm' => null,
                'night' => null,
                'total_hours' => 0,
                'total_payroll' => 0,
                'guaranteed_pay' => 0,
                'regular_hours' => 0,
                'overtime_hours' => 0,
                'regular_night_hours' => 0,
                'night_overtime_hours' => 0
            ];
        }
        if ($sess['type'] == 'am') {
            $grouped_by_emp_date_site[$key]['am'] = $sess;
        } elseif ($sess['type'] == 'pm') {
            $grouped_by_emp_date_site[$key]['pm'] = $sess;
        } elseif ($sess['type'] == 'night') {
            $grouped_by_emp_date_site[$key]['night'] = $sess;
        }
        // Accumulate totals
        $grouped_by_emp_date_site[$key]['total_hours'] += $sess['regular_hours'] + $sess['overtime_hours'];
        $grouped_by_emp_date_site[$key]['total_payroll'] += $sess['regular_pay'] + $sess['overtime_pay'];
        if (isset($sess['guaranteed_pay'])) {
            $grouped_by_emp_date_site[$key]['guaranteed_pay'] += $sess['guaranteed_pay'];
        }
        if ($sess['type'] == 'am' || $sess['type'] == 'pm') {
            $grouped_by_emp_date_site[$key]['regular_hours'] += $sess['regular_hours'];
            $grouped_by_emp_date_site[$key]['overtime_hours'] += $sess['overtime_hours'];
        } elseif ($sess['type'] == 'night') {
            $grouped_by_emp_date_site[$key]['regular_night_hours'] += $sess['regular_hours'];
            $grouped_by_emp_date_site[$key]['night_overtime_hours'] += $sess['overtime_hours'];
        }
    }
    
    // Create rows for this site
    foreach ($grouped_by_emp_date_site as $key => $row_data) {
        $emp_key = $row_data['employee_id'];
        if (!isset($employee_totals[$emp_key])) {
            $employee_totals[$emp_key] = [
                'name' => trim($row_data['first_name'] . ' ' . ($row_data['middle_name'] ?? '') . ' ' . $row_data['last_name']),
                'position' => $row_data['position'] ?? 'N/A',
                'total_hours' => 0,
                'total_payroll' => 0,
                'regular_hours' => 0,
                'overtime_hours' => 0,
                'regular_night_hours' => 0,
                'night_overtime_hours' => 0,
                'guaranteed_pay' => 0
            ];
        }
        
        // Determine overall status
        $has_present = false;
        $has_assignment = false;
        if ($row_data['am'] && $row_data['am']['status'] == 'Present') $has_present = true;
        if ($row_data['pm'] && $row_data['pm']['status'] == 'Present') $has_present = true;
        if ($row_data['night'] && $row_data['night']['status'] == 'Present') $has_present = true;
        if ($row_data['am'] || $row_data['pm'] || $row_data['night']) $has_assignment = true;
        
        if (!empty($row_data['leave_type']) && $row_data['leave_type'] != 'None') {
            $overall_status = 'On Leave';
            $site_leave_count++;
        } elseif ($has_present) {
            $overall_status = 'Present';
            $site_present_count++;
        } elseif ($has_assignment) {
            $overall_status = 'Absent';
            $site_absent_count++;
        } else {
            $overall_status = 'No Record';
            $site_absent_count++;
        }
        
        // Format times
        $time_in_am = $row_data['am'] ? $formatTime($row_data['am']['time_in']) : '--:--';
        $time_out_am = $row_data['am'] ? $formatTime($row_data['am']['time_out']) : '--:--';
        $time_in_pm = $row_data['pm'] ? $formatTime($row_data['pm']['time_in']) : '--:--';
        $time_out_pm = $row_data['pm'] ? $formatTime($row_data['pm']['time_out']) : '--:--';
        $time_in_night = $row_data['night'] ? $formatTime($row_data['night']['time_in']) : '--:--';
        $time_out_night = $row_data['night'] ? $formatTime($row_data['night']['time_out']) : '--:--';
        $status_am = $row_data['am'] ? ($row_data['am']['status'] ?? 'Present') : '--';
        $status_pm = $row_data['pm'] ? ($row_data['pm']['status'] ?? 'Present') : '--';
        $status_night = $row_data['night'] ? ($row_data['night']['status'] ?? 'Present') : '--';
        
        $total_hours = $row_data['total_hours'];
        $total_work_payroll = $row_data['total_payroll'];
        $guaranteed_pay = $row_data['guaranteed_pay'];
        $total_payroll_amount = $total_work_payroll + $guaranteed_pay;
        
        // Build payroll breakdown display
        if ($guaranteed_pay > 0 && $total_work_payroll > 0) {
            $payroll_breakdown = '₱' . number_format($total_work_payroll, 2) . ' (Work) + ₱' . number_format($guaranteed_pay, 2) . ' (Guaranteed)';
        } elseif ($guaranteed_pay > 0) {
            $payroll_breakdown = '₱' . number_format($guaranteed_pay, 2) . ' (Guaranteed Pay - No Work)';
        } else {
            $payroll_breakdown = '';
        }
        
        $site_rows[] = [
            'employee_id' => $row_data['employee_id'],
            'first_name' => $row_data['first_name'],
            'middle_name' => $row_data['middle_name'],
            'last_name' => $row_data['last_name'],
            'date' => $row_data['date'],
            'time_in_am' => $time_in_am,
            'time_out_am' => $time_out_am,
            'time_in_pm' => $time_in_pm,
            'time_out_pm' => $time_out_pm,
            'time_in_night' => $time_in_night,
            'time_out_night' => $time_out_night,
            'status_am' => $status_am,
            'status_pm' => $status_pm,
            'status_night' => $status_night,
            'overall_status' => $overall_status,
            'leave_type' => $row_data['leave_type'],
            'workday_type' => $row_data['workday_type'],
            'remarks' => $row_data['remarks'],
            'hours_worked' => number_format($total_hours, 2),
            'payroll_amount' => '₱' . number_format($total_payroll_amount, 2),
            'payroll_breakdown' => $payroll_breakdown
        ];
        
        // Update site totals
        $site_total_hours += $total_hours;
        $site_total_payroll += $total_payroll_amount;
        $site_guaranteed_pay += $guaranteed_pay;
        $site_regular_hours += $row_data['regular_hours'];
        $site_overtime_hours += $row_data['overtime_hours'];
        $site_regular_night_hours += $row_data['regular_night_hours'];
        $site_night_overtime_hours += $row_data['night_overtime_hours'];
        
        // Update employee totals
        $employee_totals[$emp_key]['total_hours'] += $total_hours;
        $employee_totals[$emp_key]['total_payroll'] += $total_payroll_amount;
        $employee_totals[$emp_key]['regular_hours'] += $row_data['regular_hours'];
        $employee_totals[$emp_key]['overtime_hours'] += $row_data['overtime_hours'];
        $employee_totals[$emp_key]['regular_night_hours'] += $row_data['regular_night_hours'];
        $employee_totals[$emp_key]['night_overtime_hours'] += $row_data['night_overtime_hours'];
        $employee_totals[$emp_key]['guaranteed_pay'] += $guaranteed_pay;
    }
    
    $site_data[] = [
        'site_name' => $site_name,
        'rows' => $site_rows,
        'present_count' => $site_present_count,
        'absent_count' => $site_absent_count,
        'leave_count' => $site_leave_count,
        'record_count' => count($site_rows),
        'total_hours' => $site_total_hours,
        'total_payroll' => $site_total_payroll,
        'guaranteed_pay' => $site_guaranteed_pay,
        'regular_hours' => $site_regular_hours,
        'overtime_hours' => $site_overtime_hours,
        'regular_night_hours' => $site_regular_night_hours,
        'night_overtime_hours' => $site_night_overtime_hours,
        'employee_totals' => $employee_totals
    ];
    
    $grand_total_records += count($site_rows);
    $grand_total_hours += $site_total_hours;
    $grand_total_payroll += $site_total_payroll;
    $grand_regular_hours += $site_regular_hours;
    $grand_overtime_hours += $site_overtime_hours;
    $grand_regular_night_hours += $site_regular_night_hours;
    $grand_night_overtime_hours += $site_night_overtime_hours;
    $grand_total_guaranteed_pay += $site_guaranteed_pay;
}

$stmt->close();

// Set headers for Excel download
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="Site_Payroll_Report_' . $date_from . '_to_' . $date_to . '.xls"');
header('Cache-Control: max-age=0');

// Generate Excel file with grouped by site and payroll (the rest of the HTML/CSS output remains exactly as original)
?>
<html>
<head>
<meta charset="UTF-8">
<title>Site Payroll Report - <?= $date_from ?> to <?= $date_to ?></title>
<style>
    /* All original CSS styles remain unchanged – copied from your original file */
    body {
        font-family: 'Arial', sans-serif;
        margin: 30px;
        color: #333;
    }
    
    .header {
        text-align: center;
        margin-bottom: 30px;
        padding-bottom: 20px;
        border-bottom: 3px solid #2E7D32;
    }
    
    .header h1 {
        color: #2E7D32;
        font-size: 28px;
        margin: 0 0 10px 0;
    }
    
    .header h2 {
        color: #1B5E20;
        font-size: 20px;
        margin: 5px 0;
        font-weight: normal;
    }
    
    .header p {
        color: #666;
        font-size: 14px;
        margin: 5px 0;
    }
    
    .info-section {
        background: #f5f5f5;
        padding: 20px;
        margin-bottom: 30px;
        border-left: 6px solid #2E7D32;
        border-radius: 8px;
    }
    
    .info-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 30px;
    }
    
    .info-item {
        flex: 1;
        min-width: 150px;
    }
    
    .info-label {
        font-size: 12px;
        color: #666;
        margin-bottom: 5px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .info-value {
        font-size: 16px;
        font-weight: 700;
        color: #2E7D32;
    }
    
    .site-section {
        margin-top: 40px;
        margin-bottom: 40px;
        page-break-inside: avoid;
    }
    
    .site-title {
        background: linear-gradient(135deg, #2E7D32, #1B5E20);
        color: black;
        padding: 12px 20px;
        border-radius: 8px 8px 0 0;
        font-size: 16px;
        font-weight: 700;
        margin-bottom: 0;
    }
    
    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 0;
        font-size: 11px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    
    th {
        background: #2E7D32;
        color: white;
        font-weight: 600;
        padding: 10px 5px;
        text-align: center;
        border: 1px solid #1B5E20;
        font-size: 12px;
    }
    
    td {
        padding: 8px 5px;
        border: 1px solid #ddd;
        text-align: center;
        vertical-align: middle;
    }
    
    tr:nth-child(even) {
        background-color: #f9f9f9;
    }
    
    tr:hover {
        background-color: #f1f8e9;
    }
    
    .status-present {
        color: #28a745;
        font-weight: 700;
        background-color: #e8f5e9;
        padding: 3px 8px;
        border-radius: 20px;
        display: inline-block;
        font-size: 10px;
    }
    
    .status-absent {
        color: #dc3545;
        font-weight: 700;
        background-color: #fef5f5;
        padding: 3px 8px;
        border-radius: 20px;
        display: inline-block;
        font-size: 10px;
    }
    
    .status-leave {
        color: #856404;
        font-weight: 700;
        background-color: #fff9e6;
        padding: 3px 8px;
        border-radius: 20px;
        display: inline-block;
        font-size: 10px;
    }
    
    .status-no-record {
        color: #6c757d;
        font-weight: 700;
        background-color: #f0f0f0;
        padding: 3px 8px;
        border-radius: 20px;
        display: inline-block;
        font-size: 10px;
    }
    
    .time-display {
        font-family: 'Courier New', monospace;
        font-size: 10px;
    }
    
    .payroll-amount {
        color: #00838f;
        font-weight: 700;
        font-family: monospace;
    }
    
    .payroll-breakdown {
        font-size: 9px;
        color: #666;
        display: block;
        margin-top: 2px;
    }
    
    .leave-type-badge {
        display: inline-block;
        padding: 2px 6px;
        border-radius: 12px;
        font-size: 9px;
        font-weight: 600;
    }
    
    .leave-type-badge.sick {
        background-color: #f8d7da;
        color: #721c24;
    }
    
    .leave-type-badge.vacation {
        background-color: #d4edda;
        color: #155724;
    }
    
    .leave-type-badge.emergency {
        background-color: #fff3cd;
        color: #856404;
    }
    
    .site-summary-container {
        margin-top: 20px;
        padding: 20px;
        background: #f8f9fa;
        border-radius: 8px;
        border: none;
    }
    
    .summary-title {
        font-size: 14px;
        font-weight: 700;
        color: #2E7D32;
        margin-bottom: 15px;
        padding-bottom: 8px;
        border-bottom: 2px solid #2E7D32;
    }
    
    .summary-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 30px;
    }
    
    .summary-box {
        flex: 1;
        min-width: 250px;
    }
    
    .summary-box h5 {
        color: #2E7D32;
        margin-bottom: 10px;
        font-size: 12px;
        font-weight: 700;
    }
    
    .summary-table {
        width: 100%;
        border: none;
        margin: 0;
        box-shadow: none;
    }
    
    .summary-table td {
        border: none;
        padding: 5px 0;
        text-align: left;
        background: transparent;
    }
    
    .summary-table td:first-child {
        font-weight: 600;
        color: #555;
        width: 60%;
    }
    
    .summary-table td:last-child {
        font-weight: 700;
        color: #2c3e50;
    }
    
    .summary-table .payroll-value {
        color: #00838f;
    }
    
    .breakdown-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 5px;
    }
    
    .breakdown-table td {
        border: none;
        padding: 4px 0;
        font-size: 10px;
    }
    
    .breakdown-table td:first-child {
        color: #666;
    }
    
    .breakdown-table td:last-child {
        font-weight: 600;
        color: #2E7D32;
    }
    
    .footer {
        margin-top: 30px;
        padding-top: 15px;
        border-top: 2px solid #2E7D32;
        font-size: 11px;
        color: #666;
        text-align: center;
    }
    
    .footer p {
        margin: 3px 0;
    }
    
    .text-center { text-align: center; }
    .text-left { text-align: left; }
    .text-right { text-align: right; }
    
    .no-data {
        text-align: center;
        padding: 30px;
        color: #666;
        background: #f9f9f9;
        border: 1px solid #ddd;
    }
</style>
</head>
<body>

<!-- Header -->
<div class="header">
    <h1>SITE PAYROLL REPORT</h1>
    <h2><?= date('F d, Y', strtotime($date_from)) ?> to <?= date('F d, Y', strtotime($date_to)) ?></h2>
    <p>Generated on: <?= date('F j, Y \a\t h:i A') ?></p>
</div>

<!-- Employee Information -->
<div class="info-section">
    <div class="info-grid">
        <div class="info-item">
            <div class="info-label">Employee</div>
            <div class="info-value"><?= htmlspecialchars($employee_name) ?></div>
        </div>
        <div class="info-item">
            <div class="info-label">Report Period</div>
            <div class="info-value"><?= date('M d, Y', strtotime($date_from)) ?> - <?= date('M d, Y', strtotime($date_to)) ?></div>
        </div>
        <div class="info-item">
            <div class="info-label">Total Sites</div>
            <div class="info-value"><?= count($sites) ?></div>
        </div>
        <div class="info-item">
            <div class="info-label">Total Records</div>
            <div class="info-value"><?= $grand_total_records ?></div>
        </div>
        <div class="info-item">
            <div class="info-label">Total Payroll</div>
            <div class="info-value">₱<?= number_format($grand_total_payroll, 2) ?></div>
        </div>
        <div class="info-item">
            <div class="info-label">Total Guaranteed Pay</div>
            <div class="info-value">₱<?= number_format($grand_total_guaranteed_pay, 2) ?></div>
        </div>
    </div>
</div>

<?php if (empty($site_data)): ?>
    <div class="no-data">
        <p>No attendance records found for the selected period.</p>
    </div>
<?php else: ?>
    <?php foreach ($site_data as $site): ?>
        <?php if (empty($site['rows'])) continue; ?>
        
        <div class="site-section">
            <div class="site-title">
                <i class="fas fa-building"></i> SITE: <?= htmlspecialchars($site['site_name']) ?>
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Employee</th>
                        <th>Date</th>
                        <th>Day</th>
                        <th colspan="2">Morning</th>
                        <th colspan="2">Afternoon</th>
                        <th colspan="2">Night</th>
                        <th>Status</th>
                        <th>Leave Type</th>
                        <th>Workday</th>
                        <th>Hours</th>
                        <th>Payroll</th>
                        <th>Remarks</th>
                    </tr>
                    <tr>
                        <th colspan="4"></th>
                        <th>In</th>
                        <th>Out</th>
                        <th>In</th>
                        <th>Out</th>
                        <th>In</th>
                        <th>Out</th>
                        <th colspan="6"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($site['rows'] as $row): 
                        $full_name = trim($row['first_name'] . ' ' . ($row['middle_name'] ?? '') . ' ' . $row['last_name']);
                        $day_name = date('D', strtotime($row['date']));
                        
                        $status_class = '';
                        $status_display = $row['overall_status'];
                        
                        if ($status_display == 'Present') $status_class = 'status-present';
                        elseif ($status_display == 'Absent') $status_class = 'status-absent';
                        elseif ($status_display == 'On Leave') $status_class = 'status-leave';
                        else $status_class = 'status-no-record';
                        
                        $leave_type_class = '';
                        if (!empty($row['leave_type']) && $row['leave_type'] != 'None') {
                            if (strpos(strtolower($row['leave_type']), 'sick') !== false) $leave_type_class = 'sick';
                            elseif (strpos(strtolower($row['leave_type']), 'vacation') !== false) $leave_type_class = 'vacation';
                            elseif (strpos(strtolower($row['leave_type']), 'emergency') !== false) $leave_type_class = 'emergency';
                        }
                    ?>
                        <tr>
                            <td class="text-center"><?= $row['employee_id'] ?></td>
                            <td class="text-left"><?= htmlspecialchars($full_name) ?></td>
                            <td class="text-center"><?= date('M d, Y', strtotime($row['date'])) ?></td>
                            <td class="text-center"><?= $day_name ?></td>
                            <td class="text-center time-display"><?= $row['time_in_am'] ?></td>
                            <td class="text-center time-display"><?= $row['time_out_am'] ?></td>
                            <td class="text-center time-display"><?= $row['time_in_pm'] ?></td>
                            <td class="text-center time-display"><?= $row['time_out_pm'] ?></td>
                            <td class="text-center time-display"><?= $row['time_in_night'] ?></td>
                            <td class="text-center time-display"><?= $row['time_out_night'] ?></td>
                            <td class="text-center"><span class="<?= $status_class ?>"><?= $status_display ?></span></td>
                            <td class="text-center">
                                <?php if (!empty($row['leave_type']) && $row['leave_type'] != 'None'): ?>
                                    <span class="leave-type-badge <?= $leave_type_class ?>"><?= htmlspecialchars($row['leave_type']) ?></span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td class="text-center"><?= htmlspecialchars($row['workday_type'] ?? '-') ?></td>
                            <td class="text-center"><strong><?= $row['hours_worked'] ?></strong></td>
                            <td class="text-center payroll-amount">
                                <?= $row['payroll_amount'] ?>
                                <?php if (!empty($row['payroll_breakdown'])): ?>
                                    <div class="payroll-breakdown"><?= $row['payroll_breakdown'] ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-left"><?= htmlspecialchars($row['remarks'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <!-- Site Summary -->
            <div class="site-summary-container">
                <div class="summary-title">
                    <i class="fas fa-chart-pie"></i> SITE SUMMARY - <?= htmlspecialchars($site['site_name']) ?>
                </div>
                <div class="summary-grid">
                    <div class="summary-box">
                        <h5><i class="fas fa-users"></i> EMPLOYEE TOTALS</h5>
                        <table class="summary-table">
                            <?php foreach ($site['employee_totals'] as $emp_id => $emp): ?>
                            <tr>
                                <td><?= htmlspecialchars($emp['name']) ?></td>
                                <td><?= number_format($emp['total_hours'], 2) ?> hrs | <span class="payroll-value">₱<?= number_format($emp['total_payroll'], 2) ?></span>
                                <?php if ($emp['guaranteed_pay'] > 0): ?>
                                    <br><small style="color:#666;">(includes ₱<?= number_format($emp['guaranteed_pay'], 2) ?> guaranteed)</small>
                                <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                    
                    <div class="summary-box">
                        <h5><i class="fas fa-chart-line"></i> SITE TOTALS</h5>
                        <table class="summary-table">
                            <tr><td class="payroll-value"><strong>Total Employees:</strong></td><td class="payroll-value"><strong><?= count($site['employee_totals']) ?></strong></div></tr>
                            <tr><td class="payroll-value"><strong>Total Hours:</strong></td><td class="payroll-value"><strong><?= number_format($site['total_hours'], 2) ?> hrs</strong></div></tr>
                            <tr><td class="payroll-value"><strong>Total Payroll:</strong></td><td class="payroll-value"><strong>₱<?= number_format($site['total_payroll'], 2) ?></strong></div></tr>
                            <?php if ($site['guaranteed_pay'] > 0): ?>
                            <tr><td class="payroll-value"><strong>Guaranteed Pay Applied:</strong></td><td class="payroll-value"><strong>₱<?= number_format($site['guaranteed_pay'], 2) ?></strong></div></tr>
                            <?php endif; ?>
                            <tr><td class="payroll-value"><strong>Present Days:</strong></td><td class="payroll-value"><strong><?= $site['present_count'] ?></strong></div></tr>
                            <tr><td class="payroll-value"><strong>Absent Days:</strong></td><td class="payroll-value"><strong><?= $site['absent_count'] ?></strong></div></tr>
                            <tr><td class="payroll-value"><strong>On Leave:</strong></td><td class="payroll-value"><strong><?= $site['leave_count'] ?></strong></div></tr>
                        </table>
                    </div>
                    
                    <div class="summary-box">
                        <h5><i class="fas fa-clock"></i> HOURS BREAKDOWN</h5>
                        <table class="breakdown-table">
                            <tr><td class="payroll-value"><strong>Regular Day Hours:</strong></td><td class="payroll-value"><strong><?= number_format($site['regular_hours'], 2) ?> hrs</strong></div></tr>
                            <tr><td class="payroll-value"><strong>Day Overtime Hours:</strong></td><td class="payroll-value"><strong><?= number_format($site['overtime_hours'], 2) ?> hrs</strong></div></tr>
                            <tr><td class="payroll-value"><strong>Regular Night Hours:</strong></td><td class="payroll-value"><strong><?= number_format($site['regular_night_hours'], 2) ?> hrs</strong></div></tr>
                            <tr><td class="payroll-value"><strong>Night Overtime Hours:</strong></td><td class="payroll-value"><strong><?= number_format($site['night_overtime_hours'], 2) ?> hrs</strong></div></tr>
                            <tr style="border-top: 1px solid #ddd;"><td><strong>Total Hours:</strong></td><td class="payroll-value"><strong><?= number_format($site['total_hours'], 2) ?> hrs</strong></div></tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    
    <!-- GRAND TOTAL SECTION -->
    <div class="site-summary-container" style="margin-top: 30px; background: linear-gradient(135deg, #e8f5e9, #c8e6c9);">
        <div class="summary-title" style="color: #1B5E20; border-bottom-color: #1B5E20;">
            <i class="fas fa-chart-bar"></i> GRAND TOTALS (All Sites)
        </div>
        <div class="summary-grid">
            <div class="summary-box">
                <h5><i class="fas fa-globe"></i> OVERALL STATISTICS</h5>
                <table class="summary-table">
                    <tr><td class="payroll-value"><strong>Total Sites:</strong></td><td class="payroll-value"><strong><?= count($site_data) ?></strong></div></tr>
                    <tr><td class="payroll-value"><strong>Total Records:</strong></td><td class="payroll-value"><strong><?= $grand_total_records ?></strong></div></tr>
                    <tr><td class="payroll-value"><strong>Total Hours Worked:</strong></td><td class="payroll-value"><strong><?= number_format($grand_total_hours, 2) ?> hrs</strong></div><tr>
                    <tr><td class="payroll-value"><strong>Total Payroll:</strong></td><td class="payroll-value"><strong>₱<?= number_format($grand_total_payroll, 2) ?></strong></div></tr>
                    <tr><td class="payroll-value"><strong>Total Guaranteed Pay:</strong></td><td class="payroll-value"><strong>₱<?= number_format($grand_total_guaranteed_pay, 2) ?></strong></div></tr>
                </table>
            </div>
            <div class="summary-box">
                <h5><i class="fas fa-chart-pie"></i> GRAND HOURS BREAKDOWN</h5>
                <table class="breakdown-table">
                    <tr><td class="payroll-value"><strong>Regular Day Hours:</strong></td><td class="payroll-value"><strong><?= number_format($grand_regular_hours, 2) ?> hrs</strong></div></tr>
                    <tr><td class="payroll-value"><strong>Day Overtime Hours:</strong></td><td class="payroll-value"><strong><?= number_format($grand_overtime_hours, 2) ?> hrs</strong></div></tr>
                    <tr><td class="payroll-value"><strong>Regular Night Hours:</strong></td><td class="payroll-value"><strong><?= number_format($grand_regular_night_hours, 2) ?> hrs</strong></div></tr>
                    <tr><td class="payroll-value"><strong>Night Overtime Hours:</strong></td><td class="payroll-value"><strong><?= number_format($grand_night_overtime_hours, 2) ?> hrs</strong></div></tr>
                    <tr style="border-top: 1px solid #ddd;"><td><strong>Total Hours:</strong></td><td class="payroll-value"><strong><?= number_format($grand_total_hours, 2) ?> hrs</strong></div></tr>
                </table>
            </div>
        </div>
    </div>
    
<?php endif; ?>

<!-- Footer -->
<div class="footer">
    <p>Payroll Management System - Official Site Payroll Report</p>
    <p>Generated on <?= date('F j, Y \a\t h:i A') ?></p>
</div>

</body>
</html>
<?php

$conn->close();
?>