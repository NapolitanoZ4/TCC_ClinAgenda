<?php
session_start();
include("conexao.php");

$usuario_id = $_SESSION['usuario_id'] ?? null;
if (!$usuario_id) {
    die("Usuário não autenticado.");
}

if ($_FILES['foto']['error'] == 0) {
    $ext = pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION);
    $nome_arquivo = uniqid() . '.' . $ext;
    $caminho = 'imagens/usuarios/' . $nome_arquivo;

    if (move_uploaded_file($_FILES['foto']['tmp_name'], $caminho)) {
        // Atualiza no banco (clientes ou médicos)
        $tabela = $_SESSION['tipo'] == 'medico' ? 'medicos' : 'usuarios';
        $sql = "UPDATE $tabela SET foto='$caminho' WHERE id=$usuario_id";
        mysqli_query($conn, $sql);
        header("Location: clientes.php");
    } else {
        echo "Erro ao salvar imagem.";
    }
}
?>
