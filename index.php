<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

/* =========================
   CONFIG
   ========================= */
$TORNEO = "COPASAYBEACH";
$EQUIPOS_FILE = __DIR__ . "/equipos.json";
$RESULTADOS_FILE = __DIR__ . "/resultados.json";

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

/* =========================
   FIXTURE - Round Robin (Circle method)
   Si impar, agrega BYE (id=0) y saltea esos cruces.
   ========================= */
function generate_round_robin($teams) {
  $n = count($teams);
  if ($n < 2) return [];

  $ids = array_map(fn($t) => (int)$t['id'], $teams);

  if ($n % 2 === 1) { // impar
    $ids[] = 0;      // BYE
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

      // alternancia simple para localía
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
        "played" => false
      ];
    }

    // rotación: fijamos el primero, rotamos el resto
    $fixed = array_shift($list);
    $last  = array_pop($list);
    array_unshift($list, $fixed);
    array_splice($list, 1, 0, [$last]);
  }

  return $matches;
}

/* =========================
   TABLA
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
      "fair" => 0
    ];
  }

  foreach ($matches as $m) {
    if (empty($m["played"])) continue;

    $h  = (int)$m["home_id"];
    $a  = (int)$m["away_id"];
    $hg = (int)$m["home_goals"];
    $ag = (int)$m["away_goals"];

    $hy = (int)$m["home_yellow"];
    $hr = (int)$m["home_red"];
    $ay = (int)$m["away_yellow"];
    $ar = (int)$m["away_red"];

    if (!isset($table[$h]) || !isset($table[$a])) continue;

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
    $row["fair"] = $row["ta"] * 1 + $row["tr"] * 3; // menor es mejor
  }
  unset($row);

  $arr = array_values($table);
  usort($arr, function($x, $y) {
    if ($x["pts"] !== $y["pts"]) return $y["pts"] <=> $x["pts"];
    if ($x["dg"]  !== $y["dg"])  return $y["dg"]  <=> $x["dg"];
    if ($x["gf"]  !== $y["gf"])  return $y["gf"]  <=> $x["gf"];
    if ($x["fair"]!== $y["fair"])return $x["fair"]<=> $y["fair"];
    return strcmp($x["nombre"], $y["nombre"]);
  });

  return $arr;
}

/* =========================
   LOAD
   ========================= */
$teams   = read_json($EQUIPOS_FILE, []);
$matches = read_json($RESULTADOS_FILE, []);

/* =========================
   ACTIONS
   ========================= */
$action = $_POST["action"] ?? null;

if ($action === "generar_fixture") {
  $matches = generate_round_robin($teams);
  write_json($RESULTADOS_FILE, $matches);
  header("Location: index.php?ok=fixture");
  exit;
}

if ($action === "reset_resultados") {
  foreach ($matches as &$m) {
    $m["home_goals"] = null;
    $m["away_goals"] = null;
    $m["home_yellow"] = 0;
    $m["home_red"] = 0;
    $m["away_yellow"] = 0;
    $m["away_red"] = 0;
    $m["played"] = false;
  }
  unset($m);
  write_json($RESULTADOS_FILE, $matches);
  header("Location: index.php?ok=reset");
  exit;
}

if ($action === "guardar_resultados") {
  $hg = $_POST["home_goals"] ?? [];
  $ag = $_POST["away_goals"] ?? [];
  $hy = $_POST["home_yellow"] ?? [];
  $hr = $_POST["home_red"] ?? [];
  $ay = $_POST["away_yellow"] ?? [];
  $ar = $_POST["away_red"] ?? [];

  $byId = [];
  foreach ($matches as $m) $byId[(int)$m["id"]] = $m;

  foreach ($byId as $id => &$m) {
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
$playedCount = count(array_filter($matches, fn($m)=>!empty($m["played"])));
?>
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

  </style>
</head>

<body>
<div class="container py-4">

  <div class="section-title mb-3">
    <div>
      <h1 class="h3 mb-1">🏖️ <?= htmlspecialchars($TORNEO) ?></h1>
       Gestión de fixture, resultados y tabla de la copa
      <!-- <div class="text-muted">Equipos desde <code>equipos.json</code> • Resultados en <code>resultados.json</code></div> -->
    </div>

    <div class="d-flex flex-wrap gap-2 align-items-center">
     
      <form method="post" class="m-0">
        <input type="hidden" name="action" value="generar_fixture">
        <button class="btn btn-ocean">Generar fixture</button>
      </form>

      <form method="post" class="m-0" onsubmit="return confirm('Esto borra goles y tarjetas (mantiene el fixture). ¿Continuar?');">
        <input type="hidden" name="action" value="reset_resultados">
        <button class="btn btn-coral">Reset resultados</button>
      </form>
      
      <span class="brand-badge">Partidos: <?= count($matches) ?> • Jugados: <?= $playedCount ?></span>

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
                  <td class="text-end d-none d-md-table-cell"><?= $r["fair"] ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="text-muted small mt-2">
          Desempate: <b>Puntos</b> → <b>DG</b> → <b>GF</b> → <b>Fair Play</b> (🟨=1, 🟥=3; menor mejor) → Nombre.
        </div>
      </div>
    </div>

    <!-- FIXTURE -->
    <div class="col-12 col-lg-6">
      <div class="beach-card p-3">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
          <h2 class="h5 mb-0">Fixture y carga de resultados</h2>

          <div class="form-check form-switch m-0">
            <input class="form-check-input" type="checkbox" role="switch" id="toggleEditPlayed">
            <label class="form-check-label" for="toggleEditPlayed">Editar partidos “Jugado”</label>
          </div>
        </div>

        <?php if (empty($matches)): ?>
          <div class="alert alert-info beach-card p-3 mb-0">
            No hay fixture aún. Tocá <b>Generar fixture</b>.
          </div>
        <?php else: ?>

          <form method="post">
            <input type="hidden" name="action" value="guardar_resultados">

            <?php foreach ($matchesByRound as $round => $list): ?>
              <div class="mt-4">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                  <div class="label-pill">Jornada <?= $round ?></div>
                  <div class="match-meta">Cargá goles y tarjetas. Si no hay goles, queda pendiente.</div>
                </div>

                <div class="d-flex flex-column gap-3">
                  <?php foreach ($list as $m):
                    $id = (int)$m["id"];
                    $homeName = team_name($teams, $m["home_id"]);
                    $awayName = team_name($teams, $m["away_id"]);
                    $played = !empty($m["played"]);

                    $winnerHome = false;
                    $winnerAway = false;
                    $isDraw = false;

                    if ($played) {
                      if ((int)$m["home_goals"] > (int)$m["away_goals"]) $winnerHome = true;
                      elseif ((int)$m["away_goals"] > (int)$m["home_goals"]) $winnerAway = true;
                      else $isDraw = true;
                    }

                    // Inputs readonly por defecto si jugado
                    $lockAttr = $played ? 'readonly data-locked="1"' : '';
                    $lockClass = $played ? 'readonly-look' : '';
                  ?>
                    <div class="beach-card p-3">
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

                      <!-- GOLES -->
                      <div class="d-flex justify-content-center align-items-center gap-1 mb-2">
                        <input class="form-control form-control-sm small-input text-center <?= $lockClass ?>"
                               type="number" min="0" inputmode="numeric"
                               name="home_goals[<?= $id ?>]"
                               value="<?= $m["home_goals"] === null ? "" : (int)$m["home_goals"] ?>"
                               placeholder="G" <?= $lockAttr ?>>

                        <span class="fw-semibold">-</span>

                        <input class="form-control form-control-sm small-input text-center <?= $lockClass ?>"
                               type="number" min="0" inputmode="numeric"
                               name="away_goals[<?= $id ?>]"
                               value="<?= $m["away_goals"] === null ? "" : (int)$m["away_goals"] ?>"
                               placeholder="G" <?= $lockAttr ?>>
                      </div>

                      <!-- TARJETAS -->
                      <div class="d-flex justify-content-center gap-2 flex-wrap mb-2">
                        <div class="d-flex align-items-center gap-1">
                          <span>🟨</span>
                          <input class="form-control form-control-sm small-input text-center <?= $lockClass ?>"
                                 type="number" min="0" inputmode="numeric"
                                 name="home_yellow[<?= $id ?>]"
                                 value="<?= (int)$m["home_yellow"] ?>" <?= $lockAttr ?>>
                          <span class="text-muted">/</span>
                          <input class="form-control form-control-sm small-input text-center <?= $lockClass ?>"
                                 type="number" min="0" inputmode="numeric"
                                 name="away_yellow[<?= $id ?>]"
                                 value="<?= (int)$m["away_yellow"] ?>" <?= $lockAttr ?>>
                        </div>

                        <div class="d-flex align-items-center gap-1">
                          <span>🟥</span>
                          <input class="form-control form-control-sm small-input text-center <?= $lockClass ?>"
                                 type="number" min="0" inputmode="numeric"
                                 name="home_red[<?= $id ?>]"
                                 value="<?= (int)$m["home_red"] ?>" <?= $lockAttr ?>>
                          <span class="text-muted">/</span>
                          <input class="form-control form-control-sm small-input text-center <?= $lockClass ?>"
                                 type="number" min="0" inputmode="numeric"
                                 name="away_red[<?= $id ?>]"
                                 value="<?= (int)$m["away_red"] ?>" <?= $lockAttr ?>>
                        </div>
                      </div>

                      <!-- ESTADO -->
                      <div class="text-center">
                        <?php if ($played): ?>
                          <?php if ($isDraw): ?>
                            <span class="badge text-bg-primary">Empate</span>
                          <?php elseif ($winnerHome || $winnerAway): ?>
                            <span class="badge text-bg-success">Ganador marcado</span>
                          <?php endif; ?>
                          <span class="badge text-bg-dark ms-1">Jugado</span>
                        <?php else: ?>
                          <span class="badge text-bg-secondary">Pendiente</span>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endforeach; ?>

            <div class="d-flex flex-wrap gap-2 mt-3">
              <button class="btn btn-ocean">Guardar resultados</button>
              <a class="btn btn-outline-secondary" href="index.php">Recargar</a>
            </div>
          </form>

        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="text-muted small mt-3">
    Desarrollado por LLP.
    <!-- Tip: si cambiás <code>equipos.json</code>, volvé a generar el fixture (pisará <code>resultados.json</code>). -->
  </div>

</div>

<script>
  // Permite editar partidos jugados (quita readonly) si activás el switch
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
  toggle?.addEventListener('change', (e) => applyLock(e.target.checked));
  applyLock(false);
</script>
</body>
</html>
