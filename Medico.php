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

// ID do médico logado (FALTAVA ISSO)
$id = (int)$_SESSION['id_medico'];

if (isset($_GET['logout'])) {
  session_unset();
  session_destroy();
  header("Location: index.php");
  exit();
}

// ID do paciente para o chat (ex.: medico.php?paciente=5)
$chatPacienteId = isset($_GET['paciente']) ? (int)$_GET['paciente'] : 0;

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

      header("Location: Medico.php?foto=ok");
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

  header("Location: Medico.php?salvo=1");
  exit;
}

/* ==================== SALVAR BLOQUEIOS (SALVAR MUDANÇAS) ==================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salvar_bloqueios'])) {
  $dias = array_filter(array_map('trim', explode(',', $_POST['dias_selecionados'] ?? '')));
  $horariosBrutos = array_filter(array_map('trim', explode(',', $_POST['horarios_selecionados'] ?? '')));

  $horariosHM = [];
  foreach ($horariosBrutos as $h) {
    if (is_valid_time_hm($h)) {
      $horariosHM[] = $h;
    }
  }

  if (empty($dias)) {
    echo "<script>alert('Selecione ao menos um dia no calendário.');</script>";
  } else {
    $hoje = new DateTime('today', new DateTimeZone('America/Sao_Paulo'));
    $insOk = 0;
    $remOk = 0;
    $ignorados = 0;

    $chkAg = $conn->prepare("
      SELECT 1 FROM agendamentos 
      WHERE medico_id=? AND data=? AND horario=? 
        AND status IN ('pendente','confirmado') 
      LIMIT 1
    ");

    foreach ($dias as $data) {
      if (!is_valid_date($data)) { $ignorados++; continue; }

      $dSel = DateTime::createFromFormat('Y-m-d', $data, new DateTimeZone('America/Sao_Paulo'));
      if (!$dSel || $dSel < $hoje) { $ignorados++; continue; }

      $selBloq = $conn->prepare("
        SELECT id, DATE_FORMAT(horario, '%H:%i') AS h 
        FROM horarios_disponiveis 
        WHERE medico_id=? AND data=? AND ocupado=1
      ");
      $selBloq->bind_param("is", $id, $data);
      $selBloq->execute();
      $resBloq = $selBloq->get_result();

      $existentes = [];
      while ($row = $resBloq->fetch_assoc()) {
        $existentes[$row['h']] = (int)$row['id'];
      }

      $novosSet = [];
      foreach ($horariosHM as $h) {
        $novosSet[$h] = true;
      }

      foreach ($existentes as $h => $rowId) {
        $horaHMS = $h . ':00';

        $chkAg->bind_param("iss", $id, $data, $horaHMS);
        $chkAg->execute();
        $rAg = $chkAg->get_result();
        if ($rAg && $rAg->num_rows) {
          $ignorados++;
          unset($novosSet[$h]);
          continue;
        }

        if (!isset($novosSet[$h])) {
          $del = $conn->prepare("DELETE FROM horarios_disponiveis WHERE id=?");
          $del->bind_param("i", $rowId);
          if ($del->execute()) $remOk++;
        } else {
          unset($novosSet[$h]);
        }
      }

      foreach (array_keys($novosSet) as $h) {
        $horaHMS = $h . ':00';

        $chkAg->bind_param("iss", $id, $data, $horaHMS);
        $chkAg->execute();
        $rAg = $chkAg->get_result();
        if ($rAg && $rAg->num_rows) {
          $ignorados++;
          continue;
        }

        $ins = $conn->prepare("
          INSERT INTO horarios_disponiveis (medico_id, data, horario, ocupado) 
          VALUES (?, ?, ?, 1)
        ");
        $ins->bind_param("iss", $id, $data, $horaHMS);
        if ($ins->execute()) $insOk++;
      }
    }

    echo "<script>alert('Salvar Mudanças concluído. Bloqueados: {$insOk}, Desbloqueados: {$remOk}, Ignorados: {$ignorados}.');</script>";
  }
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
    <button type="button" class="tab-btn" data-target="chat" onclick="mostrarSecao('chat', this)">Chat</button>
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
            <button type="submit" name="salvar_bloqueios" class="btn-salvar"><i class="fa-solid fa-floppy-disk"></i> Salvar Mudanças</button>
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

<!-- CHAT MÉDICO ⇆ PACIENTE – LAYOUT TIPO WHATSAPP -->
<div id="secao-chat" class="section">
  <div class="chat-layout">
    <aside class="chat-sidebar">
  <div class="chat-sidebar-header">
    <span>Conversas</span>
  </div>

  <!-- LISTA SCROLLÁVEL DE CONTATOS (PACIENTES) -->
  <div id="chat-conversas-list" class="chat-conversas-list">
    <!-- preenche via JS -->
  </div>
</aside>


    <!-- COLUNA DIREITA: CHAT ATUAL -->
    <section class="chat-main">
      <!-- cabeçalho do chat (clique mostra/esconde info) -->
      <div class="chat-main-header" onclick="toggleChatInfo()">
        <div class="chat-main-avatar" id="chat-header-avatar">
          <!-- iniciais do contato -->
        </div>
        <div class="chat-main-titles">
          <div id="chat-header-title">Selecione uma conversa</div>
          <div id="chat-header-sub" class="chat-header-sub">Nenhum chat ativo</div>
        </div>
        <button class="chat-info-btn" type="button"
                onclick="toggleChatInfo(); event.stopPropagation();">
          <i class="fa-solid fa-circle-info"></i>
        </button>
      </div>

      <!-- corpo: mensagens + painel de info -->
      <div class="chat-main-body">
        <div id="chat-mensagens" class="chat-mensagens">
          <div class="chat-msg chat-sistema">
            Selecione uma conversa na lista à esquerda ou crie um novo chat.
          </div>
        </div>

        <div id="chat-info-panel" class="chat-info-panel">
          <h4>Informações do contato</h4>
          <p><strong>Nome:</strong> <span id="chat-info-nome">—</span></p>
          <p><strong>Tipo:</strong> <span id="chat-info-tipo">—</span></p>
          <p><strong>ID:</strong> <span id="chat-info-id">—</span></p>
          <!-- você pode acrescentar mais infos depois (email, telefone, etc) -->
        </div>
      </div>

      <!-- input da mensagem -->
      <div class="chat-input">
        <input type="text" id="chat_texto" placeholder="Digite uma mensagem..."
               onkeydown="if(event.key==='Enter'){enviarMsgChat();}">
        <button type="button" class="btn-salvar" onclick="enviarMsgChat()">
          <i class="fa-regular fa-paper-plane"></i> Enviar
        </button>
      </div>
    </section>

  </div>
</div>


<!-- ========== SHEET DE PRESCRIÇÃO DE EXAMES ========== -->
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

    <!-- BUSCA TUSS + ADICIONAR MANUAL -->
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

    <!-- EXAMES RÁPIDOS -->
    <div class="quick-box">
      <h4>Exames Rápidos</h4>
      <div id="quick-exames" class="quick-grid"></div>
    </div>

    <!-- TABELA DE ITENS -->
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
      Exames criados com status <b>pendente</b>. Campos opcionais só são salvos se existirem na tabela <code>exames</code>.
    </div>

  </div>
</div>

<script>
// Descobre o próprio arquivo
const SELF_URL = (() => { 
  const p = new URL(location.href).pathname; 
  const base = p.substring(p.lastIndexOf('/')+1) || 'Medico.php'; 
  return base; 
})();

/* =========================================================
   TABS (Perfil / Meus Horários / Consultas / Chat)
   ========================================================= */
let CHAT_LIST_CARREGADA = false; // usado em mostrarSecao

function mostrarSecao(secao, btn) {
  // esconde todas as seções
  document.querySelectorAll('.section').forEach(div => div.classList.remove('active'));
  // mostra a seção clicada
  const alvo = document.getElementById('secao-' + secao);
  if (alvo) alvo.classList.add('active');

  // estado visual dos botões
  document.querySelectorAll('#tabs .tab-btn').forEach(b => b.classList.remove('active'));
  if (btn) btn.classList.add('active');

  // ações ao trocar de aba
  if (secao === 'consultas') {
    const first = document.querySelector('.consultas-toolbar .btn-line[data-filtro="hoje"]');
    if (first) { 
      document.querySelectorAll('.consultas-toolbar .btn-line').forEach(b=>b.classList.remove('active')); 
      first.classList.add('active'); 
    }
    carregarConsultas('hoje');
  } else if (secao === 'chat') {
    if (!CHAT_LIST_CARREGADA) {
      carregarListaConversas();
    }
  }
}

/* =========================================================
   UPLOAD DE FOTO
   ========================================================= */
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

/* =========================================================
   CONTADOR DE BIOGRAFIA
   ========================================================= */
function atualizarContador(el) {
  const max = el.maxLength || 600;
  if (el.value.length > max) el.value = el.value.slice(0, max);
  const contador = document.getElementById('contador');
  if (contador) contador.innerText = `${el.value.length}/${max}`;
}

/* =========================================================
   CALENDÁRIO / INTERVALO DE DIAS - VISUAL
   ========================================================= */

let dataAtual    = new Date();
let intervalo    = { inicio: null, fim: null }; 
let modoSelecao  = 'unico';                    
let diaFocadoISO = null;                       

function mudarModoSelecao(modo) {
  modoSelecao = modo;

  document.getElementById('btn-dia-unico')?.classList.toggle('ativo', modo === 'unico');
  document.getElementById('btn-varios-dias')?.classList.toggle('ativo', modo === 'intervalo');

  intervalo = { inicio: null, fim: null };
  diaFocadoISO = null;
  document.getElementById("dias_selecionados").value = '';
  document.getElementById("horarios-box").classList.add("horarios-inativos");

  renderizarCalendario();
}

function mudarMes(delta) {
  const dia = dataAtual.getDate();
  dataAtual.setDate(1);
  dataAtual.setMonth(dataAtual.getMonth() + delta);
  const ultimo = new Date(dataAtual.getFullYear(), dataAtual.getMonth() + 1, 0).getDate();
  dataAtual.setDate(Math.min(dia, ultimo));
  document.getElementById("horarios-box").classList.add("horarios-inativos");
  renderizarCalendario();
}

function classeDiaNoIntervalo(data) {
  if (!intervalo.inicio) return '';

  const d = new Date(data);
  const i = new Date(intervalo.inicio);
  const f = intervalo.fim ? new Date(intervalo.fim) : new Date(intervalo.inicio);

  d.setHours(0,0,0,0);
  i.setHours(0,0,0,0);
  f.setHours(0,0,0,0);

  if (d.getTime() === i.getTime() || d.getTime() === f.getTime()) {
    return 'dia-borda';
  }
  if (d > i && d < f) {
    return 'dia-intermediario';
  }
  return '';
}

function renderizarCalendario() {
  const grid   = document.getElementById("calendar-grid");
  const titulo = document.getElementById("mes-ao");
  if (!grid || !titulo) return;

  grid.innerHTML = "";

  const ano = dataAtual.getFullYear();
  const mes = dataAtual.getMonth();
  const primeiroDia = new Date(ano, mes, 1).getDay();
  const diasNoMes   = new Date(ano, mes + 1, 0).getDate();

  const hoje = new Date(); 
  hoje.setHours(0, 0, 0, 0);

  titulo.innerText = `${dataAtual.toLocaleString('pt-BR', { month: 'long' })} ${ano}`;

  for (let i = 0; i < primeiroDia; i++) {
    grid.appendChild(document.createElement('div'));
  }

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

function selecionarDia(data) {
  const iso = data.toISOString().split('T')[0];
  diaFocadoISO = iso;

  if (modoSelecao === 'unico') {
    intervalo.inicio = data;
    intervalo.fim    = data;
  } else {
    if (!intervalo.inicio || intervalo.fim) {
      intervalo.inicio = data;
      intervalo.fim    = null;
    } else {
      if (data < intervalo.inicio) {
        intervalo.fim    = intervalo.inicio;
        intervalo.inicio = data;
      } else {
        intervalo.fim = data;
      }
    }
  }

  preencherDiasSelecionados();
  renderizarCalendario();

  if (document.getElementById("dias_selecionados").value !== '') {
    document.getElementById("horarios-box").classList.remove("horarios-inativos");
  }

  desenharListaHorarios();
  if (diaFocadoISO) {
    carregarBloqueiosDia(diaFocadoISO);
  }
}

function preencherDiasSelecionados() {
  const campo = document.getElementById("dias_selecionados");
  if (!campo) return;

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

/* =========================================================
   HORÁRIOS - BOTÕES E BLOQUEIOS
   ========================================================= */

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

  const totalDesejado = 16;
  const falta = Math.max(0, totalDesejado - grid.children.length);
  for (let i = 0; i < falta; i++) {
    const ghost = document.createElement("div");
    ghost.className = "horario fantasma";
    ghost.setAttribute('aria-hidden', 'true');
    grid.appendChild(ghost);
  }
}

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
    .catch(()=>{ });
}

function selecionarTodosHorarios(bloquear=true){
  document.querySelectorAll('#lista-horarios .horario').forEach(el=>{
    if (!el.classList.contains('fantasma')) {
      el.classList.toggle('bloqueado', bloquear);
    }
  });
}

document.getElementById('form-horarios')?.addEventListener('submit', (e)=>{
  const marcados = [...document.querySelectorAll('#lista-horarios .horario.bloqueado')]
    .filter(el=>!el.classList.contains('fantasma'))
    .map(el=>el.textContent.trim().substring(0,5));
  const campo = document.getElementById('horarios_selecionados');
  if (campo) campo.value = marcados.join(',');
});

/* =========================================================
   CONSULTAS (AJAX)
   ========================================================= */

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
    const info = document.getElementById('pageInfo');
    const prev = document.getElementById('btnPrev');
    const next = document.getElementById('btnNext');
    if (info) info.textContent = '0 / 0';
    if (prev) prev.disabled = true;
    if (next) next.disabled = true;
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

    const btnExames = `<button class="btn" type="button" onclick="abrirSheet(${it.id}, ${it.cliente_id}, '${pacienteJS}', '${(it.data||'')}', '${(it.hora||'')}')">Exames</button>`;

    let acoes = '';
    if (st === 'pendente') {
      acoes = `
        <div style="display:flex; gap:6px;">
          <button class="btn-line" type="button" onclick="atualizarConsulta(${it.id}, 'confirmado')">Confirmar</button>
          <button class="btn-line" type="button" onclick="atualizarConsulta(${it.id}, 'cancelado')">Cancelar</button>
          ${btnExames}
        </div>`;
    } else if (st === 'confirmado') {
      acoes = `
        <div style="display:flex; gap:6px;">
          <button class="btn-line" type="button" onclick="atualizarConsulta(${it.id}, 'cancelado')">Cancelar</button>
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

  const info = document.getElementById('pageInfo');
  const prev = document.getElementById('btnPrev');
  const next = document.getElementById('btnNext');
  if (info) info.textContent = `${CUR_PAGE} / ${totalPages}`;
  if (prev) prev.disabled = (CUR_PAGE <= 1);
  if (next) next.disabled = (CUR_PAGE >= totalPages);
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
      const info = document.getElementById('pageInfo');
      const prev = document.getElementById('btnPrev');
      const next = document.getElementById('btnNext');
      if (info) info.textContent = '0 / 0';
      if (prev) prev.disabled = true;
      if (next) next.disabled = true;
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

/* =========================================================
   PRESCRIÇÃO DE EXAMES (SHEET) + TUSS RÁPIDO
   ========================================================= */

const sheet     = document.getElementById('sheet-prescricao');
const contextEl = document.getElementById('sheet-context');
const itensBody = document.getElementById('itensBody');
const acList    = document.getElementById('acList');

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
  if (contextEl) {
    contextEl.textContent = `${paciente} • ${dataISO ? dataISO.split('-').reverse().join('/') : ''} ${hora?('às '+hora):''}`;
  }
  renderItens();
  if (sheet) {
    sheet.classList.add('show');
    sheet.setAttribute('aria-hidden', 'false');
  }
  const buss = document.getElementById('tussBusca');
  if (buss) {
    buss.value = '';
    buss.focus();
  }
}

function fecharSheet(){
  if (!sheet) return;
  sheet.classList.remove('show');
  sheet.setAttribute('aria-hidden', 'true');
}

function renderItens(){
  if (!itensBody) return;
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
        <button class="btn" type="button" onclick="remItem(${idx})">Remover</button>
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

/* ========== QUICK TUSS / EXAMES RÁPIDOS ========== */

const TUSS_QUICK = [
  { codigo: "20103017", nome: "Hemograma completo" },
  { codigo: "40801093", nome: "Exame de Urina (EAS)" },
  { codigo: "40303012", nome: "Raio-X Tórax PA" },
  { codigo: "40303021", nome: "Raio-X Coluna" },
  { codigo: "40402017", nome: "Ultrassom Abdômen Total" },
  { codigo: "40808014", nome: "Eletrocardiograma (ECG)" },
  { codigo: "40808022", nome: "Teste Ergométrico" },
  { codigo: "40203015", nome: "Tomografia de Crânio" }
];

function renderQuickButtons() {
  const box = document.getElementById("quick-exames");
  if (!box) return;

  box.innerHTML = TUSS_QUICK.map(e => `
    <button class="btn soft" type="button" onclick='addItemQuick(${JSON.stringify(e).replace(/'/g,"&#39;")})'>
      <i class="fa-solid fa-plus"></i> ${e.nome}
    </button>
  `).join('');
}

function addItemQuick(e){
  addItem({
    codigo: e.codigo,
    descricao: e.nome
  });
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
  if (window.showToast) {
    showToast('Exame adicionado!', 'success');
  }
}

function adicionarItemManual(){
  const c = document.getElementById('manCodigo').value.trim();
  const d = document.getElementById('manDesc').value.trim();
  if (!c && !d){ alert('Informe ao menos o código ou a descrição.'); return; }
  addItem({codigo:c, descricao:d});
  document.getElementById('manCodigo').value='';
  document.getElementById('manDesc').value='';
}

/* ========== AUTOCOMPLETE TUSS COM DESTAQUE ========== */

let tussDeb;
function highlight(str, q) {
  if (!q) return str;
  const reg = new RegExp("(" + q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ")", "ig");
  return String(str).replace(reg, "<mark>$1</mark>");
}

function buscarTuss(q){
  clearTimeout(tussDeb);
  if (!q){
    acList.classList.remove('show');
    acList.innerHTML = '';
    return;
  }
  tussDeb = setTimeout(()=>{
    fetch(SELF_URL + '?action=buscar_tuss&q=' + encodeURIComponent(q))
      .then(r=>r.json())
      .then(j=>{
        if (!j.ok){
          acList.classList.remove('show');
          return;
        }
        if (!j.items || !j.items.length){
          acList.innerHTML = '<div class="ac-empty">Nenhum exame encontrado.</div>';
          acList.classList.add('show');
          return;
        }
        acList.innerHTML = j.items.map(it=>`
          <div class="ac-item" onclick='selTuss(${JSON.stringify(it).replace(/'/g,"&#39;")})'>
            <div class="ac-code">${it.codigo}</div>
            <div class="ac-desc">${highlight(it.descricao, q)}</div>
          </div>
        `).join('');
        acList.classList.add('show');
      })
      .catch(()=>{ acList.classList.remove('show'); });
  }, 200);
}

function selTuss(it){
  acList.classList.remove('show');
  addItem({codigo: it.codigo, descricao: it.descricao});
  const buss = document.getElementById('tussBusca');
  if (buss) buss.value = '';
}

/* ========== SALVAR PRESCRIÇÃO ========== */

function salvarPrescricao(){
  if (!RX.agendamento_id){ alert('Agendamento inválido.'); return; }
  if (!RX.itens.length){ alert('Adicione ao menos um exame.'); return; }

  const btn = document.getElementById('btnSalvarExames');
  if (btn) btn.disabled = true;

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
    if (btn) btn.disabled = false;
    if (j.ok){
      alert(j.msg || 'Exames salvos.');
      fecharSheet();
    } else {
      alert(j.msg || 'Falha ao salvar.');
    }
  })
  .catch(()=>{
    if (btn) btn.disabled = false;
    alert('Erro de rede.');
  });
}

/* =========================================================
   TOAST / CARDS DE MENSAGEM (substitui alert())
   ========================================================= */
(function () {
  const css = `
  .toast-container {
    position: fixed;
    top: 16px;
    right: 16px;
    z-index: 9999;
    display: flex;
    flex-direction: column;
    gap: 8px;
    pointer-events: none;
    font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
  }
  .toast {
    min-width: 260px;
    max-width: 380px;
    background: #ffffff;
    border-radius: 12px;
    box-shadow: 0 18px 40px rgba(0,0,0,0.18);
    padding: 10px 14px;
    display: flex;
    align-items: flex-start;
    gap: 8px;
    border-left: 4px solid #0ea5e9;
    pointer-events: auto;
    animation: toast-in .18s ease-out;
  }
  .toast-success { border-left-color: #22c55e; }
  .toast-error   { border-left-color: #ef4444; }
  .toast-info    { border-left-color: #0ea5e9; }

  .toast-icon {
    margin-top: 2px;
    font-size: 16px;
  }
  .toast-body {
    flex: 1;
    font-size: 14px;
    color: #111827;
  }
  .toast-close {
    border: none;
    background: transparent;
    cursor: pointer;
    font-size: 14px;
    opacity: 0.6;
  }
  .toast-close:hover {
    opacity: 1;
  }
  @keyframes toast-in {
    from { opacity: 0; transform: translateY(-6px) translateX(6px); }
    to   { opacity: 1; transform: translateY(0) translateX(0); }
  }

  .quick-box {
    margin: 12px 0 18px;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 12px;
    background: #f9fafb;
  }
  .quick-box h4 {
    margin: 0 0 8px;
    font-size: 15px;
    font-weight: 600;
    color: #111827;
  }
  .quick-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }
  .quick-grid .btn.soft {
    background: #eef2ff;
    border: 1px solid #c7d2fe;
    color: #3730a3;
    padding: 6px 10px;
    border-radius: 8px;
    font-size: 13px;
  }
  .quick-grid .btn.soft:hover {
    background: #e0e7ff;
  }

  .ac-item {
    padding: 8px 10px;
    border-bottom: 1px solid #e5e7eb;
    cursor: pointer;
  }
  .ac-item:hover {
    background: #f1f5f9;
  }
  .ac-code {
    font-weight: 700;
    color: #1e3a8a;
  }
  .ac-desc {
    font-size: 13px;
    color: #374151;
  }
  .ac-empty {
    padding: 10px;
    text-align: center;
    color: #6b7280;
  }
  `;
  const style = document.createElement('style');
  style.textContent = css;
  document.head.appendChild(style);

  let container = null;
  function getContainer() {
    if (!container) {
      container = document.createElement('div');
      container.className = 'toast-container';
      document.body.appendChild(container);
    }
    return container;
  }

  window.showToast = function (message, type = 'info') {
    const wrap = getContainer();

    const el = document.createElement('div');
    el.className = `toast toast-${type}`;

    const icon = document.createElement('div');
    icon.className = 'toast-icon';
    icon.innerHTML =
      type === 'success' ? '✔' :
      type === 'error'   ? '✖' :
                           'ℹ';

    const body = document.createElement('div');
    body.className = 'toast-body';
    body.textContent = message;

    const btnClose = document.createElement('button');
    btnClose.className = 'toast-close';
    btnClose.type = 'button';
    btnClose.innerHTML = '&times;';
    btnClose.onclick = () => {
      if (el.parentNode) el.parentNode.removeChild(el);
    };

    el.appendChild(icon);
    el.appendChild(body);
    el.appendChild(btnClose);

    wrap.appendChild(el);

    setTimeout(() => {
      if (el.parentNode) el.parentNode.removeChild(el);
    }, 4000);
  };

  const originalAlert = window.alert;
  window.alert = function (msg) {
    try {
      const text = String(msg || '');
      let tipo = 'info';
      if (/erro|falha|inválid/i.test(text)) tipo = 'error';
      else if (/conclu|salv|sucesso|bloquead/i.test(text)) tipo = 'success';

      showToast(text, tipo);
    } catch (e) {
      originalAlert(msg);
    }
  };
})();

/* =========================================================
   CHAT MÉDICO ⇆ PACIENTE (usa chat_api_medico.php)
   Lista lateral estilo WhatsApp
   ========================================================= */
const CHAT_API_URL = 'chat_api_medico.php';
const MEDICO_ID    = <?= (int)$id ?>;

let CHAT_CONVERSA_ID   = null;
let CHAT_ULTIMO_ID     = 0;
let CHAT_TIMER         = null;

/* Pequeno helper pra escapar HTML */
function escapeHtml(str) {
  return String(str || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

/* ================== LISTA DE CONVERSAS (ESQUERDA) ================== */
function carregarListaConversas() {
  const box = document.getElementById('chat-conversas-list');
  if (!box) return;

  box.innerHTML = '<div class="chat-empty">Carregando conversas...</div>';

  const url = `${CHAT_API_URL}?action=listar_conversas&medico_id=${encodeURIComponent(MEDICO_ID)}`;

  fetch(url)
    .then(r => r.json())
    .then(j => {
      if (!j || !j.ok) {
        box.innerHTML = '<div class="chat-empty">Erro ao carregar conversas.</div>';
        return;
      }

      const itens = j.items || [];
      if (!itens.length) {
        box.innerHTML = '<div class="chat-empty">Nenhuma conversa ainda.</div>';
        CHAT_LIST_CARREGADA = true;
        return;
      }

      box.innerHTML = itens.map(montarContatoHTML).join('');
      CHAT_LIST_CARREGADA = true;
    })
    .catch(() => {
      box.innerHTML = '<div class="chat-empty">Erro de rede ao carregar conversas.</div>';
    });
}

function montarContatoHTML(c) {
  const nome = escapeHtml(c.cliente_nome || ('Paciente ' + c.cliente_id));
  const preview = escapeHtml(c.ultima_msg || '');
  const hora = (c.ultima_data || '').slice(11, 16); // HH:MM
  const foto = c.foto_cliente && c.foto_cliente !== ''
    ? escapeHtml(c.foto_cliente)
    : 'img/default.jpg';

  const convId = c.conversa_id ? Number(c.conversa_id) : '';

  return `
    <div class="chat-contact"
         data-conversa="${convId}"
         data-cliente="${c.cliente_id}"
         data-nome="${nome}"
         onclick="selecionarConversa(this)">
      <div class="chat-avatar">
        <img src="${foto}" alt="${nome}">
      </div>
      <div class="chat-contact-text">
        <div class="chat-contact-top">
          <span class="chat-contact-name">${nome}</span>
          <span class="chat-contact-time">${hora || ''}</span>
        </div>
        <div class="chat-contact-last">${preview}</div>
      </div>
    </div>
  `;
}

function selecionarConversa(el) {
  document.querySelectorAll('.chat-contact').forEach(c => c.classList.remove('active'));
  el.classList.add('active');

  const nome       = el.dataset.nome || ('Paciente ' + el.dataset.cliente);
  const clienteId  = parseInt(el.dataset.cliente, 10);
  let   conversaId = parseInt(el.dataset.conversa || '0', 10);

  document.getElementById('chat-header-title').textContent = nome;
  document.getElementById('chat-header-sub').textContent   =
    'Paciente ID ' + (clienteId || '');

  const avatar = document.getElementById('chat-header-avatar');
  if (avatar) {
    const iniciais = nome.trim().split(/\s+/).map(p => p[0]).join('').slice(0, 2).toUpperCase();
    avatar.textContent = iniciais || '?';
  }

  const boxMsg = document.getElementById('chat-mensagens');
  if (boxMsg) boxMsg.innerHTML = '';

  if (!conversaId) {
    const fd = new URLSearchParams();
    fd.append('action', 'obter_ou_criar_conversa');
    fd.append('cliente_id', clienteId);
    fd.append('medico_id', MEDICO_ID);

    fetch(CHAT_API_URL, {
      method: 'POST',
      body: fd
    })
      .then(r => r.json())
      .then(j => {
        if (!j || !j.ok || !j.conversa_id) {
          appendMsg('sistema', j?.msg || 'Não foi possível iniciar o chat com este paciente.');
          return;
        }

        conversaId = parseInt(j.conversa_id, 10);
        el.dataset.conversa = conversaId;

        CHAT_CONVERSA_ID = conversaId;
        CHAT_ULTIMO_ID   = 0;

        carregarMensagensChat(true);

        if (CHAT_TIMER) clearInterval(CHAT_TIMER);
        CHAT_TIMER = setInterval(() => carregarMensagensChat(false), 3000);
      })
      .catch(() => {
        appendMsg('sistema', 'Erro de rede ao iniciar conversa.');
      });

  } else {
    CHAT_CONVERSA_ID = conversaId;
    CHAT_ULTIMO_ID   = 0;

    carregarMensagensChat(true);

    if (CHAT_TIMER) clearInterval(CHAT_TIMER);
    CHAT_TIMER = setInterval(() => carregarMensagensChat(false), 3000);
  }
}

/* ================== MENSAGENS DA CONVERSA ================== */

function appendMsg(tipo, texto, horario) {
  const box = document.getElementById('chat-mensagens');
  if (!box) return;

  const div = document.createElement('div');
  div.className = 'chat-msg ' + (
    tipo === 'me' ? 'chat-me' :
    tipo === 'ele' ? 'chat-outro' :
                     'chat-sistema'
  );

  const span = document.createElement('span');
  span.className = 'chat-texto';
  span.textContent = texto;
  div.appendChild(span);

  if (horario) {
    const small = document.createElement('small');
    small.className = 'chat-hora';
    small.textContent = horario;
    div.appendChild(small);
  }

  box.appendChild(div);
  box.scrollTop = box.scrollHeight;
}

function carregarMensagensChat(primeiraVez) {
  if (!CHAT_CONVERSA_ID) return;

  const url = `${CHAT_API_URL}?action=listar_mensagens&conversa_id=${encodeURIComponent(CHAT_CONVERSA_ID)}&ultimo_id=${encodeURIComponent(CHAT_ULTIMO_ID)}`;

  fetch(url)
    .then(r => r.json())
    .then(j => {
      if (!j || !j.ok) {
        if (primeiraVez) {
          appendMsg('sistema', j?.msg || 'Erro ao carregar mensagens.');
        }
        return;
      }

      const itens = j.items || [];
      if (!itens.length && primeiraVez) {
        appendMsg('sistema', 'Nenhuma mensagem ainda. Envie a primeira.');
        return;
      }

      itens.forEach(m => {
        const idNum = parseInt(m.id, 10) || 0;
        if (idNum > CHAT_ULTIMO_ID) CHAT_ULTIMO_ID = idNum;

        const meu   = (m.remetente_tipo === 'medico');
        const tipo  = meu ? 'me' : 'ele';
        const hora  = (m.enviado_em || '').slice(11, 16);
        appendMsg(tipo, m.mensagem || '', hora);
      });
    })
    .catch(() => {
      if (primeiraVez) {
        appendMsg('sistema', 'Erro de rede ao carregar mensagens.');
      }
    });
}

/* ================== ENVIAR MENSAGEM ================== */

function enviarMsgChat() {
  if (!CHAT_CONVERSA_ID) {
    alert('Selecione uma conversa primeiro.');
    return;
  }

  const inp = document.getElementById('chat_texto');
  if (!inp) return;

  const txt = (inp.value || '').trim();
  if (!txt) return;

  const fd = new URLSearchParams();
  fd.append('action', 'enviar_mensagem');
  fd.append('conversa_id', CHAT_CONVERSA_ID);
  fd.append('mensagem', txt);

  const textoLocal = txt;
  inp.value = '';

  fetch(CHAT_API_URL, {
    method: 'POST',
    body: fd
  })
    .then(r => r.json())
    .then(j => {
      if (!j || !j.ok) {
        alert(j?.msg || 'Erro ao enviar mensagem.');
        return;
      }
      appendMsg('me', textoLocal, new Date().toTimeString().slice(0, 5));
      CHAT_ULTIMO_ID = Math.max(CHAT_ULTIMO_ID, j.id || CHAT_ULTIMO_ID);
    })
    .catch(() => {
      alert('Erro de rede ao enviar mensagem.');
    });
}

/* ================== INFO PANE ================== */
function toggleChatInfo() {
  const panel = document.getElementById('chat-info-panel');
  if (!panel) return;
  panel.classList.toggle('show');
}

/* =========================================================
   INIT
   ========================================================= */
window.addEventListener('DOMContentLoaded', () => {
  const bio = document.getElementById('bio');
  if (bio) { 
    atualizarContador(bio); 
    bio.addEventListener('input', () => atualizarContador(bio)); 
  }

  renderizarCalendario();
  desenharListaHorarios();
  mudarModoSelecao('unico');
  renderQuickButtons();

  if (new URLSearchParams(location.search).get('salvo') === '1') {
    console.log('Perfil salvo com sucesso!');
  }
});
</script>


</body>
</html> 