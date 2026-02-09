<?php
session_start();

// Verificar que esté logueado
if (!isset($_SESSION['user'])) {
    header("Location: index.php");
    exit;
}

/* =========================
   CONFIG
   ========================= */
$TORNEO = "CopaSayBeach";
$EQUIPOS_FILE = __DIR__ . "/equipos.json";
$RESULTADOS_FILE = __DIR__ . "/resultados.json";
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

function team_name($teams, $id) {
  foreach ($teams as $t) if ((int)$t['id'] === (int)$id) return $t['nombre'];
  return "Equipo #$id";
}

function get_deporte_config() {
  global $DEPORTE_CONFIG_FILE;
  $config = read_json($DEPORTE_CONFIG_FILE, ['deporte' => 'futbol']);
  return $config;
}

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
      'points_lost' => 0,
      'possession_total' => 0,
      'possession_count' => 0,
      'possession_avg' => 0
    ];
  }
  
  foreach ($matches as $m) {
    if (!$m['played']) continue;
    
    $hid = (int)$m['home_id'];
    $aid = (int)$m['away_id'];
    
    if (!isset($standings[$hid]) || !isset($standings[$aid])) continue;
    
    if ($m['status'] === 'walkover') {
      $winner = isset($m['walkover_winner']) ? (int)$m['walkover_winner'] : null;
      
      if ($winner === $hid) {
        $standings[$hid]['pts'] += 3;
        $standings[$hid]['pg']++;
        $standings[$hid]['pj']++;
        $standings[$hid]['wo']++;
        $standings[$aid]['pp']++;
        $standings[$aid]['pj']++;
        $standings[$aid]['wo_lost']++;
      } elseif ($winner === $aid) {
        $standings[$aid]['pts'] += 3;
        $standings[$aid]['pg']++;
        $standings[$aid]['pj']++;
        $standings[$aid]['wo']++;
        $standings[$hid]['pp']++;
        $standings[$hid]['pj']++;
        $standings[$hid]['wo_lost']++;
      }
      continue;
    }
    
    if ($deporte === 'futbol' || $deporte === 'handball') {
      $hg = isset($m['home_goals']) ? (int)$m['home_goals'] : 0;
      $ag = isset($m['away_goals']) ? (int)$m['away_goals'] : 0;
      
      $standings[$hid]['pj']++;
      $standings[$aid]['pj']++;
      $standings[$hid]['gf'] += $hg;
      $standings[$hid]['gc'] += $ag;
      $standings[$aid]['gf'] += $ag;
      $standings[$aid]['gc'] += $hg;
      
      if ($hg > $ag) {
        $standings[$hid]['pts'] += 3;
        $standings[$hid]['pg']++;
        $standings[$aid]['pp']++;
      } elseif ($ag > $hg) {
        $standings[$aid]['pts'] += 3;
        $standings[$aid]['pg']++;
        $standings[$hid]['pp']++;
      } else {
        $standings[$hid]['pts']++;
        $standings[$aid]['pts']++;
        $standings[$hid]['pe']++;
        $standings[$aid]['pe']++;
      }
      
      $standings[$hid]['ta'] += isset($m['home_yellow']) ? (int)$m['home_yellow'] : 0;
      $standings[$hid]['tr'] += isset($m['home_red']) ? (int)$m['home_red'] : 0;
      $standings[$aid]['ta'] += isset($m['away_yellow']) ? (int)$m['away_yellow'] : 0;
      $standings[$aid]['tr'] += isset($m['away_red']) ? (int)$m['away_red'] : 0;
      
      if ($deporte === 'handball') {
        if (isset($m['home_possession']) && $m['home_possession'] !== null) {
          $standings[$hid]['possession_total'] += (int)$m['home_possession'];
          $standings[$hid]['possession_count']++;
        }
        if (isset($m['away_possession']) && $m['away_possession'] !== null) {
          $standings[$aid]['possession_total'] += (int)$m['away_possession'];
          $standings[$aid]['possession_count']++;
        }
      }
      
    } else { // voley
      $hs = isset($m['home_sets_won']) ? (int)$m['home_sets_won'] : 0;
      $as = isset($m['away_sets_won']) ? (int)$m['away_sets_won'] : 0;
      
      $standings[$hid]['pj']++;
      $standings[$aid]['pj']++;
      
      $standings[$hid]['sets_won'] += $hs;
      $standings[$hid]['sets_lost'] += $as;
      $standings[$aid]['sets_won'] += $as;
      $standings[$aid]['sets_lost'] += $hs;
      
      if ($hs > $as) {
        $standings[$hid]['pts'] += 3;
        $standings[$hid]['pg']++;
        $standings[$aid]['pp']++;
      } else {
        $standings[$aid]['pts'] += 3;
        $standings[$aid]['pg']++;
        $standings[$hid]['pp']++;
      }
      
      if (isset($m['home_points']) && isset($m['away_points'])) {
        $standings[$hid]['points_won'] += (int)$m['home_points'];
        $standings[$hid]['points_lost'] += (int)$m['away_points'];
        $standings[$aid]['points_won'] += (int)$m['away_points'];
        $standings[$aid]['points_lost'] += (int)$m['home_points'];
      }
    }
  }
  
  foreach ($standings as $id => $s) {
    if ($deporte === 'futbol' || $deporte === 'handball') {
      $standings[$id]['dg'] = $s['gf'] - $s['gc'];
      if ($deporte === 'handball' && $s['possession_count'] > 0) {
        $standings[$id]['possession_avg'] = round($s['possession_total'] / $s['possession_count'], 1);
      }
    } else {
      $standings[$id]['dg'] = $s['sets_won'] - $s['sets_lost'];
    }
  }
  
  usort($standings, function($a, $b) use ($deporte) {
    if ($b['pts'] !== $a['pts']) return $b['pts'] - $a['pts'];
    if ($b['dg'] !== $a['dg']) return $b['dg'] - $a['dg'];
    if ($deporte === 'futbol' || $deporte === 'handball') {
      if ($b['gf'] !== $a['gf']) return $b['gf'] - $a['gf'];
    } else {
      if ($b['sets_won'] !== $a['sets_won']) return $b['sets_won'] - $a['sets_won'];
    }
    return 0;
  });
  
  return $standings;
}

// Cargar datos
$teams = read_json($EQUIPOS_FILE, []);
$matches = read_json($RESULTADOS_FILE, []);
$deporte_config = get_deporte_config();
$current_deporte = $deporte_config['deporte'];

// Filtrar partidos del deporte actual
$matches_filtrados = array_filter($matches, fn($m) => ($m["deporte"] ?? "futbol") === $current_deporte);

// Calcular tabla
$standings = calculate_standings($teams, $matches_filtrados, $current_deporte);

// Configurar headers para PDF
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="tabla_posiciones_' . $TORNEO . '_' . date('Y-m-d') . '.pdf"');

// Crear el PDF manualmente con HTML
ob_start();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        @page {
            margin: 20mm;
        }
        body {
            font-family: Arial, sans-serif;
            font-size: 12pt;
        }
        h1 {
            text-align: center;
            color: #0a4d68;
            margin-bottom: 10px;
        }
        h2 {
            text-align: center;
            color: #088395;
            font-size: 14pt;
            margin-top: 5px;
            margin-bottom: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th {
            background-color: #0a4d68;
            color: white;
            padding: 10px;
            text-align: left;
            border: 1px solid #ddd;
        }
        td {
            padding: 8px;
            border: 1px solid #ddd;
            text-align: left;
        }
        tr:nth-child(even) {
            background-color: #f2f2f2;
        }
        tr:hover {
            background-color: #e6f3ff;
        }
        .text-center {
            text-align: center;
        }
        .text-right {
            text-align: right;
        }
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 10pt;
            color: #666;
        }
        .deporte-badge {
            display: inline-block;
            background: #05bfdb;
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 11pt;
        }
    </style>
</head>
<body>
    <h1><?= htmlspecialchars($TORNEO) ?></h1>
    <h2>
        Tabla de Posiciones
        <span class="deporte-badge">
            <?php
            if ($current_deporte === 'futbol') echo 'Fútbol ⚽';
            elseif ($current_deporte === 'voley') echo 'Vóley 🏐';
            elseif ($current_deporte === 'handball') echo 'Handball 🤾';
            ?>
        </span>
    </h2>
    
    <table>
        <thead>
            <tr>
                <th class="text-center">#</th>
                <th>Equipo</th>
                <th class="text-center">Pts</th>
                <th class="text-center">PJ</th>
                <th class="text-center">PG</th>
                <?php if ($current_deporte !== 'voley'): ?>
                    <th class="text-center">PE</th>
                <?php endif; ?>
                <th class="text-center">PP</th>
                <th class="text-center">
                    <?= $current_deporte === 'voley' ? 'Sets +/-' : 'DG' ?>
                </th>
                <?php if ($current_deporte === 'futbol' || $current_deporte === 'handball'): ?>
                    <th class="text-center">GF</th>
                    <th class="text-center">GC</th>
                    <th class="text-center">TA</th>
                    <th class="text-center">TR</th>
                    <?php if ($current_deporte === 'handball'): ?>
                        <th class="text-center">Pos%</th>
                    <?php endif; ?>
                <?php else: ?>
                    <th class="text-center">SG</th>
                    <th class="text-center">SP</th>
                    <th class="text-center">Pts</th>
                <?php endif; ?>
                <th class="text-center">WO</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($standings as $i => $r): ?>
                <tr>
                    <td class="text-center"><strong><?= $i+1 ?></strong></td>
                    <td><strong><?= htmlspecialchars($r["nombre"]) ?></strong></td>
                    <td class="text-center"><strong><?= $r["pts"] ?></strong></td>
                    <td class="text-center"><?= $r["pj"] ?></td>
                    <td class="text-center"><?= $r["pg"] ?></td>
                    <?php if ($current_deporte !== 'voley'): ?>
                        <td class="text-center"><?= $r["pe"] ?></td>
                    <?php endif; ?>
                    <td class="text-center"><?= $r["pp"] ?></td>
                    <td class="text-center"><?= $r["dg"] > 0 ? '+' : '' ?><?= $r["dg"] ?></td>
                    
                    <?php if ($current_deporte === 'futbol' || $current_deporte === 'handball'): ?>
                        <td class="text-center"><?= $r["gf"] ?></td>
                        <td class="text-center"><?= $r["gc"] ?></td>
                        <td class="text-center"><?= $r["ta"] ?></td>
                        <td class="text-center"><?= $r["tr"] ?></td>
                        <?php if ($current_deporte === 'handball'): ?>
                            <td class="text-center"><?= $r["possession_avg"] ?>%</td>
                        <?php endif; ?>
                    <?php else: ?>
                        <td class="text-center"><?= $r["sets_won"] ?></td>
                        <td class="text-center"><?= $r["sets_lost"] ?></td>
                        <td class="text-center"><?= $r["points_won"] ?>-<?= $r["points_lost"] ?></td>
                    <?php endif; ?>
                    
                    <td class="text-center"><?= $r["wo"] ?>-<?= $r["wo_lost"] ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <div class="footer">
        <p>Generado el <?= date('d/m/Y H:i') ?> hs</p>
        <p><em>Sistema de gestión de torneos - <?= htmlspecialchars($TORNEO) ?></em></p>
    </div>
</body>
</html>
<?php
$html = ob_get_clean();

// Usar una biblioteca simple para convertir HTML a PDF
// Aquí puedes usar TCPDF, FPDF, mPDF, o Dompdf
// Por ahora, vamos a mostrar el HTML directamente y usar la función de imprimir del navegador

// Si no tienes ninguna biblioteca de PDF instalada, esto mostrará el HTML formateado
// que el usuario puede imprimir como PDF desde su navegador
echo $html;

/* 
// Si quieres usar mPDF (requiere instalarlo con composer):
require_once __DIR__ . '/vendor/autoload.php';
$mpdf = new \Mpdf\Mpdf([
    'format' => 'A4',
    'orientation' => 'P'
]);
$mpdf->WriteHTML($html);
$mpdf->Output('tabla_posiciones.pdf', 'I');
*/
?>
