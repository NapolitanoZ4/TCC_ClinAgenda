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

  // slots base (08:00..10:40 e 13:00..17:40, passos de 40min, pausa almoço 11h–12:59)
  $slots_padrao = function(){
    $out = [];

    // Manhã 08:00 até 10:40
    for ($m = 8*60; $m <= (11*60 - 20); $m += 40) {
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

  // bloqueados (ocupado = 1)
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

  if (!isset($_SESSION['id'])) { 
    echo json_encode(['ok'=>false,'msg'=>'Faça login.']); 
    exit; 
  }

  $cliente_id = (int)$_SESSION['id'];
  $medico_id  = (int)($_POST['medico_id'] ?? 0);
  $data       = $_POST['data'] ?? '';
  $horario    = $_POST['horario'] ?? '';

  if (
    !$medico_id ||
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) ||
    !preg_match('/^\d{2}:\d{2}$/', $horario)
  ) {
    echo json_encode(['ok'=>false,'msg'=>'Parâmetros inválidos']); 
    exit;
  }

  $horario .= ':00';

  $dtSel = DateTime::createFromFormat(
    'Y-m-d H:i:s', 
    "$data $horario", 
    new DateTimeZone('America/Sao_Paulo')
  );

  if (!$dtSel || $dtSel < new DateTime('now', new DateTimeZone('America/Sao_Paulo'))) {
    echo json_encode(['ok'=>false,'msg'=>'Esse horário já passou.']); 
    exit;
  }

  $conn->begin_transaction();

  try {
    // 1) bloqueado?
    $q1 = $conn->prepare("
      SELECT 1 
      FROM horarios_disponiveis 
      WHERE medico_id=? AND data=? AND horario=? AND ocupado=1 
      LIMIT 1
    ");
    $q1->bind_param('iss', $medico_id, $data, $horario);
    $q1->execute();
    if ($q1->get_result()->num_rows) {
      throw new Exception('Horário bloqueado pelo médico.');
    }

    // 2) já ocupado?
    $q2 = $conn->prepare("
      SELECT 1 
      FROM agendamentos 
      WHERE medico_id=? AND data=? AND horario=? 
        AND status IN ('pendente','confirmado') 
      LIMIT 1
    ");
    $q2->bind_param('iss', $medico_id, $data, $horario);
    $q2->execute();
    if ($q2->get_result()->num_rows) {
      throw new Exception('Esse horário acabou de ser ocupado.');
    }

    // 3) inserir agendamento
    $ins = $conn->prepare("
      INSERT INTO agendamentos (medico_id, cliente_id, data, horario, status) 
      VALUES (?, ?, ?, ?, 'pendente')
    ");
    $ins->bind_param('iiss', $medico_id, $cliente_id, $data, $horario);
    $ins->execute();
    $agendamento_id = (int)$ins->insert_id;

    /* ===== BUSCAR NOMES (pra mensagem ficar bonita) ===== */
    // Nome do cliente
    $nomeCliente = 'Paciente';
    if ($stCli = $conn->prepare("SELECT nome FROM clientes WHERE id = ?")) {
      $stCli->bind_param('i', $cliente_id);
      $stCli->execute();
      $rCli = $stCli->get_result()->fetch_assoc();
      if ($rCli && !empty($rCli['nome'])) {
        $nomeCliente = $rCli['nome'];
      }
      $stCli->close();
    }

    // Nome do médico
    $nomeMedico = 'Médico';
    if ($stMed = $conn->prepare("SELECT nome FROM medicos WHERE id = ?")) {
      $stMed->bind_param('i', $medico_id);
      $stMed->execute();
      $rMed = $stMed->get_result()->fetch_assoc();
      if ($rMed && !empty($rMed['nome'])) {
        $nomeMedico = $rMed['nome'];
      }
      $stMed->close();
    }

    $dataBR = date('d/m/Y', strtotime($data));
    $horaBR = substr($horario, 0, 5);

    /* ===== NOTIFICAÇÃO PARA O MÉDICO ===== */
    $msgMedico = "Novo agendamento de $nomeCliente em $dataBR às $horaBR.";
    if ($notif = $conn->prepare("
      INSERT INTO notificacoes (usuario_tipo, usuario_id, tipo, referencia_id, mensagem)
      VALUES ('medico', ?, 'agendamento_realizado', ?, ?)
    ")) {
      $notif->bind_param('iis', $medico_id, $agendamento_id, $msgMedico);
      $notif->execute();
      $notif->close();
    }

    /* ===== NOTIFICAÇÃO PARA O CLIENTE ===== */
    $msgCliente = "Seu agendamento com $nomeMedico foi criado para $dataBR às $horaBR (status: pendente).";
    if ($notif2 = $conn->prepare("
      INSERT INTO notificacoes (usuario_tipo, usuario_id, tipo, referencia_id, mensagem)
      VALUES ('cliente', ?, 'agendamento_realizado', ?, ?)
    ")) {
      $notif2->bind_param('iis', $cliente_id, $agendamento_id, $msgCliente);
      $notif2->execute();
      $notif2->close();
    }

    $conn->commit();

    echo json_encode([
      'ok'  => true,
      'msg' => 'Agendamento criado! Status: pendente (aguardando confirmação do médico).'
    ]);
  } catch (Throwable $e) {
    $conn->rollback();
    $msg = $e->getMessage();
    $l   = strtolower($msg);
    if (str_contains($l,'duplicate') || str_contains($l,'unique')) {
      $msg = 'Esse horário acabou de ser ocupado. Escolha outro.';
    }
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
/* Ajuste: usamos a tabela 'fotos'; se não achar nada, cai num padrão em /imagens */
$fotoPath = 'imagens/cliente-default.png'; // crie esse arquivo se ainda não existir
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

/* Texto extra para status dos exames (para os cards de Exames) */
function status_exame_descricao($st){
  $s = strtolower((string)$st);
  if ($s === 'pendente') {
    return 'Exame ainda não realizado. Compareça no dia e horário marcados.';
  }
  if ($s === 'realizado') {
    return 'Exame já realizado. Verifique o resultado com a clínica ou médico.';
  }
  if ($s === 'cancelado') {
    return 'Exame cancelado. Caso necessário, solicite um novo agendamento.';
  }
  return 'Status do exame em atualização.';
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

  <!-- Estilos extras só para o widget de chat (lado direito/esquerdo + dia) -->
  <style>
    #ca-chat-messages {
      display: flex;
      flex-direction: column;
      gap: 6px;
      padding: 8px 10px;
      overflow-y: auto;
    }
    .ca-chat-dia {
      align-self: center;
      margin: 6px 0;
      padding: 3px 10px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 600;
      background: #d1fae5;
      color: #047857;
    }
    .ca-msg {
      max-width: 80%;
      padding: 8px 10px 4px;
      border-radius: 12px;
      font-size: 13px;
      box-shadow: 0 2px 6px rgba(15, 23, 42, 0.08);
      display: inline-flex;
      flex-direction: column;
      gap: 2px;
    }
    /* CLIENTE (eu) -> direita, verde mais escuro */
    .ca-msg.me {
      align-self: flex-end;
      background: #16a34a;
      color: #ecfdf5;
      border-bottom-right-radius: 4px;
    }
    /* MÉDICO -> esquerda, branco */
    .ca-msg.them {
      align-self: flex-start;
      background: #ffffff;
      color: #111827;
      border-bottom-left-radius: 4px;
    }
    .ca-msg-time {
      margin-top: 2px;
      font-size: 10px;
      opacity: 0.75;
      text-align: right;
    }
  </style>
</head>
<body>

<header class="header">
  <!-- Logo clicável para voltar para HOME (Agendamentos) -->
  <div class="header-logo" onclick="irParaHome()" style="cursor:pointer;">
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
          $descSt = status_exame_descricao($st);
        ?>
          <div class="card-exame">
            <div class="icon"><i class="fas fa-vial"></i></div>
            <div class="info">
              <strong><?= safe($titulo) ?></strong>
              <span class="sub">
                <i class="fas fa-id-card"></i> Código do exame: #<?= (int)$ex['id'] ?>
              </span>
              <span class="sub">
                <?php if ($data): ?>
                  <i class="fas fa-calendar-day"></i> <?= safe($data) ?>
                  <?php if ($hora): ?> às <?= safe($hora) ?><?php endif; ?>
                <?php else: ?>
                  Sem data definida
                <?php endif; ?>
              </span>
              <span class="sub">
                <i class="fas fa-info-circle"></i> <?= safe($descSt) ?>
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
          $descSt = status_exame_descricao($st);
        ?>
          <div class="card-exame">
            <div class="icon" style="background:#fee2e2;color:#b91c1c;"><i class="fas fa-file-medical-alt"></i></div>
            <div class="info">
              <strong><?= safe($titulo) ?></strong>
              <span class="sub">
                <i class="fas fa-id-card"></i> Código do exame: #<?= (int)$ex['id'] ?>
              </span>
              <span class="sub">
                <?php if ($data): ?>
                  <i class="fas fa-calendar-day"></i> <?= safe($data) ?>
                  <?php if ($hora): ?> às <?= safe($hora) ?><?php endif; ?>
                <?php else: ?>
                  Sem data definida
                <?php endif; ?>
              </span>
              <span class="sub">
                <i class="fas fa-info-circle"></i> <?= safe($descSt) ?>
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

<!-- ============ WIDGET DE CHAT / NOTIFICAÇÕES (CLIENTE) ============ -->
<div id="ca-chat-fab">
  💬
</div>

<div id="ca-chat-overlay" class="ca-hidden">
  <div id="ca-chat-backdrop"></div>
  <div id="ca-chat-card">
    <div class="ca-chat-header">
      <div class="ca-chat-tabs">
        <button type="button" class="ca-tab ca-tab-active" data-tab="notificacoes">Notificações</button>
        <button type="button" class="ca-tab" data-tab="chat">Chat</button>
      </div>
      <button type="button" id="ca-chat-close">×</button>
    </div>

    <div class="ca-chat-body">
      <!-- ABA NOTIFICAÇÕES -->
      <div id="ca-tab-notificacoes" class="ca-tab-pane ca-tab-pane-active">
        <div id="ca-notif-list" class="ca-list">
          <div class="ca-empty">Carregando notificações...</div>
        </div>
      </div>

      <!-- ABA CHAT -->
      <div id="ca-tab-chat" class="ca-tab-pane">
        <div id="ca-chat-main">
          <!-- LADO ESQUERDO – LISTA DE MÉDICOS -->
          <div class="ca-chat-sidebar">
            <div class="ca-chat-sidebar-header">Médicos</div>
            <div id="ca-chat-contatos" class="ca-chat-contatos">
              <div class="ca-empty">Carregando médicos...</div>
            </div>
          </div>

          <!-- LADO DIREITO – CONVERSA -->
          <div class="ca-chat-panel">
            <div class="ca-chat-conversa-header">
              <div id="ca-chat-avatar" class="ca-chat-avatar">?</div>
              <div class="ca-chat-titles">
                <div id="ca-chat-title">Selecione um médico</div>
                <div id="ca-chat-sub">Nenhum chat ativo</div>
              </div>
            </div>

            <div id="ca-chat-messages" class="ca-chat-messages">
              <div class="ca-empty">Escolha um médico na lista ao lado para iniciar o chat.</div>
            </div>

            <div class="ca-chat-input">
              <textarea id="ca-chat-text" rows="2" placeholder="Digite uma mensagem..." disabled></textarea>
              <button type="button" id="ca-chat-send" disabled>Enviar</button>
            </div>
          </div>
        </div>
      </div>
      <!-- FIM ABA CHAT -->
    </div> <!-- .ca-chat-body -->
  </div> <!-- #ca-chat-card -->
</div> <!-- #ca-chat-overlay -->


<script>
/* ================= NAVEGAÇÃO DAS SEÇÕES ================= */
function mudarSecao(sec, li){
  document.querySelectorAll('.secao').forEach(s => s.classList.remove('ativa'));
  const alvo = document.getElementById('sec-' + sec);
  if (alvo) alvo.classList.add('ativa');

  document.querySelectorAll('.sidebar nav ul li').forEach(x => x.classList.remove('active'));
  if (li) li.classList.add('active');
}

/* Logo no cabeçalho -> volta para HOME (Agendamentos) */
function irParaHome(){
  const liHome = document.querySelector('.sidebar nav ul li:first-child');
  mudarSecao('agendamentos', liHome);
}

/* ================= ESTADO AGENDAMENTO ================= */
let MEDICO_ATUAL   = 0;
let MEDICO_NOME    = '';
let SLOT_ESCOLHIDO = null;

let dataAtual       = new Date(); // navegação do calendário
let dataSelecionada = null;       // Date do dia escolhido no calendário

function pad2(n){ return String(n).padStart(2,'0'); }
function dateToISO(d){ return d ? `${d.getFullYear()}-${pad2(d.getMonth()+1)}-${pad2(d.getDate())}` : ''; }
function isoToDate(s){
  if(!/^\d{4}-\d{2}-\d{2}$/.test(s)) return null;
  const [y,m,d]=s.split('-').map(Number);
  const dt = new Date(y, m-1, d);
  dt.setHours(0,0,0,0);
  return dt;
}

/* ========= Selecionar médico (tabela) ========= */
function ativarMedico(id, nome, ev){
  MEDICO_ATUAL = id;
  MEDICO_NOME  = nome;

  document.getElementById('medico-head').textContent = `Médico: ${nome}`;
  document.getElementById('btn-confirmar').disabled = true;
  SLOT_ESCOLHIDO = null;

  document.querySelectorAll('.btn-ativar-cal').forEach(b => b.classList.remove('sel'));
  if (ev && ev.target) ev.target.classList.add('sel');

  document.getElementById('agendar-section').scrollIntoView({behavior:'smooth', block:'center'});

  const hojeISO = new Date().toISOString().slice(0,10);
  const campoData = document.getElementById('data-ag');
  if (campoData) campoData.value = hojeISO;

  dataSelecionada = isoToDate(hojeISO);
  renderizarCalendario();
  carregarSlotsSelecionados();
}

/* ================= CALENDÁRIO ================= */
function mudarMes(delta){
  const dia = dataAtual.getDate();
  dataAtual.setDate(1);
  dataAtual.setMonth(dataAtual.getMonth() + delta);
  const ultimo = new Date(dataAtual.getFullYear(), dataAtual.getMonth()+1, 0).getDate();
  dataAtual.setDate(Math.min(dia, ultimo));
  renderizarCalendario();
}

function renderizarCalendario(){
  const grid   = document.getElementById('calendar-grid');
  const titulo = document.getElementById('mes-ano');
  if (!grid || !titulo) return;

  grid.innerHTML = '';

  const ano = dataAtual.getFullYear();
  const mes = dataAtual.getMonth();

  const nomeMes = dataAtual.toLocaleString('pt-BR', { month:'long' });
  titulo.textContent = `${nomeMes} ${ano}`;

  const primeiroDia = new Date(ano, mes, 1).getDay();
  const diasNoMes   = new Date(ano, mes+1, 0).getDate();

  const hoje = new Date();
  hoje.setHours(0,0,0,0);

  for (let i=0; i<primeiroDia; i++){
    grid.appendChild(document.createElement('div'));
  }

  for (let dia = 1; dia <= diasNoMes; dia++){
    const d = new Date(ano, mes, dia);
    d.setHours(0,0,0,0);
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
    document.getElementById('data-head').textContent =
      `Data: ${dataSelecionada.toLocaleDateString('pt-BR')}`;
  } else {
    document.getElementById('data-head').textContent = '';
  }
}

function sincronizarCalendarioPorCampo(){
  const iso = document.getElementById('data-ag').value;
  dataSelecionada = isoToDate(iso);
  if (dataSelecionada){
    dataAtual = new Date(
      dataSelecionada.getFullYear(),
      dataSelecionada.getMonth(),
      dataSelecionada.getDate()
    );
  }
  renderizarCalendario();
}

/* ================= SLOTS ================= */
function carregarSlotsSelecionados(){
  const slotsBox = document.getElementById('slots');
  if (!slotsBox) return;
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
    .then(r => r.json())
    .then(j => {
      if(!j.ok){
        slotsBox.innerHTML = `<em>${j.msg||'Erro'}</em>`;
        return;
      }
      if(!j.slots || j.slots.length===0){
        slotsBox.innerHTML = '<em>Sem horários livres nesta data.</em>';
        return;
      }
      slotsBox.innerHTML = '';
      SLOT_ESCOLHIDO = null;
      document.getElementById('btn-confirmar').disabled = true;

      j.slots.forEach(h => {
        const b = document.createElement('button');
        b.className = 'slot-btn';
        b.textContent = h;
        b.onclick = () => {
          [...slotsBox.querySelectorAll('.slot-btn')].forEach(x => x.classList.remove('sel'));
          b.classList.add('sel');
          SLOT_ESCOLHIDO = h;
          document.getElementById('btn-confirmar').disabled = false;
        };
        slotsBox.appendChild(b);
      });
    })
    .catch(() => {
      slotsBox.innerHTML = '<em>Falha ao carregar slots.</em>';
    });
}

/* ================= CONFIRMAR AGENDAMENTO ================= */
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
    .then(r => r.json())
    .then(j => {
      if(!j.ok){
        alert(j.msg || 'Erro');
        return;
      }
      alert(j.msg);
      SLOT_ESCOLHIDO = null;
      document.getElementById('btn-confirmar').disabled = true;
      location.reload();
    })
    .catch(() => alert('Erro de rede.'));
}

/* ================= INIT AGENDAMENTO ================= */
window.addEventListener('DOMContentLoaded', () => {
  const hojeISO = new Date().toISOString().slice(0,10);
  const campoData = document.getElementById('data-ag');
  if (campoData) campoData.value = hojeISO;
  dataSelecionada = isoToDate(hojeISO);
  renderizarCalendario();
});

/* =====================================================
   WIDGET CHAT / NOTIFICAÇÕES (CLIENTE)
===================================================== */

const CA_API = 'chat_api_cliente.php';
const CLIENTE_ID = <?= (int)$cliente['id']; ?>;

let CA_CONVERSA_ID   = null;
let CA_ULTIMO_MSG_ID = 0;
let CA_POLL          = null;

function escapeHtml(str) {
  return String(str || '')
    .replace(/&/g,'&amp;')
    .replace(/</g,'&lt;')
    .replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;')
    .replace(/'/g,'&#39;');
}

/* ---- elementos principais ---- */
const fab      = document.getElementById('ca-chat-fab');
const overlay  = document.getElementById('ca-chat-overlay');
const closeBtn = document.getElementById('ca-chat-close');

fab.addEventListener('click', () => {
  overlay.classList.remove('ca-hidden');
  carregarNotificacoes();
});

closeBtn.addEventListener('click', () => {
  overlay.classList.add('ca-hidden');
  pararPolling();
});

/* ---- troca de abas ---- */
document.querySelectorAll('.ca-tab').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.ca-tab').forEach(b => b.classList.remove('ca-tab-active'));
    btn.classList.add('ca-tab-active');

    const tab = btn.dataset.tab;
    document.querySelectorAll('.ca-tab-pane').forEach(p => p.classList.remove('ca-tab-pane-active'));
    document.getElementById('ca-tab-' + tab).classList.add('ca-tab-pane-active');

    if (tab === 'notificacoes') {
      carregarNotificacoes();
      pararPolling();
    } else if (tab === 'chat') {
      carregarContatosChatCliente();
    }
  });
});

/* ============= NOTIFICAÇÕES ============= */
function carregarNotificacoes(){
  const list = document.getElementById('ca-notif-list');
  list.innerHTML = '<div class="ca-empty">Carregando notificações...</div>';

  fetch(CA_API + '?action=listar_notificacoes')
    .then(r => r.json())
    .then(j => {
      if (!j.ok) {
        list.innerHTML = '<div class="ca-empty">Erro ao carregar notificações.</div>';
        return;
      }
      if (!j.items || !j.items.length) {
        list.innerHTML = '<div class="ca-empty">Nenhuma notificação por enquanto.</div>';
        return;
      }
      list.innerHTML = j.items.map(n => {
        const data = (n.criado_em || '').replace('T',' ');
        let tipoLegivel = (n.tipo || '').replace(/_/g, ' ');
        if (tipoLegivel) {
          tipoLegivel = tipoLegivel.charAt(0).toUpperCase() + tipoLegivel.slice(1);
        }
        return `
          <div class="ca-notif-item ${n.lida == 1 ? 'lida' : ''}">
            <div class="ca-notif-tipo">${tipoLegivel}</div>
            <div class="ca-notif-msg">${escapeHtml(n.mensagem || '')}</div>
            <div class="ca-notif-data">${data}</div>
          </div>
        `;
      }).join('');
    })
    .catch(() => {
      list.innerHTML = '<div class="ca-empty">Erro de rede.</div>';
    });
}

/* ============= CHAT – LISTA DE MÉDICOS (CLIENTE) ============= */
function carregarContatosChatCliente() {
  const box = document.getElementById('ca-chat-contatos');
  box.innerHTML = '<div class="ca-empty">Carregando médicos...</div>';

  fetch(CA_API + '?action=listar_conversas')
    .then(r => r.json())
    .then(j => {
      if (!j.ok) {
        box.innerHTML = '<div class="ca-empty">Erro ao carregar médicos.</div>';
        return;
      }
      const items = j.items || [];
      if (!items.length) {
        box.innerHTML = '<div class="ca-empty">Nenhum médico disponível para chat.</div>';
        return;
      }

      box.innerHTML = items.map(c => contatoMedicoHTML(c)).join('');
    })
    .catch(() => {
      box.innerHTML = '<div class="ca-empty">Erro de rede.</div>';
    });
}

/* monta card do médico (blindado para variações de campos) */
function contatoMedicoHTML(c) {
  const medicoId = Number(
    c.medico_id   !== undefined ? c.medico_id   :
    c.id_medico   !== undefined ? c.id_medico   :
    c.id          !== undefined ? c.id          : 0
  );

  const medicoNomeRaw = (
    c.medico_nome !== undefined ? c.medico_nome :
    c.nome_medico !== undefined ? c.nome_medico :
    c.nome        !== undefined ? c.nome        : null
  );

  const especialidadeRaw = c.especialidade !== undefined ? c.especialidade : '';
  const ultimaMsgRaw     = c.ultima_msg    !== undefined ? c.ultima_msg    : '';
  const ultimaDataRaw    = c.ultima_data   !== undefined ? c.ultima_data   : '';
  const fotoRaw          = (
    c.foto_medico !== undefined ? c.foto_medico :
    c.foto        !== undefined ? c.foto        : ''
  );

  const nome = escapeHtml(medicoNomeRaw || 'Médico');
  const esp  = escapeHtml(especialidadeRaw || '');
  const prev = escapeHtml(ultimaMsgRaw || '');
  const hora = ultimaDataRaw ? ultimaDataRaw.slice(11, 16) : '';

  const convId = c.conversa_id ? Number(c.conversa_id) : 0;

  let avatarHTML;
  if (fotoRaw) {
    avatarHTML = `<img src="${escapeHtml(fotoRaw)}" alt="${nome}">`;
  } else {
    const base = medicoNomeRaw || 'M';
    const iniciais = base
      .trim()
      .split(/\s+/)
      .filter(Boolean)
      .map(p => p[0])
      .join('')
      .slice(0,2)
      .toUpperCase();
    avatarHTML = `<span>${escapeHtml(iniciais)}</span>`;
  }

  const nomeAttr = escapeHtml(medicoNomeRaw || 'Médico');
  const espAttr  = escapeHtml(especialidadeRaw || '');

  return `
    <div class="ca-chat-contact"
         data-conversa="${convId}"
         data-medico="${medicoId}"
         data-nome="${nomeAttr}"
         data-esp="${espAttr}"
         onclick="caSelecionarContato(this)">
      <div class="ca-chat-contact-avatar">
        ${avatarHTML}
      </div>
      <div class="ca-chat-contact-main">
        <div class="ca-chat-contact-top">
          <span class="ca-chat-contact-name">${nome}</span>
          <span class="ca-chat-contact-time">${hora}</span>
        </div>
        <div class="ca-chat-contact-last">
          ${prev ? prev : '<em>Nenhuma mensagem ainda.</em>'}
        </div>
        ${esp ? `<div class="ca-chat-contact-esp">${esp}</div>` : ''}
      </div>
    </div>
  `;
}

/* quando clica num médico */
function caSelecionarContato(el) {
  document.querySelectorAll('.ca-chat-contact').forEach(c => c.classList.remove('active'));
  el.classList.add('active');

  const medicoId = parseInt(el.dataset.medico || '0', 10);
  const nome     = el.dataset.nome || ('Médico ' + medicoId);
  const esp      = el.dataset.esp  || '';
  let conversaId = parseInt(el.dataset.conversa || '0', 10);

  const titleEl = document.getElementById('ca-chat-title');
  const subEl   = document.getElementById('ca-chat-sub');
  const avatar  = document.getElementById('ca-chat-avatar');

  titleEl.textContent = nome;
  subEl.textContent   = esp ? esp : 'Chat com o médico';

  const iniciais = (nome || '')
    .trim()
    .split(/\s+/)
    .filter(Boolean)
    .map(p => p[0])
    .join('')
    .slice(0,2)
    .toUpperCase() || '?';
  avatar.textContent = iniciais;

  const msgBox = document.getElementById('ca-chat-messages');
  msgBox.innerHTML = '<div class="ca-empty">Carregando mensagens...</div>';

  const textArea = document.getElementById('ca-chat-text');
  const btnSend  = document.getElementById('ca-chat-send');
  textArea.disabled = false;
  btnSend.disabled  = false;

  pararPolling();

  if (!conversaId) {
    const fd = new URLSearchParams();
    fd.append('action', 'obter_ou_criar_conversa');
    fd.append('cliente_id', CLIENTE_ID);
    fd.append('medico_id', medicoId);

    fetch(CA_API, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(j => {
        if (!j.ok || !j.conversa_id) {
          msgBox.innerHTML = '<div class="ca-empty">Não foi possível iniciar o chat.</div>';
          return;
        }
        conversaId          = parseInt(j.conversa_id, 10);
        el.dataset.conversa = conversaId;
        CA_CONVERSA_ID      = conversaId;
        CA_ULTIMO_MSG_ID    = 0;

        msgBox.innerHTML = '';
        carregarMensagensCliente(true);
        CA_POLL = setInterval(() => carregarMensagensCliente(false), 4000);
      })
      .catch(() => {
        msgBox.innerHTML = '<div class="ca-empty">Erro de rede ao criar conversa.</div>';
      });
  } else {
    CA_CONVERSA_ID   = conversaId;
    CA_ULTIMO_MSG_ID = 0;

    msgBox.innerHTML = '';
    carregarMensagensCliente(true);
    CA_POLL = setInterval(() => carregarMensagensCliente(false), 4000);
  }
}

/* carregar mensagens */
function carregarMensagensCliente(primeiraVez = false) {
  if (!CA_CONVERSA_ID) return;

  const params = new URLSearchParams();
  params.set('action', 'listar_mensagens');
  params.set('conversa_id', CA_CONVERSA_ID);
  if (!primeiraVez && CA_ULTIMO_MSG_ID > 0) {
    params.set('ultimo_id', CA_ULTIMO_MSG_ID);
  }

  fetch(CA_API + '?' + params.toString())
    .then(r => r.json())
    .then(j => {
      if (!j.ok) return;
      const msgs = j.items || [];
      if (!msgs.length) return;

      const box = document.getElementById('ca-chat-messages');
      if (primeiraVez) {
        box.innerHTML = '';
      }

      msgs.forEach(m => {
        const idMsg = parseInt(m.id, 10) || 0;
        if (idMsg > CA_ULTIMO_MSG_ID) CA_ULTIMO_MSG_ID = idMsg;

        // 🔹 Tenta pegar o ID do remetente nos campos mais comuns da API
        const remetenteId = parseInt(
          m.remetente_id !== undefined ? m.remetente_id :
          m.id_remetente   !== undefined ? m.id_remetente :
          m.cliente_id     !== undefined ? m.cliente_id :
          0,
          10
        );

        // CLIENTE_ID vem lá de cima: const CLIENTE_ID = <?= (int)$cliente['id']; ?>;
        const isMe = (remetenteId === CLIENTE_ID);

        const hora = (m.enviado_em || '').substring(11, 16);

        const div = document.createElement('div');
        div.className = 'ca-msg ' + (isMe ? 'me' : 'them');
        div.innerHTML = `
          <div>${escapeHtml(m.mensagem)}</div>
          <div class="ca-msg-time">${hora}</div>
        `;
        box.appendChild(div);
      });

      box.scrollTop = box.scrollHeight;
    })
    .catch(() => {});
}


function pararPolling() {
  if (CA_POLL) {
    clearInterval(CA_POLL);
    CA_POLL = null;
  }
}

/* enviar mensagem */
document.getElementById('ca-chat-send').addEventListener('click', enviarMensagemCliente);
document.getElementById('ca-chat-text').addEventListener('keydown', (ev) => {
  if (ev.key === 'Enter' && !ev.shiftKey) {
    ev.preventDefault();
    enviarMensagemCliente();
  }
});

function enviarMensagemCliente() {
  const txt = document.getElementById('ca-chat-text');
  const msg = txt.value.trim();
  if (!msg || !CA_CONVERSA_ID) return;

  const fd = new FormData();
  fd.append('action', 'enviar_mensagem');
  fd.append('conversa_id', CA_CONVERSA_ID);
  fd.append('mensagem', msg);

  fetch(CA_API, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(j => {
      if (!j.ok) {
        alert(j.msg || 'Falha ao enviar mensagem.');
        return;
      }
      txt.value = '';
      carregarMensagensCliente(false);
    })
    .catch(() => {
      alert('Erro de rede.');
    });
}
</script>
</body>
</html>
