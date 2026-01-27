<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();

/* =========================
   CONFIG
   ========================= */
$TORNEO = "CopaSayBeach";
$EQUIPOS_FILE = __DIR__ . "/equipos.json";
$RESULTADOS_FILE = __DIR__ . "/resultados.json";
$USUARIOS_FILE = __DIR__ . "/usuarios.json";
$FIXTURE_CONFIG_FILE = __DIR__ . "/fixture_config.json";

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
   FIXTURE - Round Robin (Circle method)
   ========================= */
function generate_round_robin($teams, $type = 'todos_contra_todos', $groups = 1) {
  $n = count($teams);
  if ($n < 2) return [];
  
  // Guardar configuración del fixture
  $config = [
    'type' => $type,
    'groups' => $groups,
    'teams_count' => $n,
    'generated_at' => date('Y-m-d H:i:s')
  ];
  global $FIXTURE_CONFIG_FILE;
  write_json($FIXTURE_CONFIG_FILE, $config);
  
  if ($type === 'grupos' && $groups > 1) {
    return generate_groups_fixture($teams, $groups);
  }
  
  // Todos contra todos (existente)
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
      
      $matches[] = [
        "id" => $matchId++,
        "round" => $r,
        "home_id" => $home,
        "away_id" => $away,
        "home_goals" => null,
        "away_goals" => null,
        "home_yellow" => 0,
        "home_red" => 0,
        "away_yellow" => 0,
        "away_red" => 0,
        "played" => false,
        "notes" => "",
        "status" => "pendiente", // pendiente, jugado, walkover, suspendido
        "walkover_winner" => null // id del equipo que gana por walkover
      ];
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
function generate_groups_fixture($teams, $groups) {
  shuffle($teams); // Mezclar para distribución aleatoria
  $teams_per_group = ceil(count($teams) / $groups);
  
  $grouped_teams = array_chunk($teams, $teams_per_group);
  $all_matches = [];
  $matchId = 1;
  $roundOffset = 0;
  
  for ($g = 0; $g < $groups; $g++) {
    $group_teams = $grouped_teams[$g] ?? [];
    if (count($group_teams) < 2) continue;
    
    // Generar todos contra todos dentro del grupo
    $ids = array_map(fn($t) => (int)$t['id'], $group_teams);
    
    if (count($ids) % 2 === 1) {
      $ids[] = 0;
    }
    
    $n = count($ids);
    $rounds = $n - 1;
    $half = (int)($n / 2);
    
    $list = $ids;
    
    for ($r = 1; $r <= $rounds; $r++) {
      for ($i = 0; $i < $half; $i++) {
        $home = $list[$i];
        $away = $list[$n - 1 - $i];
        
        if ($home === 0 || $away === 0) continue;
        
        if ($r % 2 === 0) {
          [$home, $away] = [$away, $home];
        }
        
        $all_matches[] = [
          "id" => $matchId++,
          "round" => $r + $roundOffset,
          "group" => $g + 1,
          "home_id" => $home,
          "away_id" => $away,
          "home_goals" => null,
          "away_goals" => null,
          "home_yellow" => 0,
          "home_red" => 0,
          "away_yellow" => 0,
          "away_red" => 0,
          "played" => false,
          "notes" => "",
          "status" => "pendiente",
          "walkover_winner" => null
        ];
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

/* =========================
   TABLA CON WALKOVER
   ========================= */
function calculate_standings($teams, $matches) {
  $table = [];
  foreach ($teams as $t) {
    $id = (int)$t['id'];
    $table[$id] = [
      "id" => $id,
      "nombre" => $t["nombre"],
      "pts" => 0,
      "pj" => 0,
      "pg" => 0,
      "pe" => 0,
      "pp" => 0,
      "gf" => 0,
      "gc" => 0,
      "dg" => 0,
      "ta" => 0,
      "tr" => 0,
      "fair" => 0,
      "wo" => 0, // walkovers ganados
      "wo_lost" => 0 // walkovers perdidos
    ];
  }
  
  foreach ($matches as $m) {
    if (empty($m["played"]) && ($m["status"] ?? "pendiente") !== "walkover") continue;
    
    $h  = (int)$m["home_id"];
    $a  = (int)$m["away_id"];
    
    if (!isset($table[$h]) || !isset($table[$a])) continue;
    
    // WALKOVER (no se presenta un equipo)
    if (($m["status"] ?? "pendiente") === "walkover") {
      $winner = (int)($m["walkover_winner"] ?? 0);
      if ($winner === 0) continue; // Si no hay ganador definido, saltar
      
      $loser = ($winner === $h) ? $a : $h;
      
      $table[$winner]["pts"] += 3;
      $table[$winner]["pj"]++;
      $table[$winner]["pg"]++;
      $table[$winner]["wo"]++;
      
      $table[$loser]["pj"]++;
      $table[$loser]["pp"]++;
      $table[$loser]["wo_lost"]++;
      
      $table[$winner]["gf"] += 3; // Convención: 3-0 en walkover
      $table[$loser]["gc"] += 3;
      continue;
    }
    
    // PARTIDO NORMAL JUGADO
    $hg = (int)($m["home_goals"] ?? 0);
    $ag = (int)($m["away_goals"] ?? 0);
    $hy = (int)($m["home_yellow"] ?? 0);
    $hr = (int)($m["home_red"] ?? 0);
    $ay = (int)($m["away_yellow"] ?? 0);
    $ar = (int)($m["away_red"] ?? 0);
    
    $table[$h]["pj"]++;
    $table[$a]["pj"]++;
    
    $table[$h]["gf"] += $hg;
    $table[$h]["gc"] += $ag;
    $table[$a]["gf"] += $ag;
    $table[$a]["gc"] += $hg;
    
    $table[$h]["ta"] += $hy;
    $table[$h]["tr"] += $hr;
    $table[$a]["ta"] += $ay;
    $table[$a]["tr"] += $ar;
    
    if ($hg > $ag) {
      $table[$h]["pts"] += 3; $table[$h]["pg"]++; $table[$a]["pp"]++;
    } elseif ($hg < $ag) {
      $table[$a]["pts"] += 3; $table[$a]["pg"]++; $table[$h]["pp"]++;
    } else {
      $table[$h]["pts"] += 1; $table[$a]["pts"] += 1;
      $table[$h]["pe"]++; $table[$a]["pe"]++;
    }
  }
  
  foreach ($table as &$row) {
    $row["dg"]   = $row["gf"] - $row["gc"];
    $row["fair"] = $row["ta"] * 1 + $row["tr"] * 3;
  }
  unset($row);
  
  $arr = array_values($table);
  usort($arr, function($x, $y) {
    if ($x["pts"] !== $y["pts"]) return $y["pts"] <=> $x["pts"];
    if ($x["dg"]  !== $y["dg"])  return $y["dg"]  <=> $x["dg"];
    if ($x["gf"]  !== $y["gf"])  return $y["gf"]  <=> $x["gf"];
    if ($x["fair"]!== $y["fair"])return $x["fair"]<=> $y["fair"];
    if ($x["wo"]  !== $y["wo"])  return $y["wo"]  <=> $x["wo"]; // Más walkovers ganados es mejor
    return strcmp($x["nombre"], $y["nombre"]);
  });
  
  return $arr;
}

/* =========================
   LOAD
   ========================= */
$teams   = read_json($EQUIPOS_FILE, []);
$matches = read_json($RESULTADOS_FILE, []);
$users   = read_json($USUARIOS_FILE, []);
$fixture_config = read_json($FIXTURE_CONFIG_FILE, ['type' => 'todos_contra_todos', 'groups' => 1]);

/* =========================
   LOGIN
   ========================= */
$login_action = $_POST["action"] ?? "";
if ($login_action === "login") {
  $username = $_POST["username"] ?? "";
  $password = $_POST["password"] ?? "";
  
  if (isset($users[$username]) && $users[$username]["password"] === $password) {
    $_SESSION['user'] = $username;
    $_SESSION['user_role'] = $users[$username]["role"];
    header("Location: index.php");
    exit;
  } else {
    header("Location: index.php?error=login");
    exit;
  }
}

$logout_action = $_GET["action"] ?? "";
if ($logout_action === "logout") {
  session_destroy();
  header("Location: index.php");
  exit;
}

/* =========================
   ACCIONES SOLO ADMIN
   ========================= */
$action = $_POST["action"] ?? null;

if ($action === "generar_fixture") {
  require_admin();
  $type = $_POST["fixture_type"] ?? 'todos_contra_todos';
  $groups = (int)($_POST["groups"] ?? 1);
  $matches = generate_round_robin($teams, $type, $groups);
  write_json($RESULTADOS_FILE, $matches);
  header("Location: index.php?ok=fixture");
  exit;
}

if ($action === "reset_resultados") {
  require_admin();
  foreach ($matches as &$m) {
    $m["home_goals"] = null;
    $m["away_goals"] = null;
    $m["home_yellow"] = 0;
    $m["home_red"] = 0;
    $m["away_yellow"] = 0;
    $m["away_red"] = 0;
    $m["played"] = false;
    $m["status"] = "pendiente";
    $m["walkover_winner"] = null;
    $m["notes"] = "";
  }
  unset($m);
  write_json($RESULTADOS_FILE, $matches);
  header("Location: index.php?ok=reset");
  exit;
}

if ($action === "guardar_resultados") {
  require_admin();
  
  $hg = $_POST["home_goals"] ?? [];
  $ag = $_POST["away_goals"] ?? [];
  $hy = $_POST["home_yellow"] ?? [];
  $hr = $_POST["home_red"] ?? [];
  $ay = $_POST["away_yellow"] ?? [];
  $ar = $_POST["away_red"] ?? [];
  $notes = $_POST["notes"] ?? [];
  $status = $_POST["match_status"] ?? [];
  $walkover_winner = $_POST["walkover_winner"] ?? [];
  
  $byId = [];
  foreach ($matches as $m) $byId[(int)$m["id"]] = $m;
  
  foreach ($byId as $id => &$m) {
    $matchStatus = $status[$id] ?? "pendiente";
    $m["status"] = $matchStatus;
    $m["notes"] = trim($notes[$id] ?? "");
    
    if ($matchStatus === "walkover") {
      $m["walkover_winner"] = (int)($walkover_winner[$id] ?? 0);
      $m["home_goals"] = null;
      $m["away_goals"] = null;
      $m["played"] = false;
      $m["home_yellow"] = 0;
      $m["home_red"] = 0;
      $m["away_yellow"] = 0;
      $m["away_red"] = 0;
    } else if ($matchStatus === "jugado") {
      $homeGoals = $hg[$id] ?? "";
      $awayGoals = $ag[$id] ?? "";
      
      $m["home_yellow"] = max(0, (int)($hy[$id] ?? 0));
      $m["home_red"]    = max(0, (int)($hr[$id] ?? 0));
      $m["away_yellow"] = max(0, (int)($ay[$id] ?? 0));
      $m["away_red"]    = max(0, (int)($ar[$id] ?? 0));
      
      if ($homeGoals !== "" && $awayGoals !== "") {
        $m["home_goals"] = max(0, (int)$homeGoals);
        $m["away_goals"] = max(0, (int)$awayGoals);
        $m["played"] = true;
      } else {
        $m["home_goals"] = null;
        $m["away_goals"] = null;
        $m["played"] = false;
      }
    } else {
      // pendiente o suspendido
      $m["home_goals"] = null;
      $m["away_goals"] = null;
      $m["played"] = false;
    }
  }
  unset($m);
  
  $matches = array_values($byId);
  usort($matches, fn($a,$b) => ($a["round"] <=> $b["round"]) ?: ($a["id"] <=> $b["id"]));
  write_json($RESULTADOS_FILE, $matches);
  
  header("Location: index.php?ok=guardado");
  exit;
}

/* =========================
   VIEW PREP
   ========================= */
$standings = calculate_standings($teams, $matches);
$matchesByRound = [];
foreach ($matches as $m) $matchesByRound[(int)$m["round"]][] = $m;
ksort($matchesByRound);

$ok = $_GET["ok"] ?? null;
$error = $_GET["error"] ?? null;
$playedCount = count(array_filter($matches, fn($m)=>!empty($m["played"]) || ($m["status"] ?? "pendiente") === "walkover"));

// Determinar si es admin para usar en la vista
$is_admin_user = is_admin();

// Si no hay sesión, mostrar login
if (!isset($_SESSION['user'])): ?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= htmlspecialchars($TORNEO) ?> - Login</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background: linear-gradient(180deg, #fff3d6, #ffffff); min-height: 100vh; }
    .login-card { max-width: 400px; margin: 100px auto; padding: 2rem; border-radius: 18px; box-shadow: 0 10px 28px rgba(0,0,0,.08); background: #fff; }
  </style>
</head>
<body>
  <div class="container">
    <div class="login-card">
      <h2 class="text-center mb-4"><?= htmlspecialchars($TORNEO) ?>🏖️</h2>
      <?php if ($error === 'login'): ?>
        <div class="alert alert-danger">Usuario o contraseña incorrectos</div>
      <?php endif; ?>
      <form method="post">
        <input type="hidden" name="action" value="login">
        <div class="mb-3">
          <label class="form-label">Usuario</label>
          <input type="text" name="username" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Contraseña</label>
          <input type="password" name="password" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-primary w-100">Ingresar</button>
      </form>
      <div class="mt-3 text-center text-muted small">
        Usuario para jugadores: jugadores / jugadores123<br>
        <!-- Usuario admin: admin / admin123 -->
      </div>

       <div class="text-muted small mt-3 text-center" style="font-size: 0.7rem;">
        © 2026 CopaSayBeach. <br>Todos los derechos reservados.<br>
    Desarrollo y diseño web:
    <a href="https://www.linkedin.com/in/llpiedrabuena"
       target="_blank"
       rel="noopener noreferrer">
        LLP
    </a>
</div>

    </div>
  </div>
</body>
</html>
<?php exit; endif; ?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= htmlspecialchars($TORNEO) ?></title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

  <style>
    :root{
      --sand: #fff3d6;
      --ocean: #15b9c6;
      --ocean-dark: #0a8f99;
      --coral: #ff6b6b;
      --seafoam: #e9fffb;
      --ink: #16323a;
    }

    body {
      background: linear-gradient(180deg, var(--sand), #ffffff);
      color: var(--ink);
    }

    .brand-badge {
      background: var(--seafoam);
      border: 1px solid rgba(21,185,198,.25);
      color: var(--ocean-dark);
      border-radius: 999px;
      padding: .35rem .7rem;
      font-weight: 600;
    }

    .beach-card {
      border: 0;
      border-radius: 18px;
      box-shadow: 0 10px 28px rgba(0,0,0,.08);
      background: #fff;
    }

    .btn-ocean {
      background: var(--ocean);
      border-color: var(--ocean);
      color: white;
      font-weight: 700;
    }
    .btn-ocean:hover { background: var(--ocean-dark); border-color: var(--ocean-dark); }

    .btn-coral {
      background: var(--coral);
      border-color: var(--coral);
      color: white;
      font-weight: 700;
    }
    .btn-coral:hover { filter: brightness(.92); }

    .small-input {
      width: 56px;
      padding: 4px 6px;
      font-size: 0.9rem;
    }
    
    .notes-input {
      font-size: 0.85rem;
      height: 60px;
    }
    
    @media (max-width: 480px) {
      .small-input {
        width: 46px;
        font-size: 0.85rem;
        padding: 3px 5px;
      }
    }

    .team-line {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: .5rem;
      font-weight: 700;
    }
    .team-name { display: inline-flex; align-items: center; gap: .35rem; }
    .winner {
      color: #198754;
      font-weight: 800;
    }
    .draw {
      color: #0d6efd;
      font-weight: 800;
    }

    .match-meta {
      color: rgba(22,50,58,.65);
      font-size: .9rem;
    }

    .readonly-look {
      background: #f2f4f6 !important;
      opacity: .95;
    }

    .label-pill {
      background: rgba(21,185,198,.12);
      border: 1px solid rgba(21,185,198,.25);
      color: var(--ocean-dark);
      font-weight: 700;
      padding: .2rem .6rem;
      border-radius: 999px;
      font-size: .8rem;
    }

    .standings thead th {
      position: sticky;
      top: 0;
      background: #fff;
      z-index: 1;
    }

    .mono { font-variant-numeric: tabular-nums; }

    .section-title {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      flex-wrap: wrap;
    }
    
    .status-badge {
      font-size: 0.75rem;
      padding: 0.2rem 0.5rem;
    }
    
    .user-info {
      background: var(--seafoam);
      padding: 0.5rem 1rem;
      border-radius: 10px;
      font-size: 0.9rem;
    }

  </style>
</head>

<body>
<div class="container py-4">

<!-- Header reestructurado para móvil -->
<div class="mb-3">
  <!-- Línea 1: Título del torneo -->
  <div class="mb-2">
    <h1 class="h3 mb-0"><?= htmlspecialchars($TORNEO) ?>🏖️</h1>
  </div>
  
  <!-- Línea 2: Usuario -->
  <div class="mb-2">
    <div class="user-info d-inline-block">
      <strong><?= htmlspecialchars($_SESSION['user']) ?></strong> 
      (<?= $_SESSION['user_role'] === 'admin' ? 'Administrador' : 'Jugadores' ?>)
      <a href="?action=logout" class="text-danger ms-2 small">Cerrar sesión</a>
    </div>
  </div>
  
  <!-- Contenedor para líneas 3 y 4 alineadas a la derecha -->
  <div class="d-flex flex-column align-items-end">
    
    <!-- Línea 3: Botones de acción (solo admin) -->
    <?php if ($is_admin_user): ?>
    <div class="mb-3">
      <div class="d-flex flex-wrap gap-2 justify-content-end">
        <form method="post" class="m-0">
          <input type="hidden" name="action" value="generar_fixture">
          <button type="button" class="btn btn-ocean btn-sm" data-bs-toggle="modal" data-bs-target="#fixtureModal">
            Generar fixture
          </button>
        </form>

        <form method="post" class="m-0" onsubmit="return confirm('Esto borra goles y tarjetas (mantiene el fixture). ¿Continuar?');">
          <input type="hidden" name="action" value="reset_resultados">
          <button class="btn btn-coral btn-sm">Reset resultados</button>
        </form>
      </div>
    </div>
    <?php endif; ?>
    
    <!-- Línea 4: Contador de partidos -->
    <div class="text-end">
      <span class="brand-badge">Partidos: <?= count($matches) ?> • Jugados: <?= $playedCount ?></span>
      <?php if (!$is_admin_user): ?>
        <span class="brand-badge ms-2">Modo solo lectura</span>
      <?php endif; ?>
    </div>
    
  </div>
</div>

  <?php if ($ok): ?>
    <div class="alert alert-success beach-card p-3">
      <?php
        echo match($ok) {
          "fixture" => "Fixture generado y guardado.",
          "reset" => "Resultados reseteados.",
          "guardado" => "Resultados guardados correctamente.",
          default => "Acción realizada."
        };
      ?>
    </div>
  <?php endif; ?>
  
  <?php if ($error === 'no_admin'): ?>
    <div class="alert alert-danger beach-card p-3">
      No tienes permisos de administrador para realizar esta acción.
    </div>
  <?php endif; ?>

  <?php if (count($teams) < 2): ?>
    <div class="alert alert-warning beach-card p-3">
      Cargá al menos 2 equipos en <code>equipos.json</code>.
    </div>
  <?php endif; ?>

  <div class="row g-3">
    <!-- TABLA -->
    <div class="col-12 col-lg-6">
      <div class="beach-card p-3">
        <h2 class="h5 mb-3">Tabla de posiciones</h2>

        <div class="table-responsive standings">
          <table class="table table-sm align-middle mono">
            <thead>
              <tr class="text-muted">
                <th>#</th>
                <th>Equipo</th>
                <th class="text-end">Pts</th>
                <th class="text-end">PJ</th>
                <th class="text-end d-none d-md-table-cell">PG</th>
                <th class="text-end d-none d-md-table-cell">PE</th>
                <th class="text-end d-none d-md-table-cell">PP</th>
                <th class="text-end">DG</th>
                <th class="text-end d-none d-md-table-cell">GF</th>
                <th class="text-end d-none d-md-table-cell">GC</th>
                <th class="text-end">🟨</th>
                <th class="text-end">🟥</th>
                <th class="text-end d-none d-md-table-cell">WO</th>
                <th class="text-end d-none d-md-table-cell">Fair</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($standings as $i => $r): ?>
                <tr>
                  <td><?= $i+1 ?></td>
                  <td><?= htmlspecialchars($r["nombre"]) ?></td>
                  <td class="text-end fw-semibold"><?= $r["pts"] ?></td>
                  <td class="text-end"><?= $r["pj"] ?></td>
                  <td class="text-end d-none d-md-table-cell"><?= $r["pg"] ?></td>
                  <td class="text-end d-none d-md-table-cell"><?= $r["pe"] ?></td>
                  <td class="text-end d-none d-md-table-cell"><?= $r["pp"] ?></td>
                  <td class="text-end"><?= $r["dg"] ?></td>
                  <td class="text-end d-none d-md-table-cell"><?= $r["gf"] ?></td>
                  <td class="text-end d-none d-md-table-cell"><?= $r["gc"] ?></td>
                  <td class="text-end"><?= $r["ta"] ?></td>
                  <td class="text-end"><?= $r["tr"] ?></td>
                  <td class="text-end d-none d-md-table-cell"><?= $r["wo"] ?>-<?= $r["wo_lost"] ?></td>
                  <td class="text-end d-none d-md-table-cell"><?= $r["fair"] ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="text-muted small mt-2">
          Desempate: <b>Puntos</b> → <b>DG</b> → <b>GF</b> → <b>Fair Play</b> (🟨=1, 🟥=3; menor mejor) → <b>Walkovers</b> → Orden alfabético. WO: Walkovers ganados-perdidos (3-0).
        </div>
      </div>
    </div>

    <!-- FIXTURE -->
    <div class="col-12 col-lg-6">
      <div class="beach-card p-3">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
          <h2 class="h5 mb-0">Fixture y resultados</h2>
          <?php if ($is_admin_user): ?>
            <div class="form-check form-switch m-0">
              <input class="form-check-input" type="checkbox" role="switch" id="toggleEditPlayed">
              <label class="form-check-label" for="toggleEditPlayed">Editar partidos jugados</label>
            </div>
          <?php endif; ?>
        </div>

        <?php if (empty($matches)): ?>
          <div class="alert alert-info beach-card p-3 mb-0">
            No hay fixture aún. Tocá <b>Generar fixture</b>.
          </div>
        <?php else: ?>

          <?php if ($is_admin_user): ?>
          <form method="post">
            <input type="hidden" name="action" value="guardar_resultados">
          <?php endif; ?>

            <div class="d-flex flex-column gap-3 mt-3">
              <?php 
              foreach ($matches as $m): 
                $id = (int)$m["id"];
                $homeName = team_name($teams, $m["home_id"]);
                $awayName = team_name($teams, $m["away_id"]);
                $played = !empty($m["played"]);
                $status = $m["status"] ?? "pendiente";
                $notes = $m["notes"] ?? "";
                
                $winnerHome = false;
                $winnerAway = false;
                $isDraw = false;
                
                if ($status === "jugado" && $played) {
                  if ((int)($m["home_goals"] ?? 0) > (int)($m["away_goals"] ?? 0)) $winnerHome = true;
                  elseif ((int)($m["away_goals"] ?? 0) > (int)($m["home_goals"] ?? 0)) $winnerAway = true;
                  else $isDraw = true;
                }
                
                // Variables para el contexto de la vista
                $allowEdit = false; // Inicializar
                $lockAttr = '';
                $lockClass = '';
                $isViewer = !$is_admin_user;
                
                if ($is_admin_user) {
                  $allowEdit = isset($_POST['allow_edit']) ? (bool)$_POST['allow_edit'] : false;
                  $lockAttr = ($status === "jugado" && !$allowEdit) ? 'readonly data-locked="1"' : '';
                  $lockClass = ($status === "jugado" && !$allowEdit) ? 'readonly-look' : '';
                } else {
                  $lockAttr = 'readonly';
                  $lockClass = 'readonly-look';
                }
              ?>
                <div class="beach-card p-3">
                  <!-- Ronda y Grupo -->
                  <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="label-pill">Ronda <?= $m["round"] ?></span>
                    <?php if (isset($m["group"])): ?>
                      <span class="badge bg-info">Grupo <?= $m["group"] ?></span>
                    <?php endif; ?>
                  </div>
                  
                  <!-- Equipos -->
                  <div class="team-line mb-2">
                    <span class="team-name <?= $winnerHome ? 'winner' : ($isDraw ? 'draw' : '') ?>">
                      <?= $winnerHome ? '🏆' : '' ?>
                      <?= htmlspecialchars($homeName) ?>
                    </span>
                    
                    <span class="text-muted small">vs</span>
                    
                    <span class="team-name <?= $winnerAway ? 'winner' : ($isDraw ? 'draw' : '') ?>">
                      <?= htmlspecialchars($awayName) ?>
                      <?= $winnerAway ? '🏆' : '' ?>
                    </span>
                  </div>
                  
                  <!-- Estado del partido (solo admin puede cambiar) -->
                  <?php if ($is_admin_user): ?>
                  <div class="mb-2">
                    <select class="form-select form-select-sm" name="match_status[<?= $id ?>]" onchange="toggleMatchFields(<?= $id ?>, this.value)">
                      <option value="pendiente" <?= $status === "pendiente" ? "selected" : "" ?>>Pendiente</option>
                      <option value="jugado" <?= $status === "jugado" ? "selected" : "" ?>>Jugado</option>
                      <option value="walkover" <?= $status === "walkover" ? "selected" : "" ?>>Walkover (no se presenta)</option>
                      <option value="suspendido" <?= $status === "suspendido" ? "selected" : "" ?>>Suspendido</option>
                    </select>
                  </div>
                  <?php endif; ?>
                  
                  <!-- Campos para partido jugado -->
                  <div id="match-fields-<?= $id ?>" class="<?= $status !== 'jugado' ? 'd-none' : '' ?>">
                    <!-- GOLES -->
                    <div class="d-flex justify-content-center align-items-center gap-1 mb-2">
                      <input class="form-control form-control-sm small-input text-center <?= $lockClass ?>"
                             type="number" min="0"
                             name="home_goals[<?= $id ?>]"
                             value="<?= $m["home_goals"] === null ? "" : (int)$m["home_goals"] ?>"
                             <?= $lockAttr ?> <?= $isViewer ? 'readonly' : '' ?>>
                      
                      <span class="fw-semibold">-</span>
                      
                      <input class="form-control form-control-sm small-input text-center <?= $lockClass ?>"
                             type="number" min="0"
                             name="away_goals[<?= $id ?>]"
                             value="<?= $m["away_goals"] === null ? "" : (int)$m["away_goals"] ?>"
                             <?= $lockAttr ?> <?= $isViewer ? 'readonly' : '' ?>>
                    </div>
                    
                    <!-- TARJETAS -->
                    <div class="d-flex justify-content-center gap-2 flex-wrap mb-2">
                      <div class="d-flex align-items-center gap-1">
                        <span>🟨</span>
                        <input class="form-control form-control-sm small-input text-center <?= $lockClass ?>"
                               type="number" min="0"
                               name="home_yellow[<?= $id ?>]"
                               value="<?= (int)$m["home_yellow"] ?>" <?= $lockAttr ?> <?= $isViewer ? 'readonly' : '' ?>>
                        <span>/</span>
                        <input class="form-control form-control-sm small-input text-center <?= $lockClass ?>"
                               type="number" min="0"
                               name="away_yellow[<?= $id ?>]"
                               value="<?= (int)$m["away_yellow"] ?>" <?= $lockAttr ?> <?= $isViewer ? 'readonly' : '' ?>>
                      </div>
                      
                      <div class="d-flex align-items-center gap-1">
                        <span>🟥</span>
                        <input class="form-control form-control-sm small-input text-center <?= $lockClass ?>"
                               type="number" min="0"
                               name="home_red[<?= $id ?>]"
                               value="<?= (int)$m["home_red"] ?>" <?= $lockAttr ?> <?= $isViewer ? 'readonly' : '' ?>>
                        <span>/</span>
                        <input class="form-control form-control-sm small-input text-center <?= $lockClass ?>"
                               type="number" min="0"
                               name="away_red[<?= $id ?>]"
                               value="<?= (int)$m["away_red"] ?>" <?= $lockAttr ?> <?= $isViewer ? 'readonly' : '' ?>>
                      </div>
                    </div>
                  </div>
                  
                  <!-- Walkover selector -->
                  <div id="walkover-fields-<?= $id ?>" class="<?= $status !== 'walkover' ? 'd-none' : '' ?> mb-2">
                    <div class="alert alert-warning p-2">
                      <label class="form-label small mb-1">¿Quién gana por walkover?</label>
                      <select class="form-select form-select-sm" name="walkover_winner[<?= $id ?>]" <?= $isViewer ? 'disabled' : '' ?>>
                        <option value="0">Seleccionar...</option>
                        <option value="<?= $m["home_id"] ?>" <?= ((int)($m["walkover_winner"] ?? 0) === (int)$m["home_id"]) ? "selected" : "" ?>>
                          <?= htmlspecialchars($homeName) ?> (Local)
                        </option>
                        <option value="<?= $m["away_id"] ?>" <?= ((int)($m["walkover_winner"] ?? 0) === (int)$m["away_id"]) ? "selected" : "" ?>>
                          <?= htmlspecialchars($awayName) ?> (Visitante)
                        </option>
                      </select>
                    </div>
                  </div>
                  
                  <!-- Observaciones -->
                  <div class="mb-2">
                    <textarea class="form-control form-control-sm notes-input <?= $isViewer ? 'readonly-look' : '' ?>" 
                              name="notes[<?= $id ?>]" 
                              placeholder="Observaciones: goles, tarjetas, incidentes..." 
                              <?= $isViewer ? 'readonly' : '' ?>><?= htmlspecialchars($notes) ?></textarea>
                  </div>
                  
                  <!-- Badges de estado -->
                  <div class="text-center">
                    <?php if ($status === "jugado"): ?>
                      <?php if ($isDraw): ?>
                        <span class="badge text-bg-primary status-badge">Empate</span>
                      <?php else: ?>
                        <span class="badge text-bg-success status-badge">Jugado</span>
                      <?php endif; ?>
                    <?php elseif ($status === "walkover"): ?>
                      <span class="badge text-bg-warning status-badge">Walkover</span>
                      <?php if ($m["walkover_winner"] ?? 0): ?>
                        <span class="badge text-bg-dark status-badge ms-1">Gana: <?= team_name($teams, $m["walkover_winner"]) ?></span>
                      <?php endif; ?>
                    <?php elseif ($status === "suspendido"): ?>
                      <span class="badge text-bg-secondary status-badge">Suspendido</span>
                    <?php else: ?>
                      <span class="badge text-bg-light status-badge">Pendiente</span>
                    <?php endif; ?>
                    
                    <?php if ($status === "jugado" && $played): ?>
                      <span class="badge text-bg-dark status-badge ms-1">Jugado</span>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            
            <?php if ($is_admin_user): ?>
            <div class="d-flex flex-wrap gap-2 mt-3">
              <button class="btn btn-ocean">Guardar resultados</button>
              <a class="btn btn-outline-secondary" href="index.php">Recargar</a>
            </div>
            <?php endif; ?>
            
          <?php if ($is_admin_user): ?>
          </form>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

<!-- Modal para generar fixture -->
<div class="modal fade" id="fixtureModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Generar Fixture</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <input type="hidden" name="action" value="generar_fixture">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Tipo de fixture</label>
            <select class="form-select" name="fixture_type" id="fixtureType" onchange="toggleGroupsField()">
              <option value="todos_contra_todos" <?= $fixture_config['type'] === 'todos_contra_todos' ? 'selected' : '' ?>>Todos contra todos</option>
              <option value="grupos" <?= $fixture_config['type'] === 'grupos' ? 'selected' : '' ?>>Por grupos</option>
            </select>
          </div>
          <div class="mb-3" id="groupsField" style="display: <?= $fixture_config['type'] === 'grupos' ? 'block' : 'none' ?>;">
            <label class="form-label">Número de grupos</label>
            <input type="number" class="form-control" name="groups" min="2" max="4" value="<?= $fixture_config['groups'] ?? 2 ?>">
            <div class="form-text">Los equipos se distribuirán aleatoriamente en grupos.</div>
          </div>
          <div class="alert alert-info">
            <strong>¡Atención!</strong> Esto reemplazará el fixture actual y todos los resultados.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary">Generar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // Mostrar/ocultar campos según estado del partido
  function toggleMatchFields(matchId, status) {
    const matchFields = document.getElementById(`match-fields-${matchId}`);
    const walkoverFields = document.getElementById(`walkover-fields-${matchId}`);
    
    if (matchFields) matchFields.classList.toggle('d-none', status !== 'jugado');
    if (walkoverFields) walkoverFields.classList.toggle('d-none', status !== 'walkover');
  }
  
  // Toggle para editar partidos jugados
  const toggle = document.getElementById('toggleEditPlayed');
  function applyLock(allowEdit) {
    document.querySelectorAll('[data-locked="1"]').forEach(inp => {
      if (allowEdit) {
        inp.removeAttribute('readonly');
        inp.classList.remove('readonly-look');
      } else {
        inp.setAttribute('readonly', 'readonly');
        inp.classList.add('readonly-look');
      }
    });
  }
  if (toggle) {
    toggle.addEventListener('change', (e) => applyLock(e.target.checked));
    applyLock(false);
  }
  
  // Mostrar/ocultar campo de grupos
  function toggleGroupsField() {
    const fixtureType = document.getElementById('fixtureType').value;
    const groupsField = document.getElementById('groupsField');
    groupsField.style.display = fixtureType === 'grupos' ? 'block' : 'none';
  }
  
  // Inicializar estados de partidos
  document.querySelectorAll('[name^="match_status"]').forEach(select => {
    const matchId = select.name.match(/\[(\d+)\]/)[1];
    toggleMatchFields(matchId, select.value);
  });
</script>
</body>
</html>