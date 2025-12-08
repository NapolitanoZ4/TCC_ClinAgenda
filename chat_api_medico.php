<?php
// chat_api_medico.php
session_start();
date_default_timezone_set('America/Sao_Paulo');

header('Content-Type: application/json; charset=utf-8');

// Deixa erros no log, mas não quebra o JSON na tela
error_reporting(E_ALL);
ini_set('display_errors', 0);

// ====================== CONEXÃO BANCO ======================
$mysqli = new mysqli("localhost", "root", "", "clinagenda");
if ($mysqli->connect_error) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'msg' => 'Erro ao conectar ao banco.']);
  exit;
}
$mysqli->set_charset('utf8mb4');

function safe($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// ====================== VALIDA MÉDICO LOGADO ======================
if (!isset($_SESSION['id_medico'])) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'msg' => 'Médico não autenticado.']);
  exit;
}
$medico_id = (int)$_SESSION['id_medico'];

// Aceita tanto "action" quanto "acao"
$acao =
  $_GET['action'] ?? $_POST['action'] ??
  $_GET['acao']   ?? $_POST['acao']   ?? '';

/* ==========================================================
   1) LISTAR CONVERSAS / PACIENTES DO MÉDICO (LATERAL)
   ========================================================== */
/*
  items[] = {
    conversa_id,
    cliente_id,
    cliente_nome,
    foto_cliente,
    ultima_msg,
    ultima_data
  }
*/
if ($acao === 'listar_conversas') {

  $sql = "
    SELECT
      c.id AS conversa_id,
      cli.id AS cliente_id,
      cli.nome AS cliente_nome,
      (
        SELECT f.caminho
        FROM fotos f
        WHERE f.cliente_id = cli.id AND f.tipo = 'perfil'
        ORDER BY f.data_upload DESC, f.id DESC
        LIMIT 1
      ) AS foto_cliente,
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
  if (!$st) {
    echo json_encode(['ok' => false, 'msg' => 'Falha ao preparar consulta.']);
    exit;
  }

  $st->bind_param("i", $medico_id);
  $st->execute();
  $result = $st->get_result();
  $items  = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

  echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ==========================================================
   2) CRIAR / OBTER CONVERSA ENTRE MÉDICO E CLIENTE
   ========================================================== */
if ($acao === 'obter_ou_criar_conversa') {

  // o JS do médico pode enviar "cliente_id" OU "paciente_id"
  $cliente_id = (int)($_POST['cliente_id'] ?? ($_POST['paciente_id'] ?? 0));

  if (!$cliente_id) {
    echo json_encode(['ok' => false, 'msg' => 'cliente_id/paciente_id inválido.']);
    exit;
  }

  // já existe conversa?
  $sel = $mysqli->prepare("
    SELECT id
    FROM conversas
    WHERE cliente_id = ? AND medico_id = ?
    LIMIT 1
  ");
  if (!$sel) {
    echo json_encode(['ok' => false, 'msg' => 'Falha ao preparar seleção.']);
    exit;
  }

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
  if (!$ins) {
    echo json_encode(['ok' => false, 'msg' => 'Falha ao preparar inserção.']);
    exit;
  }

  $ins->bind_param("ii", $cliente_id, $medico_id);
  if ($ins->execute()) {
    echo json_encode(['ok' => true, 'conversa_id' => (int)$ins->insert_id]);
  } else {
    echo json_encode(['ok' => false, 'msg' => 'Falha ao criar conversa.']);
  }
  exit;
}

/* ==========================================================
   3) LISTAR MENSAGENS DE UMA CONVERSA (MÉDICO)
   ========================================================== */
if ($acao === 'listar_mensagens') {
  $conversa_id = (int)($_GET['conversa_id'] ?? 0);
  $ultimo_id   = (int)($_GET['ultimo_id'] ?? 0);

  if (!$conversa_id) {
    echo json_encode(['ok' => false, 'msg' => 'conversa_id inválido.']);
    exit;
  }

  // checa se conversa pertence ao médico
  $chk = $mysqli->prepare("
    SELECT cliente_id, medico_id
    FROM conversas
    WHERE id = ? LIMIT 1
  ");
  if (!$chk) {
    echo json_encode(['ok' => false, 'msg' => 'Falha ao preparar verificação.']);
    exit;
  }

  $chk->bind_param("i", $conversa_id);
  $chk->execute();
  $row = $chk->get_result()->fetch_assoc();
  if (!$row) {
    echo json_encode(['ok' => false, 'msg' => 'Conversa não encontrada.']);
    exit;
  }
  if ($medico_id !== (int)$row['medico_id']) {
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

  if (!$st) {
    echo json_encode(['ok' => false, 'msg' => 'Falha ao preparar listagem.']);
    exit;
  }

  $st->execute();
  $rs   = $st->get_result();
  $msgs = $rs ? $rs->fetch_all(MYSQLI_ASSOC) : [];

  echo json_encode(['ok' => true, 'items' => $msgs], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ==========================================================
   4) ENVIAR MENSAGEM (MÉDICO)
   ========================================================== */
if ($acao === 'enviar_mensagem') {
  $conversa_id = (int)($_POST['conversa_id'] ?? 0);
  $texto       = trim($_POST['mensagem'] ?? '');

  if (!$conversa_id || $texto === '') {
    echo json_encode(['ok' => false, 'msg' => 'Dados inválidos.']);
    exit;
  }

  // checa se conversa pertence ao médico
  $chk = $mysqli->prepare("
    SELECT cliente_id, medico_id
    FROM conversas
    WHERE id = ? LIMIT 1
  ");
  if (!$chk) {
    echo json_encode(['ok' => false, 'msg' => 'Falha ao preparar verificação.']);
    exit;
  }

  $chk->bind_param("i", $conversa_id);
  $chk->execute();
  $row = $chk->get_result()->fetch_assoc();
  if (!$row) {
    echo json_encode(['ok' => false, 'msg' => 'Conversa não encontrada.']);
    exit;
  }

  if ($medico_id !== (int)$row['medico_id']) {
    echo json_encode(['ok' => false, 'msg' => 'Acesso negado à conversa.']);
    exit;
  }

  $ins = $mysqli->prepare("
    INSERT INTO mensagens (conversa_id, remetente_tipo, remetente_id, mensagem)
    VALUES (?, 'medico', ?, ?)
  ");
  if (!$ins) {
    echo json_encode(['ok' => false, 'msg' => 'Falha ao preparar envio.']);
    exit;
  }

  $ins->bind_param("iis", $conversa_id, $medico_id, $texto);

  if ($ins->execute()) {
    // atualiza "atualizado_em" pra ordenar a lista
    $upd = $mysqli->prepare("UPDATE conversas SET atualizado_em = NOW() WHERE id = ?");
    if ($upd) {
      $upd->bind_param("i", $conversa_id);
      $upd->execute();
    }

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
   5) LISTAR NOTIFICAÇÕES (MÉDICO)
   ========================================================== */
if ($acao === 'listar_notificacoes') {
  $apenas_nao_lidas = isset($_GET['nao_lidas']) && $_GET['nao_lidas'] == '1';

  $sql = "
    SELECT id, tipo, referencia_id, mensagem, lida, criado_em
    FROM notificacoes
    WHERE usuario_tipo = 'medico' AND usuario_id = ?
  ";
  if ($apenas_nao_lidas) {
    $sql .= " AND lida = 0";
  }
  $sql .= " ORDER BY criado_em DESC LIMIT 50";

  $st = $mysqli->prepare($sql);
  if (!$st) {
    echo json_encode(['ok' => false, 'msg' => 'Falha ao preparar listagem.']);
    exit;
  }

  $st->bind_param("i", $medico_id);
  $st->execute();
  $rs   = $st->get_result();
  $rows = $rs ? $rs->fetch_all(MYSQLI_ASSOC) : [];

  echo json_encode(['ok' => true, 'items' => $rows], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ==========================================================
   6) MARCAR NOTIFICAÇÃO COMO LIDA (MÉDICO)
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
    WHERE id = ? AND usuario_tipo = 'medico' AND usuario_id = ?
  ");
  if (!$up) {
    echo json_encode(['ok' => false, 'msg' => 'Falha ao preparar atualização.']);
    exit;
  }

  $up->bind_param("ii", $id_notif, $medico_id);
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
