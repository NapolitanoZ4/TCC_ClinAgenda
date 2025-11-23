<?php
session_start();
date_default_timezone_set('America/Sao_Paulo');

$mysqli = new mysqli("localhost", "root", "", "clinagenda");
if ($mysqli->connect_error) {
  die("Erro ao conectar ao banco: " . $mysqli->connect_error);
}
$mysqli->set_charset("utf8mb4");

/* ================== VERIFICA LOGIN ADM ================== */
// ajuste aqui se você estiver usando outro nome de sessão (ex.: id_adm, adm_id, etc)
if (!isset($_SESSION['id_adm'])) {
  header("Location: index.php");
  exit;
}
$id_adm = (int)$_SESSION['id_adm'];

function safe($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* ================== BUSCAR DADOS DO ADM ================== */
$adm = [
  'nome' => 'Administrador',
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

/* ================== TRATAMENTO DE FORMULÁRIOS ================== */

/* CADASTRAR MÉDICO */
$mensagem_medico = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'cadastrar_medico') {

  $nome          = trim($_POST['nome'] ?? '');
  $crm           = trim($_POST['crm'] ?? '');
  $especialidade = trim($_POST['especialidade'] ?? '');
  $email         = trim($_POST['email'] ?? '');
  $senha_raw     = $_POST['senha'] ?? '';
  $status        = $_POST['status'] ?? 'ativo';

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

/* ALTERAR STATUS DO MÉDICO (ATIVAR / INATIVAR) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'toggle_status') {
  $id_medico = (int)($_POST['id_medico'] ?? 0);
  $novo_status = $_POST['novo_status'] ?? 'ativo';

  if ($id_medico > 0 && in_array($novo_status, ['ativo','inativo'], true)) {
    if ($stmt = $mysqli->prepare("UPDATE medicos SET status = ? WHERE id = ?")) {
      $stmt->bind_param("si", $novo_status, $id_medico);
      $stmt->execute();
      $stmt->close();
    }
  }
  header("Location: ADM.php");
  exit;
}

/* ================== DADOS PARA GRÁFICOS ================== */

/* 1) Agendamentos por dia (últimos 30 dias) */
$labels_dias = [];
$valores_dias = [];

$sqlDias = "
  SELECT data, COUNT(*) AS total
  FROM agendamentos
  WHERE data >= CURDATE() - INTERVAL 30 DAY
  GROUP BY data
  ORDER BY data ASC
";
$resDias = $mysqli->query($sqlDias);
if ($resDias) {
  while ($row = $resDias->fetch_assoc()) {
    $labels_dias[]  = date('d/m', strtotime($row['data']));
    $valores_dias[] = (int)$row['total'];
  }
}

/* 2) Agendamentos por status (últimos 30 dias) */
$labels_status = [];
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

/* ================== CONTADORES RÁPIDOS ================== */
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

/* ================== LISTAS DE TABELAS ================== */

/* Médicos */
$lista_medicos = [];
$r = $mysqli->query("SELECT id, nome, crm, especialidade, email, status FROM medicos ORDER BY nome ASC");
if ($r) {
  $lista_medicos = $r->fetch_all(MYSQLI_ASSOC);
}

/* Clientes */
$lista_clientes = [];
$r = $mysqli->query("SELECT id, nome, email, cpf FROM clientes ORDER BY nome ASC");
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
  <!-- LATERAL SIMPLES (se quiser depois colocamos menu de verdade) -->
  <aside class="side">
    <nav>
      <button class="side-item active">Dashboard</button>
      <button class="side-item">Médicos</button>
      <button class="side-item">Clientes</button>
    </nav>
  </aside>

  <main class="main">
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

    <!-- CADASTRO DE MÉDICOS -->
    <section class="grid-dupla">
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

      <!-- TABELA DE MÉDICOS -->
      <div class="card tabela-card">
        <h2>Médicos cadastrados</h2>

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
                <th>Ações</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($lista_medicos)): ?>
                <tr><td colspan="7" class="td-vazio">Nenhum médico cadastrado.</td></tr>
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

    <!-- TABELA DE CLIENTES -->
    <section class="card tabela-card clientes-card">
      <h2>Clientes cadastrados</h2>
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

  </main>
</div>

<script>
const labelsDias   = <?= json_encode($labels_dias, JSON_UNESCAPED_UNICODE) ?>;
const valoresDias  = <?= json_encode($valores_dias, JSON_UNESCAPED_UNICODE) ?>;
const labelsStatus = <?= json_encode($labels_status, JSON_UNESCAPED_UNICODE) ?>;
const valoresStatus= <?= json_encode($valores_status, JSON_UNESCAPED_UNICODE) ?>;

window.addEventListener('DOMContentLoaded', () => {
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
        scales: {
          y: { beginAtZero: true, ticks:{ stepSize:1 } }
        }
      }
    });
  }

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
        plugins: {
          legend: { position:'bottom' }
        }
      }
    });
  }
});
</script>

</body>
</html>
