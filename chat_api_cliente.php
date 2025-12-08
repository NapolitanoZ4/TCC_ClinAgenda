<?php
// chat_api_cliente.php
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

function safe($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// ====================== VALIDA CLIENTE LOGADO ======================
$cliente_id = null;

// compatível com sessões antigas e novas
if (isset($_SESSION['id_cliente'])) {
  $cliente_id = (int)$_SESSION['id_cliente'];
} elseif (isset($_SESSION['id'])) {
  $cliente_id = (int)$_SESSION['id'];
}

if (!$cliente_id) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'msg' => 'Cliente não autenticado.']);
  exit;
}

$acao = $_GET['action'] ?? $_POST['action'] ?? '';

/* ==========================================================
   1) LISTAR CONVERSAS / CONTATOS DO CLIENTE (LATERAL)
   ========================================================== */
/*
  items[] = {
    conversa_id,
    medico_id,
    medico_nome,
    especialidade,
    foto_medico,
    ultima_msg,
    ultima_data
  }
*/
if ($acao === 'listar_conversas') {

  // CLIENTE → lista TODOS os médicos ativos,
  // juntando (se existir) a conversa daquele cliente com o médico.
  $sql = "
    SELECT
      COALESCE(c.id, 0) AS conversa_id,
      m.id AS medico_id,
      m.nome AS medico_nome,
      m.especialidade,
      (
        SELECT f.caminho
        FROM fotos f
        WHERE f.medico_id = m.id AND f.tipo = 'perfil'
        ORDER BY f.data_upload DESC, f.id DESC
        LIMIT 1
      ) AS foto_medico,
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
      ON c.medico_id = m.id
     AND c.cliente_id = ?
    WHERE m.status = 'ativo'
    ORDER BY
      COALESCE(ultima_data, c.atualizado_em, m.nome) DESC
  ";

  $st = $mysqli->prepare($sql);
  $st->bind_param("i", $cliente_id);
  $st->execute();
  $items = $st->get_result()->fetch_all(MYSQLI_ASSOC);

  echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ==========================================================
   2) CRIAR / OBTER CONVERSA ENTRE CLIENTE E MÉDICO
   ========================================================== */
if ($acao === 'obter_ou_criar_conversa') {
  $cliente_id_post = (int)($_POST['cliente_id'] ?? 0);
  $medico_id       = (int)($_POST['medico_id'] ?? 0);

  // segurança básica: o cliente_id do POST tem que ser o logado
  if (!$cliente_id_post || !$medico_id || $cliente_id_post !== $cliente_id) {
    echo json_encode(['ok' => false, 'msg' => 'cliente_id ou medico_id inválido.']);
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
   3) LISTAR MENSAGENS DE UMA CONVERSA (CLIENTE)
   ========================================================== */
if ($acao === 'listar_mensagens') {
  $conversa_id = (int)($_GET['conversa_id'] ?? 0);
  $ultimo_id   = (int)($_GET['ultimo_id'] ?? 0);

  if (!$conversa_id) {
    echo json_encode(['ok' => false, 'msg' => 'conversa_id inválido.']);
    exit;
  }

  // checa se conversa pertence ao cliente
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
  if ($cliente_id !== (int)$row['cliente_id']) {
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
   4) ENVIAR MENSAGEM (CLIENTE)
   ========================================================== */
if ($acao === 'enviar_mensagem') {
  $conversa_id = (int)($_POST['conversa_id'] ?? 0);
  $texto       = trim($_POST['mensagem'] ?? '');

  if (!$conversa_id || $texto === '') {
    echo json_encode(['ok' => false, 'msg' => 'Dados inválidos.']);
    exit;
  }

  // checa se conversa pertence ao cliente
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

  if ($cliente_id !== (int)$row['cliente_id']) {
    echo json_encode(['ok' => false, 'msg' => 'Acesso negado à conversa.']);
    exit;
  }

  $ins = $mysqli->prepare("
    INSERT INTO mensagens (conversa_id, remetente_tipo, remetente_id, mensagem)
    VALUES (?, 'cliente', ?, ?)
  ");
  $ins->bind_param("iis", $conversa_id, $cliente_id, $texto);

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
   5) LISTAR NOTIFICAÇÕES (CLIENTE)
   ========================================================== */
if ($acao === 'listar_notificacoes') {
  $apenas_nao_lidas = isset($_GET['nao_lidas']) && $_GET['nao_lidas'] == '1';

  $sql = "
    SELECT id, tipo, referencia_id, mensagem, lida, criado_em
    FROM notificacoes
    WHERE usuario_tipo = 'cliente' AND usuario_id = ?
  ";
  if ($apenas_nao_lidas) {
    $sql .= " AND lida = 0";
  }
  $sql .= " ORDER BY criado_em DESC LIMIT 50";

  $st = $mysqli->prepare($sql);
  $st->bind_param("i", $cliente_id);
  $st->execute();
  $rs = $st->get_result();
  $rows = $rs->fetch_all(MYSQLI_ASSOC);

  echo json_encode(['ok' => true, 'items' => $rows], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ==========================================================
   6) MARCAR NOTIFICAÇÃO COMO LIDA (CLIENTE)
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
    WHERE id = ? AND usuario_tipo = 'cliente' AND usuario_id = ?
  ");
  $up->bind_param("ii", $id_notif, $cliente_id);
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
