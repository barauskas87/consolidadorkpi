<?php

// --- Token de Autenticação da API ---
const API_TOKEN = 'SLYV15L0V3';


// --- Configurações do Banco de Dados Azure SQL ---

// O endereço do servidor de banco de dados.
$serverName = "tcp:ds-srv01.database.windows.net,1433";

// O array de informações de conexão, usando as credenciais.
$connectionInfo = array(
    "Database" => "DatasitePRD",
    "UID"      => "datasite_user",
    "PWD"      => "Dat@_$1te854p44%!",
    "LoginTimeout" => 30,
    "Encrypt" => 1,
    "TrustServerCertificate" => 0
);

?>
