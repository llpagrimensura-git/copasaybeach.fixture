<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();

/* =========================
   CONFIG
   ========================= */
$TORNEO = "MEETX";
$EQUIPOS_FILE = __DIR__ . "/equipos.json";
$RESULTADOS_FILE = __DIR__ . "/resultados.json";
$USUARIOS_FILE = __DIR__ . "/usuarios.json";
$FIXTURE_CONFIG_FILE = __DIR__ . "/fixture_config.json";
$DEPORTE_CONFIG_FILE = __DIR__ . "/deporte_config.json";

/* =========================
   HELPERS
   ========================= */
function read_json($path, $default) {
    if (!file_exists($path)) return $default;
    $raw = file_get_contents($path);
    $data = json_decode($raw, true);
    return (json_last_error() === JSON_ERROR_NONE && is_array($data)) ? $data : $default;
}

function write_json($path, $data) {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    file_put_contents($path, $json);
}

function team_name($teams, $id) {
    foreach ($teams as $t) if ((int)$t['id'] === (int)$id) return $t['nombre'];
    return "Equipo #$id";
}

function is_admin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

function require_admin() {
    if (!is_admin()) {
        header("Location: index.php?error=no_admin");
        exit;
    }
}

/* =========================
   DEPORTE FUNCTIONS
   ========================= */
function get_deporte_config() {
    global $DEPORTE_CONFIG_FILE;
    $config = read_json($DEPORTE_CONFIG_FILE, ['deporte' => 'futbol']);
    return $config;
}

function set_deporte_config($deporte) {
    global $DEPORTE_CONFIG_FILE;
    $config = ['deporte' => $deporte, 'updated_at' => date('Y-m-d H:i:s')];
    write_json($DEPORTE_CONFIG_FILE, $config);
}

function is_futbol() {
    $config = get_deporte_config();
    return $config['deporte'] === 'futbol';
}

function is_voley() {
    $config = get_deporte_config();
    return $config['deporte'] === 'voley';
}

function is_handball() {
    $config = get_deporte_config();
    return $config['deporte'] === 'handball';
}

/* =========================
   CÁLCULO DE PUNTOS POR DEPORTE - CÓDIGO SEPARADO
   ========================= */

/**
 * FÚTBOL - Reglas actuales (NO MODIFICAR)
 * - Ganador: 3 puntos
 * - Empate: 1 punto cada uno
 * - Walkover: 3 puntos ganador, 0 perdedor
 */
function calcular_puntos_futbol($match) {
    $resultado = [
        'home_points' => 0,
        'away_points' => 0,
        'home_gf' => 0,
        'home_gc' => 0,
        'away_gf' => 0,
        'away_gc' => 0
    ];

    if (!$match['played']) {
        return $resultado;
    }

    // Si hay walkover
    if (!empty($match['walkover_winner'])) {
        $winner_id = (int)$match['walkover_winner'];
        $home_id = (int)$match['home_id'];
        
        if ($winner_id === $home_id) {
            $resultado['home_points'] = 3;
            $resultado['home_gf'] = 3;
            $resultado['away_gc'] = 3;
        } else {
            $resultado['away_points'] = 3;
            $resultado['away_gf'] = 3;
            $resultado['home_gc'] = 3;
        }
        return $resultado;
    }

    // Partido jugado normalmente
    $home_goals = (int)$match['home_goals'];
    $away_goals = (int)$match['away_goals'];

    $resultado['home_gf'] = $home_goals;
    $resultado['home_gc'] = $away_goals;
    $resultado['away_gf'] = $away_goals;
    $resultado['away_gc'] = $home_goals;

    if ($home_goals > $away_goals) {
        $resultado['home_points'] = 3;
    } elseif ($away_goals > $home_goals) {
        $resultado['away_points'] = 3;
    } else {
        $resultado['home_points'] = 1;
        $resultado['away_points'] = 1;
    }

    return $resultado;
}

/**
 * HANDBALL - Nuevas reglas (igual que vóley)
 * - Ganador: 2 puntos
 * - Walkover: 2 puntos ganador, 0 perdedor
 * - Se juega por sets (2 sets, si empatan hay un 3ero)
 * - Los puntos de los sets se suman para la diferencia de goles
 */
function calcular_puntos_handball($match) {
    $resultado = [
        'home_points' => 0,
        'away_points' => 0,
        'home_gf' => 0,  // Puntos totales a favor (suma de puntos de sets)
        'home_gc' => 0,  // Puntos totales en contra
        'away_gf' => 0,
        'away_gc' => 0
    ];

    if (!$match['played']) {
        return $resultado;
    }

    // Si hay walkover
    if (!empty($match['walkover_winner'])) {
        $winner_id = (int)$match['walkover_winner'];
        $home_id = (int)$match['home_id'];
        
        if ($winner_id === $home_id) {
            $resultado['home_points'] = 2;
            // No se suman goles en walkover de handball
            $resultado['home_gf'] = 0;
            $resultado['away_gc'] = 0;
        } else {
            $resultado['away_points'] = 2;
            // No se suman goles en walkover de handball
            $resultado['away_gf'] = 0;
            $resultado['home_gc'] = 0;
        }
        return $resultado;
    }

    // Partido jugado normalmente con sets
    $home_sets = (int)($match['home_sets_won'] ?? 0);
    $away_sets = (int)($match['away_sets_won'] ?? 0);
    
    // Puntos totales de sets (suma de los puntos de cada set)
    $home_total_points = (int)($match['home_points'] ?? 0);
    $away_total_points = (int)($match['away_points'] ?? 0);

    $resultado['home_gf'] = $home_total_points;
    $resultado['home_gc'] = $away_total_points;
    $resultado['away_gf'] = $away_total_points;
    $resultado['away_gc'] = $home_total_points;

    // El que gana más sets gana el partido
    if ($home_sets > $away_sets) {
        $resultado['home_points'] = 2;
    } else {
        $resultado['away_points'] = 2;
    }

    return $resultado;
}

/**
 * VÓLEY - Nuevas reglas
 * - Ganador: 2 puntos
 * - Walkover: 2 puntos ganador, 0 perdedor
 * - Contar puntos de sets como "diferencia de goles"
 *   (puntos a favor = puntos totales ganados en todos los sets)
 */
function calcular_puntos_voley($match) {
    $resultado = [
        'home_points' => 0,
        'away_points' => 0,
        'home_gf' => 0,  // Puntos totales a favor (suma de puntos de sets)
        'home_gc' => 0,  // Puntos totales en contra
        'away_gf' => 0,
        'away_gc' => 0
    ];

    if (!$match['played']) {
        return $resultado;
    }

    // Si hay walkover
    if (!empty($match['walkover_winner'])) {
        $winner_id = (int)$match['walkover_winner'];
        $home_id = (int)$match['home_id'];
        
        if ($winner_id === $home_id) {
            $resultado['home_points'] = 2;
            $resultado['home_gf'] = 42;   // 2 sets x 21 puntos
            $resultado['away_gc'] = 42;
        } else {
            $resultado['away_points'] = 2;
            $resultado['away_gf'] = 42;
            $resultado['home_gc'] = 42;
        }
        return $resultado;
    }

    // Partido jugado normalmente
    $home_sets = (int)($match['home_sets_won'] ?? 0);
    $away_sets = (int)($match['away_sets_won'] ?? 0);
    
    // Puntos totales de sets (usamos los campos home_points y away_points del match)
    $home_total_points = (int)($match['home_points'] ?? 0);
    $away_total_points = (int)($match['away_points'] ?? 0);

    // Los puntos de sets se usan como "goles"
    $resultado['home_gf'] = $home_total_points;
    $resultado['home_gc'] = $away_total_points;
    $resultado['away_gf'] = $away_total_points;
    $resultado['away_gc'] = $home_total_points;

    // Asignar 2 puntos al ganador (quien ganó más sets)
    if ($home_sets > $away_sets) {
        $resultado['home_points'] = 2;
    } else {
        $resultado['away_points'] = 2;
    }

    return $resultado;
}

/**
 * Función principal que llama al cálculo según el deporte
 */
function calcular_puntos_partido($match) {
    $deporte = $match['deporte'] ?? 'futbol';
    
    switch ($deporte) {
        case 'futbol':
            return calcular_puntos_futbol($match);
        case 'handball':
            return calcular_puntos_handball($match);
        case 'voley':
            return calcular_puntos_voley($match);
        default:
            return calcular_puntos_futbol($match);
    }
}

/* =========================
   CALCULAR TABLA CON NUEVO SISTEMA DE PUNTOS
   ========================= */
function calculate_standings($teams, $matches, $deporte = 'futbol') {
    $standings = [];
    
    foreach ($teams as $t) {
        $id = (int)$t['id'];
        $standings[$id] = [
            'id' => $id,
            'nombre' => $t['nombre'],
            'pj' => 0,
            'pg' => 0,
            'pe' => 0,
            'pp' => 0,
            'pts' => 0,
            'gf' => 0,
            'gc' => 0,
            'dg' => 0,
            'ta' => 0,
            'tr' => 0,
            'wo' => 0,
            'wo_lost' => 0,
            'sets_won' => 0,
            'sets_lost' => 0,
            'points_won' => 0,
            'points_lost' => 0
        ];
    }
    
    foreach ($matches as $m) {
        if (!$m['played']) continue;
        
        $hid = (int)$m['home_id'];
        $aid = (int)$m['away_id'];
        
        if (!isset($standings[$hid]) || !isset($standings[$aid])) continue;
        
        // *** USAR LA NUEVA FUNCIÓN SEPARADA POR DEPORTE ***
        $puntos = calcular_puntos_partido($m);
        
        // Actualizar partidos jugados
        $standings[$hid]['pj']++;
        $standings[$aid]['pj']++;
        
        // Actualizar puntos y goles/sets
        $standings[$hid]['pts'] += $puntos['home_points'];
        $standings[$aid]['pts'] += $puntos['away_points'];
        $standings[$hid]['gf'] += $puntos['home_gf'];
        $standings[$hid]['gc'] += $puntos['home_gc'];
        $standings[$aid]['gf'] += $puntos['away_gf'];
        $standings[$aid]['gc'] += $puntos['away_gc'];
        
        // Para vóley y handball, también guardar sets y puntos totales
        if ($deporte === 'voley' || $deporte === 'handball') {
            $hs = isset($m['home_sets_won']) ? (int)$m['home_sets_won'] : 0;
            $as = isset($m['away_sets_won']) ? (int)$m['away_sets_won'] : 0;
            
            $standings[$hid]['sets_won'] += $hs;
            $standings[$hid]['sets_lost'] += $as;
            $standings[$aid]['sets_won'] += $as;
            $standings[$aid]['sets_lost'] += $hs;
            
            $standings[$hid]['points_won'] = $standings[$hid]['gf'];
            $standings[$hid]['points_lost'] = $standings[$hid]['gc'];
            $standings[$aid]['points_won'] = $standings[$aid]['gf'];
            $standings[$aid]['points_lost'] = $standings[$aid]['gc'];
        }
        
        // Actualizar PG, PE, PP
        if ($puntos['home_points'] > $puntos['away_points']) {
            $standings[$hid]['pg']++;
            $standings[$aid]['pp']++;
        } elseif ($puntos['away_points'] > $puntos['home_points']) {
            $standings[$aid]['pg']++;
            $standings[$hid]['pp']++;
        } else {
            $standings[$hid]['pe']++;
            $standings[$aid]['pe']++;
        }
        
        // Walkover
        if (!empty($m['walkover_winner'])) {
            $winner_id = (int)$m['walkover_winner'];
            $home_id = (int)$m['home_id'];
            
            if ($winner_id === $home_id) {
                $standings[$hid]['wo']++;
                $standings[$aid]['wo_lost']++;
            } else {
                $standings[$aid]['wo']++;
                $standings[$hid]['wo_lost']++;
            }
        }
        
        // Tarjetas (solo para fútbol)
        if ($deporte === 'futbol') {
            $standings[$hid]['ta'] += isset($m['home_yellow']) ? (int)$m['home_yellow'] : 0;
            $standings[$hid]['tr'] += isset($m['home_red']) ? (int)$m['home_red'] : 0;
            $standings[$aid]['ta'] += isset($m['away_yellow']) ? (int)$m['away_yellow'] : 0;
            $standings[$aid]['tr'] += isset($m['away_red']) ? (int)$m['away_red'] : 0;
        }
    }
    
    // Calcular diferencias
    foreach ($standings as $id => $s) {
        $standings[$id]['dg'] = $s['gf'] - $s['gc'];
    }
    
    // Ordenar
    usort($standings, function($a, $b) use ($deporte) {
        if ($b['pts'] !== $a['pts']) return $b['pts'] - $a['pts'];
        if ($b['dg'] !== $a['dg']) return $b['dg'] - $a['dg'];
        if ($deporte === 'voley' || $deporte === 'handball') {
            if ($b['sets_won'] !== $a['sets_won']) return $b['sets_won'] - $a['sets_won'];
        }
        return $b['gf'] - $a['gf'];
    });
    
    return $standings;
}

/* =========================
   FIXTURE - Round Robin (Circle method)
   ========================= */
function generate_round_robin($teams, $type = 'todos_contra_todos', $groups = 1, $deporte = 'futbol') {
    $n = count($teams);
    if ($n < 2) return [];
    
    // Guardar configuración del fixture
    $config = [
        'type' => $type,
        'groups' => $groups,
        'teams_count' => $n,
        'generated_at' => date('Y-m-d H:i:s'),
        'deporte' => $deporte
    ];
    global $FIXTURE_CONFIG_FILE;
    write_json($FIXTURE_CONFIG_FILE, $config);
    
    if ($type === 'grupos' && $groups > 1) {
        return generate_groups_fixture($teams, $groups, $deporte);
    }
    
    // Todos contra todos
    $ids = array_map(fn($t) => (int)$t['id'], $teams);
    
    if ($n % 2 === 1) {
        $ids[] = 0;
        $n++;
    }
    
    $rounds = $n - 1;
    $half = (int)($n / 2);
    
    $list = $ids;
    $matches = [];
    $matchId = 1;
    
    for ($r = 1; $r <= $rounds; $r++) {
        for ($i = 0; $i < $half; $i++) {
            $home = $list[$i];
            $away = $list[$n - 1 - $i];
            
            if ($home === 0 || $away === 0) continue;
            
            if ($r % 2 === 0) {
                [$home, $away] = [$away, $home];
            }
            
            $match = [
                "id" => $matchId++,
                "round" => $r,
                "home_id" => $home,
                "away_id" => $away,
                "played" => false,
                "notes" => "",
                "status" => "pendiente",
                "walkover_winner" => null,
                "deporte" => $deporte
            ];
            
            // Campos específicos por deporte
            if ($deporte === 'futbol') {
                $match["home_goals"] = null;
                $match["away_goals"] = null;
                $match["home_yellow"] = 0;
                $match["home_red"] = 0;
                $match["away_yellow"] = 0;
                $match["away_red"] = 0;
            } elseif ($deporte === 'handball') {
                $match["home_sets"] = null;
                $match["away_sets"] = null;
                $match["home_points"] = null;
                $match["away_points"] = null;
                $match["home_sets_won"] = 0;
                $match["away_sets_won"] = 0;
            } else { // voley
                $match["home_sets"] = null;
                $match["away_sets"] = null;
                $match["home_points"] = null;
                $match["away_points"] = null;
                $match["home_sets_won"] = 0;
                $match["away_sets_won"] = 0;
            }
            
            $matches[] = $match;
        }
        
        $fixed = array_shift($list);
        $last  = array_pop($list);
        array_unshift($list, $fixed);
        array_splice($list, 1, 0, [$last]);
    }
    
    return $matches;
}

/* =========================
   FIXTURE POR GRUPOS
   ========================= */
function generate_groups_fixture($teams, $groups, $deporte) {
    shuffle($teams);
    $teams_per_group = ceil(count($teams) / $groups);
    
    $grouped_teams = array_chunk($teams, $teams_per_group);
    $all_matches = [];
    $matchId = 1;
    $roundOffset = 0;
    
    for ($g = 0; $g < $groups; $g++) {
        $group_teams = $grouped_teams[$g] ?? [];
        if (count($group_teams) < 2) continue;
        
        $ids = array_map(fn($t) => (int)$t['id'], $group_teams);
        
        if (count($ids) % 2 === 1) {
            $ids[] = -999;
        }
        
        $n = count($ids);
        $rounds = $n - 1;
        $half = (int)($n / 2);
        
        $list = $ids;
        
        for ($r = 1; $r <= $rounds; $r++) {
            for ($i = 0; $i < $half; $i++) {
                $home = $list[$i];
                $away = $list[$n - 1 - $i];
                
                if ($home === -999 || $away === -999) continue;
                
                if ($r % 2 === 0) {
                    [$home, $away] = [$away, $home];
                }
                
                $match = [
                    "id" => $matchId++,
                    "round" => $r + $roundOffset,
                    "group" => $g + 1,
                    "home_id" => $home,
                    "away_id" => $away,
                    "played" => false,
                    "notes" => "",
                    "status" => "pendiente",
                    "walkover_winner" => null,
                    "deporte" => $deporte
                ];
                
                if ($deporte === 'futbol') {
                    $match["home_goals"] = null;
                    $match["away_goals"] = null;
                    $match["home_yellow"] = 0;
                    $match["home_red"] = 0;
                    $match["away_yellow"] = 0;
                    $match["away_red"] = 0;
                } elseif ($deporte === 'handball') {
                    $match["home_sets"] = null;
                    $match["away_sets"] = null;
                    $match["home_points"] = null;
                    $match["away_points"] = null;
                    $match["home_sets_won"] = 0;
                    $match["away_sets_won"] = 0;
                } else { // voley
                    $match["home_sets"] = null;
                    $match["away_sets"] = null;
                    $match["home_points"] = null;
                    $match["away_points"] = null;
                    $match["home_sets_won"] = 0;
                    $match["away_sets_won"] = 0;
                }
                
                $all_matches[] = $match;
            }
            
            $fixed = array_shift($list);
            $last  = array_pop($list);
            array_unshift($list, $fixed);
            array_splice($list, 1, 0, [$last]);
        }
        
        $roundOffset += $rounds;
    }
    
    return $all_matches;
}

function all_matches_completed($matches) {
    if (empty($matches)) return false;
    
    foreach ($matches as $match) {
        if ($match['status'] === 'pendiente') {
            return false;
        }
    }
    return true;
}



/* =========================
   PROCESAMIENTO LÓGICA
   ========================= */
$teams = read_json($EQUIPOS_FILE, []);
$matches = read_json($RESULTADOS_FILE, []);
$users = read_json($USUARIOS_FILE, []);
$fixture_config = read_json($FIXTURE_CONFIG_FILE, ['type' => 'todos_contra_todos', 'groups' => 2]);
$deporte_config = get_deporte_config();
$current_deporte = $deporte_config['deporte'];

// Verificar si todos los partidos están completados
$all_completed = all_matches_completed($matches);

// LOGIN
if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $usr = trim($_POST['username'] ?? '');
    $pwd = trim($_POST['password'] ?? '');
    
    if (isset($users[$usr]) && $users[$usr]['password'] === $pwd) {
        $_SESSION['user'] = $usr;
        $_SESSION['user_role'] = $users[$usr]['role'];
        header("Location: index.php");
        exit;
    } else {
        $login_error = "Usuario o contraseña incorrectos";
    }
}

// LOGOUT
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

$is_logged = isset($_SESSION['user']);
$is_admin_user = is_admin();

// CAMBIAR DEPORTE
if (isset($_POST['action']) && $_POST['action'] === 'cambiar_deporte') {
    require_admin();
    $new_deporte = trim($_POST['deporte'] ?? 'futbol');
    if (in_array($new_deporte, ['futbol', 'voley', 'handball'])) {
        set_deporte_config($new_deporte);
        header("Location: index.php");
        exit;
    }
}

// GESTIONAR EQUIPOS - VERSIÓN MEJORADA Y FUNCIONAL
if (isset($_POST['action']) && $_POST['action'] === 'gestionar_equipos') {
    require_admin();
    
    $new_teams = [];
    $team_count = isset($_POST['team_count']) ? (int)$_POST['team_count'] : 0;
    $team_id_counter = 1;
    
    // Primero, obtener todos los IDs existentes para no duplicar
    $existing_ids = [];
    foreach ($teams as $team) {
        $existing_ids[] = (int)$team['id'];
    }
    
    // Si hay IDs existentes, empezar desde el máximo + 1
    if (!empty($existing_ids)) {
        $team_id_counter = max($existing_ids) + 1;
    }
    
    // DEBUG: Ver qué datos llegan
    error_log("Team count from form: " . $team_count);
    
    for ($i = 1; $i <= $team_count; $i++) {
        $field_name = "team_name_$i";
        $nombre = trim($_POST[$field_name] ?? "");
        
        error_log("Processing team $i: '" . $nombre . "'");
        
        // Solo agregar si tiene nombre (no vacío después de trim)
        if (!empty($nombre)) {
            // Verificar si ya existe un equipo con este nombre (evitar duplicados)
            $existe = false;
            foreach ($new_teams as $team) {
                if (strtolower($team['nombre']) === strtolower($nombre)) {
                    $existe = true;
                    error_log("Duplicate team name found: " . $nombre);
                    break;
                }
            }
            
            if (!$existe) {
                $new_teams[] = [
                    'id' => $team_id_counter++,
                    'nombre' => $nombre
                ];
                error_log("Added team: " . $nombre . " with ID: " . ($team_id_counter-1));
            }
        }
    }
    
    error_log("Total teams to save: " . count($new_teams));
    
    if (count($new_teams) >= 2) {
        write_json($EQUIPOS_FILE, $new_teams);
        $_SESSION['success'] = "Se guardaron " . count($new_teams) . " equipos.";
        
        // Actualizar variable $teams para la página actual
        $teams = $new_teams;
        
        header("Location: index.php");
        exit;
    } else {
        // DEBUG para ver qué pasó
        $debug_info = "Team count in form: $team_count, ";
        $debug_info .= "Valid teams found: " . count($new_teams) . ", ";
        $debug_info .= "Team names: ";
        for ($i = 1; $i <= $team_count; $i++) {
            $debug_info .= "'" . trim($_POST["team_name_$i"] ?? "") . "', ";
        }
        error_log("Team saving failed: " . $debug_info);
        
        $_SESSION['error'] = "Debe haber al menos 2 equipos con nombre válido. ";
        $_SESSION['error'] .= "Encontrados: " . count($new_teams) . " equipos válidos.";
        
        // Mantener los valores en el formulario redirigiendo con un parámetro
        $_SESSION['form_data'] = [
            'team_count' => $team_count,
            'team_names' => []
        ];
        
        for ($i = 1; $i <= $team_count; $i++) {
            $_SESSION['form_data']['team_names'][$i] = $_POST["team_name_$i"] ?? "";
        }
        
        header("Location: index.php#admin");
        exit;
    }
}

// GENERAR FIXTURE
if (isset($_POST['action']) && $_POST['action'] === 'generar_fixture') {
    require_admin();
    
    if (empty($teams)) {
        $_SESSION['error'] = "Primero debes agregar equipos";
        header("Location: index.php");
        exit;
    }
    
    $type = $_POST['fixture_type'] ?? 'todos_contra_todos';
    $groups = isset($_POST['groups']) ? max(2, (int)$_POST['groups']) : 2;
    
    $matches = generate_round_robin($teams, $type, $groups, $current_deporte);
    write_json($RESULTADOS_FILE, $matches);
    $_SESSION['success'] = "Fixture generado correctamente";
    header("Location: index.php");
    exit;
}

// GUARDAR RESULTADOS - VERSIÓN FUNCIONAL
if (isset($_POST['action']) && $_POST['action'] === 'guardar_resultados') {
    require_admin();
    
    // Limpiar y preparar arrays
    $status_arr = $_POST['status'] ?? [];
    $notes_arr = $_POST['notes'] ?? [];
    $walkover_arr = $_POST['walkover_winner'] ?? [];
    
    // Procesar cada partido
    foreach ($matches as $idx => $match) {
        $match_id = (string)$match['id'];
        
        // Obtener nuevo estado
        $new_status = $status_arr[$match_id] ?? 'pendiente';
        $matches[$idx]['status'] = $new_status;
        $matches[$idx]['notes'] = $notes_arr[$match_id] ?? '';
        
        if ($new_status === 'walkover') {
            // WALKOVER
            $matches[$idx]['played'] = true;
            $matches[$idx]['walkover_winner'] = isset($walkover_arr[$match_id]) ? (int)$walkover_arr[$match_id] : null;
            
            // Limpiar todos los campos específicos del deporte
            reset_match_fields($matches[$idx], $current_deporte);
            
        } elseif ($new_status === 'jugado') {
            // JUGADO
            $matches[$idx]['played'] = true;
            $matches[$idx]['walkover_winner'] = null;
            
            // Guardar resultados según deporte
            if ($current_deporte === 'futbol') {
                // FÚTBOL - GOLES
                $home_goals = isset($_POST['home_goals'][$match_id]) && $_POST['home_goals'][$match_id] !== '' 
                    ? (int)$_POST['home_goals'][$match_id] 
                    : 0;
                    
                $away_goals = isset($_POST['away_goals'][$match_id]) && $_POST['away_goals'][$match_id] !== '' 
                    ? (int)$_POST['away_goals'][$match_id] 
                    : 0;
                
                $matches[$idx]['home_goals'] = $home_goals;
                $matches[$idx]['away_goals'] = $away_goals;
                
                // TARJETAS
                $matches[$idx]['home_yellow'] = isset($_POST['home_yellow'][$match_id]) ? (int)$_POST['home_yellow'][$match_id] : 0;
                $matches[$idx]['home_red'] = isset($_POST['home_red'][$match_id]) ? (int)$_POST['home_red'][$match_id] : 0;
                $matches[$idx]['away_yellow'] = isset($_POST['away_yellow'][$match_id]) ? (int)$_POST['away_yellow'][$match_id] : 0;
                $matches[$idx]['away_red'] = isset($_POST['away_red'][$match_id]) ? (int)$_POST['away_red'][$match_id] : 0;
                
            } elseif ($current_deporte === 'voley' || $current_deporte === 'handball') {
                // VOLEY y HANDBALL - Sets
                $home_sets = isset($_POST['home_sets'][$match_id]) ? trim($_POST['home_sets'][$match_id]) : null;
                $away_sets = isset($_POST['away_sets'][$match_id]) ? trim($_POST['away_sets'][$match_id]) : null;
                
                $matches[$idx]['home_sets'] = $home_sets;
                $matches[$idx]['away_sets'] = $away_sets;
                
                if ($home_sets && $away_sets) {
                    // Calcular sets ganados y puntos totales
                    $hs_parts = explode(',', $home_sets);
                    $as_parts = explode(',', $away_sets);
                    
                    $hs_won = 0;
                    $as_won = 0;
                    $hp_total = 0;
                    $ap_total = 0;
                    
                    for ($i = 0; $i < max(count($hs_parts), count($as_parts)); $i++) {
                        $hp = isset($hs_parts[$i]) ? (int)trim($hs_parts[$i]) : 0;
                        $ap = isset($as_parts[$i]) ? (int)trim($as_parts[$i]) : 0;
                        
                        if ($hp > $ap) $hs_won++;
                        elseif ($ap > $hp) $as_won++;
                        
                        $hp_total += $hp;
                        $ap_total += $ap;
                    }
                    
                    $matches[$idx]['home_sets_won'] = $hs_won;
                    $matches[$idx]['away_sets_won'] = $as_won;
                    $matches[$idx]['home_points'] = $hp_total;
                    $matches[$idx]['away_points'] = $ap_total;
                }
            }
            
        } else {
            // PENDIENTE o SUSPENDIDO
            $matches[$idx]['played'] = false;
            $matches[$idx]['walkover_winner'] = null;
            
            // Limpiar campos
            reset_match_fields($matches[$idx], $current_deporte);
        }
    }
    
    // Guardar en archivo
    write_json($RESULTADOS_FILE, $matches);
    $_SESSION['success'] = "Resultados guardados correctamente";
    header("Location: index.php");
    exit;
}

// GUARDAR HORARIOS
if (isset($_POST['action']) && $_POST['action'] === 'guardar_horarios') {
    require_admin();
    
    // Obtener arrays de datos
    $fechas = $_POST['fecha'] ?? [];
    $horas = $_POST['hora'] ?? [];
    $lugares = $_POST['lugar'] ?? [];
    
    // Actualizar cada partido
    foreach ($matches as $idx => $match) {
        $match_id = (string)$match['id'];
        
        // Actualizar fecha
        if (isset($fechas[$match_id])) {
            $matches[$idx]['fecha'] = trim($fechas[$match_id]);
        }
        
        // Actualizar hora
        if (isset($horas[$match_id])) {
            $matches[$idx]['hora'] = trim($horas[$match_id]);
        }
        
        // Actualizar lugar
        if (isset($lugares[$match_id])) {
            $matches[$idx]['lugar'] = trim($lugares[$match_id]);
        }
    }
    
    // Guardar en archivo
    write_json($RESULTADOS_FILE, $matches);
    $_SESSION['success'] = "Horarios guardados correctamente";
    header("Location: index.php#horarios");
    exit;
}

// FUNCIÓN AUXILIAR para limpiar campos
function reset_match_fields(&$match, $deporte) {
    if ($deporte === 'futbol') {
        $match['home_goals'] = null;
        $match['away_goals'] = null;
        $match['home_yellow'] = 0;
        $match['home_red'] = 0;
        $match['away_yellow'] = 0;
        $match['away_red'] = 0;
    } elseif ($deporte === 'voley') {
        $match['home_sets'] = null;
        $match['away_sets'] = null;
        $match['home_points'] = null;
        $match['away_points'] = null;
        $match['home_sets_won'] = 0;
        $match['away_sets_won'] = 0;
    } elseif ($deporte === 'handball') {
        $match['home_sets'] = null;
        $match['away_sets'] = null;
        $match['home_points'] = null;
        $match['away_points'] = null;
        $match['home_sets_won'] = 0;
        $match['away_sets_won'] = 0;
    }
}

// RESETEAR RESULTADOS (versión con null coalescing)
if (isset($_POST['action']) && $_POST['action'] === 'resetear') {
    require_admin();
    
    foreach ($matches as $idx => $m) {
        // Guardar horarios antes de resetear
        $fecha_guardada = $matches[$idx]['fecha'] ?? "";
        $hora_guardada = $matches[$idx]['hora'] ?? "";
        $lugar_guardado = $matches[$idx]['lugar'] ?? "";
        
        // Resetear resultados
        $matches[$idx]['played'] = false;
        $matches[$idx]['status'] = 'pendiente';
        $matches[$idx]['notes'] = '';
        $matches[$idx]['walkover_winner'] = null;
        
        // Limpiar campos según deporte
        if ($current_deporte === 'futbol') {
            $matches[$idx]['home_goals'] = null;
            $matches[$idx]['away_goals'] = null;
            $matches[$idx]['home_yellow'] = 0;
            $matches[$idx]['home_red'] = 0;
            $matches[$idx]['away_yellow'] = 0;
            $matches[$idx]['away_red'] = 0;
        } elseif ($current_deporte === 'voley') {
            $matches[$idx]['home_sets'] = null;
            $matches[$idx]['away_sets'] = null;
            $matches[$idx]['home_points'] = null;
            $matches[$idx]['away_points'] = null;
            $matches[$idx]['home_sets_won'] = 0;
            $matches[$idx]['away_sets_won'] = 0;
        } elseif ($current_deporte === 'handball') {
            $matches[$idx]['home_sets'] = null;
            $matches[$idx]['away_sets'] = null;
            $matches[$idx]['home_points'] = null;
            $matches[$idx]['away_points'] = null;
            $matches[$idx]['home_sets_won'] = 0;
            $matches[$idx]['away_sets_won'] = 0;
        }
        
        // Asegurarse de que los campos de horario existan (inicializarlos si no existen)
        $matches[$idx]['fecha'] = $fecha_guardada;
        $matches[$idx]['hora'] = $hora_guardada;
        $matches[$idx]['lugar'] = $lugar_guardado;
    }
    
    write_json($RESULTADOS_FILE, $matches);
    $_SESSION['success'] = "Resultados reseteados correctamente";
    header("Location: index.php");
    exit;
}

// CALCULAR STANDINGS
$standings = calculate_standings($teams, $matches, $current_deporte);

// Agrupar partidos por grupo si es fixture por grupos
$matches_by_group = [];
if ($fixture_config['type'] === 'grupos') {
    foreach ($matches as $m) {
        $g = $m['group'] ?? 1;
        if (!isset($matches_by_group[$g])) {
            $matches_by_group[$g] = [];
        }
        $matches_by_group[$g][] = $m;
    }
} else {
    $matches_by_group[0] = $matches; // Un solo grupo
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($TORNEO) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&family=Open+Sans:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
    :root {
        --primary-neon: #0D1B5C;      /* Color principal vibrante */
        --primary-dark: #822c8f;       /* Azul oscuro para contraste */
        --secondary: #4C1D95;          /* Púrpura eléctrico */
        --accent-orange: #F59E0B;      /* Naranja para acentos */
        --success: #10B981;            /* Verde esmeralda */
        --danger: #EF4444;             /* Rojo vibrante */
        --warning: #F59E0B;            /* Naranja para advertencias */
        --light: #F8FAFC;              /* Fondo claro */
        --dark-bg: #0F172A;            /* Fondo oscuro para modo nocturno */
        
        /* Colores de texto */
        --text-main: #1E293B;          /* Texto principal */
        --text-muted: #64748B;         /* Texto secundario */
        
        /* Variables de diseño */
        --bg-card: rgba(255, 255, 255, 0.95);
        --shadow-card: 0 10px 40px rgba(0, 0, 0, 0.08);
        --shadow-hover: 0 20px 60px rgba(217, 70, 239, 0.15);
        --radius-lg: 20px;
        --radius-md: 12px;
        --radius-sm: 8px;
        --spacing-section: 24px;
        
        /* Gradientes */
        --gradient-primary: linear-gradient(135deg, var(--primary-neon) 0%, var(--secondary) 100%);
        --gradient-dark: linear-gradient(135deg, var(--dark-bg) 0%, #1E293B 100%);
        --gradient-card: linear-gradient(to right, #ffffff, #f8f9fa);
    }
    
    body {
        font-family: 'Inter', 'Open Sans', sans-serif;
         background: 
        linear-gradient(135deg, #FFF8F0 0%, #F5F0E6 100%),
        radial-gradient(circle at 10% 20%, rgba(210, 180, 140, 0.08) 0%, transparent 25%),
        radial-gradient(circle at 90% 80%, rgba(210, 180, 140, 0.08) 0%, transparent 25%),
        repeating-linear-gradient(
            45deg,
            transparent,
            transparent 40px,
            rgba(184, 134, 72, 0.04) 40px,
            rgba(184, 134, 72, 0.04) 80px
        );
        background-blend-mode: multiply;
        min-height: 100vh;
        padding: 1rem 0;
        font-size: 0.9rem;
        color: var(--text-main);
    }
    
    .container-main {
        background: var(--bg-card);
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-card);
        padding: 1.5rem;
        margin-top: var(--spacing-section);
        margin-bottom: 2rem;
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.1);
    }
    
    .navbar-sport {
        background: var(--gradient-primary);
        border-radius: var(--radius-md);
        margin: 1rem auto;
        max-width: 95%;
        box-shadow: 0 8px 30px rgba(217, 70, 239, 0.15);
        padding: 0.75rem 1.5rem;
        color: white;
        border: none;
    }
    
    .navbar-brand {
        font-family: 'Montserrat', sans-serif;
        font-weight: 700;
        font-size: 1.4rem;
        color: white !important;
        letter-spacing: -0.02em;
    }
    
    .deporte-badge {
        background: var(--accent-orange);
        color: white;
        padding: 0.4rem 0.8rem;
        border-radius: 20px;
        font-weight: 600;
        font-size: 0.8rem;
        display: inline-block;
    }
    
    .card-sport {
        border: none;
        border-radius: var(--radius-md);
        background: white;
        box-shadow: var(--shadow-card);
        transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        overflow: hidden;
        margin-bottom: 1.5rem;
        border: 1px solid rgba(226, 232, 240, 0.6);
    }
    
    .card-sport:hover {
        transform: translateY(-4px);
        box-shadow: var(--shadow-hover);
    }
    
    .card-header-sport {
        background: var(--gradient-primary);
        color: white;
        border: none;
        padding: 1rem 1.25rem;
        font-family: 'Montserrat', sans-serif;
        font-weight: 600;
        font-size: 1rem;
        position: relative;
        overflow: hidden;
    }
    
    .match-card-sport {
        background: var(--gradient-card);
        border-left: 4px solid var(--secondary);
        border-radius: var(--radius-sm);
        padding: 1rem;
        margin-bottom: 1rem;
        transition: all 0.3s ease;
        border: 1px solid rgba(241, 245, 249, 0.8);
    }
    
    .match-card-sport:hover {
        background: white;
        border-left-color: var(--primary-neon);
    }
    
    .team-name {
        font-family: 'Montserrat', sans-serif;
        font-weight: 600;
        color: var(--primary-dark);
        font-size: 0.95rem;
        letter-spacing: -0.01em;
    }
    
    .score-display {
        font-family: 'Montserrat', sans-serif;
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--primary-neon);
        text-shadow: 0 2px 4px rgba(217, 70, 239, 0.1);
    }
    
    .btn-sport {
        background: var(--gradient-primary);
        color: white;
        border: none;
        border-radius: var(--radius-sm);
        padding: 0.6rem 1.2rem;
        font-family: 'Montserrat', sans-serif;
        font-weight: 600;
        font-size: 0.85rem;
        transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        letter-spacing: -0.01em;
    }
    
    .btn-sport:hover {
        transform: translateY(-2px);
        box-shadow: 0 12px 24px rgba(217, 70, 239, 0.25);
        color: white;
    }
    
    .table-sport th {
        background: var(--gradient-primary);
        color: white;
        font-family: 'Montserrat', sans-serif;
        font-weight: 600;
        font-size: 0.8rem;
        padding: 1rem;
        text-transform: uppercase;
        border: none;
    }
    
    .table-sport td {
        padding: 1rem;
        vertical-align: middle;
        font-size: 0.85rem;
        border-bottom: 1px solid #F1F5F9;
    }
    
    .table-sport tbody tr {
        transition: background-color 0.2s ease;
    }
    
    .table-sport tbody tr:hover {
        background-color: var(--light);
    }
    
    /* Estilos de búsqueda y filtros (inspirados en Techno-Organic) */
    .search-input {
        padding: 10px 16px;
        border-radius: var(--radius-sm);
        border: 1px solid #E2E8F0;
        background: var(--light);
        font-family: inherit;
        width: 250px;
        font-size: 0.85rem;
        transition: all 0.2s ease;
    }
    
    .search-input:focus {
        outline: none;
        border-color: var(--primary-neon);
        box-shadow: 0 0 0 3px rgba(217, 70, 239, 0.1);
    }
    
    .btn-filter {
        background: white;
        color: var(--primary-neon);
        border: 1px solid var(--primary-neon);
        border-radius: var(--radius-sm);
        padding: 10px 20px;
        font-family: 'Montserrat', sans-serif;
        font-weight: 600;
        font-size: 0.85rem;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    
    .btn-filter:hover {
        background: var(--primary-neon);
        color: white;
    }
    
    .small-input {
        width: 60px;
        font-size: 0.85rem;
        padding: 0.5rem;
        text-align: center;
        border: 1px solid #E2E8F0;
        border-radius: var(--radius-sm);
        background: white;
        transition: all 0.2s ease;
    }
    
    .small-input:focus {
        border-color: var(--primary-neon);
        box-shadow: 0 0 0 3px rgba(217, 70, 239, 0.1);
        outline: none;
    }
    
    .readonly-look {
        background-color: #F1F5F9;
        cursor: not-allowed;
        border-color: #E2E8F0;
        color: var(--text-muted);
    }
    
    .login-container {
        max-width: 400px;
        margin: 3rem auto;
    }
    
    /* Mejoras en las pestañas */
    .nav-tabs {
        border-bottom: 2px solid #F1F5F9;
        margin-bottom: 1.5rem;
    }
    
    .nav-tabs .nav-link {
        border: none;
        color: var(--text-muted);
        font-weight: 600;
        padding: 0.75rem 1.5rem;
        border-radius: var(--radius-sm) var(--radius-sm) 0 0;
        margin-right: 0.25rem;
        font-family: 'Montserrat', sans-serif;
        transition: all 0.2s ease;
    }
    
    .nav-tabs .nav-link.active {
        color: var(--primary-neon);
        background-color: white;
        border-bottom: 3px solid var(--primary-neon);
    }
    
    /* ESTILOS PARA PESTAÑA HORARIOS */
    .match-card-horario {
        transition: all 0.3s ease;
    }
    
    .match-card-horario:hover {
        background-color: #f8f9fa;
    }
    
    .avatar-small {
        width: 20px;
        height: 20px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 10px;
        font-weight: bold;
        color: white;
    }
    
    /* Responsive para horarios */
    @media (max-width: 768px) {
        .match-card-horario .row > div {
            margin-bottom: 15px;
        }
        
        .match-card-horario .col-md-4:last-child {
            border-top: 1px solid #dee2e6;
            padding-top: 15px;
        }
    }
    
    /* Badges inspirados en Techno-Organic */
    .status-badge {
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 600;
        display: inline-block;
    }
    
    .status-badge.success {
        background: #DCFCE7;
        color: #15803D;
    }
    
    .status-badge.pending {
        background: #F1F5F9;
        color: #475569;
    }
    
    .ticket-tag {
        font-size: 0.8rem;
        font-weight: 500;
        color: var(--text-main);
        background: #F1F5F9;
        padding: 4px 10px;
        border-radius: 6px;
        display: inline-block;
    }
    
    /* Avatar estilos */
    .avatar {
        width: 40px;
        height: 40px;
        background: rgba(217, 70, 239, 0.1);
        color: var(--primary-neon);
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 0.9rem;
        font-family: 'Montserrat', sans-serif;
    }
    
    .avatar.orange {
        background: rgba(245, 158, 11, 0.1);
        color: var(--accent-orange);
    }

/* CLASES PARA 3 COLORES DIFERENTES */
.ganador-local {
    border-left: 4px solid #0d6efd !important; /* AZUL - Local gana */
    background: rgba(13, 110, 253, 0.05) !important;
}

.ganador-visitante {
    border-left: 4px solid #dc3545 !important; /* ROJO - Visitante gana */
    background: rgba(220, 53, 69, 0.05) !important;
}

.empate {
    border-left: 4px solid #ffc107 !important; /* AMARILLO - Empate */
    background: rgba(255, 193, 7, 0.05) !important;
}
    
    /* Responsive mejorado */
    @media (max-width: 768px) {
        body {
            padding: 0.5rem 0;
            font-size: 0.85rem;
        }
        
        .container-main {
            padding: 1rem;
            margin: 0.5rem;
            border-radius: 15px;
        }
        
        .navbar-sport {
            padding: 0.5rem 1rem;
            margin: 0.5rem auto;
        }
        
        .navbar-brand {
            font-size: 1.2rem;
        }
        
        .score-display {
            font-size: 1.2rem;
        }
        
        .search-input {
            width: 100%;
            margin-bottom: 10px;
        }
        
        .small-input {
            width: 50px;
            font-size: 0.8rem;
            padding: 0.4rem;
        }
        
        .table-responsive {
            font-size: 0.8rem;
        }
        
        .team-name {
            font-size: 0.85rem;
        }
        
        .btn-sport {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
        }
        
        .nav-tabs .nav-link {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
        }
    }
    
    @media (max-width: 576px) {
        .container-main {
            padding: 0.75rem;
        }
        
        .match-card-sport {
            padding: 0.75rem;
        }
        
        .small-input {
            width: 45px;
            padding: 0.3rem;
        }
        
        .col-md-3, .col-md-6, .col-md-4 {
            margin-bottom: 0.5rem;
        }
        
        .table-header {
            flex-direction: column;
            align-items: stretch;
        }
        
        .search-input {
            width: 100%;
        }
    }
    
    /* Iconos deportes */
    .futbol-icon::before { content: "⚽ "; }
    .voley-icon::before { content: "🏐 "; }
    .handball-icon::before { content: "🤾 "; }
    
    /* Toast notifications */
    .toast-container {
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 9999;
    }
    
    /* Mejoras en resultados PDF */
    .result-row {
        display: flex;
        justify-content: space-between;
        padding: 0.75rem;
        border-bottom: 1px solid #F1F5F9;
        transition: background-color 0.2s ease;
    }
    
    .result-row:hover {
        background-color: var(--light);
    }
    
    /* Clases utilitarias */
    .text-right {
        text-align: right;
    }
    
    .btn-icon {
        background: none;
        border: none;
        font-size: 1.25rem;
        color: var(--text-muted);
        cursor: pointer;
        padding: 4px 8px;
        transition: color 0.2s ease;
    }
    
    .btn-icon:hover {
        color: var(--primary-neon);
    }
</style>
    

</head>
<body>

<?php if (!$is_logged): ?>
    <!-- LOGIN -->
    <div class="login-container fade-in">
        <div class="card login-card">
            <div class="card-header text-center py-4" style="background: linear-gradient(135deg, #0D1B5C 0%, #4C1D95 100%);">
                <span class="navbar-brand"> MEET<span style="color: cyan;">X</span></span>
                <p class="text-white mb-0">Sistema de Gestión Deportiva</p>
            </div>
            <div class="card-body p-4">
                <?php if (isset($login_error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <?= htmlspecialchars($login_error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <form method="post">
                    <input type="hidden" name="action" value="login">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Usuario</label>
                        <input type="text" class="form-control" name="username" required autofocus>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Contraseña</label>
                        <input type="password" class="form-control" name="password" required>
                    </div>
                    <button type="submit" class="btn btn-sport w-100">Ingresar al Sistema</button>
                </form>

      <div class="mt-3 text-center text-muted small">
        Usuario para jugadores: <strong>jugadores</strong> / <strong>jugadores123</strong><br>
      </div>


            </div>
        </div>
    </div>
<?php else: ?>
    <!-- NAVBAR -->
    <nav class="navbar navbar-sport">
        <div class="container-fluid">
            <div class="d-flex align-items-center gap-3">
                <span class="navbar-brand"> MEET<span style="color: cyan;">X</span></span>
                <span class="deporte-badge">
                    <?php if ($current_deporte === 'futbol'): ?>
                        <span class="futbol-icon"></span>Beach Futbol
                    <?php elseif ($current_deporte === 'voley'): ?>
                        <span class="voley-icon"></span>Beach Voley
                    <?php elseif ($current_deporte === 'handball'): ?>
                        <span class="handball-icon"></span>Beach Handball
                    <?php endif; ?>
                </span>
            </div>
            <div class="d-flex align-items-center gap-3">
                <span class="text-white">
                    <strong style="color: yellow;"><?= htmlspecialchars($_SESSION['user']) ?></strong>
                </span>
                <a href="index.php?logout=1" class="btn btn-outline-light btn-sm">Cerrar Sesión</a>
            </div>
        </div>
    </nav>

    <div class="container container-main fade-in">
        <!-- Mensajes de éxito/error -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?= htmlspecialchars($_SESSION['success']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?= htmlspecialchars($_SESSION['error']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>
        
        <!-- PESTAÑAS PRINCIPALES -->
        <ul class="nav nav-tabs" id="mainTabs">
            <li class="nav-item">
                <a class="nav-link active" id="tabla-tab" data-bs-toggle="tab" href="#tabla">
                    <i class="fas fa-table"></i> Tabla
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="resultados-tab" data-bs-toggle="tab" href="#resultados">
                    <i class="fas fa-futbol"></i> Resultados
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="horarios-tab" data-bs-toggle="tab" href="#horarios">
                    <i class="fas fa-calendar-alt"></i> Días y Horarios
                </a>
            </li>
            <?php if ($is_admin_user): ?>
            <li class="nav-item">
                <a class="nav-link" id="admin-tab" data-bs-toggle="tab" href="#admin">
                    <i class="fas fa-cog"></i> Administración
                </a>
            </li>
            <?php endif; ?>
        </ul>
        
        <div class="tab-content">
            <!-- PESTAÑA TABLA -->
            <div class="tab-pane fade show active" id="tabla">
                <div class="card card-sport">
                    <div class="card-header-sport">
                        <h5 class="mb-0">Tabla de Posiciones</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sport table-hover mb-0">
                                <?php if ($current_deporte === 'futbol'): ?>
                                    <thead>
                                        <tr>
                                            <th width="40">#</th>
                                            <th>Equipo</th>
                                            <th width="50" class="text-end">Pts</th>
                                            <th width="40" class="text-end">PJ</th>
                                            <th width="40" class="text-end d-none d-sm-table-cell">PG</th>
                                            <th width="40" class="text-end d-none d-sm-table-cell">PE</th>
                                            <th width="40" class="text-end d-none d-sm-table-cell">PP</th>
                                            <th width="50" class="text-end">DG</th>
                                            <th width="50" class="text-end d-none d-md-table-cell">GF</th>
                                            <th width="50" class="text-end d-none d-md-table-cell">GC</th>
                                            <th width="40" class="text-end">🟨</th>
                                            <th width="40" class="text-end">🟥</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($standings as $i => $r): ?>
                                            <tr>
                                                <td><span class="badge bg-primary"><?= $i+1 ?></span></td>
                                                <td class="fw-semibold"><?= htmlspecialchars($r["nombre"]) ?></td>
                                                <td class="text-end fw-bold text-primary"><?= $r["pts"] ?></td>
                                                <td class="text-end"><?= $r["pj"] ?></td>
                                                <td class="text-end d-none d-sm-table-cell"><?= $r["pg"] ?></td>
                                                <td class="text-end d-none d-sm-table-cell"><?= $r["pe"] ?></td>
                                                <td class="text-end d-none d-sm-table-cell"><?= $r["pp"] ?></td>
                                                <td class="text-end fw-semibold <?= $r["dg"] > 0 ? 'text-success' : ($r["dg"] < 0 ? 'text-danger' : '') ?>">
                                                    <?= $r["dg"] > 0 ? '+' : '' ?><?= $r["dg"] ?>
                                                </td>
                                                <td class="text-end d-none d-md-table-cell"><?= $r["gf"] ?></td>
                                                <td class="text-end d-none d-md-table-cell"><?= $r["gc"] ?></td>
                                                <td class="text-end"><?= $r["ta"] ?></td>
                                                <td class="text-end"><?= $r["tr"] ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                
                                <?php elseif ($current_deporte === 'voley'): ?>
                                    <thead>
                                        <tr>
                                            <th width="40">#</th>
                                            <th>Equipo</th>
                                            <th width="50" class="text-end">Pts</th>
                                            <th width="40" class="text-end">PJ</th>
                                            <th width="40" class="text-end d-none d-sm-table-cell">PG</th>
                                            <th width="40" class="text-end d-none d-sm-table-cell">PP</th>
                                            <th width="50" class="text-end">Sets +/-</th>
                                            <th width="50" class="text-end d-none d-md-table-cell">SG</th>
                                            <th width="50" class="text-end d-none d-md-table-cell">SP</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($standings as $i => $r): ?>
                                            <tr>
                                                <td><span class="badge bg-primary"><?= $i+1 ?></span></td>
                                                <td class="fw-semibold"><?= htmlspecialchars($r["nombre"]) ?></td>
                                                <td class="text-end fw-bold text-primary"><?= $r["pts"] ?></td>
                                                <td class="text-end"><?= $r["pj"] ?></td>
                                                <td class="text-end d-none d-sm-table-cell"><?= $r["pg"] ?></td>
                                                <td class="text-end d-none d-sm-table-cell"><?= $r["pp"] ?></td>
                                                <td class="text-end fw-semibold <?= $r["dg"] > 0 ? 'text-success' : ($r["dg"] < 0 ? 'text-danger' : '') ?>">
                                                    <?= $r["dg"] > 0 ? '+' : '' ?><?= $r["dg"] ?>
                                                </td>
                                                <td class="text-end d-none d-md-table-cell"><?= $r["sets_won"] ?></td>
                                                <td class="text-end d-none d-md-table-cell"><?= $r["sets_lost"] ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                
                                <?php elseif ($current_deporte === 'handball'): ?>
                                    <thead>
                                        <tr>
                                            <th width="40">#</th>
                                            <th>Equipo</th>
                                            <th width="50" class="text-end">Pts</th>
                                            <th width="40" class="text-end">PJ</th>
                                            <th width="40" class="text-end d-none d-sm-table-cell">PG</th>
                                            <th width="40" class="text-end d-none d-sm-table-cell">PP</th>
                                            <th width="50" class="text-end">Sets +/-</th>
                                            <th width="50" class="text-end d-none d-md-table-cell">SG</th>
                                            <th width="50" class="text-end d-none d-md-table-cell">SP</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($standings as $i => $r): ?>
                                            <tr>
                                                <td><span class="badge bg-primary"><?= $i+1 ?></span></td>
                                                <td class="fw-semibold"><?= htmlspecialchars($r["nombre"]) ?></td>
                                                <td class="text-end fw-bold text-primary"><?= $r["pts"] ?></td>
                                                <td class="text-end"><?= $r["pj"] ?></td>
                                                <td class="text-end d-none d-sm-table-cell"><?= $r["pg"] ?></td>
                                                <td class="text-end d-none d-sm-table-cell"><?= $r["pp"] ?></td>
                                                <td class="text-end fw-semibold <?= $r["dg"] > 0 ? 'text-success' : ($r["dg"] < 0 ? 'text-danger' : '') ?>">
                                                    <?= $r["dg"] > 0 ? '+' : '' ?><?= $r["dg"] ?>
                                                </td>
                                                <td class="text-end d-none d-md-table-cell"><?= $r["sets_won"] ?></td>
                                                <td class="text-end d-none d-md-table-cell"><?= $r["sets_lost"] ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- PESTAÑA RESULTADOS -->
            <div class="tab-pane fade" id="resultados">
                <div class="card card-sport">
                    <div class="card-header-sport d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Resultados de Partidos</h5>
                        <?php if ($is_admin_user): ?>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="toggleEditPlayed">
                                <label class="form-check-label text-white fw-semibold" for="toggleEditPlayed">
                                    Editar todo
                                </label>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if (empty($matches)): ?>
                            <div class="alert alert-info text-center py-4">
                                <h5>📋 No hay fixture generado</h5>
                                <p class="mb-0">Genera un fixture desde la pestaña de administración.</p>
                            </div>
                        <?php else: ?>
                            <?php if ($is_admin_user): ?>
                            <form method="post" id="resultsForm">
                                <input type="hidden" name="action" value="guardar_resultados">
                            <?php endif; ?>
                            
                                <div class="row">
                                    <?php foreach ($matches_by_group as $group_num => $group_matches): ?>
                                        <div class="col-12 mb-4">
                                            <?php if ($fixture_config['type'] === 'grupos'): ?>
                                                <h6 class="text-muted mb-3 border-bottom pb-2">
                                                    <span class="badge bg-secondary me-2">Grupo <?= $group_num ?></span>
                                                    Partidos del Grupo <?= $group_num ?>
                                                </h6>
                                            <?php endif; ?>
                                            
                                            <?php foreach ($group_matches as $m): 
                                                $id = $m['id'];
                                                $home = team_name($teams, $m['home_id']);
                                                $away = team_name($teams, $m['away_id']);
                                                $status = $m['status'];
                                                $played = $m['played'];
                                                
                                                $lockClass = ($played && !$is_admin_user) ? 'readonly-look' : '';
                                            ?>
                                            <div class="match-card-sport">
                                                <div class="row align-items-center">
                                                    <div class="col-md-3 mb-3 mb-md-0">
                                                        <div class="d-flex align-items-center gap-2">
                                                            <span class="badge bg-secondary">Ronda <?= $m['round'] ?></span>
                                                            <?php if (isset($m['group'])): ?>
                                                                <span class="badge bg-info">G<?= $m['group'] ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="mt-2">
                                                            <div class="team-name d-flex align-items-center gap-2">
                                                                <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 20px; height: 20px; font-size: 10px;">L</div>
                                                                <?= htmlspecialchars($home) ?>
                                                            </div>
                                                            <div class="team-name d-flex align-items-center gap-2 mt-1">
                                                                <div class="bg-danger text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 20px; height: 20px; font-size: 10px;">V</div>
                                                                <?= htmlspecialchars($away) ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="col-md-6 mb-3 mb-md-0">
                                                        <?php if ($current_deporte === 'futbol'): ?>
                                                            <!-- CAMPOS FÚTBOL -->
                                                            <div class="d-flex justify-content-center align-items-center gap-2 mb-2">
                                                                <input class="form-control small-input"
                                                                       type="number" min="0"
                                                                       name="home_goals[<?= $id ?>]"
                                                                       value="<?= $m['home_goals'] === null ? '' : (int)$m['home_goals'] ?>"
                                                                       placeholder="0"
                                                                       data-match-id="<?= $id ?>">
                                                                
                                                                <span class="score-display">-</span>
                                                                
                                                                <input class="form-control small-input"
                                                                       type="number" min="0"
                                                                       name="away_goals[<?= $id ?>]"
                                                                       value="<?= $m['away_goals'] === null ? '' : (int)$m['away_goals'] ?>"
                                                                       placeholder="0"
                                                                       data-match-id="<?= $id ?>">
                                                            </div>
                                                            
                                                            <!-- TARJETAS -->
                                                            <div class="d-flex justify-content-center gap-2 flex-wrap">
                                                                <div class="d-flex align-items-center gap-1">
                                                                    <span class="fs-6">🟨</span>
                                                                    <input class="form-control small-input"
                                                                           type="number" min="0"
                                                                           name="home_yellow[<?= $id ?>]"
                                                                           value="<?= (int)$m['home_yellow'] ?>"
                                                                           data-match-id="<?= $id ?>">
                                                                    <span>/</span>
                                                                    <input class="form-control small-input"
                                                                           type="number" min="0"
                                                                           name="away_yellow[<?= $id ?>]"
                                                                           value="<?= (int)$m['away_yellow'] ?>"
                                                                           data-match-id="<?= $id ?>">
                                                                </div>
                                                                
                                                                <div class="d-flex align-items-center gap-1">
                                                                    <span class="fs-6">🟥</span>
                                                                    <input class="form-control small-input"
                                                                           type="number" min="0"
                                                                           name="home_red[<?= $id ?>]"
                                                                           value="<?= (int)$m['home_red'] ?>"
                                                                           data-match-id="<?= $id ?>">
                                                                    <span>/</span>
                                                                    <input class="form-control small-input"
                                                                           type="number" min="0"
                                                                           name="away_red[<?= $id ?>]"
                                                                           value="<?= (int)$m['away_red'] ?>"
                                                                           data-match-id="<?= $id ?>">
                                                                </div>
                                                            </div>
                                                            
                                                        <?php elseif ($current_deporte === 'voley'): ?>
                                                            <!-- CAMPOS VOLEY -->
                                                            <div class="mb-2">
                                                                <div class="text-center small text-muted mb-1">Sets (ej: 25,23,15)</div>
                                                                <div class="d-flex justify-content-center align-items-center gap-2">
                                                                    <input class="form-control text-center"
                                                                           type="text"
                                                                           name="home_sets[<?= $id ?>]"
                                                                           value="<?= htmlspecialchars($m["home_sets"] ?? "") ?>"
                                                                           placeholder=""
                                                                           style="width: 120px;"
                                                                           data-match-id="<?= $id ?>">
                                                                    
                                                                    <span class="score-display">-</span>
                                                                    
                                                                    <input class="form-control text-center"
                                                                           type="text"
                                                                           name="away_sets[<?= $id ?>]"
                                                                           value="<?= htmlspecialchars($m["away_sets"] ?? "") ?>"
                                                                           placeholder=""
                                                                           style="width: 120px;"
                                                                           data-match-id="<?= $id ?>">
                                                                </div>
                                                            </div>
                                                            
                                                            <?php if ($played): ?>
                                                            <div class="text-center">
                                                                <small class="text-muted">
                                                                    <strong>Sets:</strong> <?= isset($m["home_sets_won"]) ? (int)$m["home_sets_won"] : 0 ?>-<?= isset($m["away_sets_won"]) ? (int)$m["away_sets_won"] : 0 ?>
                                                                </small>
                                                            </div>
                                                            <?php endif; ?>
                                                        
                                                       <?php elseif ($current_deporte === 'handball'): ?>
    <!-- CAMPOS HANDBALL (SISTEMA DE SETS COMO VÓLEY) -->
    <div class="mb-2">
        <div class="text-center small text-muted mb-1">Sets (ej: 21,19,15)</div>
        <div class="d-flex justify-content-center align-items-center gap-2">
            <input class="form-control text-center"
                   type="text"
                   name="home_sets[<?= $id ?>]"
                   value="<?= htmlspecialchars($m["home_sets"] ?? "") ?>"
                   placeholder=""
                   style="width: 120px;"
                   data-match-id="<?= $id ?>">
            
            <span class="score-display">-</span>
            
            <input class="form-control text-center"
                   type="text"
                   name="away_sets[<?= $id ?>]"
                   value="<?= htmlspecialchars($m["away_sets"] ?? "") ?>"
                   placeholder=""
                   style="width: 120px;"
                   data-match-id="<?= $id ?>">
        </div>
    </div>
    
    <?php if ($played): ?>
    <div class="text-center">
        <small class="text-muted">
            <strong>Sets:</strong> <?= isset($m["home_sets_won"]) ? (int)$m["home_sets_won"] : 0 ?>-<?= isset($m["away_sets_won"]) ? (int)$m["away_sets_won"] : 0 ?>
        </small>
    </div>
    <?php endif; ?>

<?php endif; ?>

                                                        
                                                        
                                                        <!-- WALKOVER -->
                                                        <?php if ($is_admin_user): ?>
                                                        <div id="walkover-<?= $id ?>" style="display: <?= $status === 'walkover' ? 'block' : 'none' ?>;" class="mt-2">
                                                            <label class="form-label small">Ganador por Walkover:</label>
                                                            <select class="form-select form-select-sm" name="walkover_winner[<?= $id ?>]">
                                                                <option value="">Seleccionar equipo</option>
                                                                <option value="<?= $m['home_id'] ?>" <?= isset($m['walkover_winner']) && (int)$m['walkover_winner'] === (int)$m['home_id'] ? 'selected' : '' ?>>
                                                                    <?= htmlspecialchars($home) ?>
                                                                </option>
                                                                <option value="<?= $m['away_id'] ?>" <?= isset($m['walkover_winner']) && (int)$m['walkover_winner'] === (int)$m['away_id'] ? 'selected' : '' ?>>
                                                                    <?= htmlspecialchars($away) ?>
                                                                </option>
                                                            </select>
                                                        </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    
                                                    <div class="col-md-3">
                                                        <?php if ($is_admin_user): ?>
                                                            <select class="form-select form-select-sm mb-2 match-status" 
                                                                    name="status[<?= $id ?>]"
                                                                    data-match-id="<?= $id ?>">
                                                                <option value="pendiente" <?= $status === 'pendiente' ? 'selected' : '' ?>>⏳ Pendiente</option>
                                                                <option value="jugado" <?= $status === 'jugado' ? 'selected' : '' ?>>✅ Jugado</option>
                                                                <option value="walkover" <?= $status === 'walkover' ? 'selected' : '' ?>>🚩 Walkover</option>
                                                                <option value="suspendido" <?= $status === 'suspendido' ? 'selected' : '' ?>>⏸️ Suspendido</option>
                                                            </select>
                                                        <?php else: ?>
                                                            <div class="mb-2">
                                                                <?php if ($status === 'jugado'): ?>
                                                                    <span class="badge text-bg-success status-badge w-100">Jugado</span>
                                                                <?php elseif ($status === 'walkover'): ?>
                                                                    <span class="badge text-bg-warning status-badge w-100">Walkover</span>
                                                                <?php elseif ($status === 'suspendido'): ?>
                                                                    <span class="badge text-bg-secondary status-badge w-100">Suspendido</span>
                                                                <?php else: ?>
                                                                    <span class="badge text-bg-light status-badge w-100">Pendiente</span>
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        
                                                        <input type="text" class="form-control form-control-sm mb-2" 
                                                               name="notes[<?= $id ?>]"
                                                               value="<?= htmlspecialchars($m['notes'] ?? '') ?>"
                                                               placeholder="Notas">
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <?php if ($is_admin_user): ?>
                                <div class="d-flex flex-wrap gap-2 mt-3 pt-3 border-top">
                                    <button type="submit" class="btn btn-sport">
                                        <i class="fas fa-save"></i> Guardar
                                    </button>
                                    <a class="btn btn-outline-secondary" href="index.php">
                                        <i class="fas fa-redo"></i> Recargar
                                    </a>
                                </div>
                                <?php endif; ?>
                                
                            <?php if ($is_admin_user): ?>
                            </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- EXPORTAR PDF -->
                <?php if ($all_completed && $is_admin_user): ?>
                <div class="card card-sport mt-4 border-success">
                    <div class="card-header-sport bg-success">
                        <h5 class="mb-0">🎉 Torneo Completado</h5>
                    </div>
                    <div class="card-body text-center py-3">
                        <h6 class="text-success">✅ Todos los partidos han sido finalizados</h6>
                        <p class="text-muted">El torneo ha concluido. Puedes generar el informe final en formato PDF.</p>
                        <button class="btn btn-sport" onclick="exportToPDF()">
                            <i class="fas fa-file-pdf"></i> Exportar Reporte
                        </button>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- PESTAÑA DÍAS Y HORARIOS -->
            <div class="tab-pane fade" id="horarios">
                <div class="card card-sport">
                    <div class="card-header-sport d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"></i> Calendario de Partidos</h5>
                        <button class="btn btn-sm btn-outline-light" onclick="ordenarPorFechaHora()">
                            <i class="fas fa-sort"></i> Ordenar por Fecha/Hora
                        </button>
                    </div>
                    <div class="card-body">
                        <?php if (empty($matches)): ?>
                            <div class="alert alert-info text-center py-4">
                                <h5>📋 No hay fixture generado</h5>
                                <p class="mb-0">Genera un fixture desde la pestaña de administración.</p>
                            </div>
                        <?php else: ?>
                            <?php if ($is_admin_user): ?>
                            <form method="post" id="horariosForm">
                                <input type="hidden" name="action" value="guardar_horarios">
                            <?php endif; ?>
                            
                            <div class="row">
                                
 <!-- ... código anterior ... -->
<?php 
// Ordenar partidos por fecha y hora
$matches_ordenados = $matches;
usort($matches_ordenados, function($a, $b) {
    // Verificar si existe 'fecha' en ambos arrays de forma segura
    $fechaA = isset($a['fecha']) && !empty($a['fecha']) ? $a['fecha'] : null;
    $fechaB = isset($b['fecha']) && !empty($b['fecha']) ? $b['fecha'] : null;
    
    if ($fechaA && $fechaB) {
        $cmp = strtotime($fechaA) <=> strtotime($fechaB);
        if ($cmp !== 0) return $cmp;
    } else if ($fechaA && !$fechaB) {
        return -1; // A tiene fecha, B no - A va primero
    } else if (!$fechaA && $fechaB) {
        return 1; // B tiene fecha, A no - B va primero
    }
    
    // Verificar si existe 'hora' de forma segura
    $horaA = isset($a['hora']) && !empty($a['hora']) ? $a['hora'] : null;
    $horaB = isset($b['hora']) && !empty($b['hora']) ? $b['hora'] : null;
    
    if ($horaA && $horaB) {
        return strtotime($horaA) <=> strtotime($horaB);
    }
    
    return $a['round'] <=> $b['round'];
});

$partidos_por_fecha = [];
foreach ($matches_ordenados as $m) {
    $fecha = isset($m['fecha']) && !empty($m['fecha']) ? date('d/m/Y', strtotime($m['fecha'])) : 'Sin fecha';
    if (!isset($partidos_por_fecha[$fecha])) {
        $partidos_por_fecha[$fecha] = [];
    }
    $partidos_por_fecha[$fecha][] = $m;
}
?>
                                
<?php foreach ($partidos_por_fecha as $fecha_str => $partidos_fecha): 
    $es_hoy = false;
    if ($fecha_str !== 'Sin fecha') {
        $fecha_php = DateTime::createFromFormat('d/m/Y', $fecha_str);
        // Verificar si se pudo crear la fecha correctamente
        if ($fecha_php) {
            $hoy = new DateTime();
            $es_hoy = $fecha_php->format('Y-m-d') === $hoy->format('Y-m-d');
        }
    }
?>
                                <div class="col-12 mb-4">
                                    <div class="card mb-3">
                                        <div class="card-header <?= $es_hoy ? 'bg-warning text-dark' : 'bg-secondary text-white' ?>">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <h6 class="mb-0">
                                                    <i class="fas fa-calendar-day"></i> 
                                                    <?= $fecha_str ?>
                                                    <?php if ($es_hoy): ?>
                                                        <span class="badge bg-danger ms-2">HOY</span>
                                                    <?php endif; ?>
                                                </h6>
                                                <span class="badge bg-light <?= $es_hoy ? 'text-dark' : 'text-secondary' ?>">
                                                    <?= count($partidos_fecha) ?> partido<?= count($partidos_fecha) !== 1 ? 's' : '' ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="card-body p-0">
                                            <?php foreach ($partidos_fecha as $m): 
                                                $id = $m['id'];
                                                $home = team_name($teams, $m['home_id']);
                                                $away = team_name($teams, $m['away_id']);
                                                $status = $m['status'];
                                            ?>
                                            <div class="match-card-horario p-3 border-bottom">
                                                <div class="row align-items-center">
                                                    <!-- INFORMACIÓN DEL PARTIDO -->
                                                    <div class="col-md-4">
                                                        <div class="d-flex align-items-center gap-2 mb-1">
                                                            <span class="badge bg-secondary">Ronda <?= $m['round'] ?></span>
                                                            <?php if (isset($m['group'])): ?>
                                                                <span class="badge bg-info">G<?= $m['group'] ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="team-name d-flex align-items-center gap-2 mb-1">
                                                            <div class="avatar-small bg-primary">L</div>
                                                            <strong><?= htmlspecialchars($home) ?></strong>
                                                        </div>
                                                        <div class="team-name d-flex align-items-center gap-2">
                                                            <div class="avatar-small bg-danger">V</div>
                                                            <strong><?= htmlspecialchars($away) ?></strong>
                                                        </div>
                                                    </div>
                                                    
                                                    <!-- FECHA Y HORA - SOLO ADMIN EDITA -->
                                                    <div class="col-md-4">
                                                        <?php if ($is_admin_user): ?>
                                                        <div class="row g-2">
                                                            <div class="col-6">
                                                                <label class="form-label small">Fecha</label>
                                                                <input type="date" 
                                                                       class="form-control form-control-sm"
                                                                       name="fecha[<?= $id ?>]"
                                                                       value="<?= htmlspecialchars(isset($m['fecha']) ? $m['fecha'] : '') ?>"
                                                                       data-match-id="<?= $id ?>">
                                                            </div>
                                                            <div class="col-6">
                                                                <label class="form-label small">Hora</label>
                                                                <input type="time" 
                                                                       class="form-control form-control-sm"
                                                                       name="hora[<?= $id ?>]"
                                                                       value="<?= htmlspecialchars(isset($m['hora']) ? $m['hora'] : '') ?>"
                                                                       data-match-id="<?= $id ?>">
                                                            </div>
                                                            <div class="col-12 mt-1">
                                                                <label class="form-label small">Lugar (opcional)</label>
                                                                <input type="text" 
                                                                       class="form-control form-control-sm"
                                                                       name="lugar[<?= $id ?>]"
                                                                       value="<?= htmlspecialchars(isset($m['lugar']) ? $m['lugar'] : '') ?>"
                                                                       placeholder="Cancha 1, Estadio, etc.">
                                                            </div>
                                                        </div>
                                                        <?php else: ?>
                                                        <!-- VISUALIZACIÓN PARA NO-ADMIN -->
                                                        <div class="text-center">
                                                            <?php if (isset($m['fecha']) && !empty($m['fecha'])): ?>
                                                                <div class="fw-bold text-primary">
                                                                    <i class="fas fa-calendar"></i> 
                                                                    <?= date('d/m', strtotime($m['fecha'])) ?>
                                                                </div>
                                                            <?php endif; ?>
                                                            <?php if (isset($m['hora']) && !empty($m['hora'])): ?>
                                                                <div class="fw-bold text-success">
                                                                    <i class="fas fa-clock"></i> 
                                                                    <?= date('H:i', strtotime($m['hora'])) ?>
                                                                </div>
                                                            <?php endif; ?>
                                                            <?php if (isset($m['lugar']) && !empty($m['lugar'])): ?>
                                                                <div class="small text-muted">
                                                                    <i class="fas fa-map-marker-alt"></i> 
                                                                    <?= htmlspecialchars($m['lugar']) ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                        <?php endif; ?>
                                                    </div>
                     <!-- ESTADO Y ACCIONES -->
                                               
<div class="col-md-4">
    <div class="d-flex flex-column gap-2">
        <span class="badge 
            <?= $status === 'jugado' ? 'bg-success' : 
               ($status === 'pendiente' ? 'bg-warning' : 
               ($status === 'suspendido' ? 'bg-secondary' : 
               ($status === 'walkover' ? 'bg-info' : 'bg-light'))) ?>">
            <?= $status === 'jugado' ? '✅ Jugado' : 
               ($status === 'pendiente' ? '⏳ Pendiente' : 
               ($status === 'suspendido' ? '⛔ Suspendido' : 
               ($status === 'walkover' ? '🚩 Walkover' : '📋 ' . ucfirst($status)))) ?>
        </span>
        
        <?php if ($is_admin_user): ?>
        <?php if (!in_array($status, ['jugado', 'walkover', 'suspendido'])): ?>
            <button type="button" 
                    class="btn btn-sm btn-primary"
                    onclick="irAResultados(<?= $id ?>)">
                <i class="fas fa-edit"></i> Cargar Resultado
            </button>
        <?php else: ?>
            <button type="button" 
                    class="btn btn-sm btn-outline-secondary"
                    onclick="irAResultados(<?= $id ?>)">
                <i class="fas fa-eye"></i> Ver Resultado
            </button>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>



                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <?php if ($is_admin_user): ?>
                            <!-- HERRAMIENTAS DE ASIGNACIÓN MASIVA -->
                            <div class="card mt-3">
                                <div class="card-header bg-info text-white">
                                    <h6 class="mb-0"></i> Asignación Masiva de Horarios</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3">
                                        <div class="col-md-3">
                                            <label class="form-label">Fecha Base</label>
                                            <input type="date" id="fechaBase" class="form-control">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Hora Inicio</label>
                                            <input type="time" id="horaInicio" class="form-control" value="09:00">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Intervalo (min)</label>
                                            <input type="number" id="intervalo" class="form-control" value="60" min="15" step="15">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Lugar</label>
                                            <input type="text" id="lugarBase" class="form-control" placeholder="Ej: Cancha 1">
                                        </div>
                                        <div class="col-md-2 d-flex align-items-end">
                                            <button type="button" class="btn btn-info w-100" onclick="asignarHorariosMasivo()">
                                                <i class="fas fa-magic"></i> Aplicar
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mt-3 text-end">
                                <button type="submit" class="btn btn-sport">
                                    <i class="fas fa-save"></i> Guardar Horarios
                                </button>
                                <button type="button" class="btn btn-outline-danger" onclick="borrarTodosHorarios()">
                                    <i class="fas fa-trash"></i> Borrar Horarios
                                 </button>
                            </div>
                            </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- PESTAÑA ADMINISTRACIÓN -->
            <?php if ($is_admin_user): ?>
            <div class="tab-pane fade" id="admin">
                <div class="row">
                    <!-- GESTIÓN DE EQUIPOS -->
                    <div class="col-md-6 mb-4">
                        <div class="card card-sport h-100">
                            <div class="card-header-sport">
                                <h5 class="mb-0"></i> Gestión de Equipos</h5>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($teams)): ?>
                                <div class="alert alert-info mb-3">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <strong>Equipos actuales (<?= count($teams) ?>):</strong>
                                        </div>
                                      </div>
                                    <div class="mt-2">
                                        <?php foreach ($teams as $team): ?>
                                            <span class="badge bg-primary me-1 mb-1"><?= htmlspecialchars($team['nombre']) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <form method="post" id="teamsForm">
                                    <input type="hidden" name="action" value="gestionar_equipos">
                                    
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Cantidad de equipos</label>
                                        <div class="input-group">
                                            <input type="number" class="form-control" id="teamCount" 
                                                   name="team_count" min="2" max="50" 
                                                   value="<?= count($teams) ?: 4 ?>">
                                                           <button type="button" class="btn btn-outline-danger" onclick="clearAllTeams()" title="Limpiar todos los nombres">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                            <button type="button" class="btn btn-outline-primary" onclick="cargarEquiposActuales()" title="Cargar equipos actuales para editar">
        <i class="fas fa-edit"></i>
    </button>
                                        </div>
                                        <small class="text-muted">Cambia la cantidad y presiona Enter para actualizar</small>
                                    </div>
                                    
                                   <div id="teamsContainer" class="mb-3">
    <?php 
    $team_count = count($teams) ?: 4;
    
    // Si hay datos de formulario guardados en sesión (por error), usarlos
    if (isset($_SESSION['form_data'])) {
        $team_count = $_SESSION['form_data']['team_count'] ?? $team_count;
    }
    
    for ($i = 1; $i <= $team_count; $i++): 
        $team_name = "";
        
        // Primero intentar con datos de sesión (si hubo error)
        if (isset($_SESSION['form_data']['team_names'][$i])) {
            $team_name = htmlspecialchars($_SESSION['form_data']['team_names'][$i]);
        } 
        // Si no, buscar en equipos existentes
        elseif (isset($teams[$i-1])) {
            $team_name = htmlspecialchars($teams[$i-1]['nombre']);
        }
    ?>
    <div class="mb-2">
        <label class="form-label small">Equipo <?= $i ?></label>
        <input type="text" class="form-control form-control-sm" 
               name="team_name_<?= $i ?>" 
               value="<?= $team_name ?>" 
               placeholder="Nombre del equipo" 
               required>
    </div>
    <?php endfor; 
    
    // Limpiar datos de sesión después de usarlos
    if (isset($_SESSION['form_data'])) {
        unset($_SESSION['form_data']);
    }
    ?>
</div>
                                    
                                    <button type="submit" class="btn btn-sport w-100">
                                        <i class="fas fa-save"></i> Guardar Equipos
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- CONFIGURACIÓN DEL TORNEO -->
                    <div class="col-md-6 mb-4">
                        <div class="card card-sport h-100">
                            <div class="card-header-sport">
                                <h5 class="mb-0"></i> Configuración</h5>
                            </div>
                            <div class="card-body">
                                <!-- Cambiar deporte -->
                                <div class="mb-4">
                                    <h6>Cambiar Deporte</h6>
                                    <form method="post" class="mb-3">
                                        <input type="hidden" name="action" value="cambiar_deporte">
                                        <div class="input-group">
                                            <select class="form-select" name="deporte">
                                                <option value="futbol" <?= $current_deporte === 'futbol' ? 'selected' : '' ?>>⚽ Beach Futbol</option>
                                                <option value="voley" <?= $current_deporte === 'voley' ? 'selected' : '' ?>>🏐 Beach Voley</option>
                                                <option value="handball" <?= $current_deporte === 'handball' ? 'selected' : '' ?>>🤾 Beach Handball</option>
                                            </select>
                                            <button type="submit" class="btn btn-sport">Cambiar</button>
                                        </div>
                                    </form>
                                </div>
                                
                                <!-- Generar fixture -->
                                <div class="mb-4">
                                    <h6>Generar Fixture</h6>
                                    <div class="alert alert-light">
                                        <strong>Configuración actual:</strong><br>
                                        Tipo: <?= $fixture_config['type'] === 'grupos' ? 'Por grupos' : 'Todos contra todos' ?>
                                        <?php if ($fixture_config['type'] === 'grupos'): ?>
                                            <br>Grupos: <?= $fixture_config['groups'] ?? 2 ?>
                                        <?php endif; ?>
                                    </div>
                                    <button type="button" class="btn btn-sport w-100" data-bs-toggle="modal" data-bs-target="#fixtureModal">
                                        <i class="fas fa-calendar-alt"></i> Generar Nuevo Fixture
                                    </button>
                                </div>
                                
                                <!-- Resetear resultados -->
                                <div>
                                    <h6>Herramientas</h6>
                                    <form method="post" onsubmit="return confirm('⚠️ ¿Estás seguro de resetear todos los resultados? Esta acción no se puede deshacer.');">
                                        <input type="hidden" name="action" value="resetear">
                                        <button type="submit" class="btn btn-danger w-100">
                                            <i class="fas fa-trash-alt"></i> Resetear Resultados
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal para generar fixture -->
    <div class="modal fade" id="fixtureModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Generar Nuevo Fixture</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post">
                    <input type="hidden" name="action" value="generar_fixture">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Tipo de fixture</label>
                            <select class="form-select" name="fixture_type" id="fixtureType" onchange="toggleGroupsField()">
                                <option value="todos_contra_todos" <?= $fixture_config['type'] === 'todos_contra_todos' ? 'selected' : '' ?>>Todos contra todos</option>
                                <option value="grupos" <?= $fixture_config['type'] === 'grupos' ? 'selected' : '' ?>>Por grupos</option>
                            </select>
                        </div>
                        <div class="mb-3" id="groupsField" style="display: <?= $fixture_config['type'] === 'grupos' ? 'block' : 'none' ?>;">
                            <label class="form-label fw-semibold">Número de grupos</label>
                            <input type="number" class="form-control" name="groups" min="2" max="8" value="<?= $fixture_config['groups'] ?? 2 ?>">
                        </div>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i> Esta acción reemplazará el fixture actual y todos los resultados.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Generar Fixture</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>
    <script>

// Gestión dinámica de equipos
document.getElementById('teamCount').addEventListener('change', function() {
    updateTeamsContainer(this.value);
});

// Inicializar el contenedor de equipos
function updateTeamsContainer(count) {
    const container = document.getElementById('teamsContainer');
    count = parseInt(count) || 2;
    
    if (count < 2) {
        count = 2; // Mínimo 2 equipos
        document.getElementById('teamCount').value = 2;
    }
    
    if (count > 50) {
        count = 50; // Máximo 50 equipos
        document.getElementById('teamCount').value = 50;
        showToast('❌ Límite máximo de 50 equipos', 'error');
    }
    
    // Guardar valores actuales
    const currentValues = {};
    for (let i = 1; i <= count; i++) {
        const existingInput = document.querySelector(`input[name="team_name_${i}"]`);
        if (existingInput) {
            currentValues[i] = existingInput.value;
        }
    }
    
    // Generar nuevos campos
    let html = '';
    for (let i = 1; i <= count; i++) {
        html += `
            <div class="mb-2">
                <label class="form-label small">Equipo ${i}</label>
                <input type="text" class="form-control form-control-sm" 
                       name="team_name_${i}" 
                       value="${currentValues[i] || ''}"
                       placeholder="Nombre del equipo"
                       required>
            </div>
        `;
    }
    
    container.innerHTML = html;
}

// Función para limpiar todos los equipos
function clearAllTeams() {
    if (confirm('¿Borrar todos los nombres de equipos? Esto solo limpiará los campos del formulario.')) {
        const inputs = document.querySelectorAll('#teamsContainer input');
        let hasValues = false;
        
        inputs.forEach(input => {
            if (input.value.trim() !== '') {
                hasValues = true;
            }
            input.value = '';
        });
        
        if (hasValues) {
            showToast('Nombres de equipos limpiados');
        }
    }
}
        
        // Función para limpiar todos los equipos
        function clearAllTeams() {
            if (confirm('¿Borrar todos los nombres de equipos? Esto solo limpiará los campos del formulario.')) {
                const inputs = document.querySelectorAll('#teamsContainer input');
                inputs.forEach(input => {
                    input.value = '';
                });
                showToast('Nombres de equipos limpiados');
            }
        }
        
        // Función para duplicar el último equipo
        function duplicateLastTeam() {
            const teamCountInput = document.getElementById('teamCount');
            const currentCount = parseInt(teamCountInput.value);
            
            if (currentCount < 50) {
                // Obtener el último nombre
                const lastInput = document.querySelector(`input[name="team_name_${currentCount}"]`);
                const lastName = lastInput ? lastInput.value : '';
                
                // Incrementar el contador
                teamCountInput.value = currentCount + 1;
                
                // Actualizar el contenedor
                updateTeamsContainer(teamCountInput.value);
                
                // Copiar el último nombre al nuevo campo
                setTimeout(() => {
                    const newInput = document.querySelector(`input[name="team_name_${currentCount + 1}"]`);
                    if (newInput) {
                        newInput.value = lastName;
                    }
                }, 10);
                
                showToast('Se agregó un equipo nuevo');
            } else {
                showToast('❌ Límite máximo de 50 equipos', 'error');
            }
        }

function cargarEquiposActuales() {
    // PASO 1: Verificar que hay equipos
    <?php if (empty($teams)): ?>
    alert('No hay equipos para editar');
    return;
    <?php endif; ?>
    
    // PASO 2: Crear array con los nombres
    const equiposNombres = [
        <?php foreach ($teams as $i => $team): ?>
        "<?= htmlspecialchars($team['nombre'], ENT_QUOTES) ?>"<?= ($i < count($teams) - 1) ? ',' : '' ?>
        <?php endforeach; ?>
    ];
    
    // PASO 3: Actualizar el formulario
    document.getElementById('teamCount').value = equiposNombres.length;
    
    const container = document.getElementById('teamsContainer');
    let html = '';
    
    equiposNombres.forEach((nombre, index) => {
        html += `
            <div class="mb-2">
                <label class="form-label small">Equipo ${index + 1}</label>
                <input type="text" class="form-control form-control-sm" 
                       name="team_name_${index + 1}" 
                       value="${nombre}"
                       placeholder="Nombre del equipo" 
                       required>
            </div>
        `;
    });
    
    container.innerHTML = html;
    
    // Opcional: Hacer scroll
    container.scrollIntoView({behavior: 'smooth'});
}

// VERDE PARA GANADOR, AMARILLO PARA EMPATE - VERSIÓN MEJORADA
function colorGanadorSimple(matchId) {
    const tarjeta = document.querySelector(`.match-card-sport input[data-match-id="${matchId}"]`)?.closest('.match-card-sport');
    if (!tarjeta) return;
    
    // Quitar TODAS las clases de color anteriores
    tarjeta.classList.remove('ganador-local', 'ganador-visitante', 'empate');
    
    // Obtener estado
    const estado = document.querySelector(`select[name="status[${matchId}]"]`)?.value;
    
    if (estado === 'jugado') {
        // Verificar quién gana
        const golesLocal = document.querySelector(`input[name="home_goals[${matchId}]"]`);
        const golesVisitante = document.querySelector(`input[name="away_goals[${matchId}]"]`);
        const setsLocal = document.querySelector(`input[name="home_sets[${matchId}]"]`);
        const setsVisitante = document.querySelector(`input[name="away_sets[${matchId}]"]`);
        
        if (golesLocal && golesVisitante) {
            // Fútbol o Handball - ¡IMPORTANTE! Verificar que NO estén vacíos
            const gl = golesLocal.value.trim();
            const gv = golesVisitante.value.trim();
            
            // Solo aplicar si AMBOS campos tienen valor
            if (gl !== '' && gv !== '') {
                const glNum = parseInt(gl) || 0;
                const gvNum = parseInt(gv) || 0;
                
                if (glNum > gvNum) {
                    tarjeta.classList.add('ganador-local'); // Local gana -> AZUL
                } else if (gvNum > glNum) {
                    tarjeta.classList.add('ganador-visitante'); // Visitante gana -> ROJO
                } else {
                    tarjeta.classList.add('empate'); // Empate -> AMARILLO
                }
            }
        } else if (setsLocal && setsVisitante) {
            // Vóley - ¡IMPORTANTE! Verificar que NO estén vacíos
            const sl = setsLocal.value.trim();
            const sv = setsVisitante.value.trim();
            
            if (sl !== '' && sv !== '') {
                const setsL = sl.split(',').map(s => parseInt(s.trim()) || 0);
                const setsV = sv.split(',').map(s => parseInt(s.trim()) || 0);
                
                let setsGanadosL = 0;
                let setsGanadosV = 0;
                
                for (let i = 0; i < Math.max(setsL.length, setsV.length); i++) {
                    const local = setsL[i] || 0;
                    const visitante = setsV[i] || 0;
                    if (local > visitante) setsGanadosL++;
                    else if (visitante > local) setsGanadosV++;
                }
                
                if (setsGanadosL > setsGanadosV) {
                    tarjeta.classList.add('ganador-local'); // Local gana -> AZUL
                } else if (setsGanadosV > setsGanadosL) {
                    tarjeta.classList.add('ganador-visitante'); // Visitante gana -> ROJO
                }
                // En vóley generalmente no hay empate, pero si quieres agregar:
                // else { tarjeta.classList.add('empate'); }
            }
        }
    }
}

// INICIALIZAR AL CARGAR
document.addEventListener('DOMContentLoaded', function() {
    // Aplicar a todos los partidos
    document.querySelectorAll('input[data-match-id]').forEach(input => {
        const matchId = input.dataset.matchId;
        // Esperar un momento para que carguen todos los datos
        setTimeout(() => colorGanadorSimple(matchId), 100);
    });
    
    // ESCUCHAR CAMBIOS EN INPUTS
    document.addEventListener('input', function(e) {
        if (e.target.dataset?.matchId) {
            // Pequeño delay para que se actualice el valor
            setTimeout(() => colorGanadorSimple(e.target.dataset.matchId), 50);
        }
    });
    
    // ESCUCHAR CAMBIOS EN ESTADO
    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('match-status')) {
            colorGanadorSimple(e.target.dataset.matchId);
        }
    });
});

    // Toggle para editar resultados
        const toggle = document.getElementById('toggleEditPlayed');
        if (toggle) {
            toggle.addEventListener('change', function() {
                const inputs = document.querySelectorAll('#resultados input, #resultados select, #resultados textarea');
                const isChecked = this.checked;
                
                inputs.forEach(input => {
                    if (!isChecked) {
                        // Si el partido tiene estado final (jugado, walkover o suspendido), hacer readonly
                        const matchId = input.dataset.matchId;
                        if (matchId) {
                            const statusSelect = document.querySelector(`select[name="status[${matchId}]"]`);
                            if (statusSelect) {
                                const finalStatuses = ['jugado', 'walkover', 'suspendido'];
                                if (finalStatuses.includes(statusSelect.value)) {
                                    input.setAttribute('readonly', 'readonly');
                                    input.classList.add('readonly-look');
                                }
                            }
                        }
                    } else {
                        // Permitir edición
                        input.removeAttribute('readonly');
                        input.classList.remove('readonly-look');
                    }
                });
            });
            
            // Aplicar estado inicial
            setTimeout(() => {
                toggle.dispatchEvent(new Event('change'));
            }, 100);
        }
        
        // Mostrar/ocultar walkover dinámicamente
        document.querySelectorAll('.match-status').forEach(select => {
            select.addEventListener('change', function() {
                const matchId = this.dataset.matchId;
                const walkoverDiv = document.getElementById('walkover-' + matchId);
                if (walkoverDiv) {
                    walkoverDiv.style.display = this.value === 'walkover' ? 'block' : 'none';
                }
            });
        });
        
        // Mostrar/ocultar campo de grupos
        function toggleGroupsField() {
            const fixtureType = document.getElementById('fixtureType').value;
            const groupsField = document.getElementById('groupsField');
            groupsField.style.display = fixtureType === 'grupos' ? 'block' : 'none';
        }
        
        // Función para exportar PDF
        function exportToPDF() {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF();
            
            // Configuración
            const pageWidth = doc.internal.pageSize.getWidth();
            const margin = 15;
            
            // Título
            doc.setFontSize(16);
            doc.setFont('helvetica', 'bold');
            doc.text('<?= addslashes($TORNEO) ?>', pageWidth / 2, 20, { align: 'center' });
            
            // Información del torneo
            doc.setFontSize(10);
            doc.setFont('helvetica', 'normal');
            doc.text(`Deporte: ${getDeporteName('<?= $current_deporte ?>')}`, margin, 35);
            doc.text(`Fecha: ${new Date().toLocaleDateString('es-ES')}`, pageWidth - margin, 35, { align: 'right' });
            
            // Tabla de posiciones
            doc.setFontSize(12);
            doc.setFont('helvetica', 'bold');
            doc.text('Tabla de Posiciones', margin, 45);
            
            // Obtener datos de la tabla
            const tableData = [];
            const tableRows = document.querySelectorAll('.table-sport tbody tr');
            
            tableRows.forEach(row => {
                const cells = row.querySelectorAll('td');
                const rowData = [];
                cells.forEach(cell => rowData.push(cell.innerText.trim()));
                tableData.push(rowData);
            });
            
            // Crear tabla
            doc.autoTable({
                startY: 50,
                head: [['Pos', 'Equipo', 'Pts', 'PJ', 'PG', 'PE', 'PP', 'DG', 'GF', 'GC']],
                body: tableData,
                theme: 'grid',
                headStyles: { fillColor: [26, 35, 126] },
                styles: { fontSize: 8 }
            });
            
            // Resultados por ronda
            let yPos = doc.lastAutoTable.finalY + 10;
            
            if (yPos > 280) {
                doc.addPage();
                yPos = 20;
            }
            
            doc.setFontSize(12);
            doc.setFont('helvetica', 'bold');
            doc.text('Resultados por Ronda', margin, yPos);
            yPos += 5;
            
            doc.setFontSize(9);
            doc.setFont('helvetica', 'normal');
            
            // Agrupar resultados por ronda
            const resultsByRound = {};
            document.querySelectorAll('.match-card-sport').forEach(match => {
                const round = match.querySelector('.badge.bg-secondary').innerText.replace('Ronda ', '');
                const teams = match.querySelectorAll('.team-name');
                const homeTeam = teams[0].innerText.replace('L', '').trim();
                const awayTeam = teams[1].innerText.replace('V', '').trim();
                
                if (!resultsByRound[round]) resultsByRound[round] = [];
                resultsByRound[round].push(`${homeTeam} vs ${awayTeam}`);
            });
            
            // Agregar resultados al PDF
            Object.keys(resultsByRound).sort().forEach(round => {
                if (yPos > 280) {
                    doc.addPage();
                    yPos = 20;
                }
                
                doc.setFont('helvetica', 'bold');
                doc.text(`Ronda ${round}:`, margin, yPos);
                yPos += 7;
                
                doc.setFont('helvetica', 'normal');
                resultsByRound[round].forEach(result => {
                    if (yPos > 280) {
                        doc.addPage();
                        yPos = 20;
                    }
                    
                    doc.text(`• ${result}`, margin + 5, yPos);
                    yPos += 5;
                });
                
                yPos += 5;
            });
            
            // Guardar PDF
            doc.save('<?= $TORNEO ?>_Reporte_<?= date('Y-m-d') ?>.pdf');
            showToast('✅ PDF generado exitosamente');
        }
        
   function getDeporteName(code) {
            switch(code) {
                case 'futbol': return '⚽ Beach Futbol';
                case 'voley': return '🏐 Beach Voley';
                case 'handball': return '🤾 Beach Handball';
                default: return code;
            }
        }
        
        // Función de toast para notificaciones - VERSIÓN MEJORADA
        function showToast(message, type = 'info') {
            const toast = document.createElement('div');
            const bgColor = type === 'error' ? 'alert-danger' : 'alert-info';
            toast.className = `alert ${bgColor} alert-dismissible fade show shadow`;
            
            // Posicionamiento responsive
            const isMobile = window.innerWidth < 768;
            toast.style.cssText = isMobile 
                ? 'position: fixed; top: 10px; left: 10px; right: 10px; z-index: 9999; margin: 0;'
                : 'position: fixed; top: 20px; right: 20px; z-index: 9999; max-width: 350px;';
            
            toast.innerHTML = `
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <span>${message}</span>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            `;
            
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }
        
        // Activar pestaña guardada en localStorage - VERSIÓN CORREGIDA
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof bootstrap === 'undefined') {
                console.error('Bootstrap no está cargado correctamente');
                return;
            }
            
            function activarPestana(tabId) {
                try {
                    const tabElement = document.querySelector(`#${tabId}-tab`);
                    if (tabElement) {
                        const tab = new bootstrap.Tab(tabElement);
                        tab.show();
                        return true;
                    }
                    return false;
                } catch (error) {
                    console.error('Error al activar pestaña:', error);
                    return false;
                }
            }
            
            const savedTab = localStorage.getItem('activeTab');
            if (savedTab) {
                setTimeout(() => {
                    const activated = activarPestana(savedTab);
                    if (!activated) {
                        console.warn(`No se pudo activar la pestaña: ${savedTab}`);
                        activarPestana('equipos');
                    }
                }, 100);
            }
            
            document.querySelectorAll('#mainTabs .nav-link').forEach(tab => {
                tab.addEventListener('shown.bs.tab', function(event) {
                    try {
                        const tabId = event.target.id.replace('-tab', '');
                        localStorage.setItem('activeTab', tabId);
                        window.dispatchEvent(new CustomEvent('tabChanged', { 
                            detail: { tabId: tabId } 
                        }));
                    } catch (error) {
                        console.error('Error guardando pestaña activa:', error);
                    }
                });
            });
        });

        // Validación del formulario de equipos
        document.getElementById('teamsForm')?.addEventListener('submit', function(e) {
            const inputs = document.querySelectorAll('#teamsContainer input[type="text"]');
            let validTeams = 0;
            let emptyTeams = [];
            
            inputs.forEach((input, index) => {
                if (input.value.trim() !== '') {
                    validTeams++;
                } else {
                    emptyTeams.push(index + 1);
                }
            });
            
            if (validTeams < 2) {
                e.preventDefault();
                let message = 'Debe completar al menos 2 nombres de equipo.';
                if (emptyTeams.length > 0 && emptyTeams.length < 10) {
                    message += ` Equipos vacíos: ${emptyTeams.join(', ')}`;
                }
                showToast(message, 'error');
                
                inputs.forEach(input => {
                    if (input.value.trim() === '') {
                        input.style.transition = 'all 0.3s ease';
                        input.style.borderColor = '#dc3545';
                        input.style.boxShadow = '0 0 0 0.2rem rgba(220, 53, 69, 0.25)';
                        input.classList.add('shake-error');
                        setTimeout(() => input.classList.remove('shake-error'), 600);
                    }
                });
            }
        });

        // Quitar resaltado cuando se empieza a escribir
        document.addEventListener('input', function(e) {
            if (e.target.matches('#teamsContainer input[type="text"]')) {
                e.target.style.transition = 'all 0.3s ease';
                e.target.style.borderColor = '';
                e.target.style.boxShadow = '';
            }
        });

        // FUNCIONES PARA PESTAÑA DE HORARIOS
        function ordenarPorFechaHora() {
            showToast('Partidos ordenados por fecha y hora');
            setTimeout(() => location.reload(), 1000);
        }

        function asignarHorariosMasivo() {
            const fecha = document.getElementById('fechaBase')?.value;
            const horaInicio = document.getElementById('horaInicio')?.value;
            const intervalo = parseInt(document.getElementById('intervalo')?.value || '0');
            const lugar = document.getElementById('lugarBase')?.value;
            
            if (!fecha || !horaInicio || !intervalo) {
                showToast('Por favor completa todos los campos: fecha, hora e intervalo', 'error');
                return;
            }
            
            if (intervalo < 15) {
                showToast('El intervalo debe ser de al menos 15 minutos', 'error');
                return;
            }
            
            try {
                const [hora, minuto] = horaInicio.split(':').map(Number);
                if (isNaN(hora) || isNaN(minuto)) {
                    throw new Error('Hora inválida');
                }
                
                let minutosActuales = hora * 60 + minuto;
                
                document.querySelectorAll('input[name^="fecha["]').forEach(input => {
                    input.value = fecha;
                });
                
                document.querySelectorAll('input[name^="hora["]').forEach((input, index) => {
                    const totalMinutos = minutosActuales + (index * intervalo);
                    const horaPartido = Math.floor(totalMinutos / 60) % 24;
                    const minutoPartido = totalMinutos % 60;
                    const horaStr = horaPartido.toString().padStart(2, '0');
                    const minStr = minutoPartido.toString().padStart(2, '0');
                    input.value = `${horaStr}:${minStr}`;
                });
                
                if (lugar && lugar.trim() !== '') {
                    document.querySelectorAll('input[name^="lugar["]').forEach(input => {
                        input.value = lugar;
                    });
                }
                
                showToast('✅ Horarios asignados automáticamente. No olvides guardar los cambios.');
            } catch (error) {
                showToast('Error al asignar horarios: ' + error.message, 'error');
            }
        }

        function irAResultados(matchId) {
            const resultadosTab = document.querySelector('#resultados-tab');
            if (!resultadosTab) {
                console.error('Pestaña de resultados no encontrada');
                return;
            }
            
            try {
                const tab = new bootstrap.Tab(resultadosTab);
                tab.show();
            } catch (error) {
                resultadosTab.click();
            }
            
            setTimeout(() => {
                const partido = document.querySelector(`input[data-match-id="${matchId}"]`);
                if (partido) {
                    const card = partido.closest('.match-card-sport');
                    if (card) {
                        const supportsSmooth = 'scrollBehavior' in document.documentElement.style;
                        
                        if (supportsSmooth) {
                            card.scrollIntoView({
                                behavior: 'smooth',
                                block: 'center'
                            });
                        } else {
                            const elementPosition = card.getBoundingClientRect().top + window.pageYOffset;
                            const offsetPosition = elementPosition - (window.innerHeight / 2);
                            window.scrollTo({
                                top: offsetPosition,
                                behavior: 'auto'
                            });
                        }
                        
                        const originalBoxShadow = card.style.boxShadow;
                        card.style.transition = 'box-shadow 0.3s ease';
                        card.style.boxShadow = '0 0 0 3px rgba(13, 27, 92, 0.5), 0 4px 12px rgba(13, 27, 92, 0.3)';
                        setTimeout(() => {
                            card.style.boxShadow = originalBoxShadow;
                        }, 2000);
                    }
                }
            }, 300);
        }

        // FUNCIÓN PARA BORRAR TODOS LOS HORARIOS
        function borrarTodosHorarios() {
            if (confirm('⚠️ ¿Estás seguro de borrar TODOS los horarios de todos los partidos?\n\nEsta acción eliminará todas las fechas, horas y lugares asignados.')) {
                try {
                    document.querySelectorAll('input[name^="fecha["]').forEach(input => {
                        input.value = '';
                    });
                    
                    document.querySelectorAll('input[name^="hora["]').forEach(input => {
                        input.value = '';
                    });
                    
                    document.querySelectorAll('input[name^="lugar["]').forEach(input => {
                        input.value = '';
                    });
                    
                    showToast('✅ Todos los horarios han sido borrados. Recuerda guardar los cambios.');
                } catch (error) {
                    showToast('Error al borrar horarios: ' + error.message, 'error');
                }
            }
        }

        // Prevenir zoom en iOS
        if (navigator.userAgent.match(/iPhone|iPad|iPod/i)) {
            document.addEventListener('DOMContentLoaded', function() {
                document.querySelectorAll('input, select, textarea').forEach(element => {
                    if (!element.style.fontSize || parseInt(element.style.fontSize) < 16) {
                        element.style.fontSize = '16px';
                    }
                });
            });
        }

        // CSS adicional para animaciones y mobile
        const mobileStyle = document.createElement('style');
        mobileStyle.textContent = `
            @keyframes shake {
                0%, 100% { transform: translateX(0); }
                25% { transform: translateX(-10px); }
                75% { transform: translateX(10px); }
            }
            
            .shake-error {
                animation: shake 0.6s ease;
            }
            
            @media (max-width: 768px) {
                .match-card-sport {
                    margin-bottom: 1rem;
                    font-size: 0.9rem;
                }
                
                .match-card-sport input {
                    font-size: 16px !important;
                }
                
                .btn-group {
                    flex-direction: column;
                }
                
                .btn-group .btn {
                    width: 100%;
                    margin-bottom: 0.5rem;
                }
            }
            
            .nav-link {
                transition: all 0.3s ease;
            }
            
            @media (max-width: 576px) {
                #mainTabs {
                    flex-wrap: wrap;
                }
                
                #mainTabs .nav-link {
                    font-size: 0.85rem;
                    padding: 0.5rem 0.75rem;
                }
            }
        `;
        document.head.appendChild(mobileStyle);

        console.log('✅ Sistema de correcciones mobile cargado correctamente');

    </script>
<?php endif; ?>
</body>
</html>