<?php
// Configurações do banco e token de autenticação

// Dados do banco Azure SQL
$connectionInfo = array("UID" => "datasite_user", "pwd" => "Dat@_$1te854p44%!", "Database" => "DatasitePRD", "LoginTimeout" => 30, "Encrypt" => 1, "TrustServerCertificate" => 0);
$serverName = "tcp:ds-srv01.database.windows.net,1433";
$conn = sqlsrv_connect($serverName, $connectionInfo);

// Token fixo para autenticação da API
// Altere para um valor seguro e secreto
const API_TOKEN = 'SLYV15L0V3';
?>
