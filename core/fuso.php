<?php
// Fuso horário do Argos: horário de Brasília, no PHP e no banco.
//
// Sem isto o sistema seguia o relógio do servidor, que na hospedagem é UTC:
// às 21h de Brasília o "hoje" já virava o dia seguinte — bem na hora do
// pernoite — e toda data/hora gravada saía 3 horas adiantada.
//
// Fica aqui, e não no core/config.php, porque o config de produção não é
// versionado: o que estivesse só no config.example.php nunca chegaria lá.
// Todo arquivo que carrega o config carrega este em seguida, e abre a conexão
// por conectarBancoArgos().

const ARGOS_FUSO = 'America/Sao_Paulo';

date_default_timezone_set(ARGOS_FUSO);

/**
 * A conexão do config (conectarBanco) já com o fuso da sessão acertado, pra
 * NOW(), CURDATE() e CURRENT_TIMESTAMP baterem com o date() do PHP. Usa o
 * deslocamento (-03:00) em vez do nome do fuso porque o nome depende de
 * tabelas que nem todo servidor MySQL/MariaDB tem carregadas.
 */
function conectarBancoArgos() {
    $conexao = conectarBanco();
    mysqli_query($conexao, "SET time_zone = '" . date('P') . "'");
    return $conexao;
}
