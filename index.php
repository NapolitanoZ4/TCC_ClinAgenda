<?php
session_start();
date_default_timezone_set('America/Sao_Paulo');

$host = 'localhost';
$user = 'root';
$pass = '';
$db   = 'clinagenda';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
  die("Erro ao conectar com o banco de dados: " . $conn->connect_error);
}
$conn->set_charset('utf8mb4');

function safe($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$erro_cliente_login  = '';
$erro_cliente_cad    = '';
$erro_medico_login   = '';
$erro_adm_login      = '';
$msg_sucesso_cad     = '';

/* =========================================================
   GARANTE TABELA ADM + CRIA UM ADM PADRÃO SE ESTIVER VAZIA
   ========================================================= */
$createAdmSql = "
CREATE TABLE IF NOT EXISTS adm (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome  VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  senha VARCHAR(255) NOT NULL,
  criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
$conn->query($createAdmSql);

// verifica se já existe algum ADM
$temAdm = 0;
if ($res = $conn->query("SELECT COUNT(*) AS c FROM adm")) {
  $temAdm = (int)$res->fetch_assoc()['c'];
}

// se não tiver nenhum, cria um padrão
if ($temAdm === 0) {
  $emailPadrao = 'admin@clinagenda.com';
  $nomePadrao  = 'Administrador Padrão';
  $senhaPadrao = 'admin123'; // texto simples, será hasheado
  $hashPadrao  = password_hash($senhaPadrao, PASSWORD_DEFAULT);

  $insAdm = $conn->prepare("INSERT IGNORE INTO adm (nome, email, senha) VALUES (?, ?, ?)");
  $insAdm->bind_param("sss", $nomePadrao, $emailPadrao, $hashPadrao);
  $insAdm->execute();
  $insAdm->close();
}

/* ==================== LOGIN CLIENTE ==================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'login_cliente') {
  $email = trim($_POST['email'] ?? '');
  $senha = $_POST['senha'] ?? '';

  if ($email === '' || $senha === '') {
    $erro_cliente_login = 'Informe email e senha.';
  } else {
    $sql = "SELECT id, nome, email, senha FROM clientes WHERE email = ? LIMIT 1";
    $stm = $conn->prepare($sql);
    $stm->bind_param("s", $email);
    $stm->execute();
    $res = $stm->get_result();
    if ($res && $res->num_rows === 1) {
      $cli = $res->fetch_assoc();
      $hash = $cli['senha'];

      $ok = false;
      if (password_verify($senha, $hash)) {
        $ok = true;
      } elseif ($senha === $hash) { // fallback caso tenha senha antiga em texto puro
        $ok = true;
      }

      if ($ok) {
        $_SESSION['id'] = (int)$cli['id'];
        header("Location: Cliente.php");
        exit;
      } else {
        $erro_cliente_login = 'Email ou senha inválidos.';
      }
    } else {
      $erro_cliente_login = 'Email ou senha inválidos.';
    }
  }
}

/* ==================== CADASTRO CLIENTE ==================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'cadastro_cliente') {
  $nome     = trim($_POST['nome'] ?? '');
  $email    = trim($_POST['email'] ?? '');
  $cpf      = preg_replace('/\D+/', '', $_POST['cpf'] ?? '');
  $telefone = trim($_POST['telefone'] ?? '');
  $senha1   = $_POST['senha'] ?? '';
  $senha2   = $_POST['senha2'] ?? '';

  if ($nome === '' || $email === '' || $cpf === '' || $senha1 === '' || $senha2 === '') {
    $erro_cliente_cad = 'Preencha todos os campos obrigatórios.';
  } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $erro_cliente_cad = 'Email inválido.';
  } elseif ($senha1 !== $senha2) {
    $erro_cliente_cad = 'As senhas não conferem.';
  } else {
    // verificar se email ou cpf já existem
    $chk = $conn->prepare("SELECT id FROM clientes WHERE email = ? OR cpf = ? LIMIT 1");
    $chk->bind_param("ss", $email, $cpf);
    $chk->execute();
    $r = $chk->get_result();
    if ($r && $r->num_rows > 0) {
      $erro_cliente_cad = 'Já existe um cliente com este email ou CPF.';
    } else {
      $hash = password_hash($senha1, PASSWORD_DEFAULT);
      $ins = $conn->prepare("INSERT INTO clientes (nome, email, cpf, telefone, senha) VALUES (?, ?, ?, ?, ?");
      // correção: fecha parêntese no SQL
    }
  }
}

/* Pequena correção do INSERT que foi cortado acima */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'cadastro_cliente') {
  // (repetindo a lógica, agora correta)
  $nome     = trim($_POST['nome'] ?? '');
  $email    = trim($_POST['email'] ?? '');
  $cpf      = preg_replace('/\D+/', '', $_POST['cpf'] ?? '');
  $telefone = trim($_POST['telefone'] ?? '');
  $senha1   = $_POST['senha'] ?? '';
  $senha2   = $_POST['senha2'] ?? '';

  if ($nome === '' || $email === '' || $cpf === '' || $senha1 === '' || $senha2 === '') {
    $erro_cliente_cad = 'Preencha todos os campos obrigatórios.';
  } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $erro_cliente_cad = 'Email inválido.';
  } elseif ($senha1 !== $senha2) {
    $erro_cliente_cad = 'As senhas não conferem.';
  } else {
    $chk = $conn->prepare("SELECT id FROM clientes WHERE email = ? OR cpf = ? LIMIT 1");
    $chk->bind_param("ss", $email, $cpf);
    $chk->execute();
    $r = $chk->get_result();
    if ($r && $r->num_rows > 0) {
      $erro_cliente_cad = 'Já existe um cliente com este email ou CPF.';
    } else {
      $hash = password_hash($senha1, PASSWORD_DEFAULT);
      $ins = $conn->prepare("INSERT INTO clientes (nome, email, cpf, telefone, senha) VALUES (?, ?, ?, ?, ?)");
      $ins->bind_param("sssss", $nome, $email, $cpf, $telefone, $hash);
      if ($ins->execute()) {
        $msg_sucesso_cad = 'Cadastro realizado! Você já pode fazer login.';
      } else {
        $erro_cliente_cad = 'Erro ao salvar. Tente novamente.';
      }
      $ins->close();
    }
    $chk->close();
  }
}

/* ==================== LOGIN MÉDICO ==================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'login_medico') {
  $email = trim($_POST['email'] ?? '');
  $crm   = trim($_POST['crm'] ?? '');
  $senha = $_POST['senha'] ?? '';

  if ($email === '' || $crm === '' || $senha === '') {
    $erro_medico_login = 'Preencha email, CRM e senha.';
  } else {
    $sql = "SELECT id, nome, email, crm, senha FROM medicos WHERE email = ? AND crm = ? LIMIT 1";
    $stm = $conn->prepare($sql);
    $stm->bind_param("ss", $email, $crm);
    $stm->execute();
    $res = $stm->get_result();

    if ($res && $res->num_rows === 1) {
      $med = $res->fetch_assoc();
      $hash = $med['senha'];

      $ok = false;
      if (password_verify($senha, $hash)) {
        $ok = true;
      } elseif ($senha === $hash) {
        $ok = true;
      }

      if ($ok) {
        $_SESSION['id_medico'] = (int)$med['id'];
        header("Location: medico.php");
        exit;
      } else {
        $erro_medico_login = 'Credenciais inválidas.';
      }
    } else {
      $erro_medico_login = 'Credenciais inválidas.';
    }
  }
}

/* ==================== LOGIN ADM (tabela adm) ==================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'login_adm') {
  $email = trim($_POST['email'] ?? '');
  $senha = $_POST['senha'] ?? '';

  if ($email === '' || $senha === '') {
    $erro_adm_login = 'Informe email e senha.';
  } else {
    $sql = "SELECT id, nome, email, senha FROM adm WHERE email = ? LIMIT 1";
    $stm = $conn->prepare($sql);
    $stm->bind_param("s", $email);
    $stm->execute();
    $res = $stm->get_result();

    if ($res && $res->num_rows === 1) {
      $adm = $res->fetch_assoc();
      $hash = $adm['senha'];

      $ok = false;
      if (password_verify($senha, $hash)) {
        $ok = true;
      } elseif ($senha === $hash) {
        $ok = true;
      }

      if ($ok) {
        $_SESSION['id_adm'] = (int)$adm['id'];
        header("Location: ADM.php");
        exit;
      } else {
        $erro_adm_login = 'Email ou senha inválidos.';
      }
    } else {
      $erro_adm_login = 'Email ou senha inválidos.';
    }
  }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <title>ClinAgenda - Login</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <style>
    *{box-sizing:border-box;margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;}
    body{
      min-height:100vh;
      display:flex;
      align-items:center;
      justify-content:center;
      background:linear-gradient(135deg,#f1f5f2,#dde4dc);
      color:#1f2933;
    }
    .container{
      width:100%;
      max-width:960px;
      background:#fff;
      border-radius:18px;
      box-shadow:0 20px 45px rgba(0,0,0,.12);
      display:grid;
      grid-template-columns:1.1fr 0.9fr;
      overflow:hidden;
    }
    @media(max-width:900px){
      .container{grid-template-columns:1fr;}
      .hero{display:none;}
    }
    .hero{
      background:linear-gradient(160deg,#3CB371,#2E8B57);
      color:#fff;
      padding:40px 38px;
      position:relative;
    }
    .hero h1{font-size:28px;margin-bottom:10px;}
    .hero p{opacity:.95;margin-bottom:24px;}
    .hero ul{list-style:none;margin:0;padding:0;}
    .hero li{margin-bottom:10px;display:flex;align-items:flex-start;gap:8px;font-size:14px;}
    .hero li span{margin-top:3px;}
    .hero-badge{
      display:inline-flex;
      align-items:center;
      gap:6px;
      background:rgba(255,255,255,.15);
      padding:6px 12px;
      border-radius:999px;
      font-size:12px;
      margin-bottom:20px;
    }
    .hero-badge i{font-style:normal;font-size:16px;}
    .hero-footer{
      position:absolute;
      bottom:20px;left:38px;right:38px;
      font-size:12px;
      opacity:.85;
    }
    .brand{
      font-weight:800;
      letter-spacing:.05em;
      font-size:20px;
      margin-bottom:24px;
    }
    .brand span{color:#cfead6;}
    .panel{
      padding:32px 32px 26px;
    }
    .tabs{
      display:flex;
      gap:8px;
      margin-bottom:16px;
      flex-wrap:wrap;
    }
    .tab-btn{
      border:none;
      padding:8px 14px;
      border-radius:999px;
      background:#f3f4f6;
      font-size:13px;
      cursor:pointer;
      transition:.2s;
    }
    .tab-btn.active{
      background:#3CB371;
      color:#fff;
      box-shadow:0 0 0 1px rgba(0,0,0,.05);
    }
    .form-title{
      font-size:20px;
      margin-bottom:4px;
      font-weight:700;
    }
    .form-sub{
      font-size:13px;
      color:#4b5563;
      margin-bottom:18px;
    }
    form{
      display:flex;
      flex-direction:column;
      gap:10px;
    }
    label{
      font-size:13px;
      font-weight:600;
      color:#374151;
    }
    input,select{
      width:100%;
      padding:9px 11px;
      border-radius:8px;
      border:1px solid #d1d5db;
      font-size:14px;
      outline:none;
      transition:border .15s, box-shadow .15s, background .15s;
      background:#f9fafb;
    }
    input:focus{
      border-color:#3CB371;
      box-shadow:0 0 0 1px rgba(60,179,113,.25);
      background:#fff;
    }
    .btn{
      margin-top:6px;
      padding:10px 14px;
      border-radius:999px;
      border:none;
      background:#3CB371;
      color:#fff;
      font-weight:700;
      cursor:pointer;
      font-size:14px;
      box-shadow:0 8px 20px rgba(60,179,113,.35);
      transition:.2s;
    }
    .btn:hover{background:#2E8B57;transform:translateY(-1px);}
    .error{
      background:#fef2f2;
      border:1px solid #fecaca;
      color:#b91c1c;
      padding:8px 10px;
      border-radius:8px;
      font-size:13px;
      margin-bottom:4px;
    }
    .success{
      background:#ecfdf3;
      border:1px solid #bbf7d0;
      color:#166534;
      padding:8px 10px;
      border-radius:8px;
      font-size:13px;
      margin-bottom:4px;
    }
    .row-2{
      display:grid;
      grid-template-columns:1fr 1fr;
      gap:10px;
    }
    .hint{
      font-size:11px;
      color:#6b7280;
      margin-top:4px;
    }
    .adm-box{
      margin-top:14px;
      border-top:1px dashed #e5e7eb;
      padding-top:10px;
    }
    .adm-title{
      font-size:12px;
      font-weight:700;
      text-transform:uppercase;
      letter-spacing:.08em;
      color:#6b7280;
      margin-bottom:4px;
    }
    .hidden{display:none;}
  </style>
</head>
<body>

<div class="container">
  <div class="hero">
    <div class="brand">Clin<span>Agenda</span></div>
    <div class="hero-badge">
      <i>❤</i> Painel integrado para Clínicas
    </div>
    <h1>Organize consultas e exames em poucos cliques.</h1>
    <p>Acesse como <b>paciente</b>, <b>médico</b> ou <b>administrador</b> e centralize toda a rotina da clínica.</p>
    <ul>
      <li><span>✔</span> Agendamento on-line com confirmação pelo médico.</li>
      <li><span>✔</span> Bloqueio de horários e gerenciamento da agenda médica.</li>
      <li><span>✔</span> Prescrição de exames e acompanhamento do paciente.</li>
    </ul>
    <div class="hero-footer">
      TCC - Sistema de Agendamentos Médicos • Banco de dados <b>clinagenda</b>.
    </div>
  </div>

  <div class="panel">
    <div class="tabs">
      <button type="button" class="tab-btn active" data-tab="login_cliente" onclick="trocarTab('login_cliente', this)">Paciente - Login</button>
      <button type="button" class="tab-btn" data-tab="cadastro_cliente" onclick="trocarTab('cadastro_cliente', this)">Paciente - Cadastro</button>
      <button type="button" class="tab-btn" data-tab="login_medico" onclick="trocarTab('login_medico', this)">Médico - Login</button>
      <button type="button" class="tab-btn" data-tab="login_adm" onclick="trocarTab('login_adm', this)">ADM</button>
    </div>

    <!-- LOGIN CLIENTE -->
    <div id="tab-login_cliente" class="tab-content">
      <div class="form-title">Entrar como Paciente</div>
      <div class="form-sub">Informe seu email e senha para acessar o painel de agendamentos.</div>
      <?php if($erro_cliente_login): ?>
        <div class="error"><?= safe($erro_cliente_login) ?></div>
      <?php endif; ?>
      <?php if($msg_sucesso_cad && !$erro_cliente_login): ?>
        <div class="success"><?= safe($msg_sucesso_cad) ?></div>
      <?php endif; ?>
      <form method="post" autocomplete="off">
        <input type="hidden" name="acao" value="login_cliente">
        <div>
          <label for="cli_email">Email</label>
          <input type="email" id="cli_email" name="email" required>
        </div>
        <div>
          <label for="cli_senha">Senha</label>
          <input type="password" id="cli_senha" name="senha" required>
        </div>
        <button class="btn" type="submit">Entrar</button>
      </form>
    </div>

    <!-- CADASTRO CLIENTE -->
    <div id="tab-cadastro_cliente" class="tab-content hidden">
      <div class="form-title">Cadastro de Paciente</div>
      <div class="form-sub">Crie sua conta para marcar consultas e exames.</div>
      <?php if($erro_cliente_cad): ?>
        <div class="error"><?= safe($erro_cliente_cad) ?></div>
      <?php endif; ?>
      <?php if($msg_sucesso_cad && !$erro_cliente_cad): ?>
        <div class="success"><?= safe($msg_sucesso_cad) ?></div>
      <?php endif; ?>

      <form method="post" autocomplete="off">
        <input type="hidden" name="acao" value="cadastro_cliente">
        <div>
          <label for="cad_nome">Nome completo</label>
          <input type="text" id="cad_nome" name="nome" required>
        </div>
        <div class="row-2">
          <div>
            <label for="cad_email">Email</label>
            <input type="email" id="cad_email" name="email" required>
          </div>
          <div>
            <label for="cad_cpf">CPF</label>
            <input type="text" id="cad_cpf" name="cpf" placeholder="Apenas números" required>
          </div>
        </div>
        <div>
          <label for="cad_tel">Telefone</label>
          <input type="text" id="cad_tel" name="telefone" placeholder="(DDD) 99999-9999">
        </div>
        <div class="row-2">
          <div>
            <label for="cad_senha">Senha</label>
            <input type="password" id="cad_senha" name="senha" required>
          </div>
          <div>
            <label for="cad_senha2">Confirmar senha</label>
            <input type="password" id="cad_senha2" name="senha2" required>
          </div>
        </div>
        <button class="btn" type="submit">Criar conta</button>
        <div class="hint">As senhas são armazenadas com criptografia (password_hash).</div>
      </form>
    </div>

    <!-- LOGIN MÉDICO -->
    <div id="tab-login_medico" class="tab-content hidden">
      <div class="form-title">Entrar como Médico</div>
      <div class="form-sub">Use seu email profissional, CRM e senha.</div>
      <?php if($erro_medico_login): ?>
        <div class="error"><?= safe($erro_medico_login) ?></div>
      <?php endif; ?>
      <form method="post" autocomplete="off">
        <input type="hidden" name="acao" value="login_medico">
        <div>
          <label for="med_email">Email profissional</label>
          <input type="email" id="med_email" name="email" required>
        </div>
        <div>
          <label for="med_crm">CRM</label>
          <input type="text" id="med_crm" name="crm" required>
        </div>
        <div>
          <label for="med_senha">Senha</label>
          <input type="password" id="med_senha" name="senha" required>
        </div>
        <button class="btn" type="submit">Entrar</button>
      </form>
    </div>

    <!-- LOGIN ADM -->
    <div id="tab-login_adm" class="tab-content hidden">
      <div class="form-title">Administrador</div>
      <div class="form-sub">Acesso restrito à gestão da clínica.</div>
      <?php if($erro_adm_login): ?>
        <div class="error"><?= safe($erro_adm_login) ?></div>
      <?php endif; ?>

      <div class="adm-box">
        <div class="adm-title">Login ADM</div>
        <form method="post" autocomplete="off">
          <input type="hidden" name="acao" value="login_adm">
          <div>
            <label for="adm_email">Email</label>
            <input type="email" id="adm_email" name="email" value="admin@clinagenda.com">
          </div>
          <div>
            <label for="adm_senha">Senha</label>
            <input type="password" id="adm_senha" name="senha" value="admin123">
          </div>
          <button class="btn" type="submit">Entrar como ADM</button>
        </form>
        <div class="hint">
          ADM padrão criado automaticamente se não existir:<br>
          <b>Email:</b> admin@clinagenda.com<br>
          <b>Senha:</b> admin123
        </div>
      </div>
    </div>
  </div>
</div>

<script>
function trocarTab(tabId, btn){
  document.querySelectorAll('.tab-content').forEach(div=>{
    div.classList.add('hidden');
  });
  document.getElementById('tab-'+tabId).classList.remove('hidden');

  document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
  btn.classList.add('active');
}
</script>
</body>
</html>
