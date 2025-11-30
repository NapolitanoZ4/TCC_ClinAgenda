<?php
session_start();
date_default_timezone_set('America/Sao_Paulo');

$mysqli = new mysqli("localhost", "root", "", "clinagenda");
if ($mysqli->connect_error) {
  die("Erro ao conectar ao banco: " . $mysqli->connect_error);
}
$mysqli->set_charset("utf8mb4");

/* ================== VERIFICA LOGIN ADM ================== */
if (!isset($_SESSION['id_adm'])) {
  header("Location: index.php");
  exit;
}
$id_adm = (int)$_SESSION['id_adm'];

function safe($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* ================== BUSCAR DADOS DO ADM ================== */
$adm = [
  'nome'  => 'Administrador',
  'email' => ''
];

if ($stmt = $mysqli->prepare("SELECT nome, email FROM adm WHERE id = ? LIMIT 1")) {
  $stmt->bind_param("i", $id_adm);
  $stmt->execute();
  $res = $stmt->get_result();
  if ($res && $res->num_rows > 0) {
    $adm = $res->fetch_assoc();
  }
  $stmt->close();
}

/* ====================================================================
   TRATAMENTO DE FORMULÁRIOS
   ==================================================================== */

/* ---------- CADASTRAR MÉDICO ---------- */
$mensagem_medico   = '';
$mensagem_cliente  = '';
$cliente_edicao    = null;
$id_cliente_edicao = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'cadastrar_medico') {

  $nome          = trim($_POST['nome']          ?? '');
  $crm           = trim($_POST['crm']           ?? '');
  $especialidade = trim($_POST['especialidade'] ?? '');
  $email         = trim($_POST['email']         ?? '');
  $senha_raw     = $_POST['senha']             ?? '';
  $status        = $_POST['status']            ?? 'ativo';

  if ($nome === '' || $crm === '' || $email === '' || $senha_raw === '') {
    $mensagem_medico = 'Preencha todos os campos obrigatórios (*).';
  } else {
    $senha_hash = password_hash($senha_raw, PASSWORD_DEFAULT);

    $sql = "INSERT INTO medicos (nome, crm, especialidade, email, senha, status) 
            VALUES (?, ?, ?, ?, ?, ?)";
    if ($stmt = $mysqli->prepare($sql)) {
      $stmt->bind_param("ssssss", $nome, $crm, $especialidade, $email, $senha_hash, $status);
      if ($stmt->execute()) {
        $mensagem_medico = 'Médico cadastrado com sucesso!';
      } else {
        $mensagem_medico = 'Erro ao cadastrar médico: ' . $stmt->error;
      }
      $stmt->close();
    } else {
      $mensagem_medico = 'Erro ao preparar cadastro de médico.';
    }
  }
}

/* ---------- ALTERAR STATUS DO MÉDICO (ATIVAR / INATIVAR) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'toggle_status') {
  $id_medico   = (int)($_POST['id_medico']   ?? 0);
  $novo_status =        $_POST['novo_status']?? 'ativo';

  if ($id_medico > 0 && in_array($novo_status, ['ativo','inativo'], true)) {
    if ($stmt = $mysqli->prepare("UPDATE medicos SET status = ? WHERE id = ?")) {
      $stmt->bind_param("si", $novo_status, $id_medico);
      $stmt->execute();
      $stmt->close();
    }
  }
  header("Location: ADM.php?sec=medicos");
  exit;
}

/* ---------- SALVAR EDIÇÃO DO CLIENTE ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'salvar_cliente') {

  $id_cliente_edicao = (int)($_POST['id_cliente'] ?? 0);
  $nome       = trim($_POST['nome']       ?? '');
  $sobrenome  = trim($_POST['sobrenome']  ?? '');
  $email      = trim($_POST['email']      ?? '');
  $telefone   = trim($_POST['telefone']   ?? '');
  $cpf        = trim($_POST['cpf']        ?? '');
  $status_cli = $_POST['status']          ?? 'ativo';
  $nova_senha = $_POST['nova_senha']      ?? '';

  if ($id_cliente_edicao <= 0) {
    $mensagem_cliente = 'Cliente inválido.';
  } elseif ($nome === '' || $email === '') {
    $mensagem_cliente = 'Nome e e-mail são obrigatórios.';
  } else {

    // Atualiza dados básicos
    $sql = "UPDATE clientes 
            SET nome=?, sobrenome=?, email=?, telefone=?, cpf=?, status=? 
            WHERE id=?";
    if ($stmt = $mysqli->prepare($sql)) {
      $stmt->bind_param(
        "ssssssi",
        $nome,
        $sobrenome,
        $email,
        $telefone,
        $cpf,
        $status_cli,
        $id_cliente_edicao
      );
      if ($stmt->execute()) {
        // Atualiza senha se for preenchida
        if ($nova_senha !== '') {
          $hash = password_hash($nova_senha, PASSWORD_DEFAULT);
          if ($st2 = $mysqli->prepare("UPDATE clientes SET senha=? WHERE id=?")) {
            $st2->bind_param("si", $hash, $id_cliente_edicao);
            $st2->execute();
            $st2->close();
          }
        }

        // redireciona para evitar reenvio e manter card aberto
        header("Location: ADM.php?sec=clientes&edit_cliente={$id_cliente_edicao}&cliente_ok=1");
        exit;

      } else {
        $mensagem_cliente = 'Erro ao atualizar cliente: ' . $stmt->error;
      }
      $stmt->close();
    } else {
      $mensagem_cliente = 'Erro ao preparar atualização do cliente.';
    }
  }
}

/* mensagem após redirect bem sucedido */
if (isset($_GET['cliente_ok']) && $_GET['cliente_ok'] === '1') {
  $mensagem_cliente = 'Cliente atualizado com sucesso!';
}

/* id do cliente selecionado para edição (GET) */
if (isset($_GET['edit_cliente'])) {
  $id_cliente_edicao = (int)$_GET['edit_cliente'];
}

/* carrega dados do cliente em edição, se houver id */
if ($id_cliente_edicao > 0) {
  if ($st = $mysqli->prepare("SELECT id, nome, sobrenome, cpf, email, telefone, status FROM clientes WHERE id = ? LIMIT 1")) {
    $st->bind_param("i", $id_cliente_edicao);
    $st->execute();
    $res = $st->get_result();
    if ($res && $res->num_rows > 0) {
      $cliente_edicao = $res->fetch_assoc();
    }
    $st->close();
  }
}

/* ====================================================================
   DADOS PARA GRÁFICOS
   ==================================================================== */

/* 1) Agendamentos por dia (sempre últimos 30 dias, incluindo hoje) */
$labels_dias  = [];
$valores_dias = [];

// pega os agendamentos dos últimos 30 dias
$mapDias = [];
$sqlDias = "
  SELECT data, COUNT(*) AS total
  FROM agendamentos
  WHERE data >= CURDATE() - INTERVAL 29 DAY
    AND data <= CURDATE()
  GROUP BY data
";
$resDias = $mysqli->query($sqlDias);
if ($resDias) {
  while ($row = $resDias->fetch_assoc()) {
    $mapDias[$row['data']] = (int)$row['total'];
  }
}

// monta os 30 dias a partir de hoje-29 até hoje (sempre 30 barrinhas)
for ($i = 29; $i >= 0; $i--) {
  $dataISO      = date('Y-m-d', strtotime("-{$i} days"));
  $labels_dias[]  = date('d/m', strtotime($dataISO));
  $valores_dias[] = isset($mapDias[$dataISO]) ? $mapDias[$dataISO] : 0;
}

/* 2) Agendamentos por status (últimos 30 dias) */
$labels_status  = [];
$valores_status = [];

$sqlStatus = "
  SELECT status, COUNT(*) AS total
  FROM agendamentos
  WHERE data >= CURDATE() - INTERVAL 30 DAY
  GROUP BY status
";
$resStatus = $mysqli->query($sqlStatus);
if ($resStatus) {
  while ($row = $resStatus->fetch_assoc()) {
    $labels_status[]  = ucfirst($row['status']);
    $valores_status[] = (int)$row['total'];
  }
}

/* ====================================================================
   CONTADORES RÁPIDOS
   ==================================================================== */
$total_clientes = 0;
$total_medicos  = 0;
$total_agend    = 0;

if ($r = $mysqli->query("SELECT COUNT(*) AS c FROM clientes")) {
  $total_clientes = (int)$r->fetch_assoc()['c'];
}
if ($r = $mysqli->query("SELECT COUNT(*) AS c FROM medicos")) {
  $total_medicos = (int)$r->fetch_assoc()['c'];
}
if ($r = $mysqli->query("SELECT COUNT(*) AS c FROM agendamentos")) {
  $total_agend = (int)$r->fetch_assoc()['c'];
}

/* ====================================================================
   LISTAS SIMPLES PARA TABELAS
   ==================================================================== */

/* Médicos para tabelas */
$lista_medicos = [];
$r = $mysqli->query("SELECT id, nome, crm, especialidade, email, status FROM medicos ORDER BY nome ASC");
if ($r) {
  $lista_medicos = $r->fetch_all(MYSQLI_ASSOC);
}

/* Clientes para tabelas */
$lista_clientes = [];
$r = $mysqli->query("SELECT id, nome, email, cpf, telefone, status FROM clientes ORDER BY nome ASC");
if ($r) {
  $lista_clientes = $r->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <title>Painel Administrativo - ClinAgenda</title>
  <link rel="stylesheet" href="css/adm.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <style>
    /* garante que as seções se comportem como SPA simples */
    .sec-visivel{ display:block; }
    .sec-oculta{ display:none; }
  </style>
</head>
<body>

<header class="topbar">
  <div class="topbar-left">
    <img src="imagens/logo.png" alt="ClinAgenda" class="logo">
    <div>
      <h1>ClinAgenda</h1>
      <span>Painel Administrativo</span>
    </div>
  </div>
  <div class="topbar-right">
    <div class="adm-info">
      <span class="adm-nome"><?= safe($adm['nome']) ?></span>
      <span class="adm-email"><?= safe($adm['email']) ?></span>
    </div>
    <button class="btn-logout" onclick="window.location.href='logout.php'">
      Sair
    </button>
  </div>
</header>

<div class="layout">
  <!-- MENU LATERAL -->
  <aside class="side">
    <nav>
      <button class="side-item active" data-target="sec-dashboard">Dashboard</button>
      <button class="side-item" data-target="sec-medicos">Médicos</button>
      <button class="side-item" data-target="sec-clientes">Clientes</button>
    </nav>
  </aside>

  <main class="main">

    <!-- ===================== DASHBOARD (PRINCIPAL) ===================== -->
    <section id="sec-dashboard" class="sec-visivel">

      <!-- CARDS RESUMO -->
      <section class="cards">
        <div class="card resumo">
          <div class="card-label">Clientes cadastrados</div>
          <div class="card-number"><?= $total_clientes ?></div>
        </div>
        <div class="card resumo">
          <div class="card-label">Médicos cadastrados</div>
          <div class="card-number"><?= $total_medicos ?></div>
        </div>
        <div class="card resumo">
          <div class="card-label">Agendamentos (total)</div>
          <div class="card-number"><?= $total_agend ?></div>
        </div>
      </section>

      <!-- GRÁFICOS -->
      <section class="charts">
        <div class="card grafico">
          <h2>Agendamentos por dia (últimos 30 dias)</h2>
          <canvas id="chartDias"></canvas>
        </div>

        <div class="card grafico">
          <h2>Agendamentos por status (últimos 30 dias)</h2>
          <canvas id="chartStatus"></canvas>
        </div>
      </section>

      <!-- TABELA SIMPLES DE MÉDICOS -->
      <section class="card tabela-card">
        <h2>Médicos cadastrados (visão geral)</h2>

        <div class="tabela-wrap">
          <table>
            <thead>
            <tr>
              <th>#</th>
              <th>Nome</th>
              <th>CRM</th>
              <th>Especialidade</th>
              <th>Email</th>
              <th>Status</th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($lista_medicos)): ?>
              <tr><td colspan="6" class="td-vazio">Nenhum médico cadastrado.</td></tr>
            <?php else: ?>
              <?php foreach ($lista_medicos as $m): ?>
                <tr>
                  <td><?= (int)$m['id'] ?></td>
                  <td><?= safe($m['nome']) ?></td>
                  <td><?= safe($m['crm']) ?></td>
                  <td><?= safe($m['especialidade']) ?></td>
                  <td><?= safe($m['email']) ?></td>
                  <td>
                    <span class="status-badge <?= $m['status']==='ativo'?'ativo':'inativo' ?>">
                      <?= ucfirst($m['status']) ?>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>

      <!-- TABELA SIMPLES DE CLIENTES -->
      <section class="card tabela-card clientes-card">
        <h2>Clientes cadastrados (visão geral)</h2>
        <div class="tabela-wrap">
          <table>
            <thead>
            <tr>
              <th>#</th>
              <th>Nome</th>
              <th>Email</th>
              <th>CPF</th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($lista_clientes)): ?>
              <tr><td colspan="4" class="td-vazio">Nenhum cliente cadastrado.</td></tr>
            <?php else: ?>
              <?php foreach ($lista_clientes as $c): ?>
                <tr>
                  <td><?= (int)$c['id'] ?></td>
                  <td><?= safe($c['nome']) ?></td>
                  <td><?= safe($c['email']) ?></td>
                  <td><?= safe($c['cpf']) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>

    </section>

    <!-- ===================== SEÇÃO MÉDICOS (GERENCIAR) ===================== -->
    <section id="sec-medicos" class="sec-oculta">
      <section class="grid-dupla">
        <!-- CADASTRO / EDIÇÃO BÁSICA DE MÉDICOS -->
        <div class="card form-card">
          <h2>Cadastrar médico</h2>

          <?php if ($mensagem_medico): ?>
            <div class="alert"><?= safe($mensagem_medico) ?></div>
          <?php endif; ?>

          <form method="post" class="form-medico">
            <input type="hidden" name="acao" value="cadastrar_medico">

            <div class="campo">
              <label>Nome completo *</label>
              <input type="text" name="nome" required>
            </div>

            <div class="campo-duplo">
              <div class="campo">
                <label>CRM *</label>
                <input type="text" name="crm" required>
              </div>
              <div class="campo">
                <label>Especialidade</label>
                <input type="text" name="especialidade">
              </div>
            </div>

            <div class="campo">
              <label>Email profissional *</label>
              <input type="email" name="email" required>
            </div>

            <div class="campo-duplo">
              <div class="campo">
                <label>Senha inicial *</label>
                <input type="password" name="senha" required>
              </div>
              <div class="campo">
                <label>Status</label>
                <select name="status">
                  <option value="ativo">Ativo</option>
                  <option value="inativo">Inativo</option>
                </select>
              </div>
            </div>

            <button type="submit" class="btn-salvar">Salvar médico</button>
          </form>
        </div>

        <!-- LISTA PARA GERENCIAR MÉDICOS (ATIVAR / INATIVAR) -->
        <div class="card tabela-card">
          <h2>Gerenciar médicos</h2>

          <div class="tabela-wrap">
            <table>
              <thead>
              <tr>
                <th>#</th>
                <th>Nome</th>
                <th>CRM</th>
                <th>Email</th>
                <th>Status</th>
                <th>Ações</th>
              </tr>
              </thead>
              <tbody>
              <?php if (empty($lista_medicos)): ?>
                <tr><td colspan="6" class="td-vazio">Nenhum médico cadastrado.</td></tr>
              <?php else: ?>
                <?php foreach ($lista_medicos as $m): ?>
                  <tr>
                    <td><?= (int)$m['id'] ?></td>
                    <td><?= safe($m['nome']) ?></td>
                    <td><?= safe($m['crm']) ?></td>
                    <td><?= safe($m['email']) ?></td>
                    <td>
                      <span class="status-badge <?= $m['status']==='ativo'?'ativo':'inativo' ?>">
                        <?= ucfirst($m['status']) ?>
                      </span>
                    </td>
                    <td>
                      <form method="post" class="inline-form">
                        <input type="hidden" name="acao" value="toggle_status">
                        <input type="hidden" name="id_medico" value="<?= (int)$m['id'] ?>">
                        <?php if ($m['status'] === 'ativo'): ?>
                          <input type="hidden" name="novo_status" value="inativo">
                          <button type="submit" class="btn-status inativar">Inativar</button>
                        <?php else: ?>
                          <input type="hidden" name="novo_status" value="ativo">
                          <button type="submit" class="btn-status ativar">Ativar</button>
                        <?php endif; ?>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </section>
    </section>

    <!-- ===================== SEÇÃO CLIENTES (EDITAR) ===================== -->
    <section id="sec-clientes" class="sec-oculta">

      <!-- CARD: EDIÇÃO DE CLIENTE -->
      <section class="card form-card clientes-card">
        <h2>Editar cliente selecionado</h2>

        <?php if ($mensagem_cliente): ?>
          <div class="alert"><?= safe($mensagem_cliente) ?></div>
        <?php endif; ?>

        <?php if ($cliente_edicao): ?>
          <form method="post" class="form-medico">
            <input type="hidden" name="acao" value="salvar_cliente">
            <input type="hidden" name="id_cliente" value="<?= (int)$cliente_edicao['id'] ?>">

            <div class="campo-duplo">
              <div class="campo">
                <label>Nome</label>
                <input type="text" name="nome" value="<?= safe($cliente_edicao['nome']) ?>" required>
              </div>
              <div class="campo">
                <label>Sobrenome</label>
                <input type="text" name="sobrenome" value="<?= safe($cliente_edicao['sobrenome'] ?? '') ?>">
              </div>
            </div>

            <div class="campo-duplo">
              <div class="campo">
                <label>Email</label>
                <input type="email" name="email" value="<?= safe($cliente_edicao['email']) ?>" required>
              </div>
              <div class="campo">
                <label>Telefone</label>
                <input type="text" name="telefone" value="<?= safe($cliente_edicao['telefone'] ?? '') ?>">
              </div>
            </div>

            <div class="campo-duplo">
              <div class="campo">
                <label>CPF</label>
                <input type="text" name="cpf" value="<?= safe($cliente_edicao['cpf'] ?? '') ?>">
              </div>
              <div class="campo">
                <label>Status</label>
                <select name="status">
                  <option value="ativo"   <?= ($cliente_edicao['status'] ?? '') === 'ativo'   ? 'selected' : '' ?>>Ativo</option>
                  <option value="inativo" <?= ($cliente_edicao['status'] ?? '') === 'inativo' ? 'selected' : '' ?>>Inativo</option>
                </select>
              </div>
            </div>

            <div class="campo">
              <label>Nova senha (opcional – deixa em branco para manter)</label>
              <input type="password" name="nova_senha">
            </div>

            <button type="submit" class="btn-salvar">Salvar alterações do cliente</button>
          </form>
        <?php else: ?>
          <p style="font-size:13px; color:var(--muted); margin:0;">
            Selecione um cliente na tabela abaixo para editar suas informações.
          </p>
        <?php endif; ?>
      </section>

      <!-- TABELA DE CLIENTES PARA GERENCIAR -->
      <section class="card tabela-card clientes-card">
        <h2>Gerenciar clientes</h2>
        <div class="tabela-wrap">
          <table>
            <thead>
            <tr>
              <th>#</th>
              <th>Nome</th>
              <th>Email</th>
              <th>CPF</th>
              <th>Ações</th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($lista_clientes)): ?>
              <tr><td colspan="5" class="td-vazio">Nenhum cliente cadastrado.</td></tr>
            <?php else: ?>
              <?php foreach ($lista_clientes as $c): ?>
                <tr>
                  <td><?= (int)$c['id'] ?></td>
                  <td><?= safe($c['nome']) ?></td>
                  <td><?= safe($c['email']) ?></td>
                  <td><?= safe($c['cpf']) ?></td>
                  <td>
                    <a href="ADM.php?sec=clientes&edit_cliente=<?= (int)$c['id'] ?>" class="btn-status ativar">
                      Editar
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
    </section>

  </main>
</div>

<script>
/* --------------------- MENU LATERAL (SEÇÕES) --------------------- */
document.querySelectorAll('.side-item').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.side-item').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    const alvo = btn.getAttribute('data-target');

    
    document.querySelectorAll('.main > section').forEach(sec => {
      sec.classList.add('sec-oculta');
      sec.classList.remove('sec-visivel');
    });

    const sec = document.getElementById(alvo);
    if (sec) {
      sec.classList.remove('sec-oculta');
      sec.classList.add('sec-visivel');
    }
  });
});


/* --------------------- GRÁFICOS --------------------- */
const labelsDias    = <?= json_encode($labels_dias,  JSON_UNESCAPED_UNICODE) ?>;
const valoresDias   = <?= json_encode($valores_dias, JSON_UNESCAPED_UNICODE) ?>;
const labelsStatus  = <?= json_encode($labels_status,  JSON_UNESCAPED_UNICODE) ?>;
const valoresStatus = <?= json_encode($valores_status, JSON_UNESCAPED_UNICODE) ?>;

window.addEventListener('DOMContentLoaded', () => {

  // abre aba correta se tiver ?sec=... ou ?edit_cliente=...
  const params = new URLSearchParams(window.location.search);
  let secIni = params.get('sec');
  if (params.get('edit_cliente')) {
    secIni = 'clientes';
  }
  if (secIni === 'medicos' || secIni === 'clientes') {
    const btn = document.querySelector('.side-item[data-target="sec-' + secIni + '"]');
    if (btn) btn.click();
  }

  // Gráfico de barras (30 dias, máx 10 consultas no eixo Y)
  const ctx1 = document.getElementById('chartDias');
  if (ctx1 && labelsDias.length) {
    new Chart(ctx1, {
      type: 'bar',
      data: {
        labels: labelsDias,
        datasets: [{
          label: 'Agendamentos',
          data: valoresDias,
          borderWidth: 1
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          y: {
            beginAtZero: true,
            max: 10,
            ticks: { stepSize: 1 }
          }
        }
      }
    });
  }

  // Gráfico de status (doughnut)
  const ctx2 = document.getElementById('chartStatus');
  if (ctx2 && labelsStatus.length) {
    new Chart(ctx2, {
      type: 'doughnut',
      data: {
        labels: labelsStatus,
        datasets: [{
          data: valoresStatus,
          borderWidth: 1
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'bottom' }
        }
      }
    });
  }
});
</script>

</body>
</html>
