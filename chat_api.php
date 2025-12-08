<?php
// chat_api.php
session_start();
date_default_timezone_set('America/Sao_Paulo');

header('Content-Type: application/json; charset=utf-8');

// ====================== CONEXÃO BANCO ======================
$mysqli = new mysqli("localhost", "root", "", "clinagenda");
if ($mysqli->connect_error) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'msg' => 'Erro ao conectar ao banco.']);
  exit;
}
$mysqli->set_charset('utf8mb4');

// ====================== QUEM ESTÁ LOGADO? ======================
// Prioridade: cliente, depois médico
$tipo_usuario = null;   // 'medico' ou 'cliente'
$usuario_id   = null;

// cliente (novo padrão)
if (isset($_SESSION['id_cliente'])) {
  $tipo_usuario = 'cliente';
  $usuario_id   = (int)$_SESSION['id_cliente'];
}
// cliente (compatibilidade antiga – ClinAgenda)
elseif (isset($_SESSION['id'])) {
  $tipo_usuario = 'cliente';
  $usuario_id   = (int)$_SESSION['id'];
}
// médico (se não tiver cliente)
elseif (isset($_SESSION['id_medico'])) {
  $tipo_usuario = 'medico';
  $usuario_id   = (int)$_SESSION['id_medico'];
}

if (!$tipo_usuario || !$usuario_id) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'msg' => 'Usuário não autenticado.']);
  exit;
}

function safe($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$acao = $_GET['action'] ?? $_POST['action'] ?? '';

/* ==========================================================
   1) LISTAR CONVERSAS / CONTATOS DO USUÁRIO (LATERAL)
   ========================================================== */
/*
  QUANDO MÉDICO:
  items[] = {
    conversa_id,
    cliente_id / paciente_id / nome_cliente / nome_paciente,
    foto_cliente / foto_paciente / foto,
    ultima_msg,
    ultima_data
  }

  QUANDO CLIENTE:
  items[] = {
    conversa_id,
    medico_id,
    medico_nome / nome / nome_medico,
    especialidade,
    foto_medico / foto,
    ultima_msg,
    ultima_data
  }
*/
if ($acao === 'listar_conversas') {

  /* ------------------ CLIENTE: lista todos médicos ativos ------------------ */
  if ($tipo_usuario === 'cliente') {

    $sql = "
      SELECT
        COALESCE(c.id, 0) AS conversa_id,

        m.id   AS medico_id,

        -- nomes com vários aliases pro JS
        m.nome AS medico_nome,
        m.nome AS nome_medico,
        m.nome AS nome,

        m.especialidade,

        -- fotos com vários aliases
        (
          SELECT f.caminho
          FROM fotos f
          WHERE f.medico_id = m.id AND f.tipo = 'perfil'
          ORDER BY f.data_upload DESC, f.id DESC
          LIMIT 1
        ) AS foto_medico,
        (
          SELECT f.caminho
          FROM fotos f
          WHERE f.medico_id = m.id AND f.tipo = 'perfil'
          ORDER BY f.data_upload DESC, f.id DESC
          LIMIT 1
        ) AS foto,

        -- última mensagem e data
        (
          SELECT ms.mensagem
          FROM mensagens ms
          WHERE ms.conversa_id = c.id
          ORDER BY ms.id DESC
          LIMIT 1
        ) AS ultima_msg,
        (
          SELECT ms.enviado_em
          FROM mensagens ms
          WHERE ms.conversa_id = c.id
          ORDER BY ms.id DESC
          LIMIT 1
        ) AS ultima_data
      FROM medicos m
      LEFT JOIN conversas c
        ON c.medico_id  = m.id
       AND c.cliente_id = ?
      WHERE m.status = 'ativo'
      ORDER BY COALESCE(ultima_data, c.atualizado_em, m.nome) DESC
    ";

    $st = $mysqli->prepare($sql);
    $st->bind_param("i", $usuario_id);
    $st->execute();
    $items = $st->get_result()->fetch_all(MYSQLI_ASSOC);

    echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
    exit;
  }

  /* ------------------ MÉDICO: lista os clientes/pacientes ------------------ */
  if ($tipo_usuario === 'medico') {

    $sql = "
      SELECT
        c.id AS conversa_id,

        cli.id AS cliente_id,
        cli.id AS paciente_id,

        -- nomes com um monte de aliases
        cli.nome AS cliente_nome,
        cli.nome AS paciente_nome,
        cli.nome AS nome_cliente,
        cli.nome AS nome_paciente,
        cli.nome AS nome,

        -- fotos com vários aliases
        (
          SELECT f.caminho
          FROM fotos f
          WHERE f.cliente_id = cli.id AND f.tipo = 'perfil'
          ORDER BY f.data_upload DESC, f.id DESC
          LIMIT 1
        ) AS foto_cliente,
        (
          SELECT f.caminho
          FROM fotos f
          WHERE f.cliente_id = cli.id AND f.tipo = 'perfil'
          ORDER BY f.data_upload DESC, f.id DESC
          LIMIT 1
        ) AS foto_paciente,
        (
          SELECT f.caminho
          FROM fotos f
          WHERE f.cliente_id = cli.id AND f.tipo = 'perfil'
          ORDER BY f.data_upload DESC, f.id DESC
          LIMIT 1
        ) AS foto,

        -- última mensagem e data
        (
          SELECT ms.mensagem
          FROM mensagens ms
          WHERE ms.conversa_id = c.id
          ORDER BY ms.id DESC
          LIMIT 1
        ) AS ultima_msg,
        (
          SELECT ms.enviado_em
          FROM mensagens ms
          WHERE ms.conversa_id = c.id
          ORDER BY ms.id DESC
          LIMIT 1
        ) AS ultima_data
      FROM conversas c
      INNER JOIN clientes cli ON cli.id = c.cliente_id
      WHERE c.medico_id = ?
      ORDER BY COALESCE(ultima_data, c.atualizado_em, c.id) DESC
    ";

    $st = $mysqli->prepare($sql);
    $st->bind_param("i", $usuario_id);
    $st->execute();
    $items = $st->get_result()->fetch_all(MYSQLI_ASSOC);

    echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
    exit;
  }

  echo json_encode(['ok' => false, 'msg' => 'Tipo de usuário inválido.']);
  exit;
}

/* ==========================================================
   2) CRIAR / OBTER CONVERSA ENTRE CLIENTE E MÉDICO
   ========================================================== */
if ($acao === 'obter_ou_criar_conversa') {
  // Aceita tanto cliente_id quanto paciente_id
  $cliente_id = (int)($_POST['cliente_id'] ?? ($_POST['paciente_id'] ?? 0));
  $medico_id  = (int)($_POST['medico_id'] ?? 0);

  // Preenche automaticamente a partir da sessão, se faltar
  if ($tipo_usuario === 'cliente') {
    if ($cliente_id === 0) $cliente_id = $usuario_id;
  }
  if ($tipo_usuario === 'medico') {
    if ($medico_id === 0) $medico_id = $usuario_id;
  }

  if (!$cliente_id || !$medico_id) {
    echo json_encode(['ok' => false, 'msg' => 'cliente_id ou medico_id inválido.']);
    exit;
  }

  // segurança básica: o usuário só pode criar conversa em que ele está envolvido
  if ($tipo_usuario === 'cliente' && $usuario_id !== $cliente_id) {
    echo json_encode(['ok' => false, 'msg' => 'Acesso negado à conversa.']);
    exit;
  }
  if ($tipo_usuario === 'medico' && $usuario_id !== $medico_id) {
    echo json_encode(['ok' => false, 'msg' => 'Acesso negado à conversa.']);
    exit;
  }

  // já existe conversa?
  $sel = $mysqli->prepare("
    SELECT id
    FROM conversas
    WHERE cliente_id = ? AND medico_id = ?
    LIMIT 1
  ");
  $sel->bind_param("ii", $cliente_id, $medico_id);
  $sel->execute();
  $res = $sel->get_result();
  if ($res && $res->num_rows) {
    $row = $res->fetch_assoc();
    echo json_encode(['ok' => true, 'conversa_id' => (int)$row['id']]);
    exit;
  }

  // cria conversa
  $ins = $mysqli->prepare("
    INSERT INTO conversas (cliente_id, medico_id)
    VALUES (?, ?)
  ");
  $ins->bind_param("ii", $cliente_id, $medico_id);
  if ($ins->execute()) {
    echo json_encode(['ok' => true, 'conversa_id' => (int)$ins->insert_id]);
  } else {
    echo json_encode(['ok' => false, 'msg' => 'Falha ao criar conversa.']);
  }
  exit;
}

/* ==========================================================
   3) LISTAR MENSAGENS DE UMA CONVERSA
   ========================================================== */
if ($acao === 'listar_mensagens') {
  $conversa_id = (int)($_GET['conversa_id'] ?? 0);
  $ultimo_id   = (int)($_GET['ultimo_id'] ?? 0);

  if (!$conversa_id) {
    echo json_encode(['ok' => false, 'msg' => 'conversa_id inválido.']);
    exit;
  }

  // checa se conversa pertence ao usuário
  $chk = $mysqli->prepare("
    SELECT cliente_id, medico_id
    FROM conversas
    WHERE id = ? LIMIT 1
  ");
  $chk->bind_param("i", $conversa_id);
  $chk->execute();
  $row = $chk->get_result()->fetch_assoc();
  if (!$row) {
    echo json_encode(['ok' => false, 'msg' => 'Conversa não encontrada.']);
    exit;
  }
  if ($tipo_usuario === 'cliente' && $usuario_id !== (int)$row['cliente_id']) {
    echo json_encode(['ok' => false, 'msg' => 'Acesso negado à conversa.']);
    exit;
  }
  if ($tipo_usuario === 'medico' && $usuario_id !== (int)$row['medico_id']) {
    echo json_encode(['ok' => false, 'msg' => 'Acesso negado à conversa.']);
    exit;
  }

  if ($ultimo_id > 0) {
    $sql = "
      SELECT id, remetente_tipo, remetente_id, mensagem, enviado_em
      FROM mensagens
      WHERE conversa_id = ? AND id > ?
      ORDER BY id ASC
    ";
    $st = $mysqli->prepare($sql);
    $st->bind_param("ii", $conversa_id, $ultimo_id);
  } else {
    $sql = "
      SELECT id, remetente_tipo, remetente_id, mensagem, enviado_em
      FROM mensagens
      WHERE conversa_id = ?
      ORDER BY id ASC
      LIMIT 200
    ";
    $st = $mysqli->prepare($sql);
    $st->bind_param("i", $conversa_id);
  }

  $st->execute();
  $rs = $st->get_result();
  $msgs = $rs->fetch_all(MYSQLI_ASSOC);

  echo json_encode(['ok' => true, 'items' => $msgs], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ==========================================================
   4) ENVIAR MENSAGEM
   ========================================================== */
if ($acao === 'enviar_mensagem') {
  $conversa_id = (int)($_POST['conversa_id'] ?? 0);
  $texto       = trim($_POST['mensagem'] ?? '');

  if (!$conversa_id || $texto === '') {
    echo json_encode(['ok' => false, 'msg' => 'Dados inválidos.']);
    exit;
  }

  // checa se conversa pertence ao usuário
  $chk = $mysqli->prepare("
    SELECT cliente_id, medico_id
    FROM conversas
    WHERE id = ? LIMIT 1
  ");
  $chk->bind_param("i", $conversa_id);
  $chk->execute();
  $row = $chk->get_result()->fetch_assoc();
  if (!$row) {
    echo json_encode(['ok' => false, 'msg' => 'Conversa não encontrada.']);
    exit;
  }

  $cliente_id = (int)$row['cliente_id'];
  $medico_id  = (int)$row['medico_id'];

  if ($tipo_usuario === 'cliente' && $usuario_id !== $cliente_id) {
    echo json_encode(['ok' => false, 'msg' => 'Acesso negado à conversa.']);
    exit;
  }
  if ($tipo_usuario === 'medico' && $usuario_id !== $medico_id) {
    echo json_encode(['ok' => false, 'msg' => 'Acesso negado à conversa.']);
    exit;
  }

  $ins = $mysqli->prepare("
    INSERT INTO mensagens (conversa_id, remetente_tipo, remetente_id, mensagem)
    VALUES (?, ?, ?, ?)
  ");
  $ins->bind_param("isis", $conversa_id, $tipo_usuario, $usuario_id, $texto);

  if ($ins->execute()) {
    // atualiza "atualizado_em" pra ordenar a lista
    $upd = $mysqli->prepare("UPDATE conversas SET atualizado_em = NOW() WHERE id = ?");
    $upd->bind_param("i", $conversa_id);
    $upd->execute();

    echo json_encode([
      'ok'  => true,
      'msg' => 'Mensagem enviada.',
      'id'  => (int)$ins->insert_id
    ], JSON_UNESCAPED_UNICODE);
  } else {
    echo json_encode(['ok' => false, 'msg' => 'Falha ao enviar mensagem.']);
  }
  exit;
}

/* ==========================================================
   5) LISTAR NOTIFICAÇÕES DO USUÁRIO
   ========================================================== */
if ($acao === 'listar_notificacoes') {
  $apenas_nao_lidas = isset($_GET['nao_lidas']) && $_GET['nao_lidas'] == '1';

  $sql = "
    SELECT id, tipo, referencia_id, mensagem, lida, criado_em
    FROM notificacoes
    WHERE usuario_tipo = ? AND usuario_id = ?
  ";
  if ($apenas_nao_lidas) {
    $sql .= " AND lida = 0";
  }
  $sql .= " ORDER BY criado_em DESC LIMIT 50";

  $st = $mysqli->prepare($sql);
  $st->bind_param("si", $tipo_usuario, $usuario_id);
  $st->execute();
  $rs = $st->get_result();
  $rows = $rs->fetch_all(MYSQLI_ASSOC);

  echo json_encode(['ok' => true, 'items' => $rows], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ==========================================================
   6) MARCAR NOTIFICAÇÃO COMO LIDA
   ========================================================== */
if ($acao === 'marcar_notificacao_lida') {
  $id_notif = (int)($_POST['id'] ?? 0);
  if (!$id_notif) {
    echo json_encode(['ok' => false, 'msg' => 'ID inválido.']);
    exit;
  }

  $up = $mysqli->prepare("
    UPDATE notificacoes
    SET lida = 1
    WHERE id = ? AND usuario_tipo = ? AND usuario_id = ?
  ");
  $up->bind_param("isi", $id_notif, $tipo_usuario, $usuario_id);
  if ($up->execute()) {
    echo json_encode(['ok' => true]);
  } else {
    echo json_encode(['ok' => false, 'msg' => 'Falha ao atualizar.']);
  }
  exit;
}

/* ==========================================================
   AÇÃO DESCONHECIDA
   ========================================================== */
echo json_encode(['ok' => false, 'msg' => 'Ação inválida.']);
