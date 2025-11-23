<?php
session_start();
date_default_timezone_set('America/Sao_Paulo');

$conn = new mysqli("localhost", "root", "", "clinagenda");
if ($conn->connect_error) die("Erro ao conectar: " . $conn->connect_error);
$conn->set_charset('utf8mb4');

if (!isset($_SESSION['id'])) {
  header("Location: index.php");
  exit;
}

$id = (int)$_SESSION['id'];
function safe($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Logout simples pelo próprio Cliente.php
if (isset($_GET['logout'])) {
  session_unset();
  session_destroy();
  header("Location: index.php");
  exit;
}

/* ==================== APIS INTERNAS (JSON) ==================== */
/* GET ?action=slots&medico_id=...&data=YYYY-MM-DD */
if (($_GET['action'] ?? '') === 'slots') {
  header('Content-Type: application/json; charset=utf-8');
  $medico_id = (int)($_GET['medico_id'] ?? 0);
  $data      = $_GET['data'] ?? '';

  if (!$medico_id || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$data)) {
    echo json_encode(['ok'=>false,'msg'=>'Parâmetros inválidos']); exit;
  }

  // slots base (06:00..10:40 e 13:00..17:40, passos de 40min, pausa almoço 11h–12:59)
  $slots_padrao = function(){
    $out = [];

    // Manhã 06:00 até 10:40
    for ($m = 6*60; $m <= (11*60 - 20); $m += 40) {
      $out[] = sprintf('%02d:%02d', intdiv($m,60), $m%60);
    }

    // Tarde 13:00 até 17:40
    for ($m = 13*60; $m <= 17*60 + 40; $m += 40) {
      $out[] = sprintf('%02d:%02d', intdiv($m,60), $m%60);
    }

    return $out;
  };

  // já agendados (pendente/confirmado)
  $q1 = $conn->prepare("SELECT horario FROM agendamentos WHERE medico_id=? AND data=? AND status IN ('pendente','confirmado')");
  $q1->bind_param('is', $medico_id, $data);
  $q1->execute();
  $ag = array_map(fn($t)=>substr($t[0],0,5), $q1->get_result()->fetch_all(MYSQLI_NUM));

  // bloqueados
  $q2 = $conn->prepare("SELECT horario FROM horarios_disponiveis WHERE medico_id=? AND data=? AND ocupado=1");
  $q2->bind_param('is', $medico_id, $data);
  $q2->execute();
  $bl = array_map(fn($t)=>substr($t[0],0,5), $q2->get_result()->fetch_all(MYSQLI_NUM));

  $base  = $slots_padrao();
  $livres= array_values(array_diff($base, $ag, $bl));

  // se for hoje, remove horários passados
  if ($data === date('Y-m-d')) {
    $now = date('H:i');
    $livres = array_values(array_filter($livres, fn($h)=>$h > $now));
  }

  echo json_encode(['ok'=>true,'data'=>$data,'slots'=>$livres], JSON_UNESCAPED_UNICODE);
  exit;
}

/* POST action=agendar (medico_id, data, horario) */
if (($_POST['action'] ?? '') === 'agendar') {
  header('Content-Type: application/json; charset=utf-8');

  if (!isset($_SESSION['id'])) { echo json_encode(['ok'=>false,'msg'=>'Faça login.']); exit; }
  $cliente_id = (int)$_SESSION['id'];
  $medico_id  = (int)($_POST['medico_id'] ?? 0);
  $data       = $_POST['data'] ?? '';
  $horario    = $_POST['horario'] ?? '';

  if (!$medico_id || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$data) || !preg_match('/^\d{2}:\d{2}$/',$horario)) {
    echo json_encode(['ok'=>false,'msg'=>'Parâmetros inválidos']); exit;
  }
  $horario .= ':00';

  $dtSel = DateTime::createFromFormat('Y-m-d H:i:s', "$data $horario", new DateTimeZone('America/Sao_Paulo'));
  if (!$dtSel || $dtSel < new DateTime('now', new DateTimeZone('America/Sao_Paulo'))) {
    echo json_encode(['ok'=>false,'msg'=>'Esse horário já passou.']); exit;
  }

  $conn->begin_transaction();
  try {
    // 1) bloqueado?
    $q1 = $conn->prepare("SELECT 1 FROM horarios_disponiveis WHERE medico_id=? AND data=? AND horario=? AND ocupado=1 LIMIT 1");
    $q1->bind_param('iss', $medico_id, $data, $horario);
    $q1->execute();
    if ($q1->get_result()->num_rows) throw new Exception('Horário bloqueado pelo médico.');

    // 2) já ocupado?
    $q2 = $conn->prepare("SELECT 1 FROM agendamentos WHERE medico_id=? AND data=? AND horario=? AND status IN ('pendente','confirmado') LIMIT 1");
    $q2->bind_param('iss', $medico_id, $data, $horario);
    $q2->execute();
    if ($q2->get_result()->num_rows) throw new Exception('Esse horário acabou de ser ocupado.');

    // 3) inserir
    $ins = $conn->prepare("INSERT INTO agendamentos (medico_id, cliente_id, data, horario, status) VALUES (?, ?, ?, ?, 'pendente')");
    $ins->bind_param('iiss', $medico_id, $cliente_id, $data, $horario);
    $ins->execute();

    $conn->commit();
    echo json_encode(['ok'=>true,'msg'=>'Agendamento criado! Status: pendente (aguardando confirmação do médico).']);
  } catch (Throwable $e) {
    $conn->rollback();
    $msg = $e->getMessage();
    $l = strtolower($msg);
    if (str_contains($l,'duplicate') || str_contains($l,'unique')) $msg = 'Esse horário acabou de ser ocupado. Escolha outro.';
    echo json_encode(['ok'=>false,'msg'=>$msg]);
  }
  exit;
}

/* ==================== CONFIGURAÇÕES (atualizar email/telefone) ==================== */
$msg_config_ok   = '';
$msg_config_erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'salvar_config') {
  $email    = trim($_POST['email_config'] ?? '');
  $telefone = trim($_POST['telefone_config'] ?? '');

  if ($email === '') {
    $msg_config_erro = 'Informe um email.';
  } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $msg_config_erro = 'Email inválido.';
  } else {
    $up = $conn->prepare("UPDATE clientes SET email = ?, telefone = ? WHERE id = ?");
    $up->bind_param("ssi", $email, $telefone, $id);
    if ($up->execute()) {
      $msg_config_ok = 'Dados atualizados com sucesso.';
    } else {
      $msg_config_erro = 'Erro ao atualizar. Tente novamente.';
    }
  }
}

/* ==================== DADOS DO CLIENTE ==================== */
$stmt = $conn->prepare("SELECT nome, id, email, cpf, telefone FROM clientes WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$res = $stmt->get_result();
if ($res && $res->num_rows > 0) {
  $cliente = $res->fetch_assoc();
  $primeiroNome = explode(' ', $cliente['nome'])[0];
} else {
  echo "<div style='padding:20px; color:red;'>Cliente não encontrado.</div>";
  exit;
}

/* ==================== FOTO DO CLIENTE (tabela 'fotos' + fallback) ==================== */
$fotoPath = 'img/default.jpg';
$versaoQS = '';
if ($pf = $conn->prepare("
  SELECT caminho, UNIX_TIMESTAMP(data_upload) AS v
  FROM fotos
  WHERE cliente_id = ? AND tipo = 'perfil'
  ORDER BY data_upload DESC, id DESC
  LIMIT 1
")) {
  $pf->bind_param("i", $id);
  $pf->execute();
  $r = $pf->get_result();
  if ($r && $r->num_rows) {
    $row = $r->fetch_assoc();
    if (!empty($row['caminho'])) {
      $fotoPath = $row['caminho'];
      $versaoQS = '?v=' . (int)$row['v'];
    }
  }
  $pf->close();
}
if ($fotoPath === 'img/default.jpg') {
  $candidates = [];
  $baseRel = 'uploads/clientes';
  $baseAbs = __DIR__ . DIRECTORY_SEPARATOR . $baseRel . DIRECTORY_SEPARATOR;
  $patterns = [
    $baseAbs . "cliente_{$id}_*.webp",
    $baseAbs . "cliente_{$id}_*.jpg",
    $baseAbs . "cliente_{$id}_*.jpeg",
    $baseAbs . "cliente_{$id}_*.png",
    __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . "cliente_{$id}.webp",
    __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . "cliente_{$id}.jpg",
    __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . "cliente_{$id}.jpeg",
    __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . "cliente_{$id}.png",
  ];
  foreach ($patterns as $p) {
    foreach (glob($p) ?: [] as $fileAbs) {
      $candidates[$fileAbs] = @filemtime($fileAbs) ?: time();
    }
  }
  if ($candidates) {
    arsort($candidates);
    $abs = array_key_first($candidates);
    $fotoPath = str_replace(__DIR__ . DIRECTORY_SEPARATOR, '', $abs);
    $versaoQS = '?v=' . (int)$candidates[$abs];
  }
}
$fotoSidebar = safe($fotoPath . $versaoQS);

/* ==================== CONTADORES (cartões) ==================== */
/* Consultas Agendadas = todas as futuras (de hoje em diante), exceto canceladas */
$consultasAgendadas = 0;
if ($stmt = $conn->prepare("
  SELECT COUNT(*) AS total FROM agendamentos
  WHERE cliente_id = ? AND CONCAT(data,' ',horario) >= NOW() AND status <> 'cancelado'
")) {
  $stmt->bind_param("i", $id);
  $stmt->execute();
  $consultasAgendadas = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
  $stmt->close();
}

/* Confirmações Pendentes (a partir de agora) */
$confirmacoesPendentes = 0;
if ($stmt = $conn->prepare("
  SELECT COUNT(*) AS total FROM agendamentos
  WHERE cliente_id = ? AND status = 'pendente' AND CONCAT(data,' ',horario) >= NOW()
")) {
  $stmt->bind_param("i", $id);
  $stmt->execute();
  $confirmacoesPendentes = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
  $stmt->close();
}

/* Exames Pendentes (contagem) */
$examesPendentes = 0;
if ($stmt = $conn->prepare("
  SELECT COUNT(*) AS total FROM exames
  WHERE cliente_id = ? AND status = 'pendente'
")) {
  $stmt->bind_param("i", $id);
  $stmt->execute();
  $examesPendentes = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
  $stmt->close();
}

/* ==================== BUSCA DE MÉDICOS (para Agendamentos) ==================== */
$q = trim($_GET['q'] ?? '');
if ($q !== '') {
  $like = '%' . $q . '%';
  $stmtMed = $conn->prepare("
    SELECT 
      m.id, m.nome, m.crm, m.especialidade,
      (SELECT caminho FROM fotos 
       WHERE medico_id = m.id AND tipo='perfil' 
       ORDER BY data_upload DESC, id DESC LIMIT 1) AS foto
    FROM medicos m
    WHERE (m.nome LIKE ? OR m.especialidade LIKE ?)
      AND m.status = 'ativo'
    ORDER BY m.nome ASC
  ");
  $stmtMed->bind_param("ss", $like, $like);
} else {
  $stmtMed = $conn->prepare("
    SELECT 
      m.id, m.nome, m.crm, m.especialidade,
      (SELECT caminho FROM fotos 
       WHERE medico_id = m.id AND tipo='perfil' 
       ORDER BY data_upload DESC, id DESC LIMIT 1) AS foto
    FROM medicos m
    WHERE m.status = 'ativo'
    ORDER BY RAND()
    LIMIT 10
  ");
}
$stmtMed->execute();
$medicos = $stmtMed->get_result();

/* ==================== CONSULTAS (para seção Consultas) ==================== */
$consultasFuturas = [];
if ($stm = $conn->prepare("
  SELECT a.id, a.data, a.horario, a.status,
         m.nome AS medico_nome, m.especialidade
  FROM agendamentos a
  JOIN medicos m ON m.id = a.medico_id
  WHERE a.cliente_id = ?
    AND CONCAT(a.data,' ',a.horario) >= NOW()
    AND a.status <> 'cancelado'
  ORDER BY a.data ASC, a.horario ASC
")) {
  $stm->bind_param("i", $id);
  if ($stm->execute()) $consultasFuturas = $stm->get_result()->fetch_all(MYSQLI_ASSOC);
  $stm->close();
}

/* Histórico de consultas (que já passaram) */
$consultasHistorico = [];
if ($stm = $conn->prepare("
  SELECT a.id, a.data, a.horario, a.status,
         m.nome AS medico_nome, m.especialidade
  FROM agendamentos a
  JOIN medicos m ON m.id = a.medico_id
  WHERE a.cliente_id = ?
    AND CONCAT(a.data,' ',a.horario) < NOW()
  ORDER BY a.data DESC, a.horario DESC
")) {
  $stm->bind_param("i", $id);
  if ($stm->execute()) $consultasHistorico = $stm->get_result()->fetch_all(MYSQLI_ASSOC);
  $stm->close();
}

/* ==================== LISTAS DINÂMICAS RESUMO (cards de baixo da home) ==================== */
$consultasProx = [];
if ($stm = $conn->prepare("
  SELECT a.id, a.data, a.horario, a.status, m.nome AS medico_nome
  FROM agendamentos a
  JOIN medicos m ON m.id = a.medico_id
  WHERE a.cliente_id = ? 
    AND a.status <> 'cancelado'
    AND CONCAT(a.data,' ',a.horario) >= NOW()
  ORDER BY a.data ASC, a.horario ASC
  LIMIT 3
")) {
  $stm->bind_param("i", $id);
  if ($stm->execute()) $consultasProx = $stm->get_result()->fetch_all(MYSQLI_ASSOC);
  $stm->close();
}

$examesPend = [];
if ($stm = $conn->prepare("
  SELECT e.id, e.tipo AS titulo, e.data AS data_ref, e.horario, e.status
  FROM exames e
  WHERE e.cliente_id = ? AND e.status = 'pendente'
  ORDER BY e.data ASC, e.horario ASC
  LIMIT 3
")) {
  $stm->bind_param("i", $id);
  if ($stm->execute()) $examesPend = $stm->get_result()->fetch_all(MYSQLI_ASSOC);
  $stm->close();
}

/* ==================== EXAMES (para seção Exames) ==================== */
$examesPendentesLista = [];
if ($stm = $conn->prepare("
  SELECT e.id, e.tipo AS titulo, e.data, e.horario, e.status
  FROM exames e
  WHERE e.cliente_id = ? AND e.status = 'pendente'
  ORDER BY e.data ASC, e.horario ASC
")) {
  $stm->bind_param("i", $id);
  if ($stm->execute()) $examesPendentesLista = $stm->get_result()->fetch_all(MYSQLI_ASSOC);
  $stm->close();
}

$examesOutros = [];
if ($stm = $conn->prepare("
  SELECT e.id, e.tipo AS titulo, e.data, e.horario, e.status
  FROM exames e
  WHERE e.cliente_id = ? AND e.status <> 'pendente'
  ORDER BY e.data DESC, e.horario DESC
")) {
  $stm->bind_param("i", $id);
  if ($stm->execute()) $examesOutros = $stm->get_result()->fetch_all(MYSQLI_ASSOC);
  $stm->close();
}

/* ==================== MÉDICOS (para seção Médicos) ==================== */
$listaMedicos = [];
if ($stm = $conn->prepare("
  SELECT 
    m.id, m.nome, m.crm, m.especialidade,
    (SELECT caminho FROM fotos 
       WHERE medico_id = m.id AND tipo='perfil' 
       ORDER BY data_upload DESC, id DESC LIMIT 1) AS foto
  FROM medicos m
  WHERE m.status = 'ativo'
  ORDER BY m.nome ASC
")) {
  if ($stm->execute()) $listaMedicos = $stm->get_result()->fetch_all(MYSQLI_ASSOC);
  $stm->close();
}

/* ==================== HELPERS ==================== */
function hora_fim_40min($horaIni){ $ts=strtotime($horaIni); return $ts?date('H:i',$ts+40*60):''; }
function fmt_data($d){ $t=strtotime($d); return $t?date('d/m/Y',$t):''; }
function badge_class($st){
  $s=strtolower((string)$st);
  if ($s==='pendente')   return 'badge pendente';
  if ($s==='realizado')  return 'badge agendado';
  if ($s==='confirmado') return 'badge agendado';
  if ($s==='cancelado')  return 'badge neutro';
  return 'badge neutro';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <title>ClinAgenda - Painel do Paciente</title>
  <!-- CSS principal do cliente -->
  <link rel="stylesheet" href="css/cliente.css?v=1">
  <!-- Font Awesome -->
  <script defer src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
</head>
<body>

<header class="header">
  <div class="header-logo">
    <div class="header-logo-icon">
      <i class="fas fa-plus"></i>
    </div>
    <div class="header-logo-text">
      <span class="plus"></span>
      <span class="name">ClinAgenda</span>
    </div>
  </div>
</header>

<aside class="sidebar">
  <a href="Perfil-Cliente.php" class="sidebar-profile">
    <img src="<?= $fotoSidebar ?>" alt="Foto de Perfil">
    <div class="sidebar-info">
      <strong><?= safe($primeiroNome) ?></strong>
      <small>ID: <?= safe($cliente['id']) ?></small>
    </div>
  </a>
  <nav>
    <ul>
      <li class="active" onclick="mudarSecao('agendamentos', this)"><i class="fas fa-calendar-alt"></i><span class="menu-text">. Agendamentos</span></li>
      <li onclick="mudarSecao('consultas', this)"><i class="fas fa-user-injured"></i><span class="menu-text">. Consultas</span></li>
      <li onclick="mudarSecao('medicos', this)"><i class="fas fa-file-medical"></i><span class="menu-text">. Médicos</span></li>
      <li onclick="mudarSecao('exames', this)"><i class="fas fa-flask"></i><span class="menu-text">. Exames</span></li>
      <!-- Config invisível via CSS -->
      <li class="menu-config" onclick="mudarSecao('config', this)"><i class="fas fa-cog"></i><span class="menu-text">. Configurações</span></li>
    </ul>
  </nav>

  <!-- Botão sair mais alto -->
  <a class="logout" href="Cliente.php?logout=1">
    <i class="fas fa-sign-out-alt"></i><span class="menu-text">Sair</span>
  </a>
</aside>

<main class="main-content">

  <!-- ==================== SEÇÃO AGENDAMENTOS (HOME) ==================== -->
  <section id="sec-agendamentos" class="secao ativa">
    <section class="banner">
      <div class="banner-content">
        <h1>Bem-vindo ao Painel de Agendamentos</h1>
        <p>Gerencie suas consultas e exames de forma simples e eficiente.</p>
      </div>
      <div class="banner-icon">
        <i class="fas fa-heartbeat"></i>
      </div>
    </section>

    <!-- ===== Cards resumo ===== -->
    <section class="cards">
      <div class="card white" onclick="mudarSecao('consultas')">
        <div class="card-header">
          <div class="icon-circle green"><i class="fas fa-calendar-alt"></i></div>
          <h2>Consultas Agendadas</h2>
        </div>
        <p class="card-number"><?= (int)$consultasAgendadas ?></p>
        <small class="text-green">Atualizado</small>
      </div>

      <div class="card white" onclick="mudarSecao('exames')">
        <div class="card-header">
          <div class="icon-circle blue"><i class="fas fa-vial"></i></div>
          <h2>Exames Pendentes</h2>
        </div>
        <p class="card-number"><?= (int)$examesPendentes ?></p>
        <small class="text-blue">Atualizado</small>
      </div>

      <div class="card white" onclick="mudarSecao('consultas')">
        <div class="card-header">
          <div class="icon-circle yellow"><i class="fas fa-clock"></i></div>
          <h2>Confirmações Pendentes</h2>
        </div>
        <p class="card-number"><?= (int)$confirmacoesPendentes ?></p>
        <small class="text-yellow">Atualizado</small>
      </div>
    </section>

    <!-- ===== Seção de Agendamento (Busca + Lista + Calendário/Slots) ===== -->
    <section class="agendamento" id="agendar-section">
      <div class="agendamento-header">
        <h2>Marcar Novo Horário</h2>
      </div>

      <div id="busca-medicos">
        <!-- Barra única de pesquisa -->
        <form class="search-wrap" method="GET" action="Cliente.php" autocomplete="off">
          <div class="input">
            <i class="fas fa-search"></i>
            <input type="text" name="q" placeholder="Buscar por nome ou especialidade..." value="<?= safe($q) ?>">
          </div>
          <button type="submit">Pesquisar</button>
        </form>

        <table class="tabela">
          <thead>
            <tr>
              <th>Profissional</th>
              <th>Especialidade</th>
              <th>Ação</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($medicos->num_rows === 0): ?>
              <tr><td colspan="3">
                <div class="sem-resultados">Nenhum médico encontrado para “<?= safe($q) ?>”.</div>
              </td></tr>
            <?php else: ?>
              <?php while ($prof = $medicos->fetch_assoc()): 
                $fotoMed = !empty($prof['foto']) ? $prof['foto'] : 'imagens/medico-default.png';
              ?>
                <tr>
                  <td>
                    <div class="medico-card">
                      <div class="medico-foto-box">
                        <img src="<?= safe($fotoMed) ?>" alt="Foto de <?= safe($prof['nome']) ?>">
                      </div>
                      <div class="medico-card-info">
                        <strong><?= safe($prof['nome']) ?></strong>
                        <small><?= safe($prof['crm']) ?></small>
                      </div>
                    </div>
                  </td>
                  <td><?= safe($prof['especialidade']) ?></td>
                  <td>
                    <button class="btn-ativar-cal"
                            type="button"
                            onclick="ativarMedico(<?= (int)$prof['id'] ?>,'<?= safe($prof['nome']) ?>', event)">
                      Escolher dia e horário
                    </button>
                  </td>
                </tr>
              <?php endwhile; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Grade com Calendário à esquerda e Horários à direita -->
      <div class="agendamento-grid" style="margin-top:16px;">
        <!-- Calendário -->
        <div class="cal-card">
          <div class="calendar">
            <div class="calendar-header">
              <button type="button" class="arrow" onclick="mudarMes(-1)" aria-label="Mês anterior">&lt;</button>
              <span id="mes-ano"></span>
              <button type="button" class="arrow" onclick="mudarMes(1)" aria-label="Próximo mês">&gt;</button>
            </div>
            <div class="calendar-days">
              <div>Dom</div><div>Seg</div><div>Ter</div><div>Qua</div><div>Qui</div><div>Sex</div><div>Sáb</div>
            </div>
            <div class="calendar-grid" id="calendar-grid"></div>
          </div>
        </div>

        <!-- Slots/Horários -->
        <div class="slots-card">
          <div class="slots-head">
            <div class="medico-selecionado" id="medico-head">Nenhum médico selecionado</div>
            <div id="data-head"></div>
          </div>

          <div style="display:flex; gap:8px; align-items:center; margin-bottom:8px;">
            <label for="data-ag" style="font-weight:600;">Data:</label>
            <input type="date" id="data-ag" min="<?= date('Y-m-d') ?>" onchange="sincronizarCalendarioPorCampo()">
            <button class="btn-agendar" type="button" onclick="carregarSlotsSelecionados()">Carregar horários</button>
          </div>

          <div id="slots" class="slots-wrap"></div>

          <div>
            <button id="btn-confirmar" class="btn-confirmar" disabled onclick="confirmarAgendamento()">Confirmar agendamento</button>
          </div>
        </div>
      </div>
    </section>

    <!-- ===== LISTAS DINÂMICAS (cards embaixo) ===== -->
    <section class="cards-lista grid-2">
      <!-- Próximas Consultas -->
      <div class="card-lista">
        <div class="card-lista-header">
          <h3>Próximas Consultas</h3>
          <a href="#" class="ver-todos" onclick="mudarSecao('consultas');return false;">Ver todas</a>
        </div>

        <ul class="lista-itens">
          <?php if (empty($consultasProx)): ?>
            <li class="item-vazio">Você não tem consultas próximas.</li>
          <?php else: ?>
            <?php foreach ($consultasProx as $row): 
              $ini = substr($row['horario'] ?? '', 0, 5);
              $fim = hora_fim_40min($row['horario'] ?? '');
            ?>
              <li class="item-lista">
                <div class="icone-item verde"><i class="fas fa-calendar-alt"></i></div>
                <div class="info-item">
                  <strong><?= safe($row['medico_nome']) ?></strong>
                  <p>Consulta</p>
                  <p class="sub"><i class="fas fa-calendar-day"></i> <?= fmt_data($row['data']) ?></p>
                </div>
                <div class="right-col">
                  <span class="time-range"><?= safe($ini) ?> – <?= safe($fim) ?></span>
                </div>
              </li>
            <?php endforeach; ?>
          <?php endif; ?>
        </ul>
      </div>

      <!-- Exames Pendentes -->
      <div class="card-lista">
        <div class="card-lista-header">
          <h3>Exames Pendentes</h3>
          <a href="#" class="ver-todos" onclick="mudarSecao('exames');return false;">Ver todos</a>
        </div>

        <ul class="lista-itens">
          <?php if (empty($examesPend)): ?>
            <li class="item-vazio">Você não tem exames pendentes.</li>
          <?php else: ?>
            <?php foreach ($examesPend as $row): 
              $titulo = $row['titulo'] ?? 'Exame';
              $dataRef = fmt_data($row['data_ref'] ?? '');
              $horaRef = isset($row['horario']) ? substr($row['horario'],0,5) : '';
              $st = $row['status'] ?? 'pendente';
            ?>
              <li class="item-lista">
                <div class="icone-item azul"><i class="fas fa-vial"></i></div>
                <div class="info-item">
                  <strong><?= safe($titulo) ?></strong>
                  <p><?= safe($cliente['nome']) ?></p>
                  <p class="sub">
                    <i class="fas fa-calendar-day"></i> Marcado para: <?= safe($dataRef) ?>
                    <?php if($horaRef): ?> às <?= safe($horaRef) ?><?php endif; ?>
                  </p>
                </div>
                <div class="right-col">
                  <span class="<?= badge_class($st) ?>"><?= ucfirst($st) ?></span>
                </div>
              </li>
            <?php endforeach; ?>
          <?php endif; ?>
        </ul>
      </div>
    </section>
  </section>

  <!-- ==================== SEÇÃO CONSULTAS (CARDS EM GRID 4x3) ==================== -->
  <section id="sec-consultas" class="secao">
    <h2>Consultas</h2>

    <h3 class="subtitulo-sec">Próximas consultas</h3>
    <?php if (empty($consultasFuturas)): ?>
      <p class="item-vazio">Nenhuma consulta futura encontrada.</p>
    <?php else: ?>
      <div class="lista-consultas">
        <?php foreach ($consultasFuturas as $c): 
          $data  = fmt_data($c['data']);
          $hora  = substr($c['horario'] ?? '', 0, 5);
          $st    = $c['status'] ?? 'pendente';
          $badge = badge_class($st);
        ?>
          <div class="card-consulta">
            <div class="icon">
              <i class="fas fa-stethoscope"></i>
            </div>
            <div class="info">
              <strong><?= safe($c['medico_nome']) ?></strong>
              <span class="sub"><i class="fas fa-user-md"></i> <?= safe($c['especialidade']) ?></span>
              <span class="sub">
                <i class="fas fa-calendar-day"></i> <?= safe($data) ?>
                <?php if ($hora): ?> às <?= safe($hora) ?><?php endif; ?>
              </span>
            </div>
            <div>
              <span class="<?= $badge ?>"><?= ucfirst($st) ?></span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <h3 class="subtitulo-sec" style="margin-top:24px;">Histórico de consultas</h3>
    <?php if (empty($consultasHistorico)): ?>
      <p class="item-vazio">Você ainda não possui histórico de consultas.</p>
    <?php else: ?>
      <div class="lista-consultas">
        <?php foreach ($consultasHistorico as $c): 
          $data  = fmt_data($c['data']);
          $hora  = substr($c['horario'] ?? '', 0, 5);
          $st    = $c['status'] ?? 'pendente';
          $badge = badge_class($st);
        ?>
          <div class="card-consulta">
            <div class="icon" style="background:#fee2e2;color:#b91c1c;">
              <i class="fas fa-notes-medical"></i>
            </div>
            <div class="info">
              <strong><?= safe($c['medico_nome']) ?></strong>
              <span class="sub"><i class="fas fa-user-md"></i> <?= safe($c['especialidade']) ?></span>
              <span class="sub">
                <i class="fas fa-calendar-day"></i> <?= safe($data) ?>
                <?php if ($hora): ?> às <?= safe($hora) ?><?php endif; ?>
              </span>
            </div>
            <div>
              <span class="<?= $badge ?>"><?= ucfirst($st) ?></span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <!-- ==================== SEÇÃO MÉDICOS (LISTA/TABELA) ==================== -->
  <section id="sec-medicos" class="secao">
    <h2>Médicos Ativos</h2>
    <?php if (empty($listaMedicos)): ?>
      <p class="item-vazio">Nenhum médico ativo cadastrado.</p>
    <?php else: ?>
      <table class="tabela-medicos">
        <thead>
          <tr>
            <th>Profissional</th>
            <th>CRM</th>
            <th>Especialidade</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($listaMedicos as $m): 
            $fotoMed = !empty($m['foto']) ? $m['foto'] : 'imagens/medico-default.png';
          ?>
            <tr>
              <td>
                <div style="display:flex; align-items:center; gap:10px;">
                  <div class="medico-foto-box">
                    <img src="<?= safe($fotoMed) ?>" alt="Foto de <?= safe($m['nome']) ?>">
                  </div>
                  <span><?= safe($m['nome']) ?></span>
                </div>
              </td>
              <td><?= safe($m['crm']) ?></td>
              <td><?= safe($m['especialidade']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>

  <!-- ==================== SEÇÃO EXAMES (CARDS EM GRID 4x3) ==================== -->
  <section id="sec-exames" class="secao">
    <h2>Exames</h2>

    <h3 class="subtitulo-sec">Exames pendentes</h3>
    <?php if (empty($examesPendentesLista)): ?>
      <p class="item-vazio">Você não possui exames pendentes.</p>
    <?php else: ?>
      <div class="lista-exames">
        <?php foreach ($examesPendentesLista as $ex): 
          $titulo = $ex['titulo'] ?? 'Exame';
          $data   = fmt_data($ex['data'] ?? '');
          $hora   = isset($ex['horario']) ? substr($ex['horario'],0,5) : '';
          $st     = $ex['status'] ?? 'pendente';
        ?>
          <div class="card-exame">
            <div class="icon"><i class="fas fa-vial"></i></div>
            <div class="info">
              <strong><?= safe($titulo) ?></strong>
              <span class="sub">
                <?php if ($data): ?>
                  <i class="fas fa-calendar-day"></i> <?= safe($data) ?>
                  <?php if ($hora): ?> às <?= safe($hora) ?><?php endif; ?>
                <?php else: ?>
                  Sem data definida
                <?php endif; ?>
              </span>
            </div>
            <div>
              <span class="<?= badge_class($st) ?>"><?= ucfirst($st) ?></span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <h3 class="subtitulo-sec" style="margin-top:24px;">Histórico de exames</h3>
    <?php if (empty($examesOutros)): ?>
      <p class="item-vazio">Nenhum exame realizado ou cancelado registrado.</p>
    <?php else: ?>
      <div class="lista-exames">
        <?php foreach ($examesOutros as $ex): 
          $titulo = $ex['titulo'] ?? 'Exame';
          $data   = fmt_data($ex['data'] ?? '');
          $hora   = isset($ex['horario']) ? substr($ex['horario'],0,5) : '';
          $st     = $ex['status'] ?? 'pendente';
        ?>
          <div class="card-exame">
            <div class="icon" style="background:#fee2e2;color:#b91c1c;"><i class="fas fa-file-medical-alt"></i></div>
            <div class="info">
              <strong><?= safe($titulo) ?></strong>
              <span class="sub">
                <?php if ($data): ?>
                  <i class="fas fa-calendar-day"></i> <?= safe($data) ?>
                  <?php if ($hora): ?> às <?= safe($hora) ?><?php endif; ?>
                <?php else: ?>
                  Sem data definida
                <?php endif; ?>
              </span>
            </div>
            <div>
              <span class="<?= badge_class($st) ?>"><?= ucfirst($st) ?></span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <!-- ==================== SEÇÃO CONFIGURAÇÕES (MENU OCULTO) ==================== -->
  <section id="sec-config" class="secao">
    <h2>Configurações da Conta</h2>

    <div class="config-card">
      <p>Atualize seus dados básicos de contato. Para alterar informações mais avançadas, use a tela de <strong>Perfil</strong>.</p>

      <?php if ($msg_config_ok): ?>
        <div class="msg-ok"><?= safe($msg_config_ok) ?></div>
      <?php endif; ?>
      <?php if ($msg_config_erro): ?>
        <div class="msg-erro"><?= safe($msg_config_erro) ?></div>
      <?php endif; ?>

      <form method="post" autocomplete="off">
        <input type="hidden" name="acao" value="salvar_config">

        <div class="config-row">
          <div>
            <label>Nome</label>
            <input type="text" value="<?= safe($cliente['nome']) ?>" readonly>
          </div>
          <div>
            <label>CPF</label>
            <input type="text" value="<?= safe($cliente['cpf']) ?>" readonly>
          </div>
        </div>

        <div class="config-row">
          <div>
            <label for="email_config">Email</label>
            <input type="email" id="email_config" name="email_config" value="<?= safe($cliente['email']) ?>">
          </div>
          <div>
            <label for="tel_config">Telefone</label>
            <input type="text" id="tel_config" name="telefone_config" value="<?= safe($cliente['telefone']) ?>">
          </div>
        </div>

        <button type="submit" class="btn-salvar-config">
          <i class="fas fa-save"></i> Salvar alterações
        </button>
      </form>

      <div class="hint" style="margin-top:10px; font-size:12px; color:#6b7280;">
        Para alterar foto de perfil e outros dados detalhados, acesse o <b>Perfil</b> pelo topo do menu lateral.
      </div>
    </div>
  </section>

</main>

<script>
/* ===== Navegação de seções (SPA simples) ===== */
function mudarSecao(sec, li){
  document.querySelectorAll('.secao').forEach(s => s.classList.remove('ativa'));
  const alvo = document.getElementById('sec-' + sec);
  if (alvo) alvo.classList.add('ativa');

  document.querySelectorAll('.sidebar nav ul li').forEach(x => x.classList.remove('active'));
  if (li) li.classList.add('active');
}

/* ===== Estado do agendamento ===== */
let MEDICO_ATUAL = 0;
let MEDICO_NOME  = '';
let SLOT_ESCOLHIDO = null;

let dataAtual = new Date(); // navegação do calendário
let dataSelecionada = null; // Date do dia escolhido no calendário

/* ===== Util ===== */
function pad2(n){ return String(n).padStart(2,'0'); }
function dateToISO(d){ return d ? `${d.getFullYear()}-${pad2(d.getMonth()+1)}-${pad2(d.getDate())}` : ''; }
function isoToDate(s){
  if(!/^\d{4}-\d{2}-\d{2}$/.test(s)) return null;
  const [y,m,d]=s.split('-').map(Number);
  const dt = new Date(y, m-1, d); dt.setHours(0,0,0,0);
  return dt;
}

/* ===== Selecionar médico a partir da tabela ===== */
function ativarMedico(id, nome, ev){
  MEDICO_ATUAL = id;
  MEDICO_NOME  = nome;
  document.getElementById('medico-head').textContent = `Médico: ${nome}`;
  document.getElementById('btn-confirmar').disabled = true;
  SLOT_ESCOLHIDO = null;

  // marca botão da linha
  document.querySelectorAll('.btn-ativar-cal').forEach(b=>b.classList.remove('sel'));
  if (ev && ev.target) {
    ev.target.classList.add('sel');
  }

  // rola até a grade de agendamento
  document.getElementById('agendar-section').scrollIntoView({behavior:'smooth', block:'center'});

  // sugere a data de hoje
  const hojeISO = new Date().toISOString().slice(0,10);
  document.getElementById('data-ag').value = hojeISO;
  dataSelecionada = isoToDate(hojeISO);
  renderizarCalendario();
  carregarSlotsSelecionados();
}

/* ===== Calendário ===== */
function mudarMes(delta){
  const dia = dataAtual.getDate();
  dataAtual.setDate(1);
  dataAtual.setMonth(dataAtual.getMonth() + delta);
  const ultimo = new Date(dataAtual.getFullYear(), dataAtual.getMonth()+1, 0).getDate();
  dataAtual.setDate(Math.min(dia, ultimo));
  renderizarCalendario();
}

function renderizarCalendario(){
  const grid = document.getElementById('calendar-grid');
  const titulo = document.getElementById('mes-ano');
  grid.innerHTML = '';

  const ano = dataAtual.getFullYear();
  const mes = dataAtual.getMonth();

  const nomeMes = dataAtual.toLocaleString('pt-BR', { month:'long' });
  titulo.textContent = `${nomeMes} ${ano}`;

  const primeiroDia = new Date(ano, mes, 1).getDay();
  const diasNoMes   = new Date(ano, mes+1, 0).getDate();

  const hoje = new Date(); hoje.setHours(0,0,0,0);

  for (let i=0; i<primeiroDia; i++){
    grid.appendChild(document.createElement('div'));
  }

  for (let dia = 1; dia <= diasNoMes; dia++){
    const d = new Date(ano, mes, dia); d.setHours(0,0,0,0);
    const div = document.createElement('div');
    div.textContent = dia;

    if (d < hoje){
      div.className = 'dia-desativado';
    } else {
      if (dataSelecionada && d.getTime() === dataSelecionada.getTime()){
        div.className = 'dia-borda';
      }
      div.onclick = () => {
        dataSelecionada = d;
        document.getElementById('data-ag').value = dateToISO(d);
        document.getElementById('data-head').textContent = `Data: ${d.toLocaleDateString('pt-BR')}`;
        renderizarCalendario();
        carregarSlotsSelecionados();
      };
    }
    grid.appendChild(div);
  }

  if (dataSelecionada){
    document.getElementById('data-head').textContent = `Data: ${dataSelecionada.toLocaleDateString('pt-BR')}`;
  } else {
    document.getElementById('data-head').textContent = '';
  }
}

function sincronizarCalendarioPorCampo(){
  const iso = document.getElementById('data-ag').value;
  dataSelecionada = isoToDate(iso);
  if (dataSelecionada){
    dataAtual = new Date(dataSelecionada.getFullYear(), dataSelecionada.getMonth(), dataSelecionada.getDate());
  }
  renderizarCalendario();
}

/* ===== Slots ===== */
function carregarSlotsSelecionados(){
  const slotsBox = document.getElementById('slots');
  slotsBox.innerHTML = '';

  if (!MEDICO_ATUAL){
    slotsBox.innerHTML = '<em>Selecione um médico na lista acima.</em>';
    return;
  }
  if (!dataSelecionada){
    const iso = document.getElementById('data-ag').value;
    if (iso) dataSelecionada = isoToDate(iso);
  }
  if (!dataSelecionada){
    slotsBox.innerHTML = '<em>Escolha uma data no calendário.</em>';
    return;
  }

  const dataISO = dateToISO(dataSelecionada);
  slotsBox.innerHTML = '<span>Carregando...</span>';

  fetch(`Cliente.php?action=slots&medico_id=${MEDICO_ATUAL}&data=${encodeURIComponent(dataISO)}`)
    .then(r=>r.json())
    .then(j=>{
      if(!j.ok){ slotsBox.innerHTML = `<em>${j.msg||'Erro'}</em>`; return; }
      if(!j.slots || j.slots.length===0){
        slotsBox.innerHTML = '<em>Sem horários livres nesta data.</em>';
        return;
      }
      slotsBox.innerHTML = '';
      SLOT_ESCOLHIDO = null;
      document.getElementById('btn-confirmar').disabled = true;

      j.slots.forEach(h=>{
        const b = document.createElement('button');
        b.className = 'slot-btn';
        b.textContent = h;
        b.onclick = ()=>{
          [...slotsBox.querySelectorAll('.slot-btn')].forEach(x=>x.classList.remove('sel'));
          b.classList.add('sel');
          SLOT_ESCOLHIDO = h;
          document.getElementById('btn-confirmar').disabled = false;
        };
        slotsBox.appendChild(b);
      });
    })
    .catch(()=> { slotsBox.innerHTML = '<em>Falha ao carregar slots.</em>'; });
}

/* ===== Confirmar ===== */
function confirmarAgendamento(){
  if (!MEDICO_ATUAL || !dataSelecionada || !SLOT_ESCOLHIDO){
    alert('Selecione médico, data e horário.');
    return;
  }
  const fd = new FormData();
  fd.append('action','agendar');
  fd.append('medico_id', MEDICO_ATUAL);
  fd.append('data', dateToISO(dataSelecionada));
  fd.append('horario', SLOT_ESCOLHIDO);

  fetch('Cliente.php', { method:'POST', body: fd })
    .then(r=>r.json())
    .then(j=>{
      if(!j.ok){ alert(j.msg || 'Erro'); return; }
      alert(j.msg);
      SLOT_ESCOLHIDO = null;
      document.getElementById('btn-confirmar').disabled = true;
      location.reload();
    })
    .catch(()=> alert('Erro de rede.'));
}

/* ===== Init ===== */
window.addEventListener('DOMContentLoaded', () => {
  const hojeISO = new Date().toISOString().slice(0,10);
  const campoData = document.getElementById('data-ag');
  if (campoData) campoData.value = hojeISO;
  dataSelecionada = isoToDate(hojeISO);
  renderizarCalendario();
});
</script>
</body>
</html>
