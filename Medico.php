<?php  
session_start();
date_default_timezone_set('America/Sao_Paulo');

$conn = new mysqli("localhost", "root", "", "clinagenda");
if ($conn->connect_error) die("Erro ao conectar: " . $conn->connect_error);
$conn->set_charset('utf8mb4');

// Verifica login do médico
if (!isset($_SESSION['id_medico'])) {
  header("Location: index.php");
  exit;
}

if (isset($_GET['logout'])) {
  session_unset();
  session_destroy();
  header("Location: index.php");
  exit();
}

$id = intval($_SESSION['id_medico']);

/* ==================== HELPERS ==================== */
function norm_time($h) {
  $h = trim((string)$h);
  if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $h)) return $h;
  if (preg_match('/^\d{2}:\d{2}$/', $h)) return $h . ':00';
  return $h;
}
function safe($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function is_valid_date($d){ return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/',$d); }
function is_valid_time_hm($t){ return (bool)preg_match('/^\d{2}:\d{2}$/',$t); }
function is_valid_time_hms($t){ return (bool)preg_match('/^\d{2}:\d{2}:\d{2}$/',$t); }

/* Checa se uma coluna existe (para inserts opcionais) */
function column_exists($conn, $table, $column){
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1";
  $st = $conn->prepare($sql);
  $st->bind_param("ss", $table, $column);
  $st->execute();
  return (bool)$st->get_result()->num_rows;
}

/* Cache simples dos checks de colunas de EXAMES */
$EX_HAS = [
  'tuss_codigo'    => column_exists($conn, 'exames', 'tuss_codigo'),
  'prioridade'     => column_exists($conn, 'exames', 'prioridade'),
  'jejum'          => column_exists($conn, 'exames', 'jejum'),
  'observacoes'    => column_exists($conn, 'exames', 'observacoes'),
  'agendamento_id' => column_exists($conn, 'exames', 'agendamento_id'),
];

/* ==================== UPLOAD DE FOTO (perfil) ==================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'upload_foto' && isset($_FILES['nova_foto'])) {
  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  $mime  = finfo_file($finfo, $_FILES['nova_foto']['tmp_name']);
  finfo_close($finfo);

  $permitidas = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
  $okTipo = isset($permitidas[$mime]);
  $okErro = ($_FILES['nova_foto']['error'] === UPLOAD_ERR_OK);
  $okTamanho = ($_FILES['nova_foto']['size'] > 0 && $_FILES['nova_foto']['size'] <= 5 * 1024 * 1024);

  if ($okTipo && $okErro && $okTamanho) {
    $ext = $permitidas[$mime];

    $baseDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'medicos' . DIRECTORY_SEPARATOR;
    if (!is_dir($baseDir)) mkdir($baseDir, 0777, true);

    $oldPath = null;
    $sel = $conn->prepare("SELECT caminho FROM fotos WHERE medico_id=? AND tipo='perfil' LIMIT 1");
    $sel->bind_param("i", $id);
    $sel->execute();
    $resSel = $sel->get_result();
    if ($resSel && $resSel->num_rows) $oldPath = $resSel->fetch_assoc()['caminho'];

    $nomeArquivo = 'medico_' . $id . '_' . date('Ymd_His') . '.' . $ext;
    $destinoAbs  = $baseDir . $nomeArquivo;
    $destinoRel  = 'uploads/medicos/' . $nomeArquivo;

    if (move_uploaded_file($_FILES['nova_foto']['tmp_name'], $destinoAbs)) {
      $sql = "INSERT INTO fotos (caminho, tipo, medico_id)
              VALUES (?, 'perfil', ?)
              ON DUPLICATE KEY UPDATE caminho=VALUES(caminho), data_upload=CURRENT_TIMESTAMP";
      $stm = $conn->prepare($sql);
      $stm->bind_param("si", $destinoRel, $id);
      $stm->execute();

      if ($oldPath && $oldPath !== $destinoRel) {
        $oldAbs = realpath(__DIR__ . DIRECTORY_SEPARATOR . $oldPath);
        $uploadsRoot = realpath($baseDir);
        if ($oldAbs && $uploadsRoot && strpos($oldAbs, $uploadsRoot) === 0 && file_exists($oldAbs)) {
          @unlink($oldAbs);
        }
      }

      header("Location: medico.php?foto=ok");
      exit;
    } else {
      echo "<script>alert('Falha ao salvar o arquivo. Verifique permissões da pasta uploads/.');</script>";
    }
  } else {
    echo "<script>alert('Arquivo inválido. Use JPG, PNG ou WEBP até 5MB.');</script>";
  }
}

/* ==================== SALVAR PERFIL ==================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'salvar_perfil') {
  $email   = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
  $telRaw  = $_POST['telefone'] ?? '';
  $telefone = preg_replace('/[^0-9()+\-.\s]/', '', $telRaw);

  $endereco = trim($_POST['endereco'] ?? '');
  $data_nasc = $_POST['data_nascimento'] ?? null;
  $genero    = $_POST['genero'] ?? null;

  $bio = trim($_POST['biografia'] ?? '');
  if (function_exists('mb_strlen')) {
    if (mb_strlen($bio) > 600) $bio = mb_substr($bio, 0, 600);
  } else {
    if (strlen($bio) > 600) $bio = substr($bio, 0, 600);
  }

  $up1 = $conn->prepare("UPDATE medicos SET email=?, telefone=? WHERE id=?");
  $up1->bind_param("ssi", $email, $telefone, $id);
  $up1->execute();

  $chk = $conn->prepare("SELECT id FROM dados_complementares_medicos WHERE medico_id=? LIMIT 1");
  $chk->bind_param("i", $id);
  $chk->execute();
  $r = $chk->get_result();

  if ($r && $r->num_rows) {
    $up2 = $conn->prepare("UPDATE dados_complementares_medicos 
                           SET endereco=?, data_nascimento=?, genero=?, biografia=? 
                           WHERE medico_id=?");
    $up2->bind_param("ssssi", $endereco, $data_nasc, $genero, $bio, $id);
    $up2->execute();
  } else {
    $ins2 = $conn->prepare("INSERT INTO dados_complementares_medicos 
                            (medico_id, endereco, data_nascimento, genero, biografia) 
                            VALUES (?, ?, ?, ?, ?)");
    $ins2->bind_param("issss", $id, $endereco, $data_nasc, $genero, $bio);
    $ins2->execute();
  }

  header("Location: medico.php?salvo=1");
  exit;
}

/* ==================== SALVAR BLOQUEIOS ==================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salvar_bloqueios'])) {
  $dias = array_filter(array_map('trim', explode(',', $_POST['dias_selecionados'] ?? '')));
  $horariosBrutos = array_filter(array_map('trim', explode(',', $_POST['horarios_selecionados'] ?? '')));

  $horarios = [];
  foreach ($horariosBrutos as $h) {
    $hNorm = norm_time($h);
    if (is_valid_time_hms($hNorm)) $horarios[] = $hNorm;
    elseif (is_valid_time_hm($h))  $horarios[] = $h.':00';
  }

  $hoje = new DateTime('today', new DateTimeZone('America/Sao_Paulo'));
  $insOk=0; $remOk=0; $ignorados=0;

  foreach ($dias as $data) {
    if (!is_valid_date($data)) { $ignorados++; continue; }

    $dSel = DateTime::createFromFormat('Y-m-d', $data, new DateTimeZone('America/Sao_Paulo'));
    if (!$dSel || $dSel < $hoje) { $ignorados++; continue; }

    foreach ($horarios as $hora) {
      $chkAg = $conn->prepare("SELECT 1 FROM agendamentos WHERE medico_id=? AND data=? AND horario=? AND status IN ('pendente','confirmado') LIMIT 1");
      $chkAg->bind_param("iss", $id, $data, $hora);
      $chkAg->execute();
      if ($chkAg->get_result()->num_rows) { $ignorados++; continue; }

      $check = $conn->prepare("SELECT id, ocupado FROM horarios_disponiveis WHERE medico_id=? AND data=? AND horario=?");
      $check->bind_param("iss", $id, $data, $hora);
      $check->execute();
      $res = $check->get_result();

      if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        if ((int)$row['ocupado'] === 1) {
          $del = $conn->prepare("DELETE FROM horarios_disponiveis WHERE id=?");
          $del->bind_param("i", $row['id']);
          if ($del->execute()) $remOk++;
        } else {
          $up = $conn->prepare("UPDATE horarios_disponiveis SET ocupado=1 WHERE id=?");
          $up->bind_param("i", $row['id']);
          if ($up->execute()) $insOk++;
        }
      } else {
        $ins = $conn->prepare("INSERT INTO horarios_disponiveis (medico_id, data, horario, ocupado) VALUES (?, ?, ?, 1)");
        $ins->bind_param("iss", $id, $data, $hora);
        if ($ins->execute()) $insOk++;
      }
    }
  }
  echo "<script>alert('Bloqueios atualizados. Criados: {$insOk}, Removidos: {$remOk}, Ignorados: {$ignorados}.');</script>";
}

/* ==================== ENDPOINT: LISTAR CONSULTAS (AJAX) ==================== */
if (($_GET['action'] ?? '') === 'listar_consultas') {
  header('Content-Type: application/json; charset=utf-8');

  $filtro = $_GET['filtro'] ?? 'todas';
  $where = "a.medico_id = ? AND a.status <> 'cancelado'";
  if ($filtro === 'hoje')        $where .= " AND a.data = CURDATE()";
  elseif ($filtro === 'amanha')  $where .= " AND a.data = DATE_ADD(CURDATE(), INTERVAL 1 DAY)";
  elseif ($filtro === 'futuras') $where .= " AND a.data > CURDATE()";

  $sql = "
    SELECT a.id, a.data, a.horario, a.status,
           c.id AS cliente_id, c.nome AS cliente_nome
    FROM agendamentos a
    JOIN clientes c ON c.id = a.cliente_id
    WHERE $where
    ORDER BY a.data DESC, a.horario DESC
  ";

  $stm = $conn->prepare($sql);
  $stm->bind_param("i", $id);
  $stm->execute();
  $rs = $stm->get_result();
  $rows = [];
  while ($r = $rs->fetch_assoc()) {
    $r['hora'] = substr($r['horario'] ?? '', 0, 5);

    $statusAtual = strtolower($r['status'] ?? '');
    if (in_array($statusAtual, ['pendente', 'confirmado'], true)) {
      $dataStr = trim((string)($r['data'] ?? ''));
      $horaStr = trim((string)($r['horario'] ?? '00:00:00'));
      if (preg_match('/^\d{2}:\d{2}$/', $horaStr)) $horaStr .= ':00';

      $dtConsulta = DateTime::createFromFormat(
        'Y-m-d H:i:s',
        $dataStr . ' ' . $horaStr,
        new DateTimeZone('America/Sao_Paulo')
      );
      $agora = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));

      if ($dtConsulta && $dtConsulta < $agora) {
        $r['status'] = 'expirada';
      }
    }
    $rows[] = $r;
  }

  echo json_encode(['ok' => true, 'items' => $rows], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ==================== ENDPOINT: LISTAR BLOQUEIOS DO DIA ==================== */
if (($_GET['action'] ?? '') === 'listar_bloqueios') {
  header('Content-Type: application/json; charset=utf-8');
  $data = $_GET['data'] ?? '';
  if (!is_valid_date($data)) { echo json_encode(['ok'=>false,'msg'=>'Data inválida']); exit; }

  $q = $conn->prepare("SELECT DATE_FORMAT(horario, '%H:%i') AS h FROM horarios_disponiveis WHERE medico_id=? AND data=? AND ocupado=1 ORDER BY horario");
  $q->bind_param("is", $id, $data);
  $q->execute();
  $rows = array_map(fn($r)=>$r[0], $q->get_result()->fetch_all(MYSQLI_NUM));
  echo json_encode(['ok'=>true,'data'=>$data,'bloqueios'=>$rows], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ==================== ENDPOINT: ATUALIZAR STATUS (Confirmar/Cancelar) ==================== */
if (($_POST['action'] ?? '') === 'atualizar_consulta') {
  header('Content-Type: application/json; charset=utf-8');

  $ag_id = (int)($_POST['agendamento_id'] ?? 0);
  $novo  = strtolower(trim($_POST['novo_status'] ?? ''));

  if (!$ag_id || !in_array($novo, ['confirmado','cancelado'], true)) {
    echo json_encode(['ok'=>false,'msg'=>'Parâmetros inválidos.']); exit;
  }

  $stm = $conn->prepare("SELECT id FROM agendamentos WHERE id=? AND medico_id=? LIMIT 1");
  $stm->bind_param("ii", $ag_id, $id);
  $stm->execute();
  if ($stm->get_result()->num_rows === 0) {
    echo json_encode(['ok'=>false,'msg'=>'Consulta não encontrada para este médico.']); exit;
  }

  $up = $conn->prepare("UPDATE agendamentos SET status=? WHERE id=?");
  $up->bind_param("si", $novo, $ag_id);
  if ($up->execute()) echo json_encode(['ok'=>true,'msg'=>'Status atualizado.']);
  else                echo json_encode(['ok'=>false,'msg'=>'Falha ao atualizar.']);
  exit;
}

/* ==================== ENDPOINT: BUSCAR TUSS (autocomplete) ==================== */
if (($_GET['action'] ?? '') === 'buscar_tuss') {
  header('Content-Type: application/json; charset=utf-8');
  $q = trim($_GET['q'] ?? '');
  $items = [];

  $tbExists = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='tuss_exames'")->num_rows > 0;
  if ($tbExists && $q !== '') {
    $like = "%$q%";
    $sql = "
      (SELECT codigo, descricao FROM tuss_exames WHERE codigo = ? LIMIT 10)
      UNION
      (SELECT codigo, descricao FROM tuss_exames WHERE descricao LIKE ? OR codigo LIKE ? LIMIT 15)
      LIMIT 15
    ";
    $st = $conn->prepare($sql);
    $st->bind_param("sss", $q, $like, $like);
    $st->execute();
    $items = $st->get_result()->fetch_all(MYSQLI_ASSOC);
  }

  echo json_encode(['ok'=>true, 'items'=>$items, 'tabela'=>$tbExists?'tuss_exames':'(sem tabela tuss_exames, entrada manual)'], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ==================== ENDPOINT: PRESCREVER EXAMES ==================== */
if (($_GET['action'] ?? '') === 'prescrever_exames' && $_SERVER['REQUEST_METHOD']==='POST') {
  header('Content-Type: application/json; charset=utf-8');

  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);
  if (!$data) { echo json_encode(['ok'=>false,'msg'=>'JSON inválido']); exit; }

  $agendamento_id = (int)($data['agendamento_id'] ?? 0);
  if (!$agendamento_id) { echo json_encode(['ok'=>false,'msg'=>'Agendamento inválido']); exit; }

  $st = $conn->prepare("SELECT a.id, a.cliente_id FROM agendamentos a WHERE a.id=? AND a.medico_id=? LIMIT 1");
  $st->bind_param("ii", $agendamento_id, $id);
  $st->execute();
  $ag = $st->get_result()->fetch_assoc();
  if (!$ag) { echo json_encode(['ok'=>false,'msg'=>'Agendamento não encontrado para este médico.']); exit; }
  $cliente_id = (int)$ag['cliente_id'];

  $items = $data['items'] ?? [];
  if (!is_array($items) || count($items)===0) {
    echo json_encode(['ok'=>false,'msg'=>'Nenhum exame informado.']); exit;
  }

  $ok=0; $fail=0;
  foreach ($items as $it) {
    $codigo = trim((string)($it['tuss_codigo'] ?? ''));
    $descr  = trim((string)($it['descricao'] ?? ''));
    $prio   = strtolower(trim((string)($it['prioridade'] ?? 'média')));
    $jejum  = !empty($it['jejum']) ? 1 : 0;
    $obs    = trim((string)($it['observacoes'] ?? ''));
    $data_sug = trim((string)($it['data_sugerida'] ?? ''));

    if ($descr === '' && $codigo === '') { $fail++; continue; }
    if ($data_sug !== '' && !is_valid_date($data_sug)) $data_sug = '';

    $cols = ['cliente_id','tipo','status'];
    $vals = [$cliente_id, $descr ?: $codigo, 'pendente'];
    $types= 'iss';

    if ($data_sug !== '') { $cols[]='data'; $vals[]=$data_sug; $types.='s'; }
    if ($EX_HAS['tuss_codigo'])    { $cols[]='tuss_codigo';    $vals[]=$codigo; $types.='s'; }
    if ($EX_HAS['prioridade'])     { $cols[]='prioridade';     $vals[]= in_array($prio,['baixa','média','alta']) ? $prio : 'média'; $types.='s'; }
    if ($EX_HAS['jejum'])          { $cols[]='jejum';          $vals[]=$jejum;  $types.='i'; }
    if ($EX_HAS['observacoes'])    { $cols[]='observacoes';    $vals[]=$obs;    $types.='s'; }
    if ($EX_HAS['agendamento_id']) { $cols[]='agendamento_id'; $vals[]=$agendamento_id; $types.='i'; }

    $sql = "INSERT INTO exames (".implode(',', $cols).") VALUES (" . rtrim(str_repeat('?,', count($cols)), ',') . ")";
    $stI = $conn->prepare($sql);
    if (!$stI) { $fail++; continue; }
    $stI->bind_param($types, ...$vals);
    if ($stI->execute()) $ok++; else $fail++;
  }

  $msg = "Exames salvos. Sucesso: $ok; Falhas: $fail.";
  echo json_encode(['ok'=> ($ok>0), 'msg'=>$msg, 'salvos'=>$ok, 'falhas'=>$fail], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ==================== DADOS DO MÉDICO ==================== */
$sql = "SELECT 
          m.nome, m.crm, m.especialidade, m.email, m.telefone,
          d.endereco, d.data_nascimento, d.genero, d.cpf, d.biografia
        FROM medicos m
        LEFT JOIN dados_complementares_medicos d ON m.id = d.medico_id
        WHERE m.id = $id";
$result = $conn->query($sql);
$medico = $result ? ($result->fetch_assoc() ?: []) : [];

/* Foto atual */
$foto = 'img/default.jpg';
$pf = $conn->prepare("SELECT caminho FROM fotos WHERE medico_id=? AND tipo='perfil' ORDER BY data_upload DESC, id DESC LIMIT 1");
$pf->bind_param("i", $id);
$pf->execute();
$rpf = $pf->get_result();
if ($rpf && $rpf->num_rows > 0) {
  $row = $rpf->fetch_assoc();
  if (!empty($row['caminho'])) $foto = safe($row['caminho']);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <title>Painel do Médico</title>
  <link rel="stylesheet" href="css/medico.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body>

<header>
  <div class="header-brand">
    <div class="brand-icon">
      <i class="fa-solid fa-notes-medical"></i>
    </div>
    <div class="brand-text">
      <span class="brand-title">ClinAgenda</span>
      <span class="brand-sub">Painel do Médico</span>
    </div>
  </div>

  <a href="?logout=1" class="logout-link">sair</a>
</header>

<div class="painel-central">

  <form id="form-perfil" method="post">
    <input type="hidden" name="acao" value="salvar_perfil">
  </form>

  <div class="nav-buttons" id="tabs">
    <button type="button" class="tab-btn active" data-target="perfil" onclick="mostrarSecao('perfil', this)">Perfil</button>
    <button type="button" class="tab-btn" data-target="horarios" onclick="mostrarSecao('horarios', this)">Meus Horários</button>
    <button type="button" class="tab-btn" data-target="consultas" onclick="mostrarSecao('consultas', this)">Consultas</button>
  </div>

  <!-- PERFIL -->
  <div id="secao-perfil" class="section active">
    <div class="cards-grid">

      <div class="card">
        <div class="card-header">
          <i class="fa-regular fa-id-badge"></i>
          <span>Identidade</span>
        </div>

        <form id="form-foto" method="post" enctype="multipart/form-data">
          <input type="hidden" name="acao" value="upload_foto">
          <div class="profile-pic-wrapper">
            <img id="foto-perfil" src="<?= $foto ?>" alt="Foto do médico"
                 title="Clique para selecionar uma nova foto"
                 onclick="document.getElementById('input-foto').click();">
          </div>
          <input type="file" id="input-foto" name="nova_foto" accept="image/*" style="display:none">
        </form>

        <h2 class="medico-nome"><?= safe($medico['nome'] ?? '') ?></h2>
        <div class="crm">CRM: <?= safe($medico['crm'] ?? '') ?></div>

        <div class="mini-grid">
          <div class="form-group">
            <label>Nome</label>
            <input type="text" value="<?= safe($medico['nome'] ?? '') ?>" readonly>
          </div>
          <div class="form-group">
            <label>CRM</label>
            <input type="text" value="<?= safe($medico['crm'] ?? '') ?>" readonly>
          </div>
          <div class="form-group">
            <label>Especialidade</label>
            <input type="text" value="<?= safe($medico['especialidade'] ?? '') ?>" readonly>
          </div>
          <div class="form-group">
            <label>CPF</label>
            <input type="text" value="<?= safe($medico['cpf'] ?? '') ?>" readonly>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <i class="fa-regular fa-pen-to-square"></i>
          <span>Biografia</span>
        </div>
        <div class="biografia-wrapper">
          <textarea id="bio" name="biografia" maxlength="600" form="form-perfil"
            placeholder="Escreva a sua descrição aqui (máx. 600 caracteres)..."><?= safe($medico['biografia'] ?? '') ?></textarea>
          <span class="char-counter" id="contador">0/600</span>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <i class="fa-regular fa-envelope"></i>
          <span>Contato</span>
        </div>
        <div class="mini-grid">
          <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" form="form-perfil" value="<?= safe($medico['email'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label>Telefone</label>
            <input type="text" name="telefone" form="form-perfil" value="<?= safe($medico['telefone'] ?? '') ?>">
          </div>
          <div class="form-group full">
            <label>Endereço</label>
            <input type="text" name="endereco" form="form-perfil" value="<?= safe($medico['endereco'] ?? '') ?>">
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <i class="fa-regular fa-calendar-check"></i>
          <span>Informações Complementares</span>
        </div>
        <div class="mini-grid">
          <div class="form-group">
            <label>Data de Nascimento</label>
            <input type="date" name="data_nascimento" form="form-perfil" value="<?= safe($medico['data_nascimento'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label>Gênero</label>
            <select name="genero" form="form-perfil">
              <option <?= (isset($medico['genero']) && $medico['genero']=='Masculino') ? 'selected' : '' ?>>Masculino</option>
              <option <?= (isset($medico['genero']) && $medico['genero']=='Feminino') ? 'selected' : '' ?>>Feminino</option>
              <option <?= (isset($medico['genero']) && $medico['genero']=='Outro') ? 'selected' : '' ?>>Outro</option>
            </select>
          </div>
        </div>

        <div class="btn-container right">
          <button type="submit" class="btn-salvar" form="form-perfil">
            <i class="fa-solid fa-floppy-disk"></i> Salvar Alterações
          </button>
        </div>
      </div>

    </div>
  </div>

  <!-- MEUS HORÁRIOS -->
  <div id="secao-horarios" class="section">
    <div class="cards-grid two">
      <div class="card">
        <div class="card-header">
          <i class="fa-regular fa-hand-pointer"></i>
          <span>Modo de Seleção</span>
        </div>
        <div class="modo-selecao">
          <span>Seleção:</span>
          <button type="button" id="btn-dia-unico" class="modo-btn ativo" onclick="mudarModoSelecao('unico')">Dia Único</button>
          <button type="button" id="btn-varios-dias" class="modo-btn" onclick="mudarModoSelecao('intervalo')">Vários Dias</button>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <i class="fa-regular fa-calendar-days"></i>
          <span>Calendário</span>
        </div>
        <div class="calendar">
          <div class="calendar-header">
            <button type="button" onclick="mudarMes(-1)" class="arrow" aria-label="Mês anterior">&lt;</button>
            <span id="mes-ao"></span>
            <button type="button" onclick="mudarMes(1)" class="arrow" aria-label="Próximo mês">&gt;</button>
          </div>
          <div class="calendar-days">
            <div>Dom</div><div>Seg</div><div>Ter</div><div>Qua</div><div>Qui</div><div>Sex</div><div>Sáb</div>
          </div>
          <div class="calendar-grid" id="calendar-grid"></div>
        </div>
      </div>

      <div class="card stretch">
        <div class="card-header">
          <i class="fa-regular fa-clock"></i>
          <span>Horários</span>
        </div>
        <form method="post" class="form-horarios" id="form-horarios">
          <div id="horarios-box" class="horarios-box horarios-inativos">
            <h4>Horários para os dias selecionados:</h4>
            <div id="lista-horarios" class="horarios-grid"></div>
            <div class="observacao">Clique para <b>bloquear</b> um horário. Clique novamente para <b>desbloquear</b>.</div>
          </div>

          <div class="btn-container between">
            <input type="hidden" name="dias_selecionados" id="dias_selecionados">
            <input type="hidden" name="horarios_selecionados" id="horarios_selecionados">
            <button type="button" class="btn-line" onclick="selecionarTodosHorarios(true)"><i class="fa-solid fa-ban"></i> Bloquear Todos</button>
            <button type="button" class="btn-line" onclick="selecionarTodosHorarios(false)"><i class="fa-solid fa-rotate-left"></i> Limpar</button>
            <button type="submit" name="salvar_bloqueios" class="btn-salvar"><i class="fa-solid fa-floppy-disk"></i> Salvar Bloqueios</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- CONSULTAS -->
  <div id="secao-consultas" class="section">
    <div class="cards-grid">
      <div class="card">
        <div class="card-header">
          <i class="fa-regular fa-clipboard"></i>
          <span>Consultas Agendadas</span>
        </div>

        <div class="consultas-toolbar" style="display:flex; gap:8px; margin-bottom:10px;">
          <button type="button" class="btn-line" onclick="marcarFiltro(this); carregarConsultas('hoje')" data-filtro="hoje">Hoje</button>
          <button type="button" class="btn-line" onclick="marcarFiltro(this); carregarConsultas('amanha')" data-filtro="amanha">Amanhã</button>
          <button type="button" class="btn-line" onclick="marcarFiltro(this); carregarConsultas('futuras')" data-filtro="futuras">Futuras</button>
          <button type="button" class="btn-line" onclick="marcarFiltro(this); carregarConsultas('todas')" data-filtro="todas">Todas</button>
        </div>

        <div class="consultas-section">
          <div id="lista-consultas" class="lista-consultas vazia">
            <div class="hint">Nenhuma consulta carregada por enquanto.</div>
          </div>

          <div class="pager" style="display:flex; align-items:center; gap:12px; justify-content:center; margin-top:10px;">
            <button type="button" class="btn-line" id="btnPrev" onclick="mudarPagina(-1)">« Anterior</button>
            <span id="pageInfo" style="min-width:80px; text-align:center; font-weight:700;">0 / 0</span>
            <button type="button" class="btn-line" id="btnNext" onclick="mudarPagina(1)">Próxima »</button>
          </div>
        </div>
      </div>
    </div>
  </div>

</div>

<!-- Sheet de prescrição de exames -->
<div id="sheet-prescricao" class="sheet" aria-hidden="true">
  <div class="sheet-box">
    <div class="sheet-header">
      <div>
        <h3 class="sheet-title">Prescrever Exames</h3>
        <div class="sheet-sub" id="sheet-context">Paciente — Consulta</div>
      </div>
      <div class="sheet-actions">
        <button class="btn danger" onclick="fecharSheet()">Fechar</button>
        <button class="btn primary" id="btnSalvarExames" onclick="salvarPrescricao()">Salvar exames</button>
      </div>
    </div>

    <div class="grid-2">
      <div class="field ac-wrap">
        <label>Buscar TUSS (código ou nome)</label>
        <input type="text" id="tussBusca" placeholder="Ex.: Hemograma, 20103017..." oninput="buscarTuss(this.value)">
        <div id="acList" class="ac-list"></div>
        <div class="help">Se não existir a tabela <code>tuss_exames</code>, digite manualmente e adicione.</div>
      </div>
      <div class="field">
        <label>Adicionar manualmente</label>
        <div style="display:flex; gap:6px;">
          <input type="text" id="manCodigo" placeholder="Código TUSS (opcional)" style="flex:1;">
          <input type="text" id="manDesc" placeholder="Descrição do exame" style="flex:2;">
          <button class="btn" onclick="adicionarItemManual()">Adicionar</button>
        </div>
      </div>
    </div>

    <table class="table" id="tabItens">
      <thead>
        <tr>
          <th style="width:140px">Código TUSS</th>
          <th>Descrição</th>
          <th style="width:120px">Prioridade</th>
          <th style="width:110px">Jejum</th>
          <th style="width:160px">Data sugerida</th>
          <th>Observações</th>
          <th style="width:100px">Ações</th>
        </tr>
      </thead>
      <tbody id="itensBody">
        <tr><td colspan="7" class="muted">Nenhum item ainda.</td></tr>
      </tbody>
    </table>

    <div class="help" style="margin-top:8px">
      Exames criados com status <b>pendente</b>. Campos opcionais são gravados somente se a coluna existir em <code>exames</code>.
    </div>
  </div>
</div>

<script>
// Descobre o próprio arquivo
const SELF_URL = (()=>{ 
  const p = new URL(location.href).pathname; 
  const base = p.substring(p.lastIndexOf('/')+1) || 'medico.php'; 
  return base; 
})();

// Tabs
function mostrarSecao(secao, btn) {
  document.querySelectorAll('.section').forEach(div => div.classList.remove('active'));
  document.getElementById('secao-' + secao).classList.add('active');
  document.querySelectorAll('#tabs .tab-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');

  if (secao === 'consultas') {
    const first = document.querySelector('.consultas-toolbar .btn-line[data-filtro="hoje"]');
    if (first) { 
      document.querySelectorAll('.consultas-toolbar .btn-line').forEach(b=>b.classList.remove('active')); 
      first.classList.add('active'); 
    }
    carregarConsultas('hoje');
  }
}

// Upload foto
const inputFoto = document.getElementById('input-foto');
const imgFoto   = document.getElementById('foto-perfil');
const formFoto  = document.getElementById('form-foto');
if (inputFoto && imgFoto && formFoto) {
  inputFoto.addEventListener('change', (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    const url = URL.createObjectURL(file);
    imgFoto.src = url;
    formFoto.submit();
  });
}

// Contador bio
function atualizarContador(el) {
  const max = el.maxLength || 600;
  if (el.value.length > max) el.value = el.value.slice(0, max);
  const contador = document.getElementById('contador');
  if (contador) contador.innerText = `${el.value.length}/${max}`;
}

/* ==========================================================
   CALENDÁRIO / INTERVALO DE DIAS - VISUAL
   ========================================================== */

let dataAtual    = new Date();
let intervalo    = { inicio: null, fim: null }; // datas JS
let modoSelecao  = 'unico';                    // 'unico' ou 'intervalo'
let diaFocadoISO = null;                       // último dia clicado (YYYY-MM-DD)

// Muda entre "Dia Único" e "Vários Dias"
function mudarModoSelecao(modo) {
  modoSelecao = modo;

  document.getElementById('btn-dia-unico')?.classList.toggle('ativo', modo === 'unico');
  document.getElementById('btn-varios-dias')?.classList.toggle('ativo', modo === 'intervalo');

  // limpa seleção
  intervalo = { inicio: null, fim: null };
  diaFocadoISO = null;
  document.getElementById("dias_selecionados").value = '';
  document.getElementById("horarios-box").classList.add("horarios-inativos");

  renderizarCalendario();
}

// Navegar mês
function mudarMes(delta) {
  const dia = dataAtual.getDate();
  dataAtual.setDate(1);
  dataAtual.setMonth(dataAtual.getMonth() + delta);
  const ultimo = new Date(dataAtual.getFullYear(), dataAtual.getMonth() + 1, 0).getDate();
  dataAtual.setDate(Math.min(dia, ultimo));
  document.getElementById("horarios-box").classList.add("horarios-inativos");
  renderizarCalendario();
}

// Classe visual de cada célula do calendário conforme o intervalo
function classeDiaNoIntervalo(data) {
  if (!intervalo.inicio) return '';

  const d = new Date(data);
  const i = new Date(intervalo.inicio);
  const f = intervalo.fim ? new Date(intervalo.fim) : new Date(intervalo.inicio);

  d.setHours(0,0,0,0);
  i.setHours(0,0,0,0);
  f.setHours(0,0,0,0);

  if (d.getTime() === i.getTime() || d.getTime() === f.getTime()) {
    return 'dia-borda';           // extremidades → verde forte
  }
  if (d > i && d < f) {
    return 'dia-intermediario';   // meio do intervalo → verde claro
  }
  return '';
}

// Renderiza o calendário do mês atual
function renderizarCalendario() {
  const grid   = document.getElementById("calendar-grid");
  const titulo = document.getElementById("mes-ao");
  grid.innerHTML = "";

  const ano = dataAtual.getFullYear();
  const mes = dataAtual.getMonth();
  const primeiroDia = new Date(ano, mes, 1).getDay();
  const diasNoMes   = new Date(ano, mes + 1, 0).getDate();

  const hoje = new Date(); 
  hoje.setHours(0, 0, 0, 0);

  titulo.innerText = `${dataAtual.toLocaleString('pt-BR', { month: 'long' })} ${ano}`;

  // espaços vazios antes do dia 1
  for (let i = 0; i < primeiroDia; i++) {
    grid.appendChild(document.createElement('div'));
  }

  // dias do mês
  for (let dia = 1; dia <= diasNoMes; dia++) {
    const data = new Date(ano, mes, dia); 
    data.setHours(0,0,0,0);
    const dataISO = data.toISOString().split('T')[0];

    const div = document.createElement("div");
    div.innerText = dia;
    div.dataset.data = dataISO;

    if (data < hoje) {
      div.className = "dia-desativado";
    } else {
      div.className = classeDiaNoIntervalo(data);
      div.onclick = () => selecionarDia(data);
    }
    grid.appendChild(div);
  }
}

// Quando o médico clica em um dia do calendário
function selecionarDia(data) {
  const iso = data.toISOString().split('T')[0];
  diaFocadoISO = iso;

  if (modoSelecao === 'unico') {
    intervalo.inicio = data;
    intervalo.fim    = data;
  } else {
    if (!intervalo.inicio || intervalo.fim) {
      // começando novo intervalo
      intervalo.inicio = data;
      intervalo.fim    = null;
    } else {
      // fechando intervalo
      if (data < intervalo.inicio) {
        intervalo.fim    = intervalo.inicio;
        intervalo.inicio = data;
      } else {
        intervalo.fim = data;
      }
    }
  }

  preencherDiasSelecionados();
  renderizarCalendario();                // redesenha com verde forte / claro

  if (document.getElementById("dias_selecionados").value !== '') {
    document.getElementById("horarios-box").classList.remove("horarios-inativos");
  }

  // sempre que mudar de dia, redesenha horários e aplica bloqueios do dia focado
  desenharListaHorarios();
  if (diaFocadoISO) {
    carregarBloqueiosDia(diaFocadoISO);
  }
}

// Preenche o hidden com todos os dias (YYYY-MM-DD) selecionados no intervalo
function preencherDiasSelecionados() {
  const campo = document.getElementById("dias_selecionados");
  if (!intervalo.inicio) {
    campo.value = '';
    return;
  }

  const inicio = new Date(intervalo.inicio);
  const fim    = intervalo.fim ? new Date(intervalo.fim) : new Date(intervalo.inicio);

  inicio.setHours(0,0,0,0);
  fim.setHours(0,0,0,0);

  const dias = [];
  let atual = new Date(inicio);

  while (atual <= fim) {
    dias.push(atual.toISOString().split('T')[0]);
    atual.setDate(atual.getDate() + 1);
  }

  campo.value = dias.join(',');
}

/* ==========================================================
   HORÁRIOS - BOTÕES E BLOQUEIOS
   ========================================================== */

// Gera slots de 40 em 40 min, pulando almoço (11h–13h)
function gerarSlotsDoDia() {
  const slots = [];
  let t = 8 * 60;
  const fim = 17 * 60 + 40;
  while (t <= fim) {
    if (t >= 11 * 60 && t < 13 * 60) { 
      t = 13 * 60; 
      continue; 
    }
    const h = String(Math.floor(t / 60)).padStart(2, '0');
    const m = String(t % 60).padStart(2, '0');
    slots.push(`${h}:${m}`);
    t += 40;
  }
  return slots;
}

// Desenha a grade de horários
function desenharListaHorarios() {
  const grid = document.getElementById("lista-horarios");
  if (!grid) return;
  grid.innerHTML = "";

  gerarSlotsDoDia().forEach(h => {
    const btn = document.createElement("div");
    btn.className = "horario";
    btn.innerText = h;
    btn.onclick = () => btn.classList.toggle("bloqueado");
    grid.appendChild(btn);
  });

  // placeholders só para manter grade harmoniosa
  const totalDesejado = 16;
  const falta = Math.max(0, totalDesejado - grid.children.length);
  for (let i = 0; i < falta; i++) {
    const ghost = document.createElement("div");
    ghost.className = "horario fantasma";
    ghost.setAttribute('aria-hidden', 'true');
    grid.appendChild(ghost);
  }
}

// Carrega horários bloqueados do dia clicado e pinta como bloqueado
function carregarBloqueiosDia(dataISO) {
  if (!dataISO) return;

  fetch(SELF_URL + '?action=listar_bloqueios&data=' + encodeURIComponent(dataISO))
    .then(r => r.json())
    .then(j => {
      if (!j || !j.ok) return;
      const bloqueados = j.bloqueios || [];
      const setBloq = new Set(bloqueados);

      document.querySelectorAll('#lista-horarios .horario').forEach(btn => {
        if (btn.classList.contains('fantasma')) return;
        const h = btn.textContent.trim().substring(0,5);
        if (setBloq.has(h)) {
          btn.classList.add('bloqueado');
        }
      });
    })
    .catch(()=>{ /* silencioso */ });
}

// Botão "Bloquear Todos" / "Limpar"
function selecionarTodosHorarios(bloquear=true){
  document.querySelectorAll('#lista-horarios .horario').forEach(el=>{
    if (!el.classList.contains('fantasma')) {
      el.classList.toggle('bloqueado', bloquear);
    }
  });
}

// Antes de enviar o form, coleta horários bloqueados
document.getElementById('form-horarios')?.addEventListener('submit', (e)=>{
  const marcados = [...document.querySelectorAll('#lista-horarios .horario.bloqueado')]
    .filter(el=>!el.classList.contains('fantasma'))
    .map(el=>el.textContent.trim());
  document.getElementById('horarios_selecionados').value = marcados.join(',');
});

/* ==========================================================
   CONSULTAS (AJAX)
   ========================================================== */

function marcarFiltro(btn){
  document.querySelectorAll('.consultas-toolbar .btn-line').forEach(b=>b.classList.remove('active'));
  btn.classList.add('active');
}

let ALL_ITEMS = [];
let CUR_PAGE = 1;
const PAGE_SIZE = 8;
let CUR_FILTRO = 'todas';

function renderPage(){
  const box = document.getElementById('lista-consultas');
  if (!box) return;

  const total = ALL_ITEMS.length;
  const totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));
  CUR_PAGE = Math.min(Math.max(1, CUR_PAGE), totalPages);

  if (total === 0) {
    box.classList.add('vazia');
    box.innerHTML = '<div class="hint">Nenhuma consulta para o filtro selecionado.</div>';
    document.getElementById('pageInfo').textContent = '0 / 0';
    document.getElementById('btnPrev').disabled = true;
    document.getElementById('btnNext').disabled = true;
    return;
  }

  box.classList.remove('vazia');

  const start = (CUR_PAGE - 1) * PAGE_SIZE;
  const slice = ALL_ITEMS.slice(start, start + PAGE_SIZE);

  const html = slice.map(it => {
    const data = (it.data || '').split('-').reverse().join('/');
    const hora = it.hora || '';
    const st   = (it.status || '').toLowerCase();
    const status = st.charAt(0).toUpperCase() + st.slice(1);
    const badgeClass = st==='pendente' ? 'pendente' : (st==='confirmado' ? 'agendado' : (st==='expirada' ? 'atrasado' : 'neutro'));

    const nome = (it.cliente_nome || 'Paciente').replace(/&/g,'&amp;').replace(/</g,'&lt;');
    const pacienteJS = (it.cliente_nome||'').replace(/'/g,"\\'");

    const btnExames = `<button class="btn" onclick="abrirSheet(${it.id}, ${it.cliente_id}, '${pacienteJS}', '${(it.data||'')}', '${(it.hora||'')}')">Exames</button>`;

    let acoes = '';
    if (st === 'pendente') {
      acoes = `
        <div style="display:flex; gap:6px;">
          <button class="btn-line" onclick="atualizarConsulta(${it.id}, 'confirmado')">Confirmar</button>
          <button class="btn-line" onclick="atualizarConsulta(${it.id}, 'cancelado')">Cancelar</button>
          ${btnExames}
        </div>`;
    } else if (st === 'confirmado') {
      acoes = `
        <div style="display:flex; gap:6px;">
          <button class="btn-line" onclick="atualizarConsulta(${it.id}, 'cancelado')">Cancelar</button>
          ${btnExames}
        </div>`;
    } else {
      acoes = `<div style="display:flex; gap:6px;">${btnExames}</div>`;
    }

    return `
      <div class="consulta-item">
        <div class="topo">
          <div class="nome">${nome}</div>
          <span class="badge ${badgeClass}">${status}</span>
        </div>
        <div class="data-hora">
          <span>${data}</span><br>
          <span>${hora}</span>
        </div>
        <div class="acoes">${acoes}</div>
      </div>
    `;
  }).join('');

  box.innerHTML = html;

  document.getElementById('pageInfo').textContent = `${CUR_PAGE} / ${totalPages}`;
  document.getElementById('btnPrev').disabled = (CUR_PAGE <= 1);
  document.getElementById('btnNext').disabled = (CUR_PAGE >= totalPages);
}

function mudarPagina(delta){
  CUR_PAGE += delta;
  renderPage();
}

function carregarConsultas(filtro){
  CUR_FILTRO = filtro || 'todas';
  CUR_PAGE = 1;
  const box = document.getElementById('lista-consultas');
  if (box) { box.innerHTML = '<div class="hint">Carregando...</div>'; }

  fetch(SELF_URL + '?action=listar_consultas&filtro=' + encodeURIComponent(CUR_FILTRO))
    .then(r => r.json())
    .then(json => {
      if (!json || !json.ok) throw new Error();
      ALL_ITEMS = json.items || [];
      renderPage();
    })
    .catch(() => {
      if (box) box.innerHTML = '<div class="hint">Erro ao carregar consultas.</div>';
      document.getElementById('pageInfo').textContent = '0 / 0';
      document.getElementById('btnPrev').disabled = true;
      document.getElementById('btnNext').disabled = true;
    });
}

function atualizarConsulta(agendamentoId, novoStatus){
  const fd = new FormData();
  fd.append('action','atualizar_consulta');
  fd.append('agendamento_id', agendamentoId);
  fd.append('novo_status', novoStatus);

  fetch(SELF_URL, { method:'POST', body: fd })
    .then(r => r.json())
    .then(j => {
      if (!j || !j.ok) { alert(j?.msg || 'Erro ao atualizar.'); return; }
      carregarConsultas(CUR_FILTRO);
    })
    .catch(()=> alert('Erro de rede.'));
}

/* ==========================================================
   PRESCRIÇÃO DE EXAMES (SHEET)
   ========================================================== */

const sheet = document.getElementById('sheet-prescricao');
const contextEl = document.getElementById('sheet-context');
const itensBody = document.getElementById('itensBody');
const acList = document.getElementById('acList');

let RX = {
  agendamento_id: 0,
  cliente_id: 0,
  paciente: '',
  data:'',
  hora:'',
  itens:[]
};

function abrirSheet(agendamento_id, cliente_id, paciente, dataISO, hora){
  RX = { agendamento_id, cliente_id, paciente, data: dataISO, hora: hora, itens: [] };
  contextEl.textContent = `${paciente} • ${dataISO ? dataISO.split('-').reverse().join('/') : ''} ${hora?('às '+hora):''}`;
  renderItens();
  sheet.classList.add('show');
  sheet.setAttribute('aria-hidden', 'false');
  document.getElementById('tussBusca').value = '';
  document.getElementById('tussBusca').focus();
}

function fecharSheet(){
  sheet.classList.remove('show');
  sheet.setAttribute('aria-hidden', 'true');
}

function renderItens(){
  if (!RX.itens.length){
    itensBody.innerHTML = `<tr><td colspan="7" class="muted">Nenhum item ainda.</td></tr>`;
    return;
  }
  itensBody.innerHTML = RX.itens.map((it, idx) => `
    <tr>
      <td><input type="text" value="${it.tuss_codigo||''}" oninput="editItem(${idx}, 'tuss_codigo', this.value)"></td>
      <td><input type="text" value="${it.descricao||''}" oninput="editItem(${idx}, 'descricao', this.value)"></td>
      <td>
        <select onchange="editItem(${idx}, 'prioridade', this.value)">
          <option ${it.prioridade==='baixa'?'selected':''}>baixa</option>
          <option ${(!it.prioridade || it.prioridade==='média')?'selected':''}>média</option>
          <option ${it.prioridade==='alta'?'selected':''}>alta</option>
        </select>
      </td>
      <td style="text-align:center"><input type="checkbox" ${it.jejum? 'checked':''} onchange="editItem(${idx}, 'jejum', this.checked)"></td>
      <td><input type="date" value="${it.data_sugerida||''}" onchange="editItem(${idx}, 'data_sugerida', this.value)"></td>
      <td><input type="text" value="${it.observacoes||''}" oninput="editItem(${idx}, 'observacoes', this.value)"></td>
      <td class="row-actions">
        <button class="btn" onclick="remItem(${idx})">Remover</button>
      </td>
    </tr>
  `).join('');
}

function editItem(idx, field, val){
  RX.itens[idx][field] = val;
}

function remItem(idx){
  RX.itens.splice(idx,1);
  renderItens();
}

function addItem(obj){
  RX.itens.push({
    tuss_codigo: obj.codigo || '',
    descricao: obj.descricao || '',
    prioridade: 'média',
    jejum: 0,
    data_sugerida: '',
    observacoes: ''
  });
  renderItens();
}

function adicionarItemManual(){
  const c = document.getElementById('manCodigo').value.trim();
  const d = document.getElementById('manDesc').value.trim();
  if (!c && !d){ alert('Informe ao menos o código ou a descrição.'); return; }
  addItem({codigo:c, descricao:d});
  document.getElementById('manCodigo').value='';
  document.getElementById('manDesc').value='';
}

// Autocomplete TUSS
let tussDeb;
function buscarTuss(q){
  clearTimeout(tussDeb);
  if (!q){ acList.classList.remove('show'); acList.innerHTML=''; return; }
  tussDeb = setTimeout(()=>{
    fetch(SELF_URL + '?action=buscar_tuss&q=' + encodeURIComponent(q))
      .then(r=>r.json())
      .then(j=>{
        if (!j.ok){ acList.classList.remove('show'); return; }
        if (!j.items || !j.items.length){ acList.classList.remove('show'); acList.innerHTML=''; return; }
        acList.innerHTML = j.items.map(it=>`
          <div class="ac-item" onclick='selTuss(${JSON.stringify(it).replace(/'/g,"&#39;")})'>
            <b>${it.codigo}</b> — ${it.descricao}
          </div>
        `).join('');
        acList.classList.add('show');
      })
      .catch(()=>{ acList.classList.remove('show'); });
  }, 250);
}

function selTuss(it){
  acList.classList.remove('show');
  addItem({codigo: it.codigo, descricao: it.descricao});
  document.getElementById('tussBusca').value = '';
}

// Salvar prescrição
function salvarPrescricao(){
  if (!RX.agendamento_id){ alert('Agendamento inválido.'); return; }
  if (!RX.itens.length){ alert('Adicione ao menos um exame.'); return; }

  const btn = document.getElementById('btnSalvarExames');
  btn.disabled = true;

  fetch(SELF_URL + '?action=prescrever_exames', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({
      agendamento_id: RX.agendamento_id,
      items: RX.itens
    })
  })
  .then(r=>r.json())
  .then(j=>{
    btn.disabled = false;
    alert(j.msg || (j.ok?'Exames salvos.':'Falha ao salvar.'));
    if (j.ok){ fecharSheet(); }
  })
  .catch(()=>{
    btn.disabled = false;
    alert('Erro de rede.');
  });
}

// Init
window.addEventListener('DOMContentLoaded', () => {
  const bio = document.getElementById('bio');
  if (bio) { 
    atualizarContador(bio); 
    bio.addEventListener('input', () => atualizarContador(bio)); 
  }

  renderizarCalendario();
  desenharListaHorarios();
  mudarModoSelecao('unico');

  if (new URLSearchParams(location.search).get('salvo') === '1') {
    console.log('Perfil salvo com sucesso!');
  }
});
</script>

</body>
</html>
