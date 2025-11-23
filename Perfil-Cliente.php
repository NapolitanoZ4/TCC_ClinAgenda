<?php
session_start();
date_default_timezone_set('America/Sao_Paulo');

$conn = new mysqli("localhost", "root", "", "clinagenda");
if ($conn->connect_error) die("Erro ao conectar: " . $conn->connect_error);
$conn->set_charset('utf8mb4');

/* ========= VERIFICA LOGIN DO CLIENTE ========= */
if (isset($_SESSION['id'])) {
    $idCliente = (int)$_SESSION['id'];
} elseif (isset($_SESSION['id_cliente'])) {
    $idCliente = (int)$_SESSION['id_cliente'];
} else {
    header("Location: index.php");
    exit;
}

/* ================ FUNÇÃO SAFE ================= */
function safe($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/* ================ UPLOAD DE FOTO DO CLIENTE ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['acao'] ?? '') === 'upload_foto_cliente'
    && isset($_FILES['nova_foto'])
) {

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $_FILES['nova_foto']['tmp_name']);
    finfo_close($finfo);

    $permitidas = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp'
    ];

    if (isset($permitidas[$mime]) &&
        $_FILES['nova_foto']['error'] === UPLOAD_ERR_OK &&
        $_FILES['nova_foto']['size'] > 0 &&
        $_FILES['nova_foto']['size'] <= 5 * 1024 * 1024
    ) {

        $ext = $permitidas[$mime];

        $baseDir = __DIR__ . '/uploads/clientes/';
        if (!is_dir($baseDir)) mkdir($baseDir, 0777, true);

        $nomeArquivo = 'cliente_' . $idCliente . '_' . time() . '.' . $ext;
        $destAbs = $baseDir . $nomeArquivo;
        $destRel = 'uploads/clientes/' . $nomeArquivo;

        if (move_uploaded_file($_FILES['nova_foto']['tmp_name'], $destAbs)) {

            $sql = "
                INSERT INTO fotos (caminho, tipo, cliente_id)
                VALUES (?, 'perfil', ?)
                ON DUPLICATE KEY UPDATE
                    caminho = VALUES(caminho),
                    data_upload = CURRENT_TIMESTAMP
            ";

            $stm = $conn->prepare($sql);
            $stm->bind_param("si", $destRel, $idCliente);
            $stm->execute();

            header("Location: Perfil-Cliente.php?foto=ok");
            exit;
        } else {
            echo "<script>alert('Erro ao salvar foto.');</script>";
        }
    } else {
        echo "<script>alert('Foto inválida. Use JPG/PNG/WEBP até 5MB.');</script>";
    }
}

/* ================ SALVAR PERFIL ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['acao'] ?? '') === 'salvar_perfil_cliente'
) {

    $email = trim($_POST['email'] ?? '');
    $telefone = trim($_POST['telefone'] ?? '');

    $endereco = trim($_POST['endereco'] ?? '');
    $data_nascimento = $_POST['data_nascimento'] ?? null;
    $genero = $_POST['genero'] ?? '';
    $peso = $_POST['peso'] ?? '';
    $altura = $_POST['altura'] ?? '';
    $observacoes = trim($_POST['observacoes'] ?? '');

    /* Atualiza tabela clientes */
    $upCli = $conn->prepare("UPDATE clientes SET email=?, telefone=? WHERE id=?");
    $upCli->bind_param("ssi", $email, $telefone, $idCliente);
    $upCli->execute();

    /* Verifica se já existe dados complementares */
    $chk = $conn->prepare("SELECT id FROM dados_complementares_clientes WHERE cliente_id=? LIMIT 1");
    $chk->bind_param("i", $idCliente);
    $chk->execute();
    $r = $chk->get_result();

    if ($r && $r->num_rows > 0) {
        $upComp = $conn->prepare("
            UPDATE dados_complementares_clientes
            SET endereco=?, data_nascimento=?, genero=?, peso=?, altura=?, observacoes=?
            WHERE cliente_id=?
        ");
        $upComp->bind_param("ssssdsi", $endereco, $data_nascimento, $genero, $peso, $altura, $observacoes, $idCliente);
        $upComp->execute();

    } else {
        $insComp = $conn->prepare("
            INSERT INTO dados_complementares_clientes
            (cliente_id, endereco, data_nascimento, genero, peso, altura, observacoes)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $insComp->bind_param("isssdds", $idCliente, $endereco, $data_nascimento, $genero, $peso, $altura, $observacoes);
        $insComp->execute();
    }

    header("Location: Perfil-Cliente.php?salvo=1");
    exit;
}

/* ================ BUSCA DADOS DO CLIENTE ================= */
$sql = "SELECT
          c.id,
          c.nome,
          c.sobrenome,
          c.cpf,
          c.email,
          c.telefone,
          d.endereco,
          d.data_nascimento,
          d.genero,
          d.peso,
          d.altura,
          d.observacoes
        FROM clientes c
        LEFT JOIN dados_complementares_clientes d ON c.id = d.cliente_id
        WHERE c.id = ?";

$stm = $conn->prepare($sql);
$stm->bind_param("i", $idCliente);
$stm->execute();
$res = $stm->get_result();
$cliente = $res ? $res->fetch_assoc() : [];

/* ================ FOTO DO CLIENTE ================= */
$foto = "img/default.jpg";

$pf = $conn->prepare("SELECT caminho FROM fotos WHERE cliente_id=? AND tipo='perfil' ORDER BY data_upload DESC LIMIT 1");
$pf->bind_param("i", $idCliente);
$pf->execute();
$rpf = $pf->get_result();
if ($rpf && $rpf->num_rows > 0) {
    $foto = safe($rpf->fetch_assoc()['caminho']);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <title>Perfil do Cliente - ClinAgenda</title>
  <link rel="stylesheet" href="css/Perfil-Cliente.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>

<body>

<header>
  <div class="header-brand">
    <div class="brand-icon"><i class="fa-solid fa-user"></i></div>
    <div class="brand-text">
      <span class="brand-title">ClinAgenda</span>
      <span class="brand-sub">Perfil do Cliente</span>
    </div>
  </div>
  <a href="cliente.php" class="logout-link"><i class="fa-solid fa-arrow-left"></i> voltar</a>
</header>

<div class="painel-central">

  <form id="form-perfil-cliente" method="post">
    <input type="hidden" name="acao" value="salvar_perfil_cliente">
  </form>

  <div class="cards-grid">

    <!-- ========== CARD 1 — IDENTIDADE + CONTATO ========== -->
    <div class="card">
      <div class="card-header">
        <i class="fa-regular fa-id-card"></i>
        <span>Dados do Cliente</span>
      </div>

      <!-- FOTO -->
      <form id="form-foto-cliente" method="post" enctype="multipart/form-data">
        <input type="hidden" name="acao" value="upload_foto_cliente">
        <div class="profile-pic-wrapper">
          <img
            id="foto-perfil-cliente"
            src="<?= $foto ?>"
            onclick="document.getElementById('input-foto-cliente').click();"
          >
        </div>
        <input type="file" id="input-foto-cliente" name="nova_foto" accept="image/*" style="display:none">
      </form>

      <h2 class="cliente-nome"><?= safe($cliente['nome'] . ' ' . $cliente['sobrenome']) ?></h2>
      <div class="cpf">CPF: <?= safe($cliente['cpf']) ?></div>

      <div class="mini-grid">

        <div class="form-group">
          <label>Nome</label>
          <input type="text" value="<?= safe($cliente['nome']) ?>" readonly>
        </div>

        <div class="form-group">
          <label>Sobrenome</label>
          <input type="text" value="<?= safe($cliente['sobrenome']) ?>" readonly>
        </div>

        <div class="form-group">
          <label>Email</label>
          <input type="email" name="email" form="form-perfil-cliente" value="<?= safe($cliente['email']) ?>">
        </div>

        <div class="form-group">
          <label>Telefone</label>
          <input type="text" name="telefone" form="form-perfil-cliente" value="<?= safe($cliente['telefone']) ?>">
        </div>

        <div class="form-group mini-full">
          <label>Endereço</label>
          <input type="text" name="endereco" form="form-perfil-cliente" value="<?= safe($cliente['endereco']) ?>">
        </div>
      </div>
    </div>

    <!-- ========== CARD 2 — COMPLEMENTARES + OBS ========== -->
    <div class="card">
      <div class="card-header">
        <i class="fa-regular fa-calendar"></i>
        <span>Informações Complementares</span>
      </div>

      <div class="mini-grid">

        <div class="form-group">
          <label>Data de Nascimento</label>
          <input type="date" name="data_nascimento" form="form-perfil-cliente"
                 value="<?= safe($cliente['data_nascimento']) ?>">
        </div>

        <div class="form-group">
          <label>Gênero</label>
          <select name="genero" form="form-perfil-cliente">
            <?php $g = $cliente['genero']; ?>
            <option value="">Selecione</option>
            <option value="Masculino" <?= $g === "Masculino" ? "selected" : "" ?>>Masculino</option>
            <option value="Feminino"  <?= $g === "Feminino"  ? "selected" : "" ?>>Feminino</option>
            <option value="Outro"     <?= $g === "Outro"     ? "selected" : "" ?>>Outro</option>
          </select>
        </div>

        <div class="form-group">
          <label>Peso (kg)</label>
          <input type="number" step="0.1" min="0" name="peso" form="form-perfil-cliente"
                 value="<?= safe($cliente['peso']) ?>">
        </div>

        <div class="form-group">
          <label>Altura (m)</label>
          <input type="number" step="0.01" min="0" name="altura" form="form-perfil-cliente"
                 value="<?= safe($cliente['altura']) ?>">
        </div>
      </div>

      <label style="font-size:12px;">Observações</label>
      <textarea name="observacoes" form="form-perfil-cliente" rows="5"><?= safe($cliente['observacoes']) ?></textarea>

      <div class="btn-container right">
        <button type="submit" class="btn-salvar" form="form-perfil-cliente">
          <i class="fa-solid fa-floppy-disk"></i> Salvar
        </button>
      </div>
    </div>

  </div>
</div>

<script>
const inputFotoCli = document.getElementById('input-foto-cliente');
const imgFotoCli = document.getElementById('foto-perfil-cliente');
const formFotoCli = document.getElementById('form-foto-cliente');

if (inputFotoCli && imgFotoCli && formFotoCli) {
  inputFotoCli.addEventListener('change', (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    imgFotoCli.src = URL.createObjectURL(file);
    formFotoCli.submit();
  });
}
</script>

</body>
</html>
